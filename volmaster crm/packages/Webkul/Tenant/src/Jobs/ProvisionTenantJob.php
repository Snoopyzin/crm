<?php

namespace Webkul\Tenant\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Installer\Database\Seeders\DatabaseSeeder;
use Webkul\Tenant\Models\Tenant;
use Webkul\Tenant\Provisioning\TenantProvisionerInterface;

class ProvisionTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  string  $adminPasswordHash  Already-hashed (bcrypt) admin
     *                                     password -- hashed once in SignupController so the plaintext
     *                                     password is never persisted in the tenants table or the queued
     *                                     job payload.
     */
    public function __construct(protected Tenant $tenant, protected string $adminPasswordHash) {}

    public function handle(TenantProvisionerInterface $provisioner): void
    {
        // Com a fila "sync" isto roda dentro da requisição do /signup: fechar a aba
        // ou estourar o tempo deixaria o banco criado e o status "provisioning" para sempre.
        ignore_user_abort(true);
        @set_time_limit(0);

        $tenant = $this->tenant->fresh();

        $originalConnection = Config::get('database.connections.mysql');

        try {
            $tenant->update(['status' => Tenant::STATUS_PROVISIONING]);

            $this->assignCredentials($tenant);

            $this->markStep($tenant, 'creating_database');
            $provisioner->createDatabase($tenant->db_name, $tenant->db_username, $tenant->db_password);

            $this->pointDefaultConnectionAtTenant($tenant);

            $this->markStep($tenant, 'migrating');
            Artisan::call('migrate', ['--force' => true]);

            $this->markStep($tenant, 'seeding');
            app(DatabaseSeeder::class)->run([
                'locale' => 'pt_BR',
                'currency' => 'BRL',
            ]);

            $this->markStep($tenant, 'creating_admin');
            DB::table('users')->updateOrInsert(
                ['id' => 1],
                [
                    'name' => $tenant->admin_name,
                    'email' => $tenant->admin_email,
                    'password' => $this->adminPasswordHash,
                    'role_id' => 1,
                    'status' => 1,
                ]
            );

            // O semeador do instalador apaga dados que migrations criam (atributos do cliente, funções):
            // quem precisa deles refaz aqui, ainda no banco da oficina nova. Só gravações simples e idempotentes:
            // uma exceção de um ouvinte falha o provisionamento.
            $this->markStep($tenant, 'finishing');
            Event::dispatch('tenant.provision.after', $tenant);

            $tenant->update([
                'status' => Tenant::STATUS_ACTIVE,
                'provisioning_step' => 'done',
                'provisioning_error' => null,
            ]);
        } catch (Throwable $e) {
            $this->handleFailure($provisioner, $tenant, $e);
        } finally {
            Config::set('database.connections.mysql', $originalConnection);
            DB::purge('mysql');
        }
    }

    /**
     * Generate the database name/user/password for this tenant, respecting
     * cPanel's forced account prefix, and save them (encrypted) on the row.
     */
    protected function assignCredentials(Tenant $tenant): void
    {
        $prefix = config('tenant.db_prefix');

        $slug = Str::slug($tenant->subdomain, '_');

        // MySQL usernames are capped at 32 characters (database names allow
        // 64), so the shorter limit is used for both to keep db_name and
        // db_username identical -- simpler to reason about than two
        // independently-truncated values.
        $identifier = Str::limit($prefix.$slug, 32, '');

        $tenant->update([
            'db_name' => $identifier,
            'db_username' => $identifier,
            'db_password' => Str::password(24),
        ]);
    }

    protected function pointDefaultConnectionAtTenant(Tenant $tenant): void
    {
        Config::set('database.connections.mysql.host', $tenant->db_host ?: config('database.connections.landlord.host'));
        Config::set('database.connections.mysql.database', $tenant->db_name);
        Config::set('database.connections.mysql.username', $tenant->db_username);
        Config::set('database.connections.mysql.password', $tenant->db_password);

        DB::purge('mysql');
    }

    protected function markStep(Tenant $tenant, string $step): void
    {
        $tenant->update(['provisioning_step' => $step]);
    }

    protected function handleFailure(TenantProvisionerInterface $provisioner, Tenant $tenant, Throwable $e): void
    {
        report($e);

        $tenant->update([
            'status' => Tenant::STATUS_FAILED,
            'provisioning_error' => $e->getMessage(),
        ]);

        if ($tenant->db_name && $tenant->db_username) {
            try {
                $provisioner->destroyDatabase($tenant->db_name, $tenant->db_username);
            } catch (Throwable) {
                // Best-effort cleanup only -- the failure is already recorded above.
            }
        }
    }
}
