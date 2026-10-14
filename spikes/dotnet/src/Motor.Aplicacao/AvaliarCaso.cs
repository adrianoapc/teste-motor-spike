using Motor.Dominio.Fatos;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

/// <summary>Avalia um caso até o ponto fixo (§7.4): prontidão, processamento de sistema,
/// conferências, pós-validação (§10.1) e encerramento (§10.2). Tudo na transação corrente.</summary>
public sealed class AvaliarCaso
{
    private readonly IRepositorios _r;
    private readonly RegraEntregaveis _regra;
    private readonly Conferir _conferir;

    // Estado atual de cada entregável do caso, mantido em sincronia com as transições feitas aqui:
    // a checagem de dependência lê o estado "no momento", já com as mudanças da mesma volta (§7.4),
    // sem reconsultar o banco a cada dependência.
    private Dictionary<Guid, EstadoEntregavel> _estados = new();
    private ILookup<Guid, Dependencia>? _dependencias;

    public AvaliarCaso(IRepositorios repos, RegraEntregaveis regra, Conferir conferir)
    {
        _r = repos;
        _regra = regra;
        _conferir = conferir;
    }

    public async Task ExecutarAsync(Caso caso, DateTimeOffset agora)
    {
        var ctx = Contexto.DeSnapshot(caso.Snapshot, caso.Competencia.MesDoTrimestre);

        bool mudou;
        do
        {
            mudou = false;
            var entregaveis = await _r.Entregaveis.DoCasoComTravaAsync(caso.Id);
            _estados = entregaveis.ToDictionary(x => x.Id, x => x.Estado);
            // ordenados por tipo (E01, E02, ...) pelo repositório
            foreach (var e in entregaveis)
            {
                var item = _regra.Item(e.Tipo);
                if (item is null) continue;

                if (e.Estado is EstadoEntregavel.AguardandoInsumo or EstadoEntregavel.Invalidado)
                {
                    if (await EntradasLidasSePronto(caso, e, item, ctx) is { } entradas)
                    {
                        foreach (var fato in entradas)
                            await _r.Entregaveis.LigarFatoAsync(e.Id, fato.Id, "entrada");
                        await Transicionar(caso, e, EstadoEntregavel.Pronto, "prontidao", "comando", null, "sistema", agora);
                        if (item.Executor == "humano")
                            await _r.Tarefas.CriarAsync(caso.Id, e.Id, "executar_entregavel", caso.Carteira);
                        mudou = true;
                    }
                    else if (e.Estado == EstadoEntregavel.Invalidado)
                    {
                        await Transicionar(caso, e, EstadoEntregavel.AguardandoInsumo, "prontidao", "comando", null, "sistema", agora);
                        mudou = true;
                    }
                }
                else if (e.Estado == EstadoEntregavel.Pronto && item.Executor == "sistema")
                {
                    await Transicionar(caso, e, EstadoEntregavel.Processado, "processamento_sistema", "comando", null, "sistema", agora);
                    await ConferirEDesfecharAsync(caso, e, item, ctx, agora);
                    mudou = true;
                }
                else if (e.Estado == EstadoEntregavel.Validado && item.EncerraCaso)
                {
                    await Transicionar(caso, e, EstadoEntregavel.Encerrado, "encerramento", "comando", null, "sistema", agora);
                    await _r.Casos.EncerrarAsync(caso.Id, agora);
                    await _r.Eventos.GravarComIdAsync("fechamento.concluido", caso.Id, null, "caso_id", caso.Id);
                    mudou = true;
                    break; // caso encerrado; nada mais a avaliar
                }
                else if (e.Estado == EstadoEntregavel.Validado && item.PosValidacao is { LiberacaoAutomatica: true })
                {
                    await Transicionar(caso, e, EstadoEntregavel.Liberado, "liberacao_automatica", "comando", null, "sistema", agora);
                    mudou = true;
                }
                else if (e.Estado == EstadoEntregavel.Liberado && item.PosValidacao is { } pos)
                {
                    // Protocolo (§10.1): disponibiliza quando chega o fato disponibilizado_quando com o
                    // tributo da guia de entrada do E11. O evento leva o fato_id da GUIA vigente (INV-14).
                    if (await ProtocoloDisponivelAsync(caso, pos, ctx))
                    {
                        var guia = await GuiaDeEntradaAsync(caso, ctx);
                        await Transicionar(caso, e, EstadoEntregavel.Disponibilizado, "documento_disponibilizado", "fato", null, "sistema", agora);
                        if (guia is not null)
                            await _r.Eventos.GravarComDoisIdsAsync("documento.disponibilizado", caso.Id, e.Id,
                                "entregavel_id", e.Id, "fato_id", guia.Id);
                        else
                            await _r.Eventos.GravarComIdAsync("documento.disponibilizado", caso.Id, e.Id, "entregavel_id", e.Id);
                        mudou = true;
                    }
                }
            }
        } while (mudou);
    }

