<?php

use Webkul\ServiceOrder\Models\PushSubscription;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\SmsLog;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\AlertDispatcher;
use Webkul\ServiceOrder\Services\AlertService;
use Webkul\ServiceOrder\Services\BarrierEngine;
use Webkul\ServiceOrder\Services\Sms\SmsGateway;
use Webkul\ServiceOrder\Services\Sms\SmsManager;
use Webkul\ServiceOrder\Services\StageAdvanceService;
use Webkul\ServiceOrder\Services\WebPush;

/** Web Push de mentira: guarda o que seria enviado e responde como mandarmos. */
class FakeWebPush extends WebPush
{
    public array $sent = [];

    public bool $fail = false;

    public function send(PushSubscription $subscription, array $payload, int $ttl = 3600, string $urgency = 'normal'): array
    {
        $this->sent[] = ['user_subscription' => $subscription->user_id, 'payload' => $payload, 'urgency' => $urgency];

        return $this->fail
            ? ['ok' => false, 'status' => 503, 'gone' => false, 'error' => 'HTTP 503']
            : ['ok' => true, 'status' => 201, 'gone' => false, 'error' => null];
    }
}

/** SMS de mentira. */
class FakeSmsGateway implements SmsGateway
{
    public array $sent = [];

    public function send(string $to, string $text): array
    {
        $this->sent[] = ['to' => $to, 'text' => $text];

        return ['ok' => true, 'id' => 'fake-'.count($this->sent)];
    }

    public function name(): string
    {
        return 'fake';
    }
}

function channels(): array
{
    $push = new FakeWebPush;
    $sms = new FakeSmsGateway;

    app()->instance(WebPush::class, $push);
    SmsManager::fake($sms);

    return [$push, $sms];
}

function subscribe(int $userId, string $suffix = 'a'): PushSubscription
{
    return PushSubscription::create([
        'user_id' => $userId,
        'endpoint' => "https://push.example.test/{$userId}/{$suffix}",
        'endpoint_hash' => PushSubscription::hashEndpoint("https://push.example.test/{$userId}/{$suffix}"),
        'p256dh' => 'x',
        'auth' => 'y',
    ]);
}

function withPhone($user, bool $consent = true)
{
    $user->forceFill(['celular' => '(11) 98888-7777', 'sms_aceite_em' => $consent ? now() : null])->save();

    return $user;
}

function diagnosisEndedAt(string $time, $os): void
{
    test()->at($time);
    ServiceOrderEvent::record($os, 'diagnostico_fim');
}

it('critério 9: alerta de nível 3 chega ao proprietário também por SMS', function () {
    [$push, $sms] = channels();
    $this->enablePilot();
    $consultor = $this->makeUser('consultor');
    $dono = withPhone($this->makeUser('proprietario'));
    subscribe($dono->id);
    $os = $this->makeOs(['user_id' => $consultor->id]);

    diagnosisEndedAt('2026-11-18 09:00:00', $os);
    app(BarrierEngine::class)->tick($this->at('2026-11-18 13:00:00'));

    expect($sms->sent)->toHaveCount(1)
        ->and($sms->sent[0]['to'])->toBe('+5511988887777')
        ->and($push->sent)->toHaveCount(1)
        ->and(SmsLog::first()->status)->toBe('enviado');
});

it('critério 9: nível 2 não aberto em 10 minutos gera SMS ao gerente; aberto não gera', function () {
    [$push, $sms] = channels();
    $this->enablePilot();
    $this->makeUser('consultor');
    $gerente = withPhone($this->makeUser('gerente'));
    subscribe($gerente->id);
    $os = $this->makeOs();

    diagnosisEndedAt('2026-11-18 09:00:00', $os);
    app(BarrierEngine::class)->tick($this->at('2026-11-18 12:00:00'));

    $alert = WorkshopAlert::where('user_id', $gerente->id)->first();
    expect($alert->push_status)->toBe('enviado')->and($sms->sent)->toBeEmpty();

    // 9 minutos depois ainda não passou o prazo de espera.
    app(AlertDispatcher::class)->flush($this->at('2026-11-18 12:09:00'));
    expect($sms->sent)->toBeEmpty();

    // 10 minutos sem abrir: SMS.
    app(AlertDispatcher::class)->flush($this->at('2026-11-18 12:10:00'));
    expect($sms->sent)->toHaveCount(1)->and($alert->refresh()->sms_status)->toBe('enviado');

    // Não repete.
    app(AlertDispatcher::class)->flush($this->at('2026-11-18 12:20:00'));
    expect($sms->sent)->toHaveCount(1);
});

