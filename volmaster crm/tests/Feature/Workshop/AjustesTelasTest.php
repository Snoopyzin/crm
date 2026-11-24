<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderAttachment;
use Webkul\ServiceOrder\Services\Perfis\PerfilCatalog;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

// Ajustes de tela pedidos pelo patrão em 19/11/2026: nomes das etapas, relato do motorista,
// diagnóstico com decisão/horários/mídia, horários do orçamento e a aba "Buscar cliente".

function adminDeAjustes($teste): User
{
    // Função "todas as permissões", como o administrador do servidor (os testes antigos só usavam funções custom).
    $role = Role::create(['name' => 'Admin ajustes '.uniqid(), 'description' => 'x', 'permission_type' => 'all', 'permissions' => null]);

    return User::create([
        'name' => 'Admin ajustes',
        'email' => uniqid('admin').'@teste.local',
        'password' => bcrypt('senha-teste'),
        'status' => 1,
        'role_id' => $role->id,
        'view_permission' => 'global',
    ]);
}

// ---- Nomes na tela ---------------------------------------------------------------------------

it('"Cadastro" aparece como "Cadastro do cliente" na etapa, no menu e na lista, sem mudar o valor gravado', function () {
    expect(trans('service_order::app.stages.Cadastro'))->toBe('Cadastro do cliente')
        ->and(trans('service_order::app.menu.cadastro'))->toBe('Cadastro do cliente')
        ->and(ServiceOrder::STAGE_CADASTRO)->toBe('Cadastro');

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_CADASTRO]);

    $this->actingAs(adminDeAjustes($this), 'user');

    $this->get(route('admin.service_orders.view', $os->id))->assertOk()->assertSee('Cadastro do cliente');
    $this->get(route('admin.service_orders.stage.index', ['etapa' => ServiceOrder::STAGE_CADASTRO]))->assertOk()->assertSee('Cadastro do cliente');

    expect($os->etapaAtualRotulo())->toBe('Cadastro do cliente');
});

it('a lista de etapas mostra o nome da etapa em português e o telefone do cliente no Cadastro do cliente', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_CADASTRO]);

    $this->actingAs(adminDeAjustes($this), 'user');

    $cadastro = $this->getJson(route('admin.service_orders.stage.index', ['etapa' => ServiceOrder::STAGE_CADASTRO]), ['X-Requested-With' => 'XMLHttpRequest']);
    $cadastro->assertOk();

    $linha = collect($cadastro->json('records'))->firstWhere('id', $os->id);

    expect($linha)->not->toBeNull()
        ->and($linha['workshop_status'])->toBe('Cadastro do cliente')
        ->and($linha['person_contact_numbers'])->toContain('98765-4321');

    $diagnostico = $this->getJson(route('admin.service_orders.stage.index', ['etapa' => ServiceOrder::STAGE_DIAGNOSTICO]), ['X-Requested-With' => 'XMLHttpRequest']);

    expect(collect($diagnostico->json('columns'))->pluck('index')->all())->not->toContain('person_contact_numbers');
});

// ---- Cadastro e Ordem de Serviço -------------------------------------------------------------

it('o Cadastro do cliente tem Relato do motorista e Defeito relatado, e a OS nasce como "Ordem de Serviço - PLACA"', function () {
    $this->actingAs(adminDeAjustes($this), 'user');

    $this->get(route('admin.service_orders.cadastro.create'))
        ->assertOk()
        ->assertSee('Novo cadastro do cliente')
        ->assertSee('name="relato_motorista"', false)
        ->assertSee('name="defeito_relatado"', false)
        ->assertSee('Relato do motorista')
        ->assertSee('Defeito relatado');

    $this->post(route('admin.service_orders.cadastro.store'), [
        'name' => 'Transportadora Boa Viagem',
        'cpf_cnpj' => '11.222.333/0001-81',
        'whatsapp' => '(62) 99999-1111',
        'placa' => 'XYZ9K88',
        'marca' => 'Volvo',
        'modelo' => 'FH 540',
        'relato_motorista' => 'Perde força na subida e a luz da injeção acende.',
        'defeito_relatado' => 'Está engasgando',
    ])->assertRedirect();

    $os = ServiceOrder::query()->latest('id')->firstOrFail();

    expect($os->relato_motorista)->toBe('Perde força na subida e a luz da injeção acende.')
        ->and($os->defeito_relatado)->toBe('Está engasgando')
        ->and($os->subject)->toBe('Ordem de Serviço - XYZ9K88')
        ->and($os->workshop_status)->toBe(ServiceOrder::STAGE_CADASTRO);

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('Relato do motorista')
        ->assertSee('Perde força na subida')
        ->assertSee('Defeito relatado')
        ->assertSee('Está engasgando');
});

