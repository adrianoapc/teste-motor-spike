using System.Text.Json;
using Motor.Api;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;
using Xunit;

namespace Motor.Testes.Unidade;

public class RevisaoPosMergeTeste
{
    private static JsonElement J(string s) => JsonDocument.Parse(s).RootElement.Clone();
    private static readonly Competencia C = Competencia.Analisar("202609");
    private static Caso CasoSn() => new(Guid.NewGuid(), "T001", C, J("{\"regime\":\"SN\"}"), "cart", Guid.NewGuid(), "aberto", 1);
    private static FatoVigente F(string tipo, string trib, long? valor, string payload = "{}")
        => new(Guid.NewGuid(), "T001", C, tipo, trib, 1, valor, J(payload), "h");
    private static CalculoConferencia Calc(string cf, params FatoVigente[] fatos)
        => CalculadoraConferencias.Calcular(cf, CasoSn(), default!, fatos, 6000);

    [Theory]
    [InlineData("202609", true)]
    [InlineData("202609\n", false)]
    [InlineData("\n202609", false)]
    [InlineData("202613", false)]
    public void Competencia_texto_ancora_no_fim_absoluto(string s, bool ok)
        => Assert.Equal(ok, Validacao.CompetenciaTextoValida(s));

    [Fact]
    public void Competencia_valida_rejeita_newline_final()
        => Assert.False(Validacao.CompetenciaValida(J("{\"competencia\":\"202609\\n\"}"), out _));

    [Theory]
    [InlineData("{}", true)]
    [InlineData("{\"tributo\":\"DAS\"}", true)]
    [InlineData("{\"tributo\":null}", false)]
    [InlineData("{\"tributo\":123}", false)]
    public void TextoOpcional_rejeita_tipo_errado(string json, bool ok)
        => Assert.Equal(ok, Validacao.TextoOpcionalValido(J(json), "tributo", out _));

    [Theory]
    [InlineData("\"x\"")]
    [InlineData("1.5")]
    [InlineData("99999999999999999999")]
    public void Cf04_aliquota_malformada_vira_operando_invalido(string bp)
    {
        var c = Calc("CF-04", F("faturamento_mes", "", 100), F("aliquota_mes", "", null, $"{{\"aliquota_bp\":{bp}}}"),
            F("apurado", "DAS", 1));
        Assert.NotNull(c.OperandoInvalido);
    }

    [Fact]
    public void Cf04_produto_acima_de_int64_nao_da_wrap()
    {
        var c = Calc("CF-04", F("faturamento_mes", "", long.MaxValue), F("aliquota_mes", "", null, "{\"aliquota_bp\":600}"),
            F("apurado", "DAS", 1));
        Assert.Null(c.OperandoInvalido);
        var esperado = (long)Arredondamento.MeioParaPar((Int128)long.MaxValue * 600, (Int128)10000);
        Assert.Equal(esperado, c.Esperado);
        Assert.True(esperado > 5_000_000_000_000_000L);
    }

    [Fact]
    public void Cf08_produto_acima_de_int64_nao_da_wrap()
    {
        var c = CalculadoraConferencias.Calcular("CF-08", CasoSn(), default!,
            new[] { F("faturamento_mes", "", long.MaxValue), F("prolabore", "", 1) }, 6000);
        Assert.Equal((long)Arredondamento.MeioParaPar((Int128)long.MaxValue * 6000, (Int128)10000), c.Esperado);
    }

    [Fact]
    public void Cf07_parcela_malformada_vira_operando_invalido()
    {
        var c = Calc("CF-07", F("apurado", "DAS", 10),
            F("divisao_socios", "", null, "{\"parcelas\":[{\"valor_centavos\":\"5\"}]}"));
        Assert.NotNull(c.OperandoInvalido);
    }

    [Fact]
    public void Cf09_folha_malformada_vira_operando_invalido()
    {
        var c = Calc("CF-09", F("folha", "", null, "{\"inss_centavos\":1.5,\"fgts_centavos\":2}"),
            F("guia", "INSS", 1), F("guia", "FGTS", 2));
        Assert.NotNull(c.OperandoInvalido);
    }

    [Fact]
    public void Cf09_diferenca_extrema_nao_estoura()
    {
        var c = Calc("CF-09", F("folha", "", null, "{\"inss_centavos\":0,\"fgts_centavos\":0}"),
            F("guia", "INSS", long.MinValue), F("guia", "FGTS", 0));
        Assert.Null(c.OperandoInvalido);
        Assert.Equal(long.MinValue, c.Obtido);
    }

    [Fact]
    public void Arredondamento_int128_mantem_vetores()
    {
        Assert.Equal((Int128)2, Arredondamento.MeioParaPar((Int128)25, (Int128)10));
        Assert.Equal((Int128)4, Arredondamento.MeioParaPar((Int128)7, (Int128)2));
        Assert.Equal((Int128)(-2), Arredondamento.MeioParaPar((Int128)(-25), (Int128)10));
    }

    [Fact]
    public void Classificacao_diferenca_int128_extrema_e_divergente()
    {
        var dif = Int128.Abs((Int128)0 - long.MinValue);
        var (res, sev) = Classificacao.Numerica(dif, new Tolerancia(0, 100), "B");
        Assert.Equal(ResultadoConferencia.Divergente, res);
        Assert.Equal("B", sev);
    }

    [Theory]
    [InlineData("1")]
    [InlineData("\"x\"")]
    [InlineData("[]")]
    [InlineData("null")]
    public void Cf07_parcela_nao_objeto_vira_operando_invalido(string entrada)
    {
        var c = Calc("CF-07", F("apurado", "DAS", 0),
            F("divisao_socios", "", null, $"{{\"parcelas\":[{entrada}]}}"));
        Assert.NotNull(c.OperandoInvalido);
        Assert.Equal(2, c.FatosUsados.Count);
    }

    [Fact]
    public void Invalido_retem_fatos_e_hash_varia_com_a_versao()
    {
        var aliq = F("aliquota_mes", "", null, "{\"aliquota_bp\":\"x\"}");
        var fat = F("faturamento_mes", "", 100);
        var ap1 = F("apurado", "DAS", 1);
        var ap2 = ap1 with { Versao = 2 };
        var c1 = Calc("CF-04", fat, aliq, ap1);
        var c2 = Calc("CF-04", fat, aliq, ap2);
        Assert.NotNull(c1.OperandoInvalido);
        Assert.Equal(3, c1.FatosUsados.Count);
        var v = Guid.NewGuid();
        Assert.NotEqual(EntradaHash.Calcular("CF-04", c1.FatosUsados, v), EntradaHash.Calcular("CF-04", c2.FatosUsados, v));
    }

    [Fact]
    public void Ausente_retem_fatos_ja_lidos()
    {
        var fat = F("faturamento_mes", "", 100);
        var c = Calc("CF-04", fat);
        Assert.Equal("aliquota_mes[]", c.FatoAusente);
        Assert.Single(c.FatosUsados);
        var vazio = Calc("CF-04");
        Assert.Empty(vazio.FatosUsados);
        var v = Guid.NewGuid();
        Assert.NotEqual(EntradaHash.Calcular("CF-04", c.FatosUsados, v), EntradaHash.Calcular("CF-04", vazio.FatosUsados, v));
    }
}
