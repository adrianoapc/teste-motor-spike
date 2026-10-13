namespace Motor.Dominio.Fechamento;

/// <summary>Os 10 estados do entregável (§4). A ordem define "estado ≥ X";
/// estados fora do caminho (divergente, invalidado) têm ordem nula.</summary>
public enum EstadoEntregavel
{
    AguardandoInsumo,
    Pronto,
    Processado,
    Validado,
    Liberado,
    Disponibilizado,
    Pago,
    Encerrado,
    Divergente,
    Invalidado,
}

public static class Estados
{
    /// <summary>Nome em snake_case usado no banco e no contrato.</summary>
    public static string Nome(this EstadoEntregavel e) => e switch
    {
        EstadoEntregavel.AguardandoInsumo => "aguardando_insumo",
        EstadoEntregavel.Pronto => "pronto",
        EstadoEntregavel.Processado => "processado",
        EstadoEntregavel.Validado => "validado",
        EstadoEntregavel.Liberado => "liberado",
        EstadoEntregavel.Disponibilizado => "disponibilizado",
        EstadoEntregavel.Pago => "pago",
        EstadoEntregavel.Encerrado => "encerrado",
        EstadoEntregavel.Divergente => "divergente",
        EstadoEntregavel.Invalidado => "invalidado",
        _ => throw new ArgumentOutOfRangeException(nameof(e), e, null),
    };

    public static EstadoEntregavel Analisar(string nome) => nome switch
    {
        "aguardando_insumo" => EstadoEntregavel.AguardandoInsumo,
        "pronto" => EstadoEntregavel.Pronto,
        "processado" => EstadoEntregavel.Processado,
        "validado" => EstadoEntregavel.Validado,
        "liberado" => EstadoEntregavel.Liberado,
        "disponibilizado" => EstadoEntregavel.Disponibilizado,
        "pago" => EstadoEntregavel.Pago,
        "encerrado" => EstadoEntregavel.Encerrado,
        "divergente" => EstadoEntregavel.Divergente,
        "invalidado" => EstadoEntregavel.Invalidado,
        _ => throw new ArgumentOutOfRangeException(nameof(nome), nome, "estado desconhecido"),
    };

    /// <summary>Ordem no caminho principal; null para divergente/invalidado (§4).</summary>
    public static int? Ordem(this EstadoEntregavel e) => e switch
    {
        EstadoEntregavel.AguardandoInsumo => 1,
        EstadoEntregavel.Pronto => 2,
        EstadoEntregavel.Processado => 3,
        EstadoEntregavel.Validado => 4,
        EstadoEntregavel.Liberado => 5,
        EstadoEntregavel.Disponibilizado => 6,
        EstadoEntregavel.Pago => 7,
        EstadoEntregavel.Encerrado => 8,
        _ => null,
    };

    /// <summary>"estado ≥ minimo" comparando ordem. Estado de ordem nula nunca é ≥ a nada (§4).</summary>
    public static bool PeloMenos(this EstadoEntregavel estado, EstadoEntregavel minimo)
    {
        var o = estado.Ordem();
        var m = minimo.Ordem();
        if (o is null || m is null)
            return false;
        return o.Value >= m.Value;
    }
}
