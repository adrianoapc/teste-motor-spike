using System.Text.Json;
using System.Text.RegularExpressions;

namespace Motor.Api;

/// <summary>Validação dos corpos conforme o contrato (gera 422). Mínima: cobre o que os cenários exercem.</summary>
public static class Validacao
{
    private static readonly Regex CompRegex = new("^[0-9]{4}(0[1-9]|1[0-2])$", RegexOptions.Compiled);

    public static bool CompetenciaValida(JsonElement raiz, out string texto)
    {
        texto = "";
        if (!raiz.TryGetProperty("competencia", out var c) || c.ValueKind != JsonValueKind.String)
            return false;
        var s = c.GetString()!;
        if (!CompRegex.IsMatch(s)) return false;
        texto = s;
        return true;
    }

    public static bool CampoTextoNaoVazio(JsonElement raiz, string campo, out string valor)
    {
        valor = "";
        if (!raiz.TryGetProperty(campo, out var v) || v.ValueKind != JsonValueKind.String)
            return false;
        var s = v.GetString();
        if (string.IsNullOrEmpty(s)) return false;
        valor = s;
        return true;
    }

    /// <summary>Lê valor_centavos conforme o contrato (int64 ou null). Ausente ou JSON null => válido, valor null.
    /// Presente com tipo não-numérico (ex.: "600000"), fracionário, ou fora da faixa int64 => INVÁLIDO (422) —
    /// nunca silenciosamente convertido para null, o que gravaria um fato materialmente diferente.</summary>
    public static bool ValorCentavosValido(JsonElement raiz, out long? valor)
    {
        valor = null;
        if (!raiz.TryGetProperty("valor_centavos", out var v) || v.ValueKind == JsonValueKind.Null)
            return true; // ausente ou null explícito: ok
        if (v.ValueKind != JsonValueKind.Number) return false; // string/bool/objeto/array: inválido
        if (!v.TryGetInt64(out var n)) return false; // fracionário ou fora da faixa int64: inválido
        valor = n;
        return true;
    }

    public static bool EmpresaValida(JsonElement e, out string motivo)
    {
        motivo = "";
        if (e.ValueKind != JsonValueKind.Object) { motivo = "não é objeto"; return false; }
        if (!CampoTextoNaoVazio(e, "titular_id", out _)) { motivo = "titular_id"; return false; }
        if (!e.TryGetProperty("regime", out var reg) || reg.ValueKind != JsonValueKind.String
            || reg.GetString() is not ("SN" or "LP")) { motivo = "regime"; return false; }
        foreach (var b in new[] { "tem_folha", "tem_prolabore", "tem_taxa_municipal", "filial" })
        {
            if (!e.TryGetProperty(b, out var v) || (v.ValueKind != JsonValueKind.True && v.ValueKind != JsonValueKind.False))
            { motivo = b; return false; }
        }
        if (!CampoTextoNaoVazio(e, "carteira", out _)) { motivo = "carteira"; return false; }
        if (!CampoTextoNaoVazio(e, "municipio", out _)) { motivo = "municipio"; return false; }
        return true;
    }
}
