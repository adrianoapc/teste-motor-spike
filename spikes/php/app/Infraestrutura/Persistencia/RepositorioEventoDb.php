<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioEvento;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioEventoDb implements RepositorioEvento
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function gravar(string $nome, ?string $casoId, ?string $entregavelId, array $payload): void
    {
        $payloadJson = json_encode((object) $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->conexao->statement(
            <<<'SQL'
            INSERT INTO eventos.evento (nome, caso_id, entregavel_id, payload)
            VALUES (?, ?, ?, ?::jsonb)
            SQL,
            [$nome, $casoId, $entregavelId, $payloadJson],
        );
    }
}
