<?php

use Illuminate\Support\Facades\Route;
use Webkul\WorkBay\Http\Controllers\WorkBayController;

Route::controller(WorkBayController::class)->prefix('work-bays')->group(function () {
    Route::get('', 'index')->name('admin.work_bays.index');

    Route::get('create', 'create')->name('admin.work_bays.create');

    Route::post('', 'store')->name('admin.work_bays.store');

    Route::get('{id}', 'view')->name('admin.work_bays.view');

    Route::get('{id}/edit', 'edit')->name('admin.work_bays.edit');

    Route::put('{id}', 'update')->name('admin.work_bays.update');

    Route::delete('{id}', 'destroy')->name('admin.work_bays.destroy');

    Route::post('{id}/assignments', 'storeAssignment')->name('admin.work_bays.assignments.store');

    Route::delete('{id}/assignments/{assignmentId}', 'destroyAssignment')->name('admin.work_bays.assignments.destroy');
});
