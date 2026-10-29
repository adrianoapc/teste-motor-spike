<?php

declare(strict_types=1);

namespace App\Dominio\Fatos;

/**
 * Converte um instante RFC 3339 em microssegundos inteiros desde a época, com a MESMA
 * precisão que o PostgreSQL grava num timestamptz: 6 casas decimais, arredondando a parte
 * excedente (7+ dígitos) por meio-para-o-par (§tech: "meio para o par, só com inteiros").
 *
 * Isso é necessário porque o §6.4 compara observado_em como instante entre o fato que chega
 * e a vigente lida do banco. PHP (DateTimeImmutable) TRUNCA a fração em 6 dígitos; o Postgres
 * ARREDONDA. Sem normalizar, '…00.1234567Z' viraria .123456 em PHP e .123457 no banco, e uma
 * republicação idêntica cairia em obsoleto em vez de conflito_fonte.
 *
 * Trabalha só com inteiros (nenhum float), para não perder precisão.
 */
final class Instante
{
    /**
     * Microssegundos desde a época Unix, já arredondados à precisão do timestamptz.
     * Aceita tanto o date-time RFC 3339 que chega na requisição (já validado por
     * FatosController::dataHoraValida) quanto o texto que o PostgreSQL devolve ao reler a
     * vigente ('2026-10-01 12:00:00.123457+00'). Por isso NÃO assume um formato de fuso: só
     * destaca a fração (.ddd...) e deixa o DateTimeImmutable interpretar base+fuso.
     */
    public static function microssegundos(string $instante): int
    {
        // Destaca a fração: um ponto seguido de dígitos, logo antes do fuso ou do fim.
        // O que fica (base + fuso, sem a fração) o DateTimeImmutable interpreta em qualquer
        // forma de fuso: 'Z', '+00', '+0000', '+00:00', '-03:00' ou ausente.
        $fracaoStr = '';
        $semFracao = preg_replace_callback(
            '/\.(\d+)(?=[Zz+\-]|$)/',
            static function (array $m) use (&$fracaoStr): string {
                $fracaoStr = $m[1];

                return '';
            },
            $instante,
            1,
        ) ?? $instante;

        $segundos = self::segundos($semFracao);
        $micro = self::fracaoEmMicro($fracaoStr);

        return $segundos * 1_000_000 + $micro;
    }

    /** Segundos inteiros desde a época (sem fração) a partir de um RFC 3339 com fuso. */
    private static function segundos(string $rfc3339SemFracao): int
    {
        return (int) (new \DateTimeImmutable($rfc3339SemFracao))->format('U');
    }

    /**
     * Converte uma string de dígitos de fração em microssegundos (0..999999),
     * arredondando o excedente (7+ dígitos) por meio-para-o-par, como o Postgres.
     */
    private static function fracaoEmMicro(string $digitos): int
    {
        if ($digitos === '') {
            return 0;
        }

        // Até 6 dígitos: completa com zeros à direita, sem arredondar.
        if (strlen($digitos) <= 6) {
            return (int) str_pad($digitos, 6, '0');
        }

        $micro = (int) substr($digitos, 0, 6);       // os 6 primeiros dígitos
        $resto = substr($digitos, 6);                // dígitos que decidem o arredondamento
        $primeiroResto = (int) $resto[0];

        if ($primeiroResto < 5) {
            return $micro;
        }
        if ($primeiroResto > 5) {
            return self::incrementar($micro);
        }

        // Primeiro dígito do resto é 5: meio-para-o-par, a menos que haja dígito não-zero depois.
        $temCaudaNaoZero = ltrim(substr($resto, 1), '0') !== '';
        if ($temCaudaNaoZero) {
            return self::incrementar($micro);
        }

        // Exatamente .5: arredonda para o par.
        return $micro % 2 === 0 ? $micro : self::incrementar($micro);
    }

    /** Incrementa os microssegundos; 999999 + 1 transborda para 1_000_000 (um segundo a mais). */
    private static function incrementar(int $micro): int
    {
        return $micro + 1;
    }
}
