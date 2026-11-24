<?php

use Illuminate\Support\Facades\DB;
use Webkul\Contact\Models\Person;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\Mechanic\Repositories\MechanicRepository;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\CodigoSequencial;

function novoCliente(object $teste, array $extra = []): void
{
    static $n = 0;
    $n++;

    $teste->post(route('admin.service_orders.cadastro.store'), array_merge([
        'name' => "Cliente {$n}",
        'cpf_cnpj' => sprintf('%011d', 10000000000 + $n * 7919),
        'whatsapp' => sprintf('(62) 9%04d-%04d', 1000 + $n, 2000 + $n),
    ], $extra))->assertRedirect();
}

function novoMecanico(string $nome): Mechanic
{
    return app(MechanicRepository::class)->create(['nome' => $nome, 'especialidade' => 'motor', 'status' => 'Ativo']);
}

it('cada cliente novo recebe o próximo código, de 1 em diante, sem repetir', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);
    $this->actingAs($user, 'user');

    novoCliente($this);
    novoCliente($this);
    novoCliente($this);

    $codigos = Person::orderBy('id')->pluck('codigo')->all();

    expect($codigos)->toBe([1, 2, 3])
        ->and(DB::table('persons')->distinct()->count('codigo'))->toBe(3)
        ->and(DB::table('codigo_sequencias')->where('nome', 'clientes')->value('ultimo'))->toBe(3);
});

it('o código de quem foi excluído não é reaproveitado', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);
    $this->actingAs($user, 'user');

    novoCliente($this);
    novoCliente($this);

    Person::where('codigo', 2)->firstOrFail()->delete();

    novoCliente($this);

    expect(Person::orderBy('id')->pluck('codigo')->all())->toBe([1, 3]);
});

it('o cadastro avisa o código do cliente novo e, se o cliente já existia, o código que ele já tinha', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);
    $this->actingAs($user, 'user');

    $dados = ['name' => 'Transportadora Boa Viagem', 'cpf_cnpj' => '11.222.333/0001-81', 'whatsapp' => '(62) 99999-1111'];

    $this->post(route('admin.service_orders.cadastro.store'), $dados)
        ->assertSessionHas('success', 'Cadastro criado com sucesso. Código do cliente: Nº 1.');

    $this->post(route('admin.service_orders.cadastro.store'), $dados)
        ->assertSessionHas('success', 'Cadastro criado com sucesso. Cliente já cadastrado: Nº 1.');

    expect(Person::count())->toBe(1)
        ->and(ServiceOrder::count())->toBe(2);
});

it('a tela de cadastro mostra o próximo código e o tamanho da carteira', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create', 'cadastro']);
    $this->actingAs($user, 'user');

    $this->get(route('admin.service_orders.cadastro.create'))
        ->assertOk()
        ->assertSee('Este será o cliente')
        ->assertSee('Nº 1', false)
        ->assertSee('Carteira hoje:');

    novoCliente($this);
    novoCliente($this);

    $html = $this->get(route('admin.service_orders.cadastro.create'))->assertOk()->getContent();

    expect($html)->toContain('Nº 3')
        ->and(preg_replace('/\s+/', ' ', $html))->toContain('<strong>2</strong> clientes');
});

it('a tela e a impressão da OS mostram o código do cliente', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create', 'service_orders.view']);
    $this->actingAs($user, 'user');

    novoCliente($this);

    $os = ServiceOrder::firstOrFail();

    $this->get(route('admin.service_orders.view', $os->id))->assertOk()->assertSee('Nº 1', false);
    $this->get(route('admin.service_orders.print', $os->id))->assertOk();
});