it('o Cadastro do cliente vem preenchido com o que foi digitado na busca', function () {
    $this->actingAs(adminDeAjustes($this), 'user');

    $this->get(route('admin.service_orders.cadastro.create', ['name' => 'Fretes Alfa', 'whatsapp' => '(62) 98888-7777']))
        ->assertOk()
        ->assertSee('Fretes Alfa')
        ->assertSee('(62) 98888-7777');
});

it('na tela da OS o campo vira "Descrição do técnico", com relato e defeito, e o título antigo "Cadastro - " sai como "Ordem de Serviço - "', function () {
    $os = $this->makeOs(['subject' => 'Cadastro - MKI4598', 'description' => 'Embreagem patinando', 'workshop_status' => ServiceOrder::STAGE_ORDEM_SERVICO]);

    $this->actingAs(adminDeAjustes($this), 'user');

    $edicao = $this->get(route('admin.service_orders.edit', $os->id));

    $edicao->assertOk()
        ->assertSee('Descrição do técnico')
        ->assertDontSee('Descrição do Defeito')
        ->assertSee('Relato do motorista')
        ->assertSee('Defeito relatado')
        ->assertSee('Ordem de Serviço - MKI4598');

    $this->put(route('admin.service_orders.update', $os->id), [
        'person_id' => $os->person_id,
        'vehicle_id' => $os->vehicle_id,
        'servico' => 'Ordem de Serviço - MKI4598',
        'description' => 'Embreagem patinando, disco gasto',
        'relato_motorista' => 'O motorista disse que a marcha não entra.',
        'defeito_relatado' => 'Marcha não entra',
    ])->assertRedirect(route('admin.service_orders.view', $os->id));

    $os->refresh();

    expect($os->description)->toBe('Embreagem patinando, disco gasto')
        ->and($os->relato_motorista)->toBe('O motorista disse que a marcha não entra.')
        ->and($os->defeito_relatado)->toBe('Marcha não entra');

    $this->get(route('admin.service_orders.view', $os->id))->assertSee('Descrição do técnico')->assertSee('disco gasto');
});

// ---- Diagnóstico -----------------------------------------------------------------------------

it('Diagnóstico (piloto desligado): decisão, horários, foto e vídeo, e sem previsão de conclusão', function () {
    Storage::fake('public');

    // O banco de testes é o de desenvolvimento, que fica com o piloto ligado: aqui precisa estar desligado.
    WorkshopSettings::set('piloto_ativo', false);

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO, 'data_previsao' => '2026-12-01']);

    $this->actingAs(adminDeAjustes($this), 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('O que precisa ser feito')
        ->assertSee('name="diagnostico_o_que_fazer"', false)
        ->assertSee('Início do diagnóstico')
        ->assertSee('Conclusão do diagnóstico')
        ->assertSee('Fotos e vídeos do diagnóstico')
        ->assertSee('name="fotos[]"', false)
        ->assertSee('name="videos[]"', false)
        ->assertDontSee('Previsão de Conclusão');

    $this->put(route('admin.service_orders.diagnostico.update', $os->id), [
        'diagnostico' => 'Atuador da embreagem com vazamento.',
        'diagnostico_o_que_fazer' => 'Trocar o atuador da embreagem e sangrar o sistema.',
    ])->assertRedirect();

    $os->refresh();

    expect($os->diagnostico_o_que_fazer)->toBe('Trocar o atuador da embreagem e sangrar o sistema.')
        ->and($os->diagnostico_concluido_em)->not->toBeNull();

    $this->post(route('admin.service_orders.workflow.diagnostico.midia', $os->id), [
        'fotos' => [UploadedFile::fake()->image('vazamento.jpg'), UploadedFile::fake()->image('atuador.jpg')],
        'videos' => [UploadedFile::fake()->create('ruido.mp4', 200, 'video/mp4')],
    ])->assertRedirect();

    expect($os->attachments()->where('tipo', ServiceOrderAttachment::TIPO_DIAGNOSTICO_FOTO)->count())->toBe(2)
        ->and($os->attachments()->where('tipo', ServiceOrderAttachment::TIPO_DIAGNOSTICO_VIDEO)->count())->toBe(1);

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertSee('Fotos e vídeos do diagnóstico (3)')
        ->assertSee('Trocar o atuador da embreagem');

    // Outra etapa continua mostrando a previsão.
    $orcamento = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO, 'data_previsao' => '2026-12-01']);

    $this->get(route('admin.service_orders.view', $orcamento->id))->assertSee('Previsão de Conclusão');
});

