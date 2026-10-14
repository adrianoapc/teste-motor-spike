<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\Relogio;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final readonly class RelogioDb implements Relogio
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function agora(): string
    {
        $linha = $this->conexao->selectOne('SELECT now() AS agora');
        /** @var array{agora?: mixed} $row */
        $row = $linha === null ? [] : (array) $linha;
        $agora = $row['agora'] ?? null;
        if (! is_string($agora) || $agora === '') {
            throw new RuntimeException('o banco não devolveu now()');
        }

        return $agora;
    }
}
