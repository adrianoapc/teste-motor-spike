<?php

declare(strict_types=1);

namespace App\Api;

use App\Aplicacao\Suporte\ErroDeNegocio;

/**
 * `additionalProperties: false` do contrato (contrato/openapi.yaml): o Laravel só valida as chaves
 * listadas, então chaves não declaradas (ex.: typo `valor_centavo`) precisam ser recusadas à parte.
 */
final class CorpoEstrito
{
    /**
     * @param  object|array<array-key, mixed>  $corpo
     * @param  list<string>  $permitidas
     */
    public static function exigirChaves(object|array $corpo, array $permitidas, string $onde): void
    {
        $chaves = array_keys(is_object($corpo) ? get_object_vars($corpo) : $corpo);
        $desconhecidas = array_values(array_diff(array_map('strval', $chaves), $permitidas));
        if ($desconhecidas !== []) {
            throw ErroDeNegocio::invalido('corpo_invalido', "propriedade não declarada em {$onde}: ".implode(', ', $desconhecidas));
        }
    }
}
