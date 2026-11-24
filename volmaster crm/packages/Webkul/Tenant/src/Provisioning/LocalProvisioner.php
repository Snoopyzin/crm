<?php

namespace Webkul\Tenant\Provisioning;

use Illuminate\Support\Facades\DB;

/**
 * Provisions tenant databases with raw SQL against a privileged local MySQL
 * user (XAMPP/Laragon root, etc). Only meant for local development -- a
 * shared-hosting cPanel account's own app database user normally cannot run
 * CREATE DATABASE/CREATE USER, which is why CpanelProvisioner exists for
 * production.
 */
class LocalProvisioner implements TenantProvisionerInterface
{
    public function createDatabase(string $dbName, string $dbUsername, string $dbPassword): void
    {
        $dbName = $this->assertSafeIdentifier($dbName);
        $dbUsername = $this->assertSafeIdentifier($dbUsername);

        $connection = DB::connection('landlord');

        // CREATE USER ... IDENTIFIED BY doesn't play well with PDO parameter
        // binding on some MySQL/MariaDB versions, so the password is quoted
        // manually via PDO::quote() and embedded directly instead.
        $quotedPassword = $connection->getPdo()->quote($dbPassword);

        $connection->statement("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $connection->statement("CREATE USER IF NOT EXISTS '{$dbUsername}'@'%' IDENTIFIED BY {$quotedPassword}");
        $connection->statement("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUsername}'@'%'");
        $connection->statement('FLUSH PRIVILEGES');
    }

    public function destroyDatabase(string $dbName, string $dbUsername): void
    {
        $dbName = $this->assertSafeIdentifier($dbName);
        $dbUsername = $this->assertSafeIdentifier($dbUsername);

        $connection = DB::connection('landlord');

        $connection->statement("DROP DATABASE IF EXISTS `{$dbName}`");
        $connection->statement("DROP USER IF EXISTS '{$dbUsername}'@'%'");
    }

    /**
     * Defense in depth: db_name/db_username are generated from an
     * already-validated subdomain, but never trust that at the point of
     * building raw SQL identifiers.
     */
    protected function assertSafeIdentifier(string $value): string
    {
        if (! preg_match('/^[a-zA-Z0-9_]{1,64}$/', $value)) {
            throw new \InvalidArgumentException("Unsafe database identifier: {$value}");
        }

        return $value;
    }
}
