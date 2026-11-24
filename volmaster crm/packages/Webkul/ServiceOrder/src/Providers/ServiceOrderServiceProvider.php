<?php

namespace Webkul\ServiceOrder\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Fiscal\Models\FiscalDocument;
use Webkul\ServiceOrder\Console\Commands\WorkshopBackupCommand;
use Webkul\ServiceOrder\Console\Commands\WorkshopTickCommand;
use Webkul\ServiceOrder\Console\Commands\WorkshopVapidKeysCommand;
use Webkul\ServiceOrder\Http\Middleware\DonoForaDoFluxo;
use Webkul\ServiceOrder\Http\Middleware\EntradaPorCargo;
use Webkul\ServiceOrder\Http\Middleware\PerfilSomenteLeitura;
use Webkul\ServiceOrder\Http\Middleware\RegistraAcessoSensivel;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Observers\ServiceOrderObserver;
use Webkul\ServiceOrder\Services\CodigoSequencial;
use Webkul\ServiceOrder\Services\OficinaPadrao;

class ServiceOrderServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Cada cargo entra direto na própria tela (só com o piloto ligado).
        $this->app['router']->pushMiddlewareToGroup('web', EntradaPorCargo::class);

        Route::middleware(['web', 'admin_locale', 'user', DonoForaDoFluxo::class, PerfilSomenteLeitura::class, RegistraAcessoSensivel::class])
            ->prefix(config('app.admin_path'))
            ->group(__DIR__.'/../Routes/web.php');

        // Public, unauthenticated -- reached from the WhatsApp Pós-Venda link.
        Route::middleware(['web'])
            ->group(__DIR__.'/../Routes/public.php');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'service_order');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'service_order');

        ServiceOrder::observe(ServiceOrderObserver::class);

        // Código sequencial (1, 2, 3...) de cada cliente e de cada mecânico.
        CodigoSequencial::registrar();

        // "R$ 10.000,00" money inputs on every admin page (<input data-money>).
        Event::listen('admin.layout.body.after', function ($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('service_order::components.money-mask');

            // Sino de alertas + inscrição para notificações no celular (piloto).
            $viewRenderEventManager->addTemplate('service_order::components.workshop-bell');
        });

        // Cor de crédito do cliente (B1) na página do cliente, sem editar o núcleo do Admin.
        Event::listen('admin.contact.persons.view.right.before', function ($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('service_order::components.credito-cliente');
        });

        // Impact e Recomendações saíram do menu lateral e ficam em Ajuda e recursos.
        Event::listen('admin.help.index.resources.after', function ($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('service_order::components.help-extras');
        });

        // "Testar notificação" no perfil do usuário (Minha Conta), sem editar o núcleo do Admin.
        Event::listen('admin.user.account.form.after', function ($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('service_order::components.account-push');
        });

        // Manifesto do app instalável (PWA) para as notificações no celular.
        Event::listen('admin.layout.head.after', function ($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('service_order::components.workshop-head');
        });

        // Nota fiscal autorizada (pela Focus, em qualquer caminho) entra no log da OS.
        FiscalDocument::saved(function (FiscalDocument $document) {
            if ($document->status !== FiscalDocument::STATUS_AUTORIZADO || ! $document->quote_id) {
                return;
            }

            if (! $document->wasRecentlyCreated && ! $document->wasChanged('status')) {
                return;
            }

            ServiceOrderEvent::record((int) $document->quote_id, 'nf_emitida', [
                'origem' => 'focus',
                'documento_id' => $document->id,
                'tipo' => $document->type,
                'numero' => $document->numero,
            ]);
        });

        // Oficina nova: o semeador do instalador apaga atributos e funções que as migrations criaram.
        Event::listen('tenant.provision.after', fn () => app(OficinaPadrao::class)->garantir());

        if ($this->app->runningInConsole()) {
            $this->commands([WorkshopTickCommand::class, WorkshopVapidKeysCommand::class, WorkshopBackupCommand::class]);
        }

        // Verificação das barreiras a cada minuto. Precisa do cron de 1 minuto
        // do servidor chamando `php artisan schedule:run`.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('workshop:tick')->everyMinute()->withoutOverlapping(10);

            // Backup diário do banco de cada oficina (madrugada).
            $schedule->command('workshop:backup')->dailyAt('02:30')->withoutOverlapping(60);
        });
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
            dirname(__DIR__).'/Config/asaas.php', 'asaas'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/perfis.php', 'perfis'
        );
    }
}
