namespace Motor.Dominio.Conferencias;

public enum ResultadoConferencia
{
    Ok,
    Divergente,
    Informativo,
}

/// <summary>Tolerância das conferências numéricas (regra fiscal.tolerancia.padrao, §9.2).</summary>
public readonly record struct Tolerancia(long OkAteCentavos, long AlertaAteCentavos);

/// <summary>Classificação de uma conferência numérica pela diferença e tolerância (§9.2).</summary>
public static class Classificacao
{
    /// <summary>Classifica uma conferência numérica. Retorna o resultado e a severidade efetiva.
    /// severidadeCatalogo é a severidade do catálogo (B/A/I) do tipo da CF.</summary>
    public static (ResultadoConferencia Resultado, string Severidade) Numerica(
        long diferenca, Tolerancia tol, string severidadeCatalogo)
    {
        if (diferenca <= tol.OkAteCentavos)
            return (ResultadoConferencia.Ok, severidadeCatalogo);
        if (diferenca <= tol.AlertaAteCentavos)
            return (ResultadoConferencia.Divergente, "A");
        return (ResultadoConferencia.Divergente, severidadeCatalogo);
    }
}
