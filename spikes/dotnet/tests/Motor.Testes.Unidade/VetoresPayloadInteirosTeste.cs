using System.Text.Json;
using Motor.Api;
using Motor.Dominio.Fatos;
using Xunit;

namespace Motor.Testes.Unidade;

/// <summary>Vetores compartilhados (semântica §6) de Fixtures/vetores-hash.json, cópia de contrato/vetores-hash.json.</summary>
public class VetoresPayloadInteirosTeste
{
    private static JsonElement Vetores()
    {
        var caminho = Path.Combine(AppContext.BaseDirectory, "Fixtures", "vetores-hash.json");
        return JsonDocument.Parse(File.ReadAllText(caminho)).RootElement.GetProperty("fatos");
    }

    private static IEnumerable<object[]> Casos(string esperado) =>
        Vetores().EnumerateArray().Where(f => f.GetProperty("esperado").GetString() == esperado)
            .Select(f => new object[] { f.GetProperty("nome").GetString()! });

    public static IEnumerable<object[]> Aceitos() => Casos("aceito");
    public static IEnumerable<object[]> Rejeitados() => Casos("rejeitado");

    private static JsonElement Fato(string nome) =>
        Vetores().EnumerateArray().Single(f => f.GetProperty("nome").GetString() == nome);

    [Theory, MemberData(nameof(Aceitos))]
    public void Aceito_valida_e_gera_canonico_e_sha256(string nome)
    {
        var f = Fato(nome);
        using var doc = JsonDocument.Parse(f.GetProperty("payload").GetString()!);
        Assert.True(Validacao.NumerosInteirosInt64(doc.RootElement));
        var vc = f.GetProperty("valor_centavos");
        long? valor = vc.ValueKind == JsonValueKind.Null ? null : vc.GetInt64();
        var canonico = "{\"payload\":" + HashCanonico.Canonico(doc.RootElement)
            + ",\"valor_centavos\":" + (valor?.ToString() ?? "null") + "}";
        Assert.Equal(f.GetProperty("canonico").GetString(), canonico);
        Assert.Equal(f.GetProperty("sha256").GetString(), HashCanonico.Sha256Hex(canonico));
        Assert.Equal(f.GetProperty("sha256").GetString(), HashCanonico.HashFato(doc.RootElement, valor));
    }

    [Theory, MemberData(nameof(Rejeitados))]
    public void Rejeitado_falha_na_validacao_do_payload_e_do_corpo(string nome)
    {
        var payload = Fato(nome).GetProperty("payload").GetString()!;
        using var doc = JsonDocument.Parse(payload);
        Assert.False(Validacao.NumerosInteirosInt64(doc.RootElement));
        using var corpo = JsonDocument.Parse("{\"payload\":" + payload + "}");
        Assert.False(Validacao.PayloadValido(corpo.RootElement, out _));
    }

    [Fact]
    public void Vetores_tem_12_aceitos_e_12_rejeitados()
    {
        Assert.Equal(12, Aceitos().Count());
        Assert.Equal(12, Rejeitados().Count());
    }
}
