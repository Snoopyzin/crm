<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * The public/storage symlink is now (re)created per-request by
     * Webkul\Tenant\Http\Middleware\ResolveTenant, once it knows which
     * tenant's persistent storage root applies -- that information isn't
     * available yet this early in the boot cycle.
     */
    public function boot(): void
    {
        //
    }
}
