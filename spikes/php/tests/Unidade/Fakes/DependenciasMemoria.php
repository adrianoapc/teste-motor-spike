<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\DependenciaGravada;
use App\Aplicacao\Portas\RepositorioDependencia;
use App\Dominio\Fechamento\EstadoEntregavel;

final class DependenciasMemoria implements RepositorioDependencia
{
    /** @var list<array{entregavelId: string, dependeDeId: string, estadoMinimo: string}> */
    public array $arestas = [];

    public function __construct(private readonly EntregaveisMemoria $entregaveis) {}

    public function gravar(string $entregavelId, string $dependeDeId, string $estadoMinimo): void
    {
        foreach ($this->arestas as $a) {
            if ($a['entregavelId'] === $entregavelId && $a['dependeDeId'] === $dependeDeId) {
                return; // ON CONFLICT DO NOTHING
            }
        }
        $this->arestas[] = ['entregavelId' => $entregavelId, 'dependeDeId' => $dependeDeId, 'estadoMinimo' => $estadoMinimo];
    }

    public function doEntregavel(string $entregavelId): array
    {
        $saida = [];
        foreach ($this->arestas as $a) {
            if ($a['entregavelId'] === $entregavelId) {
                $saida[] = new DependenciaGravada($a['dependeDeId'], EstadoEntregavel::from($a['estadoMinimo']));
            }
        }

        return $saida;
    }

    public function dependentesDe(string $entregavelId): array
    {
        $dependentes = [];
        foreach ($this->arestas as $a) {
            if ($a['dependeDeId'] === $entregavelId) {
                $dependentes[] = $this->entregaveis->obter($a['entregavelId']);
            }
        }
        usort($dependentes, static fn ($x, $y): int => strcmp($x->tipo, $y->tipo));

        return array_map(static fn ($e): string => $e->id, $dependentes);
    }
}
