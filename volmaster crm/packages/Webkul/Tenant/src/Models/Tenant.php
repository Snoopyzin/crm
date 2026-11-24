<?php

namespace Webkul\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Tenant\Contracts\Tenant as TenantContract;

class Tenant extends Model implements TenantContract
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUSPENDED = 'suspended';

    protected $connection = 'landlord';

    protected $table = 'tenants';

    protected $fillable = [
        'name',
        'subdomain',
        'admin_name',
        'admin_email',
        'db_host',
        'db_name',
        'db_username',
        'db_password',
        'status',
        'provisioning_step',
        'provisioning_error',
        'trial_ends_at',
        'suspended_at',
    ];

    protected $casts = [
        'db_password' => 'encrypted',
        'trial_ends_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    protected $hidden = [
        'db_password',
    ];
}
