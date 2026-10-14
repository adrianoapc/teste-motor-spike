using System.Text.Json;
using Motor.Dominio.Fatos;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Dominio.Conferencias;

public readonly record struct ComparacaoTextual(bool Igual, JsonElement Diferenca);

/// <summary>Resultado de calcular uma conferência: valores + rastro. Exatamente um "modo" é usado.</summary>
public sealed record CalculoConferencia(
    long? Esperado,
    long? Obtido,
    IReadOnlyList<FatoUsado> FatosUsados,
    string? FatoAusente = null,
    ComparacaoTextual? Textual = null,
    bool? ExistenciaOk = null,
    string? OperandoInvalido = null);

/// <summary>O que as fórmulas precisam além dos fatos: competência, item da regra, contexto do caso,
/// tributo da guia de entrada do item de protocolo (lido da regra pinada, §10.1) e o percentual do
/// pró-labore (<c>null</c> = ausente ou malformado em <c>fiscal.parametros</c>).</summary>
public sealed record ContextoCalculo(
    Competencia Competencia, ItemEntregavel Item, Contexto Contexto, string? TributoGuia, long? ProlaboreBp);

/// <summary>Fórmulas das conferências do spike (§9.1). Puras: recebem os fatos vigentes já lidos.</summary>
public static class CalculadoraConferencias
{
    public static CalculoConferencia Calcular(string cf, ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        return cf switch
        {
            "CF-01" => Cf01(fatos),
            "CF-04" => Cf04(fatos),
            "CF-05" => Cf05(c, fatos),
            "CF-06" => Cf06(c, fatos),
            "CF-07" => Cf07(c, fatos),
            "CF-08" => Cf08(c, fatos),
            "CF-09" => Cf09(fatos),
            "CF-12" => Cf12(c, fatos),
            _ => throw new InvalidOperationException($"CF não implementada no spike: {cf}"),
        };
    }

    private static FatoVigente? Buscar(IReadOnlyList<FatoVigente> fatos, string tipo, string tributo = "")
        => fatos.FirstOrDefault(f => f.Tipo == tipo && f.Tributo == tributo);

    private static FatoUsado U(FatoVigente f) => new(f.Id, f.Versao);

    // CF-01 (E01): esperado = faturamento_mes.valor; obtido = notas_oneflow.valor
    private static CalculoConferencia Cf01(IReadOnlyList<FatoVigente> fatos)
    {
        var fat = Buscar(fatos, "faturamento_mes");
        var notas = Buscar(fatos, "notas_oneflow");
        if (fat is null) return Ausente("faturamento_mes", "");
        if (notas is null) return Ausente("notas_oneflow", "", fat);
        return new CalculoConferencia(fat.ValorCentavos, notas.ValorCentavos, new[] { U(fat), U(notas) });
    }

    // CF-04 (E03 SN): esperado = arred(faturamento_mes.valor * aliquota_bp / 10000); obtido = apurado[DAS].valor
    // ("DAS" é literal na fórmula normativa de §9.1; só roda no SN pelo quando da regra.)
    private static CalculoConferencia Cf04(IReadOnlyList<FatoVigente> fatos)
    {
        var fat = Buscar(fatos, "faturamento_mes");
        var aliq = Buscar(fatos, "aliquota_mes");
        var apurado = Buscar(fatos, "apurado", "DAS");
        if (fat is null) return Ausente("faturamento_mes", "");
        if (aliq is null) return Ausente("aliquota_mes", "", fat);
        if (apurado is null) return Ausente("apurado", "DAS", fat, aliq);
        long bp = 0;
        if (aliq.Payload.TryGetProperty("aliquota_bp", out var b) && !LerInt64(b, out bp))
            return Invalido("aliquota_mes.aliquota_bp", fat, aliq, apurado);
        if (!ProdutoArredondado(fat.ValorCentavos ?? 0, bp, out var esperado))
            return Invalido("faturamento_mes*aliquota_bp fora de int64", fat, aliq, apurado);
        return new CalculoConferencia(esperado, apurado.ValorCentavos, new[] { U(fat), U(aliq), U(apurado) });
    }

