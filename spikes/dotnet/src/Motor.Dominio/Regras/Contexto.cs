using System.Text.Json;

namespace Motor.Dominio.Regras;

/// <summary>Contexto do caso: snapshot da empresa + campos derivados (§3).
/// Usado para avaliar as condições <c>quando</c>.</summary>
public sealed class Contexto
{
    private readonly IReadOnlyDictionary<string, JsonElement> _campos;

    public Contexto(IReadOnlyDictionary<string, JsonElement> campos)
    {
        _campos = campos;
    }

    /// <summary>Monta o contexto a partir do snapshot (objeto JSON) e da competência,
    /// adicionando <c>mes_do_trimestre</c> derivado.</summary>
    public static Contexto DeSnapshot(JsonElement snapshot, int mesDoTrimestre)
    {
        var campos = new Dictionary<string, JsonElement>(StringComparer.Ordinal);
        if (snapshot.ValueKind == JsonValueKind.Object)
        {
            foreach (var p in snapshot.EnumerateObject())
                campos[p.Name] = p.Value;
        }
        using var doc = JsonDocument.Parse(mesDoTrimestre.ToString(System.Globalization.CultureInfo.InvariantCulture));
        campos["mes_do_trimestre"] = doc.RootElement.Clone();
        return new Contexto(campos);
    }

    public bool TentarObter(string campo, out JsonElement valor) => _campos.TryGetValue(campo, out valor);
}
