<?php

declare(strict_types=1);

namespace App\Dominio\Regras;

use DomainException;

/**
 * O conteúdo de uma versão de regra não tem a forma que o motor exige (chave ausente, tipo errado, valor
 * fora da faixa). É erro de configuração da regra: o motor para com a mensagem em vez de assumir um padrão.
 */
final class RegraInvalida extends DomainException
{
    public static function em(string $regraChave, string $versaoId, string $motivo): self
    {
        return new self("regra {$regraChave} (versão {$versaoId}) inválida: {$motivo}");
    }
}
