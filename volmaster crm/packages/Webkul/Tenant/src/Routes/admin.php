<?php

use Illuminate\Support\Facades\Route;
use Webkul\Tenant\Http\Controllers\Admin\FiscalOnboardingController;
use Webkul\Tenant\Http\Controllers\Admin\TenantController;

Route::controller(TenantController::class)->prefix('tenants')->group(function () {
    Route::get('', 'index')->name('admin.tenants.index');
});

Route::controller(FiscalOnboardingController::class)->prefix('tenants/fiscal-requests')->group(function () {
    Route::post('{id}/approve', 'approve')->name('admin.tenants.fiscal_requests.approve');

    Route::post('{id}/reject', 'reject')->name('admin.tenants.fiscal_requests.reject');
});
