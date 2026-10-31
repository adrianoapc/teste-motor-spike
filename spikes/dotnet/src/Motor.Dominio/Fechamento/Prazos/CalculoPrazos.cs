using System.Text.Json;
using Motor.Dominio.Regras;

namespace Motor.Dominio.Fechamento.Prazos;

/// <summary>As três datas de um entregável com prazo (§18.2). Todas nulas quando nenhuma
/// obrigação se aplica (o entregável não tem prazo).</summary>
public readonly record struct Prazo(DateOnly Vencimento, DateOnly Meta, DateOnly Acao);

/// <summary>Calendário de vencimentos (regra <c>calendario.vencimentos</c>, §18.1), já lido.
/// Guarda os feriados nacionais e as prorrogações. Domínio puro: não lê banco.</summary>
public sealed class Calendario
{
    private readonly HashSet<DateOnly> _feriados;
    private readonly List<Prorrogacao> _prorrogacoes;

    /// <summary>Uma prorrogação casa por (obrigacao, competencia) e fixa a data, sem ajuste (§18.2 passo 1).</summary>
    public readonly record struct Prorrogacao(string Obrigacao, string Competencia, DateOnly NovaData);

    public Calendario(IEnumerable<DateOnly> feriados, IEnumerable<Prorrogacao> prorrogacoes)
    {
        _feriados = new HashSet<DateOnly>(feriados);
        _prorrogacoes = prorrogacoes.ToList();
    }

    public static Calendario DeJson(JsonElement conteudo)
    {
        var feriados = new List<DateOnly>();
        if (conteudo.TryGetProperty("feriados", out var fs) && fs.ValueKind == JsonValueKind.Array)
            foreach (var f in fs.EnumerateArray())
                if (f.ValueKind == JsonValueKind.String && DateOnly.TryParse(f.GetString(), out var d))
                    feriados.Add(d);
        var prorrogacoes = new List<Prorrogacao>();
        if (conteudo.TryGetProperty("prorrogacoes", out var ps) && ps.ValueKind == JsonValueKind.Array)
            foreach (var p in ps.EnumerateArray())
            {
                if (p.ValueKind != JsonValueKind.Object) continue;
                var obrig = Texto(p, "obrigacao");
                var comp = Texto(p, "competencia");
                var novaData = Texto(p, "nova_data");
                if (obrig is null || comp is null || novaData is null || !DateOnly.TryParse(novaData, out var nd)) continue;
                prorrogacoes.Add(new Prorrogacao(obrig, comp, nd));
            }
        return new Calendario(feriados, prorrogacoes);
    }

    public bool EhFeriado(DateOnly d) => _feriados.Contains(d);

    /// <summary>Dia útil: não é sábado, não é domingo e não é feriado (§18.2).</summary>
    public bool EhDiaUtil(DateOnly d) =>
        d.DayOfWeek != DayOfWeek.Saturday && d.DayOfWeek != DayOfWeek.Sunday && !EhFeriado(d);

    /// <summary>Volta ao dia útil anterior (inclui o próprio se já útil).</summary>
    public DateOnly AntecipaParaUtil(DateOnly d)
    {
        while (!EhDiaUtil(d)) d = d.AddDays(-1);
        return d;
    }

    /// <summary>Avança ao próximo dia útil (inclui o próprio se já útil).</summary>
    public DateOnly PostergaParaUtil(DateOnly d)
    {
        while (!EhDiaUtil(d)) d = d.AddDays(1);
        return d;
    }

    /// <summary>Anda <paramref name="n"/> dias úteis para trás a partir de <paramref name="d"/>
    /// (cada passo vai ao dia útil anterior); n = 0 devolve o próprio d já reduzido a dia útil.</summary>
    public DateOnly RecuaDiasUteis(DateOnly d, int n)
    {
        d = AntecipaParaUtil(d);
        for (var i = 0; i < n; i++)
        {
            d = d.AddDays(-1);
            d = AntecipaParaUtil(d);
        }
        return d;
    }

