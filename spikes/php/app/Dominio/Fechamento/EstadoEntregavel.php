<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

/**
 * Estados do entregável (§4). `ordem` define "estado ≥ X" no caminho principal;
 * estados fora do caminho (divergente, invalidado) e o aposentado (pago) têm
 * ordem nula e nunca são ≥ a nada.
 */
enum EstadoEntregavel: string
{
    case AguardandoInsumo = 'aguardando_insumo';
    case Pronto = 'pronto';
    case Processado = 'processado';
    case Validado = 'validado';
    case Liberado = 'liberado';
    case Disponibilizado = 'disponibilizado';
    case Encerrado = 'encerrado';
    case Divergente = 'divergente';
    case Invalidado = 'invalidado';

    /** Ordem no caminho principal; null fora dele. 'pago' é aposentado (v3) e não existe aqui. */
    public function ordem(): ?int
    {
        return match ($this) {
            self::AguardandoInsumo => 1,
            self::Pronto => 2,
            self::Processado => 3,
            self::Validado => 4,
            self::Liberado => 5,
            self::Disponibilizado => 6,
            self::Encerrado => 8,
            self::Divergente, self::Invalidado => null,
        };
    }

    /**
     * "este estado ≥ outro" por ordem. Qualquer lado com ordem nula → false.
     */
    public function maiorOuIgual(self $outro): bool
    {
        $a = $this->ordem();
        $b = $outro->ordem();

        return $a !== null && $b !== null && $a >= $b;
    }
}
