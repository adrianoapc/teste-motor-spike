using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

/// <summary>Executa as conferências de um entregável em 'processado' e aplica o desfecho (§9).</summary>
public sealed class Conferir
{
    private readonly IRepositorios _r;
    private readonly Tolerancia _tol;
    private readonly Guid _tolVersaoId;
    private readonly int _prolaboreBp;

    public Conferir(IRepositorios repos, Tolerancia tol, Guid tolVersaoId, int prolaboreBp)
    {
        _r = repos;
        _tol = tol;
        _tolVersaoId = tolVersaoId;
        _prolaboreBp = prolaboreBp;
    }

    /// <summary>Roda as conferências do item cujo quando casa; devolve true se o entregável
    /// deve ir para 'validado', false se foi para 'divergente' (alguma B divergente).</summary>
    public async Task<bool> ExecutarAsync(Caso caso, Entregavel e, ItemEntregavel item, Contexto ctx,
        DateTimeOffset agora)
    {
        var fatos = await _r.Fatos.VigentesDoCasoAsync(caso.TitularId, caso.Competencia);
        var algumaBloqueanteDivergente = false;

        foreach (var cfItem in item.Conferencias)
        {
            var cf = cfItem.GetProperty("cf").GetString()!;
            var quando = cfItem.TryGetProperty("quando", out var q) ? q : (JsonElement?)null;
            if (!Condicao.Casa(quando, ctx))
                continue;

            var tipoCat = await _r.Catalogo.ConferenciaTipoAsync(cf)
                ?? throw new InvalidOperationException($"catálogo sem {cf}");

            var calc = CalculadoraConferencias.Calcular(cf, caso, item, fatos, _prolaboreBp);

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
                    severidade, "S", tipoCat, agora);
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
                var dif = Math.Abs((esperado ?? 0) - (obtido ?? 0));
                (resultado, severidade) = Classificacao.Numerica(dif, _tol, tipoCat.Severidade);
                diferenca = Json($"{{\"centavos\":{dif}}}");
            }

            await GravarEDesfechoAsync(caso, e, cf, calc, resultado, esperado, obtido, diferenca,
                severidade, tipoCat.ClassePadrao, tipoCat, agora);

            if (resultado == ResultadoConferencia.Divergente && severidade == "B")
                algumaBloqueanteDivergente = true;
        }

        return !algumaBloqueanteDivergente;
    }

    private async Task GravarEDesfechoAsync(Caso caso, Entregavel e, string cf, CalculoConferencia calc,
        ResultadoConferencia resultado, long? esperado, long? obtido, JsonElement diferenca,
        string severidade, string classe, ConferenciaTipoCatalogo tipoCat, DateTimeOffset agora)
    {
        var entradaHash = EntradaHash.Calcular(cf, calc.FatosUsados, _tolVersaoId);
        var (confId, efetivo) = await _r.Conferencias.InserirOuReaproveitarAsync(new NovaConferencia(
            cf, caso.Id, e.Id, _tolVersaoId, calc.FatosUsados, entradaHash,
            resultado, esperado, obtido, diferenca, severidade, classe));

        if (efetivo == ResultadoConferencia.Divergente && severidade == "B")
        {
            // divergência bloqueante: exceção + eventos (§9.3)
            var excId = await _r.Excecoes.AbrirAsync(caso.Id, e.Id, cf, classe, caso.Carteira);
            await _r.Eventos.GravarComIdAsync("conferencia.divergente", caso.Id, e.Id, "conferencia_id", confId);
            await _r.Eventos.GravarComIdAsync("excecao.aberta", caso.Id, e.Id, "excecao_id", excId);
        }
    }

    private static JsonElement Json(string s)
    {
        using var doc = JsonDocument.Parse(s);
        return doc.RootElement.Clone();
    }
}
