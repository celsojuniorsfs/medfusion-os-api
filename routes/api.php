<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 configurado em bootstrap/app.php (apiPrefix).

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Rotas de clients, equipments e orders entram nas próximas issues da F4 (#37, #69, #45...).
});
