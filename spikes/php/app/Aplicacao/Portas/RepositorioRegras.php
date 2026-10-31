<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

use App\Dominio\Prazos\RegrasDePrazo;
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

    /**
     * As três regras de prazo (§18.1) em uso: calendário e catálogo (não fixados) + a versão
     * em uso de 'fechamento.prazos', que a abertura fixa no caso (regra_prazos_versao_id).
     */
    public function prazosEmUso(): RegrasDePrazo;

    /**
     * Como prazosEmUso, mas com a versão de 'fechamento.prazos' FIXADA no caso ($prazosVersaoId),
     * combinada com o calendário e o catálogo EM USO (§18.3 AplicarCalendario).
     */
    public function prazosComPrazosFixos(string $prazosVersaoId): RegrasDePrazo;
}
