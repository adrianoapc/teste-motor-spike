<?php

declare(strict_types=1);

namespace Tests\Unidade;

use App\Dominio\Fatos\Instante;
use PHPUnit\Framework\TestCase;

/**
 * Instante tem de arredondar a fração em 6 casas EXATAMENTE como o Postgres grava um
 * timestamptz (meio-para-o-par), senão o §6.4 classifica errado uma republicação idêntica
 * com 7+ dígitos de fração (obsoleto em vez de conflito_fonte).
 */
final class InstanteTest extends TestCase
{
    /**
     * Vetores confirmados contra o Postgres 16:
     *   .1234565 -> .123456   (5 exato, 6 já par)
     *   .1234566 -> .123457   (>5)
     *   .1234567 -> .123457   (>5)
     *   .1234575 -> .123458   (5 exato, 7 ímpar sobe para o par 8)
     */
    public function test_arredonda_fracao_como_postgres_meio_para_o_par(): void
    {
        $base = '2026-10-01T12:00:00';
        $z = $base.'.000000Z';
        $zero = Instante::microssegundos($z);

        $this->assertSame($zero + 123456, Instante::microssegundos($base.'.1234565Z'));
        $this->assertSame($zero + 123457, Instante::microssegundos($base.'.1234566Z'));
        $this->assertSame($zero + 123457, Instante::microssegundos($base.'.1234567Z'));
        $this->assertSame($zero + 123458, Instante::microssegundos($base.'.1234575Z'));
    }

    public function test_sete_digitos_truncados_por_php_igualam_a_vigente_arredondada_pelo_banco(): void
    {
        // PHP sozinho (DateTimeImmutable) truncaria .1234567 em .123456; o banco grava .123457.
        // O helper tem de dar o MESMO valor que o banco para os dois lados da comparação.
        $queChega = Instante::microssegundos('2026-10-01T12:00:00.1234567Z');
        $vigenteDoBanco = Instante::microssegundos('2026-10-01T12:00:00.123457Z');

        $this->assertSame($vigenteDoBanco, $queChega, 'mesmo instante após arredondamento');
    }

    public function test_fracao_curta_completa_com_zeros_sem_arredondar(): void
    {
        $base = '2026-10-01T12:00:00';
        $zero = Instante::microssegundos($base.'.000000Z');

        $this->assertSame($zero + 100000, Instante::microssegundos($base.'.1Z'));
        $this->assertSame($zero + 123000, Instante::microssegundos($base.'.123Z'));
        $this->assertSame($zero + 123456, Instante::microssegundos($base.'.123456Z'));
    }

    public function test_sem_fracao_e_fuso_deslocado(): void
    {
        $semFracao = Instante::microssegundos('2026-10-01T12:00:00Z');
        $comZeros = Instante::microssegundos('2026-10-01T12:00:00.000000Z');
        $this->assertSame($comZeros, $semFracao);

        // +01:00 é uma hora antes em UTC; -01:00 é uma hora depois.
        $utc = Instante::microssegundos('2026-10-01T12:00:00Z');
        $mais = Instante::microssegundos('2026-10-01T12:00:00+01:00');
        $menos = Instante::microssegundos('2026-10-01T12:00:00-01:00');
        $this->assertSame($utc - 3_600_000_000, $mais);
        $this->assertSame($utc + 3_600_000_000, $menos);
    }

    public function test_999999_com_arredondamento_para_cima_carrega_o_segundo(): void
    {
        // .9999995 -> .000000 do segundo seguinte (carry), como o Postgres.
        $base = '2026-10-01T12:00:00';
        $umSegundoDepois = Instante::microssegundos('2026-10-01T12:00:01.000000Z');
        $this->assertSame($umSegundoDepois, Instante::microssegundos($base.'.9999996Z'));
    }
}
