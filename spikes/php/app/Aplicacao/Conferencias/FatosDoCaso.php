<?php

declare(strict_types=1);

namespace App\Aplicacao\Conferencias;

use App\Dominio\Fatos\Fato;

/**
 * Índice dos fatos vigentes de um caso, para as fórmulas das conferências (§9.1)
 * lerem "apurado[DAS]", "faturamento_mes", etc.
 */
final readonly class FatosDoCaso
{
    /** @var array<string, Fato> chave "tipo|tributo" → fato vigente */
    private array $porChave;

    /**
     * @param  list<Fato>  $fatos
     */
    public function __construct(array $fatos)
    {
        $mapa = [];
        foreach ($fatos as $fato) {
            $mapa[$fato->tipo.'|'.$fato->tributo] = $fato;
        }
        $this->porChave = $mapa;
    }

    public function de(string $tipo, string $tributo = ''): ?Fato
    {
        return $this->porChave[$tipo.'|'.$tributo] ?? null;
    }

    /**
     * Tributos (não vazios) presentes nos fatos vigentes de um tipo (CF-07 LP, §9.1:
     * "tributos = apurados do caso ∪ tributos das parcelas").
     *
     * @return list<string>
     */
    public function tributosDe(string $tipo): array
    {
        $tributos = [];
        foreach ($this->porChave as $chave => $fato) {
            if ($fato->tipo === $tipo && $fato->tributo !== '') {
                $tributos[] = $fato->tributo;
            }
        }

        return $tributos;
    }
}
