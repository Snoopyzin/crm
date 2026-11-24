<?php

use App\Http\Middleware\NormalizeMoneyInput;
use App\Http\Middleware\ServeLandingPage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Webkul\Installer\Http\Middleware\CanInstall;
use Webkul\Tenant\Http\Middleware\ResolveTenant;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(ResolveTenant::class);

        $middleware->append(CanInstall::class);

        $middleware->append(ServeLandingPage::class);

        $middleware->append(NormalizeMoneyInput::class);

        $middleware->encryptCookies(except: [
            'dark_mode',
            'sidebar_collapsed',
        ]);

        $middleware->validateCsrfTokens(except: [
            'admin/mail/inbound-parse',
            'admin/web-forms/forms/*',
        ]);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
