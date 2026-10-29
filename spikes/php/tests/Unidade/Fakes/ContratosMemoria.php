<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\RepositorioContratoTipoFato;
use App\Dominio\Fatos\ContratoTipoFato;

/**
 * Contratos de tipo de fato em memória (§6.1), espelhando as regras de qualificador e
 * valor_centavos da V009 para os tipos usados nos testes de unidade. O esquema é
 * permissivo (sem `required`, `additionalProperties`) — a validação de esquema tem
 * cobertura própria em VetoresFatosTest/integração; aqui o foco é a lógica de estado.
 */
final class ContratosMemoria implements RepositorioContratoTipoFato
{
    public function ativo(string $tipo): ContratoTipoFato
    {
        // Fake sempre tem contrato ativo para qualquer tipo (os testes de "tipo_desconhecido"
        // usam o caminho de integração/HTTP, não este fake). Retorno estreitado (covariante).
        [$usa, $valores, $valor, $camposHash, $ordenar, $fontes] = match ($tipo) {
            'faturamento_mes', 'notas_oneflow', 'prolabore' => [false, [], 'obrigatorio', [], [], ['teste', 'tarefa', 'intranet', 'oneflow']],
            'aliquota_mes' => [false, [], 'proibido', ['aliquota_bp'], [], ['teste', 'calculo']],
            'taxa_municipal' => [false, [], 'opcional', [], [], ['teste', 'intranet']],
            'apurado' => [true, ['IRPJ', 'CSLL', 'PIS', 'COFINS', 'DAS'], 'obrigatorio', [], [], ['teste', 'tarefa', 'equiparacao', 'intranet-legado']],
            'divisao_socios' => [false, [], 'proibido', ['parcelas'], ['parcelas' => ['socio', 'tributo']], ['teste', 'calculo', 'tarefa']],
            'guia' => [true, ['DAS', 'INSS', 'FGTS', 'IRRF', 'IRPJ', 'CSLL', 'PIS', 'COFINS'], 'obrigatorio', ['competencia_impressa', 'vencimento'], [], ['teste', 'porta_documental']],
            'folha' => [false, [], 'obrigatorio', ['inss_centavos', 'fgts_centavos', 'irrf_centavos'], [], ['teste', 'dp', 'tarefa']],
            'recibo_obrigacao' => [true, ['PGDAS-D', 'DCTFWEB'], 'proibido', ['numero_recibo'], [], ['teste', 'integra']],
            'documento_disponibilizado' => [true, ['DAS', 'INSS', 'FGTS', 'IRRF', 'IRPJ', 'CSLL', 'PIS', 'COFINS'], 'proibido', ['canal'], [], ['teste', 'entrega']],
            'entrega_confirmada' => [true, ['DAS', 'INSS', 'FGTS', 'IRRF', 'IRPJ', 'CSLL', 'PIS', 'COFINS'], 'proibido', ['canal', 'como'], [], ['teste', 'entrega']],
            'entrega_falhou' => [true, ['DAS', 'INSS', 'FGTS', 'IRRF', 'IRPJ', 'CSLL', 'PIS', 'COFINS'], 'proibido', ['canal', 'motivo'], [], ['teste', 'entrega']],
            'recebimento_pendente' => [true, ['DAS', 'INSS', 'FGTS', 'IRRF', 'IRPJ', 'CSLL', 'PIS', 'COFINS'], 'proibido', ['dias_sem_leitura'], [], ['teste', 'agendador']],
            default => [false, [], 'opcional', [], [], ['teste']],
        };

        return new ContratoTipoFato(
            tipo: $tipo,
            versao: 1,
            esquema: $this->esquemaPermissivo(),
            qualificador: ['usa' => $usa, 'valores' => $valores],
            valorCentavos: $valor,
            camposHash: $camposHash,
            ordenarArrays: $ordenar,
            fontesPermitidas: $fontes,
        );
    }

    private function esquemaPermissivo(): object
    {
        return (object) ['type' => 'object', 'additionalProperties' => true];
    }
}
