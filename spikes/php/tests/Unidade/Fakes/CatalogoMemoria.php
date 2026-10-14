<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\CatalogoConferencia;
use App\Aplicacao\Portas\CatalogoEntregavel;
use App\Aplicacao\Portas\RepositorioCatalogo;
use RuntimeException;

/** Mesmos valores de db/migrations/V005 para os tipos usados nos testes. */
final class CatalogoMemoria implements RepositorioCatalogo
{
    private const ENTREGAVEIS = [
        'E01' => ['fiscal', 'insumos.completos'],
        'E02' => ['fiscal', null],
        'E03' => ['fiscal', 'apuracao.concluida'],
        'E10' => ['fiscal', null],
        'E11' => ['entrega', 'guias.validadas'],
        'E12' => ['sistema', null],
    ];

    private const CONFERENCIAS = [
        'CF-01' => ['E01', 'B', 'O'],
        'CF-04' => ['E03', 'B', 'S'],
        'CF-05' => ['E11', 'B', 'O'],
        'CF-06' => ['E11', 'B', 'O'],
        'CF-12' => ['E10', 'B', 'O'],
    ];

    public function entregavelTipo(string $tipo): CatalogoEntregavel
    {
        $c = self::ENTREGAVEIS[$tipo] ?? throw new RuntimeException("entregavel_tipo inexistente: {$tipo}");

        return new CatalogoEntregavel($tipo, $c[0], $c[1]);
    }

    public function conferenciaTipo(string $cf): CatalogoConferencia
    {
        $c = self::CONFERENCIAS[$cf] ?? throw new RuntimeException("conferencia_tipo inexistente: {$cf}");

        return new CatalogoConferencia($cf, $c[0], $c[1], $c[2]);
    }
}
