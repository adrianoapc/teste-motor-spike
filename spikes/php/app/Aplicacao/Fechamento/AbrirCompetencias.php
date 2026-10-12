<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioCatalogo;
use App\Aplicacao\Portas\RepositorioDependencia;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioRegras;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\Competencia;
use App\Dominio\Fechamento\Condicao;
use App\Dominio\Fechamento\EstadoEntregavel;

/**
 * AbrirCompetencias (§5). Uma transação por empresa. Idempotente por
 * (titular_id, competencia).
 */
final readonly class AbrirCompetencias
{
    public function __construct(
        private UnidadeDeTrabalho $uow,
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private RepositorioDependencia $dependencias,
        private RepositorioEvento $eventos,
        private RepositorioRegras $regras,
        private RepositorioCatalogo $catalogo,
        private Transicionador $transicionador,
        private AvaliarCaso $avaliar,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $empresas
     * @return array{criados: list<array{titular_id: string, caso_id: string}>, existentes: list<array{titular_id: string, caso_id: string}>}
     */
    public function abrir(string $competenciaValor, string $ator, array $empresas): array
    {
        $competencia = new Competencia($competenciaValor);
        $criados = [];
        $existentes = [];

        foreach ($empresas as $empresa) {
            $titularId = is_string($empresa['titular_id'] ?? null) ? $empresa['titular_id'] : '';

            /** @var array{tipo: string, caso_id: string, titular_id: string} $resultado */
            $resultado = $this->uow->transacao(function () use ($competencia, $ator, $empresa, $titularId): array {
                $existente = $this->casos->porTitularCompetencia($titularId, $competencia);
                if ($existente !== null) {
                    return ['tipo' => 'existente', 'caso_id' => $existente->id, 'titular_id' => $titularId];
                }

                $caso = $this->criarCaso($competencia, $empresa, $titularId);
                $this->criarEntregaveis($caso, $ator);
                $this->eventos->gravar('competencia.aberta', $caso->id, null, ['caso_id' => $caso->id]);
                $this->avaliar->avaliar($caso);

                return ['tipo' => 'criado', 'caso_id' => $caso->id, 'titular_id' => $titularId];
            });

            $linha = ['titular_id' => $resultado['titular_id'], 'caso_id' => $resultado['caso_id']];
            if ($resultado['tipo'] === 'criado') {
                $criados[] = $linha;
            } else {
                $existentes[] = $linha;
            }
        }

        return ['criados' => $criados, 'existentes' => $existentes];
    }

    /**
     * @param  array<string, mixed>  $empresa
     */
    private function criarCaso(Competencia $competencia, array $empresa, string $titularId): Caso
    {
        $regra = $this->regras->entregaveisEmUso();
        $carteira = is_string($empresa['carteira'] ?? null) ? $empresa['carteira'] : '';

        return $this->casos->criar($titularId, $competencia, $empresa, $carteira, $regra->versaoId);
    }

    private function criarEntregaveis(Caso $caso, string $ator): void
    {
        $regra = $this->regras->entregaveisPorId($caso->regraEntregaveisVersaoId);
        $ctx = $caso->contexto();

        // 1. Cria os entregáveis cujo `quando` casa.
        $criados = [];
        foreach ($regra->itens as $item) {
            if (! Condicao::casa($item->quando, $ctx)) {
                continue;
            }
            $area = $this->catalogo->entregavelTipo($item->tipo)->area;
            $entregavel = $this->entregaveis->criar($caso->id, $item->tipo, EstadoEntregavel::AguardandoInsumo, $item->executor, $area);
            $this->transicionador->criar($entregavel, $ator, $caso->regraEntregaveisVersaoId);
            $criados[$item->tipo] = $entregavel->id;
        }

        // 2. Grava as dependências cujo `quando` casa e cujo alvo existe no caso (§5.4).
        foreach ($regra->itens as $item) {
            if (! isset($criados[$item->tipo])) {
                continue;
            }
            foreach ($item->dependeDe as $dep) {
                if (! Condicao::casa($dep->quando, $ctx)) {
                    continue;
                }
                if (! isset($criados[$dep->tipo])) {
                    continue; // dependência para entregável inexistente no caso é ignorada
                }
                $this->dependencias->gravar($criados[$item->tipo], $criados[$dep->tipo], $dep->estadoMinimo);
            }
        }
    }
}
