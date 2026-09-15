<?php

use App\Http\Controllers\Api\AreaAvailabilityController;
use App\Http\Controllers\Api\AreaListController;
use App\Http\Controllers\Api\EscalationStoreController;
use App\Http\Controllers\Api\NoticeListController;
use App\Http\Controllers\Api\ReservationCancelController;
use App\Http\Controllers\Api\ReservationListController;
use App\Http\Controllers\Api\ReservationStoreController;
use App\Http\Controllers\Api\ResidentLookupController;
use App\Http\Controllers\Api\TicketListController;
use App\Http\Controllers\Api\TicketShowController;
use App\Http\Controllers\Api\TicketStoreController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware('agent')->group(function () {
    Route::get('/residents/lookup', ResidentLookupController::class)->name('residents_lookup');
    Route::get('/notices', NoticeListController::class)->name('notices_list');
    Route::post('/tickets', TicketStoreController::class)->name('tickets_create');
    Route::get('/tickets', TicketListController::class)->name('tickets_list');
    Route::get('/tickets/{protocol}', TicketShowController::class)->where('protocol', '[0-9]{1,9}')->name('tickets_show');
    Route::get('/areas', AreaListController::class)->name('areas_list');
    Route::get('/areas/{area}/availability', AreaAvailabilityController::class)->name('areas_availability');
    Route::post('/reservations', ReservationStoreController::class)->name('reservations_create');
    Route::get('/reservations', ReservationListController::class)->name('reservations_list');
    Route::delete('/reservations/{reservation}', ReservationCancelController::class)->where('reservation', '[0-9]{1,18}')->name('reservations_cancel');
    Route::post('/escalations', EscalationStoreController::class)->name('escalations_create');
});
