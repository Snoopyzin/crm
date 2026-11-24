<?php

namespace Webkul\ServiceOrder\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Provedor padrão enquanto nenhum provedor de SMS foi contratado: não envia
 * nada, só registra no log (e na tabela sms_logs, como "simulado").
 */
class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $text): array
    {
        Log::info("SMS simulado para {$to}: {$text}");

        return ['ok' => true, 'simulado' => true];
    }

    public function name(): string
    {
        return 'log';
    }
}
