<?php

declare(strict_types=1);

namespace Tests\Unidade;

use App\Dominio\Fatos\Instante;
use PHPUnit\Framework\TestCase;

/**
 * §6.4 (v5.1): Instante TRUNCA a fração em 6 dígitos (descarta o 7º em diante, sem
 * arredondar) e grava já truncado, para que o Postgres receba sempre 6 dígitos e nunca
 * arredonde (meio-para-o-par). Antes da v5.1 o PHP arredondava como o Postgres, e o C22
 * (republicação de mesmo instante, fonte igual) caía em obsoleto em vez de conflito_fonte.
 */
final class InstanteTest extends TestCase
{
    /**
     * Vetores de truncamento (§6.4): os 6 primeiros dígitos, o resto descartado.
     *   .1234565 -> .123456
     *   .1234566 -> .123456   (NÃO sobe: trunca)
     *   .1234567 -> .123456
     *   .1234575 -> .123457
     */
    public function test_trunca_fracao_em_seis_digitos_sem_arredondar(): void
    {
        $base = '2026-10-01T12:00:00';
        $zero = Instante::microssegundos($base.'.000000Z');

        $this->assertSame($zero + 123456, Instante::microssegundos($base.'.1234565Z'));
        $this->assertSame($zero + 123456, Instante::microssegundos($base.'.1234566Z'));
        $this->assertSame($zero + 123456, Instante::microssegundos($base.'.1234567Z'));
        $this->assertSame($zero + 123457, Instante::microssegundos($base.'.1234575Z'));
    }

    public function test_sete_digitos_truncados_igualam_a_vigente_truncada_pelo_banco(): void
    {
        // O que chega com 7 dígitos (.1234567) e a vigente gravada já truncada (.123456)
        // têm de medir o MESMO instante, para a republicação idêntica dar conflito_fonte.
        $queChega = Instante::microssegundos('2026-10-01T12:00:00.1234567Z');
        $vigenteDoBanco = Instante::microssegundos('2026-10-01T12:00:00.123456Z');

        $this->assertSame($vigenteDoBanco, $queChega, 'mesmo instante após truncamento');
    }

    public function test_fracao_curta_completa_com_zeros(): void
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

    public function test_truncamento_nao_carrega_o_segundo(): void
    {
        // Truncar NÃO arredonda: .9999996 -> .999999, sem virar o segundo seguinte.
        $base = '2026-10-01T12:00:00';
        $zero = Instante::microssegundos($base.'.000000Z');
        $this->assertSame($zero + 999999, Instante::microssegundos($base.'.9999996Z'));
    }

    /**
     * truncar() devolve o MESMO instante com a fração em 6 dígitos (para gravar já truncado):
     *   .1234569Z   -> .123456Z
     *   .9999996Z   -> .999999Z
     *   .12Z        -> .120000Z
     *   (sem fração) -> intacto
     */
    public function test_truncar_devolve_string_com_seis_digitos(): void
    {
        $this->assertSame('2026-10-01T12:00:00.123456Z', Instante::truncar('2026-10-01T12:00:00.1234569Z'));
        $this->assertSame('2026-10-01T12:00:00.999999Z', Instante::truncar('2026-10-01T12:00:00.9999996Z'));
        $this->assertSame('2026-10-01T12:00:00.120000Z', Instante::truncar('2026-10-01T12:00:00.12Z'));
        $this->assertSame('2026-10-01T12:00:00Z', Instante::truncar('2026-10-01T12:00:00Z'));
        // Fuso ±hh:mm preservado.
        $this->assertSame('2026-10-01T12:00:00.123456-03:00', Instante::truncar('2026-10-01T12:00:00.1234569-03:00'));
    }
}
