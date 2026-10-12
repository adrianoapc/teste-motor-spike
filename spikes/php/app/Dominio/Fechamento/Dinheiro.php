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
