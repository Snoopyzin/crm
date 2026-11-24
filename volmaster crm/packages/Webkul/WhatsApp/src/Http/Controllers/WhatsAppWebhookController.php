<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\ServiceOrder\Models\ServiceOrderFeedback;
use Webkul\WhatsApp\Models\WhatsAppSettings;

/**
 * Public, server-to-server endpoint Meta calls -- intentionally NOT behind
 * admin auth (mirrors Webkul\Fiscal\Http\Controllers\FocusNfeWebhookController).
 *
 * Meta requires the GET verification handshake once when the webhook URL is
 * registered, then POSTs every inbound event (delivery status, and the
 * customer's Bom/Ruim button tap on the Pós-Venda template) to the same URL.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $settings = WhatsAppSettings::current();

        if (
            $request->get('hub_mode') === 'subscribe'
            && $settings->webhook_verify_token
            && hash_equals($settings->webhook_verify_token, (string) $request->get('hub_verify_token'))
        ) {
            return response($request->get('hub_challenge'), 200);
        }

        return response('', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        $button = data_get($request->all(), 'entry.0.changes.0.value.messages.0.button.text');
        $from = data_get($request->all(), 'entry.0.changes.0.value.messages.0.from');
        $context = data_get($request->all(), 'entry.0.changes.0.value.messages.0.context.id');

        if ($button && $context) {
            $this->recordFeedbackReply($from, $button);
        }

        return response()->json(['status' => 'received']);
    }

    /**
     * Match the reply back to the survey through the customer's phone number
     * -- the most recent, still-unanswered feedback sent to that number.
     */
    protected function recordFeedbackReply(?string $from, string $buttonText): void
    {
        if (! $from) {
            return;
        }

        $avaliacao = str_contains(mb_strtolower($buttonText), 'ruim')
            ? ServiceOrderFeedback::AVALIACAO_RUIM
            : ServiceOrderFeedback::AVALIACAO_BOM;

        ServiceOrderFeedback::whereNotNull('enviado_em')
            ->whereNull('respondido_em')
            ->whereHas('serviceOrder.person', fn ($query) => $query->where('contact_numbers', 'like', '%'.substr($from, -8).'%'))
            ->latest('enviado_em')
            ->first()
            ?->update(['avaliacao' => $avaliacao, 'respondido_em' => now()]);
    }
}
