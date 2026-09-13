<?php

use Illuminate\Support\Facades\Route;
use Modules\Equipments\Presentation\Http\Controllers\EquipmentController;

// Carregadas pelo RouteServiceProvider do módulo, dentro de Route::middleware('api')->prefix('api')
// — o ->prefix('v1') abaixo fecha o "api/v1" usado no resto do projeto.

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/clients/{id}/equipments', [EquipmentController::class, 'index']);
    Route::post('/clients/{id}/equipments', [EquipmentController::class, 'store']);
    Route::put('/clients/{id}/equipments/{equipmentId}', [EquipmentController::class, 'update']);
    Route::delete('/clients/{id}/equipments/{equipmentId}', [EquipmentController::class, 'destroy']);
});
