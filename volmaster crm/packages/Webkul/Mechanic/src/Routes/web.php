<?php

use Illuminate\Support\Facades\Route;
use Webkul\Mechanic\Http\Controllers\MechanicController;

Route::controller(MechanicController::class)->prefix('mechanics')->group(function () {
    Route::get('', 'index')->name('admin.mechanics.index');

    Route::get('create', 'create')->name('admin.mechanics.create');

    Route::post('', 'store')->name('admin.mechanics.store');

    Route::get('{id}', 'view')->name('admin.mechanics.view');

    Route::get('{id}/edit', 'edit')->name('admin.mechanics.edit');

    Route::put('{id}', 'update')->name('admin.mechanics.update');

    Route::delete('{id}', 'destroy')->name('admin.mechanics.destroy');
});
