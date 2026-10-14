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

    public function publicar(PublicarFatoComando $comando): ResultadoFato
    {
        return $this->uow->transacao(function () use ($comando): ResultadoFato {
            // Ordem global de locks: 1) titular, 2) chave do fato, 3) entregáveis FOR UPDATE (caso, tipo).
            $this->casos->travarTitular($comando->titularId);
            $hash = HashFato::hash($comando->payload, $comando->valorCentavos);
            $this->fatos->travarChave($comando->titularId, $comando->competencia, $comando->tipo, $comando->tributo);
            $vigente = $this->fatos->vigente($comando->titularId, $comando->competencia, $comando->tipo, $comando->tributo);

            if ($vigente !== null && $vigente->hash === $hash) {
                return new ResultadoFato($vigente->id, $vigente->versao, 'sem_mudanca');
            }

            $versao = $vigente !== null ? $vigente->versao + 1 : 1;
            $fatoAnteriorId = $vigente?->id;
            $efeito = $vigente !== null ? 'nova_versao' : 'novo';

            $fato = $this->fatos->inserir(
                $comando->titularId, $comando->competencia, $comando->tipo, $comando->tributo, $versao,
                $comando->valorCentavos, $comando->payload, $comando->fonte, $comando->origemRef, $hash,
                $fatoAnteriorId, $comando->observadoEm,
            );

            $caso = $this->casos->porTitularCompetencia($comando->titularId, new Competencia($comando->competencia));
            $this->eventos->gravar('fato.publicado', $caso?->id, null, ['fato_id' => $fato->id]);

            // Estado do E11 do caso ANTES da reavaliação (§10.3).
            $e11Antes = $this->estadoE11($caso);

            // Invalidação (§11), antes da reavaliação (§11.4).
            if ($efeito === 'nova_versao' && $fatoAnteriorId !== null) {
                $this->invalidador->invalidar($fatoAnteriorId, $fato->id);
            }

            // Reavaliação de todos os casos abertos do titular (§6.2 superconjunto seguro).
            $this->avaliar->avaliarAbertosDoTitular($comando->titularId);

            // Acompanhamento (§10.3): só se gravou e o E11 já estava disponibilizado.
            if ($caso !== null && $e11Antes === EstadoEntregavel::Disponibilizado) {
                $guia = $this->fatos->vigente($caso->titularId, $caso->competencia->valor, 'guia', Tributos::guia($caso));
                $this->acompanhar->aplicar($caso, $comando->tipo, $comando->tributo, $efeito === 'nova_versao', $guia !== null ? $guia->id : $fato->id);
            }

            return new ResultadoFato($fato->id, $fato->versao, $efeito);
        });
    }

    private function estadoE11(?Caso $caso): ?EstadoEntregavel
    {
        if ($caso === null) {
            return null;
        }

        return $this->entregaveis->porCasoTipo($caso->id, 'E11')?->estado;
    }
}
