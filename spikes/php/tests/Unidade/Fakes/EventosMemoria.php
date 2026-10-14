<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\RepositorioEvento;

final class EventosMemoria implements RepositorioEvento
{
    /** @var list<array{nome: string, casoId: ?string, entregavelId: ?string, payload: array<string, string>}> */
    public array $eventos = [];

    public function gravar(string $nome, ?string $casoId, ?string $entregavelId, array $payload): void
    {
        $this->eventos[] = ['nome' => $nome, 'casoId' => $casoId, 'entregavelId' => $entregavelId, 'payload' => $payload];
    }

    /** @return list<string> */
    public function nomes(): array
    {
        return array_map(static fn (array $e): string => $e['nome'], $this->eventos);
    }

    public function quantos(string $nome): int
    {
        return count(array_filter($this->eventos, static fn (array $e): bool => $e['nome'] === $nome));
    }
}
