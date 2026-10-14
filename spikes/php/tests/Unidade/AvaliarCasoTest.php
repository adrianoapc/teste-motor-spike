<?php

declare(strict_types=1);

// AvaliarCaso (§7.4) e AbrirCompetencias (§5) sem banco: casos de uso reais sobre portas em memória.

use App\Aplicacao\Fechamento\AbrirCompetenciasComando;
use App\Aplicacao\Fechamento\ConcluirTarefaComando;
use App\Aplicacao\Fechamento\SaidaDaTarefa;
use App\Dominio\Fechamento\Empresa;
use App\Dominio\Fechamento\EstadoEntregavel;
use Tests\Unidade\Fakes\Mundo;

/** Publica os insumos de E01 (CF-01 ok) e E02: o E03 humano fica `pronto` com tarefa aberta. */
function publicarInsumosIniciais(Mundo $m): void
{
    $m->fato('faturamento_mes', 5000000);
    $m->fato('notas_oneflow', 5000000);
    $m->fato('aliquota_mes', null, (object) ['aliquota_bp' => 600]);
}

it('abre o caso com um entregável por item cujo quando casa e as dependências entre eles (§5)', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso(regime: 'SN');

    expect(array_map(static fn ($e) => $e->tipo, $m->entregaveis->doCaso($caso->id)))->toBe(['E01', 'E02', 'E03', 'E10', 'E11', 'E12'])
        ->and($m->entregaveis->resumoTransicoes()[0])->toBe('E01:->aguardando_insumo')
        ->and($m->eventos->nomes())->toBe(['competencia.aberta'])
        ->and($m->dependencias->arestas)->toHaveCount(9);
});

it('não cria entregável cujo quando não casa (E02 só no Simples) e ignora a dependência para ele (§5.4)', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso(regime: 'LP');

    expect(array_map(static fn ($e) => $e->tipo, $m->entregaveis->doCaso($caso->id)))->toBe(['E01', 'E03', 'E10', 'E11', 'E12'])
        ->and($m->dependencias->doEntregavel($m->idDe($caso, 'E03')))->toHaveCount(1);
});

it('é idempotente por (titular, competência): a segunda abertura só reporta existente', function (): void {
    $m = new Mundo;
    $m->abrirCaso();
    $empresa = new Empresa('T1', 'SN', true, false, false, false, 'C-01', '3549805');

    $resultado = $m->abrir->abrir(new AbrirCompetenciasComando(Mundo::COMP, 'teste', [$empresa]));

    expect($resultado->criados)->toBe([])
        ->and($resultado->existentes)->toHaveCount(1)
        ->and($m->casos->casos)->toHaveCount(1)
        ->and($m->eventos->quantos('competencia.aberta'))->toBe(1)
        ->and($m->casos->travas)->toBe(['T1', 'T1']);
});

it('cada entregável dá no máximo um passo por volta e a reavaliação repete até nada mudar (§7.4)', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $m->fato('faturamento_mes', 5000000);
    $m->fato('notas_oneflow', 5000000);

    // Entrada completa de E01: pronto -> processado -> validado (CF-01 ok) numa única reavaliação.
    expect($m->estado($caso, 'E01'))->toBe('validado')
        ->and(array_slice($m->entregaveis->resumoTransicoes(), 6))->toBe([
            'E01:aguardando_insumo>pronto',
            'E01:pronto>processado',
            'E01:processado>validado',
        ])
        ->and($m->eventos->quantos('insumos.completos'))->toBe(1)
        ->and($m->estado($caso, 'E03'))->toBe('aguardando_insumo');
});

it('abre a tarefa humana quando o entregável humano fica pronto, e só então', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $m->fato('faturamento_mes', 5000000);
    $m->fato('notas_oneflow', 5000000);
    expect($m->tarefas->tarefas)->toBe([]);

    $m->fato('aliquota_mes', null, (object) ['aliquota_bp' => 600]);

    expect($m->estado($caso, 'E03'))->toBe('pronto')
        ->and($m->tarefas->estados())->toBe(['aberta'])
        ->and(array_values($m->tarefas->tarefas)[0]['responsavel'])->toBe('C-01');
});

it('conferência divergente leva a divergente, abre a exceção CF e emite os eventos na ordem (§9.3)', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $m->fato('faturamento_mes', 5000000);
    $m->fato('notas_oneflow', 4000000);

    expect($m->estado($caso, 'E01'))->toBe('divergente')
        ->and($m->excecoes->resumo())->toBe(['CF-01:aberta'])
        ->and(array_values(array_filter($m->eventos->nomes(), static fn (string $n): bool => $n !== 'fato.publicado')))
        ->toBe(['competencia.aberta', 'conferencia.divergente', 'excecao.aberta']);
});

