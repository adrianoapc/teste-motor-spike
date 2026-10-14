<?php

declare(strict_types=1);

namespace App\Aplicacao\Conferencias;

use App\Aplicacao\Portas\CatalogoConferencia;
use App\Aplicacao\Portas\RepositorioCatalogo;
use App\Aplicacao\Portas\RepositorioConferencia;
use App\Dominio\Conferencias\HashConferencia;
use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\Condicao;
use App\Dominio\Fechamento\Dinheiro;
use App\Dominio\Fechamento\Entregavel;
use App\Dominio\Regras\ItemEntregavel;
use App\Dominio\Regras\Tolerancia;

/**
 * Roda as conferências de um entregável em 'processado' (§9) e devolve se
 * alguma CF bloqueante (B) divergiu — o chamador aplica o desfecho de estado.
 */
final readonly class Conferidor
{
    public function __construct(
        private RepositorioConferencia $conferencias,
        private RepositorioCatalogo $catalogo,
    ) {}

    /**
     * Grava as conferências do item cujo `quando` casa. Devolve true se o
     * entregável deve ir a 'divergente' (alguma B divergente sem override).
     */
    public function conferir(
        Caso $caso,
        Entregavel $entregavel,
        ItemEntregavel $item,
        FatosDoCaso $fatos,
        Formulas $formulas,
        Tolerancia $tolerancia,
        string $tributoGuia,
        string $tributoApuracao,
    ): bool {
        $ctx = $caso->contexto();

        foreach ($item->conferencias as $cfRegra) {
            if (! Condicao::casa($cfRegra->quando, $ctx)) {
                continue;
            }

            $cat = $this->catalogo->conferenciaTipo($cfRegra->cf);
            $resultado = $this->calcular($cfRegra->cf, $formulas, $fatos, $tributoGuia, $tributoApuracao, $caso->competencia->valor);

            $this->gravar($caso, $entregavel, $cfRegra->cf, $cat, $resultado, $tolerancia);
        }

        return $this->conferencias->cfsDivergentesBloqueantes($entregavel->id) !== [];
    }

    private function calcular(
        string $cf,
        Formulas $formulas,
        FatosDoCaso $fatos,
        string $tributoGuia,
        string $tributoApuracao,
        string $competenciaCaso,
    ): ResultadoFormula {
        return match ($cf) {
            'CF-01' => $formulas->cf01($fatos),
            'CF-04' => $formulas->cf04($fatos),
            'CF-05' => $formulas->cf05($fatos, $tributoGuia),
            'CF-06' => $formulas->cf06($fatos, $tributoGuia, $competenciaCaso),
            'CF-07' => $formulas->cf07($fatos, $tributoApuracao),
            'CF-08' => $formulas->cf08($fatos),
            'CF-09' => $formulas->cf09($fatos),
            'CF-12' => $formulas->cf12($fatos, $tributoGuia === 'DAS' ? 'PGDAS-D' : 'DCTFWEB'),
            default => ResultadoFormula::fatoAusente("cf desconhecida: {$cf}"),
        };
    }

    private function gravar(
        Caso $caso,
        Entregavel $entregavel,
        string $cf,
        CatalogoConferencia $cat,
        ResultadoFormula $r,
        Tolerancia $tolerancia,
    ): void {
        $entradaHash = HashConferencia::entradaHash($cf, $r->fatosUsados, $tolerancia->versaoId, HashConferencia::SEMANTICA_CALCULO);

        // Fato ausente → divergente, classe S (§9.2).
        if ($r->fatoAusente !== null) {
            $this->conferencias->gravarSeNova(
                $cf, $caso->id, $entregavel->id, $tolerancia->versaoId, $r->fatosUsados, $entradaHash,
                'divergente', null, null, ['erro' => "fato ausente: {$r->fatoAusente}"], $cat->severidade, 'S',
            );

            return;
        }

        // CF-06 (texto) e CF-12 (existência): sem valores em centavos (esperado/obtido NULL).
        if ($r->comparacaoTexto) {
            $resultado = $r->textoIgual ? 'ok' : 'divergente';
            $this->conferencias->gravarSeNova(
                $cf, $caso->id, $entregavel->id, $tolerancia->versaoId, $r->fatosUsados, $entradaHash,
                $resultado, null, null, $r->diferencaExtra, $cat->severidade, $cat->classePadrao,
            );

            return;
        }

        // Numérica: classifica pela tolerância (§9.2).
        // Operando nulo nunca vira zero: é dado ausente (classe S), não "ok".
        if ($r->esperadoCentavos === null || $r->obtidoCentavos === null) {
            $this->conferencias->gravarSeNova(
                $cf, $caso->id, $entregavel->id, $tolerancia->versaoId, $r->fatosUsados, $entradaHash,
                'divergente', null, null, ['erro' => 'fato ausente: valor nulo'], $cat->severidade, 'S',
            );

            return;
        }
        $esperado = $r->esperadoCentavos;
        $obtido = $r->obtidoCentavos;
        $diferenca = Dinheiro::diferencaExata($esperado, $obtido);
        if (is_string($diferenca)) {
            // Diferença acima de int64: maior que qualquer tolerância => divergência numérica válida,
            // severidade e classe do catálogo; a diferença exata é gravada como número (jsonb).
            $this->conferencias->gravarSeNova(
                $cf, $caso->id, $entregavel->id, $tolerancia->versaoId, $r->fatosUsados, $entradaHash,
                'divergente', $r->esperadoCentavos, $r->obtidoCentavos, ['centavos' => $diferenca], $cat->severidade, $cat->classePadrao,
            );

            return;
        }

        if ($diferenca <= $tolerancia->okAteCentavos) {
            $resultado = 'ok';
            $severidade = $cat->severidade;
        } elseif ($diferenca <= $tolerancia->alertaAteCentavos) {
            $resultado = 'divergente';
            $severidade = 'A';
        } else {
            $resultado = 'divergente';
            $severidade = $cat->severidade;
        }

        $this->conferencias->gravarSeNova(
            $cf, $caso->id, $entregavel->id, $tolerancia->versaoId, $r->fatosUsados, $entradaHash,
            $resultado, $r->esperadoCentavos, $r->obtidoCentavos, ['centavos' => $diferenca], $severidade, $cat->classePadrao,
        );
    }
}
