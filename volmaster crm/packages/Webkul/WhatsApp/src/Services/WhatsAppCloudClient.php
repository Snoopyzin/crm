<?php

namespace Webkul\WhatsApp\Services;

use Illuminate\Support\Facades\Http;
use Webkul\WhatsApp\Models\WhatsAppMessageLog;
use Webkul\WhatsApp\Models\WhatsAppSettings;
use Webkul\WhatsApp\Support\BrazilianPhone;

/**
 * Thin wrapper over the WhatsApp Business Cloud API (Meta Graph API).
 *
 * IMPORTANT: mirrors Webkul\Fiscal\Services\FocusNfeClient on purpose --
 * same "can't be exercised end-to-end without real tenant credentials"
 * situation. Until a tenant fills in Configurações > WhatsApp with a real
 * access token + phone_number_id (from a verified Meta Business account)
 * and gets their message templates approved by Meta, every call here
 * returns a failed result instead of hitting the live API.
 */
class WhatsAppCloudClient
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('whatsapp.base_url'), '/');
    }

    /**
     * Send an approved message template (the only kind Meta allows for a
     * business-initiated message, e.g. the Orçamento ready notice or the
     * Pós-Venda satisfaction survey).
     *
     * @param  string  $to  Destination phone number, any common Brazilian format.
     * @param  string  $template  The approved template name (see WhatsAppSettings).
     * @param  array<int, string>  $bodyParams  Positional {{1}}, {{2}}... values for the template body.
     */
    public function sendTemplate(string $to, string $template, array $bodyParams = [], ?int $quoteId = null): array
    {
        $settings = WhatsAppSettings::current();

        if (! $settings->isConfigured()) {
            return $this->logAndReturn($quoteId, $to, $template, false, 'WhatsApp não configurado (faltam credenciais em Configurações > WhatsApp).',
                trans('whatsapp::app.settings.not-configured'));
        }

        $number = BrazilianPhone::e164($to);

        if (! $number) {
            return $this->logAndReturn($quoteId, $to, $template, false, 'Número inválido: '.$to,
                'O número do cliente ('.$to.') não é um celular válido. Corrija no cadastro do cliente no formato +55 (DDD) NÚMERO.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $number,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => 'pt_BR'],
                'components' => $bodyParams === [] ? [] : [[
                    'type' => 'body',
                    'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => (string) $value], $bodyParams),
                ]],
            ],
        ];

        $response = Http::withToken($settings->access_token)
            ->acceptJson()
            ->asJson()
            ->post("{$this->baseUrl}/{$settings->phone_number_id}/messages", $payload);

        return $this->logAndReturn($quoteId, $to, $template, $response->successful(), $response->body(),
            $response->successful() ? null : $this->metaErrorMessage($response->json() ?? [], $to));
    }

    /**
     * A message the shop can act on, from Meta's error payload.
     */
    protected function metaErrorMessage(array $body, string $to): string
    {
        $error = $body['error'] ?? [];
        $code = (int) ($error['code'] ?? 0);
        $phone = BrazilianPhone::format($to);

        $reason = match (true) {
            in_array($code, [131026, 131030]) => "o número {$phone} não tem WhatsApp ou não pode receber mensagens",
            $code === 132001 => 'o modelo de mensagem não existe ou ainda não foi aprovado pela Meta (confira o nome em Configurações > WhatsApp)',
            in_array($code, [132000, 132012]) => 'os dados enviados não batem com o modelo de mensagem aprovado',
            in_array($code, [190, 10, 200]) => 'o token de acesso do WhatsApp expirou ou não tem permissão (atualize em Configurações > WhatsApp)',
            $code === 131047 => 'já passou 24h da última conversa com esse cliente -- só é possível enviar modelo aprovado',
            $code === 133010 => 'o número da empresa ainda não está registrado no WhatsApp Business',
            default => ($error['error_data']['details'] ?? $error['message'] ?? 'erro desconhecido').($code ? " (código {$code})" : ''),
        };

        return 'O WhatsApp recusou o envio: '.$reason.'.';
    }

    protected function logAndReturn(?int $quoteId, string $to, string $template, bool $success, ?string $response, ?string $message = null): array
    {
        WhatsAppMessageLog::create([
            'quote_id' => $quoteId,
            'to' => $to,
            'template' => $template,
            'status' => $success ? WhatsAppMessageLog::STATUS_ENVIADO : WhatsAppMessageLog::STATUS_FALHOU,
            'response' => $response,
        ]);

        return [
            'success' => $success,
            'response' => $response,
            'message' => $message,
        ];
    }
}
