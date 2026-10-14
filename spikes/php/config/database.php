<?php

declare(strict_types=1);

return [
    'default' => 'pgsql',

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            // Única fonte da conexão: a variável de ambiente MOTOR_DB (ex.:
            // pgsql://spike:spike@postgres:5432/spike_php). Nenhum valor de
            // conexão fixo no código ou na imagem (Requisito 1.2).
            'url' => env('MOTOR_DB'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ],

    // Sem migrações do Laravel: o esquema é a base comum em db/migrations/.
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
