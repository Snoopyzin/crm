<?php

use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\PainelComissao;
use Webkul\User\Models\User;

/**
 * Função com % de comissão (a coluna fica na tabela de funções).
 */
function painelComComissao(User $user, float $percentual): User
{
    DB::table('roles')->where('id', $user->role_id)->update(['comissao_percentual' => $percentual]);

    return $user->refresh();
}

/**
 * OS faturada com um serviço executado pelo mecânico; devolve [OS, item].
 */
function painelOsFaturada(array $override = [], ?int $mechanicId = null, string $servico = 'Troca de embreagem'): array
{
    // Os totais não são preenchíveis pela criação: entram à força, como no faturamento.
    $totais = array_intersect_key($override, ['grand_total' => 1, 'sub_total' => 1]);

    $os = test()->makeOs(array_diff_key($override, $totais));
    $item = test()->makeServiceItem($os, 1.0, $mechanicId, $servico);

    $os->forceFill(array_merge(['faturado_em' => now(), 'workshop_status' => ServiceOrder::STAGE_FATURAMENTO], $totais))->save();

    return [$os->refresh(), $item->refresh()];
}

const PAINEL_BLOCOS_DE_FATURAMENTO = [
    'over-all', 'revenue-stats', 'total-leads', 'revenue-by-sources', 'revenue-by-types',
    'top-selling-products', 'top-persons', 'open-leads-by-states', 'service-order-revenue-stats',
];

it('quem ganha comissão vê "Minhas comissões" e não os blocos de faturamento geral', function () {
    $mecanico = painelComComissao($this->makeUser('tecnico', ['dashboard']), 10);

    $this->actingAs($mecanico, 'user')
        ->get(route('admin.dashboard.index'))
        ->assertOk()
        ->assertSee('v-dashboard-minhas-comissoes', false)
        ->assertSee('Minhas comissões')
        ->assertSee('OS em andamento')
        ->assertDontSee('Faturamento de Ordens de Serviço')
        ->assertDontSee('v-dashboard-service-order-revenue', false)
        ->assertDontSee('v-dashboard-revenue-stats', false)
        ->assertDontSee('v-dashboard-over-all-stats', false)
        ->assertDontSee('v-dashboard-top-persons', false);
});

it('quem não tem comissão continua vendo o Início completo, sem o cartão de comissão', function () {
    $gerente = $this->makeUser('gerente', ['dashboard']);

    $this->actingAs($gerente, 'user')
        ->get(route('admin.dashboard.index'))
        ->assertOk()
        ->assertSee('Faturamento de Ordens de Serviço')
        ->assertSee('v-dashboard-top-persons', false)
        ->assertDontSee('v-dashboard-minhas-comissoes', false);

    expect(PainelComissao::somenteComissao($gerente))->toBeFalse();
});

it('a API do Início recusa os blocos de faturamento para quem ganha comissão', function () {
    $consultor = painelComComissao($this->makeUser('consultor', ['dashboard']), 5);
    $this->actingAs($consultor, 'user');

    foreach (PAINEL_BLOCOS_DE_FATURAMENTO as $bloco) {
        $this->getJson(route('admin.dashboard.stats', ['type' => $bloco]))->assertForbidden();
    }

    $this->getJson(route('admin.dashboard.stats', ['type' => 'pending-service-orders']))->assertOk();
    $this->getJson(route('admin.dashboard.stats', ['type' => 'minhas-comissoes']))->assertOk();
    $this->getJson(route('admin.dashboard.stats', ['type' => 'nao-existe']))->assertNotFound();
});

it('quem não tem comissão continua recebendo o faturamento geral pela API', function () {
    $gerente = $this->makeUser('gerente', ['dashboard']);
    $this->actingAs($gerente, 'user');

    foreach (PAINEL_BLOCOS_DE_FATURAMENTO as $bloco) {
        $this->getJson(route('admin.dashboard.stats', ['type' => $bloco]))->assertOk();
    }
});

it('administrador e quem recebeu "Faturamento geral" veem tudo mesmo com comissão', function () {
    $admin = painelComComissao($this->makeUser('dono'), 10);
    $liberado = painelComComissao($this->makeUser('gerente', ['dashboard', PainelComissao::PERMISSAO_FATURAMENTO_GERAL]), 3);

    expect(PainelComissao::somenteComissao($admin))->toBeFalse()
        ->and(PainelComissao::somenteComissao($liberado))->toBeFalse();

    // O liberado vê o faturamento geral e, por ter comissão, também o cartão dele.
    $this->actingAs($liberado, 'user')
        ->get(route('admin.dashboard.index'))
        ->assertOk()
        ->assertSee('Faturamento de Ordens de Serviço')
        ->assertSee('v-dashboard-minhas-comissoes', false);

    $this->getJson(route('admin.dashboard.stats', ['type' => 'service-order-revenue-stats']))->assertOk();
});

