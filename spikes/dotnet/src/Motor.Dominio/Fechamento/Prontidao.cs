namespace Motor.Dominio.Fechamento;

/// <summary>Uma dependência já lida: estado atual do alvo (nulo se o alvo não existe) e estado mínimo exigido.</summary>
public readonly record struct DependenciaLida(EstadoEntregavel? EstadoAtual, EstadoEntregavel EstadoMinimo);

/// <summary>Regra de prontidão (§7.2) como função pura: o entregável fica pronto se e somente se
/// toda dependência está em estado ≥ mínimo E toda entrada resolvida está presente.</summary>
public static class Prontidao
{
    public static bool DependenciasSatisfeitas(IEnumerable<DependenciaLida> dependencias)
        => dependencias.All(d => d.EstadoAtual is { } atual && atual.PeloMenos(d.EstadoMinimo));

    public static bool Satisfeita(IEnumerable<DependenciaLida> dependencias, IEnumerable<bool> entradasPresentes)
        => DependenciasSatisfeitas(dependencias) && entradasPresentes.All(presente => presente);
}