    // CF-05 (E11): esperado = apurado[T].valor, T = tributo da guia de entrada do E11 (regra); obtido = guia[T].valor
    private static CalculoConferencia Cf05(ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        if (c.TributoGuia is not { } tributo) return Invalido(SemGuiaNaRegra);
        var apurado = Buscar(fatos, "apurado", tributo);
        var guia = Buscar(fatos, RegraEntregaveis.TipoGuia, tributo);
        if (apurado is null) return Ausente("apurado", tributo);
        if (guia is null) return Ausente(RegraEntregaveis.TipoGuia, tributo, apurado);
        return new CalculoConferencia(apurado.ValorCentavos, guia.ValorCentavos, new[] { U(apurado), U(guia) });
    }

    // CF-06 (E11): ok se guia.payload.competencia_impressa = competência do caso
    private static CalculoConferencia Cf06(ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        if (c.TributoGuia is not { } tributo) return Invalido(SemGuiaNaRegra);
        var guia = Buscar(fatos, RegraEntregaveis.TipoGuia, tributo);
        if (guia is null) return Ausente(RegraEntregaveis.TipoGuia, tributo);
        var impressa = guia.Payload.TryGetProperty("competencia_impressa", out var ci) && ci.ValueKind == JsonValueKind.String
            ? ci.GetString()! : "";
        var esperada = c.Competencia.ToString();
        var igual = string.Equals(impressa, esperada, StringComparison.Ordinal);
        // Serializa via JsonSerializer: 'impressa' pode conter control chars (ex.: OCR com \n),
        // que o escape manual (só aspas/barra) deixaria passar e quebraria JsonDocument.Parse.
        var dif = JsonSerializer.SerializeToElement(new { esperada, impressa });
        return new CalculoConferencia(null, null, new[] { U(guia) }, Textual: new ComparacaoTextual(igual, dif));
    }

    // CF-07 (E04): esperado = apurado[T].valor (T = tributo da guia: DAS no SN, IRPJ no LP);
    // obtido = soma(divisao_socios.parcelas[].valor_centavos)
    private static CalculoConferencia Cf07(ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        if (c.TributoGuia is not { } tributo) return Invalido(SemGuiaNaRegra);
        var apurado = Buscar(fatos, "apurado", tributo);
        var div = Buscar(fatos, "divisao_socios");
        if (apurado is null) return Ausente("apurado", tributo);
        if (div is null) return Ausente("divisao_socios", "", apurado);
        Int128 soma = 0;
        // 'parcelas' é o container da fórmula: ausente, null, objeto ou escalar => operando inválido.
        if (!div.Payload.TryGetProperty("parcelas", out var parcelas) || parcelas.ValueKind != JsonValueKind.Array)
            return Invalido("divisao_socios.parcelas não é array", apurado, div);
        foreach (var p in parcelas.EnumerateArray())
        {
            if (p.ValueKind != JsonValueKind.Object) return Invalido("divisao_socios.parcelas[] não é objeto", apurado, div);
            if (p.TryGetProperty("valor_centavos", out var v) && v.ValueKind != JsonValueKind.Null)
            {
                if (!LerInt64(v, out var n)) return Invalido("divisao_socios.parcelas[].valor_centavos", apurado, div);
                soma += n;
            }
        }
        if (soma > long.MaxValue || soma < long.MinValue) return Invalido("soma das parcelas fora de int64", apurado, div);
        return new CalculoConferencia(apurado.ValorCentavos, (long)soma, new[] { U(apurado), U(div) });
    }

    // CF-08 (E07 SN): esperado = arred(faturamento_mes.valor * prolabore_percentual_bp / 10000); obtido = prolabore.valor
    private static CalculoConferencia Cf08(ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        var fat = Buscar(fatos, "faturamento_mes");
        var prolabore = Buscar(fatos, "prolabore");
        if (fat is null) return Ausente("faturamento_mes", "");
        if (prolabore is null) return Ausente("prolabore", "", fat);
        if (c.ProlaboreBp is not { } bp)
            return Invalido("fiscal.parametros.prolabore_percentual_bp ausente ou malformado", fat, prolabore);
        if (!ProdutoArredondado(fat.ValorCentavos ?? 0, bp, out var esperado))
            return Invalido("faturamento_mes*prolabore_bp fora de int64", fat, prolabore);
        return new CalculoConferencia(esperado, prolabore.ValorCentavos, new[] { U(fat), U(prolabore) });
    }

