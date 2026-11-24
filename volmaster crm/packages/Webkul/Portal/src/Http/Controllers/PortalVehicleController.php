<?php

namespace Webkul\Portal\Http\Controllers;

use Illuminate\View\View;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\Vehicle\Models\Vehicle;

class PortalVehicleController extends Controller
{
    public function index(): View
    {
        $vehicles = $this->scoped()->orderBy('marca')->get();

        return view('portal::vehicles.index', compact('vehicles'));
    }

    public function view(int $id): View
    {
        $vehicle = $this->scoped()->findOrFail($id);

        $serviceOrders = ServiceOrder::where('vehicle_id', $vehicle->id)->latest()->get();

        return view('portal::vehicles.view', compact('vehicle', 'serviceOrders'));
    }

    protected function scoped()
    {
        return Vehicle::where('person_id', auth()->guard('client')->user()->person_id);
    }
}
