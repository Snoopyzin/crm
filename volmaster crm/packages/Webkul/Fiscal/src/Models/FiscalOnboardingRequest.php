<?php

namespace Webkul\Fiscal\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Tenant\Models\Tenant;

/**
 * A client shop asking to be registered as an "empresa" on Volmaster's Focus
 * NFe account. The shop fills it in from its own CRM (Configurações > Nota
 * Fiscal); Volmaster approves it from the root domain, which creates the
 * empresa through the Focus API and stores the returned token in the shop's
 * own database. Lives in the landlord database, never in a tenant's.
 */
class FiscalOnboardingRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    protected $connection = 'landlord';

    protected $table = 'fiscal_onboarding_requests';

    protected $fillable = [
        'tenant_id',
        'status',
        'payload',
        'certificado',
        'senha_certificado',
        'focus_empresa_id',
        'error',
        'reviewed_at',
    ];

    protected $casts = [
        'payload' => 'encrypted:array',
        'certificado' => 'encrypted',
        'senha_certificado' => 'encrypted',
        'reviewed_at' => 'datetime',
    ];

    protected $hidden = [
        'certificado',
        'senha_certificado',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The most recent request for a tenant, or null if it never sent one.
     */
    public static function latestFor(int $tenantId): ?self
    {
        return static::where('tenant_id', $tenantId)->latest('id')->first();
    }
}
