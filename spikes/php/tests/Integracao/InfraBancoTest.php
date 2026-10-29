<?php

declare(strict_types=1);

namespace Tests\Integracao;

use App\Aplicacao\Portas\RepositorioCatalogo;
use App\Aplicacao\Portas\RepositorioRegras;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InfraBancoTest extends TestCase
{
    public function test_le_a_versao_em_uso_de_fechamento_entregaveis_v2(): void
    {
        /** @var RepositorioRegras $regras */
        $regras = $this->app->make(RepositorioRegras::class);
        $regra = $regras->entregaveisEmUso();

        // A base M2 tem a v3 de fechamento.entregaveis em uso (a v1/v2 aposentadas). Nunca fixar versao=1 (§2).
        $this->assertSame(3, $regra->versao);
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
        $this->assertSame(10, $regras->toleranciaEmUso()->alertaAteCentavos);
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

    public function test_leituras_de_regra_catalogo_e_dependencia_sao_memorizadas_so_dentro_da_requisicao(): void
    {
        /** @var RepositorioRegras $regras */
        $regras = $this->app->make(RepositorioRegras::class);
        /** @var RepositorioCatalogo $catalogo */
        $catalogo = $this->app->make(RepositorioCatalogo::class);
        $this->assertSame($regras, $this->app->make(RepositorioRegras::class), 'scoped: mesma instância na requisição');

        DB::enableQueryLog();
        $regras->entregaveisEmUso();
        $regras->entregaveisEmUso();
        $regras->entregaveisPorId($regras->entregaveisEmUso()->versaoId);
        $regras->toleranciaEmUso();
        $regras->toleranciaEmUso();
        $catalogo->entregavelTipo('E01');
        $catalogo->entregavelTipo('E01');
        $catalogo->conferenciaTipo('CF-01');
        $catalogo->conferenciaTipo('CF-01');
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        // regra de entregáveis em uso (1), tolerância em uso (1), tipo de entregável (1), tipo de conferência (1).
        $this->assertSame(4, $consultas);

        // Nova requisição: instâncias novas, nada de estado herdado.
        $this->app->forgetScopedInstances();
        $this->assertNotSame($regras, $this->app->make(RepositorioRegras::class));
    }

    public function test_unidade_de_trabalho_roda_numa_transacao(): void
    {
        $uow = $this->app->make(UnidadeDeTrabalho::class);
        $this->assertInstanceOf(UnidadeDeTrabalho::class, $uow);

        $resultado = $uow->transacao(static fn (): int => 42);
        $this->assertSame(42, $resultado);
    }
}
