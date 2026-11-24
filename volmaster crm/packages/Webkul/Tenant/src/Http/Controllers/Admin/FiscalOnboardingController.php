<?php

namespace Webkul\Tenant\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Fiscal\Models\FiscalOnboardingRequest;
use Webkul\Fiscal\Services\FocusEmpresaService;

/**
 * Volmaster approving/rejecting a client shop's "Nota Fiscal" request (sent
 * from the shop's own Configurações > Nota Fiscal). Approval is what costs
 * money on Focus NFe (one more CNPJ on the plan), hence the manual step.
 * Root domain + full administrators only.
 */
class FiscalOnboardingController extends Controller
{
    public function approve(int $id, FocusEmpresaService $service): RedirectResponse
    {
        $this->authorizeLandlordAdmin();

        $request = FiscalOnboardingRequest::findOrFail($id);

        abort_unless($request->status === FiscalOnboardingRequest::STATUS_PENDING, 422, 'Esse pedido não está mais pendente.');

        if (! config('focusnfe.token_principal')) {
            session()->flash('error', 'Configure FOCUSNFE_TOKEN_PRINCIPAL no .env do servidor antes de aprovar.');

            return redirect()->route('admin.tenants.index');
        }

        try {
            session()->flash('success', $service->approve($request));
        } catch (\Throwable $e) {
            $request->update([
                'status' => FiscalOnboardingRequest::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'reviewed_at' => now(),
            ]);

            session()->flash('error', 'A Focus NFe recusou: '.$e->getMessage());
        }

        return redirect()->route('admin.tenants.index');
    }

    public function reject(int $id): RedirectResponse
    {
        $this->authorizeLandlordAdmin();

        $request = FiscalOnboardingRequest::findOrFail($id);

        $request->update([
            'status' => FiscalOnboardingRequest::STATUS_REJECTED,
            'error' => request('motivo') ?: 'Recusado pela Volmaster.',
            'reviewed_at' => now(),
        ]);

        session()->flash('success', 'Pedido recusado -- a oficina vai ver o motivo na tela de Nota Fiscal.');

        return redirect()->route('admin.tenants.index');
    }

    protected function authorizeLandlordAdmin(): void
    {
        abort_if(app()->bound('currentTenant'), 404);

        abort_unless(auth()->guard('user')->user()?->role?->permission_type === 'all', 403);
    }
}
