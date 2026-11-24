<?php

namespace Webkul\Portal\Http\Controllers\Auth;

use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Webkul\Portal\Http\Controllers\Controller;
use Webkul\Portal\Models\ClientUser;

/**
 * Serves both the admin-invite "set your first password" link and the
 * regular "forgot password" reset link -- both mint a token through the
 * same `clients` PasswordBroker, so both land here.
 */
class PortalResetPasswordController extends Controller
{
    public function create(?string $token = null)
    {
        return view('portal::sessions.reset-password')->with([
            'token' => $token,
            'email' => request('email'),
        ]);
    }

    public function store()
    {
        try {
            $this->validate(request(), [
                'token' => 'required',
                'email' => 'required|email',
                'password' => 'required|confirmed|min:6',
            ]);

            $response = $this->broker()->reset(
                request(['email', 'password', 'password_confirmation', 'token']),
                function ($clientUser, $password) {
                    $this->resetPassword($clientUser, $password);
                }
            );

            if ($response == Password::PASSWORD_RESET) {
                return redirect()->route('portal.dashboard.index');
            }

            return back()
                ->withInput(request(['email']))
                ->withErrors(['email' => trans($response)]);
        } catch (\Exception $exception) {
            session()->flash('error', trans($exception->getMessage()));

            return redirect()->back();
        }
    }

    protected function resetPassword(ClientUser $clientUser, string $password): void
    {
        $clientUser->password = Hash::make($password);

        $clientUser->setRememberToken(Str::random(60));

        $clientUser->status = ClientUser::STATUS_ATIVO;

        $clientUser->activated_at = $clientUser->activated_at ?? now();

        $clientUser->save();

        auth()->guard('client')->login($clientUser);
    }

    public function broker(): PasswordBroker
    {
        return Password::broker('clients');
    }
}
