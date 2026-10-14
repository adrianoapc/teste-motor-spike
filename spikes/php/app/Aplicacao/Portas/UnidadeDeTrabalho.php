<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

/**
 * Unidade de trabalho: executa um comando numa única transação (§13).
 * A implementação concreta (Infraestrutura) usa DB::transaction.
 */
interface UnidadeDeTrabalho
{
    /**
     * @template TRetorno
     *
     * @param  \Closure(): TRetorno  $comando
     * @return TRetorno
     */
    public function transacao(\Closure $comando): mixed;
}
