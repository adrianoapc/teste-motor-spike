using System.Text.Json;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fatos;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;
using Xunit;

namespace Motor.Testes.Unidade;

public class DominioVetoresTeste
{
    // ----- Arredondamento meio para o par (req 7.2) -----
    [Theory]
    [InlineData(60000000000, 10000, 6000000)]
    [InlineData(5, 2, 2)]
    [InlineData(7, 2, 4)]
    [InlineData(15, 10, 2)]
    [InlineData(25, 10, 2)]
    [InlineData(26, 10, 3)]
    public void Arredondamento_vetores(long num, long den, long esperado)
    {
        Assert.Equal(esperado, Arredondamento.MeioParaPar(num, den));
    }

    [Fact]
    public void Arredondamento_cf04_cf08_cf07_do_c1()
    {
        // CF-04: 10000000 * 600 / 10000 = 600000
        Assert.Equal(600000, Arredondamento.MeioParaPar(10000000L * 600, 10000));
        // CF-08: 10000000 * 2800 / 10000 = 2800000
        Assert.Equal(2800000, Arredondamento.MeioParaPar(10000000L * 2800, 10000));
    }

    // ----- Hash canônico de fato (req 4.2) -----
    [Theory]
    [InlineData("{}", 10000000L, "1e949949f87138fe966f349922ebff0167b25b1ad5fdc5f7432f7252355faa3d")]
    [InlineData("{\"aliquota_bp\":600}", null, "5bafcc08855a4f461c99d302521e44641944b690fc4ab93f56375599289c1449")]
    public void HashFato_vetores_simples(string payloadJson, object? valor, string esperado)
    {
        using var doc = JsonDocument.Parse(payloadJson);
        long? v = valor is null ? null : Convert.ToInt64(valor);
        Assert.Equal(esperado, HashCanonico.HashFato(doc.RootElement, v));
    }

    [Fact]
    public void HashFato_parcelas()
    {
        using var doc = JsonDocument.Parse(
            "{\"parcelas\":[{\"socio\":\"S1\",\"valor_centavos\":360000},{\"socio\":\"S2\",\"valor_centavos\":240000}]}");
        Assert.Equal("b405b94732752a986eb16f76b252ffb975b339e8fbeb56b6012cfac2d9f192ad",
            HashCanonico.HashFato(doc.RootElement, null));
    }

    [Fact]
    public void HashFato_ordena_chaves_e_nao_escapa_acento()
    {
        // chaves fora de ordem na entrada; o sistema deve ordená-las
        using var doc = JsonDocument.Parse(
            "{\"descrição\":\"ção\",\"b\":1,\"a\":{\"z\":1,\"y\":2}}");
        Assert.Equal("a8b6cf1558cc659b04237ac8295a0f3556b60efb6b06a9b7be922431c745b0b0",
            HashCanonico.HashFato(doc.RootElement, 5));
    }

    // ----- entrada_hash de conferência (req 7.4) -----
    [Fact]
    public void EntradaHash_vetor()
    {
        var fatos = new[]
        {
            new FatoUsado(Guid.Parse("00000000-0000-0000-0000-000000000001"), 1),
            new FatoUsado(Guid.Parse("00000000-0000-0000-0000-000000000002"), 1),
        };
        var hash = EntradaHash.Calcular("CF-01", fatos,
            Guid.Parse("00000000-0000-0000-0000-0000000000aa"));
        Assert.Equal("4e0ad4ed509cc1fa2e908b6ef9253d5927588b01a9001b8195c7185e75c878ca", hash);
    }

    // entrada_hash independe da ordem de entrada: a mesma lista em ordem inversa produz o mesmo
    // hash (ordenação canônica por fato_id). Garante o invariante que o fatos_usados persistido
    // precisa reproduzir (review Codex P2: fatos_usados tem de ser gravado nessa mesma ordem).
    [Fact]
    public void EntradaHash_independe_da_ordem_de_entrada()
    {
        var a = Guid.Parse("11111111-1111-1111-1111-111111111111");
        var b = Guid.Parse("22222222-2222-2222-2222-222222222222");
        var ordem1 = new[] { new FatoUsado(a, 1), new FatoUsado(b, 2) };
        var ordem2 = new[] { new FatoUsado(b, 2), new FatoUsado(a, 1) };
        var rv = Guid.Parse("00000000-0000-0000-0000-0000000000aa");
        Assert.Equal(EntradaHash.Calcular("CF-01", ordem1, rv),
                     EntradaHash.Calcular("CF-01", ordem2, rv));
    }

