using System.Text.Json;
using Motor.Dominio.Fatos;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Dominio.Conferencias;

public readonly record struct ComparacaoTextual(bool Igual, JsonElement Diferenca);

/// <summary>Resultado de calcular uma conferência: valores + rastro. Exatamente um "modo" é usado.</summary>
public sealed record CalculoConferencia(
    long? Esperado,
    long? Obtido,
    IReadOnlyList<FatoUsado> FatosUsados,
    string? FatoAusente = null,
    ComparacaoTextual? Textual = null,
    bool? ExistenciaOk = null,
    string? OperandoInvalido = null);

/// <summary>O que as fórmulas precisam além dos fatos: competência, item da regra, contexto do caso,
/// tributo da guia de entrada do item de protocolo (lido da regra pinada, §10.1), o percentual do
/// pró-labore (<c>null</c> = ausente ou malformado em <c>fiscal.parametros</c>) e a folha resolvida
/// da entrada do E08 (§9.1 v4: INSS, FGTS e IRRF vêm da folha declarada como entrada, que a regra v3
/// aponta para a competência anterior; <c>null</c> = entrada não presente).</summary>
public sealed record ContextoCalculo(
    Competencia Competencia, ItemEntregavel Item, Contexto Contexto, string? TributoGuia, long? ProlaboreBp,
    FatoVigente? FolhaConferencia = null);

/// <summary>Fórmulas das conferências do spike (§9.1). Puras: recebem os fatos vigentes já lidos.</summary>
public static class CalculadoraConferencias
{
    public static CalculoConferencia Calcular(string cf, ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        return cf switch
        {
            "CF-01" => Cf01(fatos),
            "CF-04" => Cf04(fatos),
            "CF-05" => Cf05(c, fatos),
            "CF-06" => Cf06(c, fatos),
            "CF-07" => Cf07(c, fatos),
            "CF-08" => Cf08(c, fatos),
            "CF-09" => Cf09(c, fatos),
            "CF-12" => Cf12(c, fatos),
            _ => throw new InvalidOperationException($"CF não implementada no spike: {cf}"),
        };
    }

    private static FatoVigente? Buscar(IReadOnlyList<FatoVigente> fatos, string tipo, string tributo = "")
        => fatos.FirstOrDefault(f => f.Tipo == tipo && f.Tributo == tributo);

    private static FatoUsado U(FatoVigente f) => new(f.Id, f.Versao);

    // CF-01 (E01): esperado = faturamento_mes.valor; obtido = notas_oneflow.valor
    private static CalculoConferencia Cf01(IReadOnlyList<FatoVigente> fatos)
    {
        var fat = Buscar(fatos, "faturamento_mes");
        var notas = Buscar(fatos, "notas_oneflow");
        if (fat is null) return Ausente("faturamento_mes", "");
        if (notas is null) return Ausente("notas_oneflow", "", fat);
        return new CalculoConferencia(fat.ValorCentavos, notas.ValorCentavos, new[] { U(fat), U(notas) });
    }

    // CF-04 (E03 SN): esperado = arred(faturamento_mes.valor * aliquota_bp / 10000); obtido = apurado[DAS].valor
    // ("DAS" é literal na fórmula normativa de §9.1; só roda no SN pelo quando da regra.)
    private static CalculoConferencia Cf04(IReadOnlyList<FatoVigente> fatos)
    {
        var fat = Buscar(fatos, "faturamento_mes");
        var aliq = Buscar(fatos, "aliquota_mes");
        var apurado = Buscar(fatos, "apurado", "DAS");
        if (fat is null) return Ausente("faturamento_mes", "");
        if (aliq is null) return Ausente("aliquota_mes", "", fat);
        if (apurado is null) return Ausente("apurado", "DAS", fat, aliq);
        long bp = 0;
        if (aliq.Payload.TryGetProperty("aliquota_bp", out var b) && !LerInt64(b, out bp))
            return Invalido("aliquota_mes.aliquota_bp", fat, aliq, apurado);
        if (!ProdutoArredondado(fat.ValorCentavos ?? 0, bp, out var esperado))
            return Invalido("faturamento_mes*aliquota_bp fora de int64", fat, aliq, apurado);
        return new CalculoConferencia(esperado, apurado.ValorCentavos, new[] { U(fat), U(aliq), U(apurado) });
    }

