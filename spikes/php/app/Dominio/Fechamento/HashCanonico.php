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
        $normalizado = self::normalizar($valor);

        try {
            return json_encode(
                $normalizado,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new \RuntimeException('falha ao serializar JSON canônico: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Ordena recursivamente as chaves dos objetos (stdClass), preservando a
     * ordem das listas e a distinção entre {} (stdClass) e [] (array).
     */
    private static function normalizar(mixed $valor): mixed
    {
        if ($valor instanceof \stdClass) {
            $pares = get_object_vars($valor);
            uksort($pares, static fn (string $a, string $b): int => strcmp($a, $b));
            $saida = new \stdClass;
            foreach ($pares as $chave => $v) {
                $saida->{$chave} = self::normalizar($v);
            }

            return $saida;
        }

        if (is_array($valor)) {
            return array_map(static fn (mixed $v): mixed => self::normalizar($v), $valor);
        }

        return $valor;
    }
}
