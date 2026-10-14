<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\UnidadeDeTrabalho;

/** Sem banco não há rollback: o comando só roda. O que é atômico no Postgres é coberto nos testes de integração. */
final class UnidadeDeTrabalhoMemoria implements UnidadeDeTrabalho
{
    public int $transacoes = 0;

    public function transacao(\Closure $comando): mixed
    {
        $this->transacoes++;

        return $comando();
    }
}
