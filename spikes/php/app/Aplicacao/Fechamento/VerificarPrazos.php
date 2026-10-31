<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioPrazo;
use App\Aplicacao\Portas\RepositorioRegras;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\EstadoEntregavel;

/**
 * VerificarPrazos (§18.4): abre a exceção `prazo_em_risco` (classe O) nos entregáveis que ainda
 * podem gerar multa e cuja data de ação já chegou. Função do `data_ref` passado por quem chama
 * (o motor é sem relógio, §18). Uma transação por titular, sob a trava do §13.
 *
 * Para cada entregável de caso ABERTO, com `gera_multa`, NÃO cumprido e com `acao ≤ data_ref`:
 * se não houver `prazo_em_risco` aberta nele, abre uma (dono = carteira do caso, prazo =
 * vencimento) e grava `excecao.aberta`. Idempotente no mesmo `data_ref`. Nenhuma transição.
 */
final readonly class VerificarPrazos
{
    public function __construct(
        private UnidadeDeTrabalho $uow,
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private RepositorioPrazo $prazos,
        private RepositorioRegras $regras,
        private Excecoes $excecoes,
    ) {}

    public function verificar(string $dataRef): int
    {
        $porTitular = [];
        foreach ($this->casos->abertosComPrazos() as $caso) {
            $porTitular[$caso->titularId][] = $caso;
        }

        $abertas = 0;
        foreach ($porTitular as $titularId => $casosDoTitular) {
            $abertas += $this->uow->transacao(function () use ($titularId, $casosDoTitular, $dataRef): int {
                $this->casos->travarTitular((string) $titularId);

                $n = 0;
                foreach ($casosDoTitular as $caso) {
                    $n += $this->verificarCaso($caso, $dataRef);
                }

                return $n;
            });
        }

        return $abertas;
    }

    private function verificarCaso(Caso $caso, string $dataRef): int
    {
        $prazosVersaoId = $caso->regraPrazosVersaoId;
        if ($prazosVersaoId === null) {
            return 0;
        }
        $regrasDePrazo = $this->regras->prazosComPrazosFixos($prazosVersaoId);

        $abertas = 0;
        foreach ($this->entregaveis->doCasoParaAtualizar($caso->id) as $entregavel) {
            if (! $regrasDePrazo->calculo->geraMulta($entregavel->tipo)) {
                continue;
            }
            if ($this->cumprido($entregavel->estado, $regrasDePrazo->calculo->estadoCumprimento($entregavel->tipo))) {
                continue;
            }

            $datas = $this->prazos->datasAtuais($entregavel->id);
            $acao = $datas['acao'];
            $vencimento = $datas['vencimento'];
            // Sem datas (entregável sem prazo) ou ação ainda no futuro: não abre.
            if ($acao === null || $vencimento === null || $acao > $dataRef) {
                continue;
            }

            // abrirUnica garante a idempotência: só abre se não houver prazo_em_risco aberta.
            $id = $this->excecoes->abrirUnica($caso->id, $entregavel->id, 'prazo_em_risco', 'O', $caso->carteira, $vencimento);
            if ($id !== null) {
                $abertas++;
            }
        }

        return $abertas;
    }

    /** Cumprido: estado atual com ordem ≥ a do estado de cumprimento (§18.4). */
    private function cumprido(EstadoEntregavel $estado, string $estadoCumprimento): bool
    {
        return $estado->maiorOuIgual(EstadoEntregavel::from($estadoCumprimento));
    }
}
