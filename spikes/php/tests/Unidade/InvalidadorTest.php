<?php

declare(strict_types=1);

// Invalidação e cascata (§11) sobre um grafo de entregáveis em memória.
// Grafo da regra de teste: E03 <- E01, E02; E10 <- E03; E11 <- E03; E12 <- E01, E02, E03, E10, E11.

use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\EstadoEntregavel;
use Tests\Unidade\Fakes\Mundo;

/**
 * Liga o fato `fato-velho` aos entregáveis dados (qualquer papel) e dá o estado a cada um.
 *
 * @param  array<string, string>  $estados  tipo => estado
 * @param  list<string>  $ligadosAoFato  tipos
 */
function semearEstados(Mundo $m, Caso $caso, array $estados, array $ligadosAoFato): void
{
    foreach ($estados as $tipo => $estado) {
        $m->entregaveis->definirEstado($caso->id, $tipo, EstadoEntregavel::from($estado));
    }
    foreach ($ligadosAoFato as $tipo) {
        $m->entregaveis->ligarFato($m->idDe($caso, $tipo), 'fato-velho', 'entrada');
    }
    $m->entregaveis->transicoes = [];
}

/** @return list<string> */
function invalidacoes(Mundo $m): array
{
    return array_values(array_filter($m->entregaveis->resumoTransicoes(), static fn (string $t): bool => str_ends_with($t, '>invalidado')));
}

it('§11.2: cada estado do entregável semente reage de um jeito à nova versão do fato', function (string $estado, ?string $transicao, ?string $excecao): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    semearEstados($m, $caso, ['E03' => $estado], ['E03']);

    $m->invalidador->invalidar('fato-velho', 'fato-novo');

    expect(invalidacoes($m))->toBe($transicao === null ? [] : [$transicao])
        ->and($m->excecoes->resumo())->toBe($excecao === null ? [] : [$excecao]);
})->with([
    'aguardando_insumo: nada' => ['aguardando_insumo', null, null],
    'pronto: invalidado' => ['pronto', 'E03:pronto>invalidado', null],
    'processado: invalidado' => ['processado', 'E03:processado>invalidado', null],
    'divergente: invalidado' => ['divergente', 'E03:divergente>invalidado', null],
    'validado: invalidado' => ['validado', 'E03:validado>invalidado', null],
    'liberado: invalidado' => ['liberado', 'E03:liberado>invalidado', null],
    'disponibilizado: substituição, sem transição' => ['disponibilizado', null, 'substituicao:aberta'],
    'encerrado: nada' => ['encerrado', null, null],
    'invalidado: nada (já está)' => ['invalidado', null, null],
]);

it('a cascata segue os dependentes em ordem de tipo e só passa por quem foi invalidado', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    // E12 depende de todos mas ainda aguarda insumo: não é invalidado e não propaga.
    semearEstados($m, $caso, ['E01' => 'validado', 'E03' => 'validado', 'E10' => 'processado', 'E11' => 'liberado'], ['E01']);

    $m->invalidador->invalidar('fato-velho', 'fato-novo');

    expect(invalidacoes($m))->toBe([
        'E01:validado>invalidado',
        'E03:validado>invalidado',
        'E10:processado>invalidado',
        'E11:liberado>invalidado',
    ])
        ->and($m->estado($caso, 'E12'))->toBe('aguardando_insumo')
        ->and($m->eventos->quantos('entregavel.invalidado'))->toBe(4);
});

it('a cascata não atravessa um entregável que não pode ser invalidado', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    // E03 ainda aguarda insumo: E10 (depende só de E03) fica intocado mesmo estando validado.
    semearEstados($m, $caso, ['E01' => 'validado', 'E03' => 'aguardando_insumo', 'E10' => 'validado'], ['E01']);

    $m->invalidador->invalidar('fato-velho', 'fato-novo');

    expect(invalidacoes($m))->toBe(['E01:validado>invalidado'])
        ->and($m->estado($caso, 'E10'))->toBe('validado');
});

it('um dependente alcançado por dois caminhos (losango) é invalidado uma única vez', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    // E12 depende de E01 e de E03 (ambas sementes) e de E11: é alcançado várias vezes, por caminhos diferentes.
    semearEstados($m, $caso, ['E01' => 'validado', 'E03' => 'validado', 'E11' => 'validado', 'E12' => 'validado'], ['E01', 'E03']);

    $m->invalidador->invalidar('fato-velho', 'fato-novo');

    $inv = invalidacoes($m);
    // Largura primeiro: E12 (dependente direto de E01) é visitado antes de E11 (dependente de E03).
    expect($inv)->toBe(['E01:validado>invalidado', 'E03:validado>invalidado', 'E12:validado>invalidado', 'E11:validado>invalidado'])
        ->and(count(array_unique($inv)))->toBe(count($inv));
});

