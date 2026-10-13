<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioCatalogo;
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
        private RepositorioCatalogo $catalogo,
        private Transicionador $transicionador,
        private AvaliarCaso $avaliar,
    ) {}

    /**
     * @return array{entregavel_id: string, tipo: string, estado: string}
     */
    public function registrar(string $titularId, string $competenciaValor, string $tipo, string $ator, string $cf, string $motivo): array
    {
        // 422: corpo inválido (ator/cf/motivo já validados na borda; cf padrão e motivo >= 5).
        if (preg_match('/^CF-[0-9]{2}$/', $cf) !== 1 || mb_strlen($motivo) < 5 || $ator === '') {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo inválido para override');
        }

        return $this->uow->transacao(function () use ($titularId, $competenciaValor, $tipo, $ator, $cf, $motivo): array {
            // Ordem global de locks: 1) titular, 2) chave do fato, 3) entregáveis FOR UPDATE (caso, tipo).
            $this->casos->travarTitular($titularId);
            $caso = $this->casos->porTitularCompetencia($titularId, new Competencia($competenciaValor));
            if ($caso === null) {
                throw ErroDeNegocio::naoEncontrado("caso inexistente: {$titularId}/{$competenciaValor}");
            }
            $entregavel = $this->entregaveis->porCasoTipoParaAtualizar($caso->id, $tipo);
            if ($entregavel === null) {
                throw ErroDeNegocio::naoEncontrado("entregável inexistente: {$tipo}");
            }

            // 409: entregável fora de divergente, ou conferência vigente da CF não é
            // divergente B sem override.
            if ($entregavel->estado !== EstadoEntregavel::Divergente) {
                throw ErroDeNegocio::conflito('entregavel_nao_divergente', 'entregável não está divergente');
            }
            $vigente = $this->conferencias->vigente($entregavel->id, $cf);
            if ($vigente === null || $vigente['resultado'] !== 'divergente' || $vigente['severidade'] !== 'B' || $vigente['tem_override']) {
                throw ErroDeNegocio::conflito('conferencia_sem_divergencia_b', 'conferência vigente da CF não é divergente B sem override');
            }

            // Efeito.
            $this->conferencias->registrarOverride($vigente['id'], $ator, $motivo);
            $this->excecoes->resolverPorTipo($entregavel->id, $cf, 'override');
            $this->eventos->gravar('conferencia.override', $caso->id, $entregavel->id, ['conferencia_id' => $vigente['id']]);

            // Se nenhuma outra CF do entregável tiver vigente divergente B sem override: → validado.
            if ($this->conferencias->cfsDivergentesBloqueantes($entregavel->id) === []) {
                $this->transicionador->transicionar(
                    $entregavel, EstadoEntregavel::Validado, 'override', $ator,
                    $caso->regraEntregaveisVersaoId, 'conferencia', $vigente['id'],
                );
                $this->eventoDoTipo($caso->id, $entregavel->id, $entregavel->tipo);
            }

            // Reavalia os casos abertos do titular (§7.4).
            foreach ($this->casos->abertosDoTitular($caso->titularId) as $casoAberto) {
                $this->avaliar->avaliar($casoAberto);
            }

            $final = $this->entregaveis->porId($entregavel->id);
            assert($final !== null);

            return ['entregavel_id' => $final->id, 'tipo' => $final->tipo, 'estado' => $final->estado->value];
        });
    }

    private function eventoDoTipo(string $casoId, string $entregavelId, string $tipo): void
    {
        $evento = $this->catalogo->entregavelTipo($tipo)->eventoPublicado;
        if ($evento !== null) {
            $this->eventos->gravar($evento, $casoId, $entregavelId, ['entregavel_id' => $entregavelId]);
        }
    }
}
