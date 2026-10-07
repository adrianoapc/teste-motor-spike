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
    bool? ExistenciaOk = null);

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
        var bp = aliq.Payload.TryGetProperty("aliquota_bp", out var b) ? b.GetInt64() : 0;
        var esperado = Arredondamento.MeioParaPar((fat.ValorCentavos ?? 0) * bp, 10000);
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
        var dif = Json($"{{\"esperada\":\"{esperada}\",\"impressa\":\"{Escapar(impressa)}\"}}");
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
        long soma = 0;
        if (div.Payload.TryGetProperty("parcelas", out var parcelas) && parcelas.ValueKind == JsonValueKind.Array)
            foreach (var p in parcelas.EnumerateArray())
                if (p.TryGetProperty("valor_centavos", out var v) && v.ValueKind == JsonValueKind.Number)
                    soma += v.GetInt64();
        return new CalculoConferencia(apurado.ValorCentavos, soma, new[] { U(apurado), U(div) });
    }

    // CF-08 (E07 SN): esperado = arred(faturamento_mes.valor * prolabore_percentual_bp / 10000); obtido = prolabore.valor
    private static CalculoConferencia Cf08Com(IReadOnlyList<FatoVigente> fatos, int bp)
    {
        var fat = Buscar(fatos, "faturamento_mes");
        var prolabore = Buscar(fatos, "prolabore");
        if (fat is null) return Ausente("faturamento_mes", "");
        if (prolabore is null) return Ausente("prolabore", "");
        var esperado = Arredondamento.MeioParaPar((fat.ValorCentavos ?? 0) * bp, 10000);
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
        long InssEsp = Campo(folha, "inss_centavos");
        long FgtsEsp = Campo(folha, "fgts_centavos");
        var difInss = Math.Abs(InssEsp - (guiaInss.ValorCentavos ?? 0));
        var difFgts = Math.Abs(FgtsEsp - (guiaFgts.ValorCentavos ?? 0));
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

    private static long Campo(FatoVigente f, string nome)
        => f.Payload.TryGetProperty(nome, out var v) && v.ValueKind == JsonValueKind.Number ? v.GetInt64() : 0;

    private static CalculoConferencia Ausente(string tipo, string tributo)
        => new(null, null, Array.Empty<FatoUsado>(), FatoAusente: $"{tipo}[{tributo}]");

    private static string Regime(Caso caso)
        => caso.Snapshot.TryGetProperty("regime", out var r) && r.ValueKind == JsonValueKind.String
            ? r.GetString()! : "SN";

    private static string Escapar(string s) => s.Replace("\\", "\\\\").Replace("\"", "\\\"");

    private static JsonElement Json(string s)
    {
        using var doc = JsonDocument.Parse(s);
        return doc.RootElement.Clone();
    }
}
