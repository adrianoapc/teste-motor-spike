using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

/// <summary>Executa as conferências de um entregável em 'processado' e aplica o desfecho (§9).</summary>
public sealed class Conferir
{
    private readonly IRepositorios _r;
    private readonly FiscalEmUso _fiscal;
    private readonly RegraEntregaveis _regra;

    public Conferir(IRepositorios repos, FiscalEmUso fiscal, RegraEntregaveis regra)
    {
        _r = repos;
        _fiscal = fiscal;
        _regra = regra;
    }

    /// <summary>Confere o entregável em 'processado' e aplica o desfecho (§9.3): 'validado' (e o evento do
    /// tipo) ou 'divergente'. Relê o entregável antes e depois, pois a versão muda a cada transição.</summary>
    public async Task<EstadoEntregavel> ConferirEDesfecharAsync(Caso caso, Entregavel e, ItemEntregavel item, Contexto ctx)
    {
        var atual = await _r.Entregaveis.PorChaveAsync(caso.Id, e.Tipo) ?? e;
        var ok = await ExecutarAsync(caso, atual, item, ctx);
        atual = await _r.Entregaveis.PorChaveAsync(caso.Id, e.Tipo) ?? atual;
        if (ok)
        {
            await Transicoes.TransicionarAsync(_r, caso, atual, EstadoEntregavel.Validado, "conferencias_ok");
            await Transicoes.PublicarEventoDoTipoAsync(_r, caso, atual);
            return EstadoEntregavel.Validado;
        }
        await Transicoes.TransicionarAsync(_r, caso, atual, EstadoEntregavel.Divergente, "conferencia_divergente",
            causadoPorTipo: "conferencia");
        return EstadoEntregavel.Divergente;
    }

    /// <summary>Roda as conferências do item cujo quando casa; devolve true se o entregável
    /// deve ir para 'validado', false se alguma B divergiu.</summary>
    private async Task<bool> ExecutarAsync(Caso caso, Entregavel e, ItemEntregavel item, Contexto ctx)
    {
        var fatos = await _r.Fatos.VigentesDoCasoAsync(caso.TitularId, caso.Competencia);
        var algumaBloqueanteDivergente = false;
        // Folha resolvida da entrada do item (§7.1): a regra v3 do E08 declara 'folha' com
        // competencia_relativa -1, então esta é a folha do mês anterior. CF-09 lê os três pares dela.
        // Em outra competência que não a do caso, o fato não está em VigentesDoCasoAsync — busca direto.
        var folhaConf = await ResolverFolhaAsync(caso, item, ctx);
        var contextoCalculo = new ContextoCalculo(caso.Competencia, item, ctx,
            _regra.TributoDaGuia(ctx, caso.Competencia), _fiscal.ProlaboreBp, folhaConf);

        foreach (var cfItem in item.Conferencias)
        {
            var cf = cfItem.Cf;
            if (!Condicao.Casa(cfItem.Quando, ctx))
                continue;

            var tipoCat = await _r.Conferencias.TipoCatalogoAsync(cf)
                ?? throw new InvalidOperationException($"catálogo sem {cf}");

            var calc = CalculadoraConferencias.Calcular(cf, contextoCalculo, fatos);

            ResultadoConferencia resultado;
            string severidade;
            JsonElement diferenca;
            long? esperado = calc.Esperado;
            long? obtido = calc.Obtido;

            if (calc.FatoAusente is not null)
            {
                resultado = ResultadoConferencia.Divergente;
                severidade = tipoCat.Severidade;
                esperado = null;
                obtido = null;
                diferenca = Json($"{{\"erro\":\"fato ausente: {calc.FatoAusente}\"}}");
                // classe S quando fato ausente
                await GravarEDesfechoAsync(caso, e, cf, calc, resultado, esperado, obtido, diferenca,
                    severidade, "S");
                if (severidade == "B") algumaBloqueanteDivergente = true;
                continue;
            }

            if (calc.OperandoInvalido is not null)
            {
                // operando malformado (tipo/faixa): divergente classe S, sem 500
                resultado = ResultadoConferencia.Divergente;
                severidade = tipoCat.Severidade;
                esperado = null;
                obtido = null;
                diferenca = JsonSerializer.SerializeToElement(new { erro = $"operando inválido: {calc.OperandoInvalido}" });
                await GravarEDesfechoAsync(caso, e, cf, calc, resultado, esperado, obtido, diferenca,
                    severidade, "S");
                if (severidade == "B") algumaBloqueanteDivergente = true;
                continue;
            }

            if (calc.Textual is not null)
            {
                // CF-06: comparação de texto
                resultado = calc.Textual.Value.Igual ? ResultadoConferencia.Ok : ResultadoConferencia.Divergente;
                severidade = tipoCat.Severidade;
                diferenca = calc.Textual.Value.Diferenca;
                esperado = null;
                obtido = null;
            }
            else if (calc.ExistenciaOk is not null)
            {
                // CF-12: ok se existe
                resultado = calc.ExistenciaOk.Value ? ResultadoConferencia.Ok : ResultadoConferencia.Divergente;
                severidade = tipoCat.Severidade;
                diferenca = Json("{\"centavos\":0}");
                esperado = null;
                obtido = null;
            }
            else
            {
                var dif = Int128.Abs((Int128)(esperado ?? 0) - (obtido ?? 0));
                (resultado, severidade) = Classificacao.Numerica(dif, _fiscal.Tolerancia, tipoCat.Severidade);
                diferenca = Json($"{{\"centavos\":{dif}}}");
            }

            await GravarEDesfechoAsync(caso, e, cf, calc, resultado, esperado, obtido, diferenca,
                severidade, tipoCat.ClassePadrao);

            if (resultado == ResultadoConferencia.Divergente && severidade == "B")
                algumaBloqueanteDivergente = true;
        }

        return !algumaBloqueanteDivergente;
    }

