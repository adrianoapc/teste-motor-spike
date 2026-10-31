<?php

declare(strict_types=1);

namespace App\Dominio\Fatos;

/**
 * Converte um instante RFC 3339 em microssegundos inteiros desde a época, com a MESMA
 * precisão que o PostgreSQL guarda num timestamptz: 6 casas decimais.
 *
 * §6.4 (v5.1, Adriano 30/10): o motor **trunca** a fração de observado_em para 6 dígitos
 * (descarta o 7º em diante, SEM arredondar) antes de comparar e antes de gravar, e grava
 * esse valor já truncado. Assim o Postgres sempre recebe 6 dígitos e nunca arredonda (o
 * arredondamento dele é meio-para-o-par, e '.9999996' iria para o segundo seguinte).
 * Exemplos: '.1234569' -> '.123456'; '.9999996' -> '.999999'; '.12' -> '.120000'.
 * Antes da v5.1 o PHP arredondava como o Postgres; o Python e o .NET já truncavam.
 *
 * Trabalha só com inteiros (nenhum float), para não perder precisão.
 */
final class Instante
{
    /**
     * Microssegundos desde a época Unix, já truncados à precisão do timestamptz.
     * Aceita tanto o date-time RFC 3339 que chega na requisição (já validado por
     * FatosController::dataHoraValida) quanto o texto que o PostgreSQL devolve ao reler a
     * vigente ('2026-10-01 12:00:00.123456+00'). Por isso NÃO assume um formato de fuso: só
     * destaca a fração (.ddd...) e deixa o DateTimeImmutable interpretar base+fuso.
     */
    public static function microssegundos(string $instante): int
    {
        [$base, $fracaoStr, $fuso] = self::partir($instante);
        $segundos = self::segundos($base.$fuso);
        $micro = self::fracaoEmMicro($fracaoStr);

        return $segundos * 1_000_000 + $micro;
    }

    /**
     * Devolve o MESMO instante com a fração truncada para 6 dígitos (§6.4, v5.1), para
     * gravar já truncado. Base e fuso ficam intactos; a fração passa a ter exatamente 6
     * dígitos quando havia alguma, e some quando não havia (o Postgres completa com zeros).
     * Exemplos: '...00.1234569Z' -> '...00.123456Z'; '...00.12Z' -> '...00.120000Z'.
     */
    public static function truncar(string $instante): string
    {
        [$base, $fracaoStr, $fuso] = self::partir($instante);
        if ($fracaoStr === '') {
            return $instante;
        }
        $seis = substr(str_pad($fracaoStr, 6, '0'), 0, 6);

        return $base.'.'.$seis.$fuso;
    }

    /**
     * Separa um instante em [data-hora-sem-fração, dígitos-da-fração, fuso]. O fuso é 'Z',
     * '±hh:mm', '±hhmm', '±hh', um espaço + offset (forma que o Postgres devolve) ou ''.
     * A fração é '' quando não havia. Entrada não reconhecida volta como [instante, '', ''].
     *
     * @return array{string, string, string}
     */
    private static function partir(string $instante): array
    {
        if (preg_match('/^(.*?)(?:\.(\d+))?([Zz]|[+\-]\d{2}(?::?\d{2})?| [+\-]\d{2}(?::?\d{2})?)?$/', $instante, $m) !== 1) {
            return [$instante, '', ''];
        }

        return [$m[1], $m[2] ?? '', $m[3] ?? ''];
    }

    /** Segundos inteiros desde a época (sem fração) a partir de um RFC 3339 com fuso. */
    private static function segundos(string $rfc3339SemFracao): int
    {
        return (int) (new \DateTimeImmutable($rfc3339SemFracao))->format('U');
    }

    /**
     * Converte uma string de dígitos de fração em microssegundos (0..999999),
     * TRUNCANDO o excedente (7+ dígitos), sem arredondar (§6.4, v5.1).
     */
    private static function fracaoEmMicro(string $digitos): int
    {
        if ($digitos === '') {
            return 0;
        }

        // Até 6 dígitos: completa com zeros à direita. Acima de 6: fica só com os 6 primeiros.
        if (strlen($digitos) <= 6) {
            return (int) str_pad($digitos, 6, '0');
        }

        return (int) substr($digitos, 0, 6);
    }
}
