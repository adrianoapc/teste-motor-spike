<?php

declare(strict_types=1);

namespace App\Api;

use App\Aplicacao\Suporte\ErroDeNegocio;

/**
 * Parâmetros de caminho do contrato (components.parameters): TitularId (1..64),
 * Competencia (AAAAMM) e TipoEntregavel (enum). Inválido => 422, antes de qualquer 404.
 */
final class ParametrosDeRota
{
    private const TIPOS = ['E01', 'E02', 'E03', 'E04', 'E05', 'E06', 'E07', 'E08', 'E10', 'E11', 'E12'];

    public static function validar(string $titularId, string $competencia, ?string $tipo = null): void
    {
        // U+0000 em parâmetro de caminho: 422 corpo_invalido (§6; o Postgres não aceita NUL em texto).
        if (str_contains($titularId, "\0") || str_contains($competencia, "\0") || ($tipo !== null && str_contains($tipo, "\0"))) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'U+0000 não é aceito em parâmetro de caminho');
        }
        $tamanho = mb_strlen($titularId);
        if ($tamanho < 1 || $tamanho > 64) {
            throw ErroDeNegocio::invalido('parametro_invalido', 'titular_id deve ter de 1 a 64 caracteres');
        }
        if (preg_match('/^[0-9]{4}(0[1-9]|1[0-2])$/D', $competencia) !== 1) {
            throw ErroDeNegocio::invalido('parametro_invalido', 'competencia deve ser AAAAMM');
        }
        if ($tipo !== null && ! in_array($tipo, self::TIPOS, true)) {
            throw ErroDeNegocio::invalido('parametro_invalido', 'tipo de entregável desconhecido');
        }
    }
}
