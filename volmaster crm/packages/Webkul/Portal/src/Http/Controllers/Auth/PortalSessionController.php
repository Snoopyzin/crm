<?php

namespace Webkul\Portal\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Portal\Http\Controllers\Controller;
use Webkul\Portal\Models\ClientUser;

class PortalSessionController extends Controller
{
    public function create(): RedirectResponse|View
    {
        if (auth()->guard('client')->check()) {
            return redirect()->route('portal.dashboard.index');
        }

        return view('portal::sessions.login');
    }

    public function store(): RedirectResponse
    {
        $this->validate(request(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (! auth()->guard('client')->attempt(request(['email', 'password']), request()->boolean('remember'))) {
            session()->flash('error', trans('portal::app.sessions.login-error'));

            return redirect()->back()->withInput(request()->only('email'));
        }

        if (auth()->guard('client')->user()->status === ClientUser::STATUS_DESATIVADO) {
            auth()->guard('client')->logout();

            session()->flash('error', trans('portal::app.sessions.deactivated-error'));

            return redirect()->route('portal.session.create');
        }

        return redirect()->intended(route('portal.dashboard.index'));
    }

    public function destroy(): RedirectResponse
    {
        auth()->guard('client')->logout();

        return redirect()->route('portal.session.create');
    }
}
