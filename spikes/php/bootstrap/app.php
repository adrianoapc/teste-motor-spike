<?php

declare(strict_types=1);

use App\Api\ErrosHttp;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Os textos do corpo valem exatamente como enviados (espaços e "" contam, contrato: minLength/default ""):
        // sem TrimStrings nem ConvertEmptyStringsToNull, que reescreveriam titular_id, tributo etc.
        $middleware->remove([
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ErrosHttp::registrar($exceptions);
    })
    ->create();
