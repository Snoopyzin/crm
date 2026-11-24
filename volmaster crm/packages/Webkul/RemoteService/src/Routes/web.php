<?php

use Illuminate\Support\Facades\Route;
use Webkul\RemoteService\Http\Controllers\RemoteServiceController;
use Webkul\RemoteService\Http\Controllers\RemoteServiceTypeController;

Route::controller(RemoteServiceTypeController::class)->prefix('remote-services/types')->group(function () {
    Route::get('', 'index')->name('admin.remote_services.types.index');

    Route::get('create', 'create')->name('admin.remote_services.types.create');

    Route::post('create', 'store')->name('admin.remote_services.types.store');

    Route::get('edit/{id}', 'edit')->name('admin.remote_services.types.edit');

    Route::put('edit/{id}', 'update')->name('admin.remote_services.types.update');

    Route::delete('{id}', 'destroy')->name('admin.remote_services.types.destroy');
});

Route::controller(RemoteServiceController::class)->prefix('remote-services')->group(function () {
    Route::get('', 'index')->name('admin.remote_services.index');

    Route::get('create', 'create')->name('admin.remote_services.create');

    Route::post('', 'store')->name('admin.remote_services.store');

    Route::get('vehicles-by-person/{personId}', 'vehiclesByPerson')->name('admin.remote_services.vehicles-by-person');

    Route::get('search-persons', 'searchPersons')->name('admin.remote_services.search-persons');

    Route::get('lookup-by-teamviewer/{teamviewerId}', 'lookupByTeamviewer')->name('admin.remote_services.lookup-by-teamviewer');

    Route::get('{id}', 'view')->name('admin.remote_services.view');

    Route::post('{id}/finish', 'finish')->name('admin.remote_services.finish');

    Route::put('{id}/start', 'start')->name('admin.remote_services.start');

    Route::put('{id}/approve', 'approve')->name('admin.remote_services.approve');

    Route::put('{id}/teamviewer', 'updateTeamviewer')->name('admin.remote_services.update-teamviewer');

    Route::delete('{id}', 'destroy')->name('admin.remote_services.destroy');
});
