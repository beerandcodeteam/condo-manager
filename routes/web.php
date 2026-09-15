<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\CondominiumSwitchController;
use App\Support\Panel\PanelRoutes;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'pages::auth.login')->name('login');
});

Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');

Route::middleware('panel')->group(function () {
    Route::post('/condominio-atual/{condominium}', CondominiumSwitchController::class)
        ->name('condominium.switch')
        ->can(PanelRoutes::gateFor('condominium.switch'));
});

Route::middleware(['panel', 'panel.condominium'])->group(function () {
    Route::livewire('/dashboard', 'pages::dashboard')->name('dashboard')->can(PanelRoutes::gateFor('dashboard'));
});
