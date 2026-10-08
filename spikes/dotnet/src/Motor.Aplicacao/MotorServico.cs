using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fatos;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

/// <summary>Orquestra os casos de uso. Sem estado de negócio entre requisições (ADR-014):
/// cada chamada abre uma unidade de trabalho (transação) e lê as regras em uso do banco.</summary>
public sealed class MotorServico
{
    private readonly IFabricaUnidade _fabrica;

    public MotorServico(IFabricaUnidade fabrica) => _fabrica = fabrica;

    // ---- AbrirCompetencias (§5) ----
    public sealed record EmpresaDto(string TitularId, JsonElement Snapshot, string Carteira);
    public sealed record AberturaResultado(List<(string TitularId, Guid CasoId)> Criados,
        List<(string TitularId, Guid CasoId)> Existentes);

    public async Task<AberturaResultado> AbrirCompetenciasAsync(Competencia competencia, IReadOnlyList<EmpresaDto> empresas)
    {
        var res = new AberturaResultado(new(), new());
        foreach (var emp in empresas)
        {
            await using var uow = await _fabrica.AbrirAsync();
            var r = uow.Repos;
            // Serializa abertura e publicação de fato do mesmo TITULAR (lock por titular, não por
            // competência): a reavaliação lê fatos de outras competências via competencia_relativa,
            // então sem esta trava um publisher e um opener concorrentes não enxergam o estado
            // não-comitado um do outro e o entregável fica preso em aguardando_insumo (§6).
            await r.Casos.TravarCasoAsync(emp.TitularId, competencia);
            var existente = await r.Casos.PorChaveAsync(emp.TitularId, competencia);
            if (existente is not null)
            {
                res.Existentes.Add((emp.TitularId, existente.Id));
                await uow.ConfirmarAsync();
                continue;
            }

            var regraInfo = await r.Regras.VersaoEmUsoAsync("fechamento.entregaveis")
                ?? throw new InvalidOperationException("regra fechamento.entregaveis sem versão em uso");
            var regra = new RegraEntregaveis(regraInfo.Id, regraInfo.Conteudo);
            var agora = await uow.AgoraAsync();

            var caso = await r.Casos.CriarAsync(emp.TitularId, competencia, emp.Snapshot, emp.Carteira, regra.VersaoId);
            if (caso is null)
            {
                // abertura concorrente venceu entre o SELECT e o INSERT: relê e reporta como existente.
                var jaExiste = await r.Casos.PorChaveAsync(emp.TitularId, competencia)
                    ?? throw new InvalidOperationException("conflito de abertura sem caso correspondente");
                res.Existentes.Add((emp.TitularId, jaExiste.Id));
                await uow.ConfirmarAsync();
                continue;
            }
            var ctx = Contexto.DeSnapshot(emp.Snapshot, competencia.MesDoTrimestre);

            // 1. criar entregáveis cujo quando casa, com transição de criação
            var criados = new List<(ItemEntregavel item, Entregavel ent)>();
            foreach (var item in regra.Itens)
            {
                if (!Condicao.Casa(item.Quando, ctx)) continue;
                var cat = await r.Catalogo.EntregavelTipoAsync(item.Tipo)
                    ?? throw new InvalidOperationException($"catálogo sem {item.Tipo}");
                var ent = await r.Entregaveis.CriarAsync(caso.Id, item.Tipo, item.Executor, cat.Area);
                await r.Entregaveis.TransicionarAsync(ent, new NovaTransicao(
                    ent.Id, caso.Id, null, EstadoEntregavel.AguardandoInsumo, "abrir_competencia",
                    "comando", null, "sistema", regra.VersaoId), agora);
                criados.Add((item, ent));
            }

            // 2. dependências cujo quando casa e cujo alvo existe no caso
            var porTipo = criados.ToDictionary(c => c.item.Tipo, c => c.ent);
            foreach (var (item, ent) in criados)
            {
                foreach (var dep in item.Dependencias)
                {
                    var depQuando = dep.TryGetProperty("quando", out var dq) ? dq : (JsonElement?)null;
                    if (!Condicao.Casa(depQuando, ctx)) continue;
                    var alvoTipo = dep.GetProperty("tipo").GetString()!;
                    if (!porTipo.TryGetValue(alvoTipo, out var alvo)) continue; // alvo inexistente: ignora
                    var estadoMin = dep.TryGetProperty("estado_minimo", out var em) && em.ValueKind == JsonValueKind.String
                        ? Estados.Analisar(em.GetString()!)
                        : regra.EstadoMinimoPadrao;
                    await r.Entregaveis.GravarDependenciaAsync(ent.Id, alvo.Id, estadoMin);
                }
            }

            // 3. evento competencia.aberta
            await r.Eventos.GravarComIdAsync("competencia.aberta", caso.Id, null, "caso_id", caso.Id);

            // 4. avaliar prontidão de todos
            var (tol, tolVid, prolaboreBp) = await CarregarFiscalAsync(r);
            var avaliar = new AvaliarCaso(r, regra, new Conferir(r, tol, tolVid, prolaboreBp));
            await avaliar.ExecutarAsync(caso, agora);

            res.Criados.Add((emp.TitularId, caso.Id));
            await uow.ConfirmarAsync();
        }
        return res;
    }

