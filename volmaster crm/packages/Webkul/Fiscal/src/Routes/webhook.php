<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Webkul\Fiscal\Http\Controllers\FocusNfeWebhookController;

/*
 * Public, server-to-server route -- Focus NFe calls this directly, not through
 * an authenticated admin session. Deliberately outside the 'user'/admin_locale
 * middleware group used by every other route in this app.
 */
Route::post('fiscal/focusnfe/webhook', [FocusNfeWebhookController::class, 'handle'])
    ->name('fiscal.focusnfe.webhook')
    ->withoutMiddleware([VerifyCsrfToken::class]);
