<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\RepositorioTarefa;
use App\Aplicacao\Portas\TarefaAberta;

final class TarefasMemoria implements RepositorioTarefa
{
    /** @var array<string, array{casoId: string, entregavelId: string, estado: string, responsavel: string}> */
    public array $tarefas = [];

    public function abrir(string $casoId, string $entregavelId, string $tipo, string $responsavel): string
    {
        $id = 'tar-'.(count($this->tarefas) + 1);
        $this->tarefas[$id] = ['casoId' => $casoId, 'entregavelId' => $entregavelId, 'estado' => 'aberta', 'responsavel' => $responsavel];

        return $id;
    }

    public function abertaDoEntregavel(string $entregavelId): ?string
    {
        foreach (array_reverse($this->tarefas, true) as $id => $t) {
            if ($t['entregavelId'] === $entregavelId && $t['estado'] === 'aberta') {
                return $id;
            }
        }

        return null;
    }

    public function abertasDoCaso(string $casoId): array
    {
        $saida = [];
        foreach ($this->tarefas as $id => $t) {
            if ($t['casoId'] === $casoId && $t['estado'] === 'aberta') {
                $saida[] = new TarefaAberta($id, $t['entregavelId']);
            }
        }

        return $saida;
    }

    public function concluir(string $tarefaId): void
    {
        $this->tarefas[$tarefaId]['estado'] = 'concluida';
    }

    public function cancelarAbertaDoEntregavel(string $entregavelId): void
    {
        foreach ($this->tarefas as $id => $t) {
            if ($t['entregavelId'] === $entregavelId && $t['estado'] === 'aberta') {
                $this->tarefas[$id]['estado'] = 'cancelada';
            }
        }
    }

    /** @return list<string> estados de todas as tarefas, na ordem de criação */
    public function estados(): array
    {
        return array_values(array_map(static fn (array $t): string => $t['estado'], $this->tarefas));
    }
}