it('Diagnóstico (piloto ligado): o laudo guarda "O que precisa ser feito", mostra início e conclusão e aceita foto e vídeo', function () {
    Storage::fake('public');

    $this->enablePilot();

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    $this->actingAs(adminDeAjustes($this), 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('name="diagnostico_o_que_fazer"', false)
        ->assertSee('Início do diagnóstico')
        ->assertSee('ainda não iniciado')
        ->assertSee('Conclusão do diagnóstico')
        ->assertSee('ainda não concluído')
        ->assertSee('Fotos e vídeos do diagnóstico')
        ->assertDontSee('Previsão de Conclusão');

    $this->post(route('admin.service_orders.workflow.laudo', $os->id), [
        'diagnostico' => 'Bico injetor 3 entupido.',
        'diagnostico_o_que_fazer' => 'Trocar o bico injetor 3.',
    ])->assertRedirect();

    $os->refresh();

    expect($os->diagnostico)->toBe('Bico injetor 3 entupido.')
        ->and($os->diagnostico_o_que_fazer)->toBe('Trocar o bico injetor 3.');

    // Salvar o laudo sem o campo novo (formulário antigo) não apaga a decisão já escrita.
    $this->post(route('admin.service_orders.workflow.laudo', $os->id), ['diagnostico' => 'Bico injetor 3 entupido e sujeira no tanque.'])->assertRedirect();

    expect($os->refresh()->diagnostico_o_que_fazer)->toBe('Trocar o bico injetor 3.');

    $this->post(route('admin.service_orders.workflow.diagnostico.midia', $os->id), [
        'fotos' => [UploadedFile::fake()->image('bico.jpg')],
    ])->assertRedirect();

    $this->post(route('admin.service_orders.workflow.diagnostico.midia', $os->id), [])->assertRedirect();

    expect($os->attachments()->where('tipo', ServiceOrderAttachment::TIPO_DIAGNOSTICO_FOTO)->count())->toBe(1);

    $this->post(route('admin.service_orders.workflow.diagnostico.start', $os->id))->assertRedirect();

    expect($os->refresh()->diagnostico_inicio_em)->not->toBeNull();

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertSee('Início do diagnóstico')
        ->assertSee(core()->formatDate($os->diagnostico_inicio_em));
});

it('o envio de mídia do diagnóstico recusa arquivo de tipo errado', function () {
    Storage::fake('public');

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    $this->actingAs(adminDeAjustes($this), 'user');

    $this->post(route('admin.service_orders.workflow.diagnostico.midia', $os->id), [
        'fotos' => [UploadedFile::fake()->create('planilha.xlsx', 10)],
    ])->assertSessionHasErrors('fotos.0');

    expect($os->attachments()->count())->toBe(0);
});

// ---- Orçamento e itens -----------------------------------------------------------------------

it('Orçamento mostra o horário de início (quando o caminhão entrou na etapa) e o de conclusão (envio ao cliente)', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    $this->actingAs(adminDeAjustes($this), 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertSee('Início do orçamento')
        ->assertSee('Conclusão do orçamento')
        ->assertSee('ainda não iniciado');

    expect($os->etapaIniciadaEm(ServiceOrder::STAGE_ORCAMENTO))->toBeNull();

    // O caminhão passa para o Orçamento às 14h30: o evento de mudança de etapa marca o início.
    $this->travelTo(now()->setDate(2026, 11, 19)->setTime(14, 30));

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    expect($os->etapaIniciadaEm(ServiceOrder::STAGE_ORCAMENTO)?->format('d/m/Y H:i'))->toBe('19/11/2026 14:30');

    $this->get(route('admin.service_orders.view', $os->id))->assertSee('19/11/2026 14:30');

    $this->travelTo(now()->setTime(16, 5));

    $os->forceFill(['orcamento_enviado_em' => now()])->save();

    $this->get(route('admin.service_orders.view', $os->id))->assertSee('19/11/2026 16:05');

    $this->travelBack();
});

it('o bloco de itens se chama "Peças e serviços a realizar" e mostra a decisão do diagnóstico', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO, 'diagnostico_o_que_fazer' => 'Trocar a bomba de combustível.']);

    $this->actingAs(adminDeAjustes($this), 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('Peças e serviços a realizar')
        ->assertSee('catálogo de Serviços')
        ->assertSee('Trocar a bomba de combustível.');
});

