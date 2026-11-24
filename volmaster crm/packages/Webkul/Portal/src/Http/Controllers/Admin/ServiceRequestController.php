<?php

namespace Webkul\Portal\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Portal\DataGrids\ServiceRequestDataGrid;
use Webkul\Portal\Models\ServiceRequest;

class ServiceRequestController extends Controller
{
    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(ServiceRequestDataGrid::class)->process();
        }

        return view('portal::admin.service-requests.index');
    }

    public function view(int $id): View
    {
        $serviceRequest = ServiceRequest::findOrFail($id);

        $serviceRequest->load(['person', 'vehicle', 'clientUser', 'serviceOrder']);

        return view('portal::admin.service-requests.view', compact('serviceRequest'));
    }

    /**
     * Send the staff member to the existing Service Order create form with
     * the person pre-selected (via flashed old input -- the create form's
     * `old('person_id')` picks this up natively, no edits to that package
     * needed). The vehicle still needs a manual reselect: that form's
     * vehicle dropdown is populated by JS after the person is chosen and
     * doesn't read `old()`, which is a limitation of the existing page,
     * not something to work around by editing it.
     */
    public function convert(int $id): RedirectResponse
    {
        $serviceRequest = ServiceRequest::findOrFail($id);

        if ($serviceRequest->status === ServiceRequest::STATUS_PENDENTE) {
            $serviceRequest->update(['status' => ServiceRequest::STATUS_EM_ANALISE]);
        }

        return redirect()
            ->route('admin.service_orders.create')
            ->withInput([
                'person_id' => $serviceRequest->person_id,
                'servico' => trans('portal::app.admin.service-requests.convert.servico-prefix'),
                'description' => $serviceRequest->descricao,
            ]);
    }

    /**
     * Link an already-created Service Order back to this request once
     * staff has finished the conversion above.
     */
    public function linkOrder(int $id): RedirectResponse
    {
        $data = $this->validate(request(), [
            'service_order_id' => 'required|integer|exists:quotes,id',
        ]);

        $serviceRequest = ServiceRequest::findOrFail($id);

        $serviceRequest->update([
            'service_order_id' => $data['service_order_id'],
            'status' => ServiceRequest::STATUS_CONVERTIDA,
        ]);

        session()->flash('success', trans('portal::app.admin.service-requests.view.link-success'));

        return redirect()->route('admin.service_requests.view', $id);
    }

    public function reject(int $id): RedirectResponse
    {
        ServiceRequest::findOrFail($id)->update(['status' => ServiceRequest::STATUS_RECUSADA]);

        session()->flash('success', trans('portal::app.admin.service-requests.view.reject-success'));

        return redirect()->route('admin.service_requests.view', $id);
    }
}
