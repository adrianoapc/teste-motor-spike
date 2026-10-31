<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\RepositorioRegras;
use App\Dominio\Prazos\CalculoDePrazos;
use App\Dominio\Prazos\Calendario;
use App\Dominio\Prazos\CatalogoObrigacoes;
use App\Dominio\Prazos\RegraPrazos;
use App\Dominio\Prazos\RegrasDePrazo;
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

    public function prazosEmUso(): RegrasDePrazo
    {
        return $this->regrasDePrazoVazia();
    }

    public function prazosComPrazosFixos(string $prazosVersaoId): RegrasDePrazo
    {
        return $this->regrasDePrazoVazia();
    }

    /**
     * Prazos vazios (nenhum tipo tem prazo): a abertura roda sem gravar nenhuma data. Os
     * testes de prazo de verdade são o domínio puro (CalculoDePrazosTest) e o banco (verificador).
     */
    private function regrasDePrazoVazia(): RegrasDePrazo
    {
        $calculo = new CalculoDePrazos(
            new Calendario([], []),
            CatalogoObrigacoes::de(['obrigacoes' => []]),
            RegraPrazos::de(['padrao' => [], 'entregaveis' => []]),
        );

        return new RegrasDePrazo('cal-0', 1, 'cat-0', 'prz-0', $calculo);
    }
}
