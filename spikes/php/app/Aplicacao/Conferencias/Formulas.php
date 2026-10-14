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
        $usados = $this->usadosExistentes($fat, $notas);
        if ($fat === null) {
            return ResultadoFormula::fatoAusente('faturamento_mes[]', $usados);
        }
        if ($notas === null) {
            return ResultadoFormula::fatoAusente('notas_oneflow[]', $usados);
        }
        if ($fat->valorCentavos === null) {
            return ResultadoFormula::fatoAusente('faturamento_mes.valor', $usados);
        }
        if ($notas->valorCentavos === null) {
            return ResultadoFormula::fatoAusente('notas_oneflow.valor', $usados);
        }

        return new ResultadoFormula($fat->valorCentavos, $notas->valorCentavos, $usados);
    }

    /** CF-04 (E03, SN): esperado = arred(faturamento_mes.valor × aliquota_bp ÷ 10000); obtido = apurado[DAS].valor. */
    public function cf04(FatosDoCaso $f): ResultadoFormula
    {
        $fat = $f->de('faturamento_mes');
        $aliq = $f->de('aliquota_mes');
        $das = $f->de('apurado', 'DAS');
        $usados = $this->usadosExistentes($fat, $aliq, $das);
        if ($fat === null) {
            return ResultadoFormula::fatoAusente('faturamento_mes[]', $usados);
        }
        if ($aliq === null) {
            return ResultadoFormula::fatoAusente('aliquota_mes[]', $usados);
        }
        if ($das === null) {
            return ResultadoFormula::fatoAusente('apurado[DAS]', $usados);
        }
        $aliquotaBp = $this->inteiroDoPayload($aliq, 'aliquota_bp');
        if ($aliquotaBp === null) {
            return ResultadoFormula::fatoAusente('aliquota_mes.payload.aliquota_bp', $usados);
        }
        if ($fat->valorCentavos === null) {
            return ResultadoFormula::fatoAusente('faturamento_mes.valor', $usados);
        }
        if ($das->valorCentavos === null) {
            return ResultadoFormula::fatoAusente('apurado[DAS].valor', $usados);
        }
        $esperado = Dinheiro::multiplicarDividirArredMeioPar($fat->valorCentavos, $aliquotaBp, 10000);
        if ($esperado === null) {
            return ResultadoFormula::fatoAusente('faturamento_mes.valor × aliquota_bp fora da faixa int64', $usados);
        }

        return new ResultadoFormula($esperado, $das->valorCentavos, $usados);
    }

    /** CF-05 (E11): esperado = apurado[T].valor; obtido = guia[T].valor, T = tributo da guia. */
    public function cf05(FatosDoCaso $f, string $tributoGuia): ResultadoFormula
    {
        $apurado = $f->de('apurado', $tributoGuia);
        $guia = $f->de('guia', $tributoGuia);
        $usados = $this->usadosExistentes($apurado, $guia);
        if ($apurado === null) {
            return ResultadoFormula::fatoAusente("apurado[{$tributoGuia}]", $usados);
        }
        if ($guia === null) {
            return ResultadoFormula::fatoAusente("guia[{$tributoGuia}]", $usados);
        }
        if ($apurado->valorCentavos === null) {
            return ResultadoFormula::fatoAusente("apurado[{$tributoGuia}].valor", $usados);
        }
        if ($guia->valorCentavos === null) {
            return ResultadoFormula::fatoAusente("guia[{$tributoGuia}].valor", $usados);
        }

        return new ResultadoFormula($apurado->valorCentavos, $guia->valorCentavos, $usados);
    }

    /** CF-06 (E11): ok se guia[T].payload.competencia_impressa = competência do caso. Texto. */
    public function cf06(FatosDoCaso $f, string $tributoGuia, string $competenciaCaso): ResultadoFormula
    {
        $guia = $f->de('guia', $tributoGuia);
        if ($guia === null) {
            return ResultadoFormula::fatoAusente("guia[{$tributoGuia}]");
        }
        $impressa = $this->textoDoPayload($guia, 'competencia_impressa');
        if ($impressa === null) {
            return ResultadoFormula::fatoAusente("guia[{$tributoGuia}].payload.competencia_impressa", $this->usados($guia));
        }
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
        $usados = $this->usadosExistentes($apurado, $divisao);
        if ($apurado === null) {
            return ResultadoFormula::fatoAusente("apurado[{$tributoApuracao}]", $usados);
        }
        if ($divisao === null) {
            return ResultadoFormula::fatoAusente('divisao_socios[]', $usados);
        }
        if ($apurado->valorCentavos === null) {
            return ResultadoFormula::fatoAusente("apurado[{$tributoApuracao}].valor", $usados);
        }
        $soma = $this->somaParcelas($divisao);
        if ($soma === null) {
            return ResultadoFormula::fatoAusente('divisao_socios.payload.parcelas[].valor_centavos', $usados);
        }

        return new ResultadoFormula($apurado->valorCentavos, $soma, $usados);
    }

    /** CF-08 (E07, SN): esperado = arred(faturamento_mes.valor × prolabore_percentual_bp ÷ 10000); obtido = prolabore.valor. */
    public function cf08(FatosDoCaso $f): ResultadoFormula
    {
        $fat = $f->de('faturamento_mes');
        $prolabore = $f->de('prolabore');
        $usados = $this->usadosExistentes($fat, $prolabore);
        if ($fat === null) {
            return ResultadoFormula::fatoAusente('faturamento_mes[]', $usados);
        }
        if ($prolabore === null) {
            return ResultadoFormula::fatoAusente('prolabore[]', $usados);
        }
        if ($fat->valorCentavos === null) {
            return ResultadoFormula::fatoAusente('faturamento_mes.valor', $usados);
        }
        if ($prolabore->valorCentavos === null) {
            return ResultadoFormula::fatoAusente('prolabore.valor', $usados);
        }
        $esperado = Dinheiro::multiplicarDividirArredMeioPar($fat->valorCentavos, $this->prolaborePercentualBp, 10000);
        if ($esperado === null) {
            return ResultadoFormula::fatoAusente('faturamento_mes.valor × prolabore_percentual_bp fora da faixa int64', $usados);
        }

        return new ResultadoFormula($esperado, $prolabore->valorCentavos, $usados);
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
        $usados = $this->usadosExistentes($folha, $guiaInss, $guiaFgts);
        if ($folha === null) {
            return ResultadoFormula::fatoAusente('folha[]', $usados);
        }
        if ($guiaInss === null) {
            return ResultadoFormula::fatoAusente('guia[INSS]', $usados);
        }
        if ($guiaFgts === null) {
            return ResultadoFormula::fatoAusente('guia[FGTS]', $usados);
        }
        $espInss = $this->inteiroDoPayload($folha, 'inss_centavos');
        $espFgts = $this->inteiroDoPayload($folha, 'fgts_centavos');
        if ($espInss === null) {
            return ResultadoFormula::fatoAusente('folha.payload.inss_centavos', $usados);
        }
        if ($espFgts === null) {
            return ResultadoFormula::fatoAusente('folha.payload.fgts_centavos', $usados);
        }
        $obtInss = $guiaInss->valorCentavos;
        $obtFgts = $guiaFgts->valorCentavos;
        if ($obtInss === null) {
            return ResultadoFormula::fatoAusente('guia[INSS].valor', $usados);
        }
        if ($obtFgts === null) {
            return ResultadoFormula::fatoAusente('guia[FGTS].valor', $usados);
        }
        $difInss = Dinheiro::diferencaExata($espInss, $obtInss);
        $difFgts = Dinheiro::diferencaExata($espFgts, $obtFgts);

        // Par do tributo de maior diferença (comparação exata, mesmo acima de int64); empate → INSS.
        if (Dinheiro::compararDiferencas($difFgts, $difInss) > 0) {
            $esperado = $espFgts;
            $obtido = $obtFgts;
        } else {
            $esperado = $espInss;
            $obtido = $obtInss;
        }

        return new ResultadoFormula($esperado, $obtido, $usados);
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

    /** Soma das parcelas; null se `parcelas` falta, não é lista ou alguma entrada é malformada (ou a soma estoura int64). */
    private function somaParcelas(Fato $divisao): ?int
    {
        $payload = $divisao->payload;
        if (! is_object($payload) || ! isset($payload->parcelas) || ! is_array($payload->parcelas)) {
            return null;
        }
        $soma = 0;
        foreach ($payload->parcelas as $parcela) {
            if (! is_object($parcela) || ! isset($parcela->valor_centavos) || ! is_int($parcela->valor_centavos)) {
                return null;
            }
            if (($parcela->valor_centavos > 0 && $soma > PHP_INT_MAX - $parcela->valor_centavos)
                || ($parcela->valor_centavos < 0 && $soma < PHP_INT_MIN - $parcela->valor_centavos)) {
                return null;
            }
            $soma += $parcela->valor_centavos;
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

    private function textoDoPayload(Fato $fato, string $campo): ?string
    {
        $payload = $fato->payload;
        if (is_object($payload) && isset($payload->{$campo}) && is_string($payload->{$campo})) {
            return $payload->{$campo};
        }

        return null;
    }

    /**
     * Como usados(), mas ignora os fatos que não existem (para resultados de dado ausente).
     *
     * @return list<array{fato_id: string, versao: int}>
     */
    private function usadosExistentes(?Fato ...$fatos): array
    {
        return $this->usados(...array_values(array_filter($fatos, static fn (?Fato $x): bool => $x !== null)));
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
