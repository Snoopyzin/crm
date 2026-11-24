<?php

namespace Webkul\ServiceOrder\Services\Sms;

use Illuminate\Support\Facades\Http;
use Webkul\ServiceOrder\Services\WorkshopSettings;

/**
 * SMS via API REST da Twilio. Ainda não foi validado contra uma conta real:
 * o provedor definitivo depende de decisão do mentor.
 */
class TwilioSmsGateway implements SmsGateway
{
    public function send(string $to, string $text): array
    {
        $sid = (string) WorkshopSettings::get('sms_twilio_sid');
        $token = (string) WorkshopSettings::get('sms_twilio_token');
        $from = (string) WorkshopSettings::get('sms_twilio_from');

        if (! $sid || ! $token || ! $from) {
            return ['ok' => false, 'error' => 'Twilio não configurado (SID, token e número de origem).'];
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->timeout(15)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'To' => $to,
                    'From' => $from,
                    'Body' => $text,
                ]);
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'id' => (string) $response->json('sid')];
        }

        return ['ok' => false, 'error' => 'HTTP '.$response->status().': '.($response->json('message') ?? $response->body())];
    }

    public function name(): string
    {
        return 'twilio';
    }
}
