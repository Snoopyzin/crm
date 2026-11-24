<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Webkul\ServiceOrder\Http\Controllers\AsaasWebhookController;
use Webkul\ServiceOrder\Http\Controllers\PublicFeedbackController;
use Webkul\ServiceOrder\Http\Controllers\PublicTrackingController;
use Webkul\ServiceOrder\Http\Controllers\PwaController;
use Webkul\ServiceOrder\Http\Controllers\TermoPublicoController;

// Termo de entrada: o cliente aceita pelo link enviado ao WhatsApp (B2 do roteiro).
Route::controller(TermoPublicoController::class)->prefix('termo')->group(function () {
    Route::get('{token}', 'show')->name('service_orders.termo.show');
    Route::post('{token}', 'aceitar')->middleware('throttle:30,1')->name('service_orders.termo.aceitar');
});

Route::controller(PublicFeedbackController::class)->prefix('pos-venda')->group(function () {
    Route::get('{token}', 'show')->name('service_orders.feedback.show');

    Route::post('{token}', 'respond')->name('service_orders.feedback.respond');
});

Route::get('acompanhar/{id}', [PublicTrackingController::class, 'show'])
    ->middleware('signed')
    ->name('service_orders.tracking.show');

// App instalável (PWA) e notificações no celular. Públicas: o service worker
// não tem sessão; as confirmações usam o token aleatório do alerta.
Route::get('workshop.webmanifest', [PwaController::class, 'manifest'])->name('workshop.pwa.manifest');
Route::get('dono.webmanifest', [PwaController::class, 'manifestDono'])->name('workshop.pwa.manifest_dono');
Route::get('workshop-sw.js', [PwaController::class, 'serviceWorker'])->name('workshop.pwa.sw');
Route::get('workshop-icone-{size}.png', [PwaController::class, 'icon'])->whereNumber('size')->name('workshop.pwa.icon');
Route::get('workshop/push/chave', [PwaController::class, 'vapidKey'])->name('workshop.push.key');
Route::post('workshop/push/confirmar/{token}/{status}', [PwaController::class, 'ack'])
    ->middleware('throttle:120,1')
    ->name('workshop.push.ack')
    ->withoutMiddleware([VerifyCsrfToken::class]);

// Link curto dos SMS: abre a OS (pede login se necessário).
Route::get('os/{id}', fn (int $id) => redirect()->route('admin.service_orders.view', $id))
    ->whereNumber('id')
    ->name('workshop.os.short');

/*
 * Public, server-to-server route -- Asaas calls this directly, not through
 * an authenticated admin session.
 */
Route::post('service-orders/asaas/webhook', [AsaasWebhookController::class, 'handle'])
    ->name('service_orders.asaas.webhook')
    ->withoutMiddleware([VerifyCsrfToken::class]);
