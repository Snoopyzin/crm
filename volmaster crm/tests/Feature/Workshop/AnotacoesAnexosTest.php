<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderExtra;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

// O "+" da OS (pedido do patrão, 19/11/2026): anotação, fotos, vídeos e outros arquivos em qualquer tela da OS.

function usuarioAdminDeAnexos(): User
{
    $role = Role::create(['name' => 'Admin anexos '.uniqid(), 'description' => 'x', 'permission_type' => 'all', 'permissions' => null]);

    return User::create([
        'name' => 'Admin anexos',
        'email' => uniqid('anexos').'@teste.local',
        'password' => bcrypt('senha-teste'),
        'status' => 1,
        'role_id' => $role->id,
        'view_permission' => 'global',
    ]);
}

function dadosDoExtra(array $extra = []): array
{
    return array_merge([
        'extra_titulo' => 'Vazamento no cárter',
        'extra_texto' => 'O motorista mandou as fotos pelo WhatsApp.',
        'extra_fotos' => [UploadedFile::fake()->image('carter.jpg'), UploadedFile::fake()->image('junta.png')],
        'extra_videos' => [UploadedFile::fake()->create('barulho.mp4', 300, 'video/mp4')],
        'extra_arquivos' => [UploadedFile::fake()->create('orcamento-fornecedor.pdf', 120, 'application/pdf')],
    ], $extra);
}

it('a tela da OS tem a área "Anotações e anexos" com o botão + e o formulário fechado', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    $this->actingAs(usuarioAdminDeAnexos(), 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('Anotações e anexos (0)')
        ->assertSee('id="extras-mais"', false)
        ->assertSee('Nada adicionado ainda')
        // O botão tem texto claro (não é mais só um "+" no canto): "+ Adicionar anotação ou anexo".
        ->assertSee('+ Adicionar anotação ou anexo')
        ->assertSee('Clique no botão azul abaixo')
        ->assertSee('name="extra_fotos[]"', false)
        ->assertSee('name="extra_videos[]"', false)
        ->assertSee('name="extra_arquivos[]"', false)
        ->assertSee('enctype="multipart/form-data"', false)
        ->assertSee('id="extras-form"', false);
});

it('o + grava anotação, fotos, vídeos e arquivos na OS e tudo aparece na tela', function () {
    Storage::fake('public');

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    $this->actingAs(usuarioAdminDeAnexos(), 'user');

    $this->post(route('admin.service_orders.extras.store', $os->id), dadosDoExtra())->assertRedirect();

    $extra = ServiceOrderExtra::where('quote_id', $os->id)->firstOrFail();

    expect($extra->titulo)->toBe('Vazamento no cárter')
        ->and($extra->texto)->toBe('O motorista mandou as fotos pelo WhatsApp.')
        ->and(collect($extra->arquivos)->countBy('tipo')->all())->toBe(['foto' => 2, 'video' => 1, 'arquivo' => 1])
        ->and($extra->user_id)->not->toBeNull();

    foreach ($extra->arquivos as $arquivo) {
        Storage::disk('public')->assertExists($arquivo['path']);
    }

    $evento = ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'extra_adicionado')->firstOrFail();

    expect($evento->dados_json['fotos'])->toBe(2)->and($evento->dados_json['videos'])->toBe(1)->and($evento->dados_json['arquivos'])->toBe(1);

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertSee('Anotações e anexos (1)')
        ->assertSee('Vazamento no cárter')
        ->assertSee('O motorista mandou as fotos pelo WhatsApp.')
        ->assertSee('carter.jpg')
        ->assertSee('<video', false)
        ->assertSee('orcamento-fornecedor.pdf')
        ->assertSee('Admin anexos');
});

