<?php

namespace Webkul\Fiscal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Models\ApiKey;
use Webkul\Fiscal\Models\FiscalOnboardingRequest;
use Webkul\Fiscal\Models\FiscalSettings;

/**
 * Self-service "Nota Fiscal" setup for client shops (tenants): the shop's
 * admin fills in its fiscal data, address and A1 certificate, which becomes
 * a FiscalOnboardingRequest that Volmaster approves from the root domain
 * (see Webkul\Tenant\Http\Controllers\Admin\FiscalOnboardingController).
 *
 * Volmaster's OWN fiscal data and Focus token (root domain, no tenant) are
 * still managed exclusively through public/api-keys-manager.php, so this
 * screen stays unreachable there.
 */
class FiscalSettingsController extends Controller
{
    /**
     * Fields that identify the empresa on Focus NFe -- locked once approved,
     * since changing them would also require updating the empresa there.
     */
    protected const IDENTITY_FIELDS = ['razao_social', 'nome_fantasia', 'cnpj', 'inscricao_municipal', 'inscricao_estadual', 'uf', 'regime_tributario'];

    public function edit(): View
    {
        $tenant = $this->currentTenant();

        $fiscalSettings = FiscalSettings::current();

        $onboarding = FiscalOnboardingRequest::latestFor($tenant->id);

        $approved = filled(ApiKey::focusNfeToken());

        $address = $onboarding?->payload ?? [];

        return view('fiscal::settings.edit', compact('fiscalSettings', 'onboarding', 'approved', 'address'));
    }

    public function update(): RedirectResponse
    {
        $tenant = $this->currentTenant();

        $approved = filled(ApiKey::focusNfeToken());

        $onboarding = FiscalOnboardingRequest::latestFor($tenant->id);

        $needsCertificate = ! $approved && ! $onboarding?->certificado;

        $this->validate(request(), [
            'razao_social' => $approved ? 'nullable' : 'required|string|max:150',
            'nome_fantasia' => 'nullable|string|max:150',
            'cnpj' => $approved ? 'nullable' : 'required|string|max:18',
            'inscricao_municipal' => $approved ? 'nullable' : 'required|string|max:30',
            'inscricao_estadual' => 'nullable|string|max:30',
            'cfop_padrao' => 'nullable|string|max:4',
            'regime_tributario' => $approved ? 'nullable' : 'required|string|in:1,2,3',
            'codigo_municipio' => 'required|string|size:7',
            'uf' => $approved ? 'nullable' : 'required|string|size:2',
            'optante_simples_nacional' => 'nullable|boolean',
            'item_lista_servico' => 'required|string|max:20',
            'aliquota_iss' => 'nullable|numeric|min:0|max:100',

            'email' => $approved ? 'nullable' : 'required|email|max:150',
            'telefone' => 'nullable|string|max:20',
            'cep' => $approved ? 'nullable' : 'required|string|max:9',
            'logradouro' => $approved ? 'nullable' : 'required|string|max:150',
            'numero' => $approved ? 'nullable' : 'required|string|max:60',
            'complemento' => 'nullable|string|max:60',
            'bairro' => $approved ? 'nullable' : 'required|string|max:100',
            'municipio' => $approved ? 'nullable' : 'required|string|max:100',

            'certificado' => ($needsCertificate ? 'required' : 'nullable').'|file|max:100',
            'senha_certificado' => ($needsCertificate || request()->hasFile('certificado')) ? 'required|string|max:100' : 'nullable',
        ], [
            'certificado.required' => 'Envie o certificado digital A1 (arquivo .pfx ou .p12).',
            'senha_certificado.required' => 'Informe a senha do certificado digital.',
        ]);

        $certificado = null;

        if (request()->hasFile('certificado')) {
            $file = request()->file('certificado');

            if (! in_array(strtolower($file->getClientOriginalExtension()), ['pfx', 'p12'])) {
                return back()->withInput()->withErrors(['certificado' => 'O certificado precisa ser um arquivo .pfx ou .p12 (certificado A1).']);
            }

            $contents = file_get_contents($file->getRealPath());

            // Catch a wrong password here instead of only at approval time.
            if (function_exists('openssl_pkcs12_read') && ! openssl_pkcs12_read($contents, $parsed, (string) request('senha_certificado'))) {
                return back()->withInput()->withErrors(['senha_certificado' => 'Não foi possível abrir o certificado com essa senha. Confira a senha e o arquivo.']);
            }

            $certificado = base64_encode($contents);
        }

        $fiscalSettings = FiscalSettings::current();

        $data = [
            'codigo_municipio' => request('codigo_municipio'),
            'cfop_padrao' => request('cfop_padrao') ?: '5102',
            'optante_simples_nacional' => request()->boolean('optante_simples_nacional'),
            'item_lista_servico' => request('item_lista_servico'),
            'aliquota_iss' => request('aliquota_iss'),
        ];

        if (! $approved) {
            foreach (self::IDENTITY_FIELDS as $field) {
                $data[$field] = request($field);
            }

            $data['uf'] = strtoupper((string) request('uf'));
        }

        $fiscalSettings->update($data);

        if ($approved) {
            session()->flash('success', 'Configurações fiscais atualizadas.');

            return redirect()->route('admin.settings.fiscal_settings.edit');
        }

        $payload = array_merge(
            $fiscalSettings->only([...self::IDENTITY_FIELDS, 'codigo_municipio']),
            request()->only(['email', 'telefone', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'municipio'])
        );

        // Keep editing the same pending request (and its certificate, if no
        // new file was sent) instead of piling up duplicates.
        $pending = $onboarding?->status === FiscalOnboardingRequest::STATUS_PENDING ? $onboarding : null;

        $attributes = [
            'tenant_id' => $tenant->id,
            'status' => FiscalOnboardingRequest::STATUS_PENDING,
            'payload' => $payload,
            'error' => null,
        ];

        if ($certificado) {
            $attributes['certificado'] = $certificado;
            $attributes['senha_certificado'] = (string) request('senha_certificado');
        } elseif (! $pending && $onboarding?->certificado) {
            // Resubmitting after a rejection/failure without a new file.
            $attributes['certificado'] = $onboarding->certificado;
            $attributes['senha_certificado'] = $onboarding->senha_certificado;
        }

        if ($pending) {
            $pending->update($attributes);
        } else {
            FiscalOnboardingRequest::create($attributes);
        }

        session()->flash('success', 'Dados enviados! A Volmaster vai revisar e ativar a emissão de notas fiscais. Você verá o status nesta tela.');

        return redirect()->route('admin.settings.fiscal_settings.edit');
    }

    /**
     * Only client shops (tenant subdomains) use this screen.
     */
    protected function currentTenant()
    {
        abort_unless(app()->bound('currentTenant'), 404);

        return app('currentTenant');
    }
}
