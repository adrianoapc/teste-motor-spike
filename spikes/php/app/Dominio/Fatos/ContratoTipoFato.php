<?php

declare(strict_types=1);

namespace App\Dominio\Fatos;

/**
 * Contrato ativo de um tipo de fato (§6.1, v5). Imutável, lido do banco a cada
 * publicação (nada em memória entre requisições, §13). A fonte da verdade são os
 * arquivos contrato/tipos-fato/<tipo>.json, espelhados pela V009 em
 * fatos.tipo_fato_versao; aqui fica só a versão `ativa` em uso agora.
 *
 * @phpstan-type Qualificador array{usa: bool, valores: list<string>}
 */
final readonly class ContratoTipoFato
{
    /**
     * @param  object  $esquema  JSON Schema draft 2020-12 do payload (stdClass; {} preservado)
     * @param  Qualificador  $qualificador  regra da coluna tributo
     * @param  'obrigatorio'|'opcional'|'proibido'  $valorCentavos  regra do valor_centavos
     * @param  list<string>  $camposHash  campos de primeiro nível que entram no hash (§6.3)
     * @param  array<string, list<string>>  $ordenarArrays  arrays de campos_hash cuja ordem não importa
     * @param  list<string>  $fontesPermitidas  fontes que podem publicar o tipo
     */
    public function __construct(
        public string $tipo,
        public int $versao,
        public object $esquema,
        public array $qualificador,
        public string $valorCentavos,
        public array $camposHash,
        public array $ordenarArrays,
        public array $fontesPermitidas,
    ) {}

    public function usaTributo(): bool
    {
        return $this->qualificador['usa'];
    }

    /** @return list<string> */
    public function valoresDeTributo(): array
    {
        return $this->qualificador['valores'];
    }

    public function fontePermitida(string $fonte): bool
    {
        return in_array($fonte, $this->fontesPermitidas, true);
    }
}
