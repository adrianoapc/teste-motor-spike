using System.Net;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using Microsoft.AspNetCore.Mvc.Testing;
using Xunit;

namespace Motor.Testes.Unidade;

/// <summary>Integração HTTP em memória: as validações de forma (§6, §8 passo 0) respondem 422 antes de tocar o banco.</summary>
public class PayloadApiTeste : IClassFixture<PayloadApiTeste.Fabrica>
{
    public sealed class Fabrica : WebApplicationFactory<Program>
    {
        public Fabrica() => Environment.SetEnvironmentVariable("MOTOR_DB", "Host=invalido;Port=1;Database=x;Username=x;Password=x");
    }

    private readonly HttpClient _http;
    public PayloadApiTeste(Fabrica f) => _http = f.CreateClient();

    public static IEnumerable<object[]> Rejeitados() => VetoresHash.Rejeitados();

    private static async Task Espera422(HttpResponseMessage r)
    {
        Assert.Equal(HttpStatusCode.UnprocessableEntity, r.StatusCode);
        var j = await r.Content.ReadFromJsonAsync<JsonElement>();
        Assert.Equal("corpo_invalido", j.GetProperty("erro").GetString());
    }

    private static string Extra(Vetor v)
    {
        var s = v.CorpoParcial();
        return s.Length > 2 ? "," + s[1..^1] : "";
    }

    private static StringContent Json(string s) => new(s, Encoding.UTF8, "application/json");

    private const string CabecalhoFato =
        "{\"titular_id\":\"T1\",\"competencia\":\"202609\",\"tipo\":\"x\",\"fonte\":\"t\",\"observado_em\":\"2026-09-01T00:00:00Z\"";

    [Theory, MemberData(nameof(Rejeitados))]
    public async Task Fatos_rejeita_422(Vetor v)
        => await Espera422(await _http.PostAsync("/fatos", Json(CabecalhoFato + Extra(v) + "}")));

    [Theory, MemberData(nameof(Rejeitados))]
    public async Task Concluir_rejeita_422_antes_de_consultar_o_caso(Vetor v)
        => await Espera422(await _http.PostAsync("/casos/T1/202609/entregaveis/DAS/concluir",
            Json("{\"ator\":\"a\",\"saidas\":[{\"tributo\":\"DAS\"" + Extra(v) + "}]}")));

    [Fact]
    public async Task Concluir_saidas_vazio_e_422_antes_de_404()
        => await Espera422(await _http.PostAsync("/casos/INEXISTENTE/202609/entregaveis/DAS/concluir",
            Json("{\"ator\":\"a\",\"saidas\":[]}")));

    [Fact]
    public async Task Concluir_valida_todas_as_saidas_antes_de_gravar()
        => await Espera422(await _http.PostAsync("/casos/INEXISTENTE/202609/entregaveis/DAS/concluir",
            Json("{\"ator\":\"a\",\"saidas\":[{\"payload\":{}},{\"payload\":{\"n\":0.5}}]}")));

    [Fact]
    public async Task Mensagem_distingue_causa_e_traz_o_caminho()
    {
        var r1 = await _http.PostAsync("/fatos", Json(CabecalhoFato + ",\"payload\":[1]}"));
        var r2 = await _http.PostAsync("/fatos", Json(CabecalhoFato + ",\"payload\":{\"a\":[1,{\"b\":2.5}]}}"));
        var m1 = (await r1.Content.ReadFromJsonAsync<JsonElement>()).GetProperty("mensagem").GetString();
        var m2 = (await r2.Content.ReadFromJsonAsync<JsonElement>()).GetProperty("mensagem").GetString();
        Assert.Contains("deve ser um objeto", m1);
        Assert.Contains(".a[1].b", m2);
    }
}
