<?php

namespace Webkul\ServiceOrder\Services\Sms;

/**
 * Provedor de SMS. O provedor concreto é escolhido em
 * WorkshopSettings 'sms_provedor' (ver SmsManager).
 */
interface SmsGateway
{
    /**
     * @return array{ok: bool, id?: string, error?: string, simulado?: bool}
     */
    public function send(string $to, string $text): array;

    public function name(): string;
}
