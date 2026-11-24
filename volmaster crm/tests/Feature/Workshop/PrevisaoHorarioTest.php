<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Services\PrazoOs;
use Webkul\ServiceOrder\Services\PrevisaoOs;

function dadosNovaOs(array $extra = []): array
{
    return array_merge(['servico' => 'Revisão no mesmo dia', 'description' => 'Barulho no freio'], $extra);
}

it('a coluna do horário existe no banco', function () {
    expect(Schema::hasColumn('quotes', 'hora_previsao'))->toBeTrue()
        ->and(PrevisaoOs::disponivel())->toBeTrue();
});

it('o formulário de Nova OS e o de edição têm o campo de horário da previsão', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create', 'service_orders.edit']);
    $this->actingAs($user, 'user');

    $this->get(route('admin.service_orders.create'))
        ->assertOk()
        ->assertSee('name="hora_previsao"', false)
        ->assertSee('type="time"', false)
        ->assertSee('veículo que entra e sai no mesmo dia');

    $os = $this->makeOs(['data_previsao' => '2026-11-18', 'hora_previsao' => '15:30']);

    $this->get(route('admin.service_orders.edit', $os->id))
        ->assertOk()
        ->assertSee('name="hora_previsao"', false)
        ->assertSee('value="15:30"', false);
});

it('grava a previsão com horário e mostra data e hora na OS', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create', 'service_orders.view']);
    $this->actingAs($user, 'user');

    $cliente = $this->makePerson();

    $this->post(route('admin.service_orders.store'), dadosNovaOs([
        'person_id' => $cliente->id,
        'data_previsao' => '2026-11-18',
        'hora_previsao' => '15:30',
    ]))->assertRedirect();

    $os = ServiceOrder::where('person_id', $cliente->id)->firstOrFail();

    expect(substr((string) $os->hora_previsao, 0, 5))->toBe('15:30')
        ->and($os->previsaoTexto())->toBe('18/11/2026 às 15:30');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('18/11/2026 às 15:30');
});

it('o horário é opcional: sem ele a previsão continua só com a data', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);
    $this->actingAs($user, 'user');

    $cliente = $this->makePerson();

    $this->post(route('admin.service_orders.store'), dadosNovaOs([
        'person_id' => $cliente->id,
        'data_previsao' => '2026-11-18',
        'hora_previsao' => '',
    ]))->assertRedirect();

    $os = ServiceOrder::where('person_id', $cliente->id)->firstOrFail();

    expect($os->hora_previsao)->toBeNull()
        ->and($os->previsaoTexto())->toBe('18/11/2026');
});

it('horário sem data é descartado, e horário inválido é recusado', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);
    $this->actingAs($user, 'user');

    $cliente = $this->makePerson();

    $this->post(route('admin.service_orders.store'), dadosNovaOs([
        'person_id' => $cliente->id,
        'data_previsao' => '',
        'hora_previsao' => '15:30',
    ]))->assertRedirect();

    expect(ServiceOrder::where('person_id', $cliente->id)->firstOrFail()->hora_previsao)->toBeNull();

    $this->post(route('admin.service_orders.store'), dadosNovaOs([
        'person_id' => $cliente->id,
        'data_previsao' => '2026-11-18',
        'hora_previsao' => '25:99',
    ]))->assertSessionHasErrors(['hora_previsao' => 'O horário da previsão precisa estar no formato HH:MM (por exemplo, 15:30).']);

    expect(ServiceOrder::where('person_id', $cliente->id)->count())->toBe(1);
});

it('editar a OS troca o horário e limpar a data limpa o horário', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.edit']);
    $this->actingAs($user, 'user');

    $os = $this->makeOs(['data_previsao' => '2026-11-18', 'hora_previsao' => '15:30']);

    $base = ['person_id' => $os->person_id, 'servico' => 'Revisão', 'description' => 'Defeito'];

    $this->put(route('admin.service_orders.update', $os->id), $base + ['data_previsao' => '2026-11-18', 'hora_previsao' => '17:45'])->assertRedirect();
    expect($os->refresh()->previsaoTexto())->toBe('18/11/2026 às 17:45');

    $this->put(route('admin.service_orders.update', $os->id), $base + ['data_previsao' => '', 'hora_previsao' => '17:45'])->assertRedirect();
    expect($os->refresh()->hora_previsao)->toBeNull()
        ->and($os->previsaoTexto())->toBeNull();
});

