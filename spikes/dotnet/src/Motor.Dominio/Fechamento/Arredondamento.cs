namespace Motor.Dominio.Fechamento;

/// <summary>Arredondamento meio para o par (banker's rounding) só com inteiros (§9.1).
/// Nunca usa ponto flutuante.</summary>
public static class Arredondamento
{
    /// <summary>Divide <paramref name="numerador"/> por <paramref name="denominador"/> arredondando
    /// o resultado meio-para-o-par. Vetores: arred(60000000000,10000)=6000000; arred(5,2)=2;
    /// arred(7,2)=4; arred(15,10)=2; arred(25,10)=2; arred(26,10)=3.</summary>
    public static long MeioParaPar(long numerador, long denominador)
    {
        if (denominador == 0)
            throw new DivideByZeroException("denominador zero no arredondamento");
        if (denominador < 0)
        {
            numerador = -numerador;
            denominador = -denominador;
        }

        var quociente = Math.DivRem(numerador, denominador, out var resto);
        if (resto == 0)
            return quociente;

        // Trabalhamos com o resto absoluto; o sinal do quociente é tratado no fim.
        var restoAbs = Math.Abs(resto);
        var dobro = restoAbs * 2;

        if (dobro < denominador)
            return quociente; // arredonda para baixo (em magnitude)

        if (dobro > denominador)
            return numerador >= 0 ? quociente + 1 : quociente - 1; // arredonda para cima

        // exatamente no meio: vai para o par mais próximo
        var candidatoCima = numerador >= 0 ? quociente + 1 : quociente - 1;
        return (quociente % 2 == 0) ? quociente : candidatoCima;
    }
}
