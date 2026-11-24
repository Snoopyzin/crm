<?php

namespace Webkul\Fiscal\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Fiscal\Models\FiscalDocument;

class FocusNfeWebhookController extends Controller
{
    /**
     * Receive an async status update from Focus NFe and update the matching
     * fiscal_documents row.
     *
     * NOTE: payload shape is based on Focus NFe's public docs and has not
     * been validated against a real callback -- confirm once the account is
     * live. The shared secret is checked as a "secret" query string param on
     * the registered webhook URL, the most common pattern for this kind of
     * integration -- adjust if Focus NFe's actual mechanism differs.
     */
    public function handle(Request $request): JsonResponse
    {
        $configuredSecret = config('focusnfe.webhook_secret');

        if ($configuredSecret && $request->query('secret') !== $configuredSecret) {
            return response()->json(['message' => 'Invalid secret'], 401);
        }

        $ref = $request->input('ref');

        $document = FiscalDocument::where('focus_ref', $ref)->first();

        if (! $document) {
            return response()->json(['message' => 'Unknown ref'], 404);
        }

        $data = [
            'status' => $request->input('status', $document->status),
            'numero' => $request->input('numero', $document->numero),
            'xml_url' => $request->input('caminho_xml_nota_fiscal', $document->xml_url),
            'last_error' => $request->input('mensagem_sefaz', $document->last_error),
        ];

        if ($document->type === 'nfse') {
            $data['codigo_verificacao'] = $request->input('codigo_verificacao', $document->codigo_verificacao);
            $data['pdf_url'] = $request->input('url_danfse', $document->pdf_url);
        } else {
            $data['chave_nfe'] = $request->input('chave_nfe', $document->chave_nfe);
            $data['pdf_url'] = $request->input('caminho_danfe', $document->pdf_url);
        }

        $document->update($data);

        $document->logs()->create([
            'event' => 'webhook_received',
            'payload' => $request->all(),
        ]);

        return response()->json(['message' => 'ok']);
    }
}
