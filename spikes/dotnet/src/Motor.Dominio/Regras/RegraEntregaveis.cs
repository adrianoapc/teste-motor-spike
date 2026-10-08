using System.Text.Json;
using Motor.Dominio.Fechamento;

namespace Motor.Dominio.Regras;

/// <summary>A regra 'fechamento.entregaveis' já carregada (conteúdo jsonb da versão em uso).
/// Expõe os itens brutos como JsonElement; a interpretação acontece aqui, em domínio puro.</summary>
public sealed class RegraEntregaveis
{
    public Guid VersaoId { get; }
    public EstadoEntregavel EstadoMinimoPadrao { get; }
    public IReadOnlyList<ItemEntregavel> Itens { get; }

    public RegraEntregaveis(Guid versaoId, JsonElement conteudo)
    {
        VersaoId = versaoId;
        EstadoMinimoPadrao = conteudo.TryGetProperty("estado_minimo_padrao", out var emp)
            ? Estados.Analisar(emp.GetString()!)
            : EstadoEntregavel.Validado;
        var itens = new List<ItemEntregavel>();
        if (conteudo.TryGetProperty("entregaveis", out var arr) && arr.ValueKind == JsonValueKind.Array)
        {
            foreach (var it in arr.EnumerateArray())
                itens.Add(new ItemEntregavel(it));
        }
        Itens = itens;
    }

    public ItemEntregavel? Item(string tipo) => Itens.FirstOrDefault(i => i.Tipo == tipo);
}

/// <summary>Um item da regra (um tipo de entregável) com seu bruto JSON.</summary>
public sealed class ItemEntregavel
{
    public JsonElement Bruto { get; }
    public string Tipo { get; }
    public string Executor { get; }

    public ItemEntregavel(JsonElement bruto)
    {
        Bruto = bruto;
        Tipo = bruto.GetProperty("tipo").GetString()!;
        Executor = bruto.GetProperty("executor").GetString()!;
    }

    public JsonElement? Quando => Prop("quando");
    public bool EncerraCaso => Bruto.TryGetProperty("encerra_caso", out var v) && v.ValueKind == JsonValueKind.True;
    public JsonElement? PosValidacao => Prop("pos_validacao");

    public JsonElement? Saida =>
        Bruto.TryGetProperty("saida", out var s) && s.ValueKind == JsonValueKind.Object ? s : null;

    public IEnumerable<JsonElement> Dependencias => Lista("depende_de");
    public IEnumerable<JsonElement> Entradas => Lista("entradas");
    public IEnumerable<JsonElement> Conferencias => Lista("conferencias");

    private JsonElement? Prop(string nome) =>
        Bruto.TryGetProperty(nome, out var v) && v.ValueKind != JsonValueKind.Null ? v : null;

    private IEnumerable<JsonElement> Lista(string nome)
    {
        if (Bruto.TryGetProperty(nome, out var v) && v.ValueKind == JsonValueKind.Array)
            foreach (var it in v.EnumerateArray())
                yield return it;
    }
}
