<?php

declare(strict_types=1);

// Acompanhamento depois do protocolo (§10.3) sem banco. Nunca transiciona: vira evento ou exceção com dono.

use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\EstadoEntregavel;
use Tests\Unidade\Fakes\Mundo;

/**
 * Caso com o E11 já `disponibilizado`; devolve o caso e o id do E11.
 *
 * @return array{Caso, string}
 */
function casoComE11Disponibilizado(Mundo $m, string $regime = 'SN'): array
{
    $caso = $m->abrirCaso(regime: $regime);
    $e11 = $m->entregaveis->definirEstado($caso->id, 'E11', EstadoEntregavel::Disponibilizado);
    $m->eventos->eventos = [];
    $m->entregaveis->transicoes = [];

    return [$caso, $e11->id];
}

it('reenvio: nova versão do fato de protocolo emite documento.disponibilizado com a guia e resolve entrega_falhou', function (): void {
    $m = new Mundo;
    [$caso, $e11] = casoComE11Disponibilizado($m);
    $m->abertura->abrirUnica($caso->id, $e11, 'entrega_falhou', 'O', 'C-01');
    $m->eventos->eventos = [];

    $m->acompanhar->aplicar($caso, 'documento_disponibilizado', 'DAS', true, 'fato-guia');

    expect($m->eventos->eventos)->toBe([[
        'nome' => 'documento.disponibilizado', 'casoId' => $caso->id, 'entregavelId' => $e11,
        'payload' => ['entregavel_id' => $e11, 'fato_id' => 'fato-guia'],
    ]])
        ->and($m->excecoes->resumo())->toBe(['entrega_falhou:resolvida:reenviado']);
});

it('o fato de protocolo na primeira versão (não é reenvio) não faz nada aqui', function (): void {
    $m = new Mundo;
    [$caso] = casoComE11Disponibilizado($m);

    $m->acompanhar->aplicar($caso, 'documento_disponibilizado', 'DAS', false, 'fato-guia');

    expect($m->eventos->eventos)->toBe([])
        ->and($m->excecoes->excecoes)->toBe([]);
});

it('falha de entrega abre entrega_falhou com dono = carteira, uma só vez', function (): void {
    $m = new Mundo;
    [$caso] = casoComE11Disponibilizado($m);

    $m->acompanhar->aplicar($caso, 'entrega_falhou', 'DAS', false, 'fato-guia');
    $m->acompanhar->aplicar($caso, 'entrega_falhou', 'DAS', true, 'fato-guia');

    expect($m->excecoes->resumo())->toBe(['entrega_falhou:aberta'])
        ->and($m->excecoes->excecoes['exc-1']['dono'])->toBe('C-01')
        ->and($m->eventos->nomes())->toBe(['excecao.aberta']);
});

it('confirmação de recebimento emite recebimento.confirmado e resolve entrega_falhou e recebimento_pendente', function (): void {
    $m = new Mundo;
    [$caso, $e11] = casoComE11Disponibilizado($m);
    $m->abertura->abrirUnica($caso->id, $e11, 'entrega_falhou', 'O', 'C-01');
    $m->abertura->abrirUnica($caso->id, $e11, 'recebimento_pendente', 'O', 'C-01');
    $m->eventos->eventos = [];

    $m->acompanhar->aplicar($caso, 'entrega_confirmada', 'DAS', false, 'fato-guia');

    expect($m->eventos->nomes())->toBe(['recebimento.confirmado'])
        ->and($m->excecoes->resumo())->toBe(['entrega_falhou:resolvida:recebido', 'recebimento_pendente:resolvida:recebido']);
});

it('pendência de recebimento abre recebimento_pendente se não há confirmação vigente', function (): void {
    $m = new Mundo;
    [$caso] = casoComE11Disponibilizado($m);

    $m->acompanhar->aplicar($caso, 'recebimento_pendente', 'DAS', false, 'fato-guia');

    expect($m->excecoes->resumo())->toBe(['recebimento_pendente:aberta']);
});

