<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\ServiceOrder\Models\BarrierRule;
use Webkul\ServiceOrder\Models\PushSubscription;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderBoleto;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\BarrierEngine;
use Webkul\ServiceOrder\Services\WebPush;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\Role;

/** Funções da oficina com as permissões que cada uma costuma ter. */
function staff(string $perfil, array $extra = [])
{
    $base = ['service_orders', 'service_orders.view', 'service_orders.edit', 'ver_valores_financeiros'];

    return test()->makeUser($perfil, array_merge($base, $extra));
}

it('critério 4: na tela da OS o botão Avançar fica travado e lista o que falta', function () {
    $this->enablePilot();
    $gerente = staff('gerente');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO, 'user_id' => $gerente->id]);

    $html = $this->actingAs($gerente, 'user')->get(route('admin.service_orders.view', $os->id))->assertOk()->getContent();

    expect($html)->toContain('faltando-para-avancar')
        ->toContain('Laudo do diagnóstico')
        ->toContain('Visto do chefe de oficina no diagnóstico')
        ->toContain('Relação de peças entregue')
        ->toContain('disabled');

    // Tentar avançar na marra pelo formulário também é recusado no servidor.
    $this->put(route('admin.service_orders.stage.update', $os->id), ['stage' => ServiceOrder::STAGE_ORCAMENTO])
        ->assertRedirect(route('admin.service_orders.view', $os->id));

    expect($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_DIAGNOSTICO)
        ->and(session('error'))->toContain('Falta:');
});

it('só quem tem a permissão força o avanço, e a justificativa gera o evento e o alerta crítico', function () {
    $this->enablePilot();
    $semPermissao = staff('consultor');
    $comPermissao = staff('gerente', ['service_orders.forcar_avanco']);
    $chefeAlertado = $this->makeUser('gerente');

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORDEM_SERVICO, 'user_id' => $comPermissao->id]);

    $this->actingAs($semPermissao, 'user')
        ->put(route('admin.service_orders.stage.update', $os->id), ['stage' => ServiceOrder::STAGE_DIAGNOSTICO, 'forcar' => 1, 'justificativa' => 'Pressa'])
        ->assertForbidden();

    $this->actingAs($comPermissao, 'user')
        ->put(route('admin.service_orders.stage.update', $os->id), ['stage' => ServiceOrder::STAGE_DIAGNOSTICO, 'forcar' => 1, 'justificativa' => ''])
        ->assertRedirect();

    expect($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_ORDEM_SERVICO);

    $this->put(route('admin.service_orders.stage.update', $os->id), ['stage' => ServiceOrder::STAGE_DIAGNOSTICO, 'forcar' => 1, 'justificativa' => 'Técnico é definido no box'])
        ->assertRedirect();

    expect($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_DIAGNOSTICO)
        ->and(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'bloqueio_forcado')->count())->toBe(1)
        ->and(WorkshopAlert::where('user_id', $chefeAlertado->id)->where('critico', true)->count())->toBe(1);
});

it('termo de entrada: salva km, combustível, autorização e fotos, e a assinatura vira evento', function () {
    $this->enablePilot();
    Storage::fake('public');
    $consultor = staff('consultor');
    $os = $this->makeOs(['user_id' => $consultor->id]);

    $this->actingAs($consultor, 'user')
        ->post(route('admin.service_orders.workflow.termo', $os->id), [
            'km_entrada' => 351200,
            'combustivel_entrada' => '1/2',
            'itens_entrada' => 'Estepe e macaco',
            'autorizacao_rodagem' => 1,
            'assinado' => 1,
            'fotos' => [UploadedFile::fake()->image('frente.jpg'), UploadedFile::fake()->image('lateral.jpg')],
        ])
        ->assertRedirect(route('admin.service_orders.view', $os->id));

    $os->refresh();

    expect($os->km_entrada)->toBe(351200)
        ->and($os->autorizacao_rodagem)->toBeTrue()
        ->and($os->termo_assinado_em)->not->toBeNull()
        ->and($os->attachments()->where('tipo', 'termo_foto')->count())->toBe(2)
        ->and(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'termo_assinado')->count())->toBe(1);

    // B1: o consultor pede a análise; quem registra a cor é o Financeiro.
    $this->actingAs(staff('financeiro', ['credito_cliente', 'credito_cliente.registrar']), 'user')
        ->post(route('admin.service_orders.workflow.cor_credito', $os->id), ['cor_credito' => 'verde'])
        ->assertRedirect();

    expect($os->refresh()->cor_credito)->toBe('verde');

    // Salvar de novo não repete o evento de assinatura.
    $this->post(route('admin.service_orders.workflow.termo', $os->id), ['km_entrada' => 351200, 'combustivel_entrada' => '1/2', 'assinado' => 1]);
    expect(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'termo_assinado')->count())->toBe(1);
});