    // CF-05 (E11): esperado = apurado[T].valor, T = tributo da guia de entrada do E11 (regra); obtido = guia[T].valor
    private static CalculoConferencia Cf05(ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        if (c.TributoGuia is not { } tributo) return Invalido(SemGuiaNaRegra);
        var apurado = Buscar(fatos, "apurado", tributo);
        var guia = Buscar(fatos, RegraEntregaveis.TipoGuia, tributo);
        if (apurado is null) return Ausente("apurado", tributo);
        if (guia is null) return Ausente(RegraEntregaveis.TipoGuia, tributo, apurado);
        return new CalculoConferencia(apurado.ValorCentavos, guia.ValorCentavos, new[] { U(apurado), U(guia) });
    }

    // CF-06 (E11): ok se guia.payload.competencia_impressa = competência do caso
    private static CalculoConferencia Cf06(ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        if (c.TributoGuia is not { } tributo) return Invalido(SemGuiaNaRegra);
        var guia = Buscar(fatos, RegraEntregaveis.TipoGuia, tributo);
        if (guia is null) return Ausente(RegraEntregaveis.TipoGuia, tributo);
        var impressa = guia.Payload.TryGetProperty("competencia_impressa", out var ci) && ci.ValueKind == JsonValueKind.String
            ? ci.GetString()! : "";
        var esperada = c.Competencia.ToString();
        var igual = string.Equals(impressa, esperada, StringComparison.Ordinal);
        // Serializa via JsonSerializer: 'impressa' pode conter control chars (ex.: OCR com \n),
        // que o escape manual (só aspas/barra) deixaria passar e quebraria JsonDocument.Parse.
        var dif = JsonSerializer.SerializeToElement(new { esperada, impressa });
        return new CalculoConferencia(null, null, new[] { U(guia) }, Textual: new ComparacaoTextual(igual, dif));
    }

