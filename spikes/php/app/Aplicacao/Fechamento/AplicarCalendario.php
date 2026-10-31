<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioPrazo;
use App\Aplicacao\Portas\RepositorioRegras;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\EstadoEntregavel;

/**
 * AplicarCalendario (§18.3). Recalcula os prazos dos casos ABERTOS depois de o calendário ou o
 * catálogo mudarem (feriado novo, prorrogação). Uma transação por titular, sob a trava do §13.
 *
 * Para cada caso aberto com regra_prazos_versao_id, cada entregável COM prazo e NÃO cumprido
 * (§18.4): recalcula as datas com as versões EM USO do calendário e do catálogo e a versão de
 * prazos FIXADA no caso. Se alguma das três datas mudou: atualiza o entregável, grava
 * entregavel_prazo (motivo 'calendario_v<N>', N = versão em uso do calendário) e o evento
 * prazo.alterado. Caso encerrado e entregável cumprido não mudam. Nenhuma transição.
 * Idempotente: sem versão nova, nada muda (alterados = 0).
 */
final readonly class AplicarCalendario
{
    public function __construct(
        private UnidadeDeTrabalho $uow,
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private RepositorioPrazo $prazos,
        private RepositorioRegras $regras,
        private RepositorioEvento $eventos,
    ) {}

    public function aplicar(): int
    {
        // Agrupa os casos abertos com prazos por titular (vêm ordenados por titular, competência).
        $porTitular = [];
        foreach ($this->casos->abertosComPrazos() as $caso) {
            $porTitular[$caso->titularId][] = $caso;
        }

        $alterados = 0;
        foreach ($porTitular as $titularId => $casosDoTitular) {
            $alterados += $this->uow->transacao(function () use ($titularId, $casosDoTitular): int {
                $this->casos->travarTitular((string) $titularId);

                $n = 0;
                foreach ($casosDoTitular as $caso) {
                    $n += $this->recalcularCaso($caso);
                }

                return $n;
            });
        }

        return $alterados;
    }

    private function recalcularCaso(Caso $caso): int
    {
        $prazosVersaoId = $caso->regraPrazosVersaoId;
        if ($prazosVersaoId === null) {
            return 0;
        }

        // Calendário e catálogo EM USO + prazos FIXADOS no caso (§18.3).
        $regrasDePrazo = $this->regras->prazosComPrazosFixos($prazosVersaoId);
        $ctx = $caso->contexto();
        $motivo = 'calendario_v'.$regrasDePrazo->calendarioVersao;

        $alterados = 0;
        foreach ($this->entregaveis->doCasoParaAtualizar($caso->id) as $entregavel) {
            // Só entregáveis com prazo e NÃO cumpridos (§18.4).
            if ($this->cumprido($entregavel->estado, $regrasDePrazo->calculo->estadoCumprimento($entregavel->tipo))) {
                continue;
            }
            $novo = $regrasDePrazo->calculo->para($entregavel->tipo, $caso->competencia, $ctx);
            if ($novo === null) {
                continue; // sem prazo
            }

            $atuais = $this->prazos->datasAtuais($entregavel->id);
            if ($atuais['vencimento'] === $novo->vencimento->iso()
                && $atuais['meta'] === $novo->meta->iso()
                && $atuais['acao'] === $novo->acao->iso()) {
                continue; // nenhuma das três mudou → idempotente
            }

            $this->prazos->gravar($entregavel->id, $caso->id, $novo, $motivo, [
                'calendario' => $regrasDePrazo->calendarioVersaoId,
                'catalogo' => $regrasDePrazo->catalogoVersaoId,
                'prazos' => $regrasDePrazo->prazosVersaoId,
            ]);
            $this->eventos->gravar('prazo.alterado', $caso->id, $entregavel->id, ['entregavel_id' => $entregavel->id]);
            $alterados++;
        }

        return $alterados;
    }

    /** Cumprido: estado atual com ordem ≥ a do estado de cumprimento (§18.4). */
    private function cumprido(EstadoEntregavel $estado, string $estadoCumprimento): bool
    {
        $alvo = EstadoEntregavel::from($estadoCumprimento);

        return $estado->maiorOuIgual($alvo);
    }
}
