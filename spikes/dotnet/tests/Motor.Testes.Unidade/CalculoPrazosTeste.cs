using System.Text.Json;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Fechamento.Prazos;
using Motor.Dominio.Regras;
using Xunit;

namespace Motor.Testes.Unidade;

/// <summary>Testes do cálculo de datas de prazo (§18.2), domínio puro, sem banco (requisito 25.2).
/// As regras v1 são as da V010 (feriados nacionais de 2026/2027, meta −5 dias corridos, ação −2 dias úteis).</summary>
public sealed class CalculoPrazosTeste
{
    // Feriados nacionais da V010 relevantes (nov/2026: 02, 15, 20; out/2026 sem feriado).
    private const string CalendarioV1 = """
    {
      "feriados": ["2026-01-01","2026-04-03","2026-04-21","2026-05-01","2026-09-07","2026-10-12",
                   "2026-11-02","2026-11-15","2026-11-20","2026-12-25",
                   "2027-01-01","2027-03-26","2027-04-21","2027-05-01","2027-09-07","2027-10-12",
                   "2027-11-02","2027-11-15","2027-11-20","2027-12-25"],
      "prorrogacoes": []
    }
    """;

    private const string CatalogoV1 = """
    {
      "obrigacoes": [
        {"codigo":"SN-01","quando":[{"regime":"SN"}],"vencimento":{"meses_apos":1,"dia":20,"dia_nao_util":"posterga"}},
        {"codigo":"PJ-02","quando":[{"tem_folha":true},{"tem_prolabore":true}],"vencimento":{"meses_apos":1,"dia":20,"dia_nao_util":"antecipa"}},
        {"codigo":"PJ-03","quando":[{"tem_folha":true}],"vencimento":{"meses_apos":1,"dia":20,"dia_nao_util":"antecipa"}},
        {"codigo":"PJ-04","quando":[{"tem_folha":true}],"vencimento":{"meses_apos":1,"dia":20,"dia_nao_util":"antecipa"}},
        {"codigo":"LP-03","quando":[{"regime":"LP"}],"vencimento":{"meses_apos":1,"dia":25,"dia_nao_util":"antecipa"}},
        {"codigo":"LP-05","quando":[{"regime":"LP"}],"vencimento":{"meses_apos":1,"dia":"ultimo_dia_util"}}
      ]
    }
    """;

    private const string PrazosV1 = """
    {
      "padrao": {"meta":[{"dias_corridos":-5}],"acao":[{"dias_uteis":-2}],"estado_cumprimento":"validado"},
      "entregaveis": [
        {"tipo":"E03","vencimento_ref":["SN-01","LP-03"],"gera_multa":true},
        {"tipo":"E08","vencimento_ref":["PJ-02","PJ-03","PJ-04"],"gera_multa":true},
        {"tipo":"E10","vencimento_ref":["SN-01","LP-05"],"gera_multa":true},
        {"tipo":"E11","vencimento_ref":["SN-01","PJ-02","PJ-03","PJ-04"],"gera_multa":true,"estado_cumprimento":"disponibilizado"}
      ]
    }
    """;

    private static Calendario Cal(string json = CalendarioV1) => Calendario.DeJson(Doc(json));
    private static CatalogoObrigacoes Cat(string json = CatalogoV1) => CatalogoObrigacoes.DeJson(Doc(json));
    private static RegraPrazos Prz(string json = PrazosV1) => new(Guid.NewGuid(), Doc(json));
    private static JsonElement Doc(string json) => JsonDocument.Parse(json).RootElement.Clone();

    private static Contexto CtxSnFolha() => Ctx("""{"regime":"SN","tem_folha":true,"tem_prolabore":false}""", 1);
    private static Contexto CtxLp(int mesTrimestre) => Ctx("""{"regime":"LP","tem_folha":false,"tem_prolabore":false}""", mesTrimestre);
    private static Contexto Ctx(string snapshot, int mesTrimestre) => Contexto.DeSnapshot(Doc(snapshot), mesTrimestre);

    private static DateOnly D(string iso) => DateOnly.Parse(iso);

    // --- dia útil ---
    [Fact]
    public void DiaUtil_SabadoDomingoFeriado_NaoSaoUteis()
    {
        var c = Cal();
        Assert.False(c.EhDiaUtil(D("2026-11-14"))); // sábado
        Assert.False(c.EhDiaUtil(D("2026-11-15"))); // domingo + feriado
        Assert.False(c.EhDiaUtil(D("2026-11-20"))); // sexta feriado (Consciência Negra)
        Assert.True(c.EhDiaUtil(D("2026-11-13")));  // sexta útil
    }

    // --- posterga (SN-01, dia 20) ---
    [Fact]
    public void Posterga_DAS_20Nov_SextaFeriado_VaiPara23()
    {
        // SN-01 competência 202610 -> mês-base 202611, dia 20 (sexta, feriado) -> posterga -> 23/11 (segunda).
        var d = CalculoPrazos.DataObrigacao("SN-01", Competencia.Analisar("202610"), CtxSnFolha(), Cal(), Cat());
        Assert.Equal(D("2026-11-23"), d);
    }

    // --- antecipa (PJ-02/03/04, dia 20) ---
    [Fact]
    public void Antecipa_DCTFWeb_20Nov_SextaFeriado_VaiPara19()
    {
        var d = CalculoPrazos.DataObrigacao("PJ-02", Competencia.Analisar("202610"), CtxSnFolha(), Cal(), Cat());
        Assert.Equal(D("2026-11-19"), d);
    }

