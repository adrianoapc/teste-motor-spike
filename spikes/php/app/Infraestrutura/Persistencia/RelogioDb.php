<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\Relogio;
use Illuminate\Database\ConnectionInterface;

final readonly class RelogioDb implements Relogio
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function agora(): string
    {
        $linha = $this->conexao->selectOne('SELECT now() AS agora');
        /** @var array{agora?: string} $row */
        $row = $linha === null ? [] : (array) $linha;

        return is_string($row['agora'] ?? null) ? $row['agora'] : '';
    }
}
