<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Webkul\ServiceOrder\Models\AlertDelivery;
use Webkul\ServiceOrder\Models\PushSubscription;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\Sms\SmsManager;

/**
 * Entrega os alertas: sino dentro do sistema (sempre), notificação no
 * celular (Web Push) e SMS de reserva.
 *
 * SMS só para: alertas críticos, alertas de nível 3 (proprietário) e
 * alertas de nível 2 cuja notificação não foi aberta em 10 minutos ou falhou.
 * Alertas do mesmo destinatário dentro da janela de agrupamento viram uma só
 * notificação; o horário de silêncio segura tudo, menos os críticos.
 */
class AlertDispatcher
{
    public function __construct(
        protected WebPush $push,
        protected SmsManager $sms,
    ) {}

    public function dispatch(WorkshopAlert $alert): void
    {
        $this->delivery($alert, 'in_app', 'alerta', 'enviado');

        $this->dispatchPush($alert);

        // Crítico toca na hora; o SMS comum do nível 3 espera o silêncio acabar (ver flushPendingSms).
        if ($alert->critico || ($alert->nivel >= 3 && ! $this->inQuietHours(now()))) {
            $this->sendSms($alert);
        }
    }

    /**
     * Avisa, por push, quem foi alertado que a barreira foi cumprida.
     */
    public function dispatchResolved(WorkshopAlert $alert): void
    {
        $this->delivery($alert, 'in_app', 'resolvido', 'enviado');

        $subscriptions = PushSubscription::where('user_id', $alert->user_id)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $status = $this->pushTo($subscriptions, [
            'title' => 'Resolvido',
            'body' => (string) $alert->mensagem_resolvido,
            'url' => $this->urlFor($alert),
            'tag' => 'resolvido-'.$alert->id,
            'critical' => false,
            'tokens' => [$alert->token],
        ]);

        $this->delivery($alert, 'push', 'resolvido', $status);
    }

    /**
     * Chamado a cada minuto pelo job: libera notificações seguradas
     * (agrupamento e silêncio) e dispara o SMS de reserva do nível 2.
     */
    public function flush(Carbon $now): void
    {
        $this->flushPendingPush($now);
        $this->flushPendingSms($now);
        $this->escalateLevelTwoToSms($now);
    }

    /**
     * SMS de nível 3 (não crítico) que ficou esperando o horário de silêncio acabar.
     */
    protected function flushPendingSms(Carbon $now): void
    {
        if ($this->inQuietHours($now)) {
            return;
        }

        WorkshopAlert::abertos()->where('nivel', '>=', 3)->where('critico', false)->whereNull('sms_status')->get()
            ->each(fn (WorkshopAlert $alert) => $this->sendSms($alert));
    }

    public function inQuietHours(Carbon $at): bool
    {
        if (! WorkshopSettings::get('silencio_ativo', false)) {
            return false;
        }

        $start = Carbon::parse($at->format('Y-m-d').' '.WorkshopSettings::get('silencio_inicio', '20:00'), $at->timezone);
        $end = Carbon::parse($at->format('Y-m-d').' '.WorkshopSettings::get('silencio_fim', '07:00'), $at->timezone);

        // Janela que atravessa a meia-noite (ex.: 20h às 7h).
        if ($end->lte($start)) {
            return $at->gte($start) || $at->lt($end);
        }

        return $at->gte($start) && $at->lt($end);
    }

    protected function dispatchPush(WorkshopAlert $alert): void
    {
        if (! PushSubscription::where('user_id', $alert->user_id)->exists()) {
            $alert->update(['push_status' => 'sem_dispositivo']);

            return;
        }

        if (! $alert->critico && ($this->inQuietHours(now()) || $this->recentPush($alert->user_id, now()))) {
            $alert->update(['push_status' => 'pendente']);

            return;
        }

        $this->sendPushFor($alert->user_id, collect([$alert]));
    }

    protected function flushPendingPush(Carbon $now): void
    {
        $pending = WorkshopAlert::abertos()->where('push_status', 'pendente')->get()->groupBy('user_id');

        foreach ($pending as $userId => $alerts) {
            if ($this->inQuietHours($now) || $this->recentPush((int) $userId, $now)) {
                continue;
            }

            $this->sendPushFor((int) $userId, $alerts);
        }
    }

