<?php

declare(strict_types=1);

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

return [
    'default' => 'stdout',

    'channels' => [
        // Uma linha JSON por registro, em stdout (Requisito 1.5; design: Monolog + JsonFormatter).
        'stdout' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stdout',
            ],
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'appendNewline' => true,
            ],
            'processors' => [],
        ],
    ],
];
