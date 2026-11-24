<?php

use Illuminate\Support\Facades\Route;
use Webkul\PartInventory\Http\Controllers\PartCategoryController;
use Webkul\PartInventory\Http\Controllers\PartController;
use Webkul\PartInventory\Http\Controllers\ServiceController;

Route::controller(PartController::class)->prefix('parts')->group(function () {
    Route::get('', 'index')->name('admin.parts.index');

    Route::get('create', 'create')->name('admin.parts.create');

    Route::post('', 'store')->name('admin.parts.store');

    Route::get('{id}', 'view')->name('admin.parts.view');

    Route::get('{id}/edit', 'edit')->name('admin.parts.edit');

    Route::put('{id}', 'update')->name('admin.parts.update');

    Route::delete('{id}', 'destroy')->name('admin.parts.destroy');

    Route::post('{id}/movements', 'storeMovement')->name('admin.parts.movements.store');
});

Route::controller(ServiceController::class)->prefix('services-catalog')->group(function () {
    Route::get('', 'index')->name('admin.services.index');

    Route::get('create', 'create')->name('admin.services.create');

    Route::post('', 'store')->name('admin.services.store');

    Route::get('{id}/edit', 'edit')->name('admin.services.edit');

    Route::put('{id}', 'update')->name('admin.services.update');

    Route::delete('{id}', 'destroy')->name('admin.services.destroy');
});

Route::controller(PartCategoryController::class)->prefix('part-categories')->group(function () {
    Route::get('', 'index')->name('admin.part-categories.index');

    Route::post('', 'store')->name('admin.part-categories.store');

    Route::put('{id}', 'update')->name('admin.part-categories.update');

    Route::delete('{id}', 'destroy')->name('admin.part-categories.destroy');
});