    // --- ultimo_dia_util ---
    [Fact]
    public void UltimoDiaUtil_LP05_Outubro_VaiPara30()
    {
        // LP-05 competência 202609 -> mês-base 202610; 31/10 é sábado -> volta -> 30/10 (sexta útil).
        var d = CalculoPrazos.DataObrigacao("LP-05", Competencia.Analisar("202609"), CtxLp(3), Cal(), Cat());
        Assert.Equal(D("2026-10-30"), d);
    }

    // --- prorrogação fixa a data, sem ajuste ---
    [Fact]
    public void Prorrogacao_FixaNovaData_SemAjuste()
    {
        var cal = Cal("""
        {"feriados":["2026-11-20"],
         "prorrogacoes":[{"obrigacao":"SN-01","competencia":"202610","nova_data":"2026-12-05","ato":"IN 1"}]}
        """);
        // 05/12/2026 é sábado, mas prorrogação não ajusta.
        var d = CalculoPrazos.DataObrigacao("SN-01", Competencia.Analisar("202610"), CtxSnFolha(), cal, Cat());
        Assert.Equal(D("2026-12-05"), d);
    }

    // --- virada de ano (competência 202612 -> mês-base 202701) ---
    [Fact]
    public void ViradaDeAno_Competencia202612_MesBaseJaneiro2027()
    {
        // SN-01 202612 -> 202701, dia 20 (quarta, útil) -> 20/01/2027.
        var d = CalculoPrazos.DataObrigacao("SN-01", Competencia.Analisar("202612"), CtxSnFolha(), Cal(), Cat());
        Assert.Equal(D("2027-01-20"), d);
    }

    // --- meta no fim de semana antecipa ---
    [Fact]
    public void Meta_NoSabado_AntecipaParaSexta()
    {
        // E08 SN 202610: vencimento = PJ-02/03/04 todas 19/11; meta = 19 − 5 = 14/11 (sábado) -> 13/11 (sexta).
        var p = CalculoPrazos.Calcular("E08", Competencia.Analisar("202610"), CtxSnFolha(), Cal(), Cat(), Prz());
        Assert.NotNull(p);
        Assert.Equal(D("2026-11-19"), p!.Value.Vencimento);
        Assert.Equal(D("2026-11-13"), p.Value.Meta);
    }

    // --- ação atravessando feriado ---
    [Fact]
    public void Acao_RecuaDiasUteis_AtravessandoFeriado()
    {
        // E08 SN 202610: meta 13/11 (sexta). Ação = −2 dias úteis: 12/11 (qui), 11/11 (qua) -> 11/11.
        var p = CalculoPrazos.Calcular("E08", Competencia.Analisar("202610"), CtxSnFolha(), Cal(), Cat(), Prz());
        Assert.Equal(D("2026-11-11"), p!.Value.Acao);
    }

    // --- exemplo completo do §18.2 (C27) ---
    [Theory]
    [InlineData("E03", "2026-11-23", "2026-11-18", "2026-11-16")] // SN-01 posterga 23; meta 18; ação 16
    [InlineData("E08", "2026-11-19", "2026-11-13", "2026-11-11")] // PJ antecipa 19; meta 14->13; ação 11
    [InlineData("E11", "2026-11-19", "2026-11-13", "2026-11-11")] // guias: mais cedo = 19
    public void ExemploSecao182_SN_Competencia202610(string tipo, string venc, string meta, string acao)
    {
        var p = CalculoPrazos.Calcular(tipo, Competencia.Analisar("202610"), CtxSnFolha(), Cal(), Cat(), Prz());
        Assert.NotNull(p);
        Assert.Equal(D(venc), p!.Value.Vencimento);
        Assert.Equal(D(meta), p.Value.Meta);
        Assert.Equal(D(acao), p.Value.Acao);
    }

    // --- tipo fora da lista não tem prazo ---
    [Fact]
    public void TipoForaDaLista_SemPrazo()
    {
        var p = CalculoPrazos.Calcular("E01", Competencia.Analisar("202610"), CtxSnFolha(), Cal(), Cat(), Prz());
        Assert.Null(p);
    }

    // --- nenhuma obrigação aplicável (quando não casa) = sem prazo ---
    [Fact]
    public void NenhumaObrigacaoAplica_SemPrazo()
    {
        // E08 depende de PJ-* (tem_folha/tem_prolabore); num caso LP sem folha nenhuma casa.
        var p = CalculoPrazos.Calcular("E08", Competencia.Analisar("202609"), CtxLp(3), Cal(), Cat(), Prz());
        Assert.Null(p);
    }

    // --- E03 do Presumido (C27 T027B): LP-03 dia 25 antecipa ---
    [Fact]
    public void E03_Presumido_202609_LP03Dia25Domingo_Antecipa23()
    {
        // LP-03 202609 -> 202610, dia 25 (domingo) -> antecipa -> 23/10 (sexta). Meta 18->? ação.
        var p = CalculoPrazos.Calcular("E03", Competencia.Analisar("202609"), CtxLp(3), Cal(), Cat(), Prz());
        Assert.NotNull(p);
        Assert.Equal(D("2026-10-23"), p!.Value.Vencimento);
        Assert.Equal(D("2026-10-16"), p.Value.Meta);  // 23 − 5 = 18 (dom) -> wait: 18/10 is Sunday -> 16 (fri)
        Assert.Equal(D("2026-10-14"), p.Value.Acao);   // −2 úteis: 15 (qui), 14 (qua)
    }
}
