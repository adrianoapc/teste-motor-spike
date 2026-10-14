<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

use InvalidArgumentException;

/**
 * Dados da empresa no momento da abertura do caso (contrato §Empresa). Vira o
 * `snapshot` do caso e alimenta o Contexto das condições `quando` (§3).
 */
final readonly class Empresa
{
    /** @param  'SN'|'LP'  $regime */
    public function __construct(
        public string $titularId,
        public string $regime,
        public bool $temFolha,
        public bool $temProlabore,
        public bool $temTaxaMunicipal,
        public bool $filial,
        public string $carteira,
        public string $municipio,
    ) {}

    /**
     * Mesma forma do contrato (chaves em snake_case): é o que se grava em caso_competencia.snapshot.
     *
     * @return array<string, scalar>
     */
    public function paraSnapshot(): array
    {
        return [
            'titular_id' => $this->titularId,
            'regime' => $this->regime,
            'tem_folha' => $this->temFolha,
            'tem_prolabore' => $this->temProlabore,
            'tem_taxa_municipal' => $this->temTaxaMunicipal,
            'filial' => $this->filial,
            'carteira' => $this->carteira,
            'municipio' => $this->municipio,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $snapshot
     */
    public static function deSnapshot(array $snapshot): self
    {
        $regime = $snapshot['regime'] ?? null;
        if ($regime !== 'SN' && $regime !== 'LP') {
            throw new InvalidArgumentException('snapshot da empresa sem regime SN|LP');
        }

        return new self(
            self::texto($snapshot, 'titular_id'),
            $regime,
            self::booleano($snapshot, 'tem_folha'),
            self::booleano($snapshot, 'tem_prolabore'),
            self::booleano($snapshot, 'tem_taxa_municipal'),
            self::booleano($snapshot, 'filial'),
            self::texto($snapshot, 'carteira'),
            self::texto($snapshot, 'municipio'),
        );
    }

    /** @param  array<array-key, mixed>  $snapshot */
    private static function texto(array $snapshot, string $chave): string
    {
        $valor = $snapshot[$chave] ?? null;
        if (! is_string($valor)) {
            throw new InvalidArgumentException("snapshot da empresa: {$chave} deve ser texto");
        }

        return $valor;
    }

    /** @param  array<array-key, mixed>  $snapshot */
    private static function booleano(array $snapshot, string $chave): bool
    {
        $valor = $snapshot[$chave] ?? null;
        if (! is_bool($valor)) {
            throw new InvalidArgumentException("snapshot da empresa: {$chave} deve ser booleano");
        }

        return $valor;
    }
}
