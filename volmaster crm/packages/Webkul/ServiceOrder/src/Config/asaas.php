<?php

return [
    /*
     * Sandbox by default. Switch to https://api.asaas.com/v3 only once a
     * real (non-sandbox) API key is registered.
     */
    'base_url' => env('ASAAS_BASE_URL', 'https://api-sandbox.asaas.com/v3'),

    /*
     * The Asaas API key is NOT read from here -- registered under Chaves de
     * API (tipo = asaas). See ApiKey::asaasToken().
     */

    /*
     * The "Authentication Token" configured in the Asaas webhook settings
     * (sent back as the asaas-access-token header on every webhook call).
     * Never reuse the API key itself as this token.
     */
    'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
];
