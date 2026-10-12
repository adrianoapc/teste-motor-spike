<?php

declare(strict_types=1);

namespace App\Dominio\Fatos;

use App\Dominio\Fechamento\HashCanonico;

/**
 * Hash do fato (§6): SHA-256 do JSON canônico de
 * {"payload": <payload>, "valor_centavos": <valor ou null>}.
 *
 * O payload chega como stdClass|array (lido com $assoc = false), para preservar
 * a diferença entre {} e [].
 */
final class HashFato
{
    public static function hash(mixed $payload, ?int $valorCentavos): string
    {
        $obj = new \stdClass;
        $obj->payload = $payload;
        $obj->valor_centavos = $valorCentavos;

        return HashCanonico::hash($obj);
    }
}
