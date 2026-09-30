<?php

use Illuminate\Support\Facades\Route;
use Modules\Alerts\Presentation\Http\Controllers\AlertController;
use Modules\Alerts\Presentation\Http\Controllers\EquipmentRevisionAlertController;

// Carregadas pelo RouteServiceProvider do módulo, dentro de Route::middleware('api')->prefix('api')
// — o ->prefix('v1') abaixo fecha o "api/v1" usado no resto do projeto.

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/alerts', [AlertController::class, 'index']);
    Route::patch('/alerts/revisions/{id}/contacted', [EquipmentRevisionAlertController::class, 'contacted']);
});
