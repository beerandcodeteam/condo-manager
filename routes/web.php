<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\CondominiumSwitchController;
use App\Http\Controllers\TicketPhotoController;
use App\Http\Middleware\SetPanelCondominium;
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

Route::middleware(['panel', 'panel.condominium:'.SetPanelCondominium::OPTIONAL])->prefix('plataforma')->group(function () {
    Route::livewire('/condominios', 'pages::platform.condominiums')->name('condominiums.index')->can(PanelRoutes::gateFor('condominiums.index'));
    Route::livewire('/usuarios', 'pages::platform.users')->name('users.index')->can(PanelRoutes::gateFor('users.index'));
});

Route::middleware(['panel', 'panel.condominium'])->group(function () {
    Route::livewire('/dashboard', 'pages::dashboard')->name('dashboard')->can(PanelRoutes::gateFor('dashboard'));
    Route::livewire('/chamados', 'pages::tickets')->name('tickets.index')->can(PanelRoutes::gateFor('tickets.index'));
    Route::get('/chamados/fotos/{photo}', TicketPhotoController::class)->name('tickets.photos.show')->can(PanelRoutes::gateFor('tickets.photos.show'));
    Route::livewire('/comunicados', 'pages::notices')->name('notices.index')->can(PanelRoutes::gateFor('notices.index'));
    Route::livewire('/moradores', 'pages::residents')->name('residents.index')->can(PanelRoutes::gateFor('residents.index'));
    Route::livewire('/configuracoes', 'pages::settings')->name('settings')->can(PanelRoutes::gateFor('settings'));
});
