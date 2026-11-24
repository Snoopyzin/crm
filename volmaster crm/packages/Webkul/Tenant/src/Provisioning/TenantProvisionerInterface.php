<?php

namespace Webkul\Tenant\Provisioning;

interface TenantProvisionerInterface
{
    /**
     * Create a fresh database + user for a new tenant and grant that user
     * full privileges on that database only.
     */
    public function createDatabase(string $dbName, string $dbUsername, string $dbPassword): void;

    /**
     * Best-effort cleanup used when provisioning fails partway through.
     */
    public function destroyDatabase(string $dbName, string $dbUsername): void;
}