    // CF-07 (E04):
    //  SN:  esperado = apurado[DAS].valor; obtido = soma(divisao_socios.parcelas[].valor_centavos)
    //  LP (v4, §9.1): tributo a tributo. Tributos = apurados do caso ∪ tributos das parcelas.
    //       esperado[T] = apurado[T].valor; obtido[T] = soma das parcelas com tributo = T (nenhuma = 0).
    //       Diferença da conferência = a MAIOR entre os tributos; grava o par do tributo de maior
    //       diferença (empate: menor tributo em ordem de code point). Parcela sem tributo = operando
    //       inválido; tributo sem apurado no caso = dado ausente (ambos classe S, §9.2).
    private static CalculoConferencia Cf07(ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        if (c.TributoGuia is not { } tributoGuia) return Invalido(SemGuiaNaRegra);
        var div = Buscar(fatos, "divisao_socios");
        var ehLp = c.Contexto.TentarObter("regime", out var reg)
            && reg.ValueKind == JsonValueKind.String && reg.GetString() == "LP";

        if (!ehLp)
        {
            // SN: agregado contra apurado[DAS] (o tributo da guia do SN).
            var apuradoSn = Buscar(fatos, "apurado", tributoGuia);
            if (apuradoSn is null) return Ausente("apurado", tributoGuia);
            if (div is null) return Ausente("divisao_socios", "", apuradoSn);
            if (!SomarParcelas(div, p => true, out var somaSn, out var invalido))
                return Invalido(invalido!, apuradoSn, div);
            return new CalculoConferencia(apuradoSn.ValorCentavos, somaSn, new[] { U(apuradoSn), U(div) });
        }

        // LP: tributo a tributo.
        if (div is null) return Ausente("divisao_socios", "");
        if (!div.Payload.TryGetProperty("parcelas", out var parcelas) || parcelas.ValueKind != JsonValueKind.Array)
            return Invalido("divisao_socios.parcelas não é array", div);

        // Tributos vindos das parcelas (ordenados por code point); parcela sem tributo = operando inválido.
        var tributosParcela = new SortedSet<string>(StringComparer.Ordinal);
        foreach (var p in parcelas.EnumerateArray())
        {
            if (p.ValueKind != JsonValueKind.Object) return Invalido("divisao_socios.parcelas[] não é objeto", div);
            if (!p.TryGetProperty("tributo", out var t) || t.ValueKind != JsonValueKind.String
                || t.GetString() is not { Length: > 0 } nome)
                return Invalido("divisao_socios.parcelas[] sem tributo", div);
            tributosParcela.Add(nome);
        }

        // Apurados do caso (fato 'apurado' com tributo não vazio).
        var apurados = fatos.Where(f => f.Tipo == "apurado" && f.Tributo.Length > 0)
            .ToDictionary(f => f.Tributo, f => f, StringComparer.Ordinal);

        // União dos tributos, ordenada por code point (ordem determinística para empate).
        var tributos = new SortedSet<string>(StringComparer.Ordinal);
        foreach (var t in apurados.Keys) tributos.Add(t);
        foreach (var t in tributosParcela) tributos.Add(t);
        if (tributos.Count == 0) return Ausente("apurado", "");

        var usados = new List<FatoUsado> { U(div) };
        long? melhorEsperado = null, melhorObtido = null;
        Int128 maiorDif = -1;
        foreach (var t in tributos) // SortedSet enumera em ordem de code point: empate pega o menor tributo
        {
            if (!apurados.TryGetValue(t, out var apuradoT))
                return Ausente("apurado", t, div); // tributo sem apurado no caso = dado ausente (classe S)
            usados.Add(U(apuradoT));
            if (!SomarParcelas(div, p => ParcelaDoTributo(p, t), out var obtidoT, out var invalido))
                return new CalculoConferencia(null, null, usados, OperandoInvalido: invalido);
            var esperadoT = apuradoT.ValorCentavos ?? 0;
            var dif = Int128.Abs((Int128)esperadoT - obtidoT);
            if (dif > maiorDif)
            {
                maiorDif = dif;
                melhorEsperado = apuradoT.ValorCentavos;
                melhorObtido = obtidoT;
            }
        }
        return new CalculoConferencia(melhorEsperado, melhorObtido, usados);
    }

    private static bool ParcelaDoTributo(JsonElement p, string t)
        => p.TryGetProperty("tributo", out var pt) && pt.ValueKind == JsonValueKind.String && pt.GetString() == t;

    /// <summary>Soma <c>valor_centavos</c> das parcelas que casam o filtro. Parcela não-objeto ou
    /// <c>valor_centavos</c> não-int64 (quando presente e não-null) => operando inválido.</summary>
    private static bool SomarParcelas(FatoVigente div, Func<JsonElement, bool> filtro, out long soma, out string? invalido)
    {
        soma = 0;
        invalido = null;
        if (!div.Payload.TryGetProperty("parcelas", out var parcelas) || parcelas.ValueKind != JsonValueKind.Array)
        {
            invalido = "divisao_socios.parcelas não é array";
            return false;
        }
        Int128 acc = 0;
        foreach (var p in parcelas.EnumerateArray())
        {
            if (p.ValueKind != JsonValueKind.Object) { invalido = "divisao_socios.parcelas[] não é objeto"; return false; }
            if (!filtro(p)) continue;
            if (p.TryGetProperty("valor_centavos", out var v) && v.ValueKind != JsonValueKind.Null)
            {
                if (!LerInt64(v, out var n)) { invalido = "divisao_socios.parcelas[].valor_centavos"; return false; }
                acc += n;
            }
        }
        if (acc > long.MaxValue || acc < long.MinValue) { invalido = "soma das parcelas fora de int64"; return false; }
        soma = (long)acc;
        return true;
    }

