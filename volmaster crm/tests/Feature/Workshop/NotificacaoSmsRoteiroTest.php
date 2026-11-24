<?php

use Illuminate\Support\Facades\Http;
use Webkul\ServiceOrder\Models\PushSubscription;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\SmsLog;
use Webkul\ServiceOrder\Services\AlertDispatcher;
use Webkul\ServiceOrder\Services\BarrierEngine;
use Webkul\ServiceOrder\Services\Sms\SmsGateway;
use Webkul\ServiceOrder\Services\Sms\SmsManager;
use Webkul\ServiceOrder\Services\WebPush;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\Role;

/**
 * A2 (notificação no celular) e A3 (SMS de reserva) do roteiro do patrão.
 */
class NotifSmsFalso implements SmsGateway
{
    public array $enviados = [];

    public function send(string $to, string $text): array
    {
        $this->enviados[] = ['to' => $to, 'text' => $text];

        return ['ok' => true, 'id' => 'falso-'.count($this->enviados)];
    }

    public function name(): string
    {
        return 'falso';
    }
}

function notifChavesDaOficina(): void
{
    $chaves = WebPush::generateVapidKeys();

    WorkshopSettings::set('push_vapid_public', $chaves['public']);
    WorkshopSettings::set('push_vapid_private', $chaves['private']);
    WorkshopSettings::set('push_vapid_subject', 'mailto:oficina@oficina-real.com.br');
}

/** Aparelho de verdade (chaves P-256 válidas), para o envio criptografado rodar inteiro. */
function notifAparelho(int $userId, string $final = 'a'): PushSubscription
{
    $endpoint = "https://push.example.test/{$userId}/{$final}";

    return PushSubscription::create([
        'user_id' => $userId,
        'endpoint' => $endpoint,
        'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
        'p256dh' => WebPush::generateVapidKeys()['public'],
        'auth' => WebPush::b64uEncode(random_bytes(16)),
    ]);
}

function notifDonoDaOficina(): void
{
    $dono = test()->makeUser('proprietario');
    $dono->update(['role_id' => Role::create(['name' => 'Admin teste', 'permission_type' => 'all', 'permissions' => null])->id]);

    test()->actingAs($dono->refresh(), 'user');
}

function notifUsuarioComCelular(string $perfil, string $celular)
{
    $usuario = test()->makeUser($perfil);
    $usuario->forceFill(['celular' => $celular, 'sms_aceite_em' => now()])->save();

    return $usuario;
}

// ---- A2: botão "Testar notificação" ----------------------------------------------------------

it('A2: Minha Conta ganha o cartão "Testar notificação" com o piloto ligado e nada muda com ele desligado', function () {
    $usuario = $this->makeUser('tecnico', ['tecnico']);

    $this->actingAs($usuario, 'user')->get(route('admin.user.account.edit'))->assertOk()->assertDontSee('Testar notificação');

    $this->enablePilot();

    $this->actingAs($usuario, 'user')->get(route('admin.user.account.edit'))
        ->assertOk()
        ->assertSee('Notificações no celular')
        ->assertSee('Testar notificação')
        ->assertSee('Ativar neste aparelho');
});

it('A2: o teste diz o que aconteceu: sem chaves, sem aparelho, enviado de verdade (criptografado e assinado) e aparelho morto', function () {
    $usuario = $this->makeUser('tecnico', ['tecnico']);
    $this->actingAs($usuario, 'user');

    // Sem as chaves geradas.
    $this->postJson(route('admin.workshop.push.test'))->assertOk()->assertJson(['status' => 'sem_chaves']);

    notifChavesDaOficina();

    // Chaves ok, mas nenhum aparelho ativado.
    $this->postJson(route('admin.workshop.push.test'))->assertOk()->assertJson(['status' => 'sem_aparelho']);

    // Aparelho ativado: o envio real vai para o serviço de push, com cabeçalho VAPID e o texto cifrado.
    // 1º envio aceito (201); 2º, com o app desinstalado (410).
    Http::fake(['push.example.test/*' => Http::sequence()->push('', 201)->push('', 410)]);
    $aparelho = notifAparelho($usuario->id);

    $this->postJson(route('admin.workshop.push.test'))->assertOk()->assertJson(['status' => 'enviado', 'enviados' => 1]);

    Http::assertSent(fn ($requisicao) => str_starts_with($requisicao->url(), 'https://push.example.test/')
        && str_starts_with($requisicao->header('Authorization')[0], 'vapid t=')
        && $requisicao->header('Content-Encoding')[0] === 'aes128gcm'
        && $requisicao->header('Urgency')[0] === 'high'
        && strlen($requisicao->body()) > 100);

    // O app foi desinstalado (410): o teste falha com explicação e o aparelho sai da lista.
    $this->postJson(route('admin.workshop.push.test'))->assertOk()->assertJson(['status' => 'falhou']);

    expect(PushSubscription::where('user_id', $usuario->id)->exists())->toBeFalse();
});

