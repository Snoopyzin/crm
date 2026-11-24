<?php

namespace Webkul\Tenant\Http\Controllers\Admin;

use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Fiscal\Models\FiscalOnboardingRequest;
use Webkul\Tenant\Models\Tenant;

class TenantController extends Controller
{
    /**
     * Lists every signed-up client. Only meaningful -- and only allowed --
     * on the landlord's own root domain, never from inside a tenant's own
     * subdomain, since the tenant directory itself must never be visible to
     * a client (each tenant must only ever see their own data).
     */
    public function index(): View
    {
        abort_if(app()->bound('currentTenant'), 404);

        abort_unless(auth()->guard('user')->user()?->role?->permission_type === 'all', 403);

        $tenants = Tenant::orderByDesc('id')->get();

        // Latest Nota Fiscal request per tenant, keyed by tenant_id.
        $fiscalRequests = FiscalOnboardingRequest::orderBy('id')->get()->keyBy('tenant_id');

        return view('tenant::admin.index', compact('tenants', 'fiscalRequests'));
    }
}
