<?php

declare(strict_types=1);

namespace App\Dominio\Prazos;

/**
 * Uma obrigação do catálogo (§18.1): código, condição `quando` e a regra de vencimento.
 * `dia` é um inteiro (1..31) ou a palavra 'ultimo_dia_util'. `diaNaoUtil` ('antecipa' |
 * 'posterga') só se aplica ao dia numérico; para 'ultimo_dia_util' o ajuste é sempre antecipar.
 */
final readonly class Obrigacao
{
    /**
     * @param  list<array<string, scalar>>  $quando
     * @param  int|'ultimo_dia_util'  $dia
     * @param  'antecipa'|'posterga'  $diaNaoUtil
     */
    public function __construct(
        public string $codigo,
        public array $quando,
        public int $mesesApos,
        public int|string $dia,
        public string $diaNaoUtil,
    ) {}

    /**
     * @param  array<string, mixed>  $i
     */
    public static function de(array $i): self
    {
        $quandoBruto = $i['quando'] ?? null;
        /** @var list<array<string, scalar>> $quando */
        $quando = is_array($quandoBruto) ? array_values(array_filter($quandoBruto, 'is_array')) : [];

        $vencBruto = $i['vencimento'] ?? null;
        /** @var array<string, mixed> $venc */
        $venc = is_array($vencBruto) ? $vencBruto : [];

        $diaBruto = $venc['dia'] ?? null;
        $dia = $diaBruto === 'ultimo_dia_util' ? 'ultimo_dia_util' : (is_numeric($diaBruto) ? (int) $diaBruto : 0);
        $diaNaoUtil = ($venc['dia_nao_util'] ?? null) === 'posterga' ? 'posterga' : 'antecipa';

        $codigo = $i['codigo'] ?? '';
        $mesesApos = $venc['meses_apos'] ?? 0;

        return new self(
            codigo: is_string($codigo) ? $codigo : '',
            quando: $quando,
            mesesApos: is_numeric($mesesApos) ? (int) $mesesApos : 0,
            dia: $dia,
            diaNaoUtil: $diaNaoUtil,
        );
    }
}
