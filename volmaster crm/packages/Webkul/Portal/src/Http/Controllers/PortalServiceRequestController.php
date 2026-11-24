<?php

namespace Webkul\Portal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Portal\Models\ServiceRequest;
use Webkul\Vehicle\Models\Vehicle;

class PortalServiceRequestController extends Controller
{
    public function index(): View
    {
        $clientUser = auth()->guard('client')->user();

        $serviceRequests = ServiceRequest::where('client_user_id', $clientUser->id)
            ->latest()
            ->get();

        return view('portal::service-requests.index', compact('serviceRequests'));
    }

    public function create(): View
    {
        $clientUser = auth()->guard('client')->user();

        $vehicles = Vehicle::where('person_id', $clientUser->person_id)->orderBy('marca')->get();

        return view('portal::service-requests.create', compact('vehicles'));
    }

    public function store(): RedirectResponse
    {
        $clientUser = auth()->guard('client')->user();

        $data = $this->validate(request(), [
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'veiculo_descricao_livre' => 'required_without:vehicle_id|nullable|string|max:255',
            'descricao' => 'required|string',
            'data_preferencia' => 'nullable|date',
        ]);

        if (! empty($data['vehicle_id'])) {
            $ownsVehicle = Vehicle::where('id', $data['vehicle_id'])
                ->where('person_id', $clientUser->person_id)
                ->exists();

            abort_unless($ownsVehicle, 403);
        }

        ServiceRequest::create([
            ...$data,
            'person_id' => $clientUser->person_id,
            'client_user_id' => $clientUser->id,
            'status' => ServiceRequest::STATUS_PENDENTE,
        ]);

        session()->flash('success', trans('portal::app.service-requests.create.success'));

        return redirect()->route('portal.service_requests.index');
    }
}
