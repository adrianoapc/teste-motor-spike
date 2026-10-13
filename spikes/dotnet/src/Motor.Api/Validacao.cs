using System.Text.Json;
using System.Text.RegularExpressions;

namespace Motor.Api;

/// <summary>Validação dos corpos conforme o contrato (gera 422). Mínima: cobre o que os cenários exercem.</summary>
public static class Validacao
{
    private static readonly Regex CompRegex = new(@"\A[0-9]{4}(0[1-9]|1[0-2])\z", RegexOptions.Compiled);

    /// <summary>Competência AAAAMM; \z (não $) para rejeitar "202609\n".</summary>
    public static bool CompetenciaTextoValida(string s) => CompRegex.IsMatch(s);

    public static bool CompetenciaValida(JsonElement raiz, out string texto)
    {
        texto = "";
        if (!raiz.TryGetProperty("competencia", out var c) || c.ValueKind != JsonValueKind.String)
            return false;
        var s = c.GetString()!;
        if (!CompetenciaTextoValida(s)) return false;
        texto = s;
        return true;
    }

    /// <summary>Campo texto opcional: ausente => válido (null); string => válido; outro tipo => INVÁLIDO (422).
    /// JSON null só é válido quando o contrato declara o campo nullable (ex.: origem_ref); tributo não é nullable.</summary>
    public static bool TextoOpcionalValido(JsonElement raiz, string campo, out string? valor, bool aceitaNull = false)
    {
        valor = null;
        if (!raiz.TryGetProperty(campo, out var v)) return true;
        if (v.ValueKind == JsonValueKind.Null) return aceitaNull;
        if (v.ValueKind == JsonValueKind.String) { valor = v.GetString(); return true; }
        return false;
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

    /// <summary>Lê payload conforme o contrato (objeto). Ausente => válido, usa {} (default do contrato).
    /// Presente com qualquer tipo que não seja objeto (null explícito, array, número, string, bool)
    /// => INVÁLIDO (422) — nunca substituído silenciosamente por {}, o que gravaria fato diferente.</summary>
    public static bool PayloadValido(JsonElement raiz, out JsonElement payload)
    {
        payload = default;
        if (!raiz.TryGetProperty("payload", out var p))
        {
            payload = JsonDocument.Parse("{}").RootElement.Clone(); // ausente: default do contrato
            return true;
        }
        if (p.ValueKind != JsonValueKind.Object) return false; // presente com tipo errado: inválido
        if (!NumerosInteirosInt64(p)) return false; // semântica §6: só inteiros int64, em qualquer profundidade
        payload = p.Clone();
        return true;
    }

    /// <summary>Semântica §6: todo número do payload, em qualquer profundidade, deve ser inteiro int64 —
    /// só dígitos com '-' opcional, sem '.', sem expoente, sem "-0", dentro da faixa int64. Confere o texto
    /// bruto (1.0 e 1e2 são inválidos mesmo valendo inteiros).</summary>
    public static bool NumerosInteirosInt64(JsonElement e)
    {
        switch (e.ValueKind)
        {
            case JsonValueKind.Number:
                var t = e.GetRawText();
                if (t == "-0") return false;
                foreach (var ch in t.AsSpan(t[0] == '-' ? 1 : 0))
                    if (ch < '0' || ch > '9') return false;
                return long.TryParse(t, System.Globalization.NumberStyles.AllowLeadingSign,
                    System.Globalization.CultureInfo.InvariantCulture, out _);
            case JsonValueKind.Object:
                foreach (var prop in e.EnumerateObject())
                    if (!NumerosInteirosInt64(prop.Value)) return false;
                return true;
            case JsonValueKind.Array:
                foreach (var item in e.EnumerateArray())
                    if (!NumerosInteirosInt64(item)) return false;
                return true;
            default:
                return true;
        }
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