    private async Task ConferirEDesfecharAsync(Caso caso, Entregavel e, ItemEntregavel item, Contexto ctx, DateTimeOffset agora)
    {
        // recarrega o entregável no estado processado (version mudou)
        var atual = await _r.Entregaveis.PorChaveAsync(caso.Id, e.Tipo) ?? e;
        var ok = await _conferir.ExecutarAsync(caso, atual, item, ctx, agora);
        atual = await _r.Entregaveis.PorChaveAsync(caso.Id, e.Tipo) ?? atual;
        if (ok)
        {
            await Transicionar(caso, atual, EstadoEntregavel.Validado, "conferencias_ok", "comando", null, "sistema", agora);
            await PublicarEventoDoTipoAsync(caso, atual);
        }
        else
        {
            await Transicionar(caso, atual, EstadoEntregavel.Divergente, "conferencia_divergente", "conferencia", null, "sistema", agora);
        }
    }

    private async Task PublicarEventoDoTipoAsync(Caso caso, Entregavel e)
    {
        var cat = await _r.Catalogo.EntregavelTipoAsync(e.Tipo);
        if (cat?.EventoPublicado is { } nome)
            await _r.Eventos.GravarComIdAsync(nome, caso.Id, e.Id, "entregavel_id", e.Id);
    }

    /// <summary>Existe o fato <c>disponibilizado_quando</c> (tipo da regra) com o tributo da guia de entrada?</summary>
    private async Task<bool> ProtocoloDisponivelAsync(Caso caso, PosValidacaoRegra pos, Contexto ctx)
    {
        if (pos.DisponibilizadoQuandoTipo is not { } tipo) return false;
        if (_regra.TributoDaGuia(ctx, caso.Competencia) is not { } tributo) return false;
        return await _r.Fatos.VigenteAsync(caso.TitularId, caso.Competencia, tipo, tributo) is not null;
    }

    /// <summary>Fato vigente da guia de entrada do E11 (tributo lido da regra). É o fato_id que o
    /// evento documento.disponibilizado carrega (§10.1, INV-14) e que o acompanhamento usa.</summary>
    private async Task<FatoVigente?> GuiaDeEntradaAsync(Caso caso, Contexto ctx)
        => _regra.TributoDaGuia(ctx, caso.Competencia) is { } tributo
            ? await _r.Fatos.VigenteAsync(caso.TitularId, caso.Competencia, RegraEntregaveis.TipoGuia, tributo)
            : null;

    /// <summary>Aplica a regra de prontidão (§7.2, pura em <see cref="Prontidao"/>): devolve os fatos de
    /// entrada a ligar se o entregável pode ficar pronto, ou null. Só consulta fatos se as dependências já valem.</summary>
    private async Task<IReadOnlyList<FatoVigente>?> EntradasLidasSePronto(Caso caso, Entregavel e, ItemEntregavel item, Contexto ctx)
    {
        _dependencias ??= (await _r.Entregaveis.DependenciasDoCasoAsync(caso.Id)).ToLookup(d => d.EntregavelId);
        var dependencias = _dependencias[e.Id]
            .Select(d => new DependenciaLida(_estados.TryGetValue(d.DependeDeId, out var s) ? s : null, d.EstadoMinimo))
            .ToList();
        if (!Prontidao.DependenciasSatisfeitas(dependencias)) return null;

        var fatos = new List<FatoVigente>();
        var presentes = new List<bool>();
        foreach (var entrada in item.EntradasResolvidas(ctx, caso.Competencia))
        {
            var f = await _r.Fatos.VigenteAsync(caso.TitularId, entrada.Competencia, entrada.Tipo, entrada.Tributo);
            presentes.Add(f is not null);
            if (f is null) break;
            fatos.Add(f);
        }
        return Prontidao.Satisfeita(dependencias, presentes) ? fatos : null;
    }

    private async Task Transicionar(Caso caso, Entregavel e, EstadoEntregavel para, string motivo,
        string? causadoPorTipo, string? causadoPorId, string ator, DateTimeOffset agora)
    {
        await _r.Entregaveis.TransicionarAsync(e, new NovaTransicao(
            e.Id, caso.Id, e.Estado, para, motivo, causadoPorTipo, causadoPorId, ator, caso.RegraVersaoId), agora);
        _estados[e.Id] = para;
    }
}
