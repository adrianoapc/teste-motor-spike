<?php

declare(strict_types=1);

namespace App\Dominio\Prazos;

use App\Dominio\Fechamento\Contexto;

/**
 * Regra `fechamento.prazos` (§18.1), FIXADA no caso (regra_prazos_versao_id). Tem um `padrao`
 * (meta, ação, estado_cumprimento) e um item por tipo de entregável com prazo. `meta` e `acao`
 * são listas de {quando?, dias_corridos} / {quando?, dias_uteis}: vale o primeiro item cujo
 * `quando` casa (ausente = sempre). Item do tipo pode sobrepor `meta`, `acao` e
 * `estado_cumprimento` do padrão. Tipo fora da lista não tem prazo.
 */
final class RegraPrazos
{
    /**
     * @param  array<string, ItemPrazo>  $itens  por tipo de entregável
     */
    public function __construct(
        private readonly ItemPrazo $padrao,
        private readonly array $itens,
    ) {}

    /**
     * @param  array<string, mixed>  $conteudo
     */
    public static function de(array $conteudo): self
    {
        /** @var array<string, mixed> $padraoBruto */
        $padraoBruto = is_array($conteudo['padrao'] ?? null) ? $conteudo['padrao'] : [];
        $padrao = ItemPrazo::de($padraoBruto);

        $itens = [];
        $lista = $conteudo['entregaveis'] ?? null;
        if (is_array($lista)) {
            foreach ($lista as $i) {
                if (! is_array($i)) {
                    continue;
                }
                /** @var array<string, mixed> $i */
                $tipo = $i['tipo'] ?? null;
                if (is_string($tipo)) {
                    $itens[$tipo] = ItemPrazo::de($i);
                }
            }
        }

        return new self($padrao, $itens);
    }

    public function item(string $tipo): ?ItemPrazo
    {
        return isset($this->itens[$tipo]) ? $this->itens[$tipo] : null;
    }

    /** vencimento_ref do tipo (as obrigações candidatas ao vencimento). Vazio = sem prazo. */
    public function item_tem_prazo(string $tipo): bool
    {
        return isset($this->itens[$tipo]);
    }

    /** dias_corridos de `meta` do tipo, com o padrão como fallback (§18.1). */
    public function diasCorridosMeta(string $tipo, Contexto $ctx): ?int
    {
        $n = isset($this->itens[$tipo]) ? $this->itens[$tipo]->metaDiasCorridos($ctx) : null;

        return $n ?? $this->padrao->metaDiasCorridos($ctx);
    }

    /** dias_uteis de `acao` do tipo, com o padrão como fallback (§18.1). */
    public function diasUteisAcao(string $tipo, Contexto $ctx): ?int
    {
        $n = isset($this->itens[$tipo]) ? $this->itens[$tipo]->acaoDiasUteis($ctx) : null;

        return $n ?? $this->padrao->acaoDiasUteis($ctx);
    }

    /**
     * @return list<string> códigos de vencimento_ref do tipo
     */
    public function vencimentoRef(string $tipo): array
    {
        return isset($this->itens[$tipo]) ? $this->itens[$tipo]->vencimentoRef : [];
    }

    public function estadoCumprimento(string $tipo): string
    {
        $doItem = isset($this->itens[$tipo]) ? $this->itens[$tipo]->estadoCumprimento : null;

        return $doItem ?? $this->padrao->estadoCumprimento ?? 'validado';
    }

    public function geraMulta(string $tipo): bool
    {
        return isset($this->itens[$tipo]) && $this->itens[$tipo]->geraMulta;
    }
}
