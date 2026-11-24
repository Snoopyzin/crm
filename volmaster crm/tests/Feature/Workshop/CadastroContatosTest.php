<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Webkul\Contact\Models\Person;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\ClienteContatos;

function dadosDoCadastro(array $extra = []): array
{
    return array_merge([
        'name' => 'Transportadora Boa Viagem Ltda',
        'cpf_cnpj' => '11.222.333/0001-81',
        'whatsapp' => '(62) 99999-1111',
    ], $extra);
}

it('o formulário mostra Nome / Razão Social, os três telefones com responsável e os campos de endereço', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'cadastro']);

    $this->actingAs($user, 'user')
        ->get(route('admin.service_orders.cadastro.create'))
        ->assertOk()
        ->assertSee('Nome / Razão Social')
        ->assertSee('Telefone fixo')
        ->assertSee('2º telefone para contato')
        ->assertSee('Nome do responsável por esse contato')
        ->assertSee('name="whatsapp_responsavel"', false)
        ->assertSee('name="telefone_fixo_responsavel"', false)
        ->assertSee('name="telefone_2_responsavel"', false)
        ->assertSee('name="cep"', false)
        ->assertSee('name="logradouro"', false)
        ->assertSee('name="numero"', false)
        ->assertSee('name="complemento"', false)
        ->assertSee('name="bairro"', false)
        ->assertSee('name="cidade"', false)
        ->assertSee('name="estado"', false);
});

it('grava WhatsApp, fixo e 2º telefone, cada um com o nome do responsável, e o endereço completo', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);

    $this->actingAs($user, 'user')->post(route('admin.service_orders.cadastro.store'), dadosDoCadastro([
        'whatsapp_responsavel' => 'João (frota)',
        'telefone_fixo' => '6232224444',
        'telefone_fixo_responsavel' => 'Dona Maria',
        'telefone_2' => '+55 (62) 98888-2222',
        'cep' => '74000-000',
        'logradouro' => 'Rua das Oficinas',
        'numero' => '120',
        'complemento' => 'Galpão 3',
        'bairro' => 'Setor Industrial',
        'cidade' => 'Goiânia',
        'estado' => 'go',
    ]))->assertRedirect();

    $pessoa = Person::where('name', 'Transportadora Boa Viagem Ltda')->firstOrFail();

    expect($pessoa->contact_numbers)->toBe([
        ['value' => '+55 (62) 99999-1111', 'label' => 'WhatsApp', 'responsavel' => 'João (frota)'],
        ['value' => '+55 (62) 3222-4444', 'label' => 'Fixo', 'responsavel' => 'Dona Maria'],
        ['value' => '+55 (62) 98888-2222', 'label' => 'Telefone 2'],
    ])->and(ClienteContatos::enderecoDe($pessoa->id))->toBe([
        'address' => 'Rua das Oficinas',
        'numero' => '120',
        'complemento' => 'Galpão 3',
        'bairro' => 'Setor Industrial',
        'city' => 'Goiânia',
        'state' => 'GO',
        'postcode' => '74000-000',
        'country' => 'BR',
    ]);
});

it('só o WhatsApp é obrigatório: sem fixo, sem 2º telefone e sem endereço o cadastro passa', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);

    $this->actingAs($user, 'user')->post(route('admin.service_orders.cadastro.store'), dadosDoCadastro())->assertRedirect();

    $pessoa = Person::where('name', 'Transportadora Boa Viagem Ltda')->firstOrFail();

    expect($pessoa->contact_numbers)->toBe([['value' => '+55 (62) 99999-1111', 'label' => 'WhatsApp']])
        ->and(ClienteContatos::enderecoDe($pessoa->id))->toBeNull();
});

