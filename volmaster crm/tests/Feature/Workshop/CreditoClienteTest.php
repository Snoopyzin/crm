<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\AlcadaPedido;
use Webkul\ServiceOrder\Models\ClienteCreditoRegistro;
use Webkul\ServiceOrder\Services\Credito\CreditoCliente;
use Webkul\ServiceOrder\Services\Perfis\PerfilService;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\User;

/**
 * B1 do roteiro do patrão: cor de crédito do cliente, com histórico, sugestão automática pelos
 * títulos, bloqueio de prazo para Vermelho (direto ao Proprietário) e sigilo.
 */
function creditoTitulo(int $osId, string $vencimento, string $status = 'Pendente', ?string $pagoEm = null): void
{
    DB::table('service_order_boletos')->insert([
        'quote_id' => $osId, 'valor' => 500, 'vencimento' => $vencimento, 'status' => $status,
        'created_at' => now(), 'updated_at' => $pagoEm ?? now(),
    ]);
}

function creditoServico(): CreditoCliente
{
    return app(CreditoCliente::class);
}

function creditoUsuario(string $perfil): User
{
    app(PerfilService::class)->aplicar();

    return User::create([
        'name' => ucfirst($perfil).' '.Str::random(4), 'email' => Str::random(8).'@teste.local', 'password' => bcrypt('x'),
        'status' => 1, 'role_id' => app(PerfilService::class)->funcaoPara([$perfil])->id, 'view_permission' => 'global',
    ]);
}

it('B1 (teste do PDF): cliente com boleto vencido há 20 dias aparece Vermelho; atraso recente, Amarelo; em dia, Verde', function () {
    $this->at('2026-11-18 10:00:00');
    $os = $this->makeOs();
    $cliente = $os->person_id;

    expect(creditoServico()->sugestao($cliente))->toBe(['cor' => 'verde', 'motivo' => 'Sem histórico de títulos']);

    creditoTitulo($os->id, '2026-12-30'); // a vencer
    expect(creditoServico()->sugestao($cliente)['cor'])->toBe('verde');

    creditoTitulo($os->id, '2026-11-10'); // vencido há 8 dias (ainda dentro dos 15)
    expect(creditoServico()->sugestao($cliente)['cor'])->toBe('amarelo');

    creditoTitulo($os->id, '2026-10-29'); // vencido há 20 dias
    $sugestao = creditoServico()->sugestao($cliente);

    expect($sugestao['cor'])->toBe('vermelho')
        ->and($sugestao['motivo'])->toContain('20 dias');

    // O limite de dias é da oficina.
    WorkshopSettings::set('credito_dias_vermelho', 30);

    expect(creditoServico()->sugestao($cliente)['cor'])->toBe('amarelo');
});

it('B1: título pago com atraso dentro dos 90 dias deixa o cliente Amarelo; título cancelado não conta', function () {
    $this->at('2026-11-18 10:00:00');
    $os = $this->makeOs();

    creditoTitulo($os->id, '2026-09-10', 'Pago', '2026-09-25 10:00:00'); // pago 15 dias depois do vencimento
    expect(creditoServico()->sugestao($os->person_id)['cor'])->toBe('amarelo');

    $outro = $this->makeOs();
    creditoTitulo($outro->id, '2026-08-01', 'Cancelado');
    creditoTitulo($outro->id, '2026-11-01', 'Pago', '2026-11-01 09:00:00');

    expect(creditoServico()->sugestao($outro->person_id)['cor'])->toBe('verde');
});

