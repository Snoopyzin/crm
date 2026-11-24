<?php

namespace Webkul\Fiscal\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Models\ApiKey;
use Webkul\Fiscal\Models\FiscalOnboardingRequest;

/**
 * Registers a client shop as an "empresa" on Volmaster's Focus NFe account
 * (Empresas API, token principal, production only) and stores the returned
 * per-empresa token in that shop's own database. Called only from the root
 * domain, when Volmaster approves a FiscalOnboardingRequest.
 */
class FocusEmpresaService
{
    protected const TENANT_CONNECTION = 'fiscal_onboarding_tenant';

    /**
     * Approve the request. Returns a human-readable result message; throws
     * \RuntimeException with Focus' own error message on failure.
     */
    public function approve(FiscalOnboardingRequest $request): string
    {
        $tokenPrincipal = config('focusnfe.token_principal');

        if (! $tokenPrincipal) {
            throw new \RuntimeException('FOCUSNFE_TOKEN_PRINCIPAL não está configurado no .env do servidor.');
        }

        $dryRun = (bool) config('focusnfe.empresas_dry_run');

        $url = rtrim(config('focusnfe.empresas_url'), '/').'/v2/empresas'.($dryRun ? '?dry_run=1' : '');

        $response = Http::withBasicAuth($tokenPrincipal, '')
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->post($url, $this->buildPayload($request));

        if (! $response->successful()) {
            throw new \RuntimeException($this->errorMessage($response));
        }

        if ($dryRun) {
            return 'Simulação (dry run) aceita pela Focus NFe -- nada foi criado. Desligue FOCUSNFE_EMPRESAS_DRY_RUN pra aprovar de verdade.';
        }

        $empresa = $response->json() ?? [];

        // The create response may come back without tokens; the "consultar"
        // endpoint always has them.
        if (empty($empresa['token_producao']) && ! empty($empresa['id'])) {
            $empresa = Http::withBasicAuth($tokenPrincipal, '')
                ->acceptJson()
                ->timeout(60)
                ->get(rtrim(config('focusnfe.empresas_url'), '/').'/v2/empresas/'.$empresa['id'])
                ->json() ?? $empresa;
        }

        if (empty($empresa['token_producao']) && empty($empresa['token_homologacao'])) {
            throw new \RuntimeException('Empresa criada na Focus NFe (id '.($empresa['id'] ?? '?').'), mas a resposta não trouxe os tokens. Copie o token pelo painel da Focus.');
        }

        $this->storeTokens($request, $empresa);

        $request->update([
            'status' => FiscalOnboardingRequest::STATUS_APPROVED,
            'focus_empresa_id' => (string) ($empresa['id'] ?? ''),
            'error' => null,
            'reviewed_at' => now(),
            // Not needed anymore once it's on Focus -- don't keep it around.
            'certificado' => null,
            'senha_certificado' => null,
        ]);

        return 'Empresa cadastrada na Focus NFe e token salvo no CRM da oficina.';
    }

    protected function buildPayload(FiscalOnboardingRequest $request): array
    {
        $data = $request->payload;

        $digits = fn ($value) => preg_replace('/\D/', '', (string) $value);

        $inscricaoEstadual = $digits($data['inscricao_estadual'] ?? '');

        return array_filter([
            'nome' => $data['razao_social'] ?? null,
            'nome_fantasia' => $data['nome_fantasia'] ?? null,
            'cnpj' => $digits($data['cnpj'] ?? ''),
            'inscricao_estadual' => $inscricaoEstadual ?: null,
            'inscricao_municipal' => $digits($data['inscricao_municipal'] ?? '') ?: null,
            'regime_tributario' => isset($data['regime_tributario']) ? (int) $data['regime_tributario'] : null,
            'email' => $data['email'] ?? null,
            'telefone' => $data['telefone'] ?? null,
            'logradouro' => $data['logradouro'] ?? null,
            'numero' => $data['numero'] ?? null,
            'complemento' => $data['complemento'] ?? null,
            'bairro' => $data['bairro'] ?? null,
            'municipio' => $data['municipio'] ?? null,
            'uf' => isset($data['uf']) ? strtoupper($data['uf']) : null,
            'cep' => $digits($data['cep'] ?? '') ?: null,
            'arquivo_certificado_base64' => $request->certificado,
            'senha_certificado' => $request->senha_certificado,
            'habilita_nfse' => true,
            'habilita_nfe' => (bool) $inscricaoEstadual,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Save the empresa's tokens in the tenant's own database, without
     * touching the request's current (landlord) connection.
     */
    protected function storeTokens(FiscalOnboardingRequest $request, array $empresa): void
    {
        $tenant = $request->tenant;

        Config::set('database.connections.'.self::TENANT_CONNECTION, array_merge(config('database.connections.landlord'), [
            'host' => $tenant->db_host ?: config('database.connections.landlord.host'),
            'database' => $tenant->db_name,
            'username' => $tenant->db_username,
            'password' => $tenant->db_password,
        ]));

        DB::purge(self::TENANT_CONNECTION);

        // Whichever environment the CRM currently emits against is the one
        // ApiKey::focusNfeToken() must return; the other is kept untyped so
        // switching FOCUSNFE_BASE_URL later only means swapping the tipo.
        $isProduction = ! str_contains((string) config('focusnfe.base_url'), 'homologacao');

        $active = $isProduction ? 'token_producao' : 'token_homologacao';
        $other = $isProduction ? 'token_homologacao' : 'token_producao';

        ApiKey::on(self::TENANT_CONNECTION)
            ->where('tipo', ApiKey::TIPO_FOCUSNFE)
            ->orWhere('nome', 'like', 'Focus NFe -%')
            ->delete();

        ApiKey::on(self::TENANT_CONNECTION)->create([
            'nome' => 'Focus NFe - '.($isProduction ? 'produção' : 'homologação'),
            'tipo' => ApiKey::TIPO_FOCUSNFE,
            'valor' => $empresa[$active],
            'observacoes' => 'Criado automaticamente na aprovação da Volmaster (empresa Focus '.($empresa['id'] ?? '?').').',
        ]);

        if (! empty($empresa[$other])) {
            ApiKey::on(self::TENANT_CONNECTION)->create([
                'nome' => 'Focus NFe - '.($isProduction ? 'homologação' : 'produção'),
                'tipo' => null,
                'valor' => $empresa[$other],
                'observacoes' => 'Token do outro ambiente, guardado pra troca futura.',
            ]);
        }

        DB::purge(self::TENANT_CONNECTION);
    }

    protected function errorMessage($response): string
    {
        $body = $response->json();

        if (is_array($body) && ! empty($body['erros'])) {
            return collect($body['erros'])
                ->map(fn ($erro) => trim(($erro['campo'] ?? '').' '.($erro['mensagem'] ?? '')))
                ->implode('; ');
        }

        if (is_array($body) && ! empty($body['mensagem'])) {
            return $body['mensagem'];
        }

        return 'Focus NFe respondeu HTTP '.$response->status().': '.mb_substr((string) $response->body(), 0, 300);
    }
}