    // ---- PublicarFato (§6) ----
    public async Task<ResultadoFato> PublicarFatoAsync(PublicarFatoCmd cmd)
    {
        await using var uow = await _fabrica.AbrirAsync();
        var r = uow.Repos;
        var res = await PublicarFatoInternoAsync(r, cmd, reavaliar: true);
        await uow.ConfirmarAsync();
        return res;
    }

    /// <summary>Publica o fato na transação dada. Se reavaliar=true, reavalia os casos abertos do titular.</summary>
    private async Task<ResultadoFato> PublicarFatoInternoAsync(IRepositorios r, PublicarFatoCmd cmd, bool reavaliar)
    {
        // Serializa com a abertura e com outras publicações do mesmo TITULAR: a reavaliação lê fatos
        // de outras competências (competencia_relativa), então publicar e abrir em paralelo não podem
        // deixar o entregável preso em aguardando_insumo (§6). Advisory locks são reentrantes na mesma
        // transação, então o caminho de conclusão (que já tomou o lock) é seguro.
        await r.Casos.TravarCasoAsync(cmd.TitularId, cmd.Competencia);
        // Serializa publicações concorrentes da MESMA chave: sem isto, dois publishers leem o mesmo
        // vigente, calculam a mesma versão e um perde no índice único — virando 500 em vez de nova_versao.
        await r.Fatos.TravarChaveAsync(cmd.TitularId, cmd.Competencia, cmd.Tipo, cmd.Tributo);
        var hash = HashCanonico.HashFato(cmd.Payload, cmd.ValorCentavos);
        var vigente = await r.Fatos.VigenteAsync(cmd.TitularId, cmd.Competencia, cmd.Tipo, cmd.Tributo);

        if (vigente is not null && vigente.Hash == hash)
            return new ResultadoFato(vigente.Id, vigente.Versao, EfeitoFato.SemMudanca);

        var novaVersao = (vigente?.Versao ?? 0) + 1;
        var (id, versao) = await r.Fatos.GravarAsync(cmd, novaVersao, hash, vigente?.Id);
        var efeito = vigente is null ? EfeitoFato.Novo : EfeitoFato.NovaVersao;

        // evento fato.publicado — caso_id só se existe caso do titular NAQUELA competência
        var caso = await r.Casos.PorChaveAsync(cmd.TitularId, cmd.Competencia);
        await r.Eventos.GravarComIdAsync("fato.publicado", caso?.Id, null, "fato_id", id);

        if (reavaliar)
            await ReavaliarAbertosAsync(r, cmd.TitularId);

        return new ResultadoFato(id, versao, efeito);
    }

    private async Task ReavaliarAbertosAsync(IRepositorios r, string titularId)
    {
        var abertos = await r.Casos.AbertosDoTitularAsync(titularId);
        if (abertos.Count == 0) return;
        var (tol, tolVid, prolaboreBp) = await CarregarFiscalAsync(r);
        foreach (var caso in abertos)
        {
            // Carrega a versão de regra FIXADA no caso (não a em uso): reavaliar um caso antigo
            // sob uma versão nova mudaria prontidão/saídas/itens contra a regra à qual ele se vinculou (§13).
            var conteudo = await r.Regras.ConteudoPorIdAsync(caso.RegraVersaoId)
                ?? throw new InvalidOperationException($"regra fixada {caso.RegraVersaoId} do caso {caso.Id} não encontrada");
            var regra = new RegraEntregaveis(caso.RegraVersaoId, conteudo);
            var avaliar = new AvaliarCaso(r, regra, new Conferir(r, tol, tolVid, prolaboreBp));
            // estado_desde é gravado pelo banco (clock_timestamp); passamos um instante só por assinatura.
            await avaliar.ExecutarAsync(caso, DateTimeOffset.UtcNow);
        }
    }

    // ---- ConcluirTarefa (§8) ----
    public enum ConcluirErro { None, NaoEncontrado, Conflito, Invalido }
    public sealed record ConcluirResultado(ConcluirErro Erro, Entregavel? Entregavel, string? Mensagem);
    public sealed record SaidaDto(string Tributo, long? ValorCentavos, JsonElement Payload);

