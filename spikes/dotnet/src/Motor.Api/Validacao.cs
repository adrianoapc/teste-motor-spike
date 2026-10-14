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
        if (!Int64Estrito(v, out var n)) return false; // fracionário, expoente, -0 ou fora de int64: inválido
        valor = n;
        return true;
    }

    /// <summary>Opções de parse dos corpos: MaxDepth acima do limite do payload (64 níveis, §6) para que a
    /// profundidade seja medida e rejeitada por <see cref="PayloadValido"/> (422), não pelo parser.</summary>
    public static readonly JsonDocumentOptions OpcoesParse = new() { MaxDepth = 200 };

    public const int ProfundidadeMaximaPayload = 64;

    /// <summary>Único critério de "inteiro int64" do contrato (valor_centavos e números do payload):
    /// sem fração, sem expoente, dentro de int64 e sem "-0".</summary>
    public static bool Int64Estrito(JsonElement numero, out long valor)
    {
        if (!numero.TryGetInt64(out valor)) return false;
        return valor != 0 || numero.GetRawText() != "-0"; // só aloca no zero
    }

    /// <summary>Lê payload conforme o contrato (objeto). Ausente => válido, usa {} (default do contrato).
    /// Presente com qualquer tipo que não seja objeto => INVÁLIDO (422) — nunca substituído por {}.
    /// Em §6: números só int64 estrito, sem chave duplicada, sem substituto isolado, profundidade até 64.
    /// <paramref name="motivo"/> descreve a falha (com o caminho).</summary>
    public static bool PayloadValido(JsonElement raiz, out JsonElement payload, out string motivo)
    {
        payload = default;
        motivo = "";
        if (!raiz.TryGetProperty("payload", out var p))
        {
            payload = JsonDocument.Parse("{}").RootElement.Clone(); // ausente: default do contrato
            return true;
        }
        if (p.ValueKind != JsonValueKind.Object) { motivo = "payload deve ser um objeto JSON"; return false; }
        if (!ConteudoPayloadValido(p, out var falha, out var caminho))
        {
            motivo = $"payload{caminho}: {falha}";
            return false;
        }
        payload = p.Clone();
        return true;
    }

    public static bool PayloadValido(JsonElement raiz, out JsonElement payload) => PayloadValido(raiz, out payload, out _);

    /// <summary>Verifica o conteúdo do payload (nível 1 = o próprio objeto). Caminho no formato .a[2].b.</summary>
    public static bool ConteudoPayloadValido(JsonElement payload, out string motivo, out string caminho)
        => Percorrer(payload, 1, out motivo, out caminho);

    public static bool ContemNul(string s) => s.Contains('\0');

    /// <summary>U+0000 não é armazenável em jsonb/text do PostgreSQL: nenhuma string ou chave do corpo
    /// (campos de texto do envelope inclusive) pode conter. Substituto isolado também é inválido.
    /// Rejeição => 422 corpo_invalido.</summary>
    public static bool CorpoSemNul(JsonElement e, out string motivo)
    {
        motivo = "";
        switch (e.ValueKind)
        {
            case JsonValueKind.String:
                try { if (!ContemNul(e.GetString()!)) return true; }
                catch (InvalidOperationException) { motivo = "string com substituto UTF-16 isolado"; return false; }
                motivo = "texto com U+0000 não é aceito";
                return false;
            case JsonValueKind.Object:
                foreach (var prop in e.EnumerateObject())
                {
                    try { if (ContemNul(prop.Name)) { motivo = "chave com U+0000 não é aceita"; return false; } }
                    catch (InvalidOperationException) { motivo = "chave com substituto UTF-16 isolado"; return false; }
                    if (!CorpoSemNul(prop.Value, out motivo)) return false;
                }
                return true;
            case JsonValueKind.Array:
                foreach (var item in e.EnumerateArray())
                    if (!CorpoSemNul(item, out motivo)) return false;
                return true;
            default:
                return true;
        }
    }

    public static bool NumerosInteirosInt64(JsonElement e) => Percorrer(e, 1, out _, out _);

    private static bool Percorrer(JsonElement e, int nivel, out string motivo, out string caminho)
    {
        motivo = ""; caminho = "";
        switch (e.ValueKind)
        {
            case JsonValueKind.Number:
                if (Int64Estrito(e, out _)) return true;
                motivo = "número deve ser inteiro int64 (sem fração, expoente ou -0)";
                return false;
            case JsonValueKind.String:
                try { if (!ContemNul(e.GetString()!)) return true; }
                catch (InvalidOperationException) { motivo = "string com substituto UTF-16 isolado"; return false; }
                motivo = "string com U+0000 (jsonb não armazena)";
                return false;
            case JsonValueKind.Object:
            {
                if (nivel > ProfundidadeMaximaPayload) { motivo = $"profundidade acima de {ProfundidadeMaximaPayload}"; return false; }
                var vistas = new HashSet<string>(StringComparer.Ordinal);
                foreach (var prop in e.EnumerateObject())
                {
                    string nome;
                    try { nome = prop.Name; }
                    catch (InvalidOperationException) { motivo = "chave com substituto UTF-16 isolado"; return false; }
                    if (ContemNul(nome)) { motivo = "chave com U+0000 (jsonb não armazena)"; return false; }
                    if (!vistas.Add(nome)) { motivo = $"chave duplicada \"{nome}\""; caminho = "." + nome; return false; }
                    if (!Percorrer(prop.Value, nivel + 1, out motivo, out var sub)) { caminho = "." + nome + sub; return false; }
                }
                return true;
            }
            case JsonValueKind.Array:
            {
                if (nivel > ProfundidadeMaximaPayload) { motivo = $"profundidade acima de {ProfundidadeMaximaPayload}"; return false; }
                var i = 0;
                foreach (var item in e.EnumerateArray())
                {
                    if (!Percorrer(item, nivel + 1, out motivo, out var sub)) { caminho = $"[{i}]{sub}"; return false; }
                    i++;
                }
                return true;
            }
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
