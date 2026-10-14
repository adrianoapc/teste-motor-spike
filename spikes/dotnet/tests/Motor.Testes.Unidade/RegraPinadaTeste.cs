using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fatos;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;
using Xunit;

namespace Motor.Testes.Unidade;

/// <summary>Valores que dependem da regra pinada do caso (tributo da guia, recibo, parâmetros fiscais) vêm da
/// regra, não do código; e a regra de prontidão (§7.2) é função pura, testada sem banco.</summary>
public class RegraPinadaTeste
{
    private static JsonElement J(string s) => JsonDocument.Parse(s).RootElement.Clone();
    private static readonly Competencia C = Competencia.Analisar("202609");

    private static RegraEntregaveis Regra(string tributoGuiaSn = "DAS", string reciboSn = "PGDAS-D") => new(Guid.NewGuid(), J("""
        {"entregaveis":[
          {"tipo":"E10","executor":"sistema","entradas":[
             {"tipo":"recibo_obrigacao","tributo":"@RECIBO@","quando":[{"regime":"SN"}]},
             {"tipo":"recibo_obrigacao","tributo":"DCTFWEB","quando":[{"regime":"LP"}]}]},
          {"tipo":"E11","executor":"sistema","entradas":[
             {"tipo":"guia","tributo":"@GUIA@","quando":[{"regime":"SN"}]},
             {"tipo":"guia","tributo":"IRPJ","quando":[{"regime":"LP"}]}],
           "pos_validacao":{"liberacao":"automatica","disponibilizado_quando":{"tipo":"documento_disponibilizado"}},
           "acompanhamento":{"falha":{"tipo":"entrega_falhou"}}}]}
        """.Replace("@RECIBO@", reciboSn).Replace("@GUIA@", tributoGuiaSn)));

    private static Contexto Ctx(string regime) => Contexto.DeSnapshot(J($"{{\"regime\":\"{regime}\"}}"), 3);

    private static FatoVigente F(string tipo, string trib, long? valor, string payload = "{}")
        => new(Guid.NewGuid(), "T001", C, tipo, trib, 1, valor, J(payload), "h");

    [Theory]
    [InlineData("SN", "DAS")]
    [InlineData("LP", "IRPJ")]
    public void Tributo_da_guia_vem_da_entrada_do_item_de_protocolo(string regime, string esperado)
        => Assert.Equal(esperado, Regra().TributoDaGuia(Ctx(regime), C));

    [Fact]
    public void Tributo_da_guia_acompanha_a_regra_pinada_e_nao_o_regime()
        => Assert.Equal("DAS-NOVO", Regra(tributoGuiaSn: "DAS-NOVO").TributoDaGuia(Ctx("SN"), C));

    [Fact]
    public void Regra_sem_item_de_protocolo_nao_tem_tributo_de_guia()
        => Assert.Null(new RegraEntregaveis(Guid.NewGuid(), J("""{"entregaveis":[{"tipo":"E01","executor":"sistema"}]}""")).TributoDaGuia(Ctx("SN"), C));

    [Fact]
    public void Item_de_protocolo_e_blocos_tipados()
    {
        var item = Regra().ItemDeProtocolo!;
        Assert.Equal("E11", item.Tipo);
        Assert.True(item.PosValidacao!.LiberacaoAutomatica);
        Assert.Equal("documento_disponibilizado", item.PosValidacao.DisponibilizadoQuandoTipo);
        Assert.Equal("entrega_falhou", item.Acompanhamento!.FalhaTipo);
        Assert.Null(item.Acompanhamento.ConfirmacaoTipo); // bloco ausente na regra: sem valor-padrão escondido no código
    }

    [Fact]
    public void Cf05_usa_o_tributo_da_regra_para_apurado_e_guia()
    {
        var ctx = new ContextoCalculo(C, default!, Ctx("SN"), "DAS-NOVO", null);
        var c = CalculadoraConferencias.Calcular("CF-05", ctx,
            new[] { F("apurado", "DAS-NOVO", 100), F("guia", "DAS-NOVO", 100), F("apurado", "DAS", 999) });
        Assert.Equal(100, c.Esperado);
        Assert.Equal(100, c.Obtido);
    }

    [Theory]
    [InlineData("CF-05")]
    [InlineData("CF-06")]
    [InlineData("CF-07")]
    public void Sem_guia_na_regra_a_conferencia_e_operando_invalido_e_nao_assume_DAS(string cf)
    {
        var ctx = new ContextoCalculo(C, default!, Ctx("SN"), null, null);
        var c = CalculadoraConferencias.Calcular(cf, ctx, new[] { F("apurado", "DAS", 1), F("guia", "DAS", 1) });
        Assert.NotNull(c.OperandoInvalido);
    }

    [Fact]
    public void Cf12_usa_o_tributo_do_recibo_exigido_pela_entrada_da_regra()
    {
        var regra = Regra(reciboSn: "OUTRO");
        var ctx = new ContextoCalculo(C, regra.Item("E10")!, Ctx("SN"), null, null);
        Assert.True(CalculadoraConferencias.Calcular("CF-12", ctx, new[] { F("recibo_obrigacao", "OUTRO", null) }).ExistenciaOk);
        Assert.False(CalculadoraConferencias.Calcular("CF-12", ctx, new[] { F("recibo_obrigacao", "PGDAS-D", null) }).ExistenciaOk);
    }

