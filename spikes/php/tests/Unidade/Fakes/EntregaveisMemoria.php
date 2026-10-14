<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Dominio\Fechamento\Entregavel;
use App\Dominio\Fechamento\EstadoEntregavel;
use RuntimeException;

final class EntregaveisMemoria implements RepositorioEntregavel
{
    /** @var array<string, Entregavel> */
    public array $itens = [];

    /** @var list<array{tipo: string, de: ?string, para: string, motivo: string, causadoPorTipo: ?string, causadoPorId: ?string, ator: string}> */
    public array $transicoes = [];

    /** @var list<array{entregavelId: string, fatoId: string, papel: string}> */
    public array $fatosLigados = [];

    public function criar(string $casoId, string $tipo, EstadoEntregavel $estado, string $executor, string $area): Entregavel
    {
        $e = new Entregavel('ent-'.(count($this->itens) + 1), $casoId, $tipo, $estado, $executor, $area, 0);
        $this->itens[$e->id] = $e;

        return $e;
    }

    /** Atalho de teste: põe o entregável do tipo no estado, sem registrar transição. */
    public function definirEstado(string $casoId, string $tipo, EstadoEntregavel $estado): Entregavel
    {
        $atual = $this->porCasoTipo($casoId, $tipo) ?? throw new RuntimeException("sem {$tipo}");
        $this->itens[$atual->id] = new Entregavel($atual->id, $atual->casoId, $atual->tipo, $estado, $atual->executor, $atual->area, $atual->version);

        return $this->itens[$atual->id];
    }

    public function doCasoParaAtualizar(string $casoId): array
    {
        return $this->doCaso($casoId);
    }

    public function doCaso(string $casoId): array
    {
        $lista = array_values(array_filter($this->itens, static fn (Entregavel $e): bool => $e->casoId === $casoId));
        usort($lista, static fn (Entregavel $a, Entregavel $b): int => strcmp($a->tipo, $b->tipo));

        return $lista;
    }

    public function porCasoTipo(string $casoId, string $tipo): ?Entregavel
    {
        foreach ($this->itens as $e) {
            if ($e->casoId === $casoId && $e->tipo === $tipo) {
                return $e;
            }
        }

        return null;
    }

    public function porCasoTipoParaAtualizar(string $casoId, string $tipo): ?Entregavel
    {
        return $this->porCasoTipo($casoId, $tipo);
    }

    public function porId(string $entregavelId): ?Entregavel
    {
        return $this->itens[$entregavelId] ?? null;
    }

    public function obter(string $entregavelId): Entregavel
    {
        return $this->itens[$entregavelId] ?? throw new RuntimeException("entregável inexistente: {$entregavelId}");
    }

    public function salvarEstado(string $entregavelId, EstadoEntregavel $esperado, EstadoEntregavel $estado): bool
    {
        $atual = $this->itens[$entregavelId] ?? null;
        if ($atual === null || $atual->estado !== $esperado) {
            return false;
        }
        $this->itens[$entregavelId] = $atual->com($estado);

        return true;
    }

    public function registrarTransicao(
        Entregavel $entregavel,
        ?EstadoEntregavel $de,
        EstadoEntregavel $para,
        string $motivo,
        ?string $causadoPorTipo,
        ?string $causadoPorId,
        string $ator,
        string $regraVersaoId,
    ): void {
        $this->transicoes[] = [
            'tipo' => $entregavel->tipo, 'de' => $de?->value, 'para' => $para->value, 'motivo' => $motivo,
            'causadoPorTipo' => $causadoPorTipo, 'causadoPorId' => $causadoPorId, 'ator' => $ator,
        ];
    }

    public function ligarFato(string $entregavelId, string $fatoId, string $papel): void
    {
        $this->fatosLigados[] = ['entregavelId' => $entregavelId, 'fatoId' => $fatoId, 'papel' => $papel];
    }

    public function comFato(string $fatoId): array
    {
        $ids = [];
        foreach ($this->fatosLigados as $l) {
            if ($l['fatoId'] === $fatoId) {
                $ids[$l['entregavelId']] = true;
            }
        }
        $lista = array_values(array_filter($this->itens, static fn (Entregavel $e): bool => isset($ids[$e->id])));
        usort($lista, static fn (Entregavel $a, Entregavel $b): int => strcmp($a->tipo, $b->tipo));

        return $lista;
    }

    /** @return list<string> pares "tipo:de>para" das transições, na ordem em que ocorreram */
    public function resumoTransicoes(): array
    {
        return array_map(static fn (array $t): string => "{$t['tipo']}:".($t['de'] ?? '-').">{$t['para']}", $this->transicoes);
    }
}
