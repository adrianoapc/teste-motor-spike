using System.Text.Json;
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
            // ordenados por tipo (E01, E02, ...) pelo repositório
            foreach (var e in entregaveis)
            {
                var item = _regra.Item(e.Tipo);
                if (item is null) continue;

                if (e.Estado is EstadoEntregavel.AguardandoInsumo or EstadoEntregavel.Invalidado)
                {
                    if (await EntradasDepsSatisfeitasAsync(caso, e, item, ctx))
                    {
                        await LigarEntradasAsync(caso, e, item, ctx);
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
                else if (e.Estado == EstadoEntregavel.Validado && TemLiberacaoAutomatica(item))
                {
                    await Transicionar(caso, e, EstadoEntregavel.Liberado, "liberacao_automatica", "comando", null, "sistema", agora);
                    mudou = true;
                }
                else if (e.Estado == EstadoEntregavel.Liberado && item.PosValidacao is not null)
                {
                    if (await FatoPosExisteAsync(caso, item, "disponibilizado_quando"))
                    {
                        await Transicionar(caso, e, EstadoEntregavel.Disponibilizado, "documento_disponibilizado", "fato", null, "sistema", agora);
                        await _r.Eventos.GravarComIdAsync("documento.disponibilizado", caso.Id, e.Id, "entregavel_id", e.Id);
                        mudou = true;
                    }
                }
                else if (e.Estado == EstadoEntregavel.Disponibilizado && item.PosValidacao is not null)
                {
                    if (await FatoPosExisteAsync(caso, item, "pago_quando"))
                    {
                        await Transicionar(caso, e, EstadoEntregavel.Pago, "guia_paga", "fato", null, "sistema", agora);
                        await _r.Eventos.GravarComIdAsync("guia.paga", caso.Id, e.Id, "entregavel_id", e.Id);
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

    private static bool TemLiberacaoAutomatica(ItemEntregavel item)
    {
        if (item.PosValidacao is not { } pv) return false;
        return pv.TryGetProperty("liberacao", out var l) && l.ValueKind == JsonValueKind.String
            && l.GetString() == "automatica";
    }

    private async Task<bool> FatoPosExisteAsync(Caso caso, ItemEntregavel item, string chaveQuando)
    {
        if (item.PosValidacao is not { } pv) return false;
        if (!pv.TryGetProperty(chaveQuando, out var cfg) || cfg.ValueKind != JsonValueKind.Object) return false;
        var tipo = cfg.GetProperty("tipo").GetString()!;
        var tributo = TributoDaGuia(caso);
        var f = await _r.Fatos.VigenteAsync(caso.TitularId, caso.Competencia, tipo, tributo);
        return f is not null;
    }

    private static string TributoDaGuia(Caso caso)
    {
        var regime = caso.Snapshot.TryGetProperty("regime", out var r) && r.ValueKind == JsonValueKind.String
            ? r.GetString()! : "SN";
        return regime == "SN" ? "DAS" : "IRPJ";
    }

    private async Task<bool> EntradasDepsSatisfeitasAsync(Caso caso, Entregavel e, ItemEntregavel item, Contexto ctx)
    {
        // 1. dependências ≥ estado_minimo
        var deps = await _r.Entregaveis.DependenciasDoAsync(e.Id);
        foreach (var dep in deps)
        {
            var alvo = (await _r.Entregaveis.DoCasoComTravaAsync(caso.Id)).FirstOrDefault(x => x.Id == dep.DependeDeId);
            if (alvo is null) return false;
            if (!alvo.Estado.PeloMenos(dep.EstadoMinimo)) return false;
        }
        // 2. toda entrada resolvida presente
        foreach (var entrada in EntradasResolvidas(caso, item, ctx))
        {
            var f = await _r.Fatos.VigenteAsync(caso.TitularId, entrada.Competencia, entrada.Tipo, entrada.Tributo);
            if (f is null) return false;
        }
        return true;
    }

    private async Task LigarEntradasAsync(Caso caso, Entregavel e, ItemEntregavel item, Contexto ctx)
    {
        foreach (var entrada in EntradasResolvidas(caso, item, ctx))
        {
            var f = await _r.Fatos.VigenteAsync(caso.TitularId, entrada.Competencia, entrada.Tipo, entrada.Tributo);
            if (f is not null)
                await _r.Entregaveis.LigarFatoAsync(e.Id, f.Id, "entrada");
        }
    }

    private sealed record EntradaResolvida(Competencia Competencia, string Tipo, string Tributo);

    private static IEnumerable<EntradaResolvida> EntradasResolvidas(Caso caso, ItemEntregavel item, Contexto ctx)
    {
        foreach (var entrada in item.Entradas)
        {
            var quando = entrada.TryGetProperty("quando", out var q) ? q : (JsonElement?)null;
            if (!Condicao.Casa(quando, ctx)) continue;
            var tipo = entrada.GetProperty("tipo").GetString()!;
            var tributo = entrada.TryGetProperty("tributo", out var t) && t.ValueKind == JsonValueKind.String
                ? t.GetString()! : "";
            var rel = entrada.TryGetProperty("competencia_relativa", out var cr) && cr.ValueKind == JsonValueKind.Number
                ? cr.GetInt32() : 0;
            yield return new EntradaResolvida(caso.Competencia.Somar(rel), tipo, tributo);
        }
    }

    private async Task Transicionar(Caso caso, Entregavel e, EstadoEntregavel para, string motivo,
        string? causadoPorTipo, string? causadoPorId, string ator, DateTimeOffset agora)
    {
        await _r.Entregaveis.TransicionarAsync(e, new NovaTransicao(
            e.Id, caso.Id, e.Estado, para, motivo, causadoPorTipo, causadoPorId, ator, caso.RegraVersaoId), agora);
    }
}
