<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

interface RepositorioExcecao
{
    /**
     * Existe exceção aberta do mesmo tipo para o alvo (entregavel_id, ou o caso quando
     * entregavelId é null)? Base da abertura única (§9.4), aplicada em `Excecoes::abrirUnica`.
     */
    public function existeAberta(string $casoId, ?string $entregavelId, string $tipo): bool;

    /** Insere uma exceção aberta e devolve o id. Sem regra de unicidade: quem decide é a aplicação. */
    public function abrir(string $casoId, ?string $entregavelId, string $tipo, string $classe, string $dono): string;

    /**
     * Exceções abertas do caso, em ordem de abertura (aberta_em, id).
     *
     * @return list<ExcecaoAberta>
     */
    public function abertasDoCaso(string $casoId): array;

    /** Resolve exceções abertas de um entregável cujo tipo começa com 'CF-'. */
    public function resolverCfDoEntregavel(string $entregavelId, string $resolucao): void;

    /** Resolve exceções abertas de um entregável por tipo exato. */
    public function resolverPorTipo(string $entregavelId, string $tipo, string $resolucao): void;
}
