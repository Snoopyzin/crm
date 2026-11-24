<?php

use Illuminate\Support\Facades\Route;
use Webkul\Fiscal\Http\Controllers\FiscalController;
use Webkul\Fiscal\Http\Controllers\FiscalSettingsController;

Route::controller(FiscalController::class)->prefix('fiscal-documents')->group(function () {
    Route::get('', 'index')->name('admin.fiscal_documents.index');

    Route::get('{id}', 'view')->name('admin.fiscal_documents.view');
});

Route::get('service-orders/{serviceOrderId}/fiscal/emit-nfse', [FiscalController::class, 'create'])
    ->name('admin.fiscal_documents.create');

Route::post('service-orders/{serviceOrderId}/fiscal/emit-nfse', [FiscalController::class, 'emitForServiceOrder'])
    ->name('admin.fiscal_documents.emit-nfe');

Route::get('service-orders/{serviceOrderId}/fiscal/emit-nfe-produto', [FiscalController::class, 'createNfe'])
    ->name('admin.fiscal_documents.create-nfe-produto');

Route::post('service-orders/{serviceOrderId}/fiscal/emit-nfe-produto', [FiscalController::class, 'emitNfeForServiceOrder'])
    ->name('admin.fiscal_documents.emit-nfe-produto');

Route::controller(FiscalSettingsController::class)->prefix('settings/fiscal-settings')->group(function () {
    Route::get('', 'edit')->name('admin.settings.fiscal_settings.edit');

    Route::put('', 'update')->name('admin.settings.fiscal_settings.update');
});
