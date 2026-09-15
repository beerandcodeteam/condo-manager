<?php

use App\Http\Controllers\Api\ResidentLookupController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware('agent')->group(function () {
    Route::get('/residents/lookup', ResidentLookupController::class)->name('residents_lookup');
});
