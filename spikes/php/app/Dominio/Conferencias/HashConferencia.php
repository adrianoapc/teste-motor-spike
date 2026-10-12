<?php

declare(strict_types=1);

namespace App\Dominio\Conferencias;

use App\Dominio\Fechamento\HashCanonico;

/**
 * entrada_hash de uma conferência (§9.2): SHA-256 do JSON canônico de
 * {"cf", "fatos_usados", "regra_versao_id"}.
 *
 * fatos_usados = lista [{"fato_id", "versao"}] ordenada por fato_id.
 */
final class HashConferencia
{
    /**
     * @param  list<array{fato_id: string, versao: int}>  $fatosUsados
     */
    public static function entradaHash(string $cf, array $fatosUsados, string $regraVersaoId): string
    {
        $lista = $fatosUsados;
        usort($lista, static fn (array $a, array $b): int => strcmp($a['fato_id'], $b['fato_id']));

        $itens = array_map(static function (array $f): \stdClass {
            $o = new \stdClass;
            $o->fato_id = $f['fato_id'];
            $o->versao = $f['versao'];

            return $o;
        }, $lista);

        $obj = new \stdClass;
        $obj->cf = $cf;
        $obj->fatos_usados = $itens;
        $obj->regra_versao_id = $regraVersaoId;

        return HashCanonico::hash($obj);
    }
}
