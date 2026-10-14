<?php

declare(strict_types=1);

namespace Tests\Integracao;

use App\Aplicacao\Portas\RepositorioCatalogo;
use App\Aplicacao\Portas\RepositorioRegras;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use Tests\TestCase;

final class InfraBancoTest extends TestCase
{
    public function test_le_a_versao_em_uso_de_fechamento_entregaveis_v2(): void
    {
        /** @var RepositorioRegras $regras */
        $regras = $this->app->make(RepositorioRegras::class);
        $regra = $regras->entregaveisEmUso();

        // A V006 publicou a v2 (a v1 está aposentada). Nunca fixar versao=1 (§2).
        $this->assertSame(2, $regra->versao);
        $e11 = $regra->item('E11');
        $this->assertNotNull($e11);
        $this->assertNotNull($e11->posValidacao);
        $this->assertNotNull($e11->acompanhamento);
        // E12 depende do E11 em 'disponibilizado' (v3), não em 'pago'.
        $e12 = $regra->item('E12');
        $this->assertNotNull($e12);
        $this->assertTrue($e12->encerraCaso);
    }

    public function test_le_a_tolerancia_e_os_parametros_em_uso(): void
    {
        /** @var RepositorioRegras $regras */
        $regras = $this->app->make(RepositorioRegras::class);

        $this->assertSame(0, $regras->toleranciaEmUso()->okAteCentavos);
        $this->assertSame(100, $regras->toleranciaEmUso()->alertaAteCentavos);
        $this->assertSame(2800, $regras->parametrosEmUso()->prolaborePercentualBp);
    }

    public function test_le_o_catalogo(): void
    {
        /** @var RepositorioCatalogo $catalogo */
        $catalogo = $this->app->make(RepositorioCatalogo::class);

        $this->assertSame('fiscal', $catalogo->entregavelTipo('E01')->area);
        $this->assertSame('insumos.completos', $catalogo->entregavelTipo('E01')->eventoPublicado);
        $this->assertSame('B', $catalogo->conferenciaTipo('CF-01')->severidade);
        $this->assertSame('E03', $catalogo->conferenciaTipo('CF-04')->entregavelTipo);
    }

    public function test_unidade_de_trabalho_roda_numa_transacao(): void
    {
        $uow = $this->app->make(UnidadeDeTrabalho::class);
        $this->assertInstanceOf(UnidadeDeTrabalho::class, $uow);

        $resultado = $uow->transacao(static fn (): int => 42);
        $this->assertSame(42, $resultado);
    }
}
