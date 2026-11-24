<?php

namespace Webkul\Portal\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Portal\Mail\PortalInviteMail;
use Webkul\Portal\Models\ClientUser;

class PortalInviteController extends Controller
{
    public function __construct(protected PersonRepository $personRepository) {}

    /**
     * Create (or reuse) the client's portal credentials and email them a
     * "set your password" link, minted through the same PasswordBroker
     * already trusted for the admin's own forgot-password flow.
     */
    public function store(int $personId): RedirectResponse
    {
        $person = $this->personRepository->findOrFail($personId);

        $email = collect($person->emails)->first()['value'] ?? null;

        if (! $email) {
            session()->flash('error', trans('portal::app.admin.invite.no-email'));

            return redirect()->back();
        }

        $clientUser = ClientUser::firstOrNew(['person_id' => $person->id]);

        if (! $clientUser->exists) {
            $clientUser->fill([
                'person_id' => $person->id,
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'status' => ClientUser::STATUS_CONVIDADO,
            ]);
        }

        $clientUser->email = $email;
        $clientUser->invited_at = now();
        $clientUser->save();

        $token = Password::broker('clients')->createToken($clientUser);

        Mail::queue(new PortalInviteMail($clientUser, $token));

        session()->flash('success', trans('portal::app.admin.invite.success'));

        return redirect()->back();
    }
}
