<?php

use App\Http\Controllers\Api\AreaController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\EchoController;
use App\Http\Controllers\Api\EscalationController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MessageBufferController;
use App\Http\Controllers\Api\NoticeController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\ResidentController;
use App\Http\Controllers\Api\RuleController;
use App\Http\Controllers\Api\TenantResolveController;
use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware(['platform', 'throttle:60,1'])->group(function () {
    Route::post('/auth/token', TenantResolveController::class)->name('auth_token');
    // Guarda de eco: precisa responder antes de existir tenant, por isso fica no grupo de plataforma.
    Route::post('/echo', [EchoController::class, 'store'])->name('echo_remember');
    Route::get('/echo', [EchoController::class, 'show'])->name('echo_check');
});

// Memória da conversa: usa o token do condomínio, mas não é tool do agente — fica fora
// do grupo `agent` para não entrar no log de chamadas de tool.
Route::prefix('v1')->name('api.v1.')->middleware('conversation')->group(function () {
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations_list');
    Route::post('/conversations', [ConversationController::class, 'store'])->name('conversations_append');
    Route::post('/media', [MediaController::class, 'store'])->name('media_store');
    Route::post('/conversations/buffer', [MessageBufferController::class, 'append'])->name('buffer_append');
    Route::post('/conversations/buffer/flush', [MessageBufferController::class, 'flush'])->name('buffer_flush');
});

Route::prefix('v1')->name('api.v1.')->middleware('agent')->group(function () {
    Route::get('/residents/lookup', [ResidentController::class, 'lookup'])->name('residents_lookup');
    Route::post('/rules/search', [RuleController::class, 'search'])->name('rules_search');
    Route::get('/notices', [NoticeController::class, 'index'])->name('notices_list');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets_create');
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets_list');
    Route::get('/tickets/{protocol}', [TicketController::class, 'show'])->name('tickets_show');
    Route::get('/areas', [AreaController::class, 'index'])->name('areas_list');
    Route::get('/areas/{area}/availability', [AreaController::class, 'availability'])->name('areas_availability');
    Route::post('/reservations', [ReservationController::class, 'store'])->name('reservations_create');
    Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations_list');
    Route::delete('/reservations/{reservation}', [ReservationController::class, 'destroy'])->name('reservations_cancel');
    Route::post('/escalations', [EscalationController::class, 'store'])->name('escalations_create');
});
