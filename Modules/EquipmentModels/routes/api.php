<?php

use Illuminate\Support\Facades\Route;
use Modules\EquipmentModels\Presentation\Http\Controllers\EquipmentModelController;

// Carregadas pelo RouteServiceProvider do módulo, dentro de Route::middleware('api')->prefix('api')
// — o ->prefix('v1') abaixo fecha o "api/v1" usado no resto do projeto.

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/equipment-models', [EquipmentModelController::class, 'index']);
    Route::post('/equipment-models', [EquipmentModelController::class, 'store']);
});
