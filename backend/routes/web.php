<?php

use App\Http\Controllers\DevLoginController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\SsoController;
use App\Http\Middleware\EnsurePanelSession;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

// Fluxo da CW App Store
Route::get('/install', [InstallController::class, 'install'])->name('install');
Route::get('/oauth/callback', [InstallController::class, 'callback'])->name('oauth.callback');
Route::get('/sso', SsoController::class)->name('sso');

// Painel da loja
Route::middleware(EnsurePanelSession::class)->prefix('painel')->group(function () {
    Route::get('/', [PanelController::class, 'show'])->name('panel');
    Route::post('/codigo', [PanelController::class, 'createPairingCode'])->name('panel.code');
    Route::post('/teste', [PanelController::class, 'testAlarm'])->name('panel.test');
    Route::delete('/celulares/{deviceId}', [PanelController::class, 'deleteDevice'])->name('panel.device.delete');
    Route::post('/sair', [PanelController::class, 'logout'])->name('panel.logout');
});

// Só pra teste local (gerado por: php artisan alarme:loja-teste)
if (app()->environment('local')) {
    Route::get('/dev/entrar/{installation}', DevLoginController::class)
        ->middleware('signed')
        ->name('dev.login');
}
