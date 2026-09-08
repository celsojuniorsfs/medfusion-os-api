<?php

use App\Modules\Identity\Presentation\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Carregadas pelo IdentityServiceProvider sob o prefixo global api/v1 (bootstrap/app.php).

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});