it('recusa telefone, CEP e estado inválidos, sem criar nada', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);

    $this->actingAs($user, 'user')
        ->post(route('admin.service_orders.cadastro.store'), dadosDoCadastro([
            'telefone_fixo' => '12345',
            'telefone_2' => 'abc',
            'cep' => '123',
            'estado' => 'XX',
        ]))
        ->assertSessionHasErrors(['telefone_fixo', 'telefone_2', 'cep', 'estado']);

    expect(Person::where('name', 'Transportadora Boa Viagem Ltda')->exists())->toBeFalse()
        ->and(ServiceOrder::count())->toBe(0);
});

it('número repetido no WhatsApp e no 2º telefone entra uma vez só, com o WhatsApp em primeiro', function () {
    expect(ClienteContatos::numeros([
        'whatsapp' => '(62) 99999-1111',
        'telefone_fixo' => '',
        'telefone_2' => '+55 62 99999-1111',
        'telefone_2_responsavel' => 'Pedro',
    ]))->toBe([['value' => '+55 (62) 99999-1111', 'label' => 'WhatsApp']]);
});

it('cliente que volta ganha só os telefones novos e o endereço que faltava, sem perder nada do que já tinha', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);

    $this->actingAs($user, 'user')->post(route('admin.service_orders.cadastro.store'), dadosDoCadastro([
        'whatsapp_responsavel' => 'João',
    ]))->assertRedirect();

    $this->post(route('admin.service_orders.cadastro.store'), dadosDoCadastro([
        'name' => 'Outro nome digitado',
        'whatsapp_responsavel' => 'Outro responsável',
        'telefone_fixo' => '(62) 3222-4444',
        'cep' => '74000-000',
        'logradouro' => 'Rua das Oficinas',
        'numero' => '120',
        'cidade' => 'Goiânia',
        'estado' => 'GO',
    ]))->assertRedirect();

    expect(Person::count())->toBe(1);

    $pessoa = Person::first();

    expect($pessoa->name)->toBe('Transportadora Boa Viagem Ltda')
        ->and($pessoa->contact_numbers)->toBe([
            ['value' => '+55 (62) 99999-1111', 'label' => 'WhatsApp', 'responsavel' => 'João'],
            ['value' => '+55 (62) 3222-4444', 'label' => 'Fixo'],
        ])
        ->and(ClienteContatos::enderecoDe($pessoa->id)['address'])->toBe('Rua das Oficinas')
        ->and(ServiceOrder::count())->toBe(2);

    // Um terceiro cadastro com outro endereço não sobrescreve o que já está salvo.
    $this->post(route('admin.service_orders.cadastro.store'), dadosDoCadastro([
        'cep' => '01000-000', 'logradouro' => 'Avenida Nova', 'cidade' => 'São Paulo', 'estado' => 'SP',
    ]))->assertRedirect();

    expect(ClienteContatos::enderecoDe(Person::first()->id)['address'])->toBe('Rua das Oficinas');
});

it('o CEP preenche rua, bairro, cidade e estado (ViaCEP) e fica guardado', function () {
    Cache::forget('cep:74000000');

    Http::fake([
        'viacep.com.br/*' => Http::response([
            'cep' => '74000-000', 'logradouro' => 'Rua das Oficinas', 'bairro' => 'Setor Industrial', 'localidade' => 'Goiânia', 'uf' => 'GO',
        ]),
    ]);

    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);

    $this->actingAs($user, 'user')
        ->getJson(route('admin.service_orders.cadastro.cep', ['cep' => '74000-000']))
        ->assertOk()
        ->assertExactJson(['encontrado' => true, 'logradouro' => 'Rua das Oficinas', 'bairro' => 'Setor Industrial', 'cidade' => 'Goiânia', 'estado' => 'GO']);

    // Segunda consulta do mesmo CEP não chama o serviço de novo.
    $this->getJson(route('admin.service_orders.cadastro.cep', ['cep' => '74000000']))->assertOk();

    Http::assertSentCount(1);
});

