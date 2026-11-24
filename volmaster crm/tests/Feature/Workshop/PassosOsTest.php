<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

// A tela da OS em passos (pedido do patrão, 19/11/2026): Cliente > Ordem de Serviço > Diagnóstico > Orçamento...
// Cada passo mostra só o que é dele e a tela abre no passo da etapa atual.

function usuarioDosPassos(): User
{
    $role = Role::create(['name' => 'Admin passos '.uniqid(), 'description' => 'x', 'permission_type' => 'all', 'permissions' => null]);

    return User::create([
        'name' => 'Admin passos',
        'email' => uniqid('passos').'@teste.local',
        'password' => bcrypt('senha-teste'),
        'status' => 1,
        'role_id' => $role->id,
        'view_permission' => 'global',
    ]);
}

const PARTE_ITENS = 'data-painel="Orçamento|Negociação|Em Execução|Faturamento"';

it('a OS abre no passo da etapa atual e esconde as partes dos outros passos', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    $this->actingAs(usuarioDosPassos(), 'user');

    // Atributos podem vir em linhas separadas no HTML: compara com os espaços juntos.
    $html = preg_replace('/\s+/', ' ', $this->get(route('admin.service_orders.view', $os->id))->assertOk()->getContent());

    // O passo do Diagnóstico está aberto e as peças e serviços (do Orçamento) ficam escondidos.
    expect($html)->toContain('data-aba="Diagnóstico" data-estado="atual"')
        ->and($html)->toContain('aria-selected="true"')
        ->and($html)->toContain(PARTE_ITENS.' style="display:none"')
        ->and($html)->toContain('data-painel="Cadastro|Ordem de Serviço" style="display:none"')
        ->and($html)->toContain('data-painel="anexos" style="display:none"')
        ->and($html)->toContain('data-painel="Diagnóstico"')
        ->and($html)->not->toContain('data-painel="Diagnóstico" style="display:none"')
        ->and($html)->toContain('Diagnóstico: o técnico escreve a descrição técnica');
});

it('cada etapa abre o seu passo: Orçamento mostra peças e serviços, Pós-Venda e encerradas têm passo próprio', function () {
    $this->actingAs(usuarioDosPassos(), 'user');

    $orcamento = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    $html = $this->get(route('admin.service_orders.view', $orcamento->id))->getContent();

    expect($html)->toContain(PARTE_ITENS.' class=')
        ->and($html)->not->toContain(PARTE_ITENS.' style="display:none"')
        ->and($html)->toContain('Orçamento em andamento: lance as peças e os serviços');

    $concluida = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_CONCLUIDA]);

    expect($this->get(route('admin.service_orders.view', $concluida->id))->getContent())
        ->toContain('Pós-venda: contato com o cliente');

    $cancelada = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_CANCELADA]);

    expect($this->get(route('admin.service_orders.view', $cancelada->id))->getContent())
        ->toContain('Histórico: cada passo da OS');
});

it('os passos anteriores aparecem como feitos e os seguintes como pendentes, com "+ Anotações e anexos" e "Histórico" ao lado', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    $this->actingAs(usuarioDosPassos(), 'user');

    $html = preg_replace('/\s+/', ' ', $this->get(route('admin.service_orders.view', $os->id))->assertOk()->getContent());

    expect($html)->toContain('data-aba="Cadastro" data-estado="feito"')
        ->and($html)->toContain('data-aba="Diagnóstico" data-estado="feito"')
        ->and($html)->toContain('data-aba="Orçamento" data-estado="atual"')
        ->and($html)->toContain('data-aba="Negociação" data-estado="futuro"')
        ->and($html)->toContain('data-aba="Pós-Venda" data-estado="futuro"')
        ->and($html)->toContain('Anotações e anexos')
        ->and($html)->toContain('Histórico');
});

it('a tela não repete a lista de etapas em dois lugares: a barra de passos é a única', function () {
    // O aviso do que falta para avançar só existe com o piloto ligado.
    $this->enablePilot();

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO]);

    $this->actingAs(usuarioDosPassos(), 'user');

    $html = $this->get(route('admin.service_orders.view', $os->id))->assertOk()->getContent();

    // A lista antiga de "pílulas" de etapa no cartão de dados saiu; o aviso do que falta para avançar continua.
    expect(substr_count($html, 'data-aba="Em Execução"'))->toBe(1)
        ->and($html)->toContain('data-testid="faltando-para-avancar"');
});

it('todo o conteúdo continua na página (só fica escondido): o link de uma parte abre o passo certo pelo script', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_CADASTRO]);

    $this->actingAs(usuarioDosPassos(), 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        // Partes de outros passos existem na página, escondidas, para o clique nas abas mostrá-las.
        ->assertSee('id="pecas-servicos"', false)
        ->assertSee('id="anotacoes-anexos"', false)
        ->assertSee('id="defeito-relatado"', false)
        // O script trata os cliques no document (o painel Vue refaz a página depois de carregar).
        ->assertSee("document.addEventListener('click'", false)
        ->assertSee('#abas-os [data-aba]', false);
});

it('cada passo é um cartão só (as seções ficam uma embaixo da outra), sem o cartão "Fluxo da OS" nem a numeração dos blocos', function () {
    $this->enablePilot();

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    $this->actingAs(usuarioDosPassos(), 'user');

    $html = preg_replace('/\s+/', ' ', $this->get(route('admin.service_orders.view', $os->id))->assertOk()->getContent());

    expect($html)->toContain('id="painel-passo"')
        ->and($html)->toContain('data-pai-display="flex"')
        ->and($html)->not->toContain('Fluxo da OS')
        ->and($html)->not->toContain('4. Diagnóstico')
        ->and($html)->not->toContain('1. Termo de entrada')
        ->and($html)->toContain('Termo de entrada')
        // O CSS que achata os cartões internos vem junto.
        ->and($html)->toContain('#painel-passo .box-shadow');
});

