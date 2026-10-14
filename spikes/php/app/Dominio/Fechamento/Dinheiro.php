<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

use InvalidArgumentException;

/**
 * Aritmética de dinheiro em centavos (int). Nunca ponto flutuante.
 */
final class Dinheiro
{
    /**
     * Divisão inteira com arredondamento meio-para-o-par (banker's rounding),
     * só com inteiros. Usada nas conferências (§9.1).
     *
     * Vetores: arred(60000000000, 10000) = 6000000; arred(5, 2) = 2;
     * arred(7, 2) = 4; arred(15, 10) = 2; arred(25, 10) = 2; arred(26, 10) = 3.
     */
    /**
     * arred(a × b ÷ den) meio-para-o-par, sem passar por float e sem estourar int64 no produto.
     * Devolve null só se o RESULTADO não cabe em int64. `den` entre 2 e 3.037.000.499.
     */
    public static function multiplicarDividirArredMeioPar(int $a, int $b, int $den): ?int
    {
        if ($den < 2 || $den > 3037000499) {
            throw new InvalidArgumentException('denominador fora da faixa');
        }
        if ($a === 0 || $b === 0) {
            return 0;
        }
        $negativo = ($a < 0) !== ($b < 0);
        // Magnitudes sem abs() (PHP_INT_MIN): quociente e resto da divisão truncada, sinais invertidos.
        $qa = self::magnitude(intdiv($a, $den));
        $ra = self::magnitude($a % $den);
        $qb = self::magnitude(intdiv($b, $den));
        $rb = self::magnitude($b % $den);

        // |a|·|b| ÷ den = qa·qb·den + qa·rb + ra·qb + (ra·rb) ÷ den   (ra·rb < den², cabe em int64)
        $parcelas = [self::mulSegura(self::mulSegura($qa, $qb), $den), self::mulSegura($qa, $rb), self::mulSegura($ra, $qb)];
        $t = $ra * $rb;
        $parcelas[] = intdiv($t, $den);

        // Acumula já no sinal do resultado: assim -2^63 (PHP_INT_MIN, magnitude 2^63) é representável.
        $s = $negativo ? -1 : 1;
        $q = 0;
        foreach ($parcelas as $p) {
            if ($p === null) {
                return null;
            }
            $q = self::somaSegura($q, $s * $p);
            if ($q === null) {
                return null;
            }
        }
        $dobro = ($t % $den) * 2;
        if ($dobro > $den || ($dobro === $den && $q % 2 !== 0)) {
            return self::somaSegura($q, $s);
        }

        return $q;
    }

    /**
     * |a − b| EXATO, sem float. Cabendo em int64 devolve int; acima de PHP_INT_MAX (máx. 2^64 − 1)
     * devolve a string decimal exata (uma diferença assim é, por definição, maior que qualquer
     * tolerância int64, logo é divergência numérica válida — não dado ausente).
     */
    public static function diferencaExata(int $a, int $b): int|string
    {
        if ($a < $b) {
            [$a, $b] = [$b, $a];
        }
        // a >= b: só estoura com a > 0 e b < 0 (então |a-b| = a + |b|).
        if ($b < 0 && $a > PHP_INT_MAX + $b) {
            return self::somaDecimal((string) $a, ltrim((string) $b, '-'));
        }

        return $a - $b;
    }

    /** Compara duas diferenças não negativas vindas de diferencaExata(): -1, 0 ou 1. */
    public static function compararDiferencas(int|string $x, int|string $y): int
    {
        $sx = (string) $x;
        $sy = (string) $y;
        if (strlen($sx) !== strlen($sy)) {
            return strlen($sx) <=> strlen($sy);
        }

        return strcmp($sx, $sy) <=> 0;
    }

    /** Soma de dois decimais não negativos em string (sem zeros à esquerda). */
    private static function somaDecimal(string $x, string $y): string
    {
        $i = strlen($x) - 1;
        $j = strlen($y) - 1;
        $carry = 0;
        $out = '';
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $d = $carry + ($i >= 0 ? (int) $x[$i--] : 0) + ($j >= 0 ? (int) $y[$j--] : 0);
            $out = ($d % 10).$out;
            $carry = intdiv($d, 10);
        }

        return $out;
    }

    private static function magnitude(int $n): int
    {
        return $n < 0 ? -$n : $n;
    }

    private static function mulSegura(?int $x, int $y): ?int
    {
        if ($x === null) {
            return null;
        }
        if ($x === 0 || $y === 0) {
            return 0;
        }

        return $x > intdiv(PHP_INT_MAX, $y) ? null : $x * $y;
    }

    private static function somaSegura(int $x, int $y): ?int
    {
        if (($y > 0 && $x > PHP_INT_MAX - $y) || ($y < 0 && $x < PHP_INT_MIN - $y)) {
            return null;
        }

        return $x + $y;
    }

    public static function arredMeioPar(int $numerador, int $denominador): int
    {
        if ($denominador === 0) {
            throw new InvalidArgumentException('denominador zero');
        }
        if ($denominador < 0) {
            $numerador = -$numerador;
            $denominador = -$denominador;
        }

        $quociente = intdiv($numerador, $denominador);
        $resto = $numerador - $quociente * $denominador; // mesmo sinal lida abaixo

        if ($resto === 0) {
            return $quociente;
        }

        // Trabalha com o valor absoluto do resto e corrige o sinal no fim.
        $negativo = $numerador < 0;
        $restoAbs = abs($resto);
        $dobro = $restoAbs * 2;

        $arredondaParaLonge = false;
        if ($dobro > $denominador) {
            $arredondaParaLonge = true;
        } elseif ($dobro === $denominador) {
            // Exatamente no meio: vai para o par mais próximo.
            $baseAbs = $negativo ? -$quociente : $quociente;
            $arredondaParaLonge = ($baseAbs % 2) !== 0;
        }

        if (! $arredondaParaLonge) {
            return $quociente;
        }

        return $negativo ? $quociente - 1 : $quociente + 1;
    }
}