it('nível 2 que o gerente abriu a tempo não vira SMS', function () {
    [$push, $sms] = channels();
    $this->enablePilot();
    $this->makeUser('consultor');
    $gerente = withPhone($this->makeUser('gerente'));
    subscribe($gerente->id);
    $os = $this->makeOs();

    diagnosisEndedAt('2026-11-18 09:00:00', $os);
    app(BarrierEngine::class)->tick($this->at('2026-11-18 12:00:00'));

    $alert = WorkshopAlert::where('user_id', $gerente->id)->first();
    app(AlertService::class)->acknowledge($alert->token, 'entregue');
    $this->at('2026-11-18 12:03:00');
    app(AlertService::class)->acknowledge($alert->token, 'aberto');

    app(AlertDispatcher::class)->flush($this->at('2026-11-18 12:30:00'));

    expect($sms->sent)->toBeEmpty()
        ->and($alert->refresh()->push_status)->toBe('aberto')
        ->and($alert->push_aberto_em)->not->toBeNull();
});

it('nível 2 cuja notificação falhou vai para SMS na hora', function () {
    [$push, $sms] = channels();
    $push->fail = true;
    $this->enablePilot();
    $this->makeUser('consultor');
    $gerente = withPhone($this->makeUser('gerente'));
    subscribe($gerente->id);
    $os = $this->makeOs();

    diagnosisEndedAt('2026-11-18 09:00:00', $os);
    app(BarrierEngine::class)->tick($this->at('2026-11-18 12:00:00'));
    app(AlertDispatcher::class)->flush($this->at('2026-11-18 12:01:00'));

    expect(WorkshopAlert::where('user_id', $gerente->id)->first()->push_status)->toBe('falhou')
        ->and($sms->sent)->toHaveCount(1);
});

it('nível 2 sem celular instalado (sem dispositivo) também recebe o SMS de reserva', function () {
    [$push, $sms] = channels();
    $this->enablePilot();
    $this->makeUser('consultor');
    withPhone($this->makeUser('gerente'));
    $os = $this->makeOs();

    diagnosisEndedAt('2026-11-18 09:00:00', $os);
    app(BarrierEngine::class)->tick($this->at('2026-11-18 12:00:00'));
    app(AlertDispatcher::class)->flush($this->at('2026-11-18 12:01:00'));

    expect($push->sent)->toBeEmpty()->and($sms->sent)->toHaveCount(1);
});

it('nível 1 nunca vai por SMS, só por notificação e sino', function () {
    [$push, $sms] = channels();
    $this->enablePilot();
    $consultor = withPhone($this->makeUser('consultor'));
    subscribe($consultor->id);
    $os = $this->makeOs(['user_id' => $consultor->id]);

    diagnosisEndedAt('2026-11-18 09:00:00', $os);
    app(BarrierEngine::class)->tick($this->at('2026-11-18 11:00:00'));
    app(AlertDispatcher::class)->flush($this->at('2026-11-18 11:30:00'));

    expect($sms->sent)->toBeEmpty()->and($push->sent)->toHaveCount(1);
});

it('critério 10: com o celular sem internet o alerta crítico chega por SMS e fica registrado no sistema', function () {
    [$push, $sms] = channels();
    $push->fail = true;
    $this->enablePilot();
    $gerente = withPhone($this->makeUser('gerente'));
    $dono = withPhone($this->makeUser('proprietario'));
    subscribe($gerente->id);
    subscribe($dono->id);
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_FATURAMENTO]);

    app(StageAdvanceService::class)->advance($os, ServiceOrder::STAGE_POS_VENDA, true, 'Emergência de pátio');

    // SMS ao gerente e ao proprietário, mesmo com o push falhando.
    expect($sms->sent)->not->toBeEmpty();

    $destinos = collect($sms->sent)->pluck('to')->unique()->all();
    expect($destinos)->toBe(['+5511988887777']);

    // O alerta fica no sino (aberto) para os dois.
    expect(WorkshopAlert::abertos()->where('user_id', $dono->id)->exists())->toBeTrue()
        ->and(WorkshopAlert::abertos()->where('user_id', $gerente->id)->exists())->toBeTrue()
        ->and(WorkshopAlert::where('user_id', $dono->id)->first()->deliveries()->where('canal', 'in_app')->exists())->toBeTrue();
});

