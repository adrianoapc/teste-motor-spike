using System.Text.Json;
using System.Text.Json.Nodes;
using Json.Schema;

namespace Motor.Dominio.Fatos;

/// <summary>Uma violação do contrato (§6.2): o campo e a regra quebrada.</summary>
public sealed record Violacao(string Campo, string Regra);

/// <summary>Valida <c>tributo</c>, <c>valor_centavos</c> e <c>payload</c> de uma publicação contra o
/// contrato ativo do tipo (§6.2). A forma do corpo (int64, NUL, profundidade, chave duplicada) já foi
/// validada na API (§6.2 passo 1); aqui começa no passo 4. Devolve TODAS as violações.</summary>
public static class ValidadorContrato
{
    /// <summary>Valida tributo + valor + payload. Lista vazia = válido.</summary>
    public static IReadOnlyList<Violacao> Validar(ContratoTipoFato contrato, string tributo,
        long? valorCentavos, JsonElement payload)
    {
        var violacoes = new List<Violacao>();
        ValidarTributo(contrato.Qualificador, tributo, violacoes);
        ValidarValor(contrato.Valor, valorCentavos, violacoes);
        ValidarPayload(contrato.Esquema, payload, violacoes);
        return violacoes;
    }

    private static void ValidarTributo(Qualificador q, string tributo, List<Violacao> v)
    {
        if (!q.Usa)
        {
            if (tributo.Length != 0) v.Add(new Violacao("tributo", "proibido"));
            return;
        }
        if (tributo.Length == 0) { v.Add(new Violacao("tributo", "obrigatorio")); return; }
        if (q.Valores.Count > 0 && !q.Valores.Contains(tributo))
            v.Add(new Violacao("tributo", "fora_da_lista"));
    }

    private static void ValidarValor(PoliticaValor pol, long? valor, List<Violacao> v)
    {
        switch (pol)
        {
            case PoliticaValor.Obrigatorio when valor is null:
                v.Add(new Violacao("valor_centavos", "obrigatorio")); break;
            case PoliticaValor.Proibido when valor is not null:
                v.Add(new Violacao("valor_centavos", "proibido")); break;
        }
    }

    /// <summary>Valida o payload contra o JSON Schema draft 2020-12 do contrato. Mapeia as falhas para
    /// as regras de §6.2: <c>obrigatorio</c> (required ausente), <c>tipo</c> (falha de <c>type</c>),
    /// <c>esquema</c> (qualquer outra: pattern, minimum, minLength, minItems...).</summary>
    private static void ValidarPayload(JsonElement esquema, JsonElement payload, List<Violacao> v)
    {
        var schema = JsonSchema.FromText(esquema.GetRawText());
        var instancia = JsonNode.Parse(payload.GetRawText());
        var resultado = schema.Evaluate(instancia, new EvaluationOptions
        {
            OutputFormat = OutputFormat.List,
            EvaluateAs = SpecVersion.Draft202012,
        });
        if (resultado.IsValid) return;

        // Agrupa por (campo, regra) preservando a ordem de descoberta, sem duplicar.
        var vistos = new HashSet<string>(StringComparer.Ordinal);
        foreach (var detalhe in Achatar(resultado))
        {
            if (detalhe.Errors is null) continue;
            foreach (var (palavraChave, _) in detalhe.Errors)
            {
                var (campo, regra) = Mapear(palavraChave, detalhe);
                var chave = campo + "\u0000" + regra;
                if (vistos.Add(chave)) v.Add(new Violacao(campo, regra));
            }
        }
    }

    private static IEnumerable<EvaluationResults> Achatar(EvaluationResults r)
    {
        yield return r;
        foreach (var filho in r.Details)
            foreach (var neto in Achatar(filho))
                yield return neto;
    }

    /// <summary>Caminho da instância (ex.: <c>/inss_centavos</c>) + palavra-chave que falhou ->
    /// (campo, regra) de §6.2.</summary>
    private static (string Campo, string Regra) Mapear(string palavraChave, EvaluationResults detalhe)
    {
        var instancia = detalhe.InstanceLocation.ToString(); // "" na raiz, "/campo", "/parcelas/0/socio"
        if (palavraChave == "required")
        {
            // A propriedade required ausente vem no texto do erro; cai no caminho do pai + "/<prop>".
            var ausente = PropriedadeRequeridaAusente(detalhe);
            var baseCampo = CaminhoPayload(instancia);
            var campo = ausente is null ? baseCampo : (baseCampo.Length == 0 ? "payload." + ausente : baseCampo + "." + ausente);
            return (campo, "obrigatorio");
        }
        var regra = palavraChave == "type" ? "tipo" : "esquema";
        return (CaminhoPayload(instancia), regra);
    }

    /// <summary>Extrai o nome da propriedade required ausente da mensagem do erro, quando a biblioteca a traz.</summary>
    private static string? PropriedadeRequeridaAusente(EvaluationResults detalhe)
    {
        if (detalhe.Errors is null || !detalhe.Errors.TryGetValue("required", out var msg) || msg is null)
            return null;
        // Mensagem padrão: "Required properties [\"competencia_impressa\"] were not present"
        var i = msg.IndexOf('"');
        if (i < 0) return null;
        var j = msg.IndexOf('"', i + 1);
        return j > i ? msg.Substring(i + 1, j - i - 1) : null;
    }

    /// <summary>Converte o JSON Pointer da instância (<c>/parcelas/0/socio</c>) para o caminho de §6.2
    /// (<c>payload.parcelas[0].socio</c>).</summary>
    private static string CaminhoPayload(string ponteiro)
    {
        if (ponteiro.Length == 0) return "payload";
        var partes = ponteiro.Split('/', StringSplitOptions.RemoveEmptyEntries);
        var sb = new System.Text.StringBuilder("payload");
        foreach (var p in partes)
        {
            if (int.TryParse(p, out _)) sb.Append('[').Append(p).Append(']');
            else sb.Append('.').Append(p);
        }
        return sb.ToString();
    }
}
