<?php

namespace Webkul\Tenant\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Tenant\Console\Commands\MigrateTenantsCommand;
use Webkul\Tenant\Provisioning\CpanelProvisioner;
use Webkul\Tenant\Provisioning\LocalProvisioner;
use Webkul\Tenant\Provisioning\TenantProvisionerInterface;

class TenantServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::middleware(['web'])
            ->group(__DIR__.'/../Routes/web.php');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path'))
            ->group(__DIR__.'/../Routes/admin.php');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'tenant');

        if ($this->app->runningInConsole()) {
            $this->commands([MigrateTenantsCommand::class]);
        }
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/tenant.php', 'tenant');

        $this->app->bind(TenantProvisionerInterface::class, function () {
            if (config('tenant.provisioner') === 'cpanel') {
                return new CpanelProvisioner(
                    config('tenant.cpanel.host'),
                    config('tenant.cpanel.username'),
                    config('tenant.cpanel.api_token'),
                );
            }

            return new LocalProvisioner;
        });
    }
}