    public Prorrogacao? ProrrogacaoDe(string obrigacao, string competencia) =>
        _prorrogacoes.Where(p => p.Obrigacao == obrigacao && p.Competencia == competencia)
            .Select(p => (Prorrogacao?)p).FirstOrDefault();

    private static string? Texto(JsonElement o, string campo) =>
        o.TryGetProperty(campo, out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() : null;
}

/// <summary>Catálogo de obrigações (regra <c>obrigacoes.catalogo</c>, §18.1), já lido. Cada obrigação
/// dá uma data para a competência do caso (§18.2). Domínio puro.</summary>
public sealed class CatalogoObrigacoes
{
    public sealed record Obrigacao(string Codigo, JsonElement? Quando, Vencimento Vencimento);

    /// <summary>Regra de vencimento de uma obrigação. <c>DiaUltimoDiaUtil</c> = "ultimo_dia_util";
    /// caso contrário usa <c>Dia</c> numérico com o ajuste de dia não útil.</summary>
    public sealed record Vencimento(int MesesApos, bool DiaUltimoDiaUtil, int Dia, AjusteDiaNaoUtil Ajuste);

    public enum AjusteDiaNaoUtil { Antecipa, Posterga }

    private readonly Dictionary<string, Obrigacao> _porCodigo;

    public CatalogoObrigacoes(IEnumerable<Obrigacao> obrigacoes)
        => _porCodigo = obrigacoes.ToDictionary(o => o.Codigo, StringComparer.Ordinal);

    public static CatalogoObrigacoes DeJson(JsonElement conteudo)
    {
        var lista = new List<Obrigacao>();
        if (conteudo.TryGetProperty("obrigacoes", out var arr) && arr.ValueKind == JsonValueKind.Array)
            foreach (var o in arr.EnumerateArray())
            {
                if (o.ValueKind != JsonValueKind.Object) continue;
                var codigo = o.TryGetProperty("codigo", out var c) && c.ValueKind == JsonValueKind.String ? c.GetString() : null;
                if (codigo is null) continue;
                JsonElement? quando = o.TryGetProperty("quando", out var q) && q.ValueKind != JsonValueKind.Null ? q : null;
                if (!o.TryGetProperty("vencimento", out var v) || v.ValueKind != JsonValueKind.Object) continue;
                lista.Add(new Obrigacao(codigo, quando, LerVencimento(v)));
            }
        return new CatalogoObrigacoes(lista);
    }

    private static Vencimento LerVencimento(JsonElement v)
    {
        var mesesApos = v.TryGetProperty("meses_apos", out var m) && m.ValueKind == JsonValueKind.Number ? m.GetInt32() : 0;
        var dia = v.TryGetProperty("dia", out var d) ? d : default;
        if (dia.ValueKind == JsonValueKind.String && dia.GetString() == "ultimo_dia_util")
            return new Vencimento(mesesApos, true, 0, AjusteDiaNaoUtil.Antecipa);
        var diaNum = dia.ValueKind == JsonValueKind.Number ? dia.GetInt32() : 1;
        var ajuste = v.TryGetProperty("dia_nao_util", out var dn) && dn.ValueKind == JsonValueKind.String
            && dn.GetString() == "posterga" ? AjusteDiaNaoUtil.Posterga : AjusteDiaNaoUtil.Antecipa;
        return new Vencimento(mesesApos, false, diaNum, ajuste);
    }

    public Obrigacao? Por(string codigo) => _porCodigo.TryGetValue(codigo, out var o) ? o : null;
}

/// <summary>Regra de prazos do caso (<c>fechamento.prazos</c>, §18.1), já lida. Fixada no caso.</summary>
public sealed class RegraPrazos
{
    /// <summary>Uma cláusula de meta/ação: <c>Quando</c> ausente = sempre vale (§18.1). Vence a primeira que casa.</summary>
    public sealed record Clausula(JsonElement? Quando, int Dias);

