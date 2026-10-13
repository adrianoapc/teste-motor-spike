namespace Motor.Dominio.Fechamento;

/// <summary>Competência mensal no formato AAAAMM. Value object imutável (§3).</summary>
public readonly record struct Competencia
{
    public int Ano { get; }
    public int Mes { get; }

    public Competencia(int ano, int mes)
    {
        if (mes < 1 || mes > 12)
            throw new ArgumentOutOfRangeException(nameof(mes), mes, "mês deve estar entre 1 e 12");
        if (ano < 1)
            throw new ArgumentOutOfRangeException(nameof(ano), ano, "ano inválido");
        Ano = ano;
        Mes = mes;
    }

    public static Competencia Analisar(string texto)
    {
        if (texto is null || texto.Length != 6 || !texto.All(char.IsDigit))
            throw new FormatException($"competência inválida: '{texto ?? "<null>"}'");
        var ano = int.Parse(texto[..4]);
        var mes = int.Parse(texto[4..]);
        return new Competencia(ano, mes);
    }

    /// <summary>Desloca a competência por N meses, virando o ano quando necessário.
    /// Ex.: 202601 + (-1) = 202512 (§7.1).</summary>
    public Competencia Somar(int meses)
    {
        var zeroBaseado = (Ano * 12) + (Mes - 1) + meses;
        var ano = Math.DivRem(zeroBaseado, 12, out var mesZero);
        if (mesZero < 0)
        {
            mesZero += 12;
            ano -= 1;
        }
        return new Competencia(ano, mesZero + 1);
    }

    /// <summary>mes_do_trimestre = ((mes - 1) % 3) + 1 (§3).</summary>
    public int MesDoTrimestre => ((Mes - 1) % 3) + 1;

    public override string ToString() => $"{Ano:D4}{Mes:D2}";
}
