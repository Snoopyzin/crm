<?php

return [
    /*
     * Sandbox by default. Switch to https://api.focusnfe.com.br only once
     * tenants are confirmed live in production.
     */
    'base_url' => env('FOCUSNFE_BASE_URL', 'https://homologacao.focusnfe.com.br'),

    /*
     * The Focus NFe token used to EMIT is NOT read from here -- each tenant
     * has their own under Chaves de API (tipo = focusnfe), since every
     * tenant has their own CNPJ. See ApiKey::focusNfeToken().
     */

    /*
     * Volmaster's own "token principal" -- only used on the root domain to
     * create a client shop's empresa on Volmaster's Focus account when
     * Volmaster approves its fiscal onboarding request. Never sent to or
     * shown in any tenant. The Empresas API only exists in production, so
     * its URL is separate from base_url above.
     */
    'token_principal' => env('FOCUSNFE_TOKEN_PRINCIPAL'),

    'empresas_url' => env('FOCUSNFE_EMPRESAS_URL', 'https://api.focusnfe.com.br'),

    /*
     * When true, approving a request only simulates the empresa creation on
     * Focus (dry_run=1) -- nothing is created nor billed. Use for a first
     * test of the flow.
     */
    'empresas_dry_run' => (bool) env('FOCUSNFE_EMPRESAS_DRY_RUN', false),

    /*
     * Used to validate inbound webhook authenticity as a "secret" query
     * string param on the registered webhook URL. Confirm the exact scheme
     * against Focus NFe's docs once a real account exists -- this is a
     * placeholder shared secret.
     */
    'webhook_secret' => env('FOCUSNFE_WEBHOOK_SECRET'),
];
