using System.Text.Json;

namespace Motor.Dominio.Regras;

/// <summary>Avaliação da condição <c>quando</c> (§3). A condição é uma LISTA de objetos:
/// verdadeira se ALGUM objeto casa (OU); um objeto casa se TODOS os seus pares campo:valor
/// são iguais no contexto (E). Lista vazia ou nula = sempre verdadeira.</summary>
public static class Condicao
{
    public static bool Casa(JsonElement? quando, Contexto ctx)
    {
        if (quando is null)
            return true;
        var lista = quando.Value;
        if (lista.ValueKind != JsonValueKind.Array)
            return true;
        var vazia = true;
        foreach (var obj in lista.EnumerateArray())
        {
            vazia = false;
            if (ObjetoCasa(obj, ctx))
                return true;
        }
        return vazia; // lista vazia = sempre verdadeira
    }

    private static bool ObjetoCasa(JsonElement obj, Contexto ctx)
    {
        if (obj.ValueKind != JsonValueKind.Object)
            return false;
        foreach (var par in obj.EnumerateObject())
        {
            if (!ctx.TentarObter(par.Name, out var valorCtx))
                return false; // campo ausente no contexto não casa (par exige igualdade)
            if (!JsonIguais(par.Value, valorCtx))
                return false;
        }
        return true;
    }

    private static bool JsonIguais(JsonElement a, JsonElement b)
    {
        if (a.ValueKind != b.ValueKind)
        {
            // trata true/false como ValueKinds distintos — só casam se iguais
            return false;
        }
        return a.ValueKind switch
        {
            JsonValueKind.String => string.Equals(a.GetString(), b.GetString(), StringComparison.Ordinal),
            JsonValueKind.Number => a.GetRawText() == b.GetRawText(),
            JsonValueKind.True => true,
            JsonValueKind.False => true,
            JsonValueKind.Null => true,
            _ => a.GetRawText() == b.GetRawText(),
        };
    }
}
