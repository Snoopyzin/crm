<?php

use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Services\Credito\CreditoCliente;
use Webkul\ServiceOrder\Services\FichaEnquadramento;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

// Ajustes combinados na reunião de 19/11/2026: pesquisa de cliente na abertura da OS com validade do
// cadastro e pop-up, etiquetas de crédito, aba do defeito relatado, "Orçamento em andamento", busca de
// peças e serviços e horários do diagnóstico informados pelo chefe.

function usuarioDaReuniao(): User
{
    $role = Role::create(['name' => 'Admin reunião '.uniqid(), 'description' => 'x', 'permission_type' => 'all', 'permissions' => null]);

    return User::create([
        'name' => 'Admin reunião',
        'email' => uniqid('reuniao').'@teste.local',
        'password' => bcrypt('senha-teste'),
        'status' => 1,
        'role_id' => $role->id,
        'view_permission' => 'global',
    ]);
}

// ---- Nomes ------------------------------------------------------------------------------------

it('a fase "Orçamento" aparece como "Orçamento em andamento" no menu, na lista e na OS, sem mudar o valor gravado', function () {
    expect(trans('service_order::app.stages.Orçamento'))->toBe('Orçamento em andamento')
        ->and(trans('service_order::app.menu.orcamento'))->toBe('Orçamento em andamento')
        ->and(ServiceOrder::STAGE_ORCAMENTO)->toBe('Orçamento');

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    $this->actingAs(usuarioDaReuniao(), 'user');

    $this->get(route('admin.service_orders.view', $os->id))->assertOk()->assertSee('Orçamento em andamento');
    $this->get(route('admin.service_orders.stage.index', ['etapa' => ServiceOrder::STAGE_ORCAMENTO]))->assertOk()->assertSee('Orçamento em andamento');

    expect($os->tituloAtual())->toContain('Orçamento em andamento');
});

it('o parecer do profissional no diagnóstico se chama "Descrição técnica", com o piloto ligado ou não', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    $this->actingAs(usuarioDaReuniao(), 'user');

    WorkshopSettings::set('piloto_ativo', false);
    $this->get(route('admin.service_orders.view', $os->id))->assertOk()->assertSee('Descrição técnica');

    $this->enablePilot();
    $this->get(route('admin.service_orders.view', $os->id))->assertOk()->assertSee('Descrição técnica');
});

// ---- Aba do defeito relatado ------------------------------------------------------------------

