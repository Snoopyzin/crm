<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Webkul\ServiceOrder\Models\ServiceOrderFeedback;

/**
 * Public, unauthenticated page reached from the WhatsApp Pós-Venda template
 * link -- mirrors Webkul\RemoteService\Http\Controllers\PublicScheduleController
 * in spirit (no admin auth, token-addressed record).
 */
class PublicFeedbackController extends Controller
{
    public function show(string $token): View
    {
        $feedback = ServiceOrderFeedback::where('token', $token)->firstOrFail();

        return view('service_order::public.feedback', compact('feedback'));
    }

    public function respond(string $token): RedirectResponse
    {
        $feedback = ServiceOrderFeedback::where('token', $token)->firstOrFail();

        request()->validate([
            'avaliacao' => 'required|in:Bom,Ruim',
            'comentario' => 'nullable|string|max:1000',
        ]);

        $feedback->update([
            'avaliacao' => request('avaliacao'),
            'comentario' => request('comentario'),
            'respondido_em' => now(),
        ]);

        return redirect()->route('service_orders.feedback.show', $token);
    }
}
