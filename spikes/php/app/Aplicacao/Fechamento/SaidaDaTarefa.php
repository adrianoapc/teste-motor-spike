<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

/** Uma saída informada ao concluir uma tarefa (§8): vira um fato fonte=tarefa. */
final readonly class SaidaDaTarefa
{
    /** @param  mixed  $payload  objeto/array preservado como veio (hash canônico) */
    public function __construct(
        public string $tributo,
        public ?int $valorCentavos,
        public mixed $payload,
    ) {}
}
