<?php

declare(strict_types=1);

namespace App\Aplicacao\Fatos;

use App\Aplicacao\Fechamento\Acompanhar;
use App\Aplicacao\Fechamento\AvaliarCaso;
use App\Aplicacao\Fechamento\Invalidador;
use App\Aplicacao\Fechamento\Tributos;
use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioFato;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\Competencia;
use App\Dominio\Fechamento\EstadoEntregavel;

/**
 * PublicarFato (§6, §10.3, §11, v5). Uma transação por publicação, sob a trava do
 * titular (§13). Idempotência por chave (§6.5) e por hash (§6.4).
 */
final readonly class PublicarFato
{
    public function __construct(
        private UnidadeDeTrabalho $uow,
        private RepositorioFato $fatos,
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private PublicacaoDeFato $publicacao,
        private AvaliarCaso $avaliar,
        private Invalidador $invalidador,
        private Acompanhar $acompanhar,
    ) {}

    public function publicar(PublicarFatoComando $comando): ResultadoFato
    {
        return $this->uow->transacao(function () use ($comando): ResultadoFato {
            // §13: trava do titular antes de qualquer leitura de estado.
            $this->casos->travarTitular($comando->titularId);

            // Ordem global de locks: 2) chave do fato.
            $this->fatos->travarChave($comando->titularId, $comando->competencia, $comando->tipo, $comando->tributo);

            $caso = $this->casos->porTitularCompetencia($comando->titularId, new Competencia($comando->competencia));

            // Estado do E11 do caso ANTES da reavaliação (§10.3).
            $e11Antes = $this->estadoE11($caso);

            // Núcleo §6.2–6.5: valida contra o contrato, resolve idempotência de requisição,
            // calcula o hash v2, decide o efeito, grava fato (quando for o caso) e o recebimento,
            // abre conflito_fonte.
            $pub = $this->publicacao->publicar(
                $comando->titularId, $comando->competencia, $comando->tipo, $comando->tributo,
                $comando->valorCentavos, $comando->payload, $comando->fonte, $comando->origemRef,
                $comando->observadoEm, $comando->chaveIdempotencia, $caso, 'payload',
            );
            $fato = $pub->fato;
            $efeito = $pub->efeito;

            // §6.5: requisição repetida devolve o resultado da primeira vez, sem efeitos novos.
            if ($pub->repetido) {
                return new ResultadoFato($fato->id, $fato->versao, $efeito, true);
            }

            // §11: invalidação só em nova_versao, e só se o fato anterior estava ligado.
            if ($efeito === 'nova_versao' && $pub->fatoAnteriorId !== null) {
                $this->invalidador->invalidar($pub->fatoAnteriorId, $fato->id);
            }

            // Reavaliação dos casos abertos do titular (§6.2: superconjunto seguro e idempotente).
            if ($efeito === 'novo' || $efeito === 'nova_versao') {
                $this->avaliar->avaliarAbertosDoTitular($comando->titularId);
            }

            // Acompanhamento (§10.3): só se gravou e o E11 já estava disponibilizado.
            if (($efeito === 'novo' || $efeito === 'nova_versao') && $caso !== null && $e11Antes === EstadoEntregavel::Disponibilizado) {
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