    public async Task<ConcluirResultado> ConcluirTarefaAsync(string titularId, Competencia competencia,
        string tipo, string ator, IReadOnlyList<SaidaDto> saidas)
    {
        await using var uow = await _fabrica.AbrirAsync();
        var r = uow.Repos;

        var caso = await r.Casos.PorChaveAsync(titularId, competencia);
        if (caso is null) return Falha(ConcluirErro.NaoEncontrado, "caso inexistente");
        // Trava o caso (mesma chave da abertura/publicação) e reivindica a LINHA do entregável antes de
        // validar estado: duas conclusões concorrentes do mesmo entregável pronto não podem ambas ler
        // 'pronto' e uma cair em 500 no UPDATE otimista — a perdedora reencontra estado != pronto e vira 409 (§8).
        await r.Casos.TravarCasoAsync(titularId, competencia);
        var ent = await r.Entregaveis.PorChaveComTravaAsync(caso.Id, tipo);
        if (ent is null) return Falha(ConcluirErro.NaoEncontrado, "entregável inexistente");
        if (ent.Estado != EstadoEntregavel.Pronto || ent.Executor == "sistema")
            return Falha(ConcluirErro.Conflito, "entregável não está pronto ou é de sistema");

        var conteudoRegra = await r.Regras.ConteudoPorIdAsync(caso.RegraVersaoId)
            ?? throw new InvalidOperationException($"regra fixada {caso.RegraVersaoId} do caso {caso.Id} não encontrada");
        var regra = new RegraEntregaveis(caso.RegraVersaoId, conteudoRegra);
        var item = regra.Item(tipo);
        var saidaRegra = item?.Saida;
        if (saidas.Count == 0 || saidaRegra is null)
            return Falha(ConcluirErro.Invalido, "sem saídas ou entregável sem saída na regra");

        var agora = await uow.AgoraAsync();
        var tipoSaida = saidaRegra.Value.GetProperty("tipo").GetString()!;

        // 4. cada saída vira fato (fonte=tarefa), ligada com papel 'saida'
        foreach (var s in saidas)
        {
            var cmd = new PublicarFatoCmd(caso.TitularId, caso.Competencia, tipoSaida, s.Tributo,
                s.ValorCentavos, s.Payload, "tarefa", null, agora);
            var rf = await PublicarFatoInternoAsync(r, cmd, reavaliar: false);
            await r.Entregaveis.LigarFatoAsync(ent.Id, rf.FatoId, "saida");
        }

        // 5. concluir tarefa aberta
        var tarefaId = await r.Tarefas.AbertaDoEntregavelAsync(ent.Id);
        if (tarefaId is { } tid) await r.Tarefas.ConcluirAsync(tid, agora);

        // 6. pronto -> processado (tarefa_concluida)
        await r.Entregaveis.TransicionarAsync(ent, new NovaTransicao(
            ent.Id, caso.Id, ent.Estado, EstadoEntregavel.Processado, "tarefa_concluida",
            "tarefa", tarefaId?.ToString(), ator, caso.RegraVersaoId), agora);

        // 7. conferir + desfecho
        var (tol, tolVid, prolaboreBp) = await CarregarFiscalAsync(r);
        var conferir = new Conferir(r, tol, tolVid, prolaboreBp);
        var ctx = Contexto.DeSnapshot(caso.Snapshot, caso.Competencia.MesDoTrimestre);
        var processado = await r.Entregaveis.PorChaveAsync(caso.Id, tipo) ?? ent;
        var ok = await conferir.ExecutarAsync(caso, processado, item!, ctx, agora);
        processado = await r.Entregaveis.PorChaveAsync(caso.Id, tipo) ?? processado;
        if (ok)
        {
            await r.Entregaveis.TransicionarAsync(processado, new NovaTransicao(
                processado.Id, caso.Id, processado.Estado, EstadoEntregavel.Validado, "conferencias_ok",
                "comando", null, "sistema", caso.RegraVersaoId), agora);
            var cat = await r.Catalogo.EntregavelTipoAsync(tipo);
            if (cat?.EventoPublicado is { } nome)
                await r.Eventos.GravarComIdAsync(nome, caso.Id, processado.Id, "entregavel_id", processado.Id);
        }
        else
        {
            await r.Entregaveis.TransicionarAsync(processado, new NovaTransicao(
                processado.Id, caso.Id, processado.Estado, EstadoEntregavel.Divergente, "conferencia_divergente",
                "conferencia", null, "sistema", caso.RegraVersaoId), agora);
        }

        // reavaliar casos abertos do titular (propagação e pós-validação)
        await ReavaliarAbertosAsync(r, caso.TitularId);

        var final = await r.Entregaveis.PorChaveAsync(caso.Id, tipo);
        await uow.ConfirmarAsync();
        return new ConcluirResultado(ConcluirErro.None, final, null);

        static ConcluirResultado Falha(ConcluirErro e, string msg) => new(e, null, msg);
    }

    private static async Task<(Tolerancia tol, Guid versaoId, int prolaboreBp)> CarregarFiscalAsync(IRepositorios r)
    {
        var tolInfo = await r.Regras.VersaoEmUsoAsync("fiscal.tolerancia")
            ?? throw new InvalidOperationException("fiscal.tolerancia sem versão em uso");
        var padrao = tolInfo.Conteudo.GetProperty("padrao");
        var tol = new Tolerancia(
            padrao.GetProperty("ok_ate_centavos").GetInt64(),
            padrao.GetProperty("alerta_ate_centavos").GetInt64());

        var prolaboreBp = 0;
        var parInfo = await r.Regras.VersaoEmUsoAsync("fiscal.parametros");
        if (parInfo is { } p && p.Conteudo.TryGetProperty("prolabore_percentual_bp", out var bp))
            prolaboreBp = bp.GetInt32();

        return (tol, tolInfo.Id, prolaboreBp);
    }
}
