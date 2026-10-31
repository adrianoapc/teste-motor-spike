<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioRegras;
use App\Dominio\Prazos\CalculoDePrazos;
use App\Dominio\Prazos\Calendario;
use App\Dominio\Prazos\CatalogoObrigacoes;
use App\Dominio\Prazos\RegraPrazos;
use App\Dominio\Prazos\RegrasDePrazo;
use App\Dominio\Regras\Parametros;
use App\Dominio\Regras\RegraEntregaveis;
use App\Dominio\Regras\Tolerancia;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Uma versão de regra (`regras.regra_versao.id`) é imutável: lida uma vez por requisição. A versão "em uso"
 * de cada chave também é lida uma vez por requisição, para a requisição inteira enxergar a mesma regra. Esta
 * instância é `scoped` (nunca vive entre requisições): nada de estado entre requisições (tech.md).
 */
final class RepositorioRegrasDb implements RepositorioRegras
{
    /** @var array<string, RegraEntregaveis> por id da versão */
    private array $entregaveisPorId = [];

    /** @var array<string, array{id: string, versao: int, conteudo: array<string, mixed>}> por chave */
    private array $emUsoPorChave = [];

    private ?Tolerancia $tolerancia = null;

    private ?Parametros $parametros = null;

    public function __construct(private readonly ConnectionInterface $conexao) {}

    public function entregaveisEmUso(): RegraEntregaveis
    {
        $r = $this->emUso('fechamento.entregaveis');

        return $this->entregaveisPorId[$r['id']] ??= RegraEntregaveis::de($r['id'], $r['versao'], $r['conteudo']);
    }

    public function entregaveisPorId(string $versaoId): RegraEntregaveis
    {
        return $this->entregaveisPorId[$versaoId] ??= $this->lerEntregaveisPorId($versaoId);
    }

    public function toleranciaEmUso(): Tolerancia
    {
        if ($this->tolerancia === null) {
            $r = $this->emUso('fiscal.tolerancia');
            $this->tolerancia = Tolerancia::de($r['id'], $r['conteudo']);
        }

        return $this->tolerancia;
    }

    public function parametrosEmUso(): Parametros
    {
        if ($this->parametros === null) {
            $r = $this->emUso('fiscal.parametros');
            $this->parametros = Parametros::de($r['id'], $r['conteudo']);
        }

        return $this->parametros;
    }

    public function prazosEmUso(): RegrasDePrazo
    {
        $prazos = $this->emUso('fechamento.prazos');

        return $this->montarRegrasDePrazo($prazos);
    }

    public function prazosComPrazosFixos(string $prazosVersaoId): RegrasDePrazo
    {
        $prazos = $this->versaoPorId($prazosVersaoId);

        return $this->montarRegrasDePrazo($prazos);
    }

    /**
     * Monta o trio de regras de prazo: o calendário e o catálogo SEMPRE em uso (não fixados), e
     * a versão de 'fechamento.prazos' recebida (em uso na abertura, fixada no caso em §18.3).
     *
     * @param  array{id: string, versao: int, conteudo: array<string, mixed>}  $prazos
     */
    private function montarRegrasDePrazo(array $prazos): RegrasDePrazo
    {
        $calendario = $this->emUso('calendario.vencimentos');
        $catalogo = $this->emUso('obrigacoes.catalogo');

        $calculo = new CalculoDePrazos(
            Calendario::de($calendario['conteudo']),
            CatalogoObrigacoes::de($catalogo['conteudo']),
            RegraPrazos::de($prazos['conteudo']),
        );

        return new RegrasDePrazo(
            calendarioVersaoId: $calendario['id'],
            calendarioVersao: $calendario['versao'],
            catalogoVersaoId: $catalogo['id'],
            prazosVersaoId: $prazos['id'],
            calculo: $calculo,
        );
    }

    /**
     * Uma versão de regra por id (qualquer chave), para a versão de prazos fixada no caso.
     *
     * @return array{id: string, versao: int, conteudo: array<string, mixed>}
     */
    private function versaoPorId(string $versaoId): array
    {
        $linha = $this->conexao->selectOne(
            'SELECT id::text, versao, conteudo FROM regras.regra_versao WHERE id = ?',
            [$versaoId],
        );
        if ($linha === null) {
            throw new RuntimeException("versão de regra inexistente: {$versaoId}");
        }

        return $this->normalizar($linha);
    }

    private function lerEntregaveisPorId(string $versaoId): RegraEntregaveis
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

    /**
     * Versão em uso (status provisoria ou ativa) de uma chave (§2). Nunca fixa versao=1. O índice único
     * `regra_versao_uma_em_uso` garante no máximo uma; ORDER BY/LIMIT tornam a escolha determinística
     * (a maior versão) mesmo que um dia o índice mude.
     *
     * @return array{id: string, versao: int, conteudo: array<string, mixed>}
     */
    private function emUso(string $chave): array
    {
        if (isset($this->emUsoPorChave[$chave])) {
            return $this->emUsoPorChave[$chave];
        }

        $linha = $this->conexao->selectOne(
            <<<'SQL'
            SELECT id::text, versao, conteudo
            FROM regras.regra_versao
            WHERE regra_chave = ? AND status IN ('provisoria','ativa')
            ORDER BY versao DESC
            LIMIT 1
            SQL,
            [$chave],
        );
        if ($linha === null) {
            throw new RuntimeException("regra sem versão em uso: {$chave}");
        }

        return $this->emUsoPorChave[$chave] = $this->normalizar($linha);
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
