using System.Text.Json;
using Motor.Api;
using Motor.Dominio.Fatos;
using Xunit;

namespace Motor.Testes.Unidade;

/// <summary>Vetores compartilhados (semântica §6): todos os do arquivo Fixtures/vetores-hash.json.</summary>
public class VetoresPayloadInteirosTeste
{
    public static IEnumerable<object[]> Aceitos() => VetoresHash.Aceitos();
    public static IEnumerable<object[]> Rejeitados() => VetoresHash.Rejeitados();

    [Theory, MemberData(nameof(Aceitos))]
    public void Aceito_valida_e_gera_canonico_e_sha256(Vetor v)
    {
        using var corpo = JsonDocument.Parse(v.CorpoParcial(), Validacao.OpcoesParse);
        Assert.True(Validacao.PayloadValido(corpo.RootElement, out var payload, out var motivo), motivo);
        Assert.True(Validacao.ValorCentavosValido(corpo.RootElement, out var valor));
        using var envelope = JsonDocument.Parse(v.CorpoFato(), Validacao.OpcoesParse);
        Assert.True(Validacao.CorpoSemNul(envelope.RootElement, out var motivoNul), motivoNul);
        var canonico = "{\"payload\":" + HashCanonico.Canonico(payload)
            + ",\"valor_centavos\":" + (valor?.ToString() ?? "null") + "}";
        Assert.Equal(v.Canonico, canonico);
        Assert.Equal(v.Sha256, HashCanonico.Sha256Hex(canonico));
        Assert.Equal(v.Sha256, HashCanonico.HashFato(payload, valor));
    }

    [Theory, MemberData(nameof(Rejeitados))]
    public void Rejeitado_falha_na_validacao(Vetor v)
    {
        using var corpo = JsonDocument.Parse(v.CorpoParcial(), Validacao.OpcoesParse);
        var payloadOk = Validacao.PayloadValido(corpo.RootElement, out _, out _);
        var valorOk = Validacao.ValorCentavosValido(corpo.RootElement, out _);
        using var envelope = JsonDocument.Parse(v.CorpoFato(), Validacao.OpcoesParse);
        var semNul = Validacao.CorpoSemNul(envelope.RootElement, out _);
        Assert.False(payloadOk && valorOk && semNul);
    }

    [Fact]
    public void Contagem_vem_do_arquivo_e_cobre_os_dois_lados()
    {
        Assert.Equal(VetoresHash.Todos.Count, Aceitos().Count() + Rejeitados().Count());
        Assert.NotEmpty(Aceitos());
        Assert.NotEmpty(Rejeitados());
    }

    /// <summary>Drift: a cópia da fixture deve ser idêntica a contrato/vetores-hash.json quando este existir
    /// (ele só está presente a partir do merge do PR #7; sem o arquivo o teste não verifica nada).</summary>
    [Fact]
    public void Fixture_igual_ao_contrato_quando_existir()
    {
        for (var dir = new DirectoryInfo(AppContext.BaseDirectory); dir is not null; dir = dir.Parent)
        {
            var contrato = Path.Combine(dir.FullName, "contrato", "vetores-hash.json");
            if (!File.Exists(contrato)) continue;
            var copia = File.ReadAllText(Path.Combine(AppContext.BaseDirectory, VetoresHash.Caminho));
            Assert.Equal(File.ReadAllText(contrato).ReplaceLineEndings(), copia.ReplaceLineEndings());
            return;
        }
    }

    [Fact]
    public void Ordem_code_point_poe_nao_BMP_depois_de_U_FF5E()
    {
        Assert.True(OrdemCodePoint.Instancia.Compare("～", "\U0001F600") < 0);
        Assert.True(StringComparer.Ordinal.Compare("～", "\U0001F600") > 0); // por que o Ordinal não serve
        Assert.True(OrdemCodePoint.Instancia.Compare("10", "9") < 0);
    }
}
