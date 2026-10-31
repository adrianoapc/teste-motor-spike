<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

use App\Dominio\Prazos\Prazo;

/**
 * Persistência dos prazos do entregável (§18.2/§18.3): grava as três datas na linha do
 * entregável e uma linha de histórico em fechamento.entregavel_prazo (tabela só-inserção).
 */
interface RepositorioPrazo
{
    /**
     * Grava as datas do entregável e a linha de histórico. `$motivo` = 'abertura' na abertura,
     * 'calendario_v<N>' em AplicarCalendario. `$versoes` traz os ids de versão das três regras.
     *
     * @param  array{calendario: string, catalogo: string, prazos: string}  $versoes
     */
    public function gravar(string $entregavelId, string $casoId, Prazo $prazo, string $motivo, array $versoes): void;

    /**
     * Datas atuais do entregável (as três ou null), para AplicarCalendario decidir se mudaram.
     *
     * @return array{vencimento: ?string, meta: ?string, acao: ?string}
     */
    public function datasAtuais(string $entregavelId): array;
}