it('diagnóstico completo pela tela da OS: designar, iniciar, evidência, finalizar, visto, peças e avançar', function () {
    $this->enablePilot();
    $this->makeUser('consultor');
    $chefe = staff('chefe', ['service_orders.auditar']);
    $mechanic = $this->makeMechanic('Zé');
    $tecnicoUser = $this->makeUser('tecnico', ['service_orders', 'service_orders.view', 'service_orders.edit'], ['mechanic_id' => $mechanic->id, 'view_permission' => 'individual']);

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORDEM_SERVICO, 'user_id' => $chefe->id]);

    $this->actingAs($chefe, 'user');
    $this->post(route('admin.service_orders.workflow.designate', $os->id), ['mechanic_id' => $mechanic->id])->assertRedirect();

    // Já pode avançar para o diagnóstico.
    $this->put(route('admin.service_orders.stage.update', $os->id), ['stage' => ServiceOrder::STAGE_DIAGNOSTICO])->assertRedirect();
    expect($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_DIAGNOSTICO);

    $this->at('2026-11-18 08:00:00');
    $this->post(route('admin.service_orders.workflow.diagnostico.start', $os->id), [])->assertRedirect();

    // Finalizar sem laudo nem evidência é recusado.
    $this->post(route('admin.service_orders.workflow.diagnostico.finish', $os->id), [])->assertRedirect();
    expect(session('error'))->toContain('laudo');
    expect($os->refresh()->diagnostico_concluido_em)->toBeNull();

    $this->post(route('admin.service_orders.workflow.laudo', $os->id), ['diagnostico' => 'Bico injetor 3 travado'])->assertRedirect();
    $this->post(route('admin.service_orders.workflow.evidence', $os->id), ['tipo' => 'codigo', 'descricao' => 'P0203', 'valor' => 'Injetor cil. 3'])->assertRedirect();

    $this->at('2026-11-18 09:00:00');
    $this->post(route('admin.service_orders.workflow.diagnostico.finish', $os->id), [])->assertRedirect();
    expect($os->refresh()->diagnostico_concluido_em)->not->toBeNull();

    // O próprio técnico não dá o visto no laudo dele; o chefe sim.
    $this->actingAs($chefe, 'user')->post(route('admin.service_orders.workflow.diagnostico.audit', $os->id), ['status' => 'devolvido', 'observacao' => ''])->assertRedirect();
    expect($os->refresh()->diagnostico_visto_status)->toBeNull();

    $this->post(route('admin.service_orders.workflow.diagnostico.audit', $os->id), ['status' => 'aprovado'])->assertRedirect();
    $this->post(route('admin.service_orders.workflow.parts', $os->id), ['sem_pecas' => 1])->assertRedirect();

    $this->put(route('admin.service_orders.stage.update', $os->id), ['stage' => ServiceOrder::STAGE_ORCAMENTO])->assertRedirect();

    expect($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_ORCAMENTO);

    $tipos = ServiceOrderEvent::where('service_order_id', $os->id)->pluck('tipo_evento')->all();

    foreach (['tecnico_designado', 'diagnostico_inicio', 'diagnostico_fim', 'laudo_evidencia', 'diagnostico_auditado', 'relacao_pecas_entregue'] as $esperado) {
        expect($tipos)->toContain($esperado);
    }

    // Todo evento tem data, hora e usuário.
    expect(ServiceOrderEvent::where('service_order_id', $os->id)->whereNull('usuario_id')->where('tipo_evento', 'mudanca_etapa')->where('etapa_destino', ServiceOrder::STAGE_ORCAMENTO)->exists())->toBeFalse();
});

it('o técnico não dá visto no próprio diagnóstico', function () {
    $this->enablePilot();
    $mechanic = $this->makeMechanic('Zé');
    $user = $this->makeUser('chefe', ['service_orders', 'service_orders.view', 'service_orders.edit', 'service_orders.auditar'], ['mechanic_id' => $mechanic->id]);
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO, 'diagnostico_mechanic_id' => $mechanic->id, 'user_id' => $user->id]);
    $os->forceFill(['diagnostico_concluido_em' => now(), 'diagnostico' => 'x'])->save();

    $this->actingAs($user, 'user')->post(route('admin.service_orders.workflow.diagnostico.audit', $os->id), ['status' => 'aprovado'])->assertRedirect();

    expect(session('error'))->toContain('próprio laudo')->and($os->refresh()->diagnostico_visto_status)->toBeNull();
});

