<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\CatalogoConferencia;
use App\Aplicacao\Portas\CatalogoEntregavel;
use App\Aplicacao\Portas\RepositorioCatalogo;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * O catálogo (tipos de entregável e de conferência) só muda por migração, não durante uma requisição: cada
 * linha é lida uma vez por requisição (esta instância é `scoped`, nunca vive entre requisições).
 */
final class RepositorioCatalogoDb implements RepositorioCatalogo
{
    /** @var array<string, CatalogoEntregavel> */
    private array $entregaveis = [];

    /** @var array<string, CatalogoConferencia> */
    private array $conferencias = [];

    public function __construct(private readonly ConnectionInterface $conexao) {}

    public function entregavelTipo(string $tipo): CatalogoEntregavel
    {
        return $this->entregaveis[$tipo] ??= $this->lerEntregavelTipo($tipo);
    }

    public function conferenciaTipo(string $cf): CatalogoConferencia
    {
        return $this->conferencias[$cf] ??= $this->lerConferenciaTipo($cf);
    }

    private function lerEntregavelTipo(string $tipo): CatalogoEntregavel
    {
        $linha = $this->conexao->selectOne(
            'SELECT chave, area, evento_publicado FROM fechamento.entregavel_tipo WHERE chave = ?',
            [$tipo],
        );
        if ($linha === null) {
            throw new RuntimeException("entregavel_tipo inexistente: {$tipo}");
        }
        /** @var array{chave: string, area: string, evento_publicado: ?string} $row */
        $row = (array) $linha;

        return new CatalogoEntregavel($row['chave'], $row['area'], $row['evento_publicado']);
    }

    private function lerConferenciaTipo(string $cf): CatalogoConferencia
    {
        $linha = $this->conexao->selectOne(
            'SELECT chave, entregavel_tipo, severidade, classe_padrao FROM conferencias.conferencia_tipo WHERE chave = ?',
            [$cf],
        );
        if ($linha === null) {
            throw new RuntimeException("conferencia_tipo inexistente: {$cf}");
        }
        /** @var array{chave: string, entregavel_tipo: string, severidade: string, classe_padrao: string} $row */
        $row = (array) $linha;

        return new CatalogoConferencia($row['chave'], $row['entregavel_tipo'], $row['severidade'], $row['classe_padrao']);
    }
}
