<?php

use Illuminate\Support\Facades\Route;
use Webkul\Portal\Http\Controllers\Admin\PortalInviteController;
use Webkul\Portal\Http\Controllers\Admin\ServiceRequestController;

Route::post('portal/persons/{personId}/invite', [PortalInviteController::class, 'store'])
    ->name('admin.portal.invite');

Route::controller(ServiceRequestController::class)->prefix('service-requests')->group(function () {
    Route::get('', 'index')->name('admin.service_requests.index');
    Route::get('{id}', 'view')->name('admin.service_requests.view');
    Route::post('{id}/convert', 'convert')->name('admin.service_requests.convert');
    Route::post('{id}/link-order', 'linkOrder')->name('admin.service_requests.link_order');
    Route::post('{id}/reject', 'reject')->name('admin.service_requests.reject');
});
