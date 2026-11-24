<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Models\PushSubscription;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\StageGate;
use Webkul\ServiceOrder\Services\WebPush;

/**
 * Alertas do usuário logado: sino com contador, lista (abertos e
 * resolvidos) e inscrição do celular para notificações.
 */
class AlertController extends Controller
{
    public function index(): View
    {
        $alerts = WorkshopAlert::where('user_id', auth()->guard('user')->id())
            ->orderByRaw("status = 'aberto' desc")
            ->latest('disparado_em')
            ->paginate(30);

        return view('service_order::alerts.index', compact('alerts'));
    }

    /**
     * Dados do sino (consultados a cada minuto pela página).
     */
    public function summary(): JsonResponse
    {
        if (! StageGate::active()) {
            return response()->json(['ativo' => false, 'contador' => 0, 'itens' => []]);
        }

        $userId = auth()->guard('user')->id();

        $abertos = WorkshopAlert::where('user_id', $userId)->abertos();

        return response()->json([
            'ativo' => true,
            'contador' => (clone $abertos)->whereNull('lido_em')->count(),
            'itens' => (clone $abertos)->latest('disparado_em')->limit(8)->get()->map(fn (WorkshopAlert $alert) => [
                'id' => $alert->id,
                'titulo' => $alert->titulo,
                'mensagem' => $alert->mensagem,
                'critico' => $alert->critico,
                'lido' => (bool) $alert->lido_em,
                'quando' => $alert->disparado_em->format('d/m H:i'),
                'url' => $alert->service_order_id ? route('admin.service_orders.view', $alert->service_order_id, false) : null,
            ])->all(),
        ]);
    }

    /**
     * "Ciente": marca como lido; alerta crítico (sem barreira para cumprir)
     * também sai da lista de abertos.
     */
    public function acknowledge(int $alertId): RedirectResponse|JsonResponse
    {
        $alert = WorkshopAlert::where('user_id', auth()->guard('user')->id())->findOrFail($alertId);

        $alert->forceFill(['lido_em' => $alert->lido_em ?? now()]);

        if ($alert->critico && $alert->status === WorkshopAlert::STATUS_ABERTO) {
            $alert->status = WorkshopAlert::STATUS_RESOLVIDO;
            $alert->resolvido_em = now();
        }

        $alert->save();

        return request()->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function markAllRead(): RedirectResponse|JsonResponse
    {
        WorkshopAlert::where('user_id', auth()->guard('user')->id())->abertos()->whereNull('lido_em')->update(['lido_em' => now()]);

        return request()->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    /**
     * Guarda a inscrição de Web Push do aparelho do usuário.
     */
    public function subscribe(): JsonResponse
    {
        $data = $this->validate(request(), [
            'endpoint' => 'required|url|max:2000',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                'user_id' => auth()->guard('user')->id(),
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'user_agent' => substr((string) request()->userAgent(), 0, 250),
            ]
        );

        return response()->json(['ok' => true]);
    }

    /**
     * "Testar notificação" (Minha Conta): manda uma notificação de teste para os aparelhos
     * de quem está logado e diz o que aconteceu. Tocar nela abre o sistema.
     */
    public function testPush(WebPush $push): JsonResponse
    {
        if (! WebPush::configurado()) {
            return response()->json([
                'status' => 'sem_chaves',
                'mensagem' => 'As chaves das notificações ainda não foram geradas. Peça ao responsável da oficina para gerar em Configurações > Indicadores e alertas.',
            ]);
        }

        $aparelhos = PushSubscription::where('user_id', auth()->guard('user')->id())->get();

        if ($aparelhos->isEmpty()) {
            return response()->json([
                'status' => 'sem_aparelho',
                'mensagem' => 'Nenhum aparelho seu está ativado ainda. Toque em "Ativar neste aparelho" primeiro (no iPhone, abra o sistema pelo ícone da Tela de Início).',
            ]);
        }

        $enviados = 0;
        $falhas = 0;

        foreach ($aparelhos as $aparelho) {
            $resultado = $push->send($aparelho, [
                'title' => 'Teste de notificação',
                'body' => 'Funcionou! Toque aqui para abrir o sistema.',
                'url' => '/admin',
                'tag' => 'teste-'.$aparelho->id,
                'critical' => false,
                'tokens' => [],
            ], ttl: 300, urgency: 'high');

            if ($resultado['ok']) {
                $enviados++;
            } else {
                $falhas++;

                // Aparelho que desinstalou o app (404/410): sai da lista.
                if ($resultado['gone']) {
                    $aparelho->delete();
                }
            }
        }

        return response()->json($enviados > 0 ? [
            'status' => 'enviado',
            'enviados' => $enviados,
            'falhas' => $falhas,
            'mensagem' => 'Teste enviado para '.$enviados.' aparelho(s). Se a notificação não aparecer em alguns segundos, confira se as notificações estão liberadas no aparelho.',
        ] : [
            'status' => 'falhou',
            'enviados' => 0,
            'falhas' => $falhas,
            'mensagem' => 'Não foi possível entregar o teste. Ative o aparelho de novo (o aplicativo pode ter sido desinstalado) ou confira o e-mail de contato das notificações.',
        ]);
    }

    public function unsubscribe(): JsonResponse
    {
        $this->validate(request(), ['endpoint' => 'required|url|max:2000']);

        PushSubscription::where('endpoint_hash', PushSubscription::hashEndpoint(request('endpoint')))
            ->where('user_id', auth()->guard('user')->id())
            ->delete();

        return response()->json(['ok' => true]);
    }
}
