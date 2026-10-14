using System.Text.Json;
using Motor.Dominio.Fechamento;

namespace Motor.Dominio.Regras;

/// <summary>Entrada de um item da regra (§7.1), já tipada. <c>Quando</c> segue o formato de §3.</summary>
public sealed record EntradaRegra(string Tipo, string Tributo, int CompetenciaRelativa, JsonElement? Quando);

/// <summary>Dependência de um item da regra (§5.4). <c>EstadoMinimo</c> nulo = padrão da regra.</summary>
public sealed record DependenciaRegra(string Tipo, EstadoEntregavel? EstadoMinimo, JsonElement? Quando);

/// <summary>Conferência de um item da regra (§9).</summary>
public sealed record ConferenciaRegra(string Cf, JsonElement? Quando);

/// <summary>Bloco <c>pos_validacao</c> (§10.1). Os tipos de fato vêm da regra, nunca do código.</summary>
public sealed record PosValidacaoRegra(bool LiberacaoAutomatica, string? DisponibilizadoQuandoTipo);

/// <summary>Bloco <c>acompanhamento</c> (§10.3). Bloco ausente na regra = <c>null</c> (o fato não é tratado).</summary>
public sealed record AcompanhamentoRegra(string? FalhaTipo, string? ConfirmacaoTipo, string? PendenciaTipo);

/// <summary>Entrada já resolvida para um caso: competência deslocada, tipo e tributo (§7.1).</summary>
public readonly record struct EntradaResolvida(Competencia Competencia, string Tipo, string Tributo);

/// <summary>A regra 'fechamento.entregaveis' já carregada (conteúdo jsonb da versão pinada no caso).
/// Interpreta o JSON uma vez, aqui, em domínio puro; quem usa a regra recebe registros tipados.</summary>
public sealed class RegraEntregaveis
{
    /// <summary>Vocabulário de fatos que as fórmulas de §9.1 e o protocolo de §10.1 nomeiam literalmente.</summary>
    public const string TipoGuia = "guia";
    public const string TipoRecibo = "recibo_obrigacao";

    public Guid VersaoId { get; }
    public EstadoEntregavel EstadoMinimoPadrao { get; }
    public IReadOnlyList<ItemEntregavel> Itens { get; }

    public RegraEntregaveis(Guid versaoId, JsonElement conteudo)
    {
        VersaoId = versaoId;
        ItemEntregavel.ExigirObjeto(conteudo, "fechamento.entregaveis");
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

    /// <summary>Item de entrega ao cliente: o que tem <c>pos_validacao</c> (no spike, o E11; §10.1).</summary>
    public ItemEntregavel? ItemDeProtocolo => Itens.FirstOrDefault(i => i.PosValidacao is not null);

    /// <summary>Tributo da guia de entrada do item de protocolo, resolvida para o contexto do caso
    /// (DAS no Simples, IRPJ no Presumido, conforme a regra). Nulo se a regra não tem essa entrada.</summary>
    public string? TributoDaGuia(Contexto ctx, Competencia competencia)
        => ItemDeProtocolo?.EntradasResolvidas(ctx, competencia).FirstOrDefault(e => e.Tipo == TipoGuia).Tributo;
}

/// <summary>Um item da regra (um tipo de entregável), com seus blocos tipados.</summary>
public sealed class ItemEntregavel
{
    public string Tipo { get; }
    public string Executor { get; }
    public JsonElement? Quando { get; }
    public bool EncerraCaso { get; }
    public string? SaidaTipo { get; }
    public IReadOnlyList<DependenciaRegra> Dependencias { get; }
    public IReadOnlyList<EntradaRegra> Entradas { get; }
    public IReadOnlyList<ConferenciaRegra> Conferencias { get; }
    public PosValidacaoRegra? PosValidacao { get; }
    public AcompanhamentoRegra? Acompanhamento { get; }

    internal static void ExigirObjeto(JsonElement e, string caminho)
    {
        if (e.ValueKind != JsonValueKind.Object)
            throw new InvalidOperationException($"regra {caminho}: esperado objeto JSON, veio {e.ValueKind}");
    }

