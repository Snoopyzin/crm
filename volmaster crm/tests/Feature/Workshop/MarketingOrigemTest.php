<?php

use Illuminate\Support\Facades\Route;
use Webkul\ServiceOrder\Models\ServiceOrder;

/**
 * CSV do Excel (BOM + ";") em linhas de colunas.
 *
 * @return array<int, array<int, string|null>>
 */
function linhasDoCsv(string $conteudo): array
{
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);

    return collect(preg_split('/\r?\n/', $conteudo))
        ->filter(fn ($linha) => $linha !== '')
        ->map(fn ($linha) => str_getcsv($linha, ';', '"', ''))
        ->values()
        ->all();
}

it('"Outdoor Rodovia" é uma das origens do cliente, perguntada no Novo Cadastro', function () {
    expect(ServiceOrder::ORIGENS)->toContain('Outdoor Rodovia');

    $user = $this->makeUser('consultor', ['service_orders', 'cadastro']);

    $this->actingAs($user, 'user')
        ->get(route('admin.service_orders.cadastro.create'))
        ->assertOk()
        ->assertSee('Outdoor Rodovia');
});

it('o cadastro grava a origem Outdoor Rodovia na OS', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);

    $this->actingAs($user, 'user')->post(route('admin.service_orders.cadastro.store'), [
        'cpf_cnpj' => '529.982.247-25',
        'whatsapp' => '+55 (62) 99999-1111',
        'origem_cliente' => 'Outdoor Rodovia',
    ])->assertRedirect();

    expect(ServiceOrder::where('origem_cliente', 'Outdoor Rodovia')->count())->toBe(1);
});

it('a origem só é perguntada no Novo Cadastro: a Nova OS e a tela da OS não têm mais o campo', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create', 'service_orders.view', 'service_orders.edit', 'cadastro']);
    $this->actingAs($user, 'user');

    $this->get(route('admin.service_orders.create'))
        ->assertOk()
        ->assertDontSee('Por qual meio o cliente chegou')
        ->assertDontSee('name="origem_cliente"', false);

    $os = $this->makeOs();
    $os->forceFill(['origem_cliente' => 'Instagram'])->save();

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertDontSee('Como chegou à empresa')
        ->assertDontSee('name="origem_cliente"', false);

    expect(Route::has('admin.service_orders.origem.update'))->toBeFalse();

    // O Novo Cadastro continua perguntando.
    $this->get(route('admin.service_orders.cadastro.create'))->assertOk()->assertSee('name="origem_cliente"', false);
});

it('o campo de descrição da OS se chama "Descrição do técnico" ao criar, editar e ver a OS (pedido do patrão, 19/11/2026)', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create', 'service_orders.view', 'service_orders.edit']);
    $this->actingAs($user, 'user');

    $os = $this->makeOs();

    $this->get(route('admin.service_orders.create'))->assertOk()->assertSee('Descrição do técnico')->assertDontSee('Descrição do Defeito');
    $this->get(route('admin.service_orders.edit', $os->id))->assertOk()->assertSee('Descrição do técnico')->assertDontSee('Descrição do Defeito');
    $this->get(route('admin.service_orders.view', $os->id))->assertOk()->assertSee('Descrição do técnico')->assertDontSee('Descrição do Defeito');
});

it('a Nova OS grava sem pedir a origem e herda a primeira origem registrada para o cliente', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);
    $this->actingAs($user, 'user');

    $cliente = $this->makePerson(['name' => 'Cliente do outdoor', 'cpf_cnpj' => '529.982.247-25']);
    $semHistorico = $this->makePerson(['name' => 'Cliente sem origem', 'cpf_cnpj' => '11.222.333/0001-81', 'contact_numbers' => [['value' => '+55 (62) 98888-0000', 'label' => 'WhatsApp']]]);

    $primeira = $this->makeOs(['person_id' => $cliente->id]);
    $primeira->forceFill(['origem_cliente' => 'Outdoor Rodovia'])->save();
    $this->makeOs(['person_id' => $cliente->id])->forceFill(['origem_cliente' => 'Instagram'])->save();

    $this->post(route('admin.service_orders.store'), ['person_id' => $cliente->id, 'servico' => 'Revisão', 'origem_cliente' => 'TikTok'])->assertRedirect();
    $this->post(route('admin.service_orders.store'), ['person_id' => $semHistorico->id, 'servico' => 'Revisão'])->assertRedirect();

    expect(ServiceOrder::where('person_id', $cliente->id)->orderByDesc('id')->value('origem_cliente'))->toBe('Outdoor Rodovia')
        ->and(ServiceOrder::where('person_id', $semHistorico->id)->value('origem_cliente'))->toBeNull();
});