// ---- Aba "Buscar cliente" --------------------------------------------------------------------

it('Buscar cliente acha a pessoa por nome, CPF/CNPJ, telefone e placa', function () {
    $pessoa = $this->makePerson(['name' => 'Fretes Horizonte LTDA', 'cpf_cnpj' => '45.723.174/0001-10', 'contact_numbers' => [['value' => '+55 (62) 99888-7766', 'label' => 'WhatsApp']]]);
    $veiculo = $this->makeVehicle($pessoa->id, ['placa' => 'QWE4R56']);
    $outra = $this->makePerson(['name' => 'Outra Empresa SA', 'cpf_cnpj' => '11.444.777/0001-61', 'contact_numbers' => [['value' => '+55 (11) 91111-2222', 'label' => 'WhatsApp']]]);

    $this->actingAs(adminDeAjustes($this), 'user');

    $busca = fn (string $termo) => $this->get(route('admin.clientes.busca', ['q' => $termo]));

    $busca('horizonte')->assertOk()->assertSee('Fretes Horizonte LTDA')->assertDontSee('Outra Empresa SA')->assertSee('QWE4R56');
    $busca('45723174')->assertSee('Fretes Horizonte LTDA')->assertDontSee('Outra Empresa SA');
    $busca('99888-7766')->assertSee('Fretes Horizonte LTDA');
    $busca('62 99888')->assertSee('Fretes Horizonte LTDA');
    $busca('qwe-4r56')->assertSee('Fretes Horizonte LTDA');
    $busca('91111')->assertSee('Outra Empresa SA')->assertDontSee('Fretes Horizonte LTDA');

    // Os botões levam ao Cadastro do cliente (Nova OS) e à ficha da pessoa.
    $busca('horizonte')
        ->assertSee('Nova OS')
        ->assertSee('Ver ficha')
        ->assertSee(route('admin.contacts.persons.view', $pessoa->id), false);
});

it('Buscar cliente sem resultado oferece "Cadastrar cliente" já com o que foi digitado', function () {
    $this->actingAs(adminDeAjustes($this), 'user');

    $this->get(route('admin.clientes.busca'))->assertOk()->assertSee('Buscar cliente')->assertSee('Cadastrar cliente');

    $this->get(route('admin.clientes.busca', ['q' => 'x']))->assertOk()->assertSee('pelo menos 2 caracteres');

    $semNome = $this->get(route('admin.clientes.busca', ['q' => 'Nome Inexistente Zeta']));

    $semNome->assertOk()
        ->assertSee('Nenhum cliente encontrado para "Nome Inexistente Zeta"', false)
        ->assertSee(route('admin.service_orders.cadastro.create', ['name' => 'Nome Inexistente Zeta']), false);

    $this->get(route('admin.clientes.busca', ['q' => '123.456.789-09']))
        ->assertSee(route('admin.service_orders.cadastro.create', ['cpf_cnpj' => '123.456.789-09']), false);

    $this->get(route('admin.clientes.busca', ['q' => '(62) 99999-0000']))
        ->assertSee(route('admin.service_orders.cadastro.create', ['whatsapp' => '(62) 99999-0000']), false);

    $this->get(route('admin.clientes.busca', ['q' => 'abc1d23']))
        ->assertSee(route('admin.service_orders.cadastro.create', ['placa' => 'ABC1D23']), false);
});

it('Buscar cliente é uma aba do menu, liberada a quem abre o Cadastro do cliente e fechada para quem não tem a permissão', function () {
    $consultor = $this->makeUser('consultor', ['clientes_busca', 'cadastro']);

    $this->actingAs($consultor, 'user')->get(route('admin.clientes.busca'))->assertOk();

    $semAcesso = $this->makeUser('tecnico', ['service_orders']);

    $this->actingAs($semAcesso, 'user')->get(route('admin.clientes.busca'))->assertForbidden();

    $itens = collect(config('menu.admin'))->pluck('key')->all();

    expect($itens)->toContain('clientes_busca');

    // O Proprietário tem todas as permissões; Gerente e Consultor recebem a chave do menu.
    foreach (['gerente', 'consultor'] as $perfil) {
        expect(PerfilCatalog::permissoes([$perfil])['permissions'] ?? [])->toContain('clientes_busca');
    }
});
