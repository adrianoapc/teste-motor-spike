using Motor.Dominio.Fechamento;
using NetArchTest.Rules;
using Xunit;

namespace Motor.Testes.Arquitetura;

public class CamadasTeste
{
    private static System.Reflection.Assembly Dominio => typeof(Competencia).Assembly;
    private static System.Reflection.Assembly Aplicacao => typeof(Motor.Aplicacao.MotorServico).Assembly;

    private const string NsDominio = "Motor.Dominio";
    private const string NsAplicacao = "Motor.Aplicacao";
    private const string NsInfra = "Motor.Infraestrutura";
    private const string NsApi = "Motor.Api";

    [Fact]
    public void Dominio_nao_depende_de_nenhuma_outra_camada()
    {
        var resultado = Types.InAssembly(Dominio)
            .ShouldNot()
            .HaveDependencyOnAny(NsAplicacao, NsInfra, NsApi)
            .GetResult();
        Assert.True(resultado.IsSuccessful, Falhas(resultado));
    }

    [Fact]
    public void Dominio_nao_depende_de_banco_nem_web()
    {
        var resultado = Types.InAssembly(Dominio)
            .ShouldNot()
            .HaveDependencyOnAny("Npgsql", "Dapper", "Microsoft.AspNetCore")
            .GetResult();
        Assert.True(resultado.IsSuccessful, Falhas(resultado));
    }

    [Fact]
    public void Aplicacao_nao_depende_de_api_nem_de_driver_de_banco()
    {
        var resultado = Types.InAssembly(Aplicacao)
            .ShouldNot()
            .HaveDependencyOnAny(NsApi, NsInfra, "Npgsql", "Dapper", "Microsoft.AspNetCore")
            .GetResult();
        Assert.True(resultado.IsSuccessful, Falhas(resultado));
    }

    private static string Falhas(TestResult r) =>
        r.IsSuccessful ? "" : "Tipos que violam a regra: " + string.Join(", ",
            r.FailingTypeNames ?? Array.Empty<string>());
}