    private async Task GravarEDesfechoAsync(Caso caso, Entregavel e, string cf, CalculoConferencia calc,
        ResultadoConferencia resultado, long? esperado, long? obtido, JsonElement diferenca,
        string severidade, string classe)
    {
        var entradaHash = EntradaHash.Calcular(cf, calc.FatosUsados, _fiscal.ToleranciaVersaoId, EntradaHash.SemanticaCalculo);
        var (confId, efetivo) = await _r.Conferencias.InserirOuReaproveitarAsync(new NovaConferencia(
            cf, caso.Id, e.Id, _fiscal.ToleranciaVersaoId, calc.FatosUsados, entradaHash,
            resultado, esperado, obtido, diferenca, severidade, classe, EntradaHash.SemanticaCalculo));

        if (efetivo == ResultadoConferencia.Divergente && severidade == "B")
        {
            // divergência bloqueante: exceção (abertura única §9.4) + eventos (§9.3)
            var excId = await _r.Excecoes.AbrirUnicaAsync(caso.Id, e.Id, cf, classe, caso.Carteira);
            await _r.Eventos.GravarComIdAsync("conferencia.divergente", caso.Id, e.Id, "conferencia_id", confId);
            if (excId is { } eid)
                await _r.Eventos.GravarComIdAsync("excecao.aberta", caso.Id, e.Id, "excecao_id", eid);
        }
    }

    /// <summary>Folha resolvida como entrada do item (§7.1): tipo 'folha', competência deslocada por
    /// <c>competencia_relativa</c> (a regra v3 do E08 aponta -1 = mês anterior). Fora da competência do
    /// caso o fato não está em VigentesDoCasoAsync, então busca pela chave. Nulo se o item não tem
    /// entrada de folha que case o contexto, ou se a folha ainda não foi publicada.</summary>
    private async Task<Motor.Dominio.Fatos.FatoVigente?> ResolverFolhaAsync(Caso caso, ItemEntregavel item, Contexto ctx)
    {
        var folha = item.EntradasResolvidas(ctx, caso.Competencia).FirstOrDefault(e => e.Tipo == "folha");
        if (folha.Tipo != "folha") return null; // struct default => sem entrada de folha
        return await _r.Fatos.VigenteAsync(caso.TitularId, folha.Competencia, "folha", folha.Tributo);
    }

    private static JsonElement Json(string s)
    {
        using var doc = JsonDocument.Parse(s);
        return doc.RootElement.Clone();
    }
}
