<?php

declare(strict_types=1);

namespace App\Dominio\Regras;

/**
 * Regra 'fechamento.entregaveis' em uso: um item por entregável, com
 * dependências, entradas, saída, conferências e pós-validação. Imutável.
 *
 * Guarda o conteúdo cru (array decodificado) e expõe acesso tipado.
 */
final readonly class RegraEntregaveis
{
    /**
     * @param  list<ItemEntregavel>  $itens
     */
    private function __construct(
        public string $versaoId,
        public int $versao,
        public string $estadoMinimoPadrao,
        public array $itens,
    ) {}

    /**
     * @param  array<string, mixed>  $conteudo  JSON decodificado da regra
     */
    public static function de(string $versaoId, int $versao, array $conteudo): self
    {
        $padrao = is_string($conteudo['estado_minimo_padrao'] ?? null)
            ? $conteudo['estado_minimo_padrao']
            : 'validado';

        $itensCru = is_array($conteudo['entregaveis'] ?? null) ? $conteudo['entregaveis'] : [];
        $itens = [];
        foreach ($itensCru as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $itens[] = ItemEntregavel::de($item, $padrao);
            }
        }

        return new self($versaoId, $versao, $padrao, $itens);
    }

    public function item(string $tipo): ?ItemEntregavel
    {
        foreach ($this->itens as $item) {
            if ($item->tipo === $tipo) {
                return $item;
            }
        }

        return null;
    }
}
