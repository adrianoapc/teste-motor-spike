<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioExcecao;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\Competencia;

/**
 * ResolverImpedimento (§18.5): POST .../impedimento/resolver, corpo {"ator"}.
 *   422 sem ator; 404 caso/entregável inexistente; 409 se não há impedimento aberto.
 * Efeito: a impedimento_externo aberta passa a 'resolvida', resolucao = 'resolvido'.
 */
final readonly class ResolverImpedimento
{
    public function __construct(
        private UnidadeDeTrabalho $uow,
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private RepositorioExcecao $excecoes,
    ) {}

    /** @return array{excecao_id: string} */
    public function resolver(string $titularId, string $competencia, string $tipo, string $ator): array
    {
        if ($ator === '') {
            throw ErroDeNegocio::invalido('corpo_invalido', 'ator é obrigatório');
        }

        return $this->uow->transacao(function () use ($titularId, $competencia, $tipo): array {
            $this->casos->travarTitular($titularId);
            $caso = $this->casos->porTitularCompetencia($titularId, new Competencia($competencia));
            if ($caso === null) {
                throw ErroDeNegocio::naoEncontrado("caso inexistente: {$titularId}/{$competencia}");
            }
            $entregavel = $this->entregaveis->porCasoTipoParaAtualizar($caso->id, $tipo);
            if ($entregavel === null) {
                throw ErroDeNegocio::naoEncontrado("entregável inexistente: {$tipo}");
            }

            $id = $this->excecoes->idAbertaDoEntregavel($entregavel->id, 'impedimento_externo');
            if ($id === null) {
                throw ErroDeNegocio::conflito('sem_impedimento_aberto', 'não há impedimento aberto no entregável');
            }

            $this->excecoes->resolverPorTipo($entregavel->id, 'impedimento_externo', 'resolvido');

            return ['excecao_id' => $id];
        });
    }
}
