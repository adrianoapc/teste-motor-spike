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
        var ctx = new ContextoCalculo(Competencia.Analisar("202609"), default!,
            Contexto.DeSnapshot(JsonDocument.Parse("{\"regime\":\"SN\"}").RootElement.Clone(), 3), "DAS", null);
        // guia[DAS] com competencia_impressa contendo \n (ex.: valor OCR mal formado)
        var guia = new FatoVigente(Guid.NewGuid(), "T001", Competencia.Analisar("202609"),
            "guia", "DAS", 1, 600000,
            JsonDocument.Parse("{\"competencia_impressa\":\"2026\\n09\"}").RootElement.Clone(),
            "hash");
        var calc = CalculadoraConferencias.Calcular("CF-06", ctx, new[] { guia });
        Assert.NotNull(calc.Textual);
        Assert.False(calc.Textual!.Value.Igual);
        // o ponto do fix: a diferença é um JsonElement válido mesmo com o control char
        var dif = calc.Textual.Value.Diferenca;
        Assert.Equal("2026\n09", dif.GetProperty("impressa").GetString());
        Assert.Equal("202609", dif.GetProperty("esperada").GetString());
    }

    // ----- CF-07 LP tributo a tributo (§9.1 v4, task 21) -----
    private static FatoVigente Fato(string tipo, string tributo, long? valor, string payloadJson = "{}")
        => new(Guid.NewGuid(), "T014", Competencia.Analisar("202608"), tipo, tributo, 1, valor,
            JsonDocument.Parse(payloadJson).RootElement.Clone(), "hash");

    private static ContextoCalculo CtxLp(ItemEntregavel? item = null)
        => new(Competencia.Analisar("202608"), item!,
            Contexto.DeSnapshot(JsonDocument.Parse("{\"regime\":\"LP\"}").RootElement.Clone(), 2), "IRPJ", null);

    [Fact]
    public void Cf07_lp_par_do_tributo_de_maior_diferenca()
    {
        // IRPJ bate (160000=96000+64000); CSLL 5 abaixo (144000 vs 86400+57595=143995);
        // PIS 39 abaixo (13000 vs 12961); COFINS bate (60000). Maior diferença = PIS (39).
        var fatos = new[]
        {
            Fato("apurado", "IRPJ", 160000), Fato("apurado", "CSLL", 144000),
            Fato("apurado", "PIS", 13000), Fato("apurado", "COFINS", 60000),
            Fato("divisao_socios", "", null, "{\"parcelas\":[" +
                "{\"socio\":\"S1\",\"tributo\":\"IRPJ\",\"valor_centavos\":96000}," +
                "{\"socio\":\"S2\",\"tributo\":\"IRPJ\",\"valor_centavos\":64000}," +
                "{\"socio\":\"S1\",\"tributo\":\"CSLL\",\"valor_centavos\":86400}," +
                "{\"socio\":\"S2\",\"tributo\":\"CSLL\",\"valor_centavos\":57595}," +
                "{\"socio\":\"S1\",\"tributo\":\"PIS\",\"valor_centavos\":12961}," +
                "{\"socio\":\"S1\",\"tributo\":\"COFINS\",\"valor_centavos\":60000}]}"),
        };
        var calc = CalculadoraConferencias.Calcular("CF-07", CtxLp(), fatos);
        Assert.Null(calc.FatoAusente);
        Assert.Null(calc.OperandoInvalido);
        Assert.Equal(13000, calc.Esperado);   // par do PIS, maior diferença (39)
        Assert.Equal(12961, calc.Obtido);
    }

    [Fact]
    public void Cf07_lp_empate_pega_menor_tributo_por_code_point()
    {
        // CSLL e IRPJ divergem 100 cada; empate => menor code point: CSLL < IRPJ.
        var fatos = new[]
        {
            Fato("apurado", "IRPJ", 160000), Fato("apurado", "CSLL", 144000),
            Fato("divisao_socios", "", null, "{\"parcelas\":[" +
                "{\"tributo\":\"IRPJ\",\"valor_centavos\":159900}," +
                "{\"tributo\":\"CSLL\",\"valor_centavos\":143900}]}"),
        };
        var calc = CalculadoraConferencias.Calcular("CF-07", CtxLp(), fatos);
        Assert.Equal(144000, calc.Esperado); // CSLL (empate, menor por code point)
        Assert.Equal(143900, calc.Obtido);
    }

    [Fact]
    public void Cf07_lp_parcela_sem_tributo_e_operando_invalido()
    {
        var fatos = new[]
        {
            Fato("apurado", "IRPJ", 160000),
            Fato("divisao_socios", "", null, "{\"parcelas\":[" +
                "{\"socio\":\"S1\",\"tributo\":\"IRPJ\",\"valor_centavos\":100000}," +
                "{\"socio\":\"S2\",\"valor_centavos\":60000}]}"),
        };
        var calc = CalculadoraConferencias.Calcular("CF-07", CtxLp(), fatos);
        Assert.NotNull(calc.OperandoInvalido);
    }

    [Fact]
    public void Cf07_lp_tributo_sem_apurado_e_dado_ausente()
    {
        // parcela de ISS sem apurado[ISS] no caso => dado ausente (classe S).
        var fatos = new[]
        {
            Fato("apurado", "IRPJ", 160000),
            Fato("divisao_socios", "", null, "{\"parcelas\":[" +
                "{\"tributo\":\"IRPJ\",\"valor_centavos\":160000}," +
                "{\"tributo\":\"ISS\",\"valor_centavos\":5000}]}"),
        };
        var calc = CalculadoraConferencias.Calcular("CF-07", CtxLp(), fatos);
        Assert.NotNull(calc.FatoAusente);
        Assert.Contains("ISS", calc.FatoAusente);
    }

    [Fact]
    public void Cf07_sn_continua_agregado()
    {
        var ctxSn = new ContextoCalculo(Competencia.Analisar("202608"), null!,
            Contexto.DeSnapshot(JsonDocument.Parse("{\"regime\":\"SN\"}").RootElement.Clone(), 2), "DAS", null);
        var fatos = new[]
        {
            Fato("apurado", "DAS", 600000),
            Fato("divisao_socios", "", null, "{\"parcelas\":[" +
                "{\"socio\":\"S1\",\"valor_centavos\":360000},{\"socio\":\"S2\",\"valor_centavos\":240000}]}"),
        };
        var calc = CalculadoraConferencias.Calcular("CF-07", ctxSn, fatos);
        Assert.Equal(600000, calc.Esperado);
        Assert.Equal(600000, calc.Obtido); // soma de todas as parcelas, sem olhar tributo
    }

    // ----- CF-09 três pares INSS/FGTS/IRRF (§9.1 v4, task 22) -----
    // INSS/FGTS vêm da folha do mês (nos fatos); IRRF vem da folha do mês anterior (contexto).
    private static ContextoCalculo CtxCf09(FatoVigente? folhaAnterior)
        => new(Competencia.Analisar("202609"), null!,
            Contexto.DeSnapshot(JsonDocument.Parse("{\"regime\":\"SN\",\"tem_folha\":true}").RootElement.Clone(), 3),
            "DAS", null, folhaAnterior);

    private static FatoVigente Guia(string tributo, long valor)
        => new(Guid.NewGuid(), "T017", Competencia.Analisar("202609"), "guia", tributo, 1, valor,
            JsonDocument.Parse("{}").RootElement.Clone(), "hash");

    [Fact]
    public void Cf09_tres_pares_grava_o_de_maior_diferenca()
    {
        // folha do mês: INSS 88000, FGTS 32000. folha anterior: irrf 26000. guia IRRF 25000 (1000 abaixo).
        // INSS/FGTS batem; IRRF é a maior diferença.
        var folhaMes = Fato("folha", "", 400000, "{\"inss_centavos\":88000,\"fgts_centavos\":32000}");
        var folhaAnt = Fato("folha", "", 400000, "{\"irrf_centavos\":26000}");
        var calc = CalculadoraConferencias.Calcular("CF-09", CtxCf09(folhaAnt),
            new[] { folhaMes, Guia("INSS", 88000), Guia("FGTS", 32000), Guia("IRRF", 25000) });
        Assert.Null(calc.FatoAusente);
        Assert.Equal(26000, calc.Esperado); // par do IRRF
        Assert.Equal(25000, calc.Obtido);
        Assert.Equal(5, calc.FatosUsados.Count); // folhaMes + folhaAnt + 3 guias
    }

    [Fact]
    public void Cf09_empate_pega_inss_depois_fgts_depois_irrf()
    {
        // Três diferenças iguais (1000): empate => INSS.
        var folhaMes = Fato("folha", "", 400000, "{\"inss_centavos\":89000,\"fgts_centavos\":33000}");
        var folhaAnt = Fato("folha", "", 400000, "{\"irrf_centavos\":27000}");
        var calc = CalculadoraConferencias.Calcular("CF-09", CtxCf09(folhaAnt),
            new[] { folhaMes, Guia("INSS", 88000), Guia("FGTS", 32000), Guia("IRRF", 26000) });
        Assert.Equal(89000, calc.Esperado); // INSS vence o empate
        Assert.Equal(88000, calc.Obtido);
    }

    [Fact]
    public void Cf09_folha_anterior_sem_irrf_e_dado_ausente()
    {
        var folhaMes = Fato("folha", "", 400000, "{\"inss_centavos\":88000,\"fgts_centavos\":32000}");
        var folhaAnt = Fato("folha", "", 400000, "{}"); // sem irrf_centavos
        var calc = CalculadoraConferencias.Calcular("CF-09", CtxCf09(folhaAnt),
            new[] { folhaMes, Guia("INSS", 88000), Guia("FGTS", 32000), Guia("IRRF", 0) });
        Assert.NotNull(calc.FatoAusente);
        Assert.Contains("irrf", calc.FatoAusente);
    }

    [Fact]
    public void Cf09_folha_anterior_ausente_e_dado_ausente()
    {
        var folhaMes = Fato("folha", "", 400000, "{\"inss_centavos\":88000,\"fgts_centavos\":32000}");
        var calc = CalculadoraConferencias.Calcular("CF-09", CtxCf09(null),
            new[] { folhaMes, Guia("INSS", 88000), Guia("FGTS", 32000), Guia("IRRF", 25000) });
        Assert.Equal("folha[]", calc.FatoAusente);
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
        // §4 (v3): 'pago' é aposentado, ordem nula, e nunca é >= a nada
        Assert.False(EstadoEntregavel.Pago.PeloMenos(EstadoEntregavel.Pago));
        Assert.False(EstadoEntregavel.Encerrado.PeloMenos(EstadoEntregavel.Pago));
        Assert.Null(EstadoEntregavel.Pago.Ordem());
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
