<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Conferencias\Formulas;
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
    ) {}

    /**
     * @param  list<array{tributo?: string, valor_centavos?: int|null, payload?: mixed}>  $saidas
     * @return array{entregavel_id: string, tipo: string, estado: string}
     */
    public function concluir(string $titularId, string $competenciaValor, string $tipo, string $ator, array $saidas): array
    {
        return $this->uow->transacao(function () use ($titularId, $competenciaValor, $tipo, $ator, $saidas): array {
            // Ordem global de locks: 1) titular, 2) chave do fato, 3) entregáveis FOR UPDATE (caso, tipo).
            $this->casos->travarTitular($titularId);
            $competencia = new Competencia($competenciaValor);
            $caso = $this->casos->porTitularCompetencia($titularId, $competencia);
            if ($caso === null) {
                throw ErroDeNegocio::naoEncontrado("caso inexistente: {$titularId}/{$competenciaValor}");
            }
            // Nível 2 antes do 3: chaves dos fatos de saída (ordenadas) antes de travar a linha do entregável.
            $itemPrevio = $this->regras->entregaveisPorId($caso->regraEntregaveisVersaoId)->item($tipo);
            if ($itemPrevio !== null && $itemPrevio->saidaTipo !== null) {
                $tributos = array_map(static fn (array $s): string => is_string($s['tributo'] ?? null) ? $s['tributo'] : '', $saidas);
                sort($tributos, SORT_STRING);
                foreach ($tributos as $tributoSaida) {
                    $this->fatos->travarChave($caso->titularId, $caso->competencia->valor, $itemPrevio->saidaTipo, $tributoSaida);
                }
            }
            $entregavel = $this->entregaveis->porCasoTipoParaAtualizar($caso->id, $tipo);
            if ($entregavel === null) {
                throw ErroDeNegocio::naoEncontrado("entregável inexistente: {$tipo}");
            }
            if ($entregavel->estado !== EstadoEntregavel::Pronto || $entregavel->sistema()) {
                throw ErroDeNegocio::conflito('entregavel_nao_concluivel', 'entregável não está pronto para conclusão humana');
            }

            $regra = $this->regras->entregaveisPorId($caso->regraEntregaveisVersaoId);
            $item = $regra->item($tipo);
            if ($saidas === [] || $item === null || $item->saidaTipo === null) {
                throw ErroDeNegocio::invalido('saida_invalida', 'entregável sem saída na regra, ou saídas vazias');
            }

            // Publica cada saída como fato (fonte=tarefa) e liga como 'saida'.
            foreach ($saidas as $saida) {
                $this->publicarSaida($caso, $entregavel, $item->saidaTipo, $saida);
            }

            // Conclui a tarefa aberta.
            $tarefa = $this->tarefas->abertaDoEntregavel($entregavel->id);
            $tarefaId = $tarefa['id'] ?? null;
            if ($tarefaId !== null) {
                $this->tarefas->concluir($tarefaId);
            }

            // pronto → processado (tarefa_concluida).
            $this->transicionador->transicionar(
                $entregavel, EstadoEntregavel::Processado, 'tarefa_concluida', $ator,
                $caso->regraEntregaveisVersaoId, 'tarefa', $tarefaId,
            );

            // Conferências + desfecho (§9).
            $eProcessado = $this->recarregar($entregavel->id);
            $tolerancia = $this->regras->toleranciaEmUso();
            $parametros = $this->regras->parametrosEmUso();
            $this->avaliar->conferirEDecidir(
                $caso, $eProcessado, $item, new Formulas($parametros->prolaborePercentualBp), $tolerancia,
                Tributos::guia($caso), Tributos::apuracao($caso), $caso->regraEntregaveisVersaoId,
            );

            // Reavalia os casos abertos do titular (§7.4).
            foreach ($this->casos->abertosDoTitular($caso->titularId) as $casoAberto) {
                $this->avaliar->avaliar($casoAberto);
            }

            $final = $this->recarregar($entregavel->id);

            return ['entregavel_id' => $final->id, 'tipo' => $final->tipo, 'estado' => $final->estado->value];
        });
    }

    /**
     * @param  array{tributo?: string, valor_centavos?: int|null, payload?: mixed}  $saida
     */
    private function publicarSaida(Caso $caso, Entregavel $entregavel, string $saidaTipo, array $saida): void
    {
        $tributo = is_string($saida['tributo'] ?? null) ? $saida['tributo'] : '';
        $valor = array_key_exists('valor_centavos', $saida) ? $saida['valor_centavos'] : null;
        $payload = $saida['payload'] ?? new \stdClass;

        $hash = HashFato::hash($payload, $valor);
        $this->fatos->travarChave($caso->titularId, $caso->competencia->valor, $saidaTipo, $tributo);
        $vigente = $this->fatos->vigente($caso->titularId, $caso->competencia->valor, $saidaTipo, $tributo);

        if ($vigente !== null && $vigente->hash === $hash) {
            // sem_mudanca: liga a versão vigente mesmo assim (§8 item 4).
            $this->entregaveis->ligarFato($entregavel->id, $vigente->id, 'saida');

            return;
        }

        $versao = $vigente !== null ? $vigente->versao + 1 : 1;
        $fato = $this->fatos->inserir(
            $caso->titularId, $caso->competencia->valor, $saidaTipo, $tributo, $versao,
            $valor, $payload, 'tarefa', null, $hash, $vigente?->id, $this->agoraDoBanco(),
        );
        $this->eventos->gravar('fato.publicado', $caso->id, null, ['fato_id' => $fato->id]);
        $this->entregaveis->ligarFato($entregavel->id, $fato->id, 'saida');

        // Se nova_versao e o fato anterior estava ligado, invalida (§11) — exceto o
        // próprio entregável que está sendo concluído (§8 item 4).
        if ($vigente !== null) {
            $this->invalidador->invalidar($vigente->id, $fato->id, $entregavel->id);
        }
    }

    private function agoraDoBanco(): string
    {
        // observado_em das saídas = instante atual do servidor (design: decisões fixas).
        return (new \DateTimeImmutable)->format(DATE_ATOM);
    }

    private function recarregar(string $entregavelId): Entregavel
    {
        $e = $this->entregaveis->porId($entregavelId);
        assert($e !== null);

        return $e;
    }
}
