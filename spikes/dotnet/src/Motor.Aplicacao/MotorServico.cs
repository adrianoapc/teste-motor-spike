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

    /// <summary>Publica o fato na transação dada. Se reavaliar=true, reavalia os casos abertos do titular.
    /// entregavelConcluido: quando chamado de ConcluirTarefa (§8.4), a invalidação NÃO atinge o próprio
    /// entregável sendo concluído.</summary>
    private async Task<ResultadoFato> PublicarFatoInternoAsync(IRepositorios r, PublicarFatoCmd cmd, bool reavaliar,
        Guid? entregavelConcluido = null)
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
        {
            // sem_mudança: nada grava, nenhuma invalidação nem acompanhamento (§6, §10.3).
            return new ResultadoFato(vigente.Id, vigente.Versao, EfeitoFato.SemMudanca);
        }

        var novaVersao = (vigente?.Versao ?? 0) + 1;
        var (id, versao) = await r.Fatos.GravarAsync(cmd, novaVersao, hash, vigente?.Id);
        var efeito = vigente is null ? EfeitoFato.Novo : EfeitoFato.NovaVersao;

        // 1. evento fato.publicado — caso_id só se existe caso do titular NAQUELA competência
        var caso = await r.Casos.PorChaveAsync(cmd.TitularId, cmd.Competencia);
        await r.Eventos.GravarComIdAsync("fato.publicado", caso?.Id, null, "fato_id", id);

        // Estado do E11 do caso ANTES da reavaliação desta publicação — decide protocolo vs reenvio (§10.3).
        var e11Antes = caso is null ? null
            : (await r.Entregaveis.PorChaveAsync(caso.Id, "E11"))?.Estado;

        // 2. invalidação (§11): só quando nova_versao e a versão anterior estava ligada a entregáveis.
        if (efeito == EfeitoFato.NovaVersao && vigente is not null)
            await InvalidarPorNovaVersaoAsync(r, vigente.Id, id, entregavelConcluido);

        // 3. reavaliação dos casos abertos do titular (§7.4).
        if (reavaliar)
            await ReavaliarAbertosAsync(r, cmd.TitularId);

        // 4. acompanhamento depois do protocolo (§10.3): depois de invalidação e reavaliação.
        if (caso is not null)
            await AcompanhamentoAsync(r, caso, cmd, id, e11Antes);

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
            var rf = await PublicarFatoInternoAsync(r, cmd, reavaliar: false, entregavelConcluido: ent.Id);
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

    // ---- Invalidação por nova versão de fato já usado (§11) ----
    private async Task InvalidarPorNovaVersaoAsync(IRepositorios r, Guid fatoAnteriorId, Guid fatoNovoId, Guid? entregavelConcluido)
    {
        // Sementes: entregáveis que tinham a VERSÃO ANTERIOR do fato ligada (qualquer papel),
        // menos o entregável que está sendo concluído (§8.4). Agrupe por caso (§11.1).
        var sementes = (await r.Entregaveis.ComFatoLigadoAsync(fatoAnteriorId))
            .Where(e => e.Id != entregavelConcluido)
            .ToList();
        if (sementes.Count == 0) return;

        var porCaso = sementes.GroupBy(e => e.CasoId);
        foreach (var grupo in porCaso)
        {
            var caso = await r.Casos.PorIdAsync(grupo.Key);
            if (caso is null) continue;

            if (caso.Estado == "encerrado")
            {
                // §11.3: nenhuma transição; UMA exceção competencia_encerrada por caso (abertura única).
                var exId = await r.Excecoes.AbrirUnicaSemEntregavelAsync(caso.Id, "competencia_encerrada", "O", caso.Carteira);
                if (exId is { } eid)
                    await r.Eventos.GravarComIdAsync("excecao.aberta", caso.Id, null, "excecao_id", eid);
                continue;
            }

            // §11.2: caso aberto, regra por estado, com cascata a partir de quem foi para invalidado.
            // Cada entregável é tratado no máximo uma vez por publicação.
            var tratados = new HashSet<Guid>();
            var fila = new Queue<Entregavel>(grupo.OrderBy(e => e.Tipo));
            while (fila.Count > 0)
            {
                var e = fila.Dequeue();
                if (!tratados.Add(e.Id)) continue;
                // relê o estado atual (uma semente pode já ter sido invalidada em cascata)
                var atual = await r.Entregaveis.PorChaveComTravaAsync(caso.Id, e.Tipo) ?? e;
                var foiInvalidado = await AplicarInvalidacaoEstadoAsync(r, caso, atual, fatoNovoId);
                if (foiInvalidado)
                {
                    foreach (var dep in await r.Entregaveis.DependentesDeAsync(caso.Id, atual.Id))
                        if (!tratados.Contains(dep.Id))
                            fila.Enqueue(dep);
                }
            }
        }
    }

    /// <summary>Aplica a tabela de §11.2 a um entregável de caso aberto. Devolve true se foi para invalidado.</summary>
    private async Task<bool> AplicarInvalidacaoEstadoAsync(IRepositorios r, Caso caso, Entregavel e, Guid fatoNovoId)
    {
        var agora = DateTimeOffset.UtcNow;
        switch (e.Estado)
        {
            case EstadoEntregavel.Pronto:
            case EstadoEntregavel.Processado:
            case EstadoEntregavel.Divergente:
            case EstadoEntregavel.Validado:
            case EstadoEntregavel.Liberado:
                var saiuDeDivergente = e.Estado == EstadoEntregavel.Divergente;
                await r.Entregaveis.TransicionarAsync(e, new NovaTransicao(
                    e.Id, caso.Id, e.Estado, EstadoEntregavel.Invalidado, "fato_alterado",
                    "fato", fatoNovoId.ToString(), "sistema", caso.RegraVersaoId), agora);
                await r.Eventos.GravarComIdAsync("entregavel.invalidado", caso.Id, e.Id, "entregavel_id", e.Id);
                await r.Tarefas.CancelarAbertasAsync(e.Id);
                if (saiuDeDivergente)
                    await r.Excecoes.ResolverCfAbertasAsync(e.Id, "fato_alterado");
                return true;

            case EstadoEntregavel.Disponibilizado:
                // Estado não muda; abre substituicao (abertura única §9.4). O pacote já protocolado
                // precisa ser substituído (§11.2).
                var exId = await r.Excecoes.AbrirUnicaAsync(caso.Id, e.Id, "substituicao", "O", caso.Carteira);
                if (exId is { } eid)
                    await r.Eventos.GravarComIdAsync("excecao.aberta", caso.Id, e.Id, "excecao_id", eid);
                return false;

            default:
                // aguardando_insumo, invalidado, encerrado: nada
                return false;
        }
    }

    // ---- Acompanhamento depois do protocolo (§10.3) ----
    private async Task AcompanhamentoAsync(IRepositorios r, Caso caso, PublicarFatoCmd cmd, Guid fatoNovoId,
        EstadoEntregavel? e11Antes)
    {
        // Só vale para o E11 e seu tributo de guia (DAS no SN, IRPJ no LP).
        var e11 = await r.Entregaveis.PorChaveAsync(caso.Id, "E11");
        if (e11 is null) return;
        var tributoGuia = TributoDaGuia(caso);
        if (cmd.Tributo != tributoGuia) return;

        var regra = cmd.Tipo;
        var acomp = await AcompanhamentoRegraAsync(r, caso);
        if (acomp is null) return;

        // Reenvio: documento_disponibilizado em NOVA versão com o E11 JÁ em disponibilizado antes
        // desta publicação (§10.3). O protocolo (§10.1), que leva liberado->disponibilizado, não é reenvio.
        if (regra == acomp.Value.DisponibilizadoTipo && e11Antes == EstadoEntregavel.Disponibilizado)
        {
            var guia = await r.Fatos.VigenteAsync(caso.TitularId, caso.Competencia, "guia", tributoGuia);
            if (guia is not null)
                await r.Eventos.GravarComDoisIdsAsync("documento.disponibilizado", caso.Id, e11.Id,
                    "entregavel_id", e11.Id, "fato_id", guia.Id);
            else
                await r.Eventos.GravarComIdAsync("documento.disponibilizado", caso.Id, e11.Id, "entregavel_id", e11.Id);
            await r.Excecoes.ResolverPorTiposAsync(e11.Id, new[] { "entrega_falhou" }, "reenviado");
            return;
        }

        // Os demais fatos de acompanhamento só valem quando o E11 já está em disponibilizado.
        if (e11Antes != EstadoEntregavel.Disponibilizado) return;

        if (regra == acomp.Value.FalhaTipo)
        {
            var exId = await r.Excecoes.AbrirUnicaAsync(caso.Id, e11.Id, "entrega_falhou", "O", caso.Carteira);
            if (exId is { } eid)
                await r.Eventos.GravarComIdAsync("excecao.aberta", caso.Id, e11.Id, "excecao_id", eid);
        }
        else if (regra == acomp.Value.ConfirmacaoTipo)
        {
            await r.Eventos.GravarComIdAsync("recebimento.confirmado", caso.Id, e11.Id, "entregavel_id", e11.Id);
            await r.Excecoes.ResolverPorTiposAsync(e11.Id,
                new[] { "entrega_falhou", "recebimento_pendente" }, "recebido");
        }
        else if (regra == acomp.Value.PendenciaTipo)
        {
            // Só se NÃO existe fato vigente entrega_confirmada com a mesma chave.
            var confirmada = await r.Fatos.VigenteAsync(caso.TitularId, caso.Competencia,
                acomp.Value.ConfirmacaoTipo, tributoGuia);
            if (confirmada is null)
            {
                var exId = await r.Excecoes.AbrirUnicaAsync(caso.Id, e11.Id, "recebimento_pendente", "O", caso.Carteira);
                if (exId is { } eid)
                    await r.Eventos.GravarComIdAsync("excecao.aberta", caso.Id, e11.Id, "excecao_id", eid);
            }
        }
    }

    private readonly record struct AcompRegra(string DisponibilizadoTipo, string FalhaTipo, string ConfirmacaoTipo, string PendenciaTipo);

    private async Task<AcompRegra?> AcompanhamentoRegraAsync(IRepositorios r, Caso caso)
    {
        var conteudo = await r.Regras.ConteudoPorIdAsync(caso.RegraVersaoId);
        if (conteudo is not { } c) return null;
        var regra = new RegraEntregaveis(caso.RegraVersaoId, c);
        var e11 = regra.Item("E11");
        if (e11?.PosValidacao is not { } pv) return null;
        var dispTipo = pv.TryGetProperty("disponibilizado_quando", out var dq) && dq.ValueKind == JsonValueKind.Object
            && dq.TryGetProperty("tipo", out var dqt) ? dqt.GetString()! : "documento_disponibilizado";
        if (!e11.Bruto.TryGetProperty("acompanhamento", out var ac) || ac.ValueKind != JsonValueKind.Object)
            return null;
        string Tipo(string chave, string padrao) =>
            ac.TryGetProperty(chave, out var o) && o.ValueKind == JsonValueKind.Object
            && o.TryGetProperty("tipo", out var t) ? t.GetString()! : padrao;
        return new AcompRegra(dispTipo, Tipo("falha", "entrega_falhou"),
            Tipo("confirmacao", "entrega_confirmada"), Tipo("pendencia", "recebimento_pendente"));
    }

    private static string TributoDaGuia(Caso caso)
    {
        var regime = caso.Snapshot.TryGetProperty("regime", out var r) && r.ValueKind == JsonValueKind.String
            ? r.GetString()! : "SN";
        return regime == "SN" ? "DAS" : "IRPJ";
    }

    // ---- RegistrarOverride (§8.1) ----
    public enum OverrideErro { None, NaoEncontrado, Conflito, Invalido }
    public sealed record OverrideResultado(OverrideErro Erro, Entregavel? Entregavel, string? Mensagem);

    public async Task<OverrideResultado> RegistrarOverrideAsync(string titularId, Competencia competencia,
        string tipo, string ator, string cf, string motivo)
    {
        await using var uow = await _fabrica.AbrirAsync();
        var r = uow.Repos;

        var caso = await r.Casos.PorChaveAsync(titularId, competencia);
        if (caso is null) return new OverrideResultado(OverrideErro.NaoEncontrado, null, "caso inexistente");
        await r.Casos.TravarCasoAsync(titularId, competencia);
        var ent = await r.Entregaveis.PorChaveComTravaAsync(caso.Id, tipo);
        if (ent is null) return new OverrideResultado(OverrideErro.NaoEncontrado, null, "entregável inexistente");

        // 3. estado != divergente, conf vigente inexistente/≠divergente-B/já com override -> 409
        if (ent.Estado != EstadoEntregavel.Divergente)
            return new OverrideResultado(OverrideErro.Conflito, null, "entregável não está divergente");
        var conf = await r.Conferencias.VigenteDaCfAsync(ent.Id, cf);
        if (conf is null || conf.Resultado != "divergente" || conf.Severidade != "B" || conf.TemOverride)
            return new OverrideResultado(OverrideErro.Conflito, null, "conferência não elegível a override");

        var agora = await uow.AgoraAsync();

        // 1. grava override na conferência vigente
        await r.Conferencias.GravarOverrideAsync(conf.Id, ator, motivo);
        // 2. resolve exceções abertas daquele entregável com tipo = cf (resolucao override)
        await r.Excecoes.ResolverPorTiposAsync(ent.Id, new[] { cf }, "override");
        // 3. evento conferencia.override
        await r.Eventos.GravarComIdAsync("conferencia.override", caso.Id, ent.Id, "conferencia_id", conf.Id);

        // 4. se nenhuma outra CF divergente B sem override: divergente -> validado + evento do tipo
        if (!await r.Conferencias.TemOutraDivergenteBSemOverrideAsync(ent.Id, conf.Id))
        {
            await r.Entregaveis.TransicionarAsync(ent, new NovaTransicao(
                ent.Id, caso.Id, ent.Estado, EstadoEntregavel.Validado, "override",
                "conferencia", conf.Id.ToString(), ator, caso.RegraVersaoId), agora);
            var cat = await r.Catalogo.EntregavelTipoAsync(tipo);
            if (cat?.EventoPublicado is { } nome)
                await r.Eventos.GravarComIdAsync(nome, caso.Id, ent.Id, "entregavel_id", ent.Id);
        }

        // 5. reavalia casos abertos do titular (propagação)
        await ReavaliarAbertosAsync(r, caso.TitularId);

        var final = await r.Entregaveis.PorChaveAsync(caso.Id, tipo);
        await uow.ConfirmarAsync();
        return new OverrideResultado(OverrideErro.None, final, null);
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
