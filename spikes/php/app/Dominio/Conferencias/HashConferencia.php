<?php

declare(strict_types=1);

namespace App\Dominio\Conferencias;

use App\Dominio\Fechamento\HashCanonico;

/**
 * entrada_hash de uma conferência (§9.2): SHA-256 do JSON canônico de
 * {"cf", "fatos_usados", "regra_versao_id"} (mais "semantica_calculo" quando > 1).
 *
 * fatos_usados = lista [{"fato_id", "versao"}] ordenada por fato_id.
 */
final class HashConferencia
{
    /**
     * Versão corrente da semântica do cálculo das conferências (semântica §9.2, "semantica_calculo").
     * Sobe sempre que uma regra de cálculo muda de modo que os mesmos fatos possam dar outro resultado;
     * faz parte da chave de idempotência e é persistida em conferencias.conferencia.semantica_calculo.
     * 1 = cálculo legado (CF-12 com 0/0); 2 = CF-12 sem valores em centavos, operando ausente vira
     * divergente classe S, int64 estrito; 3 (v4/M1) = CF-07 do LP tributo a tributo e par IRRF na CF-09.
     */
    public const SEMANTICA_CALCULO = 3;

    /**
     * @param  list<array{fato_id: string, versao: int}>  $fatosUsados
     */
    public static function entradaHash(string $cf, array $fatosUsados, string $regraVersaoId, int $semanticaCalculo): string
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
        // Só entra na chave (última, por code point) quando > 1: conferências legadas (1) mantêm o hash antigo.
        if ($semanticaCalculo > 1) {
            $obj->semantica_calculo = $semanticaCalculo;
        }

        return HashCanonico::hash($obj);
    }
}
