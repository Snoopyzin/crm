<?php

return [
    /*
     * Which TenantProvisionerInterface implementation to use when creating a
     * new tenant's database: 'local' (raw SQL, for development) or 'cpanel'
     * (cPanel UAPI, for the real HostGator account).
     */
    'provisioner' => env('TENANT_PROVISIONER', 'local'),

    /*
     * cPanel forces a prefix (e.g. "USUARIO_CPANEL_") on every database/user name
     * created under the account -- it's always the cPanel account username
     * followed by an underscore, so it's derived from CPANEL_USERNAME
     * automatically instead of needing its own separate .env value (one less
     * thing to configure, and one less way to get it wrong). Set
     * TENANT_DB_PREFIX explicitly only if that default is ever wrong.
     */
    'db_prefix' => env('TENANT_DB_PREFIX') ?: (env('CPANEL_USERNAME') ? env('CPANEL_USERNAME').'_' : ''),

    'cpanel' => [
        'host' => env('CPANEL_HOST'),
        'username' => env('CPANEL_USERNAME'),
        'api_token' => env('CPANEL_API_TOKEN'),
    ],
];
