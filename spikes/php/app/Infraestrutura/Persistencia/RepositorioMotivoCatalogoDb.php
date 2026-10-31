<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioMotivoCatalogo;
use Illuminate\Database\ConnectionInterface;

final class RepositorioMotivoCatalogoDb implements RepositorioMotivoCatalogo
{
    /** @var array<string, array{aplica_a: string, exige_texto: bool}>|null por código, lido uma vez por requisição */
    private ?array $catalogo = null;

    public function __construct(private readonly ConnectionInterface $conexao) {}

    public function existe(string $codigo, string $aplicaA): bool
    {
        $item = $this->catalogo()[$codigo] ?? null;

        return $item !== null && $item['aplica_a'] === $aplicaA;
    }

    public function exigeTexto(string $codigo, string $aplicaA): bool
    {
        $item = $this->catalogo()[$codigo] ?? null;

        return $item !== null && $item['aplica_a'] === $aplicaA && $item['exige_texto'];
    }

    /**
     * @return array<string, array{aplica_a: string, exige_texto: bool}>
     */
    private function catalogo(): array
    {
        if ($this->catalogo !== null) {
            return $this->catalogo;
        }

        $linhas = $this->conexao->select('SELECT codigo, aplica_a, exige_texto FROM trabalho.motivo_catalogo');
        $mapa = [];
        foreach ($linhas as $l) {
            /** @var array{codigo: string, aplica_a: string, exige_texto: bool} $row */
            $row = (array) $l;
            $mapa[$row['codigo']] = ['aplica_a' => $row['aplica_a'], 'exige_texto' => (bool) $row['exige_texto']];
        }

        return $this->catalogo = $mapa;
    }
}
