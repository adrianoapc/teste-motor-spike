<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioDependencia;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioExcecao;
use App\Aplicacao\Portas\RepositorioTarefa;
use App\Dominio\Fechamento\Entregavel;
use App\Dominio\Fechamento\EstadoEntregavel;

/**
 * Invalidação por nova versão de fato já usado (§11). Roda dentro da transação
 * do comando, antes da reavaliação (§11.4).
 */
final readonly class Invalidador
{
    public function __construct(
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private RepositorioDependencia $dependencias,
        private RepositorioTarefa $tarefas,
        private RepositorioExcecao $excecoes,
        private RepositorioEvento $eventos,
        private Transicionador $transicionador,
    ) {}

    /**
     * @param  string  $fatoAnteriorId  versão anterior do fato (a que foi substituída)
     * @param  string  $fatoNovoId  versão nova (causa a invalidação)
     * @param  ?string  $excluirEntregavelId  entregável a excluir das sementes (§8 item 4)
     */
    public function invalidar(string $fatoAnteriorId, string $fatoNovoId, ?string $excluirEntregavelId = null): void
    {
        // Sementes: entregáveis que têm a versão anterior do fato (qualquer papel). Roda sob o lock do titular
        // (nível 1, tomado pelo comando): nenhum outro escritor do titular pode estar ligando fatos a entregáveis
        // sem commit, então a consulta enxerga todos os vínculos. O fato e seus casos são sempre do mesmo titular.
        $sementes = $this->entregaveis->comFato($fatoAnteriorId);

        // Agrupa por caso.
        /** @var array<string, list<Entregavel>> $porCaso */
        $porCaso = [];
        foreach ($sementes as $e) {
            if ($excluirEntregavelId !== null && $e->id === $excluirEntregavelId) {
                continue;
            }
            $porCaso[$e->casoId][] = $e;
        }

        // Ordem determinística por caso (com o trava por tipo abaixo) evita deadlock entre invalidações concorrentes.
        ksort($porCaso, SORT_STRING);
        foreach ($porCaso as $casoId => $sementesDoCaso) {
            $this->invalidarCaso($casoId, $sementesDoCaso, $fatoNovoId);
        }
    }

    /**
     * @param  list<Entregavel>  $sementes
     */
    private function invalidarCaso(string $casoId, array $sementes, string $fatoNovoId): void
    {
        $caso = $this->casos->porId($casoId);
        if ($caso === null) {
            return;
        }

        // Caso encerrado (§11.3): nenhuma transição; abre uma exceção competencia_encerrada.
        if ($caso->encerrado()) {
            $excId = $this->excecoes->abrirUnica($casoId, null, 'competencia_encerrada', 'O', $caso->carteira);
            if ($excId !== null) {
                $this->eventos->gravar('excecao.aberta', $casoId, null, ['excecao_id' => $excId]);
            }

            return;
        }

        // Trava todos os entregáveis do caso (FOR UPDATE, ordem de tipo) antes de ler estados e transicionar:
        // invalidações concorrentes do mesmo caso se serializam em vez de falhar no compare-and-set (409).
        $this->entregaveis->doCasoParaAtualizar($casoId);

        // Caso aberto: cascata com visitados; só continua a partir de quem foi para invalidado.
        $fila = $sementes;
        /** @var array<string, true> $visitados */
        $visitados = [];

        while ($fila !== []) {
            $e = array_shift($fila);
            if (isset($visitados[$e->id])) {
                continue;
            }
            $visitados[$e->id] = true;

            $atual = $this->entregaveis->porId($e->id);
            if ($atual === null) {
                continue;
            }

            if ($this->podeInvalidar($atual->estado)) {
                $this->transicionador->transicionar(
                    $atual, EstadoEntregavel::Invalidado, 'fato_alterado', 'sistema',
                    $caso->regraEntregaveisVersaoId, 'fato', $fatoNovoId,
                );
                $this->eventos->gravar('entregavel.invalidado', $casoId, $atual->id, ['entregavel_id' => $atual->id]);
                $this->tarefas->cancelarAbertaDoEntregavel($atual->id);
                if ($atual->estado === EstadoEntregavel::Divergente) {
                    $this->excecoes->resolverCfDoEntregavel($atual->id, 'fato_alterado');
                }

                // Cascata: dependentes do que foi invalidado, em ordem de tipo.
                foreach ($this->dependencias->dependentesDe($atual->id) as $depId) {
                    $dep = $this->entregaveis->porId($depId);
                    if ($dep !== null && ! isset($visitados[$dep->id])) {
                        $fila[] = $dep;
                    }
                }
            } elseif ($atual->estado === EstadoEntregavel::Disponibilizado) {
                // Documento já protocolado: substituição (§11.2), sem transição.
                $excId = $this->excecoes->abrirUnica($casoId, $atual->id, 'substituicao', 'O', $caso->carteira);
                if ($excId !== null) {
                    $this->eventos->gravar('excecao.aberta', $casoId, $atual->id, ['excecao_id' => $excId]);
                }
            }
        }
    }

    private function podeInvalidar(EstadoEntregavel $estado): bool
    {
        return in_array($estado, [
            EstadoEntregavel::Pronto,
            EstadoEntregavel::Processado,
            EstadoEntregavel::Divergente,
            EstadoEntregavel::Validado,
            EstadoEntregavel::Liberado,
        ], true);
    }
}
