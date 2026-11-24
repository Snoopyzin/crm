<?php

namespace Webkul\Tenant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Webkul\Tenant\Models\Tenant;

class ResolveTenant
{
    /**
     * Subdomains that always mean "no tenant, this is the landlord/root site".
     */
    protected array $reservedSubdomains = ['www'];

    /**
     * Handle an incoming request.
     *
     * Runs before everything else (see bootstrap/app.php) so every other
     * middleware, including the installer's CanInstall check and the normal
     * 'user'/'client' auth guards, already sees the correct tenant database.
     */
    public function handle(Request $request, Closure $next)
    {
        $subdomain = $this->resolveSubdomain($request);

        if (! $subdomain) {
            // Root domain (e.g. volmastercrm.com): serves the landlord's own
            // CRM data untouched, plus the public /signup page. No database
            // switch happens here.
            $this->configurePersistentStorage('landlord');

            $this->addSettingsMenuItem('clientes', 'Clientes', 'Oficinas cadastradas e pedidos de nota fiscal', 'Oficinas', 'admin.tenants.index');

            return $next($request);
        }

        $tenant = Tenant::where('subdomain', $subdomain)->first();

        if (! $tenant) {
            return response()->view('tenant::errors.not-found', [], 404);
        }

        if (in_array($tenant->status, [Tenant::STATUS_PENDING, Tenant::STATUS_PROVISIONING])) {
            return response()->view('tenant::errors.provisioning', ['tenant' => $tenant], 200);
        }

        if ($tenant->status === Tenant::STATUS_FAILED) {
            return response()->view('tenant::errors.failed', ['tenant' => $tenant], 200);
        }

        if ($tenant->status === Tenant::STATUS_SUSPENDED) {
            return response()->view('tenant::errors.suspended', ['tenant' => $tenant], 200);
        }

        $this->switchConnectionToTenant($tenant);

        $this->configurePersistentStorage($tenant->subdomain);

        app()->instance('currentTenant', $tenant);

        $this->addSettingsMenuItem('fiscal', 'Nota Fiscal', 'Dados da empresa e certificado pra emitir notas fiscais', 'Nota Fiscal', 'admin.settings.fiscal_settings.edit');

        return $next($request);
    }

    /**
     * Configurações entries that only make sense on one side: "Nota Fiscal"
     * (self-service Focus NFe setup) for client shops, "Clientes" (tenant
     * list + Nota Fiscal approvals) for Volmaster's root domain. Added per
     * request since the menu config can't know which side it's on.
     */
    protected function addSettingsMenuItem(string $key, string $name, string $info, string $childName, string $route): void
    {
        Config::push('menu.admin', [
            'key' => "settings.$key",
            'name' => $name,
            'info' => $info,
            'route' => $route,
            'sort' => 4,
            'icon-class' => '',
        ]);

        Config::push('menu.admin', [
            'key' => "settings.$key.index",
            'name' => $childName,
            'info' => $info,
            'route' => $route,
            'sort' => 1,
            'icon-class' => 'icon-setting',
        ]);
    }

    /**
     * Point uploaded-file storage (avatars, etc.) at a directory OUTSIDE the
     * app root, one level up from base_path() and scoped per tenant.
     *
     * This deliberately does not live inside the deployed project tree: the
     * shop's redeploy workflow is "delete the whole project folder, extract
     * a fresh zip" -- anything stored under storage/app/public would be
     * wiped on every single redeploy. A sibling directory survives that.
     */
    protected function configurePersistentStorage(string $tenantSlug): void
    {
        $root = dirname(base_path()).'/volmastercrm-uploads/'.$tenantSlug.'/app/public';

        Config::set('filesystems.disks.public.root', $root);

        Config::set('filesystems.links', [
            public_path('storage') => $root,
        ]);

        // The 'public' disk instance is cached once resolved -- purge it so
        // the next Storage::disk('public') call picks up the new root
        // instead of reusing one built from the previous request/tenant.
        Storage::purge('public');

        $this->ensureStorageLinkExists($root);
    }

    /**
     * The public/storage symlink is a real filesystem symlink, so it doesn't
     * survive a zip-based redeploy (delete everything + extract a fresh
     * zip). Recreate it automatically instead of relying on a manual
     * "storage:link" step after every deploy.
     */
    protected function ensureStorageLinkExists(string $target): void
    {
        $link = public_path('storage');

        if (File::exists($link)) {
            return;
        }

        try {
            File::ensureDirectoryExists($target);

            File::link($target, $link);
        } catch (\Throwable $e) {
            // Silently ignore -- avoid breaking every request if permissions
            // don't allow it; upload features will simply keep failing to
            // display until permissions are fixed.
        }
    }

    /**
     * Extract the tenant subdomain from the request host, based on APP_URL's
     * own host as the central domain. Returns null for the root domain,
     * "www", or any host that isn't a subdomain of the central domain (e.g.
     * localhost during early local testing).
     */
    protected function resolveSubdomain(Request $request): ?string
    {
        $centralDomain = parse_url((string) config('app.url'), PHP_URL_HOST);

        $host = $request->getHost();

        if (! $centralDomain || $host === $centralDomain) {
            return null;
        }

        $suffix = '.'.$centralDomain;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen($suffix));

        if ($subdomain === '' || in_array($subdomain, $this->reservedSubdomains, true)) {
            return null;
        }

        return $subdomain;
    }

    /**
     * Repoint the shared 'mysql' connection at this tenant's own database for
     * the remainder of the request. The 'landlord' connection (see
     * config/database.php) is untouched, so the tenants directory stays
     * reachable no matter which tenant is currently active.
     */
    protected function switchConnectionToTenant(Tenant $tenant): void
    {
        Config::set('database.connections.mysql.host', $tenant->db_host ?: config('database.connections.landlord.host'));
        Config::set('database.connections.mysql.database', $tenant->db_name);
        Config::set('database.connections.mysql.username', $tenant->db_username);
        Config::set('database.connections.mysql.password', $tenant->db_password);

        DB::purge('mysql');

        // Scope the session cookie per tenant so a browser session on one
        // subdomain is never valid on another.
        Config::set('session.cookie', 'volmaster_'.$tenant->subdomain.'_session');
    }
}
