<?php

declare(strict_types=1);

namespace App\Dominio\Fatos;

use App\Dominio\Fechamento\HashCanonico;
use Normalizer;

/**
 * Hash v2 do fato (§6.3, v5), sob o contrato ativo do tipo:
 *
 *   hash = sha256( canonico( {"payload": ordenar(normalizar(projetar(payload))), "valor_centavos": v} ) )
 *
 * Funções puras do domínio. O canônico é o da v4 (HashCanonico). O payload chega
 * como stdClass|array (lido com $assoc = false), para preservar {} vs [].
 *
 * Provado pelos vetores de contrato/vetores-hash-v2.json (e a etapa canônica por
 * vetores-hash.json). Nada aqui faz UPDATE em fatos.fato: é só cálculo.
 */
final class HashFatoV2
{
    /** Entrada exata do sha256 (texto canônico), para os vetores e para o hash. */
    public static function entrada(mixed $payload, ?int $valorCentavos, ContratoTipoFato $contrato): string
    {
        $projetado = self::projetar($payload, $contrato->camposHash);
        $normalizado = self::normalizar($projetado);
        $ordenado = self::ordenar($normalizado, $contrato->ordenarArrays);

        $obj = new \stdClass;
        $obj->payload = $ordenado;
        $obj->valor_centavos = $valorCentavos;

        return HashCanonico::codificar($obj);
    }

    public static function hash(mixed $payload, ?int $valorCentavos, ContratoTipoFato $contrato): string
    {
        return hash('sha256', self::entrada($payload, $valorCentavos, $contrato));
    }

    /**
     * Projetar: mantém só as chaves de campos_hash que existem no payload,
     * na ordem em que aparecem no payload (o canônico reordena por code point depois).
     *
     * @param  list<string>  $camposHash
     */
    public static function projetar(mixed $payload, array $camposHash): \stdClass
    {
        $saida = new \stdClass;
        if (! $payload instanceof \stdClass) {
            return $saida;
        }
        foreach (get_object_vars($payload) as $chave => $valor) {
            if (in_array($chave, $camposHash, true)) {
                $saida->{$chave} = $valor;
            }
        }

        return $saida;
    }

    /**
     * Normalizar (§6.3): strings em NFC, espaços colapsados a um, pontas sem espaço;
     * membro de objeto com valor null removido (em qualquer profundidade); elemento
     * null de array fica; chaves de objeto não mudam.
     */
    public static function normalizar(mixed $valor): mixed
    {
        if (is_string($valor)) {
            return self::normalizarTexto($valor);
        }
        if ($valor instanceof \stdClass) {
            $saida = new \stdClass;
            foreach (get_object_vars($valor) as $chave => $v) {
                if ($v === null) {
                    continue;
                }
                $saida->{$chave} = self::normalizar($v);
            }

            return $saida;
        }
        if (is_array($valor)) {
            return array_map(static fn (mixed $v): mixed => self::normalizar($v), array_values($valor));
        }

        return $valor;
    }

    private static function normalizarTexto(string $s): string
    {
        $nfc = Normalizer::normalize($s, Normalizer::FORM_C);
        // Normalizer::normalize devolve string|false; na borda o texto já é UTF-8 válido.
        $base = is_string($nfc) ? $nfc : $s;
        // Sequências de U+0020, \t, \n, \r viram um espaço; outros espaços Unicode (U+00A0) não.
        $colapsado = preg_replace('/[ \t\n\r]+/', ' ', $base);
        assert(is_string($colapsado));

        return trim($colapsado, ' ');
    }

    /**
     * Ordenar (§6.3): cada array listado em ordenar_arrays é ordenado pelos campos
     * declarados, comparando o texto canônico de cada valor por code point; campo
     * ausente vem antes de qualquer valor; empate desempata pelo texto canônico do
     * item inteiro. Arrays fora de ordenar_arrays mantêm a ordem.
     *
     * @param  array<string, list<string>>  $ordenarArrays
     */
    public static function ordenar(mixed $projecao, array $ordenarArrays): mixed
    {
        if (! $projecao instanceof \stdClass) {
            return $projecao;
        }
        foreach ($ordenarArrays as $campo => $por) {
            $atual = $projecao->{$campo} ?? null;
            if (! is_array($atual)) {
                continue;
            }
            $itens = array_values($atual);
            usort($itens, static fn (mixed $a, mixed $b): int => self::compararItens($a, $b, $por));
            $projecao->{$campo} = $itens;
        }

        return $projecao;
    }

    /**
     * @param  list<string>  $campos
     */
    private static function compararItens(mixed $a, mixed $b, array $campos): int
    {
        foreach ($campos as $campo) {
            $ca = self::chaveDeCampo($a, $campo);
            $cb = self::chaveDeCampo($b, $campo);
            // (0,'') = ausente, antes de (1, texto). Primeiro o marcador de presença.
            if ($ca[0] !== $cb[0]) {
                return $ca[0] <=> $cb[0];
            }
            $cmp = strcmp($ca[1], $cb[1]);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        // Empate nos campos declarados: desempata pelo texto canônico do item inteiro.
        return strcmp(HashCanonico::codificar($a), HashCanonico::codificar($b));
    }

    /**
     * Chave de ordenação de um campo do item: [0,''] se ausente (ou item não-objeto),
     * [1, textoCanonico] se presente.
     *
     * @return array{0: int, 1: string}
     */
    private static function chaveDeCampo(mixed $item, string $campo): array
    {
        if (! $item instanceof \stdClass || ! property_exists($item, $campo)) {
            return [0, ''];
        }

        return [1, HashCanonico::codificar($item->{$campo})];
    }
}