it('registra a causa da transição: fato, id da versão nova, ator sistema, motivo fato_alterado', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    semearEstados($m, $caso, ['E03' => 'validado'], ['E03']);

    $m->invalidador->invalidar('fato-velho', 'fato-novo');

    expect($m->entregaveis->transicoes)->toHaveCount(1)
        ->and($m->entregaveis->transicoes[0]['motivo'])->toBe('fato_alterado')
        ->and($m->entregaveis->transicoes[0]['ator'])->toBe('sistema')
        ->and($m->entregaveis->transicoes[0]['causadoPorTipo'])->toBe('fato')
        ->and($m->entregaveis->transicoes[0]['causadoPorId'])->toBe('fato-novo');
});

it('cancela a tarefa aberta do entregável invalidado', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $m->tarefas->abrir($caso->id, $m->idDe($caso, 'E03'), 'executar_entregavel', 'C-01');
    semearEstados($m, $caso, ['E03' => 'pronto'], ['E03']);

    $m->invalidador->invalidar('fato-velho', 'fato-novo');

    expect($m->tarefas->estados())->toBe(['cancelada']);
});

it('de divergente: resolve as exceções CF do entregável com fato_alterado e deixa as outras', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $e11 = $m->idDe($caso, 'E11');
    semearEstados($m, $caso, ['E11' => 'divergente'], ['E11']);
    $m->abertura->abrirUnica($caso->id, $e11, 'CF-05', 'O', 'C-01');
    $m->abertura->abrirUnica($caso->id, $e11, 'entrega_falhou', 'O', 'C-01');

    $m->invalidador->invalidar('fato-velho', 'fato-novo');

    expect($m->excecoes->resumo())->toBe(['CF-05:resolvida:fato_alterado', 'entrega_falhou:aberta']);
});

it('o entregável excluído (o que está sendo concluído) não é semente (§8 item 4)', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    semearEstados($m, $caso, ['E03' => 'validado'], ['E03']);

    // Só o E03 usa o fato e ele é o excluído: nada a invalidar.
    $m->invalidador->invalidar('fato-velho', 'fato-novo', $m->idDe($caso, 'E03'));

    expect(invalidacoes($m))->toBe([]);
});

it('o excluído só deixa de ser semente: se um dependente o alcança pela cascata, é invalidado', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    semearEstados($m, $caso, ['E01' => 'validado', 'E03' => 'validado'], ['E01', 'E03']);

    $m->invalidador->invalidar('fato-velho', 'fato-novo', $m->idDe($caso, 'E03'));

    expect(invalidacoes($m))->toBe(['E01:validado>invalidado', 'E03:validado>invalidado']);
});

it('substituição (documento já protocolado) é única por entregável mesmo com várias invalidações', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    semearEstados($m, $caso, ['E11' => 'disponibilizado'], ['E11']);

    $m->invalidador->invalidar('fato-velho', 'fato-novo');
    $m->invalidador->invalidar('fato-velho', 'fato-novo-2');

    expect($m->excecoes->resumo())->toBe(['substituicao:aberta'])
        ->and($m->estado($caso, 'E11'))->toBe('disponibilizado');
});

it('caso encerrado (§11.3): nenhuma transição; abre competencia_encerrada uma única vez, sem entregável', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    semearEstados($m, $caso, ['E01' => 'encerrado', 'E03' => 'validado'], ['E01', 'E03']);
    $m->casos->encerrar($caso->id);

    $m->invalidador->invalidar('fato-velho', 'fato-novo');
    $m->invalidador->invalidar('fato-velho', 'fato-novo-2');

    expect($m->entregaveis->transicoes)->toBe([])
        ->and($m->excecoes->resumo())->toBe(['competencia_encerrada:aberta'])
        ->and($m->excecoes->excecoes['exc-1']['entregavelId'])->toBeNull();
});

it('um mesmo fato usado em dois casos do titular invalida cada caso, em ordem de id', function (): void {
    $m = new Mundo;
    $jan = $m->abrirCaso('T1', '202601');
    $fev = $m->abrirCaso('T1', '202602');
    semearEstados($m, $fev, ['E03' => 'validado'], ['E03']);
    semearEstados($m, $jan, ['E03' => 'processado'], ['E03']);
    $m->entregaveis->transicoes = [];

    $m->invalidador->invalidar('fato-velho', 'fato-novo');

    expect($m->entregaveis->resumoTransicoes())->toBe(['E03:processado>invalidado', 'E03:validado>invalidado']);
});

it('sem nenhum entregável ligado ao fato anterior não há efeito', function (): void {
    $m = new Mundo;
    $m->abrirCaso();
    $m->entregaveis->transicoes = [];

    $m->invalidador->invalidar('fato-que-ninguem-usa', 'fato-novo');

    expect($m->entregaveis->transicoes)->toBe([])
        ->and($m->eventos->quantos('entregavel.invalidado'))->toBe(0)
        ->and($m->excecoes->excecoes)->toBe([]);
});
