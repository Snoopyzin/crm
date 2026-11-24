<?php

namespace Webkul\Portal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\Portal\Models\ClientUser;

class PortalAuth
{
    /**
     * Mirrors Webkul\Admin\Http\Middleware\Bouncer's auth-check/redirect
     * shape for the 'client' guard -- the portal has no roles/permissions
     * system, so this only needs the auth-check and the deactivated-account
     * check, not Bouncer's permission logic.
     */
    public function handle(Request $request, Closure $next)
    {
        if (! auth()->guard('client')->check()) {
            return redirect()->route('portal.session.create');
        }

        if (auth()->guard('client')->user()->status === ClientUser::STATUS_DESATIVADO) {
            auth()->guard('client')->logout();

            session()->flash('error', trans('portal::app.sessions.deactivated-error'));

            return redirect()->route('portal.session.create');
        }

        return $next($request);
    }
}
