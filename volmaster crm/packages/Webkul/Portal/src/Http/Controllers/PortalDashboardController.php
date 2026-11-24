<?php

namespace Webkul\Portal\Http\Controllers;

use Illuminate\View\View;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\Vehicle\Models\Vehicle;

class PortalDashboardController extends Controller
{
    public function index(): View
    {
        $personId = auth()->guard('client')->user()->person_id;

        $vehicleCount = Vehicle::where('person_id', $personId)->count();

        $recentServiceOrders = ServiceOrder::where('person_id', $personId)
            ->latest()
            ->take(5)
            ->get();

        $pendingApprovalCount = ServiceOrder::where('person_id', $personId)
            ->where('workshop_status', ServiceOrder::STATUS_ABERTA)
            ->count();

        return view('portal::dashboard', compact('vehicleCount', 'recentServiceOrders', 'pendingApprovalCount'));
    }
}
