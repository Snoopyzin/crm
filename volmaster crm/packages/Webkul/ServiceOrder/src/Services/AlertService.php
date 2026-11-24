<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\ServiceOrder\Models\AlertDelivery;
use Webkul\ServiceOrder\Models\BarrierInstance;
use Webkul\ServiceOrder\Models\BarrierRule;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\User\Models\User;

/**
 * Cria os alertas de cada nível de uma barreira e os resolve quando a
 * barreira é cumprida. Os textos nunca levam valores em reais nem dados de
 * crédito.
 */
class AlertService
{
    public function __construct(
        protected RecipientResolver $recipients,
        protected AlertDispatcher $dispatcher,
    ) {}

    /**
     * Dispara o nível $nivel da regra para os destinatários dele.
     *
     * Regra do dono blindado: o proprietário só recebe o nível 3 e os alertas
     * críticos -- mesmo que a configuração o ponha num nível mais baixo.
     *
     * @return Collection<int, WorkshopAlert>
     */
    public function fire(
        BarrierRule $rule,
        int $nivel,
        ServiceOrder $serviceOrder,
        ?BarrierInstance $instance,
        ?ServiceOrderItem $item,
        Carbon $vencidoEm,
    ): Collection {
        $destinos = $rule->destinos($nivel);

        if (! $rule->critico && $nivel < 3) {
            $destinos = array_values(array_diff($destinos, ['proprietario']));
        }

        $users = $this->recipients->resolve($destinos, $serviceOrder, $item);

        $responsavel = $this->responsavel($rule, $serviceOrder, $item);

        $alerts = collect();

        foreach ($users as $user) {
            $alert = WorkshopAlert::create([
                'user_id' => $user->id,
                'service_order_id' => $serviceOrder->id,
                'barrier_instance_id' => $instance?->id,
                'barrier_code' => $rule->codigo,
                'nivel' => $rule->critico ? 0 : $nivel,
                'critico' => $rule->critico,
                'titulo' => $rule->nome,
                'mensagem' => $this->mensagem($rule, $serviceOrder, $vencidoEm, $responsavel),
                'responsavel' => $responsavel,
                'vencido_em' => $vencidoEm,
                'disparado_em' => now(),
            ]);

            $alerts->push($alert);
        }

        ServiceOrderEvent::record($serviceOrder, 'alerta_enviado', [
            'barreira' => $rule->codigo,
            'nivel' => $nivel,
            'critico' => $rule->critico,
            'destinatarios' => $users->pluck('id')->all(),
            'item_id' => $item?->id,
        ], usuarioId: 0);

        foreach ($alerts as $alert) {
            $this->dispatcher->dispatch($alert);
        }

        return $alerts;
    }

    /**
     * Alerta que não nasce de uma barreira de prazo (OS parada, pedido de
     * alçada, RH a vencer...). Segue as mesmas regras: o proprietário só
     * recebe se for crítico, e a mensagem nunca leva valores nem crédito.
     *
     * Com $unicoPorDia, não repete o mesmo alerta (código + OS + destinatário)
     * no mesmo dia.
     *
     * @param  string[]  $destinos
     * @return Collection<int, WorkshopAlert>
     */
    public function raise(
        string $code,
        string $titulo,
        string $mensagem,
        array $destinos,
        ?ServiceOrder $serviceOrder = null,
        bool $critico = false,
        bool $unicoPorDia = false,
        string $responsavel = '-',
        bool $permitirDono = false,
    ): Collection {
        // O dono só recebe alertas críticos, o nível 3 e o que ele mesmo pediu (resumo opcional).
        if (! $critico && ! $permitirDono) {
            $destinos = array_values(array_diff($destinos, ['proprietario']));
        }

        $alerts = collect();

        foreach ($this->recipients->resolve($destinos, $serviceOrder) as $user) {
            if ($unicoPorDia && WorkshopAlert::where('user_id', $user->id)
                ->where('barrier_code', $code)
                ->where('service_order_id', $serviceOrder?->id)
                ->where('disparado_em', '>=', now()->startOfDay())
                ->exists()) {
                continue;
            }

            $alerts->push(WorkshopAlert::create([
                'user_id' => $user->id,
                'service_order_id' => $serviceOrder?->id,
                'barrier_code' => $code,
                'nivel' => $critico ? 0 : 1,
                'critico' => $critico,
                'titulo' => $titulo,
                'mensagem' => $mensagem,
                'responsavel' => $responsavel,
                'vencido_em' => now(),
                'disparado_em' => now(),
            ]));
        }

        foreach ($alerts as $alert) {
            $this->dispatcher->dispatch($alert);
        }

        return $alerts;
    }