it('serve só com texto, só com foto, e não grava nada quando vem tudo vazio', function () {
    Storage::fake('public');

    $os = $this->makeOs();

    $this->actingAs(usuarioAdminDeAnexos(), 'user');

    $this->post(route('admin.service_orders.extras.store', $os->id), ['extra_texto' => 'Só uma observação.'])->assertRedirect();
    $this->post(route('admin.service_orders.extras.store', $os->id), ['extra_fotos' => [UploadedFile::fake()->image('so-foto.jpg')]])->assertRedirect();

    expect(ServiceOrderExtra::where('quote_id', $os->id)->count())->toBe(2);

    $this->post(route('admin.service_orders.extras.store', $os->id), ['extra_titulo' => '   ', 'extra_texto' => ''])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(ServiceOrderExtra::where('quote_id', $os->id)->count())->toBe(2);
});

it('recusa arquivo de tipo errado, grande demais e quantidade acima do limite, sem criar nada', function () {
    Storage::fake('public');

    $os = $this->makeOs();

    $this->actingAs(usuarioAdminDeAnexos(), 'user');

    $this->post(route('admin.service_orders.extras.store', $os->id), ['extra_arquivos' => [UploadedFile::fake()->create('programa.exe', 10, 'application/octet-stream')]])
        ->assertSessionHasErrors('extra_arquivos.0');

    $this->post(route('admin.service_orders.extras.store', $os->id), ['extra_fotos' => [UploadedFile::fake()->create('nao-e-foto.pdf', 10, 'application/pdf')]])
        ->assertSessionHasErrors('extra_fotos.0');

    $this->post(route('admin.service_orders.extras.store', $os->id), ['extra_fotos' => [UploadedFile::fake()->image('gigante.jpg')->size(11000)]])
        ->assertSessionHasErrors('extra_fotos.0');

    $this->post(route('admin.service_orders.extras.store', $os->id), ['extra_videos' => [UploadedFile::fake()->create('enorme.mp4', 110000, 'video/mp4')]])
        ->assertSessionHasErrors('extra_videos.0');

    $fotos = collect(range(1, 21))->map(fn ($i) => UploadedFile::fake()->image("f{$i}.jpg"))->all();

    $this->post(route('admin.service_orders.extras.store', $os->id), ['extra_fotos' => $fotos])->assertSessionHasErrors('extra_fotos');

    expect(ServiceOrderExtra::count())->toBe(0);
});

it('quem escreveu remove a própria anotação (os arquivos saem junto); outro usuário sem permissão de excluir leva 403', function () {
    Storage::fake('public');

    $os = $this->makeOs();

    $autor = $this->makeUser('consultor', ['service_orders', 'service_orders.view', 'service_orders.edit']);
    $outro = $this->makeUser('consultor', ['service_orders', 'service_orders.view', 'service_orders.edit']);

    $this->actingAs($autor, 'user')->post(route('admin.service_orders.extras.store', $os->id), dadosDoExtra())->assertRedirect();

    $extra = ServiceOrderExtra::firstOrFail();
    $caminhos = collect($extra->arquivos)->pluck('path');

    $this->actingAs($outro, 'user')->delete(route('admin.service_orders.extras.destroy', [$os->id, $extra->id]))->assertForbidden();

    expect(ServiceOrderExtra::count())->toBe(1);

    $this->actingAs($autor, 'user')->delete(route('admin.service_orders.extras.destroy', [$os->id, $extra->id]))->assertRedirect();

    expect(ServiceOrderExtra::count())->toBe(0);

    foreach ($caminhos as $caminho) {
        Storage::disk('public')->assertMissing($caminho);
    }

    // Quem tem permissão de excluir OS (o administrador) remove a de qualquer pessoa.
    $this->actingAs($autor, 'user')->post(route('admin.service_orders.extras.store', $os->id), ['extra_texto' => 'Outra anotação'])->assertRedirect();

    $this->actingAs(usuarioAdminDeAnexos(), 'user')->delete(route('admin.service_orders.extras.destroy', [$os->id, ServiceOrderExtra::firstOrFail()->id]))->assertRedirect();

    expect(ServiceOrderExtra::count())->toBe(0);
});

