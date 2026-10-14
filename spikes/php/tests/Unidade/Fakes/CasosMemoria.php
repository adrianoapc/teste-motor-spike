<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\RepositorioCaso;
use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\Competencia;
use App\Dominio\Fechamento\Empresa;

final class CasosMemoria implements RepositorioCaso
{
    /** @var array<string, Caso> */
    public array $casos = [];

    /** @var list<string> titulares travados, na ordem */
    public array $travas = [];

    public function porTitularCompetencia(string $titularId, Competencia $competencia): ?Caso
    {
        foreach ($this->casos as $caso) {
            if ($caso->titularId === $titularId && $caso->competencia->igual($competencia)) {
                return $caso;
            }
        }

        return null;
    }

    public function travarTitular(string $titularId): void
    {
        $this->travas[] = $titularId;
    }

    public function criar(Competencia $competencia, Empresa $empresa, string $regraEntregaveisVersaoId): Caso
    {
        $caso = new Caso('caso-'.(count($this->casos) + 1), $empresa->titularId, $competencia, $empresa, $empresa->carteira, $regraEntregaveisVersaoId, 'aberto');
        $this->casos[$caso->id] = $caso;

        return $caso;
    }

    public function encerrar(string $casoId): void
    {
        $c = $this->casos[$casoId];
        $this->casos[$casoId] = new Caso($c->id, $c->titularId, $c->competencia, $c->empresa, $c->carteira, $c->regraEntregaveisVersaoId, 'encerrado');
    }

    public function abertosDoTitular(string $titularId): array
    {
        $abertos = array_values(array_filter(
            $this->casos,
            static fn (Caso $c): bool => $c->titularId === $titularId && $c->estado === 'aberto',
        ));
        usort($abertos, static fn (Caso $a, Caso $b): int => strcmp($a->competencia->valor, $b->competencia->valor));

        return $abertos;
    }

    public function porId(string $casoId): ?Caso
    {
        return $this->casos[$casoId] ?? null;
    }
}
