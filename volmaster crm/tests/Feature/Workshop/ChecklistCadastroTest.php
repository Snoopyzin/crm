<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderAttachment;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Services\StageGate;
use Webkul\Vehicle\Models\Vehicle;

function dadosComVeiculo(array $extra = []): array
{
    return array_merge([
        'name' => 'Transportadora Boa Viagem',
        'cpf_cnpj' => '11.222.333/0001-81',
        'whatsapp' => '(62) 99999-1111',
        'placa' => 'ABC1D23',
        'marca' => 'Scania',
        'modelo' => 'R450',
    ], $extra);
}

it('o Novo Cadastro mostra o checklist com km, combustível, chassi, descrição, itens, fotos e vídeos', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'cadastro']);

    $this->actingAs($user, 'user')
        ->get(route('admin.service_orders.cadastro.create'))
        ->assertOk()
        ->assertSee('Checklist de entrada do veículo')
        ->assertSee('name="km_entrada"', false)
        ->assertSee('name="combustivel_entrada"', false)
        ->assertSee('name="chassi"', false)
        ->assertSee('name="descricao_entrada"', false)
        ->assertSee('name="itens_entrada"', false)
        ->assertSee('name="fotos[]"', false)
        ->assertSee('name="videos[]"', false)
        ->assertSee('accept="video/*"', false)
        ->assertSee('enctype="multipart/form-data"', false)
        ->assertSee('Quantidade de combustível')
        ->assertSee('Cheio');
});

it('grava o checklist: km, combustível, itens, descrição e o chassi no veículo', function () {
    Storage::fake('public');

    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);

    $this->actingAs($user, 'user')->post(route('admin.service_orders.cadastro.store'), dadosComVeiculo([
        'chassi' => '9BSR4X2B0F3891234',
        'km_entrada' => 482350,
        'combustivel_entrada' => '3/4',
        'descricao_entrada' => 'Cavalo mecânico branco, arranhão na porta direita.',
        'itens_entrada' => 'Estepe, macaco e extintor.',
    ]))->assertRedirect();

    $os = ServiceOrder::firstOrFail();
    $veiculo = Vehicle::firstOrFail();

    expect($os->km_entrada)->toBe(482350)
        ->and($os->combustivel_entrada)->toBe('3/4')
        ->and($os->descricao_entrada)->toBe('Cavalo mecânico branco, arranhão na porta direita.')
        ->and($os->itens_entrada)->toBe('Estepe, macaco e extintor.')
        ->and($veiculo->chassi)->toBe('9BSR4X2B0F3891234')
        ->and($veiculo->km)->toBe(482350)
        ->and($os->termo_assinado_em)->toBeNull()
        ->and($os->autorizacao_rodagem)->toBeFalse();

    $evento = ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'checklist_entrada')->firstOrFail();

    expect($evento->dados_json['km'])->toBe(482350)
        ->and($evento->dados_json['combustivel'])->toBe('3/4');
});

it('guarda várias fotos e vários vídeos como anexos da OS (as fotos valem para o termo de entrada)', function () {
    Storage::fake('public');

    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);

    $this->actingAs($user, 'user')->post(route('admin.service_orders.cadastro.store'), dadosComVeiculo([
        'km_entrada' => 1000,
        'fotos' => [
            UploadedFile::fake()->create('frente.jpg', 200, 'image/jpeg'),
            UploadedFile::fake()->create('lateral.png', 200, 'image/png'),
            UploadedFile::fake()->create('painel.heic', 200, 'image/heic'),
        ],
        'videos' => [
            UploadedFile::fake()->create('volta-no-caminhao.mp4', 5000, 'video/mp4'),
            UploadedFile::fake()->create('motor-ligado.mov', 5000, 'video/quicktime'),
        ],
    ]))->assertRedirect()->assertSessionHas('success');

    $os = ServiceOrder::firstOrFail();
    $fotos = $os->attachments()->where('tipo', ServiceOrderAttachment::TIPO_TERMO_FOTO)->get();
    $videos = $os->attachments()->where('tipo', ServiceOrderAttachment::TIPO_TERMO_VIDEO)->get();

    expect($fotos)->toHaveCount(3)
        ->and($videos)->toHaveCount(2)
        ->and($videos->pluck('nome_original')->sort()->values()->all())->toBe(['motor-ligado.mov', 'volta-no-caminhao.mp4']);

    foreach ($fotos->merge($videos) as $anexo) {
        Storage::disk('public')->assertExists($anexo->path);
    }

    // O aviso depois de salvar conta os anexos.
    expect(session('success'))->toContain('Checklist com 3 fotos e 2 vídeos.');

    // Fotos e km já satisfazem as exigências do termo de entrada (só falta assinar e autorizar).
    $this->enablePilot();
    $faltando = app(StageGate::class)->missing($os->fresh(), ServiceOrder::STAGE_ORDEM_SERVICO);

    expect($faltando)->not->toContain('Km de entrada')
        ->and($faltando)->not->toContain('Termo de entrada: fotos do veículo')
        ->and($faltando)->toContain('Termo de entrada assinado');
});

