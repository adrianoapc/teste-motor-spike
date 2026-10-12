<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

use InvalidArgumentException;

/**
 * Competência AAAAMM. Imutável. Opera só com inteiros.
 */
final readonly class Competencia
{
    public int $ano;

    public int $mes;

    public function __construct(public string $valor)
    {
        if (preg_match('/^[0-9]{4}(0[1-9]|1[0-2])$/', $valor) !== 1) {
            throw new InvalidArgumentException("competência inválida: {$valor}");
        }
        $this->ano = (int) substr($valor, 0, 4);
        $this->mes = (int) substr($valor, 4, 2);
    }

    /**
     * Soma (ou subtrai) meses, com virada de ano. -1 sobre 202601 dá 202512.
     */
    public function somar(int $meses): self
    {
        $total = ($this->ano * 12 + ($this->mes - 1)) + $meses;
        $ano = intdiv($total, 12);
        $mes = ($total % 12) + 1;

        return new self(sprintf('%04d%02d', $ano, $mes));
    }

    /**
     * Mês dentro do trimestre: ((mes - 1) % 3) + 1 → 1, 2 ou 3.
     */
    public function mesDoTrimestre(): int
    {
        return (($this->mes - 1) % 3) + 1;
    }

    public function igual(self $outra): bool
    {
        return $this->valor === $outra->valor;
    }
}
