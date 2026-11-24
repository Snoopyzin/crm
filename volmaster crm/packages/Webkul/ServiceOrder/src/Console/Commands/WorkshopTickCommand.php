<?php

namespace Webkul\ServiceOrder\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\ServiceOrder\Services\AlertDispatcher;
use Webkul\ServiceOrder\Services\BarrierEngine;
use Webkul\ServiceOrder\Services\Bonus\FechamentoService;
use Webkul\ServiceOrder\Services\PeriodicAlerts;
use Webkul\ServiceOrder\Services\StageGate;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\Tenant\Models\Tenant;

/**
 * Verificação a cada minuto das barreiras de prazo e da entrega de alertas
 * (agrupamento, silêncio e SMS de reserva). Percorre a instalação principal
 * e cada oficina ativa, cada uma com o próprio banco.
 *
 * Na HostGator, basta um cron de 1 minuto chamando o agendador do Laravel:
 *   * * * * * cd /caminho/do/projeto && php artisan schedule:run >> /dev/null 2>&1
 */
class WorkshopTickCommand extends Command
{
    protected $signature = 'workshop:tick {--tenant= : Subdomínio de uma única oficina}';

    protected $description = 'Verifica as barreiras de prazo e dispara os alertas vencidos';

    public function handle(): int
    {
        $original = Config::get('database.connections.mysql');
        $total = 0;

        try {
            foreach ($this->contexts() as $tenant) {
                $total += $this->runFor($tenant, $original);
            }
        } finally {
            // Volta a conexão para a instalação principal.
            Config::set('database.connections.mysql', $original);
            DB::purge('mysql');
            app()->forgetInstance('currentTenant');
            WorkshopSettings::flush();
        }

        $this->info("Alertas disparados: {$total}");

        return self::SUCCESS;
    }

    /**
     * null = instalação principal; depois, cada oficina ativa.
     *
     * @return array<int, Tenant|null>
     */
    protected function contexts(): array
    {
        if ($subdomain = $this->option('tenant')) {
            return [Tenant::where('subdomain', $subdomain)->firstOrFail()];
        }

        $tenants = [];

        try {
            $tenants = Tenant::where('status', Tenant::STATUS_ACTIVE)->get()->all();
        } catch (\Throwable) {
            // Sem diretório de oficinas (instalação de uma oficina só).
        }

        return array_merge([null], $tenants);
    }

    protected function runFor(?Tenant $tenant, array $original): int
    {
        Config::set('database.connections.mysql', $original);

        if ($tenant) {
            Config::set('database.connections.mysql.host', $tenant->db_host ?: config('database.connections.landlord.host'));
            Config::set('database.connections.mysql.database', $tenant->db_name);
            Config::set('database.connections.mysql.username', $tenant->db_username);
            Config::set('database.connections.mysql.password', $tenant->db_password);

            app()->instance('currentTenant', $tenant);
        } else {
            app()->forgetInstance('currentTenant');
        }

        DB::purge('mysql');
        WorkshopSettings::flush();

        try {
            if (! Schema::hasTable('barrier_instances') || ! StageGate::active()) {
                return 0;
            }

            $fired = app(BarrierEngine::class)->tick();

            // OS parada, indicador vermelho sem plano, RH a vencer (no máximo 1 vez por hora).
            app(PeriodicAlerts::class)->runIfDue();

            // Dia 1: congela os indicadores, detecta travas e calcula as notas do mês anterior.
            app(FechamentoService::class)->runIfDue();

            app(AlertDispatcher::class)->flush(now());

            WorkshopSettings::set('ultimo_tick', now()->toIso8601String());

            return $fired;
        } catch (\Throwable $exception) {
            // Uma oficina com problema não pode impedir a verificação das outras.
            $this->error(($tenant?->subdomain ?? 'principal').': '.$exception->getMessage());
            report($exception);

            return 0;
        }
    }
}