it('só quem edita OS usa o +, e a regra de visibilidade da OS vale também aqui', function () {
    $os = $this->makeOs();

    $semEditar = $this->makeUser('consultor', ['service_orders', 'service_orders.view']);

    $this->actingAs($semEditar, 'user')->post(route('admin.service_orders.extras.store', $os->id), ['extra_texto' => 'x'])->assertForbidden();

    // Visibilidade "individual": a OS foi aberta por outro usuário e não é deste mecânico.
    $restrito = $this->makeUser('consultor', ['service_orders', 'service_orders.view', 'service_orders.edit'], ['view_permission' => 'individual']);

    $this->actingAs($restrito, 'user')->post(route('admin.service_orders.extras.store', $os->id), ['extra_texto' => 'x'])->assertForbidden();

    expect(ServiceOrderExtra::count())->toBe(0);
});

it('os formulários de criar e editar a OS têm o + com os campos de anexo e gravam junto com a OS', function () {
    Storage::fake('public');

    $os = $this->makeOs(['subject' => 'Ordem de Serviço - ABC1D23']);

    $this->actingAs(usuarioAdminDeAnexos(), 'user');

    $this->get(route('admin.service_orders.create'))
        ->assertOk()
        ->assertSee('Anotações e anexos (opcional)')
        ->assertSee('id="extras-mais-form"', false)
        ->assertSee('name="extra_fotos[]"', false)
        ->assertSee('enctype="multipart/form-data"', false);

    $this->get(route('admin.service_orders.edit', $os->id))
        ->assertOk()
        ->assertSee('id="extras-mais-form"', false)
        ->assertSee('name="extra_videos[]"', false)
        ->assertSee('enctype="multipart/form-data"', false);

    // Editar a OS e juntar uma foto e uma anotação no mesmo envio.
    $this->put(route('admin.service_orders.update', $os->id), [
        'person_id' => $os->person_id,
        'vehicle_id' => $os->vehicle_id,
        'servico' => 'Ordem de Serviço - ABC1D23',
        'extra_texto' => 'Foto do painel com a luz acesa.',
        'extra_fotos' => [UploadedFile::fake()->image('painel.jpg')],
    ])->assertRedirect(route('admin.service_orders.view', $os->id));

    expect(ServiceOrderExtra::where('quote_id', $os->id)->count())->toBe(1)
        ->and(ServiceOrderExtra::firstOrFail()->texto)->toBe('Foto do painel com a luz acesa.');

    // Criar uma OS nova já com anexo.
    $this->post(route('admin.service_orders.store'), [
        'person_id' => $os->person_id,
        'vehicle_id' => $os->vehicle_id,
        'servico' => 'Revisão com anexo',
        'extra_titulo' => 'Laudo do cliente',
        'extra_arquivos' => [UploadedFile::fake()->create('laudo.pdf', 50, 'application/pdf')],
    ])->assertRedirect();

    $nova = ServiceOrder::query()->where('subject', 'Revisão com anexo')->firstOrFail();
    $extra = ServiceOrderExtra::where('quote_id', $nova->id)->firstOrFail();

    expect($extra->titulo)->toBe('Laudo do cliente')
        ->and($extra->arquivos[0]['tipo'])->toBe('arquivo')
        ->and($nova->subject)->toBe('Revisão com anexo');
});

it('salvar a OS sem preencher o + não cria anotação, e os formulários antigos (sem os campos) seguem funcionando', function () {
    $os = $this->makeOs(['subject' => 'Ordem de Serviço - XYZ9K88']);

    $this->actingAs(usuarioAdminDeAnexos(), 'user');

    $this->put(route('admin.service_orders.update', $os->id), [
        'person_id' => $os->person_id,
        'vehicle_id' => $os->vehicle_id,
        'servico' => 'Ordem de Serviço - XYZ9K88',
        'description' => 'Sem anexos desta vez',
    ])->assertRedirect();

    expect(ServiceOrderExtra::count())->toBe(0)
        ->and($os->refresh()->description)->toBe('Sem anexos desta vez');
});
