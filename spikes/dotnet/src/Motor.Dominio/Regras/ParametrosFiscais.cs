using System.Text.Json;
using Motor.Dominio.Conferencias;

namespace Motor.Dominio.Regras;

/// <summary>Leitura das regras fiscais em uso (§2). Valor ausente ou malformado nunca vira 0 em silêncio.</summary>
public static class ParametrosFiscais
{
    /// <summary>Tolerância (<c>fiscal.tolerancia.padrao</c>, §9.2). Obrigatória: ausente ou malformada
    /// é erro explícito (a conferência numérica não tem como seguir sem ela).</summary>
    public static Tolerancia LerTolerancia(JsonElement conteudo)
    {
        if (conteudo.ValueKind != JsonValueKind.Object)
            throw new InvalidOperationException("fiscal.tolerancia: conteúdo não é um objeto JSON");
        if (!conteudo.TryGetProperty("padrao", out var padrao) || padrao.ValueKind != JsonValueKind.Object)
            throw new InvalidOperationException("fiscal.tolerancia sem o objeto 'padrao'");
        return new Tolerancia(
            Inteiro(padrao, "ok_ate_centavos", "fiscal.tolerancia.padrao"),
            Inteiro(padrao, "alerta_ate_centavos", "fiscal.tolerancia.padrao"));
    }

    /// <summary>Percentual do pró-labore em pontos-base (<c>fiscal.parametros</c>, CF-08). Ausente ou
    /// malformado devolve <c>null</c>: o consumidor trata como operando inválido, nunca como 0.</summary>
    public static long? LerProlaboreBp(JsonElement? conteudo)
    {
        if (conteudo is not { ValueKind: JsonValueKind.Object } c || !c.TryGetProperty("prolabore_percentual_bp", out var bp)
            || bp.ValueKind != JsonValueKind.Number || !bp.TryGetInt64(out var valor))
            return null;
        return valor;
    }

    private static long Inteiro(JsonElement objeto, string campo, string caminho)
    {
        if (!objeto.TryGetProperty(campo, out var v) || v.ValueKind != JsonValueKind.Number || !v.TryGetInt64(out var n))
            throw new InvalidOperationException($"{caminho}.{campo} ausente ou não é inteiro int64");
        return n;
    }
}
