<?php

declare(strict_types=1);

namespace Tests\Unidade;

use App\Dominio\Fechamento\Competencia;
use App\Dominio\Fechamento\Contexto;
use App\Dominio\Prazos\CalculoDePrazos;
use App\Dominio\Prazos\Calendario;
use App\Dominio\Prazos\CatalogoObrigacoes;
use App\Dominio\Prazos\RegraPrazos;
use PHPUnit\Framework\TestCase;

/**
 * Cálculo de datas de prazo (§18.2), função pura do domínio — requisito 25.2.
 *
 * Vetores do próprio §18.2 (exemplo do C27): competência 202610, Simples Nacional com folha,
 * feriados 02/11, 15/11 e 20/11/2026. Esperado:
 *   SN-01 = 20/11 (sexta, feriado) → posterga → 23/11;
 *   PJ-02/03/04 = 20/11 → antecipa → 19/11.
 *   E03: vencimento 23/11, meta 18/11, ação 16/11.
 *   E08: vencimento 19/11, meta 13/11 (14/11 é sábado → 13/11), ação 11/11.
 *   E11: vencimento 19/11, meta 13/11, ação 11/11.
 */
final class CalculoDePrazosTest extends TestCase
{
    // Regras exatamente como na V010 (só os campos que o cálculo usa).
    private const FERIADOS = ['2026-11-02', '2026-11-15', '2026-11-20'];

    private function catalogo(): CatalogoObrigacoes
    {
        return CatalogoObrigacoes::de(['obrigacoes' => [
            ['codigo' => 'SN-01', 'quando' => [['regime' => 'SN']], 'vencimento' => ['meses_apos' => 1, 'dia' => 20, 'dia_nao_util' => 'posterga']],
            ['codigo' => 'LP-01', 'quando' => [['regime' => 'LP', 'mes_do_trimestre' => 3]], 'vencimento' => ['meses_apos' => 1, 'dia' => 'ultimo_dia_util']],
            ['codigo' => 'LP-02', 'quando' => [['regime' => 'LP', 'mes_do_trimestre' => 3]], 'vencimento' => ['meses_apos' => 1, 'dia' => 'ultimo_dia_util']],
            ['codigo' => 'LP-03', 'quando' => [['regime' => 'LP']], 'vencimento' => ['meses_apos' => 1, 'dia' => 25, 'dia_nao_util' => 'antecipa']],
            ['codigo' => 'LP-04', 'quando' => [['regime' => 'LP']], 'vencimento' => ['meses_apos' => 1, 'dia' => 25, 'dia_nao_util' => 'antecipa']],
            ['codigo' => 'LP-05', 'quando' => [['regime' => 'LP']], 'vencimento' => ['meses_apos' => 1, 'dia' => 'ultimo_dia_util']],
            ['codigo' => 'PJ-02', 'quando' => [['tem_folha' => true], ['tem_prolabore' => true]], 'vencimento' => ['meses_apos' => 1, 'dia' => 20, 'dia_nao_util' => 'antecipa']],
            ['codigo' => 'PJ-03', 'quando' => [['tem_folha' => true]], 'vencimento' => ['meses_apos' => 1, 'dia' => 20, 'dia_nao_util' => 'antecipa']],
            ['codigo' => 'PJ-04', 'quando' => [['tem_folha' => true]], 'vencimento' => ['meses_apos' => 1, 'dia' => 20, 'dia_nao_util' => 'antecipa']],
        ]]);
    }

    private function prazos(): RegraPrazos
    {
        return RegraPrazos::de([
            'padrao' => ['meta' => [['dias_corridos' => -5]], 'acao' => [['dias_uteis' => -2]], 'estado_cumprimento' => 'validado'],
            'entregaveis' => [
                ['tipo' => 'E03', 'vencimento_ref' => ['SN-01', 'LP-01', 'LP-02', 'LP-03', 'LP-04'], 'gera_multa' => true],
                ['tipo' => 'E08', 'vencimento_ref' => ['PJ-02', 'PJ-03', 'PJ-04'], 'gera_multa' => true],
                ['tipo' => 'E10', 'vencimento_ref' => ['SN-01', 'LP-05'], 'gera_multa' => true],
                ['tipo' => 'E11', 'vencimento_ref' => ['SN-01', 'LP-01', 'LP-02', 'LP-03', 'LP-04', 'PJ-02', 'PJ-03', 'PJ-04'], 'gera_multa' => true, 'estado_cumprimento' => 'disponibilizado'],
            ],
        ]);
    }