it('resposta do cliente exige meio e motivo da recusa e registra o evento', function () {
    $this->enablePilot();
    $consultor = staff('consultor');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO, 'user_id' => $consultor->id]);

    $this->actingAs($consultor, 'user');

    $this->post(route('admin.service_orders.workflow.approval', $os->id), ['tipo' => 'recusa', 'meio' => 'WhatsApp'])->assertRedirect();
    expect(session('error'))->toContain('motivo');

    $this->post(route('admin.service_orders.workflow.approval', $os->id), ['tipo' => 'total', 'meio' => 'WhatsApp'])->assertRedirect();

    $os->refresh();
    expect($os->aprovacao_tipo)->toBe('total')
        ->and($os->aprovacao_em)->not->toBeNull()
        ->and(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'cliente_aprovou')->first()->dados_json['meio'])->toBe('WhatsApp');
});

it('negociação registra a forma de pagamento no log quando ela é definida', function () {
    $this->enablePilot();
    $consultor = staff('consultor');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO, 'user_id' => $consultor->id]);

    $this->actingAs($consultor, 'user')
        ->put(route('admin.service_orders.negociacao.update', $os->id), ['condicao_pagamento' => 'PIX à vista'])
        ->assertRedirect();

    expect(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'forma_pagamento_registrada')->first()->dados_json['forma'])->toBe('PIX à vista');
});

it('pagamento do boleto marcado como Pago entra no log e libera a saída', function () {
    $this->enablePilot();
    $financeiro = staff('financeiro');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_FATURAMENTO, 'user_id' => $financeiro->id]);
    $os->forceFill(['nf_manual_em' => now(), 'nf_manual_numero' => '99', 'checklist_saida_em' => now()])->save();

    $boleto = ServiceOrderBoleto::create(['quote_id' => $os->id, 'valor' => 100, 'status' => 'Pendente']);

    $this->actingAs($financeiro, 'user')
        ->put(route('admin.service_orders.boletos.update', [$os->id, $boleto->id]), ['status' => 'Pago'])
        ->assertRedirect();

    expect(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'pagamento_confirmado')->exists())->toBeTrue();

    $this->put(route('admin.service_orders.stage.update', $os->id), ['stage' => ServiceOrder::STAGE_POS_VENDA])->assertRedirect();

    expect($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_POS_VENDA);
});

it('sino: o resumo traz só os alertas do usuário logado, e "ciente" fecha o crítico', function () {
    $this->enablePilot();
    $gerente = staff('gerente');
    $outro = staff('gerente');
    $os = $this->makeOs();

    $comum = WorkshopAlert::create(['user_id' => $gerente->id, 'service_order_id' => $os->id, 'barrier_code' => 'x', 'nivel' => 1, 'critico' => false, 'titulo' => 'Comum', 'mensagem' => 'ALERTA - Comum']);
    $critico = WorkshopAlert::create(['user_id' => $gerente->id, 'service_order_id' => $os->id, 'barrier_code' => 'y', 'nivel' => 0, 'critico' => true, 'titulo' => 'Crítico', 'mensagem' => 'ALERTA - Crítico']);
    WorkshopAlert::create(['user_id' => $outro->id, 'barrier_code' => 'z', 'nivel' => 1, 'critico' => false, 'titulo' => 'Do outro', 'mensagem' => 'm']);

    $resumo = $this->actingAs($gerente, 'user')->getJson(route('admin.workshop.alerts.summary'))->assertOk();

    expect($resumo->json('contador'))->toBe(2)
        ->and(collect($resumo->json('itens'))->pluck('titulo')->sort()->values()->all())->toBe(['Comum', 'Crítico']);

    $this->post(route('admin.workshop.alerts.ack', $critico->id))->assertRedirect();
    expect($critico->refresh()->status)->toBe('resolvido')->and($comum->refresh()->status)->toBe('aberto');

    // Não dá para dar ciente no alerta de outra pessoa.
    $alheio = WorkshopAlert::where('user_id', $outro->id)->first();
    $this->post(route('admin.workshop.alerts.ack', $alheio->id))->assertNotFound();

    $this->get(route('admin.workshop.alerts.index'))->assertOk()->assertSee('Comum');
});

