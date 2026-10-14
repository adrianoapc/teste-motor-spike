using System.Text.Json;

namespace Motor.Testes.Unidade;

/// <summary>Um vetor do arquivo compartilhado. Textos são os JSON brutos; null = campo omitido do corpo.</summary>
public sealed record Vetor(string Nome, bool Aceito, string? PayloadBruto, string? ValorBruto, string? Canonico, string? Sha256)
{
    /// <summary>Corpo mínimo com os campos payload/valor_centavos do vetor (omitidos quando ausentes).</summary>
    public string CorpoParcial()
    {
        var partes = new List<string>();
        if (PayloadBruto is not null) partes.Add("\"payload\":" + PayloadBruto);
        if (ValorBruto is not null) partes.Add("\"valor_centavos\":" + ValorBruto);
        return "{" + string.Join(",", partes) + "}";
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
            var aceito = f.GetProperty("esperado").GetString() == "aceito";
            lista.Add(new Vetor(f.GetProperty("nome").GetString()!, aceito, payload, valor,
                aceito ? f.GetProperty("canonico").GetString() : null,
                aceito ? f.GetProperty("sha256").GetString() : null));
        }
        return lista;
    }
}
