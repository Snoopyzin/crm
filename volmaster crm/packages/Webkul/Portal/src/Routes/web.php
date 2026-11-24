<?php

use Illuminate\Support\Facades\Route;
use Webkul\Portal\Http\Controllers\Auth\PortalForgotPasswordController;
use Webkul\Portal\Http\Controllers\Auth\PortalResetPasswordController;
use Webkul\Portal\Http\Controllers\Auth\PortalSessionController;
use Webkul\Portal\Http\Controllers\PortalDashboardController;
use Webkul\Portal\Http\Controllers\PortalServiceOrderController;
use Webkul\Portal\Http\Controllers\PortalServiceRequestController;
use Webkul\Portal\Http\Controllers\PortalVehicleController;

/*
 * Client-facing portal, entirely separate from /admin: its own session
 * guard ('client'), its own login/forgot/reset flow, own middleware
 * ('portal_auth', not the admin's Bouncer-flavored 'user' alias).
 */
Route::name('portal.')->group(function () {
    Route::controller(PortalSessionController::class)->group(function () {
        Route::get('login', 'create')->name('session.create');
        Route::post('login', 'store')->name('session.store');
        Route::post('logout', 'destroy')->name('session.destroy');
    });

    Route::controller(PortalForgotPasswordController::class)->prefix('forgot-password')->group(function () {
        Route::get('', 'create')->name('forgot_password.create');
        Route::post('', 'store')->name('forgot_password.store');
    });

    Route::controller(PortalResetPasswordController::class)->prefix('reset-password')->group(function () {
        Route::get('{token}', 'create')->name('reset_password.create');
        Route::post('', 'store')->name('reset_password.store');
    });

    Route::middleware(['portal_auth'])->group(function () {
        Route::get('dashboard', [PortalDashboardController::class, 'index'])->name('dashboard.index');

        Route::controller(PortalVehicleController::class)->prefix('vehicles')->name('vehicles.')->group(function () {
            Route::get('', 'index')->name('index');
            Route::get('{id}', 'view')->name('view');
        });

        Route::controller(PortalServiceOrderController::class)->prefix('service-orders')->name('service_orders.')->group(function () {
            Route::get('', 'index')->name('index');
            Route::get('{id}', 'view')->name('view');
            Route::post('{id}/approve', 'approve')->name('approve');
            Route::post('{id}/reject', 'reject')->name('reject');
        });

        Route::controller(PortalServiceRequestController::class)->prefix('service-requests')->name('service_requests.')->group(function () {
            Route::get('', 'index')->name('index');
            Route::get('create', 'create')->name('create');
            Route::post('', 'store')->name('store');
        });
    });
});
