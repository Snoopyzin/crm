<?php

namespace Webkul\Fiscal\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Webkul\Admin\Models\ApiKey;
use Webkul\Fiscal\Models\FiscalDocument;

/**
 * Thin wrapper over the Focus NFe REST API.
 *
 * IMPORTANT: this client cannot be exercised end-to-end without a real
 * Focus NFe account -- each tenant registers their own token under "Chaves
 * de API" (tipo = focusnfe). For NF-e/NFC-e specifically a digital
 * certificate uploaded through Focus NFe's own dashboard would also be
 * required (this codebase never touches the certificate directly), which is
 * why only NFS-e (service invoice, no certificate needed) is wired up for
 * real use right now -- emitNfe()/emitNfce() are left in place but unused.
 * Until a tenant configures real credentials, every method here will fail
 * against the live API; only the request-shape can be verified locally via
 * Http::fake().
 */
class FocusNfeClient
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('focusnfe.base_url'), '/');
    }

    /**
     * The current tenant's Focus NFe token, resolved lazily (not in the
     * constructor) since it depends on ResolveTenant having already run.
     */
    protected function token(): ?string
    {
        return ApiKey::focusNfeToken();
    }

    /**
     * Emit a NFS-e (service invoice) for a service order. This is the
     * document type an auto-repair shop actually needs -- issued through
     * the municipality's own webservice via Focus NFe, no digital
     * certificate required on our side.
     */
    public function emitNfse(array $payload, ?string $ref = null): FiscalDocument
    {
        return $this->emit('nfse', 'v2/nfse', $payload, $ref);
    }

    /**
     * Emit a NF-e (product/goods invoice). Not wired into any UI flow yet --
     * requires a digital certificate on the tenant's Focus NFe account.
     */
    public function emitNfe(array $payload, ?string $ref = null): FiscalDocument
    {
        return $this->emit('nfe', 'v2/nfe', $payload, $ref);
    }

    /**
     * Emit a NFC-e (consumer invoice). Not wired into any UI flow yet --
     * requires a digital certificate on the tenant's Focus NFe account.
     */
    public function emitNfce(array $payload, ?string $ref = null): FiscalDocument
    {
        return $this->emit('nfce', 'v2/nfce', $payload, $ref);
    }

    /**
     * Query the current status of a previously emitted document.
     */
    public function consultStatus(FiscalDocument $document): array
    {
        $response = $this->request()->get("{$this->baseUrl}/{$this->endpointFor($document->type)}/{$document->focus_ref}");

        $body = $response->json() ?? [];

        $document->update($this->mapResponseToColumns($document->type, $body));

        $this->log($document, 'status_consulted', $body);

        return $body;
    }

    /**
     * Cancel a previously authorized document.
     */
    public function cancel(FiscalDocument $document, string $justificativa): array
    {
        $response = $this->request()->delete("{$this->baseUrl}/{$this->endpointFor($document->type)}/{$document->focus_ref}", [
            'justificativa' => $justificativa,
        ]);

        $body = $response->json() ?? [];

        $document->update($this->mapResponseToColumns($document->type, $body));

        $this->log($document, 'cancel_requested', $body);

        return $body;
    }

    /**
     * Build the authenticated HTTP request (Focus NFe uses HTTP Basic Auth
     * with the token as username and an empty password).
     */
    protected function request()
    {
        return Http::withBasicAuth($this->token() ?? '', '')
            ->acceptJson()
            ->asJson();
    }

    protected function endpointFor(string $type): string
    {
        return match ($type) {
            'nfse' => 'v2/nfse',
            'nfce' => 'v2/nfce',
            default => 'v2/nfe',
        };
    }

    protected function emit(string $type, string $endpoint, array $payload, ?string $ref = null): FiscalDocument
    {
        $ref = $ref ?? (string) Str::uuid();

        $document = FiscalDocument::create([
            'quote_id' => $payload['quote_id'] ?? null,
            'type' => $type,
            'focus_ref' => $ref,
            'status' => FiscalDocument::STATUS_PROCESSANDO,
        ]);

        $this->log($document, 'request_sent', $payload);

        $response = $this->request()->post("{$this->baseUrl}/{$endpoint}?ref={$ref}", $payload);

        $body = $response->json() ?? [];

        $document->update($this->mapResponseToColumns($type, $body));

        $this->log($document, 'response_received', $body);

        return $document->refresh();
    }

    /**
     * Focus NFe's response shape differs slightly by document type: NFS-e
     * identifies the document with "codigo_verificacao" and returns the PDF
     * at "url_danfse", while NF-e/NFC-e use "chave_nfe" and "caminho_danfe".
     */
    protected function mapResponseToColumns(string $type, array $body): array
    {
        $columns = [
            'status' => $body['status'] ?? FiscalDocument::STATUS_PROCESSANDO,
            'numero' => $body['numero'] ?? null,
            'xml_url' => $body['caminho_xml_nota_fiscal'] ?? null,
            'last_error' => $body['mensagem_sefaz'] ?? ($body['erros'][0]['mensagem'] ?? null),
        ];

        if ($type === 'nfse') {
            $columns['codigo_verificacao'] = $body['codigo_verificacao'] ?? null;
            $columns['pdf_url'] = $body['url_danfse'] ?? null;
        } else {
            $columns['chave_nfe'] = $body['chave_nfe'] ?? null;
            $columns['pdf_url'] = $body['caminho_danfe'] ?? null;
        }

        return $columns;
    }

    protected function log(FiscalDocument $document, string $event, array $payload): void
    {
        $document->logs()->create([
            'event' => $event,
            'payload' => $payload,
        ]);
    }
}
