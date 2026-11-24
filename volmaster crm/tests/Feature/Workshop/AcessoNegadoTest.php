<?php

use Webkul\ServiceOrder\Services\FichaEnquadramento;
use Webkul\ServiceOrder\Services\Perfis\PerfilCatalog;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

// B8 do roteiro: quem está logado mas não tem permissão recebe 403 (acesso negado), nunca 401,
// e o Gerente lê a Ficha de Enquadramento sem poder alterá-la.

function usuarioDoPerfil(string $perfil): User
{
    $permissoes = PerfilCatalog::permissoes([$perfil]);

    $role = Role::create([
        'name' => ucfirst($perfil).' acesso '.uniqid(),
        'description' => 'teste',
        'permission_type' => $permissoes['permission_type'],
        'permissions' => $permissoes['permissions'],
    ]);

    return User::create([
        'name' => ucfirst($perfil).' teste',
        'email' => uniqid($perfil).'@teste.local',
        'password' => bcrypt('senha-teste'),
        'status' => 1,
        'role_id' => $role->id,
        'view_permission' => 'global',
    ]);
}

function dadosDaFicha(array $extra = []): array
{
    return array_merge([
        'ficha_porte' => array_key_first(FichaEnquadramento::PORTES),
        'garantia_servico_dias' => 90,
        'garantia_pecas_dias' => 90,
        'dono_horas_prospeccao' => 4,
        'valor_hora_mao_obra' => '150,00',
        'pct_impostos' => '6',
        'inicio_mentoria' => '2026-11-01',
    ], $extra);
}

it('quem está logado e não tem permissão recebe 403 (acesso negado), nunca 401', function () {
    // Como no servidor de verdade: sem o modo de depuração, a página de erro própria (em português) aparece.
    config(['app.debug' => false]);

    $tecnico = usuarioDoPerfil('tecnico');

    $this->actingAs($tecnico, 'user');

    // Rota protegida pelo controle de permissões (ACL) do sistema.
    $this->get(route('admin.clientes.busca'))->assertForbidden()->assertSee('403 Acesso negado');
    $this->get(route('admin.ficha.edit'))->assertForbidden();
    $this->get(route('admin.colaboradores.index'))->assertForbidden();

    // A API (JSON) também recusa com 403 e uma mensagem, sem dado nenhum.
    $this->getJson(route('admin.clientes.busca', ['q' => 'transportadora']))->assertForbidden()->assertJsonStructure(['message']);
});

it('quem não fez login vai para a tela de entrada, não recebe erro de permissão', function () {
    $this->get(route('admin.clientes.busca'))->assertRedirect(route('admin.session.create'));
    $this->get(route('admin.ficha.edit'))->assertRedirect(route('admin.session.create'));
});

it('o Gerente lê a Ficha de Enquadramento, mas não consegue alterar nada nela (tela e rota)', function () {
    $gerente = usuarioDoPerfil('gerente');

    $this->actingAs($gerente, 'user');

    $tela = $this->get(route('admin.ficha.edit'));

    $tela->assertOk()
        ->assertSee('Ficha de Enquadramento')
        ->assertSee('só o Proprietário altera')
        ->assertDontSee('Salvar a ficha')
        ->assertDontSee('Salvar tabela de margem')
        ->assertDontSee('Registro de acesso aos dados sensíveis')
        ->assertSee('<fieldset disabled', false);

    // Mesmo forçando a rota (sem usar a tela), a alteração é recusada e nada muda.
    $this->put(route('admin.ficha.update'), dadosDaFicha(['ficha_cidade' => 'Goiânia']))->assertForbidden();
    $this->post(route('admin.ficha.margens'), ['faixa' => [['custo_ate' => '100', 'margem_minima' => '10', 'margem_alvo' => '20']]])->assertForbidden();
    $this->post(route('admin.ficha.pendencia'), ['cargo' => 'gerente', 'acao' => 'contratar'])->assertForbidden();
    $this->post(route('admin.ficha.pendencia.resolver', 999999))->assertForbidden();
    $this->get(route('admin.ficha.acessos'))->assertForbidden();

    expect(WorkshopSettings::get('ficha_cidade'))->not->toBe('Goiânia');

    // Configurações de usuários continuam fechadas para o Gerente.
    $this->get(route('admin.settings.users.index'))->assertForbidden();
});

it('o Proprietário (e o administrador) continuam alterando a Ficha', function () {
    $proprietario = usuarioDoPerfil('proprietario');

    $this->actingAs($proprietario, 'user');

    $this->get(route('admin.ficha.edit'))
        ->assertOk()
        ->assertSee('Salvar a ficha')
        ->assertDontSee('só o Proprietário altera')
        ->assertDontSee('<fieldset disabled', false);

    $this->put(route('admin.ficha.update'), dadosDaFicha(['ficha_cidade' => 'Goiânia']))->assertRedirect(route('admin.ficha.edit'));

    expect(WorkshopSettings::get('ficha_cidade'))->toBe('Goiânia');
});

it('o menu de Configurações do Gerente mostra a Ficha para leitura e a função "Gerente" da mentoria a descreve assim', function () {
    $chaves = PerfilCatalog::permissoes(['gerente'])['permissions'];

    expect($chaves)->toContain('settings.ficha')
        ->and($chaves)->not->toContain('settings.ficha.editar')
        ->and(PerfilCatalog::permissoes(['proprietario'])['permission_type'])->toBe('all');

    expect(collect(config('acl'))->pluck('key')->all())->toContain('settings.ficha', 'settings.ficha.editar');
});