it('o Diagnóstico é uma seção só: laudo, fotos e vídeos à vista e o resto recolhido', function () {
    $this->enablePilot();

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    $chefe = $this->makeUser('chefe', ['service_orders', 'service_orders.view', 'service_orders.edit', 'service_orders.auditar']);

    $this->actingAs($chefe, 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('Salvar laudo')
        ->assertSee('Iniciar diagnóstico')
        ->assertSee('Fotos e vídeos do diagnóstico (0)')
        ->assertSee('Código de falha ou medição (0)')
        ->assertSee('Informar ou corrigir os horários (chefe de oficina)')
        ->assertSee('Entregar relação de peças')
        ->assertDontSee('Evidências do laudo');
});

it('foto ou vídeo do diagnóstico valem como evidência para finalizar (sem precisar de código de falha ou medição)', function () {
    Storage::fake('public');

    $this->enablePilot();

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    $this->actingAs(usuarioDosPassos(), 'user');

    $this->post(route('admin.service_orders.workflow.diagnostico.start', $os->id))->assertRedirect();
    $this->post(route('admin.service_orders.workflow.laudo', $os->id), ['diagnostico' => 'Atuador vazando.'])->assertRedirect();

    // Sem nenhuma evidência: não finaliza, e a mensagem diz o que fazer.
    $this->post(route('admin.service_orders.workflow.diagnostico.finish', $os->id))->assertSessionHas('error', fn ($erro) => str_contains($erro, 'foto ou um vídeo'));

    expect($os->refresh()->temEvidenciaDoDiagnostico())->toBeFalse()->and($os->diagnostico_concluido_em)->toBeNull();

    // Uma foto na aba de mídia do diagnóstico já basta.
    $this->post(route('admin.service_orders.workflow.diagnostico.midia', $os->id), ['fotos' => [UploadedFile::fake()->image('atuador.jpg')]])->assertRedirect();

    expect($os->refresh()->temEvidenciaDoDiagnostico())->toBeTrue();

    $this->post(route('admin.service_orders.workflow.diagnostico.finish', $os->id))->assertSessionHas('success');

    expect($os->refresh()->diagnostico_concluido_em)->not->toBeNull();
});

// ---- Cadastro do cliente em passos -------------------------------------------------------------

it('o Cadastro do cliente é feito em 3 passos (Cliente, Veículo, Entrada do veículo) e abre no primeiro', function () {
    $this->actingAs(usuarioDosPassos(), 'user');

    $html = preg_replace('/\s+/', ' ', $this->get(route('admin.service_orders.cadastro.create'))->assertOk()->getContent());

    expect($html)->toContain('data-ir-passo="1"')
        ->and($html)->toContain('1. Cliente')
        ->and($html)->toContain('2. Veículo')
        ->and($html)->toContain('3. Entrada do veículo')
        ->and($html)->toContain('data-atual="1"')
        // Passo 1 aberto; os passos 2 e 3 escondidos (mas com os campos na página, para o envio).
        ->and($html)->toContain('data-passo-cadastro="1" class="box-shadow')
        ->and($html)->toContain('data-passo-cadastro="2" style="display:none" class="box-shadow')
        ->and($html)->toContain('data-passo-cadastro="3" style="display:none" class="box-shadow')
        ->and($html)->toContain('name="placa"')
        ->and($html)->toContain('name="relato_motorista"')
        ->and($html)->toContain('name="fotos[]"')
        ->and($html)->toContain('Próximo: Veículo')
        ->and($html)->toContain('Próximo: Entrada do veículo')
        ->and($html)->toContain("closest('[data-ir-passo]')");
});

it('quando o Cadastro volta com erro, abre o passo que tem o erro', function () {
    $this->actingAs(usuarioDosPassos(), 'user');

    $existente = $this->makeOs();
    $placa = $existente->vehicle->placa;

    $dados = ['name' => 'Cliente Novo', 'cpf_cnpj' => '529.982.247-25', 'whatsapp' => '(62) 99999-0001'];

    // Erro no passo 2 (placa repetida): a tela abre no passo do Veículo.
    $this->post(route('admin.service_orders.cadastro.store'), $dados + ['placa' => $placa])->assertSessionHasErrors('placa');

    expect($this->get(route('admin.service_orders.cadastro.create'))->getContent())->toContain('data-atual="2"');

    // Erro no passo 1 (sem WhatsApp): abre no passo do Cliente.
    $this->post(route('admin.service_orders.cadastro.store'), ['name' => 'Sem Telefone', 'cpf_cnpj' => '529.982.247-25'])->assertSessionHasErrors('whatsapp');

    expect($this->get(route('admin.service_orders.cadastro.create'))->getContent())->toContain('data-atual="1"');

    // Sem erro, abre no primeiro.
    expect($this->get(route('admin.service_orders.cadastro.create'))->getContent())->toContain('data-atual="1"');
});

it('os campos de busca (peças e serviços, pátio e cliente) tratam o evento no document, como o resto do sistema', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    $this->actingAs(usuarioDosPassos(), 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee("evento.target.id !== 'item-busca'", false);

    $this->get(route('admin.service_orders.create'))
        ->assertOk()
        ->assertSee("evento.target.id !== 'cliente-busca'", false)
        ->assertSee("alvo.closest('#cliente-trocar')", false);

    $this->get(route('admin.rotina.patio'))
        ->assertOk()
        ->assertSee("evento.target.id === 'patio-busca'", false);
});
