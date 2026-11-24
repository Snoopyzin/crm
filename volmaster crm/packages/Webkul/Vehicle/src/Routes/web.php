<?php

use Illuminate\Support\Facades\Route;
use Webkul\Vehicle\Http\Controllers\VehicleController;

Route::controller(VehicleController::class)->prefix('vehicles')->group(function () {
    Route::get('', 'index')->name('admin.vehicles.index');

    Route::get('create', 'create')->name('admin.vehicles.create');

    Route::post('', 'store')->name('admin.vehicles.store');

    Route::get('{id}', 'view')->name('admin.vehicles.view');

    Route::get('{id}/edit', 'edit')->name('admin.vehicles.edit');

    Route::put('{id}', 'update')->name('admin.vehicles.update');

    Route::delete('{id}', 'destroy')->name('admin.vehicles.destroy');
});