it('cliente que volta ao Novo Cadastro sem repetir a origem mantém a que já tinha', function () {
    $user = $this->makeUser('consultor', ['service_orders', 'service_orders.create']);
    $this->actingAs($user, 'user');

    $dados = ['name' => 'Cliente fiel', 'cpf_cnpj' => '11.222.333/0001-81', 'whatsapp' => '(62) 99999-1111'];

    $this->post(route('admin.service_orders.cadastro.store'), $dados + ['origem_cliente' => 'Outdoor Rodovia'])->assertRedirect();
    $this->post(route('admin.service_orders.cadastro.store'), $dados)->assertRedirect();

    expect(ServiceOrder::pluck('origem_cliente')->all())->toBe(['Outdoor Rodovia', 'Outdoor Rodovia']);
});

it('o relatório de Marketing separa o Outdoor Rodovia e exporta o resumo por canal com o total', function () {
    $this->at('2026-11-18 10:00:00');

    $gestor = $this->makeUser('gerente', ['marketing', 'ver_valores_financeiros']);

    $faturada = $this->makeOs();
    $aberta = $this->makeOs();
    $instagram = $this->makeOs();

    $faturada->forceFill(['origem_cliente' => 'Outdoor Rodovia', 'faturado_em' => now(), 'grand_total' => 1500])->save();
    $aberta->forceFill(['origem_cliente' => 'Outdoor Rodovia'])->save();
    $instagram->forceFill(['origem_cliente' => 'Instagram'])->save();

    $this->actingAs($gestor, 'user')
        ->get(route('admin.marketing.index'))
        ->assertOk()
        ->assertSee('Outdoor Rodovia')
        ->assertSee('Exportar resumo (Excel)')
        ->assertSee('Exportar OS por canal (Excel)');

    $resposta = $this->get(route('admin.marketing.export', ['de' => '2026-11-01', 'ate' => '2026-11-30']))->assertOk();
    $linhas = collect(linhasDoCsv($resposta->streamedContent()));

    expect($resposta->headers->get('content-disposition'))->toContain('marketing-resumo-2026-11-01-a-2026-11-30.csv')
        ->and($linhas[0])->toBe(['Canal', 'OS abertas', 'Faturadas', 'Conversão (%)', 'Faturamento', 'Ticket médio'])
        // 2 OS abertas, 1 faturada: conversão 50%, faturamento e ticket de R$ 1.500,00.
        ->and($linhas->first(fn ($l) => $l[0] === 'Outdoor Rodovia'))->toBe(['Outdoor Rodovia', '2', '1', '50,00', '1500,00', '1500,00'])
        ->and($linhas->first(fn ($l) => $l[0] === 'Instagram'))->toBe(['Instagram', '1', '0', '0,00', '0,00', '0,00'])
        ->and($linhas->last())->toBe(['TOTAL', '3', '1', '33,33', '1500,00', '1500,00']);
});

it('a exportação detalhada traz uma linha por OS com o canal de origem', function () {
    $this->at('2026-11-18 10:00:00');

    $gestor = $this->makeUser('gerente', ['marketing', 'ver_valores_financeiros']);

    $faturada = $this->makeOs();
    $semOrigem = $this->makeOs();
    $faturada->forceFill(['origem_cliente' => 'Outdoor Rodovia', 'faturado_em' => now(), 'grand_total' => 2300.5])->save();

    $resposta = $this->actingAs($gestor, 'user')
        ->get(route('admin.marketing.export', ['de' => '2026-11-01', 'ate' => '2026-11-30', 'tipo' => 'detalhe']))
        ->assertOk();

    $linhas = collect(linhasDoCsv($resposta->streamedContent()));
    $daFaturada = $linhas->first(fn ($l) => $l[0] === (string) $faturada->id);
    $daSemOrigem = $linhas->first(fn ($l) => $l[0] === (string) $semOrigem->id);

    expect($resposta->headers->get('content-disposition'))->toContain('marketing-os-por-canal-')
        ->and($linhas[0])->toBe(['OS', 'Aberta em', 'Canal', 'Cliente', 'Placa', 'Etapa atual', 'Faturada em', 'Faturamento'])
        ->and($linhas)->toHaveCount(3)
        ->and($daFaturada[1])->toBe('18/11/2026')
        ->and($daFaturada[2])->toBe('Outdoor Rodovia')
        ->and($daFaturada[6])->toBe('18/11/2026')
        ->and($daFaturada[7])->toBe('2300,50')
        ->and($daSemOrigem[2])->toBe('Não informado')
        ->and($daSemOrigem[6])->toBe('')
        ->and($daSemOrigem[7])->toBe('0,00');
});

it('o técnico não exporta o relatório de Marketing nem por URL direta', function () {
    $this->enablePilot();

    $tecnico = $this->makeUser('tecnico', ['marketing']);

    $this->actingAs($tecnico, 'user')
        ->get(route('admin.marketing.export'))
        ->assertForbidden();
});
