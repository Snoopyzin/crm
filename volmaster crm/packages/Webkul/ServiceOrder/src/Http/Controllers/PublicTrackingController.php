<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\JornadaCliente;
use Webkul\ServiceOrder\Services\OficinaPublica;

/**
 * Public, unauthenticated "onde está meu veículo" tracking page -- the link
 * is a Laravel signed URL (no extra token column needed, no login), meant
 * to be sent to the client by WhatsApp/e-mail. Mirrors PublicFeedbackController
 * in spirit: no admin auth, read-only.
 */
class PublicTrackingController extends Controller
{
    public function show(int $id): View
    {
        $serviceOrder = ServiceOrder::withoutGlobalScopes()
            ->with('vehicle')
            ->findOrFail($id);

        $jornada = app(JornadaCliente::class);
        $caminhao = JornadaCliente::caminhao($serviceOrder->vehicle->cor ?? null);

        return view('service_order::public.tracking', [
            'jornada' => $jornada->jornada($serviceOrder),
            'historico' => $jornada->historico($serviceOrder),
            'anexos' => $jornada->anexos($serviceOrder),
            'caminhao' => $caminhao,
            'oficina' => OficinaPublica::dados($caminhao['slug']),
            'serviceOrder' => $serviceOrder,
            'steps' => ServiceOrder::customerTrackingSteps(),
            'currentStep' => $serviceOrder->customerTrackingStepIndex(),
            'isCancelled' => $serviceOrder->workshop_status === ServiceOrder::STAGE_CANCELADA,
        ]);
    }
}
