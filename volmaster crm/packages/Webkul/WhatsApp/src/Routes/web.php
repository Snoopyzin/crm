<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\WhatsAppSettingsController;

Route::controller(WhatsAppSettingsController::class)->prefix('settings/whatsapp-settings')->group(function () {
    Route::get('', 'edit')->name('admin.settings.whatsapp_settings.edit');

    Route::put('', 'update')->name('admin.settings.whatsapp_settings.update');
});