it('a OS tem a aba "Defeito relatado pelo motorista" e as abas levam às partes da tela', function () {
    $os = $this->makeOs();

    $this->actingAs(usuarioDaReuniao(), 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('id="abas-os"', false)
        ->assertSee('data-aba="Cadastro"', false)
        ->assertSee('data-aba="Diagnóstico"', false)
        ->assertSee('data-aba="anexos"', false)
        ->assertSee('data-aba="historico"', false)
        ->assertSee('id="defeito-relatado"', false)
        ->assertSee('Defeito relatado pelo motorista')
        ->assertSee('name="relato_motorista"', false)
        ->assertSee('name="defeito_relatado"', false);

    $this->put(route('admin.service_orders.defeito.update', $os->id), [
        'relato_motorista' => 'Perde força na serra e esquenta.',
        'defeito_relatado' => 'Perda de potência',
    ])->assertRedirect();

    $os->refresh();

    expect($os->relato_motorista)->toBe('Perde força na serra e esquenta.')
        ->and($os->defeito_relatado)->toBe('Perda de potência');

    $this->get(route('admin.service_orders.view', $os->id))->assertSee('Perde força na serra e esquenta.')->assertSee('Perda de potência');

    // Apagar o texto limpa o campo.
    $this->put(route('admin.service_orders.defeito.update', $os->id), ['relato_motorista' => '', 'defeito_relatado' => ''])->assertRedirect();

    expect($os->refresh()->relato_motorista)->toBeNull();
});

it('quem não edita OS lê o defeito relatado mas não altera', function () {
    $os = $this->makeOs(['relato_motorista' => 'Barulho no eixo traseiro', 'defeito_relatado' => 'Barulho']);

    $leitor = $this->makeUser('consultor', ['service_orders', 'service_orders.view']);

    $this->actingAs($leitor, 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('Barulho no eixo traseiro')
        ->assertDontSee('Salvar o defeito relatado');

    $this->put(route('admin.service_orders.defeito.update', $os->id), ['relato_motorista' => 'x'])->assertForbidden();

    expect($os->refresh()->relato_motorista)->toBe('Barulho no eixo traseiro');
});

// ---- Peças e serviços -------------------------------------------------------------------------

it('lançar peças e serviços tem busca por nome ou código e atalhos para cadastrar peça e serviço', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    $this->actingAs(usuarioDaReuniao(), 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('id="item-busca"', false)
        ->assertSee('Buscar peça ou serviço (nome ou código)')
        ->assertSee(route('admin.parts.create'), false)
        ->assertSee(route('admin.services.create'), false)
        ->assertSee('Cadastrar peça')
        ->assertSee('Cadastrar serviço');
});

// ---- Pesquisa de cliente na abertura da OS ----------------------------------------------------

it('a abertura da OS tem o campo de pesquisa de cliente (nome, código, CPF/CNPJ, telefone ou placa) em vez da lista inteira', function () {
    $this->makePerson(['name' => 'Cliente Que Não Pode Aparecer Na Lista']);

    $this->actingAs(usuarioDaReuniao(), 'user');

    $this->get(route('admin.service_orders.create'))
        ->assertOk()
        ->assertSee('id="cliente-busca"', false)
        ->assertSee('Pesquise por nome, código, CPF/CNPJ, telefone ou placa')
        ->assertSee('id="person_id"', false)
        ->assertSee('id="cliente-alerta"', false)
        ->assertDontSee('Cliente Que Não Pode Aparecer Na Lista');
});

it('a pesquisa acha o cliente por nome, código, CPF/CNPJ, telefone e placa', function () {
    $pessoa = $this->makePerson(['name' => 'Fretes Horizonte LTDA', 'cpf_cnpj' => '45.723.174/0001-10', 'contact_numbers' => [['value' => '+55 (62) 99888-7766', 'label' => 'WhatsApp']]]);
    $this->makeVehicle($pessoa->id, ['placa' => 'QWE4R56']);
    $outra = $this->makePerson(['name' => 'Outra Empresa SA', 'cpf_cnpj' => '11.444.777/0001-61', 'contact_numbers' => [['value' => '+55 (11) 91111-2222', 'label' => 'WhatsApp']]]);

    // O código do cliente é dado pelo sistema ao cadastrar: relê do banco.
    $pessoa->refresh();
    $outra->refresh();

    $this->actingAs(usuarioDaReuniao(), 'user');

    $acha = fn (string $termo) => collect($this->getJson(route('admin.clientes.pesquisar', ['q' => $termo]))->assertOk()->json('resultados'))->pluck('id')->all();

    expect($acha('horizonte'))->toBe([$pessoa->id])
        ->and($acha('45723174'))->toBe([$pessoa->id])
        ->and($acha('99888-7766'))->toBe([$pessoa->id])
        ->and($acha('qwe-4r56'))->toBe([$pessoa->id])
        ->and($acha('91111'))->toBe([$outra->id])
        ->and($acha('x'))->toBe([])
        ->and($acha((string) $pessoa->codigo))->toContain($pessoa->id);

    $com_codigo = $this->getJson(route('admin.clientes.pesquisar', ['q' => '#'.$outra->codigo]))->json('resultados');

    expect(collect($com_codigo)->pluck('id')->all())->toContain($outra->id)
        ->and(collect($com_codigo)->firstWhere('id', $outra->id)['codigo'])->toBe($outra->codigo);

    // Sem resultado: o endereço de "Cadastrar cliente" já leva o que foi digitado.
    expect($this->getJson(route('admin.clientes.pesquisar', ['q' => 'Nome Inexistente Zeta']))->json('cadastro'))
        ->toBe(route('admin.service_orders.cadastro.create', ['name' => 'Nome Inexistente Zeta']));
});

it('a aba Buscar cliente também acha pelo código e tem "Nova OS" (abre a OS com o cliente) e "Cadastro do cliente"', function () {
    $pessoa = $this->makePerson(['name' => 'Transportes Códigos LTDA'])->refresh();

    $this->actingAs(usuarioDaReuniao(), 'user');

    $this->get(route('admin.clientes.busca', ['q' => (string) $pessoa->codigo]))
        ->assertOk()
        ->assertSee('Transportes Códigos LTDA')
        ->assertSee('Nº '.$pessoa->codigo)
        ->assertSee(route('admin.service_orders.create', ['person_id' => $pessoa->id]), false)
        ->assertSee('Cadastro do cliente');
});

it('o cliente escolhido na busca chega à OS já selecionado', function () {
    $pessoa = $this->makePerson(['name' => 'Cliente Escolhido SA']);

    $this->actingAs(usuarioDaReuniao(), 'user');

    $this->get(route('admin.service_orders.create', ['person_id' => $pessoa->id]))
        ->assertOk()
        ->assertSee('value="'.$pessoa->id.'"', false)
        ->assertSee('Cliente Escolhido SA');
});

it('cadastro vencido: sem análise de crédito, com análise antiga e com análise recente, usando a validade da Ficha', function () {
    $this->actingAs(usuarioDaReuniao(), 'user');

    $pessoa = $this->makePerson(['name' => 'Cliente da Validade']);
    $credito = app(CreditoCliente::class);

    // Nunca analisado: vencido.
    $semAnalise = $credito->validade($pessoa->id);

    expect($semAnalise['vencida'])->toBeTrue()->and($semAnalise['nunca'])->toBeTrue()->and($semAnalise['validade_dias'])->toBe(90);

    $credito->registrar($pessoa->id, 'verde', 'Consulta feita');

    $recente = $credito->validade($pessoa->id);

    expect($recente['vencida'])->toBeFalse()->and($recente['dias'])->toBe(0);

    // A análise tem 120 dias: passou dos 90 da validade.
    DB::table('cliente_credito_registros')->where('person_id', $pessoa->id)->update(['created_at' => now()->subDays(120)]);

    $antiga = $credito->validade($pessoa->id);

    expect($antiga['vencida'])->toBeTrue()->and($antiga['nunca'])->toBeFalse()->and($antiga['dias'])->toBe(120);

    // A validade é da oficina: com 150 dias, a mesma análise ainda vale.
    WorkshopSettings::set('cadastro_validade_dias', 150);

    expect($credito->validade($pessoa->id)['vencida'])->toBeFalse();

    // Valor inválido volta ao padrão em vez de vencer tudo.
    WorkshopSettings::set('cadastro_validade_dias', 0);

    expect($credito->validade($pessoa->id)['validade_dias'])->toBe(90);
});

it('o resultado da pesquisa leva a validade e a cor de crédito, e a etiqueta aparece na OS (verde, amarelo, vermelho)', function () {
    $this->actingAs(usuarioDaReuniao(), 'user');

    $pessoa = $this->makePerson(['name' => 'Cliente Etiquetado']);
    $credito = app(CreditoCliente::class);
    $credito->registrar($pessoa->id, 'amarelo', 'Atrasa às vezes');

    DB::table('cliente_credito_registros')->where('person_id', $pessoa->id)->update(['created_at' => now()->subDays(100)]);

    $resultado = collect($this->getJson(route('admin.clientes.pesquisar', ['q' => 'Etiquetado']))->json('resultados'))->firstWhere('id', $pessoa->id);

    expect($resultado['credito']['cor'])->toBe('amarelo')
        ->and($resultado['credito']['vencida'])->toBeTrue()
        ->and($resultado['credito']['dias'])->toBe(100)
        ->and($resultado['credito']['validade_dias'])->toBe(90);

    $os = $this->makeOs();
    $os->forceFill(['person_id' => $pessoa->id])->save();

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertSee('Crédito: duvidoso')
        ->assertSee('Cadastro vencido há 100 dias')
        ->assertSee('etiqueta-credito', false);
});

it('quem não pode ver crédito não recebe cor, validade nem etiqueta (o Técnico nunca vê)', function () {
    $pessoa = $this->makePerson(['name' => 'Cliente Reservado']);

    $this->actingAs(usuarioDaReuniao(), 'user');
    app(CreditoCliente::class)->registrar($pessoa->id, 'vermelho', 'Inadimplente');

    $os = $this->makeOs();
    $os->forceFill(['person_id' => $pessoa->id])->save();

    $tecnico = $this->makeUser('tecnico', ['service_orders', 'service_orders.view', 'service_orders.create', 'clientes_busca']);

    $this->actingAs($tecnico, 'user');

    $json = $this->getJson(route('admin.clientes.pesquisar', ['q' => 'Reservado']))->assertOk()->json('resultados');

    expect($json)->toHaveCount(1)->and($json[0]['credito'])->toBeNull();

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertDontSee('Crédito: ruim')
        ->assertDontSee('etiqueta-credito', false)
        ->assertDontSee('Cadastro vencido');

    // E sem a permissão de criar OS, nem a pesquisa abre.
    $semCriar = $this->makeUser('consultor', ['service_orders', 'service_orders.view']);

    $this->actingAs($semCriar, 'user')->getJson(route('admin.clientes.pesquisar', ['q' => 'Reservado']))->assertForbidden();
});

it('a Ficha guarda a validade do cadastro do cliente e rejeita valor inválido', function () {
    $this->actingAs(usuarioDaReuniao(), 'user');

    $base = [
        'ficha_porte' => array_key_first(FichaEnquadramento::PORTES),
        'garantia_servico_dias' => 90,
        'garantia_pecas_dias' => 90,
        'dono_horas_prospeccao' => 4,
    ];

    $this->get(route('admin.ficha.edit'))->assertOk()->assertSee('Validade do cadastro do cliente (dias)');

    $this->put(route('admin.ficha.update'), $base + ['cadastro_validade_dias' => 45])->assertRedirect(route('admin.ficha.edit'));

    expect((int) WorkshopSettings::get('cadastro_validade_dias'))->toBe(45);

    $this->put(route('admin.ficha.update'), $base + ['cadastro_validade_dias' => 0])->assertSessionHasErrors('cadastro_validade_dias');

    // Formulário sem o campo não apaga o que já estava.
    $this->put(route('admin.ficha.update'), $base)->assertRedirect(route('admin.ficha.edit'));

    expect((int) WorkshopSettings::get('cadastro_validade_dias'))->toBe(45);
});

// ---- Horários do diagnóstico informados pelo chefe --------------------------------------------

it('o chefe de oficina informa os horários do diagnóstico, que viram os eventos de início e fim', function () {
    $this->enablePilot();

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);
    $os->forceFill(['diagnostico' => 'Bico injetor 3 entupido.'])->save();

    $chefe = $this->makeUser('chefe', ['service_orders', 'service_orders.view', 'service_orders.edit', 'service_orders.auditar']);

    $this->actingAs($chefe, 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('Informar ou corrigir os horários (chefe de oficina)')
        ->assertSee('name="diagnostico_inicio_em"', false)
        ->assertSee('name="diagnostico_fim_em"', false);

    $inicio = now()->subHours(3)->format('Y-m-d\TH:i');
    $fim = now()->subHour()->format('Y-m-d\TH:i');

    $this->post(route('admin.service_orders.workflow.diagnostico.horarios', $os->id), ['diagnostico_inicio_em' => $inicio, 'diagnostico_fim_em' => $fim])
        ->assertRedirect()
        ->assertSessionHas('success');

    $os->refresh();

    expect($os->diagnostico_inicio_em->format('Y-m-d\TH:i'))->toBe($inicio)
        ->and($os->diagnostico_concluido_em->format('Y-m-d\TH:i'))->toBe($fim);

    $eventos = ServiceOrderEvent::where('service_order_id', $os->id)->whereIn('tipo_evento', ['diagnostico_inicio', 'diagnostico_fim'])->get()->keyBy('tipo_evento');

    expect($eventos['diagnostico_inicio']->dados_json['origem'])->toBe('informado_chefe')
        ->and($eventos['diagnostico_fim']->ocorrido_em->format('Y-m-d\TH:i'))->toBe($fim);
});

it('horário do diagnóstico: recusa futuro, conclusão antes do início, conclusão sem a descrição técnica e correção sem motivo', function () {
    $this->enablePilot();

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    $chefe = $this->makeUser('chefe', ['service_orders', 'service_orders.view', 'service_orders.edit', 'service_orders.auditar']);

    $this->actingAs($chefe, 'user');

    $rota = route('admin.service_orders.workflow.diagnostico.horarios', $os->id);
    $hora = fn (string $quando) => now()->modify($quando)->format('Y-m-d\TH:i');

    // Sem a descrição técnica não fecha o diagnóstico.
    $this->post($rota, ['diagnostico_inicio_em' => $hora('-3 hours'), 'diagnostico_fim_em' => $hora('-1 hour')])->assertSessionHas('error');

    expect($os->refresh()->diagnostico_concluido_em)->toBeNull();

    $os->forceFill(['diagnostico' => 'Laudo escrito.'])->save();

    // Futuro.
    $this->post($rota, ['diagnostico_inicio_em' => $hora('+2 hours')])->assertSessionHas('error');

    // Conclusão antes do início.
    $this->post($rota, ['diagnostico_inicio_em' => $hora('-1 hour'), 'diagnostico_fim_em' => $hora('-3 hours')])->assertSessionHas('error');

    // Nada informado.
    $this->post($rota, [])->assertSessionHas('error');

    expect($os->refresh()->diagnostico_inicio_em)->toBeNull();

    // Registra; depois, mudar o horário já registrado exige motivo.
    $this->post($rota, ['diagnostico_inicio_em' => $hora('-3 hours'), 'diagnostico_fim_em' => $hora('-2 hours')])->assertSessionHas('success');

    $this->post($rota, ['diagnostico_fim_em' => $hora('-1 hour')])->assertSessionHas('error');

    expect($os->refresh()->diagnostico_concluido_em->format('Y-m-d\TH:i'))->toBe($hora('-2 hours'));

    $this->post($rota, ['diagnostico_fim_em' => $hora('-1 hour'), 'justificativa' => 'O técnico esqueceu de finalizar'])->assertSessionHas('success');

    expect($os->refresh()->diagnostico_concluido_em->format('Y-m-d\TH:i'))->toBe($hora('-1 hour'));

    $correcao = ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'correcao')->firstOrFail();

    expect($correcao->dados_json['justificativa'])->toBe('O técnico esqueceu de finalizar')
        ->and($correcao->dados_json['campo'])->toBe('diagnostico_concluido_em');
});

it('só quem audita (chefe, gerente, proprietário) informa os horários do diagnóstico', function () {
    $this->enablePilot();

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    $tecnico = $this->makeUser('tecnico', ['service_orders', 'service_orders.view', 'service_orders.edit']);

    $this->actingAs($tecnico, 'user');

    $this->get(route('admin.service_orders.view', $os->id))->assertOk()->assertDontSee('Informar ou corrigir os horários');

    $this->post(route('admin.service_orders.workflow.diagnostico.horarios', $os->id), ['diagnostico_inicio_em' => now()->subHour()->format('Y-m-d\TH:i')])->assertForbidden();

    expect($os->refresh()->diagnostico_inicio_em)->toBeNull();
});
