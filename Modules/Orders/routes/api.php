<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Presentation\Http\Controllers\OrderController;
use Modules\Orders\Presentation\Http\Controllers\OrderPdfController;

// Carregadas pelo RouteServiceProvider do módulo, dentro de Route::middleware('api')->prefix('api')
// — o ->prefix('v1') abaixo fecha o "api/v1" usado no resto do projeto.

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);

    // Antes de qualquer /orders/{id}, pra "next-number" não ser interpretado como um {id}.
    Route::get('/orders/next-number', [OrderController::class, 'nextNumber']);

    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::put('/orders/{id}', [OrderController::class, 'update']);
    Route::patch('/orders/{id}/status', [OrderController::class, 'updateStatus']);

    Route::post('/orders/{id}/pdf', [OrderPdfController::class, 'store']);
    Route::get('/orders/{id}/pdf', [OrderPdfController::class, 'show']);
});

// Fora do auth:sanctum de propósito, mesmo padrão de Modules/Equipments/routes/api.php: a
// credencial é a assinatura da URL (middleware `signed`), não um header Authorization — permite
// abrir/baixar o PDF direto numa aba, sem o front precisar reenviar o Bearer token.
Route::prefix('v1')->middleware('signed')->group(function () {
    Route::get('/orders/{id}/pdf/download', [OrderPdfController::class, 'download'])
        ->name('orders.pdf.download');
});
