using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

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

/// <summary>Fórmulas das conferências do spike (§9.1). Puras: recebem os fatos vigentes já lidos.</summary>
public static class CalculadoraConferencias
{
    public static CalculoConferencia Calcular(string cf, Caso caso, ItemEntregavel item,
        IReadOnlyList<FatoVigente> fatos, int prolaboreBp)
    {
        return cf switch
        {
            "CF-01" => Cf01(fatos),
            "CF-04" => Cf04(fatos),
            "CF-05" => Cf05(caso, fatos),
            "CF-06" => Cf06(caso, fatos),
            "CF-07" => Cf07(caso, fatos),
            "CF-08" => Cf08Com(fatos, prolaboreBp),
            "CF-09" => Cf09(fatos),
            "CF-12" => Cf12(caso, item, fatos),
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
        if (notas is null) return Ausente("notas_oneflow", "");
        return new CalculoConferencia(fat.ValorCentavos, notas.ValorCentavos, new[] { U(fat), U(notas) });
    }

    // CF-04 (E03 SN): esperado = arred(faturamento_mes.valor * aliquota_bp / 10000); obtido = apurado[DAS].valor
    private static CalculoConferencia Cf04(IReadOnlyList<FatoVigente> fatos)
    {
        var fat = Buscar(fatos, "faturamento_mes");
        var aliq = Buscar(fatos, "aliquota_mes");
        var apurado = Buscar(fatos, "apurado", "DAS");
        if (fat is null) return Ausente("faturamento_mes", "");
        if (aliq is null) return Ausente("aliquota_mes", "");
        if (apurado is null) return Ausente("apurado", "DAS");
        long bp = 0;
        if (aliq.Payload.TryGetProperty("aliquota_bp", out var b) && !LerInt64(b, out bp))
            return Invalido("aliquota_mes.aliquota_bp");
        if (!ProdutoArredondado(fat.ValorCentavos ?? 0, bp, out var esperado))
            return Invalido("faturamento_mes*aliquota_bp fora de int64");
        return new CalculoConferencia(esperado, apurado.ValorCentavos, new[] { U(fat), U(aliq), U(apurado) });
    }

    // CF-05 (E11): esperado = apurado[T].valor, T = tributo da guia; obtido = guia[T].valor
    private static CalculoConferencia Cf05(Caso caso, IReadOnlyList<FatoVigente> fatos)
    {
        var regime = Regime(caso);
        var tributo = regime == "SN" ? "DAS" : "IRPJ";
        var apurado = Buscar(fatos, "apurado", tributo);
        var guia = Buscar(fatos, "guia", tributo);
        if (apurado is null) return Ausente("apurado", tributo);
        if (guia is null) return Ausente("guia", tributo);
        return new CalculoConferencia(apurado.ValorCentavos, guia.ValorCentavos, new[] { U(apurado), U(guia) });
    }

    // CF-06 (E11): ok se guia.payload.competencia_impressa = competência do caso
    private static CalculoConferencia Cf06(Caso caso, IReadOnlyList<FatoVigente> fatos)
    {
        var regime = Regime(caso);
        var tributo = regime == "SN" ? "DAS" : "IRPJ";
        var guia = Buscar(fatos, "guia", tributo);
        if (guia is null) return Ausente("guia", tributo);
        var impressa = guia.Payload.TryGetProperty("competencia_impressa", out var ci) && ci.ValueKind == JsonValueKind.String
            ? ci.GetString()! : "";
        var esperada = caso.Competencia.ToString();
        var igual = string.Equals(impressa, esperada, StringComparison.Ordinal);
        // Serializa via JsonSerializer: 'impressa' pode conter control chars (ex.: OCR com \n),
        // que o escape manual (só aspas/barra) deixaria passar e quebraria JsonDocument.Parse.
        var dif = JsonObjeto(new { esperada, impressa });
        return new CalculoConferencia(null, null, new[] { U(guia) }, Textual: new ComparacaoTextual(igual, dif));
    }

    // CF-07 (E04): esperado = apurado[DAS ou IRPJ].valor; obtido = soma(divisao_socios.parcelas[].valor_centavos)
    private static CalculoConferencia Cf07(Caso caso, IReadOnlyList<FatoVigente> fatos)
    {
        var regime = Regime(caso);
        var tributo = regime == "SN" ? "DAS" : "IRPJ";
        var apurado = Buscar(fatos, "apurado", tributo);
        var div = Buscar(fatos, "divisao_socios");
        if (apurado is null) return Ausente("apurado", tributo);
        if (div is null) return Ausente("divisao_socios", "");
        Int128 soma = 0;
        if (div.Payload.TryGetProperty("parcelas", out var parcelas) && parcelas.ValueKind == JsonValueKind.Array)
            foreach (var p in parcelas.EnumerateArray())
                if (p.ValueKind == JsonValueKind.Object && p.TryGetProperty("valor_centavos", out var v)
                    && v.ValueKind != JsonValueKind.Null)
                {
                    if (!LerInt64(v, out var n)) return Invalido("divisao_socios.parcelas[].valor_centavos");
                    soma += n;
                }
        if (soma > long.MaxValue || soma < long.MinValue) return Invalido("soma das parcelas fora de int64");
        return new CalculoConferencia(apurado.ValorCentavos, (long)soma, new[] { U(apurado), U(div) });
    }

    // CF-08 (E07 SN): esperado = arred(faturamento_mes.valor * prolabore_percentual_bp / 10000); obtido = prolabore.valor
    private static CalculoConferencia Cf08Com(IReadOnlyList<FatoVigente> fatos, int bp)
    {
        var fat = Buscar(fatos, "faturamento_mes");
        var prolabore = Buscar(fatos, "prolabore");
        if (fat is null) return Ausente("faturamento_mes", "");
        if (prolabore is null) return Ausente("prolabore", "");
        if (!ProdutoArredondado(fat.ValorCentavos ?? 0, bp, out var esperado))
            return Invalido("faturamento_mes*prolabore_bp fora de int64");
        return new CalculoConferencia(esperado, prolabore.ValorCentavos, new[] { U(fat), U(prolabore) });
    }

    // CF-09 (E08): INSS e FGTS; esperado=folha.payload.<t>_centavos; obtido=guia[t].valor; diferença = maior das duas
    private static CalculoConferencia Cf09(IReadOnlyList<FatoVigente> fatos)
    {
        var folha = Buscar(fatos, "folha");
        var guiaInss = Buscar(fatos, "guia", "INSS");
        var guiaFgts = Buscar(fatos, "guia", "FGTS");
        if (folha is null) return Ausente("folha", "");
        if (guiaInss is null) return Ausente("guia", "INSS");
        if (guiaFgts is null) return Ausente("guia", "FGTS");
        if (!Campo(folha, "inss_centavos", out var InssEsp)) return Invalido("folha.inss_centavos");
        if (!Campo(folha, "fgts_centavos", out var FgtsEsp)) return Invalido("folha.fgts_centavos");
        var difInss = Int128.Abs((Int128)InssEsp - (guiaInss.ValorCentavos ?? 0));
        var difFgts = Int128.Abs((Int128)FgtsEsp - (guiaFgts.ValorCentavos ?? 0));
        // Representamos esperado/obtido pelo tributo de MAIOR diferença (a conferência reporta a maior).
        var usados = new[] { U(folha), U(guiaInss), U(guiaFgts) };
        if (difFgts > difInss)
            return new CalculoConferencia(FgtsEsp, guiaFgts.ValorCentavos, usados);
        return new CalculoConferencia(InssEsp, guiaInss.ValorCentavos, usados);
    }

    // CF-12 (E10): ok se existe recibo_obrigacao com o tributo exigido pela entrada
    private static CalculoConferencia Cf12(Caso caso, ItemEntregavel item, IReadOnlyList<FatoVigente> fatos)
    {
        var regime = Regime(caso);
        var tributo = regime == "SN" ? "PGDAS-D" : "DCTFWEB";
        var recibo = Buscar(fatos, "recibo_obrigacao", tributo);
        if (recibo is null)
            return new CalculoConferencia(null, null, Array.Empty<FatoUsado>(), ExistenciaOk: false);
        return new CalculoConferencia(null, null, new[] { U(recibo) }, ExistenciaOk: true);
    }

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

    private static CalculoConferencia Invalido(string operando)
        => new(null, null, Array.Empty<FatoUsado>(), OperandoInvalido: operando);

    private static CalculoConferencia Ausente(string tipo, string tributo)
        => new(null, null, Array.Empty<FatoUsado>(), FatoAusente: $"{tipo}[{tributo}]");

    private static string Regime(Caso caso)
        => caso.Snapshot.TryGetProperty("regime", out var r) && r.ValueKind == JsonValueKind.String
            ? r.GetString()! : "SN";

    private static JsonElement Json(string s)
    {
        using var doc = JsonDocument.Parse(s);
        return doc.RootElement.Clone();
    }

    private static JsonElement JsonObjeto(object valor)
    {
        var texto = JsonSerializer.Serialize(valor);
        using var doc = JsonDocument.Parse(texto);
        return doc.RootElement.Clone();
    }
}
