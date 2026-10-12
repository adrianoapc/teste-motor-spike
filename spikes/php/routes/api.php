<?php

declare(strict_types=1);

use App\Api\Controllers\CasoController;
use App\Api\Controllers\CompetenciasController;
use App\Api\Controllers\ConcluirController;
use App\Api\Controllers\FatosController;
use App\Api\Controllers\FilaController;
use App\Api\Controllers\SaudeController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [SaudeController::class, 'health']);
Route::get('/admin/fila', [FilaController::class, 'fila']);

Route::post('/competencias/abrir', [CompetenciasController::class, 'abrir']);
Route::post('/fatos', [FatosController::class, 'publicar']);
Route::get('/casos/{titular_id}/{competencia}', [CasoController::class, 'mostrar']);
Route::post('/casos/{titular_id}/{competencia}/entregaveis/{tipo}/concluir', [ConcluirController::class, 'concluir']);