it('pendência de recebimento não abre nada se já existe fato de confirmação vigente com a mesma chave', function (): void {
    $m = new Mundo;
    [$caso] = casoComE11Disponibilizado($m);
    $m->fatos->inserir('T1', Mundo::COMP, 'entrega_confirmada', 'DAS', 1, null, new stdClass, 'teste', null, 'h', null, '');

    $m->acompanhar->aplicar($caso, 'recebimento_pendente', 'DAS', false, 'fato-guia');

    expect($m->excecoes->excecoes)->toBe([]);
});

it('só vale para o tributo da guia do caso: DAS no Simples, IRPJ no Lucro Presumido', function (): void {
    $m = new Mundo;
    [$sn] = casoComE11Disponibilizado($m, 'SN');
    $m->acompanhar->aplicar($sn, 'entrega_falhou', 'IRPJ', false, 'g');
    expect($m->excecoes->excecoes)->toBe([]);

    $m2 = new Mundo;
    [$lp] = casoComE11Disponibilizado($m2, 'LP');
    $m2->acompanhar->aplicar($lp, 'entrega_falhou', 'DAS', false, 'g');
    expect($m2->excecoes->excecoes)->toBe([]);
    $m2->acompanhar->aplicar($lp, 'entrega_falhou', 'IRPJ', false, 'g');
    expect($m2->excecoes->resumo())->toBe(['entrega_falhou:aberta']);
});

it('um fato de outro tipo não dispara nada', function (): void {
    $m = new Mundo;
    [$caso] = casoComE11Disponibilizado($m);

    $m->acompanhar->aplicar($caso, 'guia', 'DAS', true, 'fato-guia');

    expect($m->eventos->eventos)->toBe([])
        ->and($m->excecoes->excecoes)->toBe([]);
});

it('nunca transiciona nem reabre o caso, nem em caso encerrado', function (): void {
    $m = new Mundo;
    [$caso] = casoComE11Disponibilizado($m);
    $m->casos->encerrar($caso->id);
    $encerrado = $m->casos->porId($caso->id);
    assert($encerrado instanceof Caso);

    $m->acompanhar->aplicar($encerrado, 'entrega_falhou', 'DAS', false, 'fato-guia');
    $m->acompanhar->aplicar($encerrado, 'entrega_confirmada', 'DAS', false, 'fato-guia');

    expect($m->entregaveis->transicoes)->toBe([])
        ->and($m->casos->porId($caso->id)?->estado)->toBe('encerrado')
        ->and($m->eventos->nomes())->toBe(['excecao.aberta', 'recebimento.confirmado']);
});

it('sem bloco de acompanhamento no E11 da regra fixada do caso, não faz nada', function (): void {
    $regra = Mundo::regraPadrao();
    foreach ($regra['entregaveis'] as $i => $item) {
        if ($item['tipo'] === 'E11') {
            unset($regra['entregaveis'][$i]['acompanhamento']);
        }
    }
    $m = new Mundo($regra);
    [$caso] = casoComE11Disponibilizado($m);

    $m->acompanhar->aplicar($caso, 'entrega_falhou', 'DAS', false, 'fato-guia');

    expect($m->excecoes->excecoes)->toBe([])
        ->and($m->eventos->eventos)->toBe([]);
});

it('PublicarFato só aciona o acompanhamento quando o E11 já estava disponibilizado antes da publicação', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();

    $m->fato('entrega_falhou', null, new stdClass, 'DAS');
    expect($m->excecoes->excecoes)->toBe([]); // E11 ainda aguarda insumo

    $m->entregaveis->definirEstado($caso->id, 'E11', EstadoEntregavel::Disponibilizado);
    $m->fato('entrega_falhou', null, (object) ['tentativa' => 2], 'DAS');

    expect($m->excecoes->resumo())->toBe(['entrega_falhou:aberta']);
});
