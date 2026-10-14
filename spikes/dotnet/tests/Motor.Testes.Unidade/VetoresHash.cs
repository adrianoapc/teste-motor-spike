using System.Text.Json;

namespace Motor.Testes.Unidade;

/// <summary>Um vetor do arquivo compartilhado. Textos são os JSON brutos; null = campo omitido do corpo.</summary>
public sealed record Vetor(string Nome, bool Aceito, string? PayloadBruto, string? ValorBruto, string? Canonico, string? Sha256,
    IReadOnlyDictionary<string, string>? CamposTexto = null)
{
    /// <summary>Corpo mínimo com os campos payload/valor_centavos do vetor (omitidos quando ausentes).</summary>
    public string CorpoParcial()
    {
        var partes = new List<string>();
        if (PayloadBruto is not null) partes.Add("\"payload\":" + PayloadBruto);
        if (ValorBruto is not null) partes.Add("\"valor_centavos\":" + ValorBruto);
        return "{" + string.Join(",", partes) + "}";
    }

    /// <summary>Corpo completo de POST /fatos: envelope válido, campos_texto do vetor sobrescrevendo o campo de
    /// mesmo nome (serializados com escape correto, inclusive U+0000), mais payload/valor_centavos do vetor.</summary>
    public string CorpoFato()
    {
        var env = new Dictionary<string, string>
        {
            ["titular_id"] = "T1", ["competencia"] = "202609", ["tipo"] = "x", ["fonte"] = "t",
            ["observado_em"] = "2026-09-01T00:00:00Z",
        };
        if (CamposTexto is not null) foreach (var (k, val) in CamposTexto) env[k] = val;
        var cabeca = JsonSerializer.Serialize(env);
        var resto = CorpoParcial();
        return resto.Length > 2 ? cabeca[..^1] + "," + resto[1..] : cabeca;
    }

    public override string ToString() => Nome;
}

/// <summary>Carrega Fixtures/vetores-hash.json (cópia de contrato/vetores-hash.json) uma única vez.</summary>
public static class VetoresHash
{
    public const string Caminho = "Fixtures/vetores-hash.json";

    public static readonly IReadOnlyList<Vetor> Todos = Carregar(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, Caminho)));

    public static IEnumerable<object[]> Aceitos() => Todos.Where(v => v.Aceito).Select(v => new object[] { v });
    public static IEnumerable<object[]> Rejeitados() => Todos.Where(v => !v.Aceito).Select(v => new object[] { v });

    public static List<Vetor> Carregar(string json)
    {
        using var doc = JsonDocument.Parse(json);
        var lista = new List<Vetor>();
        foreach (var f in doc.RootElement.GetProperty("fatos").EnumerateArray())
        {
            var omitidos = f.TryGetProperty("campos_omitidos", out var o)
                ? o.EnumerateArray().Select(x => x.GetString()!).ToHashSet() : new HashSet<string>();
            string? payload = null, valor = null;
            if (f.TryGetProperty("valor_centavos_bruto", out var bruto)) valor = bruto.GetString();
            else if (!omitidos.Contains("valor_centavos") && f.TryGetProperty("valor_centavos", out var vc)) valor = vc.GetRawText();
            if (!omitidos.Contains("payload") && f.TryGetProperty("payload", out var p)) payload = p.GetString();
            Dictionary<string, string>? campos = null;
            if (f.TryGetProperty("campos_texto", out var ct))
                campos = ct.EnumerateObject().ToDictionary(x => x.Name, x => x.Value.GetString()!);
            var aceito = f.GetProperty("esperado").GetString() == "aceito";
            lista.Add(new Vetor(f.GetProperty("nome").GetString()!, aceito, payload, valor,
                aceito ? f.GetProperty("canonico").GetString() : null,
                aceito ? f.GetProperty("sha256").GetString() : null, campos));
        }
        return lista;
    }
}
