<?php

namespace Webkul\RemoteService\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Webkul\RemoteService\Models\RemoteService;

/**
 * Public, unauthenticated "book a programming slot" link -- separate from
 * the admin-only RemoteServiceController. Anyone with the link can submit a
 * request without logging in; it lands as "Pendente" until a consultant
 * reviews/confirms it (see RemoteServiceController::approve()), which is
 * also where the exact internal tipo_atendimento gets picked from the full
 * catalog -- the client only ever sees these 3 broad options, not the long
 * internal list of specific service names.
 */
class PublicScheduleController extends Controller
{
    public const PROGRAMACAO = 'Programação';

    public const SUPORTE_TECHTOOL = 'Suporte do Techtool';

    /**
     * Sentinel value for "I don't know which one I need yet".
     */
    public const NAO_SEI = 'Ainda não sei, preciso de ajuda para identificar';

    public function create(): View
    {
        return view('remote_service::public.schedule', [
            'tipos' => [self::PROGRAMACAO, self::SUPORTE_TECHTOOL],
            'naoSei' => self::NAO_SEI,
        ]);
    }

    public function store(): RedirectResponse
    {
        request()->validate([
            'whatsapp' => 'required|string|max:30',
            'nome' => 'required|string|max:255',
            'nome_oficina' => 'required|string|max:255',
            'tipo_atendimento' => 'required|array',
            'tipo_atendimento.*' => 'string|max:255',
            'teamviewer_id' => 'required|string|max:255',
            'teamviewer_password' => 'required|string|max:255',
        ]);

        $tiposInformados = request('tipo_atendimento', []);
        $naoSabe = in_array(self::NAO_SEI, $tiposInformados, true);

        $remoteService = RemoteService::create([
            'cliente_nome_livre' => request('nome'),
            'nome_oficina' => request('nome_oficina'),
            'whatsapp' => request('whatsapp'),
            'teamviewer_id' => request('teamviewer_id'),
            'teamviewer_password' => request('teamviewer_password'),
            'tipo_atendimento' => $naoSabe ? null : $tiposInformados,
            'descricao_problema' => $naoSabe
                ? 'Cliente não sabe qual programação precisa -- entrar em contato pelo WhatsApp para identificar.'
                : 'Agendamento solicitado pelo cliente via link público.',
            'status' => RemoteService::STATUS_PENDENTE,
            'fila_token' => (string) Str::uuid(),
        ]);

        return redirect()->route('remote_service.public.status', $remoteService->fila_token);
    }

    public function status(string $token): View
    {
        $remoteService = RemoteService::where('fila_token', $token)->firstOrFail();

        $posicao = null;

        if (in_array($remoteService->status, [RemoteService::STATUS_PENDENTE, RemoteService::STATUS_ABERTO])) {
            // Only counts requests that came through this same public link
            // (have a fila_token) -- walk-ins created directly by staff in
            // the admin panel aren't part of this queue.
            $posicao = RemoteService::whereNotNull('fila_token')
                ->whereIn('status', [RemoteService::STATUS_PENDENTE, RemoteService::STATUS_ABERTO])
                ->where('created_at', '<', $remoteService->created_at)
                ->count();
        }

        return view('remote_service::public.status', [
            'remoteService' => $remoteService,
            'posicao' => $posicao,
        ]);
    }
}
