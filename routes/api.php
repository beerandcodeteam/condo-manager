<?php

use App\Http\Controllers\Api\NoticeListController;
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
});