    public sealed record ItemPrazo(
        string Tipo,
        IReadOnlyList<string> VencimentoRef,
        bool GeraMulta,
        IReadOnlyList<Clausula>? Meta,
        IReadOnlyList<Clausula>? Acao,
        string? EstadoCumprimento);

    public Guid VersaoId { get; }
    public IReadOnlyList<Clausula> MetaPadrao { get; }
    public IReadOnlyList<Clausula> AcaoPadrao { get; }
    public string EstadoCumprimentoPadrao { get; }
    private readonly Dictionary<string, ItemPrazo> _porTipo;

    public RegraPrazos(Guid versaoId, JsonElement conteudo)
    {
        VersaoId = versaoId;
        var padrao = conteudo.TryGetProperty("padrao", out var p) && p.ValueKind == JsonValueKind.Object ? p : default;
        MetaPadrao = LerClausulas(padrao, "meta", "dias_corridos");
        AcaoPadrao = LerClausulas(padrao, "acao", "dias_uteis");
        EstadoCumprimentoPadrao = padrao.ValueKind == JsonValueKind.Object
            && padrao.TryGetProperty("estado_cumprimento", out var ec) && ec.ValueKind == JsonValueKind.String
            ? ec.GetString()! : "validado";

        var itens = new Dictionary<string, ItemPrazo>(StringComparer.Ordinal);
        if (conteudo.TryGetProperty("entregaveis", out var arr) && arr.ValueKind == JsonValueKind.Array)
            foreach (var it in arr.EnumerateArray())
            {
                if (it.ValueKind != JsonValueKind.Object) continue;
                var tipo = it.TryGetProperty("tipo", out var t) && t.ValueKind == JsonValueKind.String ? t.GetString() : null;
                if (tipo is null) continue;
                var refs = new List<string>();
                if (it.TryGetProperty("vencimento_ref", out var vr) && vr.ValueKind == JsonValueKind.Array)
                    foreach (var r in vr.EnumerateArray())
                        if (r.ValueKind == JsonValueKind.String) refs.Add(r.GetString()!);
                var geraMulta = it.TryGetProperty("gera_multa", out var gm) && gm.ValueKind == JsonValueKind.True;
                var meta = it.TryGetProperty("meta", out _) ? LerClausulas(it, "meta", "dias_corridos") : null;
                var acao = it.TryGetProperty("acao", out _) ? LerClausulas(it, "acao", "dias_uteis") : null;
                var estadoCump = it.TryGetProperty("estado_cumprimento", out var iec) && iec.ValueKind == JsonValueKind.String
                    ? iec.GetString() : null;
                itens[tipo] = new ItemPrazo(tipo, refs, geraMulta, meta, acao, estadoCump);
            }
        _porTipo = itens;
    }

    public ItemPrazo? Item(string tipo) => _porTipo.TryGetValue(tipo, out var i) ? i : null;

