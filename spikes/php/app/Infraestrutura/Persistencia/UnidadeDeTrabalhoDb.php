<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\UnidadeDeTrabalho;
use Illuminate\Database\ConnectionInterface;

final readonly class UnidadeDeTrabalhoDb implements UnidadeDeTrabalho
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function transacao(\Closure $comando): mixed
    {
        return $this->conexao->transaction($comando);
    }
}
