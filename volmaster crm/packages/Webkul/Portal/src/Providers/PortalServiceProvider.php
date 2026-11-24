<?php

namespace Webkul\Portal\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Portal\Http\Middleware\PortalAuth;

class PortalServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'portal');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'portal');

        $this->app['router']->aliasMiddleware('portal_auth', PortalAuth::class);

        Route::middleware(['web'])
            ->prefix('portal')
            ->group(__DIR__.'/../Routes/web.php');

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path'))
            ->group(__DIR__.'/../Routes/admin.php');

        $this->registerInviteButtonHooks();
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerConfig();
    }

    /**
     * Register package config.
     */
    protected function registerConfig(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/menu.php', 'menu.admin'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/acl.php', 'acl'
        );
    }

    /**
     * Inject the "Invite to Portal" button on the Person admin detail page
     * via the view_render_event hook -- no core Blade file is edited.
     * The listener receives the ViewRenderEventManager and registers a
     * template name; it renders that template with whatever params the
     * calling Blade passed to view_render_event() -- here,
     * ['person' => $person] (persons/view.blade.php:51/77) -- so the
     * partial receives $person for free. The equivalent hook on the edit
     * form (persons/edit.blade.php:44) passes no params at all, so it
     * can't be used for this.
     */
    protected function registerInviteButtonHooks(): void
    {
        Event::listen('admin.contact.persons.view.actions.after', function ($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('portal::admin.invite-button');
        });
    }
}
