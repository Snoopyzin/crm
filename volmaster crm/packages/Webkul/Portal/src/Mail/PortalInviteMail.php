<?php

namespace Webkul\Portal\Mail;

use Illuminate\Mail\Mailable;
use Webkul\Portal\Models\ClientUser;

class PortalInviteMail extends Mailable
{
    public function __construct(public ClientUser $clientUser, public string $token) {}

    public function build()
    {
        return $this
            ->to($this->clientUser->email)
            ->subject(trans('portal::app.emails.invite.subject', ['app_name' => config('app.name')]))
            ->view('portal::emails.invite', [
                'personName' => $this->clientUser->person->name,
                'acceptUrl' => route('portal.reset_password.create', [
                    'token' => $this->token,
                    'email' => $this->clientUser->email,
                ]),
            ]);
    }
}
