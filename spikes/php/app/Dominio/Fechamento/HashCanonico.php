<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

use JsonException;

/**
 * Hash canônico (§6, tech.md): SHA-256 hexadecimal minúsculo do JSON canônico.
 * - chaves de objeto ordenadas por bytes UTF-8 (strcmp), em todos os níveis;
 * - sem espaços;
 * - UTF-8 sem escapar não-ASCII nem barras;
 * - preserva a diferença entre objeto vazio {} e lista vazia [].
 *
 * Objetos vêm como stdClass (decodificados com $assoc = false), listas como
 * array. Nunca usar json_decode(..., true): transformaria {} em [].
 */
final class HashCanonico
{
    public static function hash(mixed $valor): string
    {
        return hash('sha256', self::codificar($valor));
    }

    /**
     * JSON canônico de um valor (objetos ordenados). Público para o
     * entrada_hash das conferências (§9.2), que serializa um objeto montado.
     */
    public static function codificar(mixed $valor): string
    {
        try {
            // Números: só inteiros int64 (JsonCru::somenteInteiros é garantido na borda); nunca float.
            return JsonCru::codificar($valor, true);
        } catch (JsonException $e) {
            throw new \RuntimeException('falha ao serializar JSON canônico: '.$e->getMessage(), 0, $e);
        }
    }
}
