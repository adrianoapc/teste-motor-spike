<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioRegras;
use App\Dominio\Regras\Parametros;
use App\Dominio\Regras\RegraEntregaveis;
use App\Dominio\Regras\Tolerancia;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final readonly class RepositorioRegrasDb implements RepositorioRegras
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function entregaveisEmUso(): RegraEntregaveis
    {
        $r = $this->emUso('fechamento.entregaveis');

        return RegraEntregaveis::de($r['id'], $r['versao'], $r['conteudo']);
    }

    public function entregaveisPorId(string $versaoId): RegraEntregaveis
    {
        $linha = $this->conexao->selectOne(
            'SELECT id::text, versao, conteudo FROM regras.regra_versao WHERE id = ?',
            [$versaoId],
        );
        if ($linha === null) {
            throw new RuntimeException("versão de regra inexistente: {$versaoId}");
        }
        $r = $this->normalizar($linha);

        return RegraEntregaveis::de($r['id'], $r['versao'], $r['conteudo']);
    }

    public function toleranciaEmUso(): Tolerancia
    {
        $r = $this->emUso('fiscal.tolerancia');

        return Tolerancia::de($r['id'], $r['conteudo']);
    }

    public function parametrosEmUso(): Parametros
    {
        $r = $this->emUso('fiscal.parametros');

        return Parametros::de($r['id'], $r['conteudo']);
    }

    /**
     * Versão em uso (status provisoria ou ativa) de uma chave (§2). Nunca fixa versao=1.
     *
     * @return array{id: string, versao: int, conteudo: array<string, mixed>}
     */
    private function emUso(string $chave): array
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            SELECT id::text, versao, conteudo
            FROM regras.regra_versao
            WHERE regra_chave = ? AND status IN ('provisoria','ativa')
            SQL,
            [$chave],
        );
        if ($linha === null) {
            throw new RuntimeException("regra sem versão em uso: {$chave}");
        }

        return $this->normalizar($linha);
    }

    /**
     * @return array{id: string, versao: int, conteudo: array<string, mixed>}
     */
    private function normalizar(mixed $linha): array
    {
        /** @var array{id: string, versao: int, conteudo: string} $row */
        $row = (array) $linha;
        /** @var array<string, mixed> $conteudo */
        $conteudo = json_decode($row['conteudo'], true, flags: JSON_THROW_ON_ERROR);

        return ['id' => $row['id'], 'versao' => (int) $row['versao'], 'conteudo' => $conteudo];
    }
}
