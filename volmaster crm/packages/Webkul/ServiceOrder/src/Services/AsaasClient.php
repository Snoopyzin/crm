<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\Http;
use Webkul\Admin\Models\ApiKey;
use Webkul\Contact\Contracts\Person;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderBoleto;

/**
 * Thin wrapper over the Asaas REST API, used to collect payment for a
 * service order's Faturamento stage (boleto, Pix, or card -- Asaas's own
 * hosted invoice page lets the customer pick, so this codebase never
 * touches a card number directly).
 *
 * IMPORTANT: this client cannot be exercised end-to-end without a real
 * Asaas account -- each tenant registers their own API key under "Chaves de
 * API" (tipo = asaas). Until then every method here will fail against the
 * live API; only the request/response shape can be verified against Asaas's
 * public docs and via Http::fake().
 */
class AsaasClient
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('asaas.base_url'), '/');
    }

    /**
     * The current tenant's Asaas API key, resolved lazily (not in the
     * constructor) since it depends on ResolveTenant having already run.
     */
    protected function token(): ?string
    {
        return ApiKey::asaasToken();
    }

    /**
     * Create a charge (billingType UNDEFINED, so the customer picks boleto,
     * Pix, or card on Asaas's own hosted invoice page) and store the result
     * on a new service_order_boletos row.
     */
    public function createCharge(ServiceOrder $serviceOrder, float $valor, ?string $vencimento = null, ?string $descricao = null): ServiceOrderBoleto
    {
        $customerId = $this->findOrCreateCustomer($serviceOrder->person);

        $response = $this->request()->post("{$this->baseUrl}/payments", [
            'customer' => $customerId,
            'billingType' => 'UNDEFINED',
            'value' => $valor,
            'dueDate' => $vencimento ?? now()->addDays(7)->toDateString(),
            'description' => $descricao ?? 'OS #'.$serviceOrder->id,
            'externalReference' => (string) $serviceOrder->id,
        ])->throw();

        $body = $response->json() ?? [];

        return ServiceOrderBoleto::create([
            'quote_id' => $serviceOrder->id,
            'valor' => $valor,
            'vencimento' => $body['dueDate'] ?? $vencimento,
            'status' => $this->mapStatus($body['status'] ?? 'PENDING'),
            'link_pagamento' => $body['invoiceUrl'] ?? null,
            'gateway' => 'asaas',
            'asaas_payment_id' => $body['id'] ?? null,
            'billing_type' => $body['billingType'] ?? 'UNDEFINED',
        ]);
    }

    /**
     * Find the person's existing Asaas customer, or create one and persist
     * its id on the person so subsequent charges reuse it.
     */
    protected function findOrCreateCustomer(Person $person): string
    {
        if ($person->asaas_customer_id) {
            return $person->asaas_customer_id;
        }

        $response = $this->request()->post("{$this->baseUrl}/customers", [
            'name' => $person->name,
            'cpfCnpj' => preg_replace('/\D/', '', $person->cpf_cnpj ?? ''),
            'email' => $person->emails[0]['value'] ?? null,
            'mobilePhone' => preg_replace('/\D/', '', $person->contact_numbers[0]['value'] ?? ''),
            'externalReference' => (string) $person->id,
        ])->throw();

        $customerId = $response->json('id');

        // A plain Eloquent update() would also try to persist any custom
        // (EAV) attribute loaded onto $person, like cpf_cnpj, as if it were
        // a real column -- go through the query builder instead, which only
        // ever touches the one column named here.
        $person::query()->whereKey($person->getKey())->update(['asaas_customer_id' => $customerId]);

        $person->asaas_customer_id = $customerId;

        return $customerId;
    }

    /**
     * Asaas's payment status vocabulary, collapsed down to the shop's own
     * three-state Pendente/Pago/Cancelado.
     */
    public function mapStatus(string $asaasStatus): string
    {
        return match ($asaasStatus) {
            'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH' => ServiceOrderBoleto::STATUS_PAGO,
            'REFUNDED', 'REFUND_REQUESTED', 'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'AWAITING_CHARGEBACK_REVERSAL', 'DELETED' => ServiceOrderBoleto::STATUS_CANCELADO,
            default => ServiceOrderBoleto::STATUS_PENDENTE,
        };
    }

    /**
     * Build the authenticated HTTP request (Asaas uses a plain API key in
     * the "access_token" header, not Bearer/Basic auth).
     */
    protected function request()
    {
        return Http::withHeaders(['access_token' => $this->token() ?? ''])
            ->acceptJson()
            ->asJson();
    }
}
