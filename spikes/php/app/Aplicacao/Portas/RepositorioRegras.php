<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

use App\Dominio\Regras\Parametros;
use App\Dominio\Regras\RegraEntregaveis;
use App\Dominio\Regras\Tolerancia;

interface RepositorioRegras
{
    /** Versão em uso de 'fechamento.entregaveis' (status provisoria/ativa). */
    public function entregaveisEmUso(): RegraEntregaveis;

    /** Versão de 'fechamento.entregaveis' por id (a versão guardada no caso). */
    public function entregaveisPorId(string $versaoId): RegraEntregaveis;

    /** Versão em uso de 'fiscal.tolerancia'. */
    public function toleranciaEmUso(): Tolerancia;

    /** Versão em uso de 'fiscal.parametros'. */
    public function parametrosEmUso(): Parametros;
}