    private static IReadOnlyList<Clausula> LerClausulas(JsonElement pai, string campo, string chaveDias)
    {
        var lista = new List<Clausula>();
        if (pai.ValueKind == JsonValueKind.Object && pai.TryGetProperty(campo, out var arr) && arr.ValueKind == JsonValueKind.Array)
            foreach (var c in arr.EnumerateArray())
            {
                if (c.ValueKind != JsonValueKind.Object) continue;
                JsonElement? quando = c.TryGetProperty("quando", out var q) && q.ValueKind != JsonValueKind.Null ? q : null;
                var dias = c.TryGetProperty(chaveDias, out var d) && d.ValueKind == JsonValueKind.Number ? d.GetInt32() : 0;
                lista.Add(new Clausula(quando, dias));
            }
        return lista;
    }
}

/// <summary>Cálculo das datas de prazo (§18.2). Função pura do domínio: recebe as regras já lidas
/// e o contexto do caso; não lê banco e não tem relógio.</summary>
public static class CalculoPrazos
{
    /// <summary>Data de uma obrigação para a competência do caso (§18.2). Nula se a obrigação não
    /// existe no catálogo ou seu <c>quando</c> não casa com o contexto.</summary>
    public static DateOnly? DataObrigacao(string codigo, Competencia competencia, Contexto ctx,
        Calendario calendario, CatalogoObrigacoes catalogo)
    {
        var o = catalogo.Por(codigo);
        if (o is null) return null;
        if (!Condicao.Casa(o.Quando, ctx)) return null;

        // 1. prorrogação com a mesma obrigacao e competencia: usa nova_data, sem ajuste.
        var pror = calendario.ProrrogacaoDe(codigo, competencia.ToString());
        if (pror is { } p) return p.NovaData;

        // 2. mês-base = competência + meses_apos.
        var baseComp = competencia.Somar(o.Vencimento.MesesApos);
        var ultimoDia = DateTime.DaysInMonth(baseComp.Ano, baseComp.Mes);

        if (o.Vencimento.DiaUltimoDiaUtil)
        {
            // 4. "ultimo_dia_util": último dia do mês-base; se não útil, volta ao dia útil anterior.
            return calendario.AntecipaParaUtil(new DateOnly(baseComp.Ano, baseComp.Mes, ultimoDia));
        }

        // 3. dia numérico: min(dia, último dia do mês); antecipa/posterga se não útil.
        var dia = Math.Min(o.Vencimento.Dia, ultimoDia);
        var data = new DateOnly(baseComp.Ano, baseComp.Mes, dia);
        return o.Vencimento.Ajuste == CatalogoObrigacoes.AjusteDiaNaoUtil.Posterga
            ? calendario.PostergaParaUtil(data)
            : calendario.AntecipaParaUtil(data);
    }

    /// <summary>Prazo do entregável de um tipo (§18.2), ou <c>null</c> quando nenhuma obrigação se
    /// aplica (o entregável não tem prazo: as três datas ficam nulas e não há linha de histórico).</summary>
    public static Prazo? Calcular(string tipo, Competencia competencia, Contexto ctx,
        Calendario calendario, CatalogoObrigacoes catalogo, RegraPrazos prazos)
    {
        var item = prazos.Item(tipo);
        if (item is null) return null; // tipo fora da lista não tem prazo

        // Vencimento = a MAIS CEDO entre as obrigações de vencimento_ref que existem e cujo quando casa.
        DateOnly? vencimento = null;
        foreach (var codigo in item.VencimentoRef)
        {
            var d = DataObrigacao(codigo, competencia, ctx, calendario, catalogo);
            if (d is { } data && (vencimento is null || data < vencimento)) vencimento = data;
        }
        if (vencimento is not { } venc) return null; // nenhuma obrigação aplicável: sem prazo

        // Meta = vencimento + dias_corridos (cláusula que casa, padrão se o item não tem); antecipa p/ útil.
        var metaClausulas = item.Meta ?? prazos.MetaPadrao;
        var diasMeta = DiasDaClausula(metaClausulas, ctx);
        var meta = calendario.AntecipaParaUtil(venc.AddDays(diasMeta));

        // Ação = a partir da meta, |dias_uteis| dias úteis para trás (0 = a própria meta).
        var acaoClausulas = item.Acao ?? prazos.AcaoPadrao;
        var diasAcao = DiasDaClausula(acaoClausulas, ctx);
        var acao = calendario.RecuaDiasUteis(meta, Math.Abs(diasAcao));

        return new Prazo(venc, meta, acao);
    }

    /// <summary>Estado de cumprimento do tipo (padrão aplicado quando o item não o sobrescreve, §18.4).</summary>
    public static string EstadoCumprimento(string tipo, RegraPrazos prazos)
        => prazos.Item(tipo)?.EstadoCumprimento ?? prazos.EstadoCumprimentoPadrao;

    private static int DiasDaClausula(IReadOnlyList<RegraPrazos.Clausula> clausulas, Contexto ctx)
    {
        foreach (var c in clausulas)
            if (Condicao.Casa(c.Quando, ctx)) return c.Dias;
        return 0;
    }
}
