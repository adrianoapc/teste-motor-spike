<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioExcecao;
use App\Aplicacao\Portas\RepositorioMotivoCatalogo;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\Competencia;

/**
 * RegistrarImpedimento (§18.5): abre a exceção `impedimento_externo` (classe S, dono = carteira,
 * com motivo_codigo), SEM mudar o estado do entregável. Validação, nesta ordem:
 *   1. ator ou motivo_codigo ausente/vazio → 422 corpo_invalido;
 *   2. código fora do catálogo ou aplica_a ≠ 'impedimento' → 422 motivo_invalido;
 *   3. código com exige_texto e texto ausente/<5 → 422 corpo_invalido;
 *   4. caso ou entregável inexistente → 404;
 *   5. caso encerrado, ou impedimento já aberto no entregável → 409.
 */
final readonly class RegistrarImpedimento
{
    public function __construct(
        private UnidadeDeTrabalho $uow,
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private RepositorioExcecao $excecoes,
        private RepositorioMotivoCatalogo $motivos,
        private Excecoes $abertura,
    ) {}

    /** @return array{excecao_id: string} */
    public function registrar(RegistrarImpedimentoComando $comando): array
    {
        // 1. corpo_invalido.
        if ($comando->ator === '' || $comando->motivoCodigo === '') {
            throw ErroDeNegocio::invalido('corpo_invalido', 'ator e motivo_codigo são obrigatórios');
        }
        // 2. motivo_invalido.
        if (! $this->motivos->existe($comando->motivoCodigo, 'impedimento')) {
            throw ErroDeNegocio::invalido('motivo_invalido', "motivo_codigo inválido para impedimento: {$comando->motivoCodigo}");
        }
        // 3. corpo_invalido por exige_texto.
        if ($this->motivos->exigeTexto($comando->motivoCodigo, 'impedimento') && ($comando->texto === null || mb_strlen($comando->texto) < 5)) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'este motivo exige texto com ao menos 5 caracteres');
        }

        return $this->uow->transacao(function () use ($comando): array {
            $this->casos->travarTitular($comando->titularId);
            $caso = $this->casos->porTitularCompetencia($comando->titularId, new Competencia($comando->competencia));
            if ($caso === null) {
                throw ErroDeNegocio::naoEncontrado("caso inexistente: {$comando->titularId}/{$comando->competencia}");
            }
            $entregavel = $this->entregaveis->porCasoTipoParaAtualizar($caso->id, $comando->tipo);
            if ($entregavel === null) {
                throw ErroDeNegocio::naoEncontrado("entregável inexistente: {$comando->tipo}");
            }

            // 5. 409: caso encerrado, ou impedimento já aberto no entregável.
            if ($caso->encerrado()) {
                throw ErroDeNegocio::conflito('caso_encerrado', 'caso encerrado');
            }
            if ($this->excecoes->idAbertaDoEntregavel($entregavel->id, 'impedimento_externo') !== null) {
                throw ErroDeNegocio::conflito('impedimento_ja_aberto', 'já existe impedimento aberto no entregável');
            }

            // Efeito: exceção impedimento_externo (classe S, dono = carteira, motivo_codigo), sem transição.
            $id = $this->abertura->abrirUnica($caso->id, $entregavel->id, 'impedimento_externo', 'S', $caso->carteira, null, $comando->motivoCodigo);
            assert($id !== null); // já checamos que não havia aberta

            return ['excecao_id' => $id];
        });
    }
}