it('A2: o teste só alcança os aparelhos de quem está logado', function () {
    notifChavesDaOficina();
    Http::fake(['push.example.test/*' => Http::response('', 201)]);

    $eu = $this->makeUser('tecnico', ['tecnico']);
    $outro = $this->makeUser('consultor', ['service_orders']);
    notifAparelho($outro->id);

    $this->actingAs($eu, 'user')->postJson(route('admin.workshop.push.test'))->assertJson(['status' => 'sem_aparelho']);

    Http::assertNothingSent();
});

// ---- A2: e-mail de contato (VAPID) -----------------------------------------------------------

it('A2: o e-mail de contato vira mailto:, texto solto e e-mail de exemplo são recusados', function () {
    expect(WebPush::normalizarAssunto('oficina@gmail.com'))->toBe('mailto:oficina@gmail.com')
        ->and(WebPush::normalizarAssunto('mailto:oficina@gmail.com'))->toBe('mailto:oficina@gmail.com')
        ->and(WebPush::normalizarAssunto('MAILTO:Oficina@Gmail.com'))->toBe('mailto:Oficina@Gmail.com')
        ->and(WebPush::normalizarAssunto('https://oficina.com.br/contato'))->toBe('https://oficina.com.br/contato')
        ->and(WebPush::normalizarAssunto('  '))->toBeNull()
        ->and(WebPush::normalizarAssunto('falar com o João'))->toBeNull()
        ->and(WebPush::normalizarAssunto('suporte@example.com'))->toBeNull();

    notifDonoDaOficina();

    $dados = [
        'silencio_inicio' => '21:00', 'silencio_fim' => '06:00', 'agrupar_alertas_minutos' => 5,
        'sms_provedor' => 'log', 'sms_limite_mensal' => 100, 'sms_custo_unitario' => 0.1, 'sms_nao_aberto_minutos' => 10,
        'tecnico_margem_percentual' => 120, 'tecnico_meta_eficiencia' => 85,
    ];

    $this->put(route('admin.workshop.settings.general'), $dados + ['push_vapid_subject' => 'suporte@example.com'])
        ->assertSessionHasErrors('push_vapid_subject');

    $this->put(route('admin.workshop.settings.general'), $dados + ['push_vapid_subject' => 'oficina@gmail.com'])->assertSessionHasNoErrors();
    WorkshopSettings::flush();

    expect(WorkshopSettings::get('push_vapid_subject'))->toBe('mailto:oficina@gmail.com');
});

it('A2: o token VAPID usa o contato normalizado mesmo quando o valor antigo foi gravado sem mailto:', function () {
    notifChavesDaOficina();
    WorkshopSettings::set('push_vapid_subject', 'oficina@gmail.com'); // gravado antes da correção

    $cabecalho = app(WebPush::class)->vapidAuthorization('https://fcm.googleapis.com/fcm/send/abc');

    expect(preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=/', $cabecalho, $partes))->toBe(1);

    $claims = json_decode(WebPush::b64uDecode($partes[2]), true);

    expect($claims['sub'])->toBe('mailto:oficina@gmail.com')
        ->and($claims['aud'])->toBe('https://fcm.googleapis.com');
});

// ---- A3: SMS ---------------------------------------------------------------------------------

it('A3: com "Simulado" nada sai pela rede e o relatório mostra o que TERIA sido enviado', function () {
    Http::fake();
    $this->enablePilot();
    WorkshopSettings::set('sms_provedor', 'log');
    $consultor = $this->makeUser('consultor');
    $dono = notifUsuarioComCelular('proprietario', '(11) 98888-7777');
    $os = $this->makeOs(['user_id' => $consultor->id]);

    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');
    app(BarrierEngine::class)->tick($this->at('2026-11-18 13:00:00'));

    $registro = SmsLog::where('user_id', $dono->id)->first();

    expect($registro->status)->toBe('simulado')
        ->and($registro->telefone)->toBe('(11) 98888-7777');

    Http::assertNothingSent();

    notifDonoDaOficina();

    $this->get(route('admin.workshop.settings.sms_report'))
        ->assertOk()
        ->assertSee('Modo simulado')
        ->assertSee('teria')
        ->assertSee('Simulado')
        ->assertSee('(11) 98888-7777');
});

it('A3: com o provedor real (Twilio) o SMS sai pela API com as credenciais da oficina e o custo fica registrado', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);
    $this->enablePilot([
        'sms_provedor' => 'twilio', 'sms_twilio_sid' => 'ACteste', 'sms_twilio_token' => 'segredo', 'sms_twilio_from' => '+15550001111',
        'sms_limite_mensal' => 50, 'sms_custo_unitario' => 0.12,
    ]);
    $consultor = $this->makeUser('consultor');
    $dono = notifUsuarioComCelular('proprietario', '(11) 98888-7777');
    $os = $this->makeOs(['user_id' => $consultor->id]);

    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');
    app(BarrierEngine::class)->tick($this->at('2026-11-18 13:00:00'));

    Http::assertSent(fn ($requisicao) => str_contains($requisicao->url(), '/Accounts/ACteste/Messages.json')
        && $requisicao->header('Authorization')[0] === 'Basic '.base64_encode('ACteste:segredo')
        && $requisicao['To'] === '+5511988887777'
        && $requisicao['From'] === '+15550001111');

    $registro = SmsLog::where('user_id', $dono->id)->first();

    expect($registro->status)->toBe('enviado')
        ->and((float) $registro->custo)->toBe(0.12)
        ->and($registro->provedor)->not->toBe('-');
});

