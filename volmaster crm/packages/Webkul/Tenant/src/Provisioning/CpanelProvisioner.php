<?php

namespace Webkul\Tenant\Provisioning;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Provisions tenant databases through cPanel's UAPI, authenticated with a
 * cPanel API Token (cPanel -> Security -> API Tokens). This is the standard
 * way to create MySQL databases/users programmatically on shared cPanel
 * hosting, since the app's own restricted DB user cannot run CREATE
 * DATABASE/CREATE USER itself.
 *
 * NOT YET VERIFIED against the live HostGator account -- confirm the API
 * Token feature and the account's MySQL database limit before relying on
 * this in production (see Fase 0 in the implementation plan).
 */
class CpanelProvisioner implements TenantProvisionerInterface
{
    public function __construct(
        protected string $host,
        protected string $cpanelUsername,
        protected string $apiToken,
    ) {}

    public function createDatabase(string $dbName, string $dbUsername, string $dbPassword): void
    {
        $this->call('Mysql', 'create_database', ['name' => $dbName]);

        $this->call('Mysql', 'create_user', [
            'name' => $dbUsername,
            'password' => $dbPassword,
        ]);

        $this->call('Mysql', 'set_privileges_on_database', [
            'user' => $dbUsername,
            'database' => $dbName,
            'privileges' => 'ALL PRIVILEGES',
        ]);
    }

    public function destroyDatabase(string $dbName, string $dbUsername): void
    {
        $this->call('Mysql', 'destroy_database', ['name' => $dbName]);

        $this->call('Mysql', 'delete_user', ['name' => $dbUsername]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function call(string $module, string $function, array $params): array
    {
        $response = Http::withHeaders([
            'Authorization' => "cpanel {$this->cpanelUsername}:{$this->apiToken}",
        ])
            ->timeout(30)
            ->get("https://{$this->host}:2083/execute/{$module}/{$function}", $params);

        $data = $response->json() ?? [];

        if (! $response->successful() || (int) ($data['status'] ?? 1) === 0) {
            $error = $data['errors'][0] ?? $response->body();

            throw new RuntimeException("cPanel UAPI {$module}::{$function} falhou: {$error}");
        }

        return $data;
    }
}
