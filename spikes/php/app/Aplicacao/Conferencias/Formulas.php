<?php

declare(strict_types=1);

namespace App\Aplicacao\Conferencias;

use App\Dominio\Fatos\Fato;
use App\Dominio\Fechamento\Dinheiro;

/**
 * Fórmulas das conferências do spike (§9.1). Funções que recebem os fatos
 * vigentes do caso já lidos e devolvem esperado/obtido/fatos_usados.
 *
 * "apurado[X]" = fato tipo 'apurado', tributo 'X'. Dinheiro em centavos (int).
 */
final readonly class Formulas
{
    public function __construct(private int $prolaborePercentualBp) {}

    /** CF-01 (E01): esperado = faturamento_mes.valor; obtido = notas_oneflow.valor. */
    public function cf01(FatosDoCaso $f): ResultadoFormula
    {
        $fat = $f->de('faturamento_mes');
        $notas = $f->de('notas_oneflow');
        if ($fat === null) {
            return ResultadoFormula::fatoAusente('faturamento_mes[]');
        }
        if ($notas === null) {
            return ResultadoFormula::fatoAusente('notas_oneflow[]');
        }

        return new ResultadoFormula($fat->valorCentavos, $notas->valorCentavos, $this->usados($fat, $notas));
    }

    /** CF-04 (E03, SN): esperado = arred(faturamento_mes.valor × aliquota_bp ÷ 10000); obtido = apurado[DAS].valor. */
    public function cf04(FatosDoCaso $f): ResultadoFormula
    {
        $fat = $f->de('faturamento_mes');
        $aliq = $f->de('aliquota_mes');
        $das = $f->de('apurado', 'DAS');
        if ($fat === null) {
            return ResultadoFormula::fatoAusente('faturamento_mes[]');
        }
        if ($aliq === null) {
            return ResultadoFormula::fatoAusente('aliquota_mes[]');
        }
        if ($das === null) {
            return ResultadoFormula::fatoAusente('apurado[DAS]');
        }
        $aliquotaBp = $this->inteiroDoPayload($aliq, 'aliquota_bp');
        if ($aliquotaBp === null || $fat->valorCentavos === null) {
            return ResultadoFormula::fatoAusente('aliquota_mes.payload.aliquota_bp');
        }
        $esperado = Dinheiro::arredMeioPar($fat->valorCentavos * $aliquotaBp, 10000);

        return new ResultadoFormula($esperado, $das->valorCentavos, $this->usados($fat, $aliq, $das));
    }

    /** CF-05 (E11): esperado = apurado[T].valor; obtido = guia[T].valor, T = tributo da guia. */
    public function cf05(FatosDoCaso $f, string $tributoGuia): ResultadoFormula
    {
        $apurado = $f->de('apurado', $tributoGuia);
        $guia = $f->de('guia', $tributoGuia);
        if ($apurado === null) {
            return ResultadoFormula::fatoAusente("apurado[{$tributoGuia}]");
        }
        if ($guia === null) {
            return ResultadoFormula::fatoAusente("guia[{$tributoGuia}]");
        }

        return new ResultadoFormula($apurado->valorCentavos, $guia->valorCentavos, $this->usados($apurado, $guia));
    }

    /** CF-06 (E11): ok se guia[T].payload.competencia_impressa = competência do caso. Texto. */
    public function cf06(FatosDoCaso $f, string $tributoGuia, string $competenciaCaso): ResultadoFormula
    {
        $guia = $f->de('guia', $tributoGuia);
        if ($guia === null) {
            return ResultadoFormula::fatoAusente("guia[{$tributoGuia}]");
        }
        $impressa = $this->textoDoPayload($guia, 'competencia_impressa');
        $igual = $impressa === $competenciaCaso;

        return new ResultadoFormula(
            esperadoCentavos: null,
            obtidoCentavos: null,
            fatosUsados: $this->usados($guia),
            comparacaoTexto: true,
            textoIgual: $igual,
            diferencaExtra: ['esperada' => $competenciaCaso, 'impressa' => $impressa],
        );
    }

    /** CF-07 (E04): esperado = apurado[DAS ou IRPJ].valor; obtido = soma(divisao_socios.payload.parcelas[].valor_centavos). */
    public function cf07(FatosDoCaso $f, string $tributoApuracao): ResultadoFormula
    {
        $apurado = $f->de('apurado', $tributoApuracao);
        $divisao = $f->de('divisao_socios');
        if ($apurado === null) {
            return ResultadoFormula::fatoAusente("apurado[{$tributoApuracao}]");
        }
        if ($divisao === null) {
            return ResultadoFormula::fatoAusente('divisao_socios[]');
        }
        $soma = $this->somaParcelas($divisao);

        return new ResultadoFormula($apurado->valorCentavos, $soma, $this->usados($apurado, $divisao));
    }

    /** CF-08 (E07, SN): esperado = arred(faturamento_mes.valor × prolabore_percentual_bp ÷ 10000); obtido = prolabore.valor. */
    public function cf08(FatosDoCaso $f): ResultadoFormula
    {
        $fat = $f->de('faturamento_mes');
        $prolabore = $f->de('prolabore');
        if ($fat === null) {
            return ResultadoFormula::fatoAusente('faturamento_mes[]');
        }
        if ($prolabore === null) {
            return ResultadoFormula::fatoAusente('prolabore[]');
        }
        if ($fat->valorCentavos === null) {
            return ResultadoFormula::fatoAusente('faturamento_mes.valor');
        }
        $esperado = Dinheiro::arredMeioPar($fat->valorCentavos * $this->prolaborePercentualBp, 10000);

        return new ResultadoFormula($esperado, $prolabore->valorCentavos, $this->usados($fat, $prolabore));
    }

    /**
     * CF-09 (E08): para INSS e FGTS: esperado = folha.payload.<tributo>_centavos; obtido = guia[tributo].valor.
     * A diferença é a MAIOR das duas; esperado/obtido gravam o par do tributo de maior diferença (empate: INSS).
     */
    public function cf09(FatosDoCaso $f): ResultadoFormula
    {
        $folha = $f->de('folha');
        $guiaInss = $f->de('guia', 'INSS');
        $guiaFgts = $f->de('guia', 'FGTS');
        if ($folha === null) {
            return ResultadoFormula::fatoAusente('folha[]');
        }
        if ($guiaInss === null) {
            return ResultadoFormula::fatoAusente('guia[INSS]');
        }
        if ($guiaFgts === null) {
            return ResultadoFormula::fatoAusente('guia[FGTS]');
        }
        $espInss = $this->inteiroDoPayload($folha, 'inss_centavos');
        $espFgts = $this->inteiroDoPayload($folha, 'fgts_centavos');
        if ($espInss === null) {
            return ResultadoFormula::fatoAusente('folha.payload.inss_centavos');
        }
        if ($espFgts === null) {
            return ResultadoFormula::fatoAusente('folha.payload.fgts_centavos');
        }
        $obtInss = $guiaInss->valorCentavos ?? 0;
        $obtFgts = $guiaFgts->valorCentavos ?? 0;
        $difInss = abs($espInss - $obtInss);
        $difFgts = abs($espFgts - $obtFgts);

        // Par do tributo de maior diferença; empate → INSS.
        if ($difFgts > $difInss) {
            $esperado = $espFgts;
            $obtido = $obtFgts;
        } else {
            $esperado = $espInss;
            $obtido = $obtInss;
        }

        return new ResultadoFormula($esperado, $obtido, $this->usados($folha, $guiaInss, $guiaFgts));
    }

    /** CF-12 (E10): ok se existe recibo_obrigacao com o tributo exigido. diferenca = 0. */
    public function cf12(FatosDoCaso $f, string $tributoRecibo): ResultadoFormula
    {
        $recibo = $f->de('recibo_obrigacao', $tributoRecibo);
        if ($recibo === null) {
            return ResultadoFormula::fatoAusente("recibo_obrigacao[{$tributoRecibo}]");
        }

        // ok: existe. esperado/obtido ambos 0 → diferença 0.
        return new ResultadoFormula(0, 0, $this->usados($recibo));
    }

    private function somaParcelas(Fato $divisao): int
    {
        $payload = $divisao->payload;
        $soma = 0;
        if (is_object($payload) && isset($payload->parcelas) && is_array($payload->parcelas)) {
            foreach ($payload->parcelas as $parcela) {
                if (is_object($parcela) && isset($parcela->valor_centavos) && is_int($parcela->valor_centavos)) {
                    $soma += $parcela->valor_centavos;
                }
            }
        }

        return $soma;
    }

    private function inteiroDoPayload(Fato $fato, string $campo): ?int
    {
        $payload = $fato->payload;
        if (is_object($payload) && isset($payload->{$campo}) && is_int($payload->{$campo})) {
            return $payload->{$campo};
        }

        return null;
    }

    private function textoDoPayload(Fato $fato, string $campo): string
    {
        $payload = $fato->payload;
        if (is_object($payload) && isset($payload->{$campo}) && is_string($payload->{$campo})) {
            return $payload->{$campo};
        }

        return '';
    }

    /**
     * fatos_usados = [{fato_id, versao}] ordenado por fato_id (§9.2).
     *
     * @return list<array{fato_id: string, versao: int}>
     */
    private function usados(Fato ...$fatos): array
    {
        $lista = array_map(
            static fn (Fato $x): array => ['fato_id' => $x->id, 'versao' => $x->versao],
            $fatos,
        );
        usort($lista, static fn (array $a, array $b): int => strcmp($a['fato_id'], $b['fato_id']));

        return $lista;
    }
}
