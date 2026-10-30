using System.Globalization;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace Motor.Dominio.Fatos;

/// <summary>Hash v2 (§6.3) sob o contrato ativo do tipo:
/// <c>sha256( canonico( {"payload": ordenar(normalizar(projetar(payload))), "valor_centavos": valor} ) )</c>.
/// Projetar/normalizar/ordenar são funções puras; o canônico e o sha256 são os da v4
/// (<see cref="HashCanonico"/>). Vetores: <c>contrato/vetores-hash-v2.json</c>.</summary>
public static class HashV2
{
    /// <summary>Hash v2 do fato, sob o contrato dado.</summary>
    public static string Hash(ContratoTipoFato contrato, JsonElement payload, long? valorCentavos)
    {
        var projetado = Projetar(contrato.CamposHash, payload);
        var normalizado = Normalizar(projetado);
        var ordenado = Ordenar(contrato.OrdenarArrays, normalizado);
        return Sha256DoCanonico(ordenado, valorCentavos);
    }

    /// <summary>Projetar: mantém só as chaves de <c>campos_hash</c> de primeiro nível que existem no payload.</summary>
    public static JsonNode Projetar(IReadOnlyList<string> camposHash, JsonElement payload)
    {
        var o = new JsonObject();
        if (payload.ValueKind == JsonValueKind.Object)
            foreach (var campo in camposHash)
                if (payload.TryGetProperty(campo, out var v))
                    o[campo] = Converter(v);
        return o;
    }

    /// <summary>Normalizar (§6.3): em toda string, em qualquer profundidade, NFC + colapso de
    /// espaço/tab/\n/\r em um U+0020 com trim; membro de objeto com valor null é removido; chaves
    /// de objeto não mudam; elemento null de array fica.</summary>
    public static JsonNode? Normalizar(JsonNode? no)
    {
        switch (no)
        {
            case JsonObject obj:
            {
                var r = new JsonObject();
                foreach (var par in obj)
                {
                    if (par.Value is null) continue; // membro null removido (ausente = null)
                    var v = Normalizar(par.Value);
                    if (v is null) continue;
                    r[par.Key] = v;
                }
                return r;
            }
            case JsonArray arr:
            {
                var r = new JsonArray();
                foreach (var item in arr)
                    r.Add(item is null ? null : Normalizar(item)); // null de array fica
                return r;
            }
            case JsonValue val when val.TryGetValue<string>(out var s):
                return JsonValue.Create(NormalizarTexto(s));
            default:
                return no?.DeepClone();
        }
    }

    private static string NormalizarTexto(string s)
    {
        var nfc = s.Normalize(NormalizationForm.FormC);
        var sb = new StringBuilder(nfc.Length);
        var emEspaco = false;
        foreach (var c in nfc)
        {
            if (c is ' ' or '\t' or '\n' or '\r')
            {
                emEspaco = true;
                continue;
            }
            if (emEspaco)
            {
                if (sb.Length > 0) sb.Append(' '); // não emite espaço inicial
                emEspaco = false;
            }
            sb.Append(c);
        }
        // espaço final: emEspaco pendente é descartado (trim do fim)
        return sb.ToString();
    }

    /// <summary>Ordenar (§6.3): cada array listado em <c>ordenar_arrays</c> (já normalizado) é ordenado
    /// pelos campos declarados; compara o texto canônico de cada valor por code point, campo ausente vem
    /// antes de qualquer valor, empate pelo texto canônico do item inteiro. Arrays fora da lista mantêm a ordem.</summary>
    public static JsonNode? Ordenar(IReadOnlyDictionary<string, IReadOnlyList<string>> ordenarArrays, JsonNode? no)
    {
        if (no is not JsonObject obj) return no;
        var r = new JsonObject();
        foreach (var par in obj)
        {
            if (ordenarArrays.TryGetValue(par.Key, out var campos) && par.Value is JsonArray arr)
                r[par.Key] = OrdenarArray(arr, campos);
            else
                r[par.Key] = par.Value?.DeepClone();
        }
        return r;
    }

    private static JsonArray OrdenarArray(JsonArray arr, IReadOnlyList<string> campos)
    {
        var itens = arr.Select(x => x?.DeepClone()).ToList();
        itens.Sort((a, b) =>
        {
            foreach (var campo in campos)
            {
                var ca = ChaveCampo(a, campo);
                var cb = ChaveCampo(b, campo);
                // campo ausente (null) vem antes de qualquer valor
                if (ca is null && cb is null) continue;
                if (ca is null) return -1;
                if (cb is null) return 1;
                var cmp = string.CompareOrdinal(ca, cb);
                if (cmp != 0) return CmpCodePoint(ca, cb);
            }
            // empate: texto canônico do item inteiro
            return CmpCodePoint(CanonicoDeNode(a), CanonicoDeNode(b));
        });
        var saida = new JsonArray();
        foreach (var it in itens) saida.Add(it);
        return saida;
    }

    /// <summary>Texto canônico do valor do campo do item, ou null se o item não é objeto ou o campo falta.</summary>
    private static string? ChaveCampo(JsonNode? item, string campo)
    {
        if (item is not JsonObject o || !o.TryGetPropertyValue(campo, out var v) || v is null) return null;
        return CanonicoDeNode(v);
    }

    private static int CmpCodePoint(string a, string b)
    {
        var n = Math.Min(a.Length, b.Length);
        for (var i = 0; i < n; i++)
        {
            if (a[i] == b[i]) continue;
            return Chave(a[i]).CompareTo(Chave(b[i]));
        }
        return a.Length.CompareTo(b.Length);
        static int Chave(char c) => c >= 0xE000 ? c - 0x800 : c >= 0xD800 ? c + 0x2000 : c;
    }

    /// <summary>Canônico (§6.3, etapa final = v4) do envelope {"payload":...,"valor_centavos":...} + sha256.</summary>
    private static string Sha256DoCanonico(JsonNode? payloadOrdenado, long? valorCentavos)
    {
        var texto = payloadOrdenado is null ? "{}" : CanonicoDeNode(payloadOrdenado);
        var sb = new StringBuilder();
        sb.Append("{\"payload\":").Append(texto).Append(",\"valor_centavos\":");
        sb.Append(valorCentavos is null ? "null" : valorCentavos.Value.ToString(CultureInfo.InvariantCulture));
        sb.Append('}');
        return HashCanonico.Sha256Hex(sb.ToString());
    }

    /// <summary>Texto canônico (v4) de um <see cref="JsonNode"/>: serializa para JsonElement e reusa
    /// <see cref="HashCanonico.Canonico"/>, única fonte da forma canônica.</summary>
    private static string CanonicoDeNode(JsonNode? no)
    {
        if (no is null) return "null";
        using var doc = JsonDocument.Parse(no.ToJsonString());
        return HashCanonico.Canonico(doc.RootElement);
    }

    private static JsonNode? Converter(JsonElement e) => JsonNode.Parse(e.GetRawText());
}