    private function calculo(): CalculoDePrazos
    {
        return new CalculoDePrazos(new Calendario(self::FERIADOS, []), $this->catalogo(), $this->prazos());
    }

    private function ctxSimplesComFolha(): Contexto
    {
        return Contexto::de(['regime' => 'SN', 'tem_folha' => true, 'tem_prolabore' => true], new Competencia('202610'));
    }

    public function test_e03_simples_com_folha(): void
    {
        $p = $this->calculo()->para('E03', new Competencia('202610'), $this->ctxSimplesComFolha());
        $this->assertNotNull($p);
        $this->assertSame('2026-11-23', $p->vencimento->iso()); // SN-01: 20/11 feriado → posterga → 23/11
        $this->assertSame('2026-11-18', $p->meta->iso());       // 23 − 5 = 18/11 (quarta, útil)
        $this->assertSame('2026-11-16', $p->acao->iso());       // 18 − 2 úteis = 16/11 (segunda)
    }

    public function test_e08_simples_com_folha(): void
    {
        $p = $this->calculo()->para('E08', new Competencia('202610'), $this->ctxSimplesComFolha());
        $this->assertNotNull($p);
        $this->assertSame('2026-11-19', $p->vencimento->iso()); // PJ-02/03/04: 20/11 feriado → antecipa → 19/11
        $this->assertSame('2026-11-13', $p->meta->iso());       // 19 − 5 = 14/11 (sábado) → antecipa → 13/11
        $this->assertSame('2026-11-11', $p->acao->iso());       // 13 − 2 úteis = 11/11 (12 e 11 úteis)
    }

    public function test_e11_simples_com_folha(): void
    {
        $p = $this->calculo()->para('E11', new Competencia('202610'), $this->ctxSimplesComFolha());
        $this->assertNotNull($p);
        $this->assertSame('2026-11-19', $p->vencimento->iso()); // mais cedo entre SN-01 (23) e PJ-* (19) = 19
        $this->assertSame('2026-11-13', $p->meta->iso());
        $this->assertSame('2026-11-11', $p->acao->iso());
    }

    public function test_tipo_fora_da_lista_nao_tem_prazo(): void
    {
        $this->assertNull($this->calculo()->para('E01', new Competencia('202610'), $this->ctxSimplesComFolha()));
    }

    public function test_tipo_com_prazo_mas_sem_obrigacao_aplicavel_nao_tem_prazo(): void
    {
        // Simples puro (sem folha): E08 só tem PJ-* (tem_folha/tem_prolabore) → nenhuma casa → sem prazo.
        $ctx = Contexto::de(['regime' => 'SN', 'tem_folha' => false, 'tem_prolabore' => false], new Competencia('202610'));
        $this->assertNull($this->calculo()->para('E08', new Competencia('202610'), $ctx));
    }

    public function test_ordem_das_tres_datas(): void
    {
        foreach (['E03', 'E08', 'E11'] as $tipo) {
            $p = $this->calculo()->para($tipo, new Competencia('202610'), $this->ctxSimplesComFolha());
            $this->assertNotNull($p);
            $this->assertTrue(! $p->vencimento->menorQue($p->meta), "vencimento ≥ meta ($tipo)");
            $this->assertTrue(! $p->meta->menorQue($p->acao), "meta ≥ ação ($tipo)");
        }
    }

    public function test_ultimo_dia_util_do_mes(): void
    {
        // LP no 3º mês do trimestre: LP-01/02/05 usam ultimo_dia_util de 202611 = 30/11 (segunda, útil).
        $ctx = Contexto::de(['regime' => 'LP', 'tem_folha' => false, 'tem_prolabore' => false], new Competencia('202609'));
        $p = $this->calculo()->para('E10', new Competencia('202609'), $ctx); // E10: SN-01 (não casa, regime LP) ∪ LP-05
        $this->assertNotNull($p);
        $this->assertSame('2026-10-30', $p->vencimento->iso()); // ultimo_dia_util de 202610 = 31/10 sábado → 30/10 sexta
    }
}