it('CEP inexistente e CEP malformado avisam para preencher à mão', function () {
    Cache::forget('cep:99999999');

    Http::fake(['viacep.com.br/*' => Http::response(['erro' => true])]);

    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);
    $this->actingAs($user, 'user');

    $this->getJson(route('admin.service_orders.cadastro.cep', ['cep' => '99999999']))
        ->assertOk()
        ->assertJson(['encontrado' => false])
        ->assertJsonFragment(['mensagem' => 'CEP não encontrado. Confira o número ou preencha o endereço à mão.']);

    $this->getJson(route('admin.service_orders.cadastro.cep', ['cep' => '123']))
        ->assertStatus(422)
        ->assertJson(['encontrado' => false]);
});

it('com o ViaCEP e a BrasilAPI fora do ar, a consulta falha sem derrubar o formulário', function () {
    Cache::forget('cep:88888888');

    Http::fake([
        'viacep.com.br/*' => Http::response('', 503),
        'brasilapi.com.br/*' => Http::response('', 503),
    ]);

    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);

    $this->actingAs($user, 'user')
        ->getJson(route('admin.service_orders.cadastro.cep', ['cep' => '88888888']))
        ->assertOk()
        ->assertJson(['encontrado' => false])
        ->assertJsonFragment(['mensagem' => 'Não foi possível consultar o CEP agora. Preencha o endereço à mão.']);
});

it('se o ViaCEP falhar, a BrasilAPI responde no lugar', function () {
    Cache::forget('cep:74000000');

    Http::fake([
        'viacep.com.br/*' => Http::response('', 500),
        'brasilapi.com.br/*' => Http::response(['cep' => '74000000', 'state' => 'GO', 'city' => 'Goiânia', 'neighborhood' => 'Setor Industrial', 'street' => 'Rua das Oficinas']),
    ]);

    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);

    $this->actingAs($user, 'user')
        ->getJson(route('admin.service_orders.cadastro.cep', ['cep' => '74000000']))
        ->assertOk()
        ->assertJson(['encontrado' => true, 'logradouro' => 'Rua das Oficinas', 'cidade' => 'Goiânia', 'estado' => 'GO']);
});

it('a impressão e a tela da OS mostram os telefones com responsável e o endereço', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create', 'service_orders.view']);

    $this->actingAs($user, 'user')->post(route('admin.service_orders.cadastro.store'), dadosDoCadastro([
        'whatsapp_responsavel' => 'João',
        'telefone_fixo' => '6232224444',
        'telefone_fixo_responsavel' => 'Dona Maria',
        'cep' => '74000-000',
        'logradouro' => 'Rua das Oficinas',
        'numero' => '120',
        'complemento' => 'Galpão 3',
        'bairro' => 'Setor Industrial',
        'cidade' => 'Goiânia',
        'estado' => 'GO',
    ]))->assertRedirect();

    $os = ServiceOrder::firstOrFail();

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('+55 (62) 99999-1111 (João, WhatsApp)')
        ->assertSee('+55 (62) 3222-4444 (Dona Maria, Fixo)')
        ->assertSee('Rua das Oficinas, 120 - Galpão 3 - Setor Industrial')
        ->assertSee('74000-000');

    // A impressão sai em PDF: confere que ela continua sendo gerada com os dados novos.
    $impressao = $this->get(route('admin.service_orders.print', $os->id))->assertOk();

    expect($impressao->headers->get('content-type'))->toContain('pdf');

    // E as linhas que ela imprime (mesmas funções da tela).
    $pessoa = Person::firstOrFail();

    expect(ClienteContatos::linhaTelefones($pessoa->contact_numbers))
        ->toBe('+55 (62) 99999-1111 (João, WhatsApp) / +55 (62) 3222-4444 (Dona Maria, Fixo)')
        ->and(ClienteContatos::linhaEndereco(ClienteContatos::enderecoDe($pessoa->id)))
        ->toBe('Rua das Oficinas, 120 - Galpão 3 - Setor Industrial');
});
