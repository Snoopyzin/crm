<?php

use Illuminate\Testing\TestResponse;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderAttachment;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderTermo;
use Webkul\ServiceOrder\Services\StageAdvanceService;
use Webkul\ServiceOrder\Services\TermoEntrada;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\ServiceOrder\Support\Documento;

/**
 * B2 do roteiro do patrão: dados obrigatórios para sair do Cadastro e aceite do termo de entrada
 * (assinatura na tela ou link do WhatsApp, com data, hora e IP).
 */
function termoFaltando(ServiceOrder $os): array
{
    return app(StageAdvanceService::class)->advance($os->refresh(), ServiceOrder::STAGE_ORDEM_SERVICO)['faltando'];
}

function termoFotos(ServiceOrder $os, int $quantidade): void
{
    for ($i = 1; $i <= $quantidade; $i++) {
        ServiceOrderAttachment::create(['quote_id' => $os->id, 'tipo' => 'termo_foto', 'path' => "x/foto-{$i}.jpg"]);
    }
}

function termoAceite(string $token, array $dados = []): TestResponse
{
    return test()->post(route('service_orders.termo.aceitar', $token), array_merge([
        'nome' => 'João da Silva', 'autoriza_rodagem' => '1', 'li_e_aceito' => '1',
        'assinatura' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    ], $dados));
}

it('B2: CPF e CNPJ são validados pelos dígitos', function () {
    foreach (['529.982.247-25', '52998224725', '11.222.333/0001-81', '11444777000161'] as $valido) {
        expect(Documento::valido($valido))->toBeTrue();
    }

    foreach (['111.111.111-11', '123.456.789-00', '12.345.678/0001-90', '00.000.000/0000-00', '12345', '', null] as $invalido) {
        expect(Documento::valido($invalido))->toBeFalse();
    }
});

it('B2 (teste do PDF): sem fotos, sem aceite ou com dado faltando, o botão de avançar mostra o que falta', function () {
    $this->enablePilot();
    $pessoa = $this->makePerson(['cpf_cnpj' => '12.345.678/0001-90']);
    $os = $this->makeOs(['person_id' => $pessoa->id, 'vehicle_id' => $this->makeVehicle($pessoa->id)->id]);

    $faltando = termoFaltando($os);

    expect($faltando)->toContain('CPF/CNPJ do cliente inválido (confira os dígitos)')
        ->toContain('Km de entrada')
        ->toContain('Nível de combustível')
        ->toContain('Termo de entrada: fotos do veículo (mínimo 4, há 0)')
        ->toContain('Termo de entrada assinado');

    // 3 fotos ainda não bastam; com 4 o item some. O mínimo é da oficina.
    termoFotos($os, 3);
    expect(termoFaltando($os))->toContain('Termo de entrada: fotos do veículo (mínimo 4, há 3)');

    termoFotos($os, 1);
    expect(termoFaltando($os))->not->toContain('Termo de entrada: fotos do veículo (mínimo 4, há 3)')
        ->not->toContain('Termo de entrada: fotos do veículo (mínimo 4, há 4)');

    WorkshopSettings::set('termo_fotos_minimas', 6);
    expect(termoFaltando($os))->toContain('Termo de entrada: fotos do veículo (mínimo 6, há 4)');
});

it('B2: serviço só de software dispensa fotos, km, combustível e termo; a autorização "não" não trava a saída do Cadastro', function () {
    $this->enablePilot();

    $software = $this->makeOs(['vehicle_id' => null]);
    expect(termoFaltando($software))->toBe(['Cor de crédito (verde, amarelo ou vermelho)']);

    // Com o veículo: cliente que NÃO autoriza a rodagem assinou o termo e a OS sai do Cadastro.
    $os = $this->makeOs();
    $os->forceFill(['km_entrada' => 1000, 'combustivel_entrada' => 'Cheio', 'itens_entrada' => 'sem itens nem avarias', 'termo_assinado_em' => now(), 'autorizacao_rodagem' => false, 'cor_credito' => 'verde'])->save();
    termoFotos($os, 4);

    // Nada falta: a chamada já avança a OS para a próxima etapa.
    expect(termoFaltando($os))->toBe([])
        // O que a negativa trava é o teste de rodagem (Veículo em Teste), não o Cadastro.
        ->and($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_ORDEM_SERVICO);
});

