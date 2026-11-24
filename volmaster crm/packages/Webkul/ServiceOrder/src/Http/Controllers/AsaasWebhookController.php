<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderBoleto;
use Webkul\ServiceOrder\Services\AsaasClient;
use Webkul\ServiceOrder\Services\WorkflowService;

class AsaasWebhookController extends Controller
{
    public function __construct(protected AsaasClient $client) {}

    /**
     * Receive an async payment status update from Asaas and update the
     * matching service_order_boletos row.
     *
     * NOTE: payload shape is based on Asaas's public docs and has not been
     * validated against a real callback -- confirm once a real webhook is
     * registered. Asaas sends back the "Authentication Token" configured in
     * their webhook settings as the asaas-access-token header -- never the
     * API key itself.
     */
    public function handle(Request $request): JsonResponse
    {
        $configuredToken = config('asaas.webhook_token');

        if ($configuredToken && $request->header('asaas-access-token') !== $configuredToken) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $paymentId = $request->input('payment.id');

        $boleto = ServiceOrderBoleto::where('asaas_payment_id', $paymentId)->first();

        if (! $boleto) {
            return response()->json(['message' => 'Unknown payment'], 404);
        }

        $boleto->update([
            'status' => $this->client->mapStatus($request->input('payment.status', '')),
        ]);

        // Cobrança paga: grava o pagamento confirmado no log da OS.
        if ($boleto->status === 'Pago' && ($serviceOrder = ServiceOrder::find($boleto->quote_id))) {
            app(WorkflowService::class)->confirmPayment($serviceOrder, 'asaas');
        }

        return response()->json(['message' => 'ok']);
    }
}
