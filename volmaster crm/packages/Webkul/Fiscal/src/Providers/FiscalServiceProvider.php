<?php

namespace Webkul\Fiscal\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FiscalServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path'))
            ->group(__DIR__.'/../Routes/web.php');

        // Public, server-to-server webhook -- intentionally NOT behind admin auth.
        Route::middleware(['web'])
            ->group(__DIR__.'/../Routes/webhook.php');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'fiscal');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'fiscal');
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConfig();
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/menu.php', 'menu.admin'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/acl.php', 'acl'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/focusnfe.php', 'focusnfe'
        );
    }
}
