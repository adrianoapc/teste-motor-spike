using System.Text.Json;
using Motor.Dominio.Fatos;
using Xunit;

namespace Motor.Testes.Unidade;

/// <summary>Hash v2 (§6.3) contra os vetores compartilhados <c>contrato/vetores-hash-v2.json</c>:
/// cada caso tem de bater com o <c>sha256</c>, e cada par de <c>iguais</c> tem de dar o mesmo hash.
/// A etapa canônica continua coberta por <see cref="VetoresPayloadInteirosTeste"/>/<see cref="VetoresHash"/>.</summary>
public class VetoresHashV2Teste
{
    private const string Caminho = "Fixtures/vetores-hash-v2.json";

    private static readonly JsonDocument Doc =
        JsonDocument.Parse(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, Caminho)));

    /// <summary>Monta um contrato só com os campos que o hash usa (campos_hash, ordenar_arrays);
    /// esquema/qualificador/valor são irrelevantes para o hash.</summary>
    private static ContratoTipoFato Contrato(JsonElement contratoEl)
    {
        var camposHash = contratoEl.GetProperty("campos_hash").EnumerateArray().Select(x => x.GetString()!).ToList();
        var ordenar = contratoEl.GetProperty("ordenar_arrays");
        using var esquemaVazio = JsonDocument.Parse("{}");
        using var qualVazio = JsonDocument.Parse("{\"usa\":false,\"valores\":[]}");
        return ContratoTipoFato.De("x", 1, esquemaVazio.RootElement, qualVazio.RootElement,
            "opcional", camposHash, ordenar, Array.Empty<string>());
    }

    private static long? Valor(JsonElement e) =>
        e.ValueKind == JsonValueKind.Null ? null : e.GetInt64();

    private static JsonElement Payload(string bruto)
    {
        var doc = JsonDocument.Parse(bruto);
        return doc.RootElement.Clone();
    }

    public static IEnumerable<object[]> Casos()
    {
        foreach (var c in Doc.RootElement.GetProperty("casos").EnumerateArray())
            yield return new object[] { c.GetProperty("nome").GetString()!, c.Clone() };
    }

    public static IEnumerable<object[]> Iguais()
    {
        foreach (var c in Doc.RootElement.GetProperty("iguais").EnumerateArray())
            yield return new object[] { c.GetProperty("nome").GetString()!, c.Clone() };
    }

    [Theory, MemberData(nameof(Casos))]
    public void Cada_caso_bate_com_o_sha256(string nome, JsonElement caso)
    {
        _ = nome;
        var contrato = Contrato(caso.GetProperty("contrato"));
        var hash = HashV2.Hash(contrato, Payload(caso.GetProperty("payload").GetString()!),
            Valor(caso.GetProperty("valor_centavos")));
        Assert.Equal(caso.GetProperty("sha256").GetString(), hash);
    }

    [Theory, MemberData(nameof(Iguais))]
    public void Cada_par_de_iguais_tem_o_mesmo_hash(string nome, JsonElement par)
    {
        _ = nome;
        var contrato = Contrato(par.GetProperty("contrato"));
        var valor = Valor(par.GetProperty("valor_centavos"));
        var ha = HashV2.Hash(contrato, Payload(par.GetProperty("payload_a").GetString()!), valor);
        var hb = HashV2.Hash(contrato, Payload(par.GetProperty("payload_b").GetString()!), valor);
        Assert.Equal(ha, hb);
        Assert.Equal(par.GetProperty("sha256").GetString(), ha);
    }
}
