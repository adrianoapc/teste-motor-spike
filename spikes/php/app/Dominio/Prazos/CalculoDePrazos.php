<?php

declare(strict_types=1);

namespace App\Dominio\Prazos;

use App\Dominio\Fechamento\Competencia;
use App\Dominio\Fechamento\Contexto;

/**
 * Cálculo de prazos de um entregável (§18.2), função pura das três regras (calendário, catálogo,
 * prazos) mais a competência e o contexto do caso. É o mesmo nas três spikes, até o dia.
 *
 * - Vencimento: a mais CEDO entre as obrigações de `vencimento_ref` que existem no catálogo e
 *   cujo `quando` casa com o contexto. Nenhuma se aplica → o entregável NÃO tem prazo (as três
 *   datas nulas, sem linha em entregavel_prazo).
 * - Meta: vencimento + dias_corridos; se não for útil, antecipa.
 * - Ação: a partir da meta, anda |dias_uteis| dias úteis para trás (0 = a própria meta).
 * - Garante acao ≤ meta ≤ vencimento, e as três nulas ou as três preenchidas.
 */
final readonly class CalculoDePrazos
{
    public function __construct(
        private Calendario $calendario,
        private CatalogoObrigacoes $catalogo,
        private RegraPrazos $prazos,
    ) {}

    /** Datas do tipo de entregável, ou null quando o tipo não tem prazo. */
    public function para(string $tipo, Competencia $competencia, Contexto $ctx): ?Prazo
    {
        if (! $this->prazos->item_tem_prazo($tipo)) {
            return null;
        }

        $vencimento = $this->vencimento($tipo, $competencia, $ctx);
        if ($vencimento === null) {
            return null; // nenhuma obrigação se aplica
        }

        $diasCorridosMeta = $this->prazos->diasCorridosMeta($tipo, $ctx) ?? 0;
        $meta = $this->calendario->antecipa($vencimento->maisDias($diasCorridosMeta));

        $diasUteisAcao = $this->prazos->diasUteisAcao($tipo, $ctx) ?? 0;
        $acao = $this->calendario->recuarDiasUteis($meta, abs($diasUteisAcao));

        return new Prazo($vencimento, $meta, $acao);
    }

    /** O estado de cumprimento do tipo (padrão 'validado'; E11 = 'disponibilizado'), §18.4. */
    public function estadoCumprimento(string $tipo): string
    {
        return $this->prazos->estadoCumprimento($tipo);
    }

    /** Se o tipo gera multa (§18.4). */
    public function geraMulta(string $tipo): bool
    {
        return $this->prazos->geraMulta($tipo);
    }

    /** A data de vencimento mais cedo entre as obrigações aplicáveis de vencimento_ref. */
    private function vencimento(string $tipo, Competencia $competencia, Contexto $ctx): ?Data
    {
        $maisCedo = null;
        foreach ($this->prazos->vencimentoRef($tipo) as $codigo) {
            $d = $this->catalogo->dataDe($codigo, $competencia, $ctx, $this->calendario);
            if ($d === null) {
                continue;
            }
            if ($maisCedo === null || $d->menorQue($maisCedo)) {
                $maisCedo = $d;
            }
        }

        return $maisCedo;
    }
}
