<?php

namespace Webkul\Portal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\ServiceOrder\Models\ServiceOrder;

class PortalServiceOrderController extends Controller
{
    public function index(): View
    {
        $serviceOrders = $this->scoped()->latest()->paginate(10);

        return view('portal::service-orders.index', compact('serviceOrders'));
    }

    public function view(int $id): View
    {
        $serviceOrder = $this->scoped()->findOrFail($id);

        $serviceOrder->load(['vehicle', 'mechanic', 'items']);

        return view('portal::service-orders.view', compact('serviceOrder'));
    }

    public function approve(int $id): RedirectResponse
    {
        return $this->decide($id, ServiceOrder::STATUS_AUTORIZADO, trans('portal::app.service-orders.view.approve-success'));
    }

    public function reject(int $id): RedirectResponse
    {
        return $this->decide($id, ServiceOrder::STATUS_CANCELADA, trans('portal::app.service-orders.view.reject-success'));
    }

    protected function decide(int $id, string $newStatus, string $successMessage): RedirectResponse
    {
        $serviceOrder = $this->scoped()->findOrFail($id);

        if ($serviceOrder->workshop_status !== ServiceOrder::STATUS_ABERTA) {
            session()->flash('error', trans('portal::app.service-orders.view.decision-locked'));

            return redirect()->route('portal.service_orders.view', $id);
        }

        $serviceOrder->update(['workshop_status' => $newStatus]);

        session()->flash('success', $successMessage);

        return redirect()->route('portal.service_orders.view', $id);
    }

    protected function scoped()
    {
        return ServiceOrder::where('person_id', auth()->guard('client')->user()->person_id);
    }
}