    /**
     * Aviso a uma pessoa específica (ex.: o resultado de um pedido de alçada
     * volta a quem pediu). Mesmas regras: sem valores nem crédito no texto.
     */
    public function notifyUser(User $user, string $code, string $titulo, string $mensagem, ?ServiceOrder $serviceOrder = null): WorkshopAlert
    {
        $alert = WorkshopAlert::create([
            'user_id' => $user->id,
            'service_order_id' => $serviceOrder?->id,
            'barrier_code' => $code,
            'nivel' => 1,
            'critico' => false,
            'titulo' => $titulo,
            'mensagem' => $mensagem,
            'responsavel' => '-',
            'vencido_em' => now(),
            'disparado_em' => now(),
        ]);

        $this->dispatcher->dispatch($alert);

        return $alert;
    }

    /**
     * Barreira cumprida: resolve os alertas abertos e avisa quem foi alertado.
     */
    public function resolveInstance(BarrierInstance $instance, Carbon $quando): void
    {
        $alerts = WorkshopAlert::where('barrier_instance_id', $instance->id)->abertos()->get();

        if ($alerts->isEmpty()) {
            return;
        }

        $serviceOrder = ServiceOrder::with(['vehicle', 'person'])->find($instance->service_order_id);

        foreach ($alerts as $alert) {
            $alert->update([
                'status' => WorkshopAlert::STATUS_RESOLVIDO,
                'resolvido_em' => $quando,
                'mensagem_resolvido' => "RESOLVIDO - {$alert->titulo} da OS #{$alert->service_order_id} às ".$quando->format('H:i').'.',
            ]);

            $this->dispatcher->dispatchResolved($alert);
        }

        if ($serviceOrder) {
            ServiceOrderEvent::record($serviceOrder, 'alerta_resolvido', [
                'barreira' => $instance->barrier_code,
                'alertas' => $alerts->pluck('id')->all(),
            ], usuarioId: 0);
        }
    }

    /**
     * Confirmação vinda do celular (service worker): entregue ou aberto.
     */
    public function acknowledge(string $token, string $status): bool
    {
        $alert = WorkshopAlert::where('token', $token)->first();

        if (! $alert || ! in_array($status, ['entregue', 'aberto'], true)) {
            return false;
        }

        // "aberto" vale mais que "entregue" e nunca se volta atrás.
        if ($alert->push_status === 'aberto') {
            return true;
        }

        $alert->forceFill(['push_status' => $status]);

        if ($status === 'aberto') {
            $alert->push_aberto_em = now();
            $alert->lido_em ??= now();
        }

        $alert->save();

        AlertDelivery::create(['alert_id' => $alert->id, 'canal' => 'push', 'tipo' => 'alerta', 'status' => $status]);

        return true;
    }

    /**
     * "ALERTA - {barreira}. OS #{numero} - {placa} - {cliente}. Prazo venceu
     * às {hora}. Responsável: {nome}."
     */
    public function mensagem(BarrierRule $rule, ServiceOrder $serviceOrder, Carbon $quando, string $responsavel): string
    {
        $serviceOrder->loadMissing(['vehicle', 'person']);

        $placa = $serviceOrder->vehicle?->placa ?? '-';
        $cliente = $serviceOrder->person?->name ?? '-';
        $verbo = $rule->critico ? 'Ocorrido' : 'Prazo venceu';

        return "ALERTA - {$rule->nome}. OS #{$serviceOrder->id} - {$placa} - {$cliente}. {$verbo} às ".$quando->format('H:i').". Responsável: {$responsavel}.";
    }

    protected function responsavel(BarrierRule $rule, ServiceOrder $serviceOrder, ?ServiceOrderItem $item): string
    {
        // Barreira de operação: o técnico que está executando.
        if ($rule->modo === BarrierRule::MODO_OPERACAO) {
            $mechanicId = $item?->funcionario_id ?: $serviceOrder->mechanic_id;

            return (string) (Mechanic::find($mechanicId)?->nome ?? '-');
        }

        $nomes = $this->recipients->resolve($rule->destinos(1), $serviceOrder, $item)->pluck('name');

        return $nomes->isEmpty() ? '-' : $nomes->take(2)->implode(' / ');
    }
}