it('SMS respeita aceite, celular e limite mensal, e registra o custo', function () {
    [$push, $sms] = channels();
    $this->enablePilot(['sms_limite_mensal' => 1, 'sms_custo_unitario' => 0.15]);
    $semAceite = withPhone($this->makeUser('proprietario'), false);
    $os = $this->makeOs();

    $alert = WorkshopAlert::create([
        'user_id' => $semAceite->id, 'service_order_id' => $os->id, 'barrier_code' => 'x', 'nivel' => 3,
        'critico' => false, 'titulo' => 'Teste', 'mensagem' => 'm', 'responsavel' => 'Fulano',
    ]);

    expect(app(SmsManager::class)->sendAlert($alert))->toBe('bloqueado')
        ->and($sms->sent)->toBeEmpty()
        ->and(SmsLog::first()->detalhe)->toContain('aceite');

    $comAceite = withPhone($this->makeUser('proprietario'));
    $alert2 = WorkshopAlert::create([
        'user_id' => $comAceite->id, 'service_order_id' => $os->id, 'barrier_code' => 'x', 'nivel' => 3,
        'critico' => false, 'titulo' => 'Teste', 'mensagem' => 'm', 'responsavel' => 'Fulano',
    ]);

    expect(app(SmsManager::class)->sendAlert($alert2))->toBe('enviado')
        ->and((float) SmsLog::where('status', 'enviado')->first()->custo)->toBe(0.15)
        // O segundo SMS do mês bate no limite configurado (1).
        ->and(app(SmsManager::class)->sendAlert($alert2))->toBe('bloqueado')
        ->and(SmsLog::where('status', 'bloqueado')->latest('id')->first()->detalhe)->toContain('Limite mensal');
});

it('o texto do SMS tem no máximo 160 caracteres, sem acentos, e segue o modelo', function () {
    [$push, $sms] = channels();
    $this->enablePilot();
    $dono = withPhone($this->makeUser('proprietario'));
    $os = $this->makeOs();

    $alert = WorkshopAlert::create([
        'user_id' => $dono->id, 'service_order_id' => $os->id, 'barrier_code' => 'x', 'nivel' => 3, 'critico' => false,
        'titulo' => 'Orçamento em 2 horas', 'mensagem' => 'm', 'responsavel' => 'João da Conceição Aparecida de Albuquerque Maranhão Filho',
        'vencido_em' => now()->setTime(11, 0),
    ]);

    $text = app(SmsManager::class)->textFor($alert);

    expect(strlen($text))->toBeLessThanOrEqual(160)
        ->and($text)->toMatch('/^ALERTA Orcamento em 2 horas OS \d+ \S+\. Venceu 11:00\. Resp: .+\. http\S+$/')
        ->and(preg_match('/[^\x20-\x7E]/', $text))->toBe(0);
});

it('agrupa em uma só notificação os alertas do mesmo destinatário dentro de 5 minutos', function () {
    [$push, $sms] = channels();
    $this->enablePilot();
    $chefe = $this->makeUser('chefe');
    subscribe($chefe->id);
    $this->makeUser('consultor');

    $os1 = $this->makeOs();
    $os2 = $this->makeOs();
    $os3 = $this->makeOs();

    $this->at('2026-11-18 09:00:00');
    foreach ([$os1, $os2, $os3] as $os) {
        ServiceOrderEvent::record($os, 'diagnostico_fim');
    }

    app(BarrierEngine::class)->tick($this->at('2026-11-18 11:00:00'));

    // O primeiro sai na hora; os outros dois ficam aguardando a janela de 5 min.
    expect($push->sent)->toHaveCount(1)
        ->and(WorkshopAlert::where('user_id', $chefe->id)->where('push_status', 'pendente')->count())->toBe(2);

    app(AlertDispatcher::class)->flush($this->at('2026-11-18 11:03:00'));
    expect($push->sent)->toHaveCount(1);

    app(AlertDispatcher::class)->flush($this->at('2026-11-18 11:06:00'));

    expect($push->sent)->toHaveCount(2)
        ->and($push->sent[1]['payload']['title'])->toBe('2 alertas da oficina')
        ->and(WorkshopAlert::where('user_id', $chefe->id)->where('push_status', 'enviado')->count())->toBe(3);
});