it('com o piloto desligado o sino não aparece e o resumo vem vazio', function () {
    $gerente = staff('gerente');

    $this->actingAs($gerente, 'user')->getJson(route('admin.workshop.alerts.summary'))->assertOk()->assertJson(['ativo' => false, 'contador' => 0]);

    $html = $this->get(route('admin.service_orders.index'))->assertOk()->getContent();
    expect($html)->not->toContain('vm-bell-btn')->not->toContain('workshop.webmanifest');
});

it('com o piloto ligado o sino e o manifesto do app entram nas páginas do sistema', function () {
    $this->enablePilot();
    $gerente = staff('gerente');

    $html = $this->actingAs($gerente, 'user')->get(route('admin.service_orders.index'))->assertOk()->getContent();

    expect($html)->toContain('vm-bell-btn')->toContain('/workshop.webmanifest')->toContain('/workshop-sw.js');
});

it('critério 8 (servidor): inscrição de push, confirmação de entregue/aberto, manifesto, ícones e service worker', function () {
    $this->enablePilot();
    $gerente = staff('gerente');
    $os = $this->makeOs();

    $this->actingAs($gerente, 'user')
        ->postJson(route('admin.workshop.push.subscribe'), ['endpoint' => 'https://push.example.test/abc', 'keys' => ['p256dh' => 'chave', 'auth' => 'auth']])
        ->assertOk();

    // Inscrever o mesmo aparelho de novo não duplica.
    $this->postJson(route('admin.workshop.push.subscribe'), ['endpoint' => 'https://push.example.test/abc', 'keys' => ['p256dh' => 'chave2', 'auth' => 'auth']])->assertOk();
    expect(PushSubscription::where('user_id', $gerente->id)->count())->toBe(1);

    $alert = WorkshopAlert::create(['user_id' => $gerente->id, 'service_order_id' => $os->id, 'barrier_code' => 'x', 'nivel' => 2, 'critico' => false, 'titulo' => 'T', 'mensagem' => 'm']);

    // O service worker não tem sessão: confirma pelo token do alerta.
    auth()->guard('user')->logout();
    $this->postJson(route('workshop.push.ack', [$alert->token, 'entregue']))->assertOk();
    expect($alert->refresh()->push_status)->toBe('entregue');

    $this->postJson(route('workshop.push.ack', [$alert->token, 'aberto']))->assertOk();
    expect($alert->refresh()->push_status)->toBe('aberto')->and($alert->push_aberto_em)->not->toBeNull();

    $this->postJson(route('workshop.push.ack', ['token-que-nao-existe', 'aberto']))->assertNotFound();
    $this->postJson(route('workshop.push.ack', [$alert->token, 'qualquer']))->assertNotFound();

    $this->get('/workshop.webmanifest')->assertOk()->assertJsonPath('display', 'standalone')->assertJsonPath('icons.0.sizes', '192x192');

    $icone = $this->get('/workshop-icone-512.png')->assertOk();
    expect($icone->headers->get('Content-Type'))->toBe('image/png');
    $this->get('/workshop-icone-100.png')->assertNotFound();

    $sw = $this->get('/workshop-sw.js')->assertOk();
    expect($sw->headers->get('Content-Type'))->toContain('javascript')
        ->and($sw->headers->get('Service-Worker-Allowed'))->toBe('/')
        ->and($sw->getContent())->toContain("addEventListener('push'")->toContain('notificationclick')->toContain('requireInteraction');

    // Link curto do SMS leva à OS.
    $this->get("/os/{$os->id}")->assertRedirect(route('admin.service_orders.view', $os->id));
});

