<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioContratoTipoFato;
use App\Dominio\Fatos\ContratoTipoFato;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioContratoTipoFatoDb implements RepositorioContratoTipoFato
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function ativo(string $tipo): ?ContratoTipoFato
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            SELECT tipo, versao, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas
            FROM fatos.tipo_fato_versao
            WHERE tipo = ? AND estado = 'ativa'
            SQL,
            [$tipo],
        );

        if ($linha === null) {
            return null;
        }

        /** @var array{tipo: string, versao: int, esquema: string, qualificador: string, valor_centavos: string, campos_hash: string, ordenar_arrays: string, fontes_permitidas: string} $row */
        $row = (array) $linha;

        return new ContratoTipoFato(
            tipo: $row['tipo'],
            versao: (int) $row['versao'],
            esquema: $this->esquemaObjeto($row['esquema']),
            qualificador: $this->qualificador($row['qualificador']),
            valorCentavos: $this->valorCentavos($row['valor_centavos']),
            camposHash: $this->arrayPg($row['campos_hash']),
            ordenarArrays: $this->ordenarArrays($row['ordenar_arrays']),
            fontesPermitidas: $this->arrayPg($row['fontes_permitidas']),
        );
    }

    /** Esquema como stdClass (preserva {} vs []), para o opis (que exige objetos). */
    private function esquemaObjeto(string $json): object
    {
        $v = json_decode($json, false, flags: JSON_THROW_ON_ERROR);
        assert($v instanceof \stdClass);

        return $v;
    }

    /** @return array{usa: bool, valores: list<string>} */
    private function qualificador(string $json): array
    {
        /** @var array{usa?: bool, valores?: array<array-key, string>} $v */
        $v = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return ['usa' => (bool) ($v['usa'] ?? false), 'valores' => array_values($v['valores'] ?? [])];
    }

    /** @return 'obrigatorio'|'opcional'|'proibido' */
    private function valorCentavos(string $v): string
    {
        return match ($v) {
            'obrigatorio' => 'obrigatorio',
            'opcional' => 'opcional',
            'proibido' => 'proibido',
            default => throw new \RuntimeException("valor_centavos de contrato inválido: {$v}"),
        };
    }

    /** @return array<string, list<string>> */
    private function ordenarArrays(string $json): array
    {
        /** @var array<string, list<string>> $v */
        $v = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $v;
    }

    /**
     * Decodifica um array literal do Postgres (text[]): `{a,b}` → ['a','b'], `{}` → [].
     *
     * @return list<string>
     */
    private function arrayPg(string $literal): array
    {
        $interno = trim($literal, '{}');
        if ($interno === '') {
            return [];
        }

        $itens = str_getcsv($interno, ',', '"', '\\');

        return array_map(static fn (?string $s): string => (string) $s, $itens);
    }
}
