<?php

namespace Webkul\WhatsApp\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\WhatsApp\Models\WhatsAppSettings;

class WhatsAppSettingsController extends Controller
{
    /**
     * Show the tenant's single WhatsApp Cloud API settings row, creating it
     * with defaults on first access.
     */
    public function edit(): View
    {
        $whatsAppSettings = WhatsAppSettings::current();

        return view('whatsapp::settings.edit', compact('whatsAppSettings'));
    }

    /**
     * Update the tenant's WhatsApp Cloud API settings.
     *
     * "access_token" is left blank on the edit form -- the stored secret is
     * never redisplayed -- so it's only overwritten when actually typed.
     */
    public function update(): RedirectResponse
    {
        $this->validate(request(), [
            'access_token' => 'nullable|string',
            'phone_number_id' => 'nullable|string|max:50',
            'business_account_id' => 'nullable|string|max:50',
            'webhook_verify_token' => 'nullable|string|max:100',
            'template_orcamento' => 'nullable|string|max:100',
            'template_diagnostico' => 'nullable|string|max:100',
            'template_pos_venda' => 'nullable|string|max:100',
        ]);

        $whatsAppSettings = WhatsAppSettings::current();

        $data = [
            'phone_number_id' => request('phone_number_id'),
            'business_account_id' => request('business_account_id'),
            'webhook_verify_token' => request('webhook_verify_token'),
            'template_orcamento' => request('template_orcamento') ?: 'orcamento_pronto',
            'template_diagnostico' => request('template_diagnostico') ?: 'diagnostico_pronto',
            'template_pos_venda' => request('template_pos_venda') ?: 'pesquisa_satisfacao',
        ];

        if (request()->filled('access_token')) {
            $data['access_token'] = request('access_token');
        }

        $whatsAppSettings->update($data);

        session()->flash('success', 'Configurações do WhatsApp atualizadas com sucesso.');

        return redirect()->route('admin.settings.whatsapp_settings.edit');
    }
}