it('a tela Minhas tarefas abre e a configuração do piloto grava a chave e os horários', function () {
    $dono = $this->makeUser('proprietario');
    $dono->update(['role_id' => Role::create(['name' => 'Admin teste', 'permission_type' => 'all', 'permissions' => null])->id]);

    $this->actingAs($dono->refresh(), 'user');

    $this->get(route('admin.tecnico.index'))->assertOk()->assertSee('Minhas tarefas');
    $this->get(route('admin.workshop.settings.edit'))->assertOk()->assertSee('Chave do piloto');

    // Para LIGAR o piloto a Ficha mínima e um gerente precisam existir (A5 do roteiro de 18/11).
    $this->completeFichaForPilot();

    $this->put(route('admin.workshop.settings.general'), [
        'piloto_ativo' => 1,
        'horario' => [
            '1' => ['aberto' => 1, 'inicio' => '07:30', 'fim' => '17:30'],
            '6' => ['inicio' => '08:00', 'fim' => '12:00'],
        ],
        'silencio_ativo' => 1, 'silencio_inicio' => '21:00', 'silencio_fim' => '06:00',
        'agrupar_alertas_minutos' => 7, 'sms_provedor' => 'twilio', 'sms_limite_mensal' => 50, 'sms_custo_unitario' => 0.2, 'sms_nao_aberto_minutos' => 15,
        'tecnico_margem_percentual' => 130, 'tecnico_meta_eficiencia' => 85,
        'sms_twilio_token' => 'segredo-twilio',
    ])->assertRedirect();

    WorkshopSettings::flush();

    expect(WorkshopSettings::get('piloto_ativo'))->toBeTrue()
        ->and(WorkshopSettings::get('horario_comercial'))->toBe(['1' => ['07:30', '17:30']])
        ->and(WorkshopSettings::get('agrupar_alertas_minutos'))->toBe(7)
        ->and(WorkshopSettings::get('tecnico_margem_percentual'))->toBe(130)
        ->and(WorkshopSettings::get('sms_twilio_token'))->toBe('segredo-twilio');

    // O token fica criptografado no banco.
    expect(DB::table('workshop_settings')->where('chave', 'sms_twilio_token')->value('valor'))->not->toContain('segredo-twilio');

    $this->post(route('admin.workshop.settings.vapid'))->assertRedirect();
    WorkshopSettings::flush();
    expect(strlen(WebPush::b64uDecode(WorkshopSettings::get('push_vapid_public'))))->toBe(65);

    $this->get(route('admin.workshop.settings.sms_report'))->assertOk();
});

it('a configuração só é acessível a quem tem a permissão', function () {
    $this->enablePilot();
    $consultor = staff('consultor');

    $this->actingAs($consultor, 'user')->get(route('admin.workshop.settings.edit'))->assertForbidden();
    $this->put(route('admin.workshop.settings.general'), ['piloto_ativo' => 0])->assertForbidden();
});

it('alterar a barreira e o papel pela tela muda quem é alertado, sem mexer em código', function () {
    $this->enablePilot();
    $admin = $this->makeUser('proprietario');
    $admin->update(['role_id' => Role::create(['name' => 'Admin teste', 'permission_type' => 'all', 'permissions' => null])->id]);

    $consultor = $this->makeUser('consultor');
    $coordenador = $this->makeUser('chefe');
    $os = $this->makeOs(['user_id' => $consultor->id]);

    $regra = BarrierRule::where('codigo', 'orcamento_2h')->first();

    $this->actingAs($admin->refresh(), 'user')
        ->put(route('admin.workshop.settings.rule', $regra->id), [
            'prazo_minutos' => 30,
            'nivel2_apos_minutos' => 15, 'nivel3_apos_minutos' => 30,
            'destinos1' => ['gerente'], 'destinos2' => ['chefe'], 'destinos3' => ['proprietario'],
            'ativo' => 1,
        ])
        ->assertRedirect();

    $gerente = $this->makeUser('gerente');

    $this->at('2026-11-18 09:00:00');
    ServiceOrderEvent::record($os, 'diagnostico_fim');

    app(BarrierEngine::class)->tick($this->at('2026-11-18 09:30:00'));

    // Nível 1 agora vai para o gerente (e não mais para consultor e chefe).
    expect(WorkshopAlert::where('nivel', 1)->pluck('user_id')->all())->toBe([$gerente->id]);

    app(BarrierEngine::class)->tick($this->at('2026-11-18 09:45:00'));
    expect(WorkshopAlert::where('nivel', 2)->pluck('user_id')->all())->toBe([$coordenador->id]);
});

it('com o piloto desligado a OS abre normalmente, sem bloco novo nem restrição', function () {
    $gerente = staff('gerente');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO, 'user_id' => $gerente->id]);

    $html = $this->actingAs($gerente, 'user')->get(route('admin.service_orders.view', $os->id))->assertOk()->getContent();

    expect($html)->not->toContain('id="fluxo-os"')->not->toContain('faltando-para-avancar');

    // O botão Avançar do fluxo antigo continua funcionando.
    $this->put(route('admin.service_orders.stage.update', $os->id), ['stage' => ServiceOrder::STAGE_ORCAMENTO])->assertRedirect();
    expect($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_ORCAMENTO);

    // E o primeiro "salvar diagnóstico" antigo ainda grava o fim do diagnóstico no log.
    $os2 = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO, 'user_id' => $gerente->id]);
    $this->put(route('admin.service_orders.diagnostico.update', $os2->id), ['diagnostico' => 'Tudo certo'])->assertRedirect();

    expect(ServiceOrderEvent::where('service_order_id', $os2->id)->where('tipo_evento', 'diagnostico_fim')->count())->toBe(1);
});
