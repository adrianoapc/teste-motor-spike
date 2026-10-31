<?php

declare(strict_types=1);

namespace App\Dominio\Prazos;

use App\Dominio\Fechamento\Competencia;
use App\Dominio\Fechamento\Condicao;
use App\Dominio\Fechamento\Contexto;

/**
 * Catálogo de obrigações (§18.1/§18.2, v6), função pura. Cada obrigação tem `codigo`, `quando`
 * (§3) e uma regra de `vencimento`. A data da obrigação para a competência do caso segue §18.2:
 *   1. prorrogação com a mesma obrigação e competência → nova_data, sem ajuste;
 *   2. senão, mês-base = competência + meses_apos;
 *   3. dia numérico: min(dia, último do mês-base); se não for útil, antecipa ou posterga;
 *   4. dia = "ultimo_dia_util": último dia do mês-base; se não for útil, antecipa.
 */
final class CatalogoObrigacoes
{
    /** @var array<string, Obrigacao> por código */
    private array $obrigacoes = [];

    /**
     * @param  list<Obrigacao>  $obrigacoes
     */
    public function __construct(array $obrigacoes)
    {
        foreach ($obrigacoes as $o) {
            $this->obrigacoes[$o->codigo] = $o;
        }
    }

    /**
     * @param  array<string, mixed>  $conteudo
     */
    public static function de(array $conteudo): self
    {
        $lista = [];
        $itens = $conteudo['obrigacoes'] ?? null;
        if (is_array($itens)) {
            foreach ($itens as $i) {
                if (is_array($i)) {
                    /** @var array<string, mixed> $i */
                    $lista[] = Obrigacao::de($i);
                }
            }
        }

        return new self($lista);
    }

    public function obrigacao(string $codigo): ?Obrigacao
    {
        return $this->obrigacoes[$codigo] ?? null;
    }

    /**
     * Data da obrigação $codigo para o caso (§18.2). Null se a obrigação não existe no catálogo
     * ou seu `quando` não casa com o contexto do caso.
     */
    public function dataDe(string $codigo, Competencia $competencia, Contexto $ctx, Calendario $calendario): ?Data
    {
        $o = $this->obrigacoes[$codigo] ?? null;
        if ($o === null || ! Condicao::casa($o->quando, $ctx)) {
            return null;
        }

        // 1. Prorrogação tem prioridade e não sofre ajuste.
        $prorrogada = $calendario->prorrogacao($codigo, $competencia->valor);
        if ($prorrogada !== null) {
            return $prorrogada;
        }

        // 2. Mês-base = competência + meses_apos.
        $base = $competencia->somar($o->mesesApos);

        // 3/4. Dia da regra, com o ajuste de dia não útil.
        if ($o->dia === 'ultimo_dia_util') {
            return $calendario->antecipa(Data::primeiroDiaDoMes($base->ano, $base->mes)->ultimoDiaDoMes());
        }

        $d = Data::diaDoMesLimitado($base->ano, $base->mes, $o->dia);
        if ($calendario->ehDiaUtil($d)) {
            return $d;
        }

        return $o->diaNaoUtil === 'posterga' ? $calendario->posterga($d) : $calendario->antecipa($d);
    }
}
