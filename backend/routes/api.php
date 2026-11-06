<?php

use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

// Webhook de pedidos (URL cadastrada no app da CW App Store)
Route::post('/webhooks/cardapioweb', WebhookController::class)->name('webhook');

// App Android
Route::post('/devices/pair', [DeviceController::class, 'pair'])->middleware('throttle:10,1');

Route::middleware(AuthenticateDevice::class)->prefix('devices/me')->group(function () {
    Route::get('/', [DeviceController::class, 'me']);
    Route::put('/fcm-token', [DeviceController::class, 'updateFcmToken']);
    Route::post('/test', [DeviceController::class, 'test'])->middleware('throttle:6,1');
    Route::delete('/', [DeviceController::class, 'unpair']);
});
