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

    public function abrir(AbrirCompetenciasComando $comando): ResultadoAbertura
    {
        $competencia = new Competencia($comando->competencia);
        $criados = [];
        $existentes = [];

        foreach ($comando->empresas as $empresa) {
            /** @var array{criado: bool, caso: CasoDaEmpresa} $resultado */
            $resultado = $this->uow->transacao(function () use ($competencia, $comando, $empresa): array {
                // Lock nível 1 (titular): pedidos concorrentes da mesma chave esperam, relêem e reportam em `existentes`.
                $this->casos->travarTitular($empresa->titularId);
                $existente = $this->casos->porTitularCompetencia($empresa->titularId, $competencia);
                if ($existente !== null) {
                    return ['criado' => false, 'caso' => new CasoDaEmpresa($empresa->titularId, $existente->id)];
                }

                $caso = $this->casos->criar($competencia, $empresa, $this->regras->entregaveisEmUso()->versaoId);
                $this->criarEntregaveis($caso, $comando->ator);
                $this->eventos->gravar('competencia.aberta', $caso->id, null, ['caso_id' => $caso->id]);
                $this->avaliar->avaliar($caso);

                return ['criado' => true, 'caso' => new CasoDaEmpresa($empresa->titularId, $caso->id)];
            });

            if ($resultado['criado']) {
                $criados[] = $resultado['caso'];
            } else {
                $existentes[] = $resultado['caso'];
            }
        }

        return new ResultadoAbertura($criados, $existentes);
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
