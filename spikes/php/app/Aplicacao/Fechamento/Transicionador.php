<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioCatalogo;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioExcecao;
use App\Aplicacao\Portas\RepositorioRegras;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\Entregavel;
use App\Dominio\Fechamento\EstadoEntregavel;

/**
 * Regra de ouro das transições (§4.1), numa única transação (chamada já dentro
 * da transação do comando): atualiza estado/estado_desde/version e grava a linha
 * em fechamento.entregavel_transicao com a versão de regra do caso.
 */
final readonly class Transicionador
{
    public function __construct(
        private RepositorioEntregavel $entregaveis,
        private RepositorioCatalogo $catalogo,
        private RepositorioEvento $eventos,
        private RepositorioExcecao $excecoes,
        private RepositorioCaso $casos,
        private RepositorioRegras $regras,
    ) {}

    /**
     * Devolve o entregável no estado novo (estado e version já atualizados), sem reler o banco.
     *
     * @param  'fato'|'conferencia'|'tarefa'|'comando'|'dependencia'|null  $causadoPorTipo
     */
    public function transicionar(
        Entregavel $entregavel,
        EstadoEntregavel $para,
        string $motivo,
        string $ator,
        string $regraVersaoId,
        ?string $causadoPorTipo = null,
        ?string $causadoPorId = null,
    ): Entregavel {
        if (! $this->entregaveis->salvarEstado($entregavel->id, $entregavel->estado, $para)) {
            throw ErroDeNegocio::conflito('estado_concorrente', 'entregável mudou de estado concorrentemente');
        }

        $this->entregaveis->registrarTransicao(
            $entregavel, $entregavel->estado, $para, $motivo, $causadoPorTipo, $causadoPorId, $ator, $regraVersaoId,
        );

        // v6 (§18.4): ao transicionar para o estado_cumprimento do tipo, resolve a prazo_em_risco
        // aberta do entregável (resolucao 'cumprido'), na mesma transação. Fica aqui, na função única
        // de transição, para nenhum caminho (fato, conferência, tarefa, override) esquecer.
        $this->resolverPrazoEmRiscoSeCumpriu($entregavel, $para);

        return $entregavel->com($para);
    }

    /** Resolve a prazo_em_risco aberta quando o novo estado atinge o estado_cumprimento do tipo (§18.4). */
    private function resolverPrazoEmRiscoSeCumpriu(Entregavel $entregavel, EstadoEntregavel $para): void
    {
        $caso = $this->casos->porId($entregavel->casoId);
        if ($caso === null || $caso->regraPrazosVersaoId === null) {
            return;
        }
        $regrasDePrazo = $this->regras->prazosComPrazosFixos($caso->regraPrazosVersaoId);
        $alvo = EstadoEntregavel::from($regrasDePrazo->calculo->estadoCumprimento($entregavel->tipo));
        if ($para->maiorOuIgual($alvo)) {
            $this->excecoes->resolverPorTipo($entregavel->id, 'prazo_em_risco', 'cumprido');
        }
    }

    /**
     * Transição para `validado` + evento publicado do tipo do entregável (catálogo), se houver.
     * Único caminho para validar: conferências ok (§9.3) e override (§8.1).
     *
     * @param  'conferencia'  $causadoPorTipo
     */
    public function validar(
        Entregavel $entregavel,
        string $motivo,
        string $ator,
        string $regraVersaoId,
        string $causadoPorTipo,
        ?string $causadoPorId,
    ): Entregavel {
        $validado = $this->transicionar($entregavel, EstadoEntregavel::Validado, $motivo, $ator, $regraVersaoId, $causadoPorTipo, $causadoPorId);

        $evento = $this->catalogo->entregavelTipo($entregavel->tipo)->eventoPublicado;
        if ($evento !== null) {
            $this->eventos->gravar($evento, $entregavel->casoId, $entregavel->id, ['entregavel_id' => $entregavel->id]);
        }

        return $validado;
    }

    /**
     * Transição de criação (de_estado = NULL → aguardando_insumo), motivo abrir_competencia.
     */
    public function criar(Entregavel $entregavel, string $ator, string $regraVersaoId): void
    {
        $this->entregaveis->registrarTransicao(
            $entregavel, null, EstadoEntregavel::AguardandoInsumo, 'abrir_competencia', 'comando', null, $ator, $regraVersaoId,
        );
    }
}
