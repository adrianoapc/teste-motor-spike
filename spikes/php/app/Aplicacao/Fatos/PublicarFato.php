<?php

declare(strict_types=1);

namespace App\Aplicacao\Fatos;

use App\Aplicacao\Fechamento\AvaliarCaso;
use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioFato;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use App\Dominio\Fatos\HashFato;
use App\Dominio\Fechamento\Competencia;

/**
 * PublicarFato (§6). Uma transação por publicação. Idempotente por hash.
 */
final readonly class PublicarFato
{
    public function __construct(
        private UnidadeDeTrabalho $uow,
        private RepositorioFato $fatos,
        private RepositorioCaso $casos,
        private RepositorioEvento $eventos,
        private AvaliarCaso $avaliar,
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
            $vigente = $this->fatos->vigente($titularId, $competencia, $tipo, $tributo);

            if ($vigente !== null && $vigente->hash === $hash) {
                return ['fato_id' => $vigente->id, 'versao' => $vigente->versao, 'efeito' => 'sem_mudanca'];
            }

            $versao = $vigente !== null ? $vigente->versao + 1 : 1;
            $substituiId = $vigente?->id;
            $efeito = $vigente !== null ? 'nova_versao' : 'novo';

            $fato = $this->fatos->inserir(
                $titularId, $competencia, $tipo, $tributo, $versao,
                $valorCentavos, $payload, $fonte, $origemRef, $hash,
                $substituiId, $observadoEm,
            );

            // Evento fato.publicado (§12): caso_id preenchido se existir caso do titular naquela competência.
            $caso = $this->casos->porTitularCompetencia($titularId, new Competencia($competencia));
            $casoId = $caso?->id;
            $this->eventos->gravar('fato.publicado', $casoId, null, ['fato_id' => $fato->id]);

            // Fase 1: sem invalidação (§11 é fase 2).
            // Reavaliação: todos os casos abertos do titular (§6.2 superconjunto seguro).
            foreach ($this->casos->abertosDoTitular($titularId) as $casoAberto) {
                $this->avaliar->avaliar($casoAberto);
            }

            return ['fato_id' => $fato->id, 'versao' => $fato->versao, 'efeito' => $efeito];
        });
    }
}
