<?php

declare(strict_types=1);

// Teste de arquitetura das camadas (Requisitos 11.2 e 12.1).
//   dominio:        só biblioteca padrão do PHP (nada de framework, banco ou outras camadas).
//   aplicacao:      só dominio + biblioteca padrão. As portas (interfaces) ficam em Aplicacao\Portas e são
//                   implementadas em Infraestrutura; nenhum framework, driver de banco ou SQL.
//   infraestrutura: dominio + aplicacao (+ framework/driver); nunca api.
//
// Cada regra FALHA se alguém reintroduzir a dependência proibida.

/** Funções globais do Laravel que escondem acesso ao framework (container, config, HTTP, relógio...). */
const HELPERS_DO_FRAMEWORK = [
    'app', 'resolve', 'config', 'env', 'cache', 'session', 'request', 'response', 'redirect', 'view',
    'abort', 'abort_if', 'abort_unless', 'auth', 'event', 'dispatch', 'logger', 'info', 'report',
    'now', 'today', 'collect', 'data_get', 'optional', 'tap', 'cookie', 'route', 'url', 'storage_path',
    'base_path', 'app_path',
];

const PACOTES_DE_FRAMEWORK = ['Illuminate', 'Laravel', 'Symfony', 'PDO', 'Psr', 'Carbon'];

/** Registros de dados das portas (o resto de Aplicacao\Portas são interfaces). */
const DTOS_DAS_PORTAS = [
    'App\Aplicacao\Portas\CatalogoConferencia',
    'App\Aplicacao\Portas\CatalogoEntregavel',
    'App\Aplicacao\Portas\ConferenciaVigente',
    'App\Aplicacao\Portas\DependenciaGravada',
    'App\Aplicacao\Portas\DivergenciaBloqueante',
    'App\Aplicacao\Portas\ExcecaoAberta',
    'App\Aplicacao\Portas\TarefaAberta',
];

/** Entrada e saída dos casos de uso (e a Empresa, que atravessa Api -> Aplicacao -> Infraestrutura). */
const DTOS_DOS_CASOS_DE_USO = [
    'App\Aplicacao\Fechamento\AbrirCompetenciasComando',
    'App\Aplicacao\Fechamento\CasoDaEmpresa',
    'App\Aplicacao\Fechamento\ResultadoAbertura',
    'App\Aplicacao\Fechamento\SaidaDaTarefa',
    'App\Aplicacao\Fechamento\ConcluirTarefaComando',
    'App\Aplicacao\Fechamento\RegistrarOverrideComando',
    'App\Aplicacao\Fechamento\ResultadoEntregavel',
    'App\Aplicacao\Fechamento\CasoConsultado',
    'App\Aplicacao\Fechamento\EntregavelConsultado',
    'App\Aplicacao\Fechamento\TarefaConsultada',
    'App\Aplicacao\Fechamento\ExcecaoConsultada',
    'App\Aplicacao\Fatos\PublicarFatoComando',
    'App\Aplicacao\Fatos\ResultadoFato',
    'App\Dominio\Fechamento\Empresa',
];

arch('todo arquivo declara strict_types')
    ->expect('App')
    ->toUseStrictTypes();

arch('dominio não depende de framework, banco ou outras camadas')
    ->expect('App\Dominio')
    ->not->toUse([
        ...PACOTES_DE_FRAMEWORK,
        'App\Aplicacao',
        'App\Infraestrutura',
        'App\Api',
        'App\Providers',
    ]);

arch('dominio não usa helpers globais do framework')
    ->expect('App\Dominio')
    ->not->toUse(HELPERS_DO_FRAMEWORK);

arch('aplicacao não depende de framework, driver de banco, api, infraestrutura nem providers')
    ->expect('App\Aplicacao')
    ->not->toUse([
        ...PACOTES_DE_FRAMEWORK,
        'App\Api',
        'App\Infraestrutura',
        'App\Providers',
    ]);

arch('aplicacao não usa helpers globais do framework')
    ->expect('App\Aplicacao')
    ->not->toUse(HELPERS_DO_FRAMEWORK);

arch('aplicacao só conhece dominio e a si mesma')
    ->expect('App\Aplicacao')
    ->toOnlyUse(['App\Dominio', 'App\Aplicacao']);

arch('portas da aplicacao são interfaces (fora os registros de dados)')
    ->expect('App\Aplicacao\Portas')
    ->toBeInterfaces()
    ->ignoring(DTOS_DAS_PORTAS);

arch('registros de dados das portas são final readonly')
    ->expect(DTOS_DAS_PORTAS)
    ->toBeFinal()
    ->toBeReadonly();

arch('entrada e saída dos casos de uso são final readonly')
    ->expect(DTOS_DOS_CASOS_DE_USO)
    ->toBeFinal()
    ->toBeReadonly();

arch('testes de unidade (domínio e aplicação puros) rodam sem banco nem framework')
    ->expect('Tests\Unidade')
    ->not->toUse([...PACOTES_DE_FRAMEWORK, 'Tests\TestCase', 'Tests\Integracao', 'App\Infraestrutura', 'App\Api', 'App\Providers']);

arch('infraestrutura não depende de api')
    ->expect('App\Infraestrutura')
    ->not->toUse('App\Api');
