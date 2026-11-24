<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\TermoEntrada;

/**
 * Página pública do termo de entrada (B2): o cliente abre o link que recebeu no WhatsApp, confere
 * os dados do veículo, assina na tela e aceita. Quem tem o link é quem aceita; nada aparece além
 * dos dados do veículo (nunca CPF, valores nem crédito).
 */
class TermoPublicoController extends Controller
{
    public function __construct(protected TermoEntrada $termos) {}

    public function show(string $token): View
    {
        $termo = $this->termos->encontrar($token);

        abort_unless($termo, 404);

        $os = ServiceOrder::with('vehicle')->findOrFail($termo->quote_id);

        return view('service_order::public.termo', [
            'termo' => $termo,
            'os' => $os,
            'texto' => $this->termos->texto(),
            'fotos' => $os->attachments()->where('tipo', 'termo_foto')->count(),
            'token' => $token,
        ]);
    }

    public function aceitar(string $token, Request $request): RedirectResponse
    {
        $termo = $this->termos->encontrar($token);

        abort_unless($termo, 404);

        $request->validate([
            'nome' => 'required|string|max:150',
            'autoriza_rodagem' => 'required|in:0,1',
            'li_e_aceito' => 'accepted',
            'assinatura' => 'nullable|string|max:400000',
        ], [
            'nome.required' => 'Informe o seu nome.',
            'autoriza_rodagem.required' => 'Responda se autoriza o teste de rodagem (sim ou não).',
            'li_e_aceito.accepted' => 'Marque que leu e aceita o termo.',
        ]);

        $this->termos->aceitar($termo, $request->only(['nome', 'autoriza_rodagem', 'assinatura']), (string) $request->ip(), $request->userAgent());

        return redirect()->route('service_orders.termo.show', $token);
    }
}
