<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Contact\Models\Person;
use Webkul\ServiceOrder\Services\Credito\CreditoCliente;

/**
 * Cor de crédito do cliente (B1): quem registra a análise na página do cliente.
 */
class CreditoClienteController extends Controller
{
    public function registrar(int $personId, CreditoCliente $credito): RedirectResponse
    {
        abort_unless($credito->podeRegistrar(), 403, 'Só o Financeiro, o Gerente e o Proprietário registram a cor de crédito.');

        Person::findOrFail($personId);

        $this->validate(request(), [
            'cor_credito' => 'required|in:verde,amarelo,vermelho',
            'observacao' => 'nullable|string|max:1000',
            'justificativa' => 'nullable|string|max:1000',
        ]);

        $credito->registrar($personId, request('cor_credito'), request('observacao'), request('justificativa'));

        session()->flash('success', 'Cor de crédito registrada.');

        return back();
    }
}