    // ----- CF-06: diferença textual escapa control chars (review Codex P2) -----
    [Fact]
    public void Cf06_diferenca_com_control_char_gera_json_valido()
    {
        var caso = new Caso(Guid.NewGuid(), "T001", Competencia.Analisar("202609"),
            JsonDocument.Parse("{\"regime\":\"SN\"}").RootElement.Clone(), "cart", Guid.NewGuid(), "aberto", 1);
        // guia[DAS] com competencia_impressa contendo \n (ex.: valor OCR mal formado)
        var guia = new FatoVigente(Guid.NewGuid(), "T001", Competencia.Analisar("202609"),
            "guia", "DAS", 1, 600000,
            JsonDocument.Parse("{\"competencia_impressa\":\"2026\\n09\"}").RootElement.Clone(),
            "hash");
        var item = default(ItemEntregavel);
        var calc = CalculadoraConferencias.Calcular("CF-06", caso, item!, new[] { guia }, 0);
        Assert.NotNull(calc.Textual);
        Assert.False(calc.Textual!.Value.Igual);
        // o ponto do fix: a diferença é um JsonElement válido mesmo com o control char
        var dif = calc.Textual.Value.Diferenca;
        Assert.Equal("2026\n09", dif.GetProperty("impressa").GetString());
        Assert.Equal("202609", dif.GetProperty("esperada").GetString());
    }

    // ----- Competência: somar meses, virada de ano (req 5.1) -----
    [Theory]
    [InlineData("202601", -1, "202512")]
    [InlineData("202601", -2, "202511")]
    [InlineData("202609", -1, "202608")]
    [InlineData("202612", 1, "202701")]
    [InlineData("202609", 0, "202609")]
    public void Competencia_somar(string origem, int meses, string esperado)
    {
        Assert.Equal(esperado, Competencia.Analisar(origem).Somar(meses).ToString());
    }

    // ----- mes_do_trimestre (req 5.2) -----
    [Theory]
    [InlineData("202601", 1)]
    [InlineData("202602", 2)]
    [InlineData("202603", 3)]
    [InlineData("202609", 3)]
    [InlineData("202607", 1)]
    [InlineData("202612", 3)]
    public void MesDoTrimestre(string comp, int esperado)
    {
        Assert.Equal(esperado, Competencia.Analisar(comp).MesDoTrimestre);
    }

    // ----- ordem / "estado >= X" (§4) -----
    [Fact]
    public void OrdemDosEstados()
    {
        Assert.True(EstadoEntregavel.Validado.PeloMenos(EstadoEntregavel.Pronto));
        Assert.True(EstadoEntregavel.Pago.PeloMenos(EstadoEntregavel.Pago));
        Assert.False(EstadoEntregavel.Pronto.PeloMenos(EstadoEntregavel.Validado));
        // ordem nula nunca é >= a nada
        Assert.False(EstadoEntregavel.Divergente.PeloMenos(EstadoEntregavel.AguardandoInsumo));
        Assert.False(EstadoEntregavel.Invalidado.PeloMenos(EstadoEntregavel.Pronto));
        Assert.Null(EstadoEntregavel.Divergente.Ordem());
        Assert.Null(EstadoEntregavel.Invalidado.Ordem());
    }

    // ----- Condição quando (§3) -----
    [Fact]
    public void CondicaoQuando()
    {
        var ctx = Contexto.DeSnapshot(
            JsonDocument.Parse("{\"regime\":\"LP\",\"tem_folha\":false}").RootElement, mesDoTrimestre: 3);

        JsonElement Q(string s) => JsonDocument.Parse(s).RootElement;

        Assert.True(Condicao.Casa(Q("[]"), ctx));                                  // vazia = sempre
        Assert.True(Condicao.Casa(null, ctx));                                     // nula = sempre
        Assert.True(Condicao.Casa(Q("[{\"regime\":\"LP\"}]"), ctx));               // casa
        Assert.False(Condicao.Casa(Q("[{\"regime\":\"SN\"}]"), ctx));              // não casa
        Assert.True(Condicao.Casa(Q("[{\"regime\":\"SN\"},{\"tem_folha\":false}]"), ctx)); // OU
        Assert.True(Condicao.Casa(Q("[{\"regime\":\"LP\",\"mes_do_trimestre\":3}]"), ctx)); // E com derivado
        Assert.False(Condicao.Casa(Q("[{\"regime\":\"LP\",\"mes_do_trimestre\":2}]"), ctx));
        Assert.False(Condicao.Casa(Q("[{\"tem_prolabore\":true}]"), ctx));          // campo ausente não casa
    }
}