    private static string TextoObrigatorio(JsonElement pai, string campo, string caminho)
    {
        ExigirObjeto(pai, caminho);
        if (!pai.TryGetProperty(campo, out var v) || v.ValueKind != JsonValueKind.String || v.GetString() is not { } t)
            throw new InvalidOperationException($"regra {caminho}.{campo} ausente ou não é texto");
        return t;
    }

    public ItemEntregavel(JsonElement bruto)
    {
        Tipo = TextoObrigatorio(bruto, "tipo", "entregaveis[]");
        Executor = TextoObrigatorio(bruto, "executor", $"entregaveis[{Tipo}]");
        Quando = Obj(bruto, "quando");
        EncerraCaso = bruto.TryGetProperty("encerra_caso", out var enc) && enc.ValueKind == JsonValueKind.True;
        SaidaTipo = bruto.TryGetProperty("saida", out var s) && s.ValueKind == JsonValueKind.Object
            ? TextoObrigatorio(s, "tipo", $"{Tipo}.saida")
            : null;
        Dependencias = Lista(bruto, "depende_de").Select(d => new DependenciaRegra(
            TextoObrigatorio(d, "tipo", $"{Tipo}.depende_de[]"),
            d.TryGetProperty("estado_minimo", out var em) && em.ValueKind == JsonValueKind.String
                ? Estados.Analisar(em.GetString()!) : null,
            Obj(d, "quando"))).ToList();
        Entradas = Lista(bruto, "entradas").Select(e => new EntradaRegra(
            TextoObrigatorio(e, "tipo", $"{Tipo}.entradas[]"),
            e.TryGetProperty("tributo", out var t) && t.ValueKind == JsonValueKind.String ? t.GetString()! : "",
            e.TryGetProperty("competencia_relativa", out var cr) && cr.ValueKind == JsonValueKind.Number ? cr.GetInt32() : 0,
            Obj(e, "quando"))).ToList();
        Conferencias = Lista(bruto, "conferencias")
            .Select(c => new ConferenciaRegra(TextoObrigatorio(c, "cf", $"{Tipo}.conferencias[]"), Obj(c, "quando"))).ToList();
        PosValidacao = bruto.TryGetProperty("pos_validacao", out var pv) && pv.ValueKind == JsonValueKind.Object
            ? new PosValidacaoRegra(
                pv.TryGetProperty("liberacao", out var l) && l.ValueKind == JsonValueKind.String && l.GetString() == "automatica",
                TipoDoBloco(pv, "disponibilizado_quando"))
            : null;
        Acompanhamento = bruto.TryGetProperty("acompanhamento", out var ac) && ac.ValueKind == JsonValueKind.Object
            ? new AcompanhamentoRegra(TipoDoBloco(ac, "falha"), TipoDoBloco(ac, "confirmacao"), TipoDoBloco(ac, "pendencia"))
            : null;
    }

    /// <summary>Entradas cujo <c>quando</c> casa no contexto, com a competência deslocada (§7.1).</summary>
    public IEnumerable<EntradaResolvida> EntradasResolvidas(Contexto ctx, Competencia competencia)
        => Entradas.Where(e => Condicao.Casa(e.Quando, ctx))
            .Select(e => new EntradaResolvida(competencia.Somar(e.CompetenciaRelativa), e.Tipo, e.Tributo));

    private static JsonElement? Obj(JsonElement pai, string nome) =>
        pai.ValueKind == JsonValueKind.Object && pai.TryGetProperty(nome, out var v) && v.ValueKind != JsonValueKind.Null ? v : null;

    private static IEnumerable<JsonElement> Lista(JsonElement pai, string nome) =>
        pai.ValueKind == JsonValueKind.Object && pai.TryGetProperty(nome, out var v) && v.ValueKind == JsonValueKind.Array ? v.EnumerateArray() : [];

    private static string? TipoDoBloco(JsonElement pai, string chave) =>
        pai.ValueKind == JsonValueKind.Object && pai.TryGetProperty(chave, out var o) && o.ValueKind == JsonValueKind.Object
        && o.TryGetProperty("tipo", out var t) && t.ValueKind == JsonValueKind.String
            ? t.GetString() : null;
}