it('reavaliar de novo não duplica a exceção CF (abertura única, §9.4)', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $m->fato('faturamento_mes', 5000000);
    $m->fato('notas_oneflow', 4000000);

    $m->avaliar->avaliar($caso);
    $m->avaliar->avaliar($caso);

    expect($m->excecoes->resumo())->toBe(['CF-01:aberta']);
});

it('reavalia só os casos abertos do titular: ignora outros titulares e casos encerrados', function (): void {
    $m = new Mundo;
    $m->abrirCaso('T1', '202602');
    $m->abrirCaso('T1', '202601');
    $m->abrirCaso('T2', '202601');
    $encerrado = $m->abrirCaso('T1', '202512');
    $m->casos->encerrar($encerrado->id);
    $m->entregaveis->transicoes = [];

    foreach (['202602', '202601', '202512'] as $comp) {
        $m->fatos->inserir('T1', $comp, 'faturamento_mes', '', 1, 100, new stdClass, 'teste', null, 'h-f'.$comp, null, '');
        $m->fatos->inserir('T1', $comp, 'notas_oneflow', '', 1, 100, new stdClass, 'teste', null, 'h-n'.$comp, null, '');
    }
    $m->avaliar->avaliarAbertosDoTitular('T1');

    $estadoDoE01 = static fn (string $casoId): string => ($m->entregaveis->porCasoTipo($casoId, 'E01') ?? throw new RuntimeException)->estado->value;
    expect($estadoDoE01('caso-1'))->toBe('validado')                 // T1 fev
        ->and($estadoDoE01('caso-2'))->toBe('validado')              // T1 jan
        ->and($estadoDoE01('caso-3'))->toBe('aguardando_insumo')     // outro titular
        ->and($estadoDoE01($encerrado->id))->toBe('aguardando_insumo') // encerrado: não é reavaliado
        ->and(count($m->entregaveis->transicoes))->toBe(6);
});

it('do protocolo ao encerramento: liberado, disponibilizado, E12 encerra o caso (§10)', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    publicarInsumosIniciais($m);

    // E03 humano: conclui com o apurado esperado (5.000.000 x 6% = 300.000).
    $m->concluir->concluir(new ConcluirTarefaComando('T1', Mundo::COMP, 'E03', 'ana', [new SaidaDaTarefa('DAS', 300000, new stdClass)]));
    $m->fato('recibo_obrigacao', null, new stdClass, 'PGDAS-D');
    $m->fato('guia', 300000, (object) ['competencia_impressa' => '202601'], 'DAS');

    expect($m->estado($caso, 'E11'))->toBe('liberado')
        ->and($m->estado($caso, 'E12'))->toBe('aguardando_insumo');

    $m->fato('documento_disponibilizado', null, new stdClass, 'DAS');

    expect($m->estado($caso, 'E11'))->toBe('disponibilizado')
        ->and($m->estado($caso, 'E12'))->toBe('encerrado')
        ->and($m->casos->porId($caso->id)?->estado)->toBe('encerrado')
        ->and($m->eventos->quantos('documento.disponibilizado'))->toBe(1)
        ->and($m->eventos->quantos('fechamento.concluido'))->toBe(1);
});

it('não protocola sem a guia vigente: o fato de disponibilização sozinho não basta (§10.1)', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $m->entregaveis->definirEstado($caso->id, 'E11', EstadoEntregavel::Liberado);

    $m->fato('documento_disponibilizado', null, new stdClass, 'DAS');

    expect($m->estado($caso, 'E11'))->toBe('liberado')
        ->and($m->eventos->quantos('documento.disponibilizado'))->toBe(0);
});

it('invalidado que não cumpre mais a prontidão volta a aguardando_insumo (§7.2)', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $m->entregaveis->definirEstado($caso->id, 'E02', EstadoEntregavel::Invalidado);

    $m->avaliar->avaliar($caso);

    expect($m->estado($caso, 'E02'))->toBe('aguardando_insumo')
        ->and($m->entregaveis->resumoTransicoes()[count($m->entregaveis->resumoTransicoes()) - 1])->toBe('E02:invalidado>aguardando_insumo');
});

it('a consulta do caso reúne entregáveis, tarefas e exceções abertas de módulos diferentes', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    publicarInsumosIniciais($m);

    $c = $m->consultar->consultar('T1', Mundo::COMP);

    expect($c->casoId)->toBe($caso->id)
        ->and($c->estado)->toBe('aberto')
        ->and(array_map(static fn ($e) => $e->tipo.':'.$e->estado, $c->entregaveis))->toBe([
            'E01:validado', 'E02:validado', 'E03:pronto', 'E10:aguardando_insumo', 'E11:aguardando_insumo', 'E12:aguardando_insumo',
        ])
        ->and(array_map(static fn ($t) => $t->entregavelTipo, $c->tarefasAbertas))->toBe(['E03'])
        ->and($c->excecoesAbertas)->toBe([]);
});