it('B1: o histórico é só inserção (data, usuário, observação) e cor mais branda que a sugestão exige justificativa', function () {
    $this->at('2026-11-18 10:00:00');
    $os = $this->makeOs();
    creditoTitulo($os->id, '2026-10-20'); // vencido há 29 dias: sugestão Vermelho
    $financeiro = creditoUsuario('financeiro');

    // Registrar Verde contra a sugestão Vermelha sem justificar: não grava.
    expect(fn () => creditoServico()->registrar($os->person_id, 'verde', 'cliente prometeu pagar', null, null, $financeiro))
        ->toThrow(ValidationException::class);

    expect(ClienteCreditoRegistro::count())->toBe(0);

    $registro = creditoServico()->registrar($os->person_id, 'verde', 'cliente prometeu pagar', 'Acordo assinado hoje', null, $financeiro);

    expect($registro->user_id)->toBe($financeiro->id)
        ->and($registro->sugestao_cor)->toBe('vermelho')
        ->and($registro->justificativa)->toBe('Acordo assinado hoje');

    // Nova análise vira nova linha, com a cor anterior; nada se edita nem se apaga.
    $segundo = creditoServico()->registrar($os->person_id, 'vermelho', null, null, null, $financeiro);

    expect($segundo->cor_anterior)->toBe('verde')
        ->and(ClienteCreditoRegistro::where('person_id', $os->person_id)->count())->toBe(2)
        ->and(fn () => $registro->update(['cor' => 'amarelo']))->toThrow(LogicException::class)
        ->and(fn () => $registro->delete())->toThrow(LogicException::class);
});

it('B1: a cor que vale para liberar prazo piora sozinha quando a sugestão piora; decisão justificada vale enquanto a sugestão não mudar', function () {
    $this->at('2026-11-18 10:00:00');
    $os = $this->makeOs();
    $cliente = $os->person_id;
    $financeiro = creditoUsuario('financeiro');

    // Sem análise e sem atraso: sem cor.
    expect(creditoServico()->corEfetiva($cliente))->toBeNull();

    creditoServico()->registrar($cliente, 'verde', null, null, null, $financeiro);
    expect(creditoServico()->corEfetiva($cliente))->toBe('verde');

    // Um título passa de 15 dias: o cliente "fica Vermelho" sozinho.
    creditoTitulo($os->id, '2026-10-25');
    expect(creditoServico()->corEfetiva($cliente))->toBe('vermelho');

    // O Financeiro decide, com justificativa, manter Verde: vale enquanto a sugestão não mudar.
    creditoServico()->registrar($cliente, 'verde', null, 'Pagamento confirmado por e-mail', null, $financeiro);
    expect(creditoServico()->corEfetiva($cliente))->toBe('verde');
});

it('B1: prazo para cliente Vermelho vai DIRETO ao Proprietário, com 24 h; Amarelo segue para o Gerente', function () {
    $this->enablePilot(['modo_teste_barreiras' => false]);
    $this->at('2026-11-18 10:00:00');
    $os = $this->makeOs();
    creditoTitulo($os->id, '2026-10-25'); // Vermelho pela sugestão
    $consultor = creditoUsuario('consultor');
    $os->forceFill(['workshop_status' => 'Negociação'])->save();

    $this->actingAs($consultor, 'user')
        ->put(route('admin.service_orders.negociacao.update', $os->id), ['condicao_pagamento' => 'Boleto 30 dias'])
        ->assertRedirect();

    $pedido = AlcadaPedido::where('tema', 'condicao_pagamento')->first();

    expect($pedido)->not->toBeNull()
        ->and($pedido->status)->toBe(AlcadaPedido::ENCAMINHADO)
        ->and($pedido->nivel_necessario)->toBe('proprietario')
        ->and(Carbon::parse($pedido->prazo_dono_em)->diffInHours(now(), true))->toBeGreaterThan(23.5)
        ->and($os->fresh()->condicao_pagamento)->not->toBe('Boleto 30 dias');
});