    // CF-09 (E08): INSS e FGTS; esperado=folha.payload.<t>_centavos; obtido=guia[t].valor; diferença = maior das duas
    private static CalculoConferencia Cf09(IReadOnlyList<FatoVigente> fatos)
    {
        var folha = Buscar(fatos, "folha");
        var guiaInss = Buscar(fatos, RegraEntregaveis.TipoGuia, "INSS");
        var guiaFgts = Buscar(fatos, RegraEntregaveis.TipoGuia, "FGTS");
        if (folha is null) return Ausente("folha", "");
        if (guiaInss is null) return Ausente(RegraEntregaveis.TipoGuia, "INSS", folha);
        if (guiaFgts is null) return Ausente(RegraEntregaveis.TipoGuia, "FGTS", folha, guiaInss);
        if (!Campo(folha, "inss_centavos", out var inssEsp)) return Invalido("folha.inss_centavos", folha, guiaInss, guiaFgts);
        if (!Campo(folha, "fgts_centavos", out var fgtsEsp)) return Invalido("folha.fgts_centavos", folha, guiaInss, guiaFgts);
        var difInss = Int128.Abs((Int128)inssEsp - (guiaInss.ValorCentavos ?? 0));
        var difFgts = Int128.Abs((Int128)fgtsEsp - (guiaFgts.ValorCentavos ?? 0));
        // Representamos esperado/obtido pelo tributo de MAIOR diferença (a conferência reporta a maior).
        var usados = new[] { U(folha), U(guiaInss), U(guiaFgts) };
        if (difFgts > difInss)
            return new CalculoConferencia(fgtsEsp, guiaFgts.ValorCentavos, usados);
        return new CalculoConferencia(inssEsp, guiaInss.ValorCentavos, usados);
    }

    // CF-12 (E10): ok se existe recibo_obrigacao com o tributo exigido pela entrada do item (regra)
    private static CalculoConferencia Cf12(ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        var tributo = c.Item.EntradasResolvidas(c.Contexto, c.Competencia)
            .Where(e => e.Tipo == RegraEntregaveis.TipoRecibo).Select(e => e.Tributo).FirstOrDefault();
        if (tributo is null) return Invalido("item sem entrada recibo_obrigacao na regra");
        var recibo = Buscar(fatos, RegraEntregaveis.TipoRecibo, tributo);
        if (recibo is null)
            return new CalculoConferencia(null, null, Array.Empty<FatoUsado>(), ExistenciaOk: false);
        return new CalculoConferencia(null, null, new[] { U(recibo) }, ExistenciaOk: true);
    }

    private const string SemGuiaNaRegra = "regra sem entrada guia no item de protocolo";

    /// <summary>Lê int64 sem lançar: número inteiro dentro da faixa => true.</summary>
    private static bool LerInt64(JsonElement v, out long n)
    {
        n = 0;
        return v.ValueKind == JsonValueKind.Number && v.TryGetInt64(out n);
    }

    /// <summary>Campo ausente/null => 0 (comportamento anterior); presente e inválido => false.</summary>
    private static bool Campo(FatoVigente f, string nome, out long n)
    {
        n = 0;
        if (!f.Payload.TryGetProperty(nome, out var v) || v.ValueKind == JsonValueKind.Null) return true;
        return LerInt64(v, out n);
    }

    /// <summary>arred(valor * bp / 10000) com produto em Int128; false se o resultado não couber em int64.</summary>
    private static bool ProdutoArredondado(long valor, long bp, out long resultado)
    {
        var r = Arredondamento.MeioParaPar((Int128)valor * bp, (Int128)10000);
        resultado = 0;
        if (r > long.MaxValue || r < long.MinValue) return false;
        resultado = (long)r;
        return true;
    }

    // Resultados sem valor ainda carregam os fatos já lidos: entrada_hash passa a variar por versão
    // dos fatos e InserirOuReaproveitarAsync não reaproveita conferência obsoleta.
    private static CalculoConferencia Invalido(string operando, params FatoVigente[] lidos)
        => new(null, null, lidos.Select(U).ToArray(), OperandoInvalido: operando);

    private static CalculoConferencia Ausente(string tipo, string tributo, params FatoVigente[] lidos)
        => new(null, null, lidos.Select(U).ToArray(), FatoAusente: $"{tipo}[{tributo}]");
}
