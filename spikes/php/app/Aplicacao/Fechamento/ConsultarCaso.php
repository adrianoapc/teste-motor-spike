<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\ExcecaoAberta;
use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioExcecao;
use App\Aplicacao\Portas\RepositorioTarefa;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\Competencia;

/**
 * Consulta do caso (contrato CasoResponse): caso, entregáveis, tarefas abertas,
 * exceções abertas. Cada dado vem do repositório do módulo dono (fechamento,
 * trabalho); o cruzamento tarefa × tipo do entregável é feito aqui, não em SQL.
 */
final readonly class ConsultarCaso
{
    public function __construct(
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private RepositorioTarefa $tarefas,
        private RepositorioExcecao $excecoes,
    ) {}

    public function consultar(string $titularId, string $competenciaValor): CasoConsultado
    {
        $caso = $this->casos->porTitularCompetencia($titularId, new Competencia($competenciaValor));
        if ($caso === null) {
            throw ErroDeNegocio::naoEncontrado("caso inexistente: {$titularId}/{$competenciaValor}");
        }

        $entregaveis = [];
        /** @var array<string, string> $tipoPorId */
        $tipoPorId = [];
        foreach ($this->entregaveis->doCaso($caso->id) as $e) {
            $entregaveis[] = new EntregavelConsultado($e->id, $e->tipo, $e->estado->value, $e->executor);
            $tipoPorId[$e->id] = $e->tipo;
        }

        $tarefas = [];
        foreach ($this->tarefas->abertasDoCaso($caso->id) as $t) {
            $tarefas[] = new TarefaConsultada($t->tarefaId, $tipoPorId[$t->entregavelId] ?? '');
        }
        usort($tarefas, static fn (TarefaConsultada $a, TarefaConsultada $b): int => strcmp($a->entregavelTipo, $b->entregavelTipo));

        return new CasoConsultado(
            casoId: $caso->id,
            titularId: $caso->titularId,
            competencia: $caso->competencia->valor,
            estado: $caso->estado,
            entregaveis: $entregaveis,
            tarefasAbertas: $tarefas,
            excecoesAbertas: array_map(
                static fn (ExcecaoAberta $x): ExcecaoConsultada => new ExcecaoConsultada($x->excecaoId, $x->tipo, $x->classe),
                $this->excecoes->abertasDoCaso($caso->id),
            ),
        );
    }
}
