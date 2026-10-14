<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /** Fecha as conexões de cada teste: sem isso, centenas de testes esgotam max_connections do Postgres. */
    protected function tearDown(): void
    {
        $this->app->make('db')->disconnect();
        parent::tearDown();
    }
}
