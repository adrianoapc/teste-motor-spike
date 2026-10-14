<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\ConferenciaVigente;
use App\Aplicacao\Portas\DivergenciaBloqueante;
use App\Aplicacao\Portas\RepositorioConferencia;

final class ConferenciasMemoria implements RepositorioConferencia
{
    /** @var list<array{id: string, cf: string, entregavelId: string, entradaHash: string, resultado: string, severidade: string, classe: string, esperado: ?int, obtido: ?int, diferenca: array<string, mixed>, override: bool}> na ordem de gravação (= `em`) */
    public array $linhas = [];

    public function gravarSeNova(
        string $cf,
        string $casoId,
        string $entregavelId,
        string $regraVersaoId,
        array $fatosUsados,
        string $entradaHash,
        string $resultado,
        ?int $esperadoCentavos,
        ?int $obtidoCentavos,
        array $diferenca,
        string $severidade,
        string $classe,
    ): void {
        foreach ($this->linhas as $l) {
            if ($l['cf'] === $cf && $l['entregavelId'] === $entregavelId && $l['entradaHash'] === $entradaHash) {
                return; // ON CONFLICT DO NOTHING
            }
        }
        $this->linhas[] = [
            'id' => 'conf-'.(count($this->linhas) + 1), 'cf' => $cf, 'entregavelId' => $entregavelId, 'entradaHash' => $entradaHash,
            'resultado' => $resultado, 'severidade' => $severidade, 'classe' => $classe,
            'esperado' => $esperadoCentavos, 'obtido' => $obtidoCentavos, 'diferenca' => $diferenca, 'override' => false,
        ];
    }

    public function vigente(string $entregavelId, string $cf): ?ConferenciaVigente
    {
        $v = $this->vigenteDe($entregavelId, $cf);

        return $v === null ? null : new ConferenciaVigente($v['id'], $v['resultado'], $v['severidade'], $v['override']);
    }

    public function registrarOverride(string $conferenciaId, string $autor, string $motivo): void
    {
        foreach ($this->linhas as $i => $l) {
            if ($l['id'] === $conferenciaId) {
                $this->linhas[$i]['override'] = true;
            }
        }
    }

    public function divergentesBloqueantes(string $entregavelId): array
    {
        $cfs = array_values(array_unique(array_map(
            static fn (array $l): string => $l['cf'],
            array_filter($this->linhas, static fn (array $l): bool => $l['entregavelId'] === $entregavelId),
        )));
        sort($cfs);

        $saida = [];
        foreach ($cfs as $cf) {
            $v = $this->vigenteDe($entregavelId, $cf);
            if ($v !== null && $v['resultado'] === 'divergente' && $v['severidade'] === 'B' && ! $v['override']) {
                $saida[] = new DivergenciaBloqueante($v['id'], $cf, $v['classe']);
            }
        }

        return $saida;
    }

    public function cfsDivergentesBloqueantes(string $entregavelId): array
    {
        return array_map(static fn (DivergenciaBloqueante $d): string => $d->cf, $this->divergentesBloqueantes($entregavelId));
    }

    /**
     * @return array{id: string, cf: string, entregavelId: string, entradaHash: string, resultado: string, severidade: string, classe: string, esperado: ?int, obtido: ?int, diferenca: array<string, mixed>, override: bool}|null
     */
    private function vigenteDe(string $entregavelId, string $cf): ?array
    {
        $vigente = null;
        foreach ($this->linhas as $l) {
            if ($l['entregavelId'] === $entregavelId && $l['cf'] === $cf) {
                $vigente = $l; // a última gravada é a de maior `em`
            }
        }

        return $vigente;
    }
}