it('horário de silêncio segura os alertas comuns, mas não os críticos', function () {
    [$push, $sms] = channels();
    $this->enablePilot(['silencio_ativo' => true, 'silencio_inicio' => '20:00', 'silencio_fim' => '07:00']);
    $gerente = $this->makeUser('gerente');
    subscribe($gerente->id);
    $this->makeUser('consultor');

    $dispatcher = app(AlertDispatcher::class);

    expect($dispatcher->inQuietHours($this->at('2026-11-18 21:00:00')))->toBeTrue()
        ->and($dispatcher->inQuietHours($this->at('2026-11-18 03:00:00')))->toBeTrue()
        ->and($dispatcher->inQuietHours($this->at('2026-11-18 12:00:00')))->toBeFalse();

    $os = $this->makeOs();
    $this->at('2026-11-18 20:30:00');

    $comum = WorkshopAlert::create([
        'user_id' => $gerente->id, 'service_order_id' => $os->id, 'barrier_code' => 'x', 'nivel' => 1,
        'critico' => false, 'titulo' => 'Comum', 'mensagem' => 'comum',
    ]);
    $dispatcher->dispatch($comum);

    $critico = WorkshopAlert::create([
        'user_id' => $gerente->id, 'service_order_id' => $os->id, 'barrier_code' => 'y', 'nivel' => 0,
        'critico' => true, 'titulo' => 'Crítico', 'mensagem' => 'critico',
    ]);
    $dispatcher->dispatch($critico);

    expect($comum->refresh()->push_status)->toBe('pendente')
        ->and($critico->refresh()->push_status)->toBe('enviado')
        ->and($push->sent)->toHaveCount(1)
        ->and($push->sent[0]['urgency'])->toBe('high')
        ->and($push->sent[0]['payload']['critical'])->toBeTrue();

    // De manhã o comum é liberado.
    $dispatcher->flush($this->at('2026-11-19 07:10:00'));
    expect($comum->refresh()->push_status)->toBe('enviado');
});

it('a notificação leva o link que abre a OS e o resolvido avisa quem foi alertado', function () {
    [$push, $sms] = channels();
    $this->enablePilot();
    $consultor = $this->makeUser('consultor');
    subscribe($consultor->id);
    $os = $this->makeOs(['user_id' => $consultor->id]);

    diagnosisEndedAt('2026-11-18 09:00:00', $os);
    app(BarrierEngine::class)->tick($this->at('2026-11-18 11:00:00'));

    expect($push->sent[0]['payload']['url'])->toBe("/admin/service-orders/{$os->id}");

    $this->at('2026-11-18 11:20:00');
    ServiceOrderEvent::record($os, 'orcamento_enviado', ['canal' => 'whatsapp']);

    expect($push->sent)->toHaveCount(2)
        ->and($push->sent[1]['payload']['title'])->toBe('Resolvido')
        ->and($push->sent[1]['payload']['body'])->toBe("RESOLVIDO - Orçamento em 2 horas da OS #{$os->id} às 11:20.");
});

it('as chaves VAPID e a mensagem não levam valores em reais nem dados de crédito', function () {
    [$push, $sms] = channels();
    $this->enablePilot();
    $consultor = $this->makeUser('consultor');
    subscribe($consultor->id);
    $os = $this->makeOs(['user_id' => $consultor->id]);
    $os->forceFill(['grand_total' => 54321.99, 'cor_credito' => 'amarelo'])->save();

    diagnosisEndedAt('2026-11-18 09:00:00', $os);
    app(BarrierEngine::class)->tick($this->at('2026-11-18 11:00:00'));

    $json = json_encode($push->sent, JSON_UNESCAPED_UNICODE);

    expect($json)->not->toContain('54321')->not->toContain('R$')->not->toContain('amarelo')->not->toContain('crédito');
});
