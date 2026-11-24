<?php

use Illuminate\Support\Facades\Route;
use Webkul\WhatsApp\Http\Controllers\WhatsAppWebhookController;

Route::controller(WhatsAppWebhookController::class)->prefix('webhooks/whatsapp')->group(function () {
    Route::get('', 'verify')->name('whatsapp.webhook.verify');

    Route::post('', 'receive')->name('whatsapp.webhook.receive');
});
