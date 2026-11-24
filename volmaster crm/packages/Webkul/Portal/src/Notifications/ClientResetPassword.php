<?php

namespace Webkul\Portal\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ClientResetPassword extends ResetPassword
{
    public function toMail($notifiable)
    {
        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $this->token);
        }

        return (new MailMessage)
            ->subject(trans('portal::app.emails.reset-password.subject'))
            ->view('portal::emails.forget-password', [
                'client_name' => $notifiable->name,
                'token' => $this->token,
                'email' => $notifiable->email,
            ]);
    }
}