it('B1: o cartão do cliente aparece só para Consultor, Financeiro, Gerente e Proprietário; nunca para Chefe, Técnico, Peças, Zeladoria e Mentor', function () {
    $os = $this->makeOs();
    $this->enablePilot();
    creditoServico()->registrar($os->person_id, 'amarelo', 'atrasa às vezes', null, null, creditoUsuario('financeiro'));
    $pagina = route('admin.contacts.persons.view', $os->person_id);

    // O Financeiro registra pela OS (ele não abre o cadastro de clientes): ver o teste da OS abaixo.
    foreach (['consultor', 'gerente'] as $perfil) {
        $this->actingAs(creditoUsuario($perfil), 'user')->get($pagina)->assertOk()->assertSee('Cor de crédito do cliente')->assertSee('atrasa às vezes');
    }

    // Só o Financeiro e o Gerente registram; o Consultor vê, mas não registra.
    $this->actingAs(creditoUsuario('consultor'), 'user')->get($pagina)->assertDontSee('Nova análise');
    $this->actingAs(creditoUsuario('gerente'), 'user')->get($pagina)->assertSee('Nova análise');

    $dono = $this->makeUser('proprietario');
    $this->actingAs($dono, 'user')->get($pagina)->assertOk()->assertSee('Cor de crédito do cliente');

    // Os demais perfis nem abrem a página do cliente; e, se abrissem, o cartão exige a permissão.
    foreach (['chefe', 'tecnico', 'pecas', 'zeladoria', 'mentor'] as $perfil) {
        $usuario = creditoUsuario($perfil);

        $this->actingAs($usuario, 'user');
        expect(creditoServico()->podeVer($usuario))->toBeFalse();
    }
});

it('B1: o registro pela página do cliente grava no histórico; Consultor não registra; sem permissão ninguém registra', function () {
    $os = $this->makeOs();
    $this->enablePilot();

    $this->actingAs(creditoUsuario('consultor'), 'user')
        ->post(route('admin.credito.registrar', $os->person_id), ['cor_credito' => 'amarelo'])
        ->assertForbidden();

    $financeiro = creditoUsuario('financeiro');

    $this->actingAs($financeiro, 'user')
        ->post(route('admin.credito.registrar', $os->person_id), ['cor_credito' => 'amarelo', 'observacao' => 'conferido no banco'])
        ->assertRedirect();

    $registro = ClienteCreditoRegistro::first();

    expect($registro->cor)->toBe('amarelo')
        ->and($registro->observacao)->toBe('conferido no banco')
        ->and($registro->user_id)->toBe($financeiro->id);
});

it('B1: a cor registrada na OS também entra no histórico do cliente, com usuário e observação, e a hora da PRIMEIRA cor é mantida', function () {
    $this->enablePilot();
    $this->at('2026-11-18 09:00:00');
    $os = $this->makeOs();
    $financeiro = creditoUsuario('financeiro');

    $this->actingAs($financeiro, 'user')
        ->post(route('admin.service_orders.workflow.cor_credito', $os->id), ['cor_credito' => 'verde', 'observacao' => 'paga em dia'])
        ->assertRedirect();

    $primeira = $os->fresh()->cor_credito_em;

    $this->at('2026-11-18 10:00:00');
    $this->post(route('admin.service_orders.workflow.cor_credito', $os->id), ['cor_credito' => 'amarelo', 'observacao' => 'atrasou 1 boleto'])->assertRedirect();

    expect($os->fresh()->cor_credito)->toBe('amarelo')
        // O CON-03 mede "antes da liberação": mudar a cor depois não penaliza o consultor.
        ->and($os->fresh()->cor_credito_em->equalTo($primeira))->toBeTrue()
        ->and(ClienteCreditoRegistro::where('person_id', $os->person_id)->orderBy('id')->pluck('cor')->all())->toBe(['verde', 'amarelo'])
        ->and(ClienteCreditoRegistro::where('person_id', $os->person_id)->first()->quote_id)->toBe($os->id);
});

it('B1: a informação de crédito NUNCA aparece no link de acompanhamento do cliente nem na OS impressa', function () {
    $this->enablePilot();
    $os = $this->makeOs();
    creditoServico()->registrar($os->person_id, 'vermelho', 'inadimplente-secreto', null, $os, creditoUsuario('financeiro'));
    $os->forceFill(['cor_credito' => 'vermelho'])->save();

    $this->actingAs($this->makeUser('proprietario'), 'user')
        ->get(route('admin.service_orders.print', $os->id))
        ->assertOk()
        ->assertDontSee('inadimplente-secreto')
        ->assertDontSee('Cor de crédito')
        ->assertDontSee('Inadimplente');
});
