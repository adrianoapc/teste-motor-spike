<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\Relogio;

final class RelogioFixo implements Relogio
{
    public int $leituras = 0;

    public function __construct(private readonly string $instante = '2026-01-15 12:00:00+00') {}

    public function agora(): string
    {
        $this->leituras++;

        return $this->instante;
    }
}
