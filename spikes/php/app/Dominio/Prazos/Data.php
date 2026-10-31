<?php

declare(strict_types=1);

namespace App\Dominio\Prazos;

use InvalidArgumentException;

/**
 * Data civil (AAAA-MM-DD), imutável e pura. Sem fuso, sem hora, sem relógio: o motor é sem
 * relógio (§18). Toda a aritmética de dias e meses passa por aqui, para o cálculo de prazos
 * (§18.2) ser o mesmo nas três spikes, até o dia.
 *
 * Usa DateTimeImmutable em UTC só como motor de aritmética de calendário (meses com tamanhos
 * diferentes, bissexto); nunca expõe hora nem fuso.
 */
final class Data
{
    private function __construct(private readonly \DateTimeImmutable $d) {}

    public static function de(string $iso): self
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $iso) !== 1) {
            throw new InvalidArgumentException("data inválida: {$iso}");
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new \DateTimeZone('UTC'));
        if ($dt === false || $dt->format('Y-m-d') !== $iso) {
            throw new InvalidArgumentException("data inválida: {$iso}");
        }

        return new self($dt);
    }

    /** Primeiro dia do mês AAAAMM (competência). */
    public static function primeiroDiaDoMes(int $ano, int $mes): self
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $ano, $mes), new \DateTimeZone('UTC'));
        assert($dt !== false);

        return new self($dt);
    }

    /** Dia $dia do mês AAAAMM, limitado ao último dia do mês (min(dia, último)). */
    public static function diaDoMesLimitado(int $ano, int $mes, int $dia): self
    {
        $ultimo = self::primeiroDiaDoMes($ano, $mes)->ultimoDiaDoMes()->dia();

        return self::de(sprintf('%04d-%02d-%02d', $ano, $mes, min($dia, $ultimo)));
    }

    public function ultimoDiaDoMes(): self
    {
        return new self($this->d->modify('last day of this month'));
    }

    public function maisDias(int $n): self
    {
        return new self($this->d->modify(sprintf('%+d days', $n)));
    }

    /** ISO-8601: 1 = segunda ... 6 = sábado, 7 = domingo. */
    public function diaDaSemana(): int
    {
        return (int) $this->d->format('N');
    }

    public function dia(): int
    {
        return (int) $this->d->format('j');
    }

    public function iso(): string
    {
        return $this->d->format('Y-m-d');
    }

    public function igual(self $outra): bool
    {
        return $this->iso() === $outra->iso();
    }

    public function menorQue(self $outra): bool
    {
        return $this->iso() < $outra->iso();
    }
}
