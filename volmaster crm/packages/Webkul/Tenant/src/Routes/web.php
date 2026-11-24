<?php

use Illuminate\Support\Facades\Route;
use Webkul\Tenant\Http\Controllers\SignupController;

Route::controller(SignupController::class)->prefix('signup')->group(function () {
    Route::get('', 'create')->name('tenant.signup.create');

    Route::post('', 'store')->name('tenant.signup.store');
});
