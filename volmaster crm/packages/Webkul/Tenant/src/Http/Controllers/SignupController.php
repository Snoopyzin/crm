<?php

namespace Webkul\Tenant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Webkul\Tenant\Jobs\ProvisionTenantJob;
use Webkul\Tenant\Models\Tenant;

class SignupController
{
    public function create(): View
    {
        return view('tenant::signup.create');
    }

    public function store(Request $request): View
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'subdomain' => [
                'required', 'string', 'min:3', 'max:30',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                'unique:landlord.tenants,subdomain',
            ],
            'admin_name' => ['required', 'string', 'max:150'],
            'admin_email' => ['required', 'email', 'max:150'],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'subdomain.regex' => 'Use só letras minúsculas, números e hífen (ex: oficina-do-joao).',
        ]);

        $tenant = Tenant::create([
            'name' => $validated['name'],
            'subdomain' => strtolower($validated['subdomain']),
            'admin_name' => $validated['admin_name'],
            'admin_email' => $validated['admin_email'],
            'db_name' => '',
            'db_username' => '',
            'db_password' => '',
            'status' => Tenant::STATUS_PENDING,
        ]);

        ProvisionTenantJob::dispatch($tenant, Hash::make($validated['admin_password']));

        $centralDomain = parse_url((string) config('app.url'), PHP_URL_HOST);

        return view('tenant::signup.pending', [
            'tenant' => $tenant,
            'tenantUrl' => 'http://'.$tenant->subdomain.'.'.$centralDomain,
        ]);
    }
}
