using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace Motor.Dominio.Fatos;

/// <summary>JSON canônico e hash SHA-256 (§6, tech.md).
/// Canônico = chaves de objeto ordenadas por CODE POINT Unicode (= bytes UTF-8, não UTF-16) em todos os níveis,
/// sem espaços, UTF-8 sem escapar não-ASCII, números inteiros sem ponto.</summary>
public static class HashCanonico
{
    /// <summary>Serializa um <see cref="JsonElement"/> na forma canônica.</summary>
    public static string Canonico(JsonElement elemento)
    {
        var sb = new StringBuilder();
        Escrever(sb, elemento);
        return sb.ToString();
    }

    /// <summary>SHA-256 hexadecimal minúsculo do texto canônico já montado.</summary>
    public static string Sha256Hex(string textoCanonico)
    {
        var bytes = SHA256.HashData(Encoding.UTF8.GetBytes(textoCanonico));
        return Convert.ToHexStringLower(bytes);
    }

    /// <summary>Hash do fato: SHA-256 do canônico de {"payload":payload,"valor_centavos":valor}.</summary>
    public static string HashFato(JsonElement payload, long? valorCentavos)
    {
        var sb = new StringBuilder();
        sb.Append("{\"payload\":");
        Escrever(sb, payload);
        sb.Append(",\"valor_centavos\":");
        sb.Append(valorCentavos is null ? "null" : valorCentavos.Value.ToString(CultureInfo.InvariantCulture));
        sb.Append('}');
        return Sha256Hex(sb.ToString());
    }

    private static void Escrever(StringBuilder sb, JsonElement e)
    {
        switch (e.ValueKind)
        {
            case JsonValueKind.Object:
                sb.Append('{');
                var primeiro = true;
                foreach (var prop in e.EnumerateObject()
                             .OrderBy(p => p.Name, OrdemCodePoint.Instancia))
                {
                    if (!primeiro) sb.Append(',');
                    primeiro = false;
                    EscreverString(sb, prop.Name);
                    sb.Append(':');
                    Escrever(sb, prop.Value);
                }
                sb.Append('}');
                break;

            case JsonValueKind.Array:
                sb.Append('[');
                var primeiroItem = true;
                foreach (var item in e.EnumerateArray())
                {
                    if (!primeiroItem) sb.Append(',');
                    primeiroItem = false;
                    Escrever(sb, item);
                }
                sb.Append(']');
                break;

            case JsonValueKind.String:
                EscreverString(sb, e.GetString()!);
                break;

            case JsonValueKind.Number:
                sb.Append(e.GetRawText());
                break;

            case JsonValueKind.True:
                sb.Append("true");
                break;

            case JsonValueKind.False:
                sb.Append("false");
                break;

            case JsonValueKind.Null:
            case JsonValueKind.Undefined:
                sb.Append("null");
                break;

            default:
                throw new ArgumentOutOfRangeException(nameof(e), e.ValueKind, "tipo JSON não suportado");
        }
    }

    /// <summary>Escreve uma string JSON sem escapar caracteres não-ASCII (UTF-8 cru),
    /// escapando apenas o estritamente necessário (aspas, barra invertida, controles).</summary>
    private static void EscreverString(StringBuilder sb, string valor)
    {
        sb.Append('"');
        foreach (var c in valor)
        {
            switch (c)
            {
                case '"': sb.Append("\\\""); break;
                case '\\': sb.Append("\\\\"); break;
                case '\b': sb.Append("\\b"); break;
                case '\f': sb.Append("\\f"); break;
                case '\n': sb.Append("\\n"); break;
                case '\r': sb.Append("\\r"); break;
                case '\t': sb.Append("\\t"); break;
                default:
                    if (c < 0x20)
                        sb.Append("\\u").Append(((int)c).ToString("x4", CultureInfo.InvariantCulture));
                    else
                        sb.Append(c);
                    break;
            }
        }
        sb.Append('"');
    }
}

/// <summary>Ordem por code point Unicode (equivale à ordem de bytes UTF-8). A ordem ordinal UTF-16 do .NET
/// põe pares substitutos (U+10000+) antes de U+E000..U+FFFF; aqui eles vêm depois (§6).</summary>
public sealed class OrdemCodePoint : IComparer<string>
{
    public static readonly OrdemCodePoint Instancia = new();

    public int Compare(string? x, string? y)
    {
        if (ReferenceEquals(x, y)) return 0;
        if (x is null) return -1;
        if (y is null) return 1;
        var n = Math.Min(x.Length, y.Length);
        for (var i = 0; i < n; i++)
        {
            if (x[i] == y[i]) continue;
            return Chave(x[i]).CompareTo(Chave(y[i]));
        }
        return x.Length.CompareTo(y.Length);
    }

    // Desloca os substitutos (D800..DFFF) para depois de E000..FFFF, preservando a ordem entre eles.
    private static int Chave(char c) => c >= 0xE000 ? c - 0x800 : c >= 0xD800 ? c + 0x2000 : c;
}