it('A3: falha do provedor vira "falhou" com o motivo, e o limite mensal bloqueia com o rótulo certo no relatório', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['message' => 'credenciais inválidas'], 401)]);
    $this->enablePilot([
        'sms_provedor' => 'twilio', 'sms_twilio_sid' => 'ACteste', 'sms_twilio_token' => 'errado', 'sms_twilio_from' => '+15550001111',
        'sms_limite_mensal' => 50,
    ]);
    $consultor = $this->makeUser('consultor');
    $dono = notifUsuarioComCelular('proprietario', '(11) 98888-7777');
    $os = $this->makeOs(['user_id' => $consultor->id]);

    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');
    app(BarrierEngine::class)->tick($this->at('2026-11-18 13:00:00'));

    expect(SmsLog::where('user_id', $dono->id)->first()->status)->toBe('falhou');

    // Limite zerado: o próximo SMS não sai ("não enviado: limite").
    WorkshopSettings::set('sms_limite_mensal', 0);
    $segunda = $this->makeOs(['user_id' => $consultor->id]);
    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($segunda, 'diagnostico_fim');
    app(BarrierEngine::class)->tick($this->at('2026-11-18 13:01:00'));

    $bloqueado = SmsLog::where('status', 'bloqueado')->first();

    expect($bloqueado)->not->toBeNull()->and($bloqueado->detalhe)->toContain('Limite mensal');

    notifDonoDaOficina();

    $this->get(route('admin.workshop.settings.sms_report'))->assertOk()->assertSee('Não enviado: limite mensal atingido');
});

it('A3: celular de DDD 55 (RS) não é confundido com o código do país', function () {
    $falso = new NotifSmsFalso;
    SmsManager::fake($falso);

    $this->enablePilot();
    $consultor = $this->makeUser('consultor');

    foreach (['(55) 99999-8888' => '+5555999998888', '+55 (11) 98888-7777' => '+5511988887777', '11988887777' => '+5511988887777'] as $digitado => $esperado) {
        $dono = notifUsuarioComCelular('proprietario', $digitado);
        $os = $this->makeOs(['user_id' => $consultor->id]);

        $this->at('2026-11-18 09:00:00');
        ServiceOrderEvent::record($os, 'diagnostico_fim');
        app(BarrierEngine::class)->tick($this->at('2026-11-18 13:00:00'));

        $enviado = collect($falso->enviados)->last();

        expect($enviado['to'])->toBe($esperado);
        $dono->update(['status' => 0]);
    }
});

it('A3: no horário de silêncio o SMS comum espera; o crítico toca na hora e o comum sai quando o silêncio acaba', function () {
    $falso = new NotifSmsFalso;
    SmsManager::fake($falso);

    $this->enablePilot(['silencio_ativo' => true, 'silencio_inicio' => '12:30', 'silencio_fim' => '14:00']);
    $consultor = $this->makeUser('consultor');
    $dono = notifUsuarioComCelular('proprietario', '(11) 98888-7777');
    $os = $this->makeOs(['user_id' => $consultor->id]);

    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');

    // 13h: nível 3 do dono, mas em silêncio: espera.
    app(BarrierEngine::class)->tick($this->at('2026-11-18 13:00:00'));
    expect($falso->enviados)->toBeEmpty();

    app(AlertDispatcher::class)->flush($this->at('2026-11-18 13:59:00'));
    expect($falso->enviados)->toBeEmpty();

    // 14h: o silêncio acabou e o SMS sai uma única vez.
    app(AlertDispatcher::class)->flush($this->at('2026-11-18 14:00:00'));
    expect($falso->enviados)->toHaveCount(1)->and($dono->fresh())->not->toBeNull();

    app(AlertDispatcher::class)->flush($this->at('2026-11-18 14:05:00'));
    expect($falso->enviados)->toHaveCount(1);
});