it('a permissão "Faturamento geral" aparece no cadastro de funções', function () {
    $this->actingAs($this->makeUser('dono'), 'user')
        ->get(route('admin.settings.roles.create'))
        ->assertOk()
        // A árvore de permissões vai em JSON (acentos escapados): confere pela chave.
        ->assertSee('dashboard.faturamento_geral', false);
});

it('com o piloto ligado, perfil sem permissão de valores também só vê o que não é faturamento', function () {
    $this->enablePilot();

    $semValores = $this->makeUser('tecnico', ['dashboard']);
    $comValores = $this->makeUser('gerente', ['dashboard', 'ver_valores_financeiros']);

    expect(PainelComissao::somenteComissao($semValores))->toBeTrue()
        ->and(PainelComissao::temComissao($semValores))->toBeFalse()
        ->and(PainelComissao::somenteComissao($comValores))->toBeFalse();

    // Sem comissão na Função, nem aparece o cartão.
    $this->actingAs($semValores, 'user')
        ->get(route('admin.dashboard.index'))
        ->assertOk()
        ->assertDontSee('Faturamento de Ordens de Serviço')
        ->assertDontSee('v-dashboard-minhas-comissoes', false);

    $this->getJson(route('admin.dashboard.stats', ['type' => 'revenue-stats']))->assertForbidden();
});

it('o card "OS em andamento" não leva valor nenhum da OS para quem ganha comissão', function () {
    $mecanico = painelComComissao($this->makeUser('tecnico', ['dashboard']), 10);

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_EM_EXECUCAO]);
    $os->forceFill(['grand_total' => 98765.43, 'sub_total' => 98765.43])->save();

    $json = $this->actingAs($mecanico, 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'pending-service-orders']))
        ->assertOk()
        ->assertJsonPath('statistics.0.id', $os->id)
        ->assertJsonPath('statistics.0.person.name', 'Transportadora Teste')
        ->assertJsonPath('statistics.0.prazo.estado', 'sem_prazo')
        ->assertJsonMissingPath('statistics.0.grand_total')
        ->assertJsonMissingPath('statistics.0.sub_total')
        ->assertJsonMissingPath('statistics.0.person.cpf_cnpj')
        ->getContent();

    expect($json)->not->toContain('98765');

    // Quem vê tudo continua recebendo a OS completa, como antes.
    $gerente = $this->makeUser('gerente', ['dashboard']);

    $this->actingAs($gerente, 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'pending-service-orders']))
        ->assertOk()
        ->assertJsonStructure(['statistics' => [['id', 'grand_total', 'sub_total']]]);
});

it('o mecânico vê só a comissão dele, com cliente e serviço, sem o valor da OS', function () {
    $eu = $this->makeMechanic('Mecânico Eu');
    $outro = $this->makeMechanic('Mecânico Outro');

    $meuUsuario = painelComComissao($this->makeUser('tecnico', ['dashboard'], ['mechanic_id' => $eu->id]), 10);
    painelComComissao($this->makeUser('tecnico', ['dashboard'], ['mechanic_id' => $outro->id]), 10);

    [$minhaOs, $meuItem] = painelOsFaturada(['grand_total' => 7777], $eu->id, 'Troca de embreagem');
    painelOsFaturada(['grand_total' => 5555], $outro->id, 'Alinhamento');

    $comissao = round($meuItem->line_total * 10 / 100, 2);

    $resposta = $this->actingAs($meuUsuario, 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'minhas-comissoes']))
        ->assertOk()
        ->assertJsonCount(1, 'statistics.linhas')
        ->assertJsonPath('statistics.linhas.0.os', $minhaOs->id)
        ->assertJsonPath('statistics.linhas.0.cliente', 'Transportadora Teste')
        ->assertJsonPath('statistics.linhas.0.servico', 'Troca de embreagem')
        ->assertJsonPath('statistics.os', 1);

    $texto = $resposta->getContent();
    $linha = $resposta->json('statistics.linhas.0');

    expect($linha['comissao'])->toEqual($comissao)
        ->and($resposta->json('statistics.total.valor'))->toEqual($comissao);

    // Nada do valor da OS, do desconto, do percentual nem da OS do colega.
    expect(array_keys($linha))->toBe(['os', 'data', 'cliente', 'servico', 'comissao', 'comissao_formatada'])
        ->and($texto)->not->toContain('7777')
        ->and($texto)->not->toContain('5555')
        ->and($texto)->not->toContain('Alinhamento')
        ->and($texto)->not->toContain('percent');
});

