<?php

use Illuminate\Support\Facades\Route;
use Modules\Equipments\Presentation\Http\Controllers\EquipmentController;
use Modules\Equipments\Presentation\Http\Controllers\EquipmentPhotoController;

// Carregadas pelo RouteServiceProvider do módulo, dentro de Route::middleware('api')->prefix('api')
// — o ->prefix('v1') abaixo fecha o "api/v1" usado no resto do projeto.

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/clients/{id}/equipments', [EquipmentController::class, 'index']);
    Route::post('/clients/{id}/equipments', [EquipmentController::class, 'store']);
    Route::put('/clients/{id}/equipments/{equipmentId}', [EquipmentController::class, 'update']);
    Route::delete('/clients/{id}/equipments/{equipmentId}', [EquipmentController::class, 'destroy']);

    Route::get('/clients/{id}/equipments/{equipmentId}/photos', [EquipmentPhotoController::class, 'index']);
    Route::post('/clients/{id}/equipments/{equipmentId}/photos', [EquipmentPhotoController::class, 'store']);
    Route::delete('/clients/{id}/equipments/{equipmentId}/photos/{photoId}', [EquipmentPhotoController::class, 'destroy']);
});

// Fora do auth:sanctum de propósito, e é a única rota do projeto assim: serve o arquivo da foto
// pra uma tag <img>, que não tem como mandar header Authorization. A credencial é a assinatura da
// URL (middleware `signed`), gerada com validade de 30 minutos pelo EquipmentPhotoResource — quem
// não veio da API autenticada não tem como forjar uma.
Route::prefix('v1')->middleware('signed')->group(function () {
    Route::get('/equipment-photos/{photoId}', [EquipmentPhotoController::class, 'show'])
        ->name('equipment-photos.show');
});
