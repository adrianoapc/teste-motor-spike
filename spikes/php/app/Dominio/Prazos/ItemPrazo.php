<?php

declare(strict_types=1);

namespace App\Dominio\Prazos;

use App\Dominio\Fechamento\Condicao;
use App\Dominio\Fechamento\Contexto;

/**
 * Item de `fechamento.prazos` (§18.1): vale para o `padrao` e para cada tipo. `meta` e `acao`
 * são listas de {quando?, dias_corridos} / {quando?, dias_uteis}; vale o primeiro cujo `quando`
 * casa (ausente = sempre). `estadoCumprimento` é opcional (null = herdar o padrão).
 */
final readonly class ItemPrazo
{
    /**
     * @param  list<string>  $vencimentoRef
     * @param  list<array{quando?: list<array<string, scalar>>, dias_corridos?: int}>  $meta
     * @param  list<array{quando?: list<array<string, scalar>>, dias_uteis?: int}>  $acao
     */
    public function __construct(
        public array $vencimentoRef,
        public array $meta,
        public array $acao,
        public bool $geraMulta,
        public ?string $estadoCumprimento,
    ) {}

    /**
     * @param  array<string, mixed>  $i
     */
    public static function de(array $i): self
    {
        /** @var list<string> $ref */
        $ref = is_array($i['vencimento_ref'] ?? null) ? array_values(array_filter($i['vencimento_ref'], 'is_string')) : [];
        /** @var list<array{quando?: list<array<string, scalar>>, dias_corridos?: int}> $meta */
        $meta = is_array($i['meta'] ?? null) ? array_values(array_filter($i['meta'], 'is_array')) : [];
        /** @var list<array{quando?: list<array<string, scalar>>, dias_uteis?: int}> $acao */
        $acao = is_array($i['acao'] ?? null) ? array_values(array_filter($i['acao'], 'is_array')) : [];
        $estado = isset($i['estado_cumprimento']) && is_string($i['estado_cumprimento']) ? $i['estado_cumprimento'] : null;

        return new self(
            vencimentoRef: $ref,
            meta: $meta,
            acao: $acao,
            geraMulta: (bool) ($i['gera_multa'] ?? false),
            estadoCumprimento: $estado,
        );
    }

    public function metaDiasCorridos(Contexto $ctx): ?int
    {
        foreach ($this->meta as $regra) {
            if (Condicao::casa($regra['quando'] ?? [], $ctx)) {
                return (int) ($regra['dias_corridos'] ?? 0);
            }
        }

        return null;
    }

    public function acaoDiasUteis(Contexto $ctx): ?int
    {
        foreach ($this->acao as $regra) {
            if (Condicao::casa($regra['quando'] ?? [], $ctx)) {
                return (int) ($regra['dias_uteis'] ?? 0);
            }
        }

        return null;
    }
}
