<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioConferencia;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioExcecao;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\Competencia;
use App\Dominio\Fechamento\EstadoEntregavel;

/**
 * RegistrarOverride (§8.1). Valida 422 → 404 → 409, grava o override na
 * conferência vigente, resolve a exceção da CF, e se não restar divergência B
 * sem override leva o entregável a validado.
 */
final readonly class RegistrarOverride
{
    public function __construct(
        private UnidadeDeTrabalho $uow,
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private RepositorioConferencia $conferencias,
        private RepositorioExcecao $excecoes,
        private RepositorioEvento $eventos,
        private Transicionador $transicionador,
        private AvaliarCaso $avaliar,
    ) {}

    public function registrar(RegistrarOverrideComando $comando): ResultadoEntregavel
    {
        // 422: corpo inválido (ator/cf/motivo já validados na borda; cf padrão e motivo >= 5).
        if (preg_match('/^CF-[0-9]{2}$/D', $comando->cf) !== 1 || mb_strlen($comando->motivo) < 5 || $comando->ator === '') {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo inválido para override');
        }

        return $this->uow->transacao(function () use ($comando): ResultadoEntregavel {
            // Ordem global de locks: 1) titular, 2) chave do fato, 3) entregáveis FOR UPDATE (caso, tipo).
            $this->casos->travarTitular($comando->titularId);
            $caso = $this->casos->porTitularCompetencia($comando->titularId, new Competencia($comando->competencia));
            if ($caso === null) {
                throw ErroDeNegocio::naoEncontrado("caso inexistente: {$comando->titularId}/{$comando->competencia}");
            }
            $entregavel = $this->entregaveis->porCasoTipoParaAtualizar($caso->id, $comando->tipo);
            if ($entregavel === null) {
                throw ErroDeNegocio::naoEncontrado("entregável inexistente: {$comando->tipo}");
            }

            // 409: entregável fora de divergente, ou conferência vigente da CF não é
            // divergente B sem override.
            if ($entregavel->estado !== EstadoEntregavel::Divergente) {
                throw ErroDeNegocio::conflito('entregavel_nao_divergente', 'entregável não está divergente');
            }
            $vigente = $this->conferencias->vigente($entregavel->id, $comando->cf);
            if ($vigente === null || $vigente->resultado !== 'divergente' || $vigente->severidade !== 'B' || $vigente->temOverride) {
                throw ErroDeNegocio::conflito('conferencia_sem_divergencia_b', 'conferência vigente da CF não é divergente B sem override');
            }

            // Efeito.
            $this->conferencias->registrarOverride($vigente->id, $comando->ator, $comando->motivo);
            $this->excecoes->resolverPorTipo($entregavel->id, $comando->cf, 'override');
            $this->eventos->gravar('conferencia.override', $caso->id, $entregavel->id, ['conferencia_id' => $vigente->id]);

            // Se nenhuma outra CF do entregável tiver vigente divergente B sem override: → validado.
            if ($this->conferencias->cfsDivergentesBloqueantes($entregavel->id) === []) {
                $this->transicionador->validar(
                    $entregavel, 'override', $comando->ator, $caso->regraEntregaveisVersaoId, 'conferencia', $vigente->id,
                );
            }

            // Reavalia os casos abertos do titular (§7.4).
            $this->avaliar->avaliarAbertosDoTitular($caso->titularId);

            return ResultadoEntregavel::de($this->entregaveis->obter($entregavel->id));
        });
    }
}