    // CF-08 (E07 SN): esperado = arred(faturamento_mes.valor * prolabore_percentual_bp / 10000); obtido = prolabore.valor
    private static CalculoConferencia Cf08(ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        var fat = Buscar(fatos, "faturamento_mes");
        var prolabore = Buscar(fatos, "prolabore");
        if (fat is null) return Ausente("faturamento_mes", "");
        if (prolabore is null) return Ausente("prolabore", "", fat);
        if (c.ProlaboreBp is not { } bp)
            return Invalido("fiscal.parametros.prolabore_percentual_bp ausente ou malformado", fat, prolabore);
        if (!ProdutoArredondado(fat.ValorCentavos ?? 0, bp, out var esperado))
            return Invalido("faturamento_mes*prolabore_bp fora de int64", fat, prolabore);
        return new CalculoConferencia(esperado, prolabore.ValorCentavos, new[] { U(fat), U(prolabore) });
    }

    // CF-09 (E08, v4 §9.1): três pares. INSS/FGTS vêm da folha do PRÓPRIO caso (competência do mês,
    // nos fatos vigentes do caso); IRRF vem da folha da COMPETÊNCIA ANTERIOR (entrada do E08 com
    // competencia_relativa -1, resolvida em c.FolhaConferencia). obtido = guia[INSS|FGTS|IRRF].valor do mês.
    // Diferença da conferência = a MAIOR das três; grava o par de maior diferença (empate: INSS, FGTS, IRRF).
    private static CalculoConferencia Cf09(ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        var folhaMes = Buscar(fatos, "folha");            // folha do próprio mês (INSS/FGTS)
        var folhaAnterior = c.FolhaConferencia;           // folha do mês anterior (IRRF), §9.1 v4
        var guiaInss = Buscar(fatos, RegraEntregaveis.TipoGuia, "INSS");
        var guiaFgts = Buscar(fatos, RegraEntregaveis.TipoGuia, "FGTS");
        var guiaIrrf = Buscar(fatos, RegraEntregaveis.TipoGuia, "IRRF");
        if (folhaMes is null) return Ausente("folha", "");
        if (guiaInss is null) return Ausente(RegraEntregaveis.TipoGuia, "INSS", folhaMes);
        if (guiaFgts is null) return Ausente(RegraEntregaveis.TipoGuia, "FGTS", folhaMes, guiaInss);
        if (guiaIrrf is null) return Ausente(RegraEntregaveis.TipoGuia, "IRRF", folhaMes, guiaInss, guiaFgts);
        if (folhaAnterior is null) return Ausente("folha", "", folhaMes, guiaInss, guiaFgts, guiaIrrf); // folha M-1 ainda não publicada
        // INSS/FGTS: ausente/null => 0 (comportamento anterior, mantém C1–C10). Presente e inválido => operando inválido.
        if (!Campo(folhaMes, "inss_centavos", out var inssEsp)) return Invalido("folha.inss_centavos", folhaMes, folhaAnterior, guiaInss, guiaFgts, guiaIrrf);
        if (!Campo(folhaMes, "fgts_centavos", out var fgtsEsp)) return Invalido("folha.fgts_centavos", folhaMes, folhaAnterior, guiaInss, guiaFgts, guiaIrrf);
        // IRRF (v4 §16): folha do mês anterior sem irrf_centavos = dado ausente, classe S (C13 T018).
        if (!folhaAnterior.Payload.TryGetProperty("irrf_centavos", out var irrfEl) || irrfEl.ValueKind == JsonValueKind.Null)
            return Ausente("folha.irrf_centavos", "", folhaMes, folhaAnterior, guiaInss, guiaFgts, guiaIrrf);
        if (!LerInt64(irrfEl, out var irrfEsp)) return Invalido("folha.irrf_centavos", folhaMes, folhaAnterior, guiaInss, guiaFgts, guiaIrrf);

        var usados = new[] { U(folhaMes), U(folhaAnterior), U(guiaInss), U(guiaFgts), U(guiaIrrf) };
        // Ordem de empate: INSS, FGTS, IRRF — a primeira com a maior diferença estrita vence.
        var pares = new (long esp, long? obt)[]
        {
            (inssEsp, guiaInss.ValorCentavos),
            (fgtsEsp, guiaFgts.ValorCentavos),
            (irrfEsp, guiaIrrf.ValorCentavos),
        };
        long? melhorEsp = pares[0].esp;
        long? melhorObt = pares[0].obt;
        var maiorDif = Int128.Abs((Int128)pares[0].esp - (pares[0].obt ?? 0));
        for (var i = 1; i < pares.Length; i++)
        {
            var dif = Int128.Abs((Int128)pares[i].esp - (pares[i].obt ?? 0));
            if (dif > maiorDif) { maiorDif = dif; melhorEsp = pares[i].esp; melhorObt = pares[i].obt; }
        }
        return new CalculoConferencia(melhorEsp, melhorObt, usados);
    }