it('o checklist é opcional: sem ele o cadastro funciona como antes', function () {
    Storage::fake('public');

    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);

    $this->actingAs($user, 'user')->post(route('admin.service_orders.cadastro.store'), dadosComVeiculo())->assertRedirect();

    $os = ServiceOrder::firstOrFail();

    expect($os->km_entrada)->toBeNull()
        ->and($os->descricao_entrada)->toBeNull()
        ->and($os->attachments()->count())->toBe(0)
        ->and(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'checklist_entrada')->exists())->toBeFalse();
});

it('recusa arquivo que não é foto ou vídeo, arquivo grande demais e quantidade acima do limite, sem criar nada', function () {
    Storage::fake('public');

    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);
    $this->actingAs($user, 'user');

    $this->post(route('admin.service_orders.cadastro.store'), dadosComVeiculo([
        'fotos' => [UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'), UploadedFile::fake()->create('enorme.jpg', 11000, 'image/jpeg')],
        'videos' => [UploadedFile::fake()->create('programa.exe', 100, 'application/octet-stream')],
    ]))->assertSessionHasErrors(['fotos.0', 'fotos.1', 'videos.0']);

    $this->post(route('admin.service_orders.cadastro.store'), dadosComVeiculo([
        'videos' => [UploadedFile::fake()->create('grande.mp4', 103000, 'video/mp4')],
    ]))->assertSessionHasErrors(['videos.0']);

    $this->post(route('admin.service_orders.cadastro.store'), dadosComVeiculo([
        'videos' => array_map(fn ($i) => UploadedFile::fake()->create("v{$i}.mp4", 100, 'video/mp4'), range(1, 4)),
    ]))->assertSessionHasErrors(['videos']);

    expect(ServiceOrder::count())->toBe(0)
        ->and(DB::table('service_order_attachments')->count())->toBe(0);
});

it('km negativo, combustível fora da lista e chassi sem placa são recusados', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);

    $this->actingAs($user, 'user')
        ->post(route('admin.service_orders.cadastro.store'), [
            'name' => 'Cliente', 'cpf_cnpj' => '11.222.333/0001-81', 'whatsapp' => '(62) 99999-1111',
            'km_entrada' => -5, 'combustivel_entrada' => 'Transbordando', 'chassi' => '9BSR4X2B0F3891234',
        ])
        ->assertSessionHasErrors(['km_entrada', 'combustivel_entrada', 'chassi']);

    expect(ServiceOrder::count())->toBe(0);
});

it('a tela da OS mostra o checklist: dados, fotos e vídeos; e a impressão continua saindo', function () {
    Storage::fake('public');

    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create', 'service_orders.view']);
    $this->actingAs($user, 'user');

    $this->post(route('admin.service_orders.cadastro.store'), dadosComVeiculo([
        'chassi' => '9BSR4X2B0F3891234',
        'km_entrada' => 482350,
        'combustivel_entrada' => '1/2',
        'descricao_entrada' => 'Cavalo mecânico branco.',
        'itens_entrada' => 'Estepe e macaco.',
        'fotos' => [UploadedFile::fake()->create('frente.jpg', 100, 'image/jpeg')],
        'videos' => [UploadedFile::fake()->create('volta.mp4', 1000, 'video/mp4')],
    ]))->assertRedirect();

    $os = ServiceOrder::firstOrFail();

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('Checklist de entrada do veículo')
        ->assertSee('482.350 km')
        ->assertSee('1/2')
        ->assertSee('9BSR4X2B0F3891234')
        ->assertSee('Cavalo mecânico branco.')
        ->assertSee('Estepe e macaco.')
        ->assertSee('Fotos (1)')
        ->assertSee('Vídeos (1)')
        ->assertSee('<video', false);

    expect($this->get(route('admin.service_orders.print', $os->id))->assertOk()->headers->get('content-type'))->toContain('pdf');

    // OS sem checklist não mostra o cartão.
    $outra = $this->makeOs();
    $this->get(route('admin.service_orders.view', $outra->id))->assertOk()->assertDontSee('Checklist de entrada do veículo');
});