    [Fact]
    public void Cf08_sem_percentual_do_prolabore_e_operando_invalido_e_nao_zero()
    {
        var ctx = new ContextoCalculo(C, default!, Ctx("SN"), "DAS", null);
        var c = CalculadoraConferencias.Calcular("CF-08", ctx, new[] { F("faturamento_mes", "", 10_000_000), F("prolabore", "", 0) });
        Assert.NotNull(c.OperandoInvalido);
        Assert.Null(c.Esperado);
    }

    [Fact]
    public void Prolabore_ausente_ou_malformado_nao_vira_zero()
    {
        Assert.Equal(2800, ParametrosFiscais.LerProlaboreBp(J("""{"prolabore_percentual_bp":2800}""")));
        Assert.Null(ParametrosFiscais.LerProlaboreBp(null));
        Assert.Null(ParametrosFiscais.LerProlaboreBp(J("{}")));
        Assert.Null(ParametrosFiscais.LerProlaboreBp(J("""{"prolabore_percentual_bp":"2800"}""")));
        Assert.Null(ParametrosFiscais.LerProlaboreBp(J("""{"prolabore_percentual_bp":28.5}""")));
    }

    [Fact]
    public void Tolerancia_valida_e_lida()
        => Assert.Equal(new Tolerancia(0, 100),
            ParametrosFiscais.LerTolerancia(J("""{"padrao":{"ok_ate_centavos":0,"alerta_ate_centavos":100}}""")));

    [Theory]
    [InlineData("{}")]
    [InlineData("""{"padrao":{"ok_ate_centavos":0}}""")]
    [InlineData("""{"padrao":{"ok_ate_centavos":"0","alerta_ate_centavos":100}}""")]
    [InlineData("""{"padrao":{"ok_ate_centavos":0,"alerta_ate_centavos":1.5}}""")]
    public void Tolerancia_ausente_ou_malformada_e_erro_explicito(string json)
        => Assert.Throws<InvalidOperationException>(() => ParametrosFiscais.LerTolerancia(J(json)));

    // ----- Prontidão (§7.2) como função pura -----
    private static DependenciaLida Dep(EstadoEntregavel? atual, EstadoEntregavel minimo) => new(atual, minimo);

    [Fact]
    public void Prontidao_sem_dependencias_nem_entradas_esta_satisfeita()
        => Assert.True(Prontidao.Satisfeita([], []));

    [Fact]
    public void Prontidao_exige_estado_minimo_em_toda_dependencia()
    {
        Assert.True(Prontidao.Satisfeita([Dep(EstadoEntregavel.Validado, EstadoEntregavel.Validado),
            Dep(EstadoEntregavel.Encerrado, EstadoEntregavel.Validado)], []));
        Assert.False(Prontidao.Satisfeita([Dep(EstadoEntregavel.Validado, EstadoEntregavel.Validado),
            Dep(EstadoEntregavel.Processado, EstadoEntregavel.Validado)], []));
    }

    [Theory]
    [InlineData(EstadoEntregavel.Divergente)]
    [InlineData(EstadoEntregavel.Invalidado)]
    public void Prontidao_estado_de_ordem_nula_nunca_satisfaz(EstadoEntregavel atual)
        => Assert.False(Prontidao.DependenciasSatisfeitas([Dep(atual, EstadoEntregavel.AguardandoInsumo)]));

    [Fact]
    public void Prontidao_alvo_inexistente_nao_satisfaz()
        => Assert.False(Prontidao.DependenciasSatisfeitas([Dep(null, EstadoEntregavel.Validado)]));

    [Fact]
    public void Prontidao_exige_toda_entrada_presente()
    {
        var dep = Dep(EstadoEntregavel.Validado, EstadoEntregavel.Validado);
        Assert.True(Prontidao.Satisfeita([dep], [true, true]));
        Assert.False(Prontidao.Satisfeita([dep], [true, false]));
    }

    [Fact]
    public void Entradas_resolvidas_deslocam_a_competencia_e_respeitam_o_quando()
    {
        var item = new ItemEntregavel(J("""
            {"tipo":"E03","executor":"humano","entradas":[
              {"tipo":"apurado","tributo":"IRPJ","competencia_relativa":-1,"quando":[{"regime":"LP","mes_do_trimestre":3}]},
              {"tipo":"apurado","tributo":"IRPJ","competencia_relativa":-2,"quando":[{"regime":"LP","mes_do_trimestre":3}]},
              {"tipo":"x","quando":[{"regime":"SN"}]}]}
            """));
        var r = item.EntradasResolvidas(Contexto.DeSnapshot(J("""{"regime":"LP"}"""), 3), Competencia.Analisar("202601")).ToList();
        Assert.Equal(new[] { "202512", "202511" }, r.Select(x => x.Competencia.ToString()));
        Assert.All(r, x => Assert.Equal("IRPJ", x.Tributo));
    }
}