    // CF-12 (E10): ok se existe recibo_obrigacao com o tributo exigido pela entrada do item (regra)
    private static CalculoConferencia Cf12(ContextoCalculo c, IReadOnlyList<FatoVigente> fatos)
    {
        var tributo = c.Item.EntradasResolvidas(c.Contexto, c.Competencia)
            .Where(e => e.Tipo == RegraEntregaveis.TipoRecibo).Select(e => e.Tributo).FirstOrDefault();
        if (tributo is null) return Invalido("item sem entrada recibo_obrigacao na regra");
        var recibo = Buscar(fatos, RegraEntregaveis.TipoRecibo, tributo);
        if (recibo is null)
            return new CalculoConferencia(null, null, Array.Empty<FatoUsado>(), ExistenciaOk: false);
        return new CalculoConferencia(null, null, new[] { U(recibo) }, ExistenciaOk: true);
    }

    private const string SemGuiaNaRegra = "regra sem entrada guia no item de protocolo";

    /// <summary>Lê int64 sem lançar: número inteiro dentro da faixa => true.</summary>
    private static bool LerInt64(JsonElement v, out long n)
    {
        n = 0;
        return v.ValueKind == JsonValueKind.Number && v.TryGetInt64(out n);
    }

    /// <summary>Campo ausente/null => 0 (comportamento anterior); presente e inválido => false.</summary>
    private static bool Campo(FatoVigente f, string nome, out long n)
    {
        n = 0;
        if (!f.Payload.TryGetProperty(nome, out var v) || v.ValueKind == JsonValueKind.Null) return true;
        return LerInt64(v, out n);
    }

    /// <summary>arred(valor * bp / 10000) com produto em Int128; false se o resultado não couber em int64.</summary>
    private static bool ProdutoArredondado(long valor, long bp, out long resultado)
    {
        var r = Arredondamento.MeioParaPar((Int128)valor * bp, (Int128)10000);
        resultado = 0;
        if (r > long.MaxValue || r < long.MinValue) return false;
        resultado = (long)r;
        return true;
    }

    // Resultados sem valor ainda carregam os fatos já lidos: entrada_hash passa a variar por versão
    // dos fatos e InserirOuReaproveitarAsync não reaproveita conferência obsoleta.
    private static CalculoConferencia Invalido(string operando, params FatoVigente[] lidos)
        => new(null, null, lidos.Select(U).ToArray(), OperandoInvalido: operando);

    private static CalculoConferencia Ausente(string tipo, string tributo, params FatoVigente[] lidos)
        => new(null, null, lidos.Select(U).ToArray(), FatoAusente: $"{tipo}[{tributo}]");
}