it('B2: aceite pelo link do WhatsApp registra data, hora, IP e aparelho, e a OS passa a ter o termo assinado', function () {
    $this->enablePilot();
    $os = $this->makeOs();
    $dono = $this->makeUser('proprietario');

    $this->actingAs($dono, 'user')
        ->post(route('admin.service_orders.workflow.termo_link', $os->id))
        ->assertRedirect(route('admin.service_orders.view', $os->id))
        ->assertSessionHas('termo_link');

    $link = session('termo_link');

    expect($link['url'])->toContain('/termo/')
        ->and($link['whatsapp'])->toStartWith('https://wa.me/5511987654321?text=')
        // Só o hash fica no banco; o token existe só no link.
        ->and(ServiceOrderTermo::count())->toBe(1);

    $token = basename(parse_url($link['url'], PHP_URL_PATH));

    expect(ServiceOrderTermo::first()->token_hash)->not->toContain($token)->and(ServiceOrderTermo::first()->token_hash)->toBe(hash('sha256', $token));

    // O cliente (sem login) abre o link: vê os dados do veículo e o texto do termo, nunca CPF nem valores.
    auth()->guard('user')->logout();
    $os->forceFill(['km_entrada' => 351200, 'combustivel_entrada' => '1/2', 'itens_entrada' => 'Estepe e macaco'])->save();

    $this->get($link['url'])
        ->assertOk()
        ->assertSee('Termo de entrada do veículo')
        ->assertSee($os->vehicle->placa)
        ->assertSee('351.200')
        ->assertSee('Estepe e macaco')
        ->assertDontSee('11.222.333')
        ->assertDontSee('R$');

    expect($os->refresh()->termo_assinado_em)->toBeNull();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeader('User-Agent', 'iPhone Safari de teste')
        ->from($link['url']);

    termoAceite($token)->assertRedirect(route('service_orders.termo.show', $token));

    $termo = ServiceOrderTermo::first();
    $os->refresh();

    expect($termo->aceito_em)->not->toBeNull()
        ->and($termo->aceite_nome)->toBe('João da Silva')
        ->and($termo->aceite_ip)->toBe('203.0.113.7')
        ->and($termo->aceite_user_agent)->toBe('iPhone Safari de teste')
        ->and($termo->autoriza_rodagem)->toBeTrue()
        ->and($termo->assinatura)->toStartWith('data:image/png;base64,')
        ->and($os->termo_assinado_em)->not->toBeNull()
        ->and($os->autorizacao_rodagem)->toBeTrue();

    $evento = ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'termo_assinado')->first();

    expect($evento->dados_json['origem'])->toBe('link')->and($evento->dados_json['ip'])->toBe('203.0.113.7');

    // Link já usado: abre mostrando o aceite e não aceita de novo (o aceite é prova e não muda).
    $this->get($link['url'])->assertOk()->assertSee('O termo foi aceito em');
    termoAceite($token, ['nome' => 'Outra Pessoa', 'autoriza_rodagem' => '0'])->assertRedirect();

    expect(ServiceOrderTermo::first()->aceite_nome)->toBe('João da Silva')
        ->and(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'termo_assinado')->count())->toBe(1);

    // A tela da OS mostra data, hora e IP do aceite (roteiro, linha 14).
    $this->actingAs($dono, 'user')->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('Aceito por')
        ->assertSee('203.0.113.7');
});

it('B2: o aceite exige nome, resposta sim/não, o "li e aceito" e uma assinatura válida; "não autorizo" é registrado', function () {
    $this->enablePilot();
    $os = $this->makeOs();
    $this->actingAs($this->makeUser('proprietario'), 'user')->post(route('admin.service_orders.workflow.termo_link', $os->id));
    $token = basename(parse_url(session('termo_link')['url'], PHP_URL_PATH));
    auth()->guard('user')->logout();

    termoAceite($token, ['nome' => ''])->assertSessionHasErrors('nome');
    termoAceite($token, ['autoriza_rodagem' => null])->assertSessionHasErrors('autoriza_rodagem');
    termoAceite($token, ['li_e_aceito' => null])->assertSessionHasErrors('li_e_aceito');
    termoAceite($token, ['assinatura' => 'data:text/html;base64,PHNjcmlwdD4='])->assertSessionHasErrors('assinatura');

    expect(ServiceOrderTermo::first()->aceito_em)->toBeNull();

    // Sem assinatura desenhada também vale (o aceite fica registrado do mesmo jeito); "não autorizo" é respeitado.
    termoAceite($token, ['autoriza_rodagem' => '0', 'assinatura' => ''])->assertSessionHasNoErrors();

    expect(ServiceOrderTermo::first()->autoriza_rodagem)->toBeFalse()
        ->and($os->refresh()->autorizacao_rodagem)->toBeFalse()
        ->and($os->termo_assinado_em)->not->toBeNull();
});

it('B2: link inventado ou vencido não abre, e o link só é gerado para OS com veículo', function () {
    $this->enablePilot();
    $os = $this->makeOs();

    $this->get(route('service_orders.termo.show', str_repeat('a', 48)))->assertNotFound();
    $this->get(route('service_orders.termo.show', 'curto'))->assertNotFound();

    $this->actingAs($this->makeUser('proprietario'), 'user')->post(route('admin.service_orders.workflow.termo_link', $os->id));
    $token = basename(parse_url(session('termo_link')['url'], PHP_URL_PATH));
    auth()->guard('user')->logout();

    $this->get(route('service_orders.termo.show', $token))->assertOk();

    // Vencido (7 dias) e ainda sem aceite: não abre mais.
    ServiceOrderTermo::query()->update(['expira_em' => now()->subMinute()]);
    $this->get(route('service_orders.termo.show', $token))->assertNotFound();
    termoAceite($token)->assertNotFound();

    $software = $this->makeOs(['vehicle_id' => null]);
    $this->actingAs($this->makeUser('proprietario'), 'user')
        ->post(route('admin.service_orders.workflow.termo_link', $software->id))
        ->assertSessionHas('error');

    expect(ServiceOrderTermo::count())->toBe(1);
});

it('B2: o aceite não pode ser apagado e o texto do termo vem da configuração da oficina', function () {
    expect(app(TermoEntrada::class)->texto())->toBe(TermoEntrada::TEXTO_PADRAO);

    WorkshopSettings::set('termo_texto', 'Texto próprio da oficina.');

    expect(app(TermoEntrada::class)->texto())->toBe('Texto próprio da oficina.');

    $os = $this->makeOs();
    $termo = ServiceOrderTermo::create(['quote_id' => $os->id, 'token_hash' => hash('sha256', 'x'), 'expira_em' => now()->addDay()]);

    expect(fn () => $termo->delete())->toThrow(LogicException::class);
});
