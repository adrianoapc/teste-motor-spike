<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\RepositorioRegras;
use App\Dominio\Regras\Parametros;
use App\Dominio\Regras\RegraEntregaveis;
use App\Dominio\Regras\Tolerancia;

final class RegrasMemoria implements RepositorioRegras
{
    public function __construct(
        private readonly RegraEntregaveis $regra,
        private readonly Tolerancia $tolerancia = new Tolerancia('tol-1', 0, 100),
        private readonly Parametros $parametros = new Parametros('par-1', 2800),
    ) {}

    public function entregaveisEmUso(): RegraEntregaveis
    {
        return $this->regra;
    }

    public function entregaveisPorId(string $versaoId): RegraEntregaveis
    {
        return $this->regra;
    }

    public function toleranciaEmUso(): Tolerancia
    {
        return $this->tolerancia;
    }

    public function parametrosEmUso(): Parametros
    {
        return $this->parametros;
    }
}
