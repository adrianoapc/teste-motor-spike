<?php

declare(strict_types=1);

namespace App\Aplicacao\Fatos;

use App\Aplicacao\Fechamento\Acompanhar;
use App\Aplicacao\Fechamento\AvaliarCaso;
use App\Aplicacao\Fechamento\Invalidador;
use App\Aplicacao\Fechamento\Tributos;
use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioFato;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use App\Dominio\Fatos\HashFato;
use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\Competencia;
use App\Dominio\Fechamento\EstadoEntregavel;

/**
 * PublicarFato (§6, §10.3, §11). Uma transação por publicação. Idempotente por hash.
 */
final readonly class PublicarFato
{
    public function __construct(
        private UnidadeDeTrabalho $uow,
        private RepositorioFato $fatos,
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private RepositorioEvento $eventos,
        private AvaliarCaso $avaliar,
        private Invalidador $invalidador,
        private Acompanhar $acompanhar,
    ) {}

    /**
     * @return array{fato_id: string, versao: int, efeito: string}
     */
    public function publicar(
        string $titularId,
        string $competencia,
        string $tipo,
        string $tributo,
        ?int $valorCentavos,
        mixed $payload,
        string $fonte,
        ?string $origemRef,
        string $observadoEm,
    ): array {
        return $this->uow->transacao(function () use ($titularId, $competencia, $tipo, $tributo, $valorCentavos, $payload, $fonte, $origemRef, $observadoEm): array {
            $hash = HashFato::hash($payload, $valorCentavos);
            $this->fatos->travarChave($titularId, $competencia, $tipo, $tributo);
            $vigente = $this->fatos->vigente($titularId, $competencia, $tipo, $tributo);

            if ($vigente !== null && $vigente->hash === $hash) {
                return ['fato_id' => $vigente->id, 'versao' => $vigente->versao, 'efeito' => 'sem_mudanca'];
            }

            $versao = $vigente !== null ? $vigente->versao + 1 : 1;
            $fatoAnteriorId = $vigente?->id;
            $efeito = $vigente !== null ? 'nova_versao' : 'novo';

            $fato = $this->fatos->inserir(
                $titularId, $competencia, $tipo, $tributo, $versao,
                $valorCentavos, $payload, $fonte, $origemRef, $hash,
                $fatoAnteriorId, $observadoEm,
            );

            $caso = $this->casos->porTitularCompetencia($titularId, new Competencia($competencia));
            $this->eventos->gravar('fato.publicado', $caso?->id, null, ['fato_id' => $fato->id]);

            // Estado do E11 do caso ANTES da reavaliação (§10.3).
            $e11Antes = $this->estadoE11($caso);

            // Invalidação (§11), antes da reavaliação (§11.4).
            if ($efeito === 'nova_versao' && $fatoAnteriorId !== null) {
                $this->invalidador->invalidar($fatoAnteriorId, $fato->id);
            }

            // Reavaliação de todos os casos abertos do titular (§6.2 superconjunto seguro).
            foreach ($this->casos->abertosDoTitular($titularId) as $casoAberto) {
                $this->avaliar->avaliar($casoAberto);
            }

            // Acompanhamento (§10.3): só se gravou e o E11 já estava disponibilizado.
            if ($caso !== null && $e11Antes === EstadoEntregavel::Disponibilizado) {
                $guia = $this->fatos->vigente($caso->titularId, $caso->competencia->valor, 'guia', Tributos::guia($caso));
                $this->acompanhar->aplicar($caso, $tipo, $tributo, $efeito === 'nova_versao', $guia !== null ? $guia->id : $fato->id);
            }

            return ['fato_id' => $fato->id, 'versao' => $fato->versao, 'efeito' => $efeito];
        });
    }

    private function estadoE11(?Caso $caso): ?EstadoEntregavel
    {
        if ($caso === null) {
            return null;
        }
        $e11 = $this->entregaveis->porCasoTipo($caso->id, 'E11');

        return $e11?->estado;
    }
}
