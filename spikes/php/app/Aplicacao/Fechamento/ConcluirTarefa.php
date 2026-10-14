<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\Relogio;
use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioFato;
use App\Aplicacao\Portas\RepositorioRegras;
use App\Aplicacao\Portas\RepositorioTarefa;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fatos\HashFato;
use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\Competencia;
use App\Dominio\Fechamento\Entregavel;
use App\Dominio\Fechamento\EstadoEntregavel;

/**
 * ConcluirTarefa (§8). POST /casos/{titular}/{competencia}/entregaveis/{tipo}/concluir.
 */
final readonly class ConcluirTarefa
{
    public function __construct(
        private UnidadeDeTrabalho $uow,
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private RepositorioTarefa $tarefas,
        private RepositorioFato $fatos,
        private RepositorioEvento $eventos,
        private RepositorioRegras $regras,
        private Transicionador $transicionador,
        private AvaliarCaso $avaliar,
        private Invalidador $invalidador,
        private Relogio $relogio,
    ) {}

    public function concluir(ConcluirTarefaComando $comando): ResultadoEntregavel
    {
        return $this->uow->transacao(function () use ($comando): ResultadoEntregavel {
            // Ordem global de locks: 1) titular, 2) chave do fato, 3) entregáveis FOR UPDATE (caso, tipo).
            $this->casos->travarTitular($comando->titularId);
            $caso = $this->casos->porTitularCompetencia($comando->titularId, new Competencia($comando->competencia));
            if ($caso === null) {
                throw ErroDeNegocio::naoEncontrado("caso inexistente: {$comando->titularId}/{$comando->competencia}");
            }
            $item = $this->regras->entregaveisPorId($caso->regraEntregaveisVersaoId)->item($comando->tipo);

            // Nível 2 antes do 3: chaves dos fatos de saída (ordenadas) antes de travar a linha do entregável.
            if ($item !== null && $item->saidaTipo !== null) {
                $tributos = array_map(static fn (SaidaDaTarefa $s): string => $s->tributo, $comando->saidas);
                sort($tributos, SORT_STRING);
                foreach ($tributos as $tributoSaida) {
                    $this->fatos->travarChave($caso->titularId, $caso->competencia->valor, $item->saidaTipo, $tributoSaida);
                }
            }
            $entregavel = $this->entregaveis->porCasoTipoParaAtualizar($caso->id, $comando->tipo);
            if ($entregavel === null) {
                throw ErroDeNegocio::naoEncontrado("entregável inexistente: {$comando->tipo}");
            }
            if ($entregavel->estado !== EstadoEntregavel::Pronto || $entregavel->sistema()) {
                throw ErroDeNegocio::conflito('entregavel_nao_concluivel', 'entregável não está pronto para conclusão humana');
            }
            if ($comando->saidas === [] || $item === null || $item->saidaTipo === null) {
                throw ErroDeNegocio::invalido('saida_invalida', 'entregável sem saída na regra, ou saídas vazias');
            }

            // Publica cada saída como fato (fonte=tarefa) e liga como 'saida'.
            foreach ($comando->saidas as $saida) {
                $this->publicarSaida($caso, $entregavel, $item->saidaTipo, $saida);
            }

            // Conclui a tarefa aberta.
            $tarefaId = $this->tarefas->abertaDoEntregavel($entregavel->id);
            if ($tarefaId !== null) {
                $this->tarefas->concluir($tarefaId);
            }

            // pronto → processado (tarefa_concluida); depois conferências + desfecho (§9).
            $processado = $this->transicionador->transicionar(
                $entregavel, EstadoEntregavel::Processado, 'tarefa_concluida', $comando->ator,
                $caso->regraEntregaveisVersaoId, 'tarefa', $tarefaId,
            );
            $this->avaliar->conferirEDecidir($caso, $processado, $item, $this->avaliar->parametrosDe($caso));

            // Reavalia os casos abertos do titular (§7.4).
            $this->avaliar->avaliarAbertosDoTitular($caso->titularId);

            return ResultadoEntregavel::de($this->entregaveis->obter($entregavel->id));
        });
    }

    private function publicarSaida(Caso $caso, Entregavel $entregavel, string $saidaTipo, SaidaDaTarefa $saida): void
    {
        $hash = HashFato::hash($saida->payload, $saida->valorCentavos);
        $this->fatos->travarChave($caso->titularId, $caso->competencia->valor, $saidaTipo, $saida->tributo);
        $vigente = $this->fatos->vigente($caso->titularId, $caso->competencia->valor, $saidaTipo, $saida->tributo);

        if ($vigente !== null && $vigente->hash === $hash) {
            // sem_mudanca: liga a versão vigente mesmo assim (§8 item 4).
            $this->entregaveis->ligarFato($entregavel->id, $vigente->id, 'saida');

            return;
        }

        $versao = $vigente !== null ? $vigente->versao + 1 : 1;
        $fato = $this->fatos->inserir(
            $caso->titularId, $caso->competencia->valor, $saidaTipo, $saida->tributo, $versao,
            // observado_em das saídas = instante atual do banco (design: hora sempre do banco, nunca do PHP).
            $saida->valorCentavos, $saida->payload, 'tarefa', null, $hash, $vigente?->id, $this->relogio->agora(),
        );
        $this->eventos->gravar('fato.publicado', $caso->id, null, ['fato_id' => $fato->id]);
        $this->entregaveis->ligarFato($entregavel->id, $fato->id, 'saida');

        // Se nova_versao e o fato anterior estava ligado, invalida (§11) — exceto o
        // próprio entregável que está sendo concluído (§8 item 4).
        if ($vigente !== null) {
            $this->invalidador->invalidar($vigente->id, $fato->id, $entregavel->id);
        }
    }
}