it('o consultor ganha pela venda da OS e vê "Venda da OS", sem o total', function () {
    $consultor = painelComComissao($this->makeUser('consultor', ['dashboard']), 5);

    [$os] = painelOsFaturada(['user_id' => $consultor->id, 'grand_total' => 4000, 'sub_total' => 4000]);

    $resposta = $this->actingAs($consultor, 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'minhas-comissoes']))
        ->assertOk()
        ->assertJsonCount(1, 'statistics.linhas')
        ->assertJsonPath('statistics.linhas.0.os', $os->id)
        ->assertJsonPath('statistics.linhas.0.servico', 'Venda da OS');

    expect($resposta->json('statistics.linhas.0.comissao'))->toEqual(200)
        ->and($resposta->json('statistics.total.valor'))->toEqual(200)
        ->and($resposta->getContent())->not->toContain('4000');
});

it('a comissão respeita o período do filtro e só conta OS faturada', function () {
    $this->at('2026-11-18 10:00:00');

    $eu = $this->makeMechanic('Mecânico Eu');
    $meuUsuario = painelComComissao($this->makeUser('tecnico', ['dashboard'], ['mechanic_id' => $eu->id]), 10);

    [$dentro] = painelOsFaturada([], $eu->id);

    // Faturada em outubro: fora do período; e uma OS ainda não faturada.
    [$fora] = painelOsFaturada([], $eu->id);
    $fora->forceFill(['faturado_em' => '2026-10-02 10:00:00'])->save();

    $naoFaturada = $this->makeOs();
    $this->makeServiceItem($naoFaturada, 1.0, $eu->id);

    $this->actingAs($meuUsuario, 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'minhas-comissoes', 'start' => '2026-11-01', 'end' => '2026-11-30']))
        ->assertOk()
        ->assertJsonCount(1, 'statistics.linhas')
        ->assertJsonPath('statistics.linhas.0.os', $dentro->id)
        // O filtro do Início não passa de hoje (18/11).
        ->assertJsonPath('statistics.periodo', '01/11/2026 a 18/11/2026');
});

// Os relatórios do Início guardam as datas do primeiro pedido da requisição:
// um período diferente pede outra requisição (outro teste).
it('um período anterior mostra só as OS faturadas naquele período', function () {
    $this->at('2026-11-18 10:00:00');

    $eu = $this->makeMechanic('Mecânico Eu');
    $meuUsuario = painelComComissao($this->makeUser('tecnico', ['dashboard'], ['mechanic_id' => $eu->id]), 10);

    painelOsFaturada([], $eu->id);

    [$fora] = painelOsFaturada([], $eu->id);
    $fora->forceFill(['faturado_em' => '2026-10-02 10:00:00'])->save();

    $this->actingAs($meuUsuario, 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'minhas-comissoes', 'start' => '2026-10-01', 'end' => '2026-10-31']))
        ->assertOk()
        ->assertJsonCount(1, 'statistics.linhas')
        ->assertJsonPath('statistics.linhas.0.os', $fora->id)
        ->assertJsonPath('statistics.periodo', '01/10/2026 a 31/10/2026');
});

it('quem não ganha comissão recebe o cartão vazio, sem dado de ninguém', function () {
    $this->at('2026-11-18 10:00:00');

    $eu = $this->makeMechanic('Mecânico Eu');
    painelComComissao($this->makeUser('tecnico', ['dashboard'], ['mechanic_id' => $eu->id]), 10);
    painelOsFaturada([], $eu->id);

    $gerente = $this->makeUser('gerente', ['dashboard']);

    $resposta = $this->actingAs($gerente, 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'minhas-comissoes', 'start' => '2026-11-01', 'end' => '2026-11-30']))
        ->assertOk()
        ->assertJsonCount(0, 'statistics.linhas');

    expect($resposta->json('statistics.total.valor'))->toEqual(0);
});