it('com horário o prazo vence naquela hora (e sem horário, no fim do expediente)', function () {
    $this->enablePilot([], ['prazo_prometido']);
    $this->at('2026-11-20 08:00:00');

    $mesmoDia = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_EM_EXECUCAO]);
    $mesmoDia->update(['data_previsao' => '2026-11-20', 'hora_previsao' => '15:30']);

    $semHorario = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_EM_EXECUCAO]);
    $semHorario->update(['data_previsao' => '2026-11-20']);

    $evento = fn (ServiceOrder $os) => ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'prazo_informado')->latest('id')->first();

    expect($evento($mesmoDia)->dados_json['prazo_em'])->toBe('2026-11-20 15:30:00')
        ->and($mesmoDia->refresh()->prazo_prometido_em->format('d/m H:i'))->toBe('20/11 15:30')
        ->and($evento($semHorario)->dados_json['prazo_em'])->toBe('2026-11-20 18:00:00');

    // Só mudar o horário também reinforma o prazo ao cliente.
    $mesmoDia->update(['hora_previsao' => '16:45']);

    expect($evento($mesmoDia)->dados_json['prazo_em'])->toBe('2026-11-20 16:45:00')
        ->and($mesmoDia->refresh()->prazo_prometido_em->format('d/m H:i'))->toBe('20/11 16:45');
});

it('o card OS em andamento fica vermelho quando passa do horário marcado, no mesmo dia', function () {
    $this->at('2026-11-18 10:00:00');

    $comHorario = $this->makeOs(['data_previsao' => '2026-11-18', 'hora_previsao' => '15:30']);
    $semHorario = $this->makeOs(['data_previsao' => '2026-11-18']);

    $estados = fn () => PrazoOs::aplicar(ServiceOrder::all())->mapWithKeys(fn ($os) => [$os->id => $os->prazo])->all();

    $antes = $estados();

    expect($antes[$comHorario->id]['estado'])->toBe(PrazoOs::NO_PRAZO)
        ->and($antes[$comHorario->id]['texto'])->toBe('Previsão de entrega 18/11/2026 às 15:30')
        ->and($antes[$semHorario->id]['estado'])->toBe(PrazoOs::NO_PRAZO)
        ->and($antes[$semHorario->id]['texto'])->toBe('Previsão de entrega 18/11/2026');

    // 16:00: passou do horário marcado; a OS sem horário vale até o fim do dia.
    $this->at('2026-11-18 16:00:00');
    $depois = $estados();

    expect($depois[$comHorario->id]['estado'])->toBe(PrazoOs::ESTOURADO)
        ->and($depois[$comHorario->id]['texto'])->toBe('Previsão de entrega (atrasada) 18/11/2026 às 15:30')
        ->and($depois[$semHorario->id]['estado'])->toBe(PrazoOs::NO_PRAZO);
});

it('a OS impressa e o acompanhamento do cliente mostram o horário', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.view']);
    $this->actingAs($user, 'user');

    $os = $this->makeOs(['data_previsao' => '2026-11-18', 'hora_previsao' => '15:30']);

    $this->get(route('admin.service_orders.print', $os->id))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->get(URL::signedRoute('service_orders.tracking.show', $os->id))
        ->assertOk()
        ->assertSee('18/11/2026 às 15:30');
});

it('as funções de apoio normalizam horário, texto e momento', function () {
    expect(PrevisaoOs::hora('09:05:00'))->toBe('09:05')
        ->and(PrevisaoOs::hora('9:05'))->toBeNull()
        ->and(PrevisaoOs::hora('24:00'))->toBeNull()
        ->and(PrevisaoOs::hora(''))->toBeNull()
        ->and(PrevisaoOs::hora(null))->toBeNull()
        ->and(PrevisaoOs::texto('2026-11-18', '09:05:00'))->toBe('18/11/2026 às 09:05')
        ->and(PrevisaoOs::texto('2026-11-18'))->toBe('18/11/2026')
        ->and(PrevisaoOs::texto(null, '09:05'))->toBeNull()
        ->and(PrevisaoOs::momento('2026-11-18', '09:05')->format('Y-m-d H:i'))->toBe('2026-11-18 09:05')
        ->and(PrevisaoOs::momento('2026-11-18')->format('Y-m-d H:i:s'))->toBe('2026-11-18 23:59:59')
        ->and(PrevisaoOs::momento(null, '09:05'))->toBeNull();
});
