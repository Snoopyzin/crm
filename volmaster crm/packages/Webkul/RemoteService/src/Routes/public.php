<?php

use Illuminate\Support\Facades\Route;
use Webkul\RemoteService\Http\Controllers\PublicScheduleController;

/*
 * Public "book a programming slot" link -- no login required. Deliberately
 * outside the admin route group (no 'user'/admin_locale middleware, no
 * '/admin' prefix).
 */
Route::controller(PublicScheduleController::class)->prefix('agendar')->group(function () {
    Route::get('', 'create')->name('remote_service.public.create');

    Route::post('', 'store')->middleware('throttle:5,1')->name('remote_service.public.store');

    Route::get('status/{token}', 'status')->name('remote_service.public.status');
});
