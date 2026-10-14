<?php

declare(strict_types=1);

// Teste de arquitetura das camadas (Requisito 11.2).
// dominio: nada de framework, banco ou outras camadas.
// aplicacao: não depende de api nem de driver de banco concreto.

arch('todo arquivo declara strict_types')
    ->expect('App')
    ->toUseStrictTypes();

arch('dominio não depende de Illuminate, PDO ou outras camadas')
    ->expect('App\Dominio')
    ->not->toUse([
        'Illuminate',
        'PDO',
        'App\Aplicacao',
        'App\Infraestrutura',
        'App\Api',
    ]);

arch('aplicacao não depende de api, infraestrutura ou PDO')
    ->expect('App\Aplicacao')
    ->not->toUse([
        'App\Api',
        'App\Infraestrutura',
        'PDO',
    ]);

arch('infraestrutura não depende de api')
    ->expect('App\Infraestrutura')
    ->not->toUse('App\Api');