it('quem já estava cadastrado é numerado na ordem de entrada e o contador continua dali', function () {
    // Clientes e mecânicos de antes da numeração (sem código).
    foreach (['Antigo A', 'Antigo B', 'Antigo C'] as $nome) {
        DB::table('persons')->insert(['name' => $nome, 'emails' => '[]', 'contact_numbers' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    }

    foreach (['Mec A', 'Mec B'] as $nome) {
        DB::table('mechanics')->insert(['nome' => $nome, 'especialidade' => 'motor', 'status' => 'Ativo', 'created_at' => now(), 'updated_at' => now()]);
    }

    expect(DB::table('persons')->whereNull('codigo')->count())->toBe(3);

    (require base_path('packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_17_120000_add_codigo_to_persons_and_mechanics.php'))->up();

    expect(Person::orderBy('id')->pluck('name', 'codigo')->all())->toBe([1 => 'Antigo A', 2 => 'Antigo B', 3 => 'Antigo C'])
        ->and(Mechanic::orderBy('id')->pluck('nome', 'codigo')->all())->toBe([1 => 'Mec A', 2 => 'Mec B'])
        ->and(DB::table('codigo_sequencias')->pluck('ultimo', 'nome')->all())->toBe(['clientes' => 3, 'mecanicos' => 2]);

    // Rodar de novo não renumera ninguém.
    (require base_path('packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_17_120000_add_codigo_to_persons_and_mechanics.php'))->up();

    expect(Person::orderBy('id')->pluck('codigo')->all())->toBe([1, 2, 3]);

    // E o próximo cadastro segue a contagem.
    $novo = $this->makePerson(['name' => 'Cliente Novo', 'cpf_cnpj' => '529.982.247-25']);

    expect($novo->refresh()->codigo)->toBe(4)
        ->and(novoMecanico('Mec C')->codigo)->toBe(3);
});

it('o contador nunca volta atrás de um código que já existe', function () {
    DB::table('persons')->insert(['name' => 'Importado', 'codigo' => 50, 'emails' => '[]', 'contact_numbers' => '[]', 'created_at' => now(), 'updated_at' => now()]);

    expect(CodigoSequencial::proximo('clientes', 'persons'))->toBe(51)
        ->and(CodigoSequencial::proximo('clientes', 'persons'))->toBe(52);
});

it('cada mecânico recebe um código, o código aparece nas telas e não pode ser editado', function () {
    $gestor = $this->makeUser('gerente');
    $this->actingAs($gestor, 'user');

    $primeiro = novoMecanico('Carlos');
    $segundo = novoMecanico('Marcos');

    expect($primeiro->codigo)->toBe(1)
        ->and($segundo->codigo)->toBe(2);

    $this->get(route('admin.mechanics.view', $segundo->id))->assertOk()->assertSee('Nº 2', false);
    $this->get(route('admin.mechanics.edit', $segundo->id))->assertOk()->assertSee('Código do mecânico', false)->assertSee('Nº 2', false);
    $this->get(route('admin.mechanics.create'))->assertOk()->assertSee('gerado automaticamente ao salvar');

    // Tentar mudar o código pela edição não muda nada.
    $this->put(route('admin.mechanics.update', $segundo->id), [
        'nome' => 'Marcos Silva', 'especialidade' => 'motor', 'status' => 'Ativo', 'codigo' => 99,
    ])->assertRedirect();

    expect(Mechanic::find($segundo->id)->nome)->toBe('Marcos Silva')
        ->and(Mechanic::find($segundo->id)->codigo)->toBe(2);

    // Criar pela tela avisa o código no aviso de sucesso.
    $this->post(route('admin.mechanics.store'), ['nome' => 'Pedro', 'especialidade' => 'freios', 'status' => 'Ativo'])
        ->assertSessionHas('success', 'Mecânico criado com sucesso. Código do mecânico: Nº 3.');
});

it('as listas de mecânicos e de clientes trazem a coluna Código', function () {
    $gestor = $this->makeUser('gerente');
    $this->actingAs($gestor, 'user');

    novoMecanico('Carlos');
    novoCliente($this);

    foreach ([route('admin.mechanics.index'), route('admin.contacts.persons.index')] as $url) {
        $dados = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->get($url)->assertOk()->json();

        $coluna = collect($dados['columns'])->firstWhere('index', 'codigo');

        expect($coluna['label'])->toBe('Código')
            ->and(collect($dados['records'])->pluck('codigo')->all())->toContain(1);
    }
});
