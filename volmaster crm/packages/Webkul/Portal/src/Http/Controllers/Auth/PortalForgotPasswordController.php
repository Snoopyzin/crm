<?php

namespace Webkul\Portal\Http\Controllers\Auth;

use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\Password;
use Webkul\Portal\Http\Controllers\Controller;
use Webkul\Portal\Notifications\ClientResetPassword;

class PortalForgotPasswordController extends Controller
{
    public function create()
    {
        if (auth()->guard('client')->check()) {
            return redirect()->route('portal.dashboard.index');
        }

        return view('portal::sessions.forgot-password');
    }

    public function store()
    {
        try {
            $this->validate(request(), [
                'email' => 'required|email',
            ]);

            $response = $this->broker()->sendResetLink(request(['email']), function ($clientUser, $token) {
                $clientUser->notify(new ClientResetPassword($token));
            });

            if ($response == Password::RESET_LINK_SENT) {
                session()->flash('success', trans('portal::app.sessions.forgot-password.reset-link-sent'));

                return back();
            }

            return back()
                ->withInput(request(['email']))
                ->withErrors(['email' => trans('portal::app.sessions.forgot-password.email-not-exist')]);
        } catch (\Exception $exception) {
            session()->flash('error', trans($exception->getMessage()));

            return redirect()->back();
        }
    }

    public function broker(): PasswordBroker
    {
        return Password::broker('clients');
    }
}
