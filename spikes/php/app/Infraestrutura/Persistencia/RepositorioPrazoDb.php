<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioPrazo;
use App\Dominio\Prazos\Prazo;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioPrazoDb implements RepositorioPrazo
{
    public function __construct(private ConnectionInterface $conexao) {}

    /**
     * @param  array{calendario: string, catalogo: string, prazos: string}  $versoes
     */
    public function gravar(string $entregavelId, string $casoId, Prazo $prazo, string $motivo, array $versoes): void
    {
        // 1. Datas na linha do entregável (a CHECK entregavel_datas_juntas/ordem valida no banco).
        $this->conexao->update(
            'UPDATE fechamento.entregavel SET vencimento = ?, meta = ?, acao = ? WHERE id = ?',
            [$prazo->vencimento->iso(), $prazo->meta->iso(), $prazo->acao->iso(), $entregavelId],
        );

        // 2. Linha de histórico (só-inserção; o id bigserial define a ordem).
        $this->conexao->insert(
            <<<'SQL'
            INSERT INTO fechamento.entregavel_prazo
                (entregavel_id, caso_id, vencimento, meta, acao, motivo,
                 calendario_versao_id, catalogo_versao_id, prazos_versao_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL,
            [
                $entregavelId, $casoId,
                $prazo->vencimento->iso(), $prazo->meta->iso(), $prazo->acao->iso(), $motivo,
                $versoes['calendario'], $versoes['catalogo'], $versoes['prazos'],
            ],
        );
    }

    /**
     * @return array{vencimento: ?string, meta: ?string, acao: ?string}
     */
    public function datasAtuais(string $entregavelId): array
    {
        $linha = $this->conexao->selectOne(
            'SELECT vencimento::text, meta::text, acao::text FROM fechamento.entregavel WHERE id = ?',
            [$entregavelId],
        );
        /** @var array{vencimento: ?string, meta: ?string, acao: ?string} $row */
        $row = $linha === null ? ['vencimento' => null, 'meta' => null, 'acao' => null] : (array) $linha;

        return ['vencimento' => $row['vencimento'], 'meta' => $row['meta'], 'acao' => $row['acao']];
    }
}