    /**
     * Uma notificação para todos os alertas do usuário (agrupados).
     */
    protected function sendPushFor(int $userId, $alerts): void
    {
        $subscriptions = PushSubscription::where('user_id', $userId)->get();

        $single = $alerts->count() === 1;
        $first = $alerts->first();

        $payload = [
            'title' => $single ? $first->titulo : $alerts->count().' alertas da oficina',
            'body' => $single ? $first->mensagem : $alerts->take(3)->pluck('mensagem')->implode("\n"),
            'url' => $single ? $this->urlFor($first) : route('admin.workshop.alerts.index', [], false),
            'tag' => $single ? 'alerta-'.$first->id : 'alertas-'.$userId,
            'critical' => $alerts->contains('critico', true),
            'tokens' => $alerts->pluck('token')->all(),
        ];

        $status = $this->pushTo($subscriptions, $payload);

        foreach ($alerts as $alert) {
            $alert->forceFill(['push_status' => $status, 'push_enviado_em' => now()])->save();
            $this->delivery($alert, 'push', 'alerta', $status);
        }
    }

    /**
     * Envia a todos os dispositivos do usuário. Retorna "enviado" se pelo
     * menos um aceitou, senão "falhou". Inscrições mortas são removidas.
     */
    protected function pushTo($subscriptions, array $payload): string
    {
        $ok = false;

        foreach ($subscriptions as $subscription) {
            try {
                $result = $this->push->send(
                    $subscription,
                    $payload,
                    ttl: $payload['critical'] ? 86400 : 3600,
                    urgency: $payload['critical'] ? 'high' : 'normal',
                );
            } catch (\Throwable $exception) {
                Log::warning('Falha no push: '.$exception->getMessage());

                continue;
            }

            if ($result['ok']) {
                $ok = true;
            } elseif ($result['gone']) {
                $subscription->delete();
            }
        }

        return $ok ? 'enviado' : 'falhou';
    }

    /**
     * Nível 2 vira SMS quando a notificação falhou, não existe dispositivo
     * ou não foi aberta em N minutos (padrão 10).
     */
    protected function escalateLevelTwoToSms(Carbon $now): void
    {
        // No silêncio o SMS de reserva também espera.
        if ($this->inQuietHours($now)) {
            return;
        }

        $minutos = (int) WorkshopSettings::get('sms_nao_aberto_minutos', 10);

        $candidatos = WorkshopAlert::abertos()
            ->where('nivel', 2)
            ->where('critico', false)
            ->whereNull('sms_status')
            ->whereNull('push_aberto_em')
            ->get();

        foreach ($candidatos as $alert) {
            $falhou = in_array($alert->push_status, ['falhou', 'sem_dispositivo'], true);
            $venceuEspera = $alert->disparado_em->lte($now->copy()->subMinutes($minutos));

            if ($falhou || $venceuEspera) {
                $this->sendSms($alert);
            }
        }
    }

    protected function sendSms(WorkshopAlert $alert): void
    {
        $status = $this->sms->sendAlert($alert);

        $alert->update(['sms_status' => $status]);

        $this->delivery($alert, 'sms', 'alerta', in_array($status, ['enviado', 'simulado'], true) ? 'enviado' : 'falhou', $status);
    }

    /**
     * Houve push para este usuário dentro da janela de agrupamento?
     */
    protected function recentPush(int $userId, Carbon $now): bool
    {
        $janela = (int) WorkshopSettings::get('agrupar_alertas_minutos', 5);

        return WorkshopAlert::where('user_id', $userId)
            ->whereNotNull('push_enviado_em')
            ->where('push_enviado_em', '>', $now->copy()->subMinutes($janela))
            ->exists();
    }

    protected function urlFor(WorkshopAlert $alert): string
    {
        return $alert->service_order_id
            ? route('admin.service_orders.view', $alert->service_order_id, false)
            : route('admin.workshop.alerts.index', [], false);
    }

    protected function delivery(WorkshopAlert $alert, string $canal, string $tipo, string $status, ?string $detalhe = null): void
    {
        AlertDelivery::create([
            'alert_id' => $alert->id,
            'canal' => $canal,
            'tipo' => $tipo,
            'status' => $status,
            'detalhe' => $detalhe,
        ]);
    }
}
