<?php

declare(strict_types=1);

namespace App\Aplicacao\Fatos;

use App\Dominio\Fatos\Fato;

/**
 * Resultado de uma publicação pelo núcleo (PublicacaoDeFato): o fato de referência
 * (criado em novo/nova_versao; a vigente nos demais), o efeito (§6.4), o id da versão
 * anterior (para a invalidação em nova_versao; null quando não há) e `repetido`
 * (§6.5: requisição idempotente já recebida — nada foi gravado).
 */
final readonly class Publicacao
{
    public function __construct(
        public Fato $fato,
        public string $efeito,
        public ?string $fatoAnteriorId = null,
        public bool $repetido = false,
    ) {}
}
