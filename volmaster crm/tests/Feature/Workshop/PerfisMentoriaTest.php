<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\Perfis\PerfilCatalog;
use Webkul\ServiceOrder\Services\Perfis\PerfilService;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

/**
 * A4 (perfis = cargos da mentoria) e B8 (menu e permissões por perfil) do roteiro do patrão.
 */
function perfilTesteServico(): PerfilService
{
    return app(PerfilService::class);
}

function perfilTesteUsuario(string $perfil, array $extra = []): User
{
    $role = perfilTesteServico()->funcaoPara([$perfil]);

    return User::create(array_merge([
        'name' => ucfirst($perfil).' '.Str::random(4),
        'email' => Str::random(8).'@teste.local',
        'password' => bcrypt('senha-teste'),
        'status' => 1,
        'role_id' => $role->id,
        'view_permission' => 'global',
    ], $extra));
}

function perfilTesteUsuarioAntigo(string $nomeDaFuncao, string $nome): User
{
    $role = Role::firstOrCreate(['name' => $nomeDaFuncao], ['description' => 'antiga', 'permission_type' => 'custom', 'permissions' => ['service_orders', 'ver_valores_financeiros']]);

    return User::create(['name' => $nome, 'email' => Str::random(8).'@antigo.local', 'password' => bcrypt('x'), 'status' => 1, 'role_id' => $role->id, 'view_permission' => 'global']);
}

// ---- Catálogo e soma das permissões ------------------------------------------------------------

it('toda chave de acesso listada nos perfis existe no sistema (senão ela seria ignorada em silêncio)', function () {
    $existentes = PerfilCatalog::chavesDoSistema();
    $faltando = [];

    foreach (PerfilCatalog::todos() as $perfil => $definicao) {
        foreach ((array) ($definicao['chaves'] ?? []) as $chave) {
            if (! in_array($chave, $existentes, true)) {
                $faltando[] = $perfil.': '.$chave;
            }
        }
    }

    expect($faltando)->toBe([]);
});

it('acúmulo de cargos: soma as permissões e o Técnico só vê valores se OUTRO cargo dele enxerga', function () {
    $chefeTecnico = PerfilCatalog::permissoes(['tecnico', 'chefe']);

    expect($chefeTecnico['permission_type'])->toBe('custom')
        ->and($chefeTecnico['permissions'])->toContain('tecnico', 'diagnostico', 'service_orders.auditar')
        ->and($chefeTecnico['permissions'])->not->toContain('ver_valores_financeiros', 'comissoes', 'financeiro_dre')
        ->and(PerfilCatalog::veValores(['chefe', 'tecnico']))->toBeFalse()
        ->and(PerfilCatalog::veValores(['zeladoria', 'tecnico']))->toBeFalse()
        ->and(PerfilCatalog::veValores(['consultor', 'tecnico']))->toBeTrue()
        ->and(PerfilCatalog::veValores(['financeiro', 'tecnico']))->toBeTrue()
        ->and(PerfilCatalog::permissoes(['tecnico', 'proprietario'])['permission_type'])->toBe('all')
        ->and(PerfilCatalog::nomeDaFuncao(['tecnico', 'chefe']))->toBe('Chefe de oficina + Técnico');

    // Ninguém além do Técnico, Chefe, Zeladoria e Mentor fica sem valores.
    expect(PerfilCatalog::veValores(['tecnico']))->toBeFalse()
        ->and(PerfilCatalog::veValores(['mentor']))->toBeFalse()
        ->and(PerfilCatalog::veValores(['gerente']))->toBeTrue();
});

// ---- Criar as funções (A4) ----------------------------------------------------------------------

it('A4: "Criar as funções da mentoria" cria os 10 perfis, é idempotente e não apaga nem renomeia à toa as funções antigas', function () {
    $financeiroAntigo = Role::create(['name' => 'Financeiro', 'description' => 'antiga', 'permission_type' => 'custom', 'permissions' => ['service_orders']]);
    $usuarioAntigo = User::create(['name' => 'Ana', 'email' => 'ana@antigo.local', 'password' => bcrypt('x'), 'status' => 1, 'role_id' => $financeiroAntigo->id, 'view_permission' => 'global']);
    $antes = Role::count();

    $feitos = perfilTesteServico()->aplicar();

    expect($feitos)->toHaveCount(10)
        ->and(collect($feitos)->pluck('acao')->unique()->all())->toBe(['criada'])
        ->and(Role::count())->toBe($antes + 10)
        ->and(Role::where('description', 'like', '[Mentoria]%')->count())->toBe(10);

    // A função antiga com o mesmo nome fica com "(antiga)", sem sumir e sem perder o usuário.
    expect($financeiroAntigo->fresh()->name)->toBe('Financeiro (antiga)')
        ->and(Role::where('name', 'Financeiro')->count())->toBe(1)
        ->and($usuarioAntigo->fresh()->role_id)->toBe($financeiroAntigo->id);

    // Rodar de novo não duplica nem muda nada.
    $denovo = perfilTesteServico()->aplicar();

    expect(collect($denovo)->pluck('acao')->unique()->all())->toBe(['igual'])
        ->and(Role::count())->toBe($antes + 10);

    $tecnico = Role::where('perfil_oficina', 'tecnico')->first();
    $proprietario = Role::where('perfil_oficina', 'proprietario')->first();

    expect($tecnico->permissions)->toBe(['bonus_meu', 'tecnico'])
        ->and($proprietario->permission_type)->toBe('all');
});

// ---- Mover os usuários (A4) ---------------------------------------------------------------------

it('A4: a prévia sugere o cargo pelo nome da função antiga e "Programador" vira Suporte Volmaster', function () {
    perfilTesteServico()->aplicar();

    $mecanico = perfilTesteUsuarioAntigo('Mecânico', 'Zé Mecânico');
    $diagnostico = perfilTesteUsuarioAntigo('Diagnóstico', 'Dani Diagnóstico');
    $programador = perfilTesteUsuarioAntigo('Programador', 'Pedro Programador');
    $outro = perfilTesteUsuarioAntigo('Sócio', 'Sócio sem equivalente');

    $plano = collect(perfilTesteServico()->plano())->keyBy('usuario_id');

    expect($plano[$mecanico->id]['sugerido'])->toBe('tecnico')
        ->and($plano[$mecanico->id]['valores_antes'])->toBeTrue()
        ->and($plano[$mecanico->id]['valores_depois'])->toBeFalse()
        ->and($plano[$diagnostico->id]['sugerido'])->toBe('chefe')
        ->and($plano[$programador->id]['sugerido'])->toBe('suporte')
        ->and($plano[$outro->id]['sugerido'])->toBeNull();
});

it('A4: mover os usuários grava o registro, leva a comissão, e o lote pode ser desfeito', function () {
    perfilTesteServico()->aplicar();

    $mecanico = perfilTesteUsuarioAntigo('Mecânico', 'Zé Mecânico');
    $roleAntiga = $mecanico->role;
    DB::table('roles')->where('id', $roleAntiga->id)->update(['comissao_percentual' => 4]);

    $resultado = perfilTesteServico()->migrar([$mecanico->id => 'tecnico'], null);

    $mecanico->refresh()->load('role');

    expect($resultado['movidos'])->toBe(1)
        ->and($mecanico->role->name)->toBe('Técnico')
        ->and((float) $mecanico->role->getAttribute('comissao_percentual'))->toBe(4.0)
        ->and(MentoriaRegistro::where('tipo', 'perfil_usuario')->count())->toBe(1)
        ->and(perfilTesteServico()->ultimoLote())->toBe($resultado['lote']);

    expect(perfilTesteServico()->desfazer($resultado['lote']))->toBe(1)
        ->and($mecanico->fresh()->role_id)->toBe($roleAntiga->id)
        ->and(perfilTesteServico()->ultimoLote())->toBeNull();

    // A função antiga continua existindo.
    expect(Role::whereKey($roleAntiga->id)->exists())->toBeTrue();
});

it('A4: nunca deixa a oficina sem acesso total nem deixa a pessoa reduzir o próprio acesso', function () {
    perfilTesteServico()->aplicar();

    $adminRole = Role::create(['name' => 'Administrador teste', 'description' => 'x', 'permission_type' => 'all', 'permissions' => null]);
    User::query()->update(['status' => 0]); // só o administrador do teste fica ativo
    $unico = User::create(['name' => 'Único', 'email' => 'unico@teste.local', 'password' => bcrypt('x'), 'status' => 1, 'role_id' => $adminRole->id, 'view_permission' => 'global']);

    // É o único com acesso total: não sai dele.
    $resultado = perfilTesteServico()->migrar([$unico->id => 'consultor'], null);

    expect($resultado['movidos'])->toBe(0)
        ->and($resultado['ignorados'][0])->toContain('único com acesso total')
        ->and($unico->fresh()->role_id)->toBe($adminRole->id);

    // Com outro administrador ativo, a própria pessoa ainda não reduz o próprio acesso.
    User::create(['name' => 'Outro', 'email' => 'outro@teste.local', 'password' => bcrypt('x'), 'status' => 1, 'role_id' => $adminRole->id, 'view_permission' => 'global']);

    $resultado = perfilTesteServico()->migrar([$unico->id => 'consultor'], $unico);

    expect($resultado['movidos'])->toBe(0)
        ->and($resultado['ignorados'][0])->toContain('próprio acesso');
});

it('A4: a tela cria as funções e mostra a prévia; sem permissão ninguém entra', function () {
    $dono = $this->makeUser('proprietario');
    $dono->update(['role_id' => Role::create(['name' => 'Admin teste', 'permission_type' => 'all', 'permissions' => null])->id]);
    $mecanico = perfilTesteUsuarioAntigo('Mecânico', 'Zé Mecânico');

    $this->actingAs($dono->refresh(), 'user');

    $this->get(route('admin.workshop.perfis.index'))->assertOk()->assertSee('Perfis da mentoria')->assertSee('Criar as funções da mentoria');

    $this->post(route('admin.workshop.perfis.aplicar'))->assertRedirect(route('admin.workshop.perfis.index'));

    $this->get(route('admin.workshop.perfis.index'))
        ->assertOk()
        ->assertSee('Atualizar as funções da mentoria')
        ->assertSee('Zé Mecânico')
        ->assertSee('Mover os usuários');

    $this->post(route('admin.workshop.perfis.migrar'), ['usuario' => [$mecanico->id => 'tecnico']])->assertRedirect();

    expect($mecanico->fresh()->role->name)->toBe('Técnico');

    $semAcesso = $this->makeUser('consultor', ['service_orders']);
    $this->actingAs($semAcesso, 'user')->get(route('admin.workshop.perfis.index'))->assertForbidden();
});

// ---- Colaborador <-> perfil do usuário (A4) -----------------------------------------------------

it('A4: marcar o cargo no colaborador atualiza o perfil do usuário, e o acúmulo Chefe + Técnico continua sem valores', function () {
    $colaboradorSemUsuario = Colaborador::create(['nome' => 'Sem usuário', 'cargos' => 'tecnico', 'classificacao' => 'oficina', 'ativo' => true]);
    $usuario = perfilTesteUsuarioAntigo('Mecânico', 'Zé Mecânico');
    $colaborador = Colaborador::create(['nome' => 'Zé', 'cargos' => 'chefe,tecnico', 'classificacao' => 'oficina', 'ativo' => true, 'user_id' => $usuario->id]);
    $dono = $this->makeUser('proprietario');
    $dono->update(['role_id' => Role::create(['name' => 'Admin teste', 'permission_type' => 'all', 'permissions' => null])->id]);

    // Antes de criar as funções da mentoria o comportamento é o de sempre: nada muda.
    expect(perfilTesteServico()->sincronizarColaborador($colaborador, $dono))->toBeNull()
        ->and(perfilTesteServico()->sincronizarColaborador($colaboradorSemUsuario, $dono))->toBeNull()
        ->and($usuario->fresh()->role->name)->toBe('Mecânico');

    perfilTesteServico()->aplicar();

    $mensagem = perfilTesteServico()->sincronizarColaborador($colaborador, $dono);

    $usuario->refresh()->load('role');

    expect($mensagem)->toContain('Chefe de oficina + Técnico')
        ->and($usuario->role->getAttribute('perfil_oficina'))->toBe('chefe,tecnico')
        ->and(perfilTesteServico()->funcaoVeValores($usuario->role))->toBeFalse()
        ->and($usuario->role->permissions)->toContain('tecnico', 'diagnostico');

    // Trocar um cargo muda a função de novo; a combinada antiga continua existindo.
    $colaborador->update(['cargos' => 'consultor,tecnico']);
    perfilTesteServico()->sincronizarColaborador($colaborador, $dono);

    expect($usuario->fresh()->role->getAttribute('perfil_oficina'))->toBe('consultor,tecnico')
        ->and(perfilTesteServico()->funcaoVeValores($usuario->fresh()->role))->toBeTrue();
});

it('A4: o gerente só altera cargos operacionais e ninguém altera o próprio perfil', function () {
    perfilTesteServico()->aplicar();

    $gerente = perfilTesteUsuario('gerente');
    $tecnico = perfilTesteUsuario('tecnico');
    $outroGerente = perfilTesteUsuario('gerente');

    $paraConsultor = Colaborador::create(['nome' => 'T', 'cargos' => 'consultor', 'classificacao' => 'oficina', 'ativo' => true, 'user_id' => $tecnico->id]);
    $paraProprietario = Colaborador::create(['nome' => 'G', 'cargos' => 'proprietario', 'classificacao' => 'oficina', 'ativo' => true, 'user_id' => $outroGerente->id]);
    $proprio = Colaborador::create(['nome' => 'Eu', 'cargos' => 'consultor', 'classificacao' => 'oficina', 'ativo' => true, 'user_id' => $gerente->id]);

    expect(perfilTesteServico()->sincronizarColaborador($paraConsultor, $gerente))->toContain('Consultor técnico')
        ->and($tecnico->fresh()->role->getAttribute('perfil_oficina'))->toBe('consultor')
        ->and(perfilTesteServico()->sincronizarColaborador($paraProprietario, $gerente))->toContain('Só o Proprietário')
        ->and($outroGerente->fresh()->role->getAttribute('perfil_oficina'))->toBe('gerente')
        ->and(perfilTesteServico()->sincronizarColaborador($proprio, $gerente))->toContain('seu próprio perfil')
        ->and($gerente->fresh()->role->getAttribute('perfil_oficina'))->toBe('gerente');
});

// ---- B8: cada perfil entra e vê só o que é do trabalho dele --------------------------------------

it('B8: o Técnico leva acesso negado nas rotas de valores, da OS e do financeiro, chamadas direto pelo endereço', function () {
    perfilTesteServico()->aplicar();
    $this->enablePilot();
    $os = $this->makeOs();
    $tecnico = perfilTesteUsuario('tecnico');

    $this->actingAs($tecnico, 'user');

    $this->get(route('admin.tecnico.index'))->assertOk()->assertSee('Minhas tarefas');

    foreach ([route('admin.commissions.index'), route('admin.financeiro.dre'), route('admin.service_orders.view', $os->id), route('admin.service_orders.index'), route('admin.mentoria.painel')] as $endereco) {
        $this->get($endereco)->assertForbidden();
    }

    // Menu enxuto: só as telas do trabalho dele.
    $pagina = $this->get(route('admin.tecnico.index'))->assertOk();

    foreach (['Comissões', 'DRE e preços', 'Financeiro', 'Faturamento', 'Colaboradores', 'Marketing'] as $naoDeve) {
        $pagina->assertDontSee($naoDeve);
    }
});

it('B8: cada etapa da OS abre só para quem tem a chave dela (Chefe: do Diagnóstico ao Teste; Financeiro: Faturamento)', function () {
    perfilTesteServico()->aplicar();
    $this->enablePilot();

    $lista = fn (string $etapa) => route('admin.service_orders.stage.index', ['etapa' => $etapa]);

    $this->actingAs(perfilTesteUsuario('chefe'), 'user');
    $this->get($lista(ServiceOrder::STAGE_DIAGNOSTICO))->assertOk();
    $this->get($lista(ServiceOrder::STAGE_VEICULO_TESTE))->assertOk();
    $this->get($lista(ServiceOrder::STAGE_FATURAMENTO))->assertForbidden();
    $this->get($lista(ServiceOrder::STAGE_POS_VENDA))->assertForbidden();
    $this->get($lista(ServiceOrder::STAGE_CADASTRO))->assertForbidden();

    $this->actingAs(perfilTesteUsuario('consultor'), 'user');

    foreach ([ServiceOrder::STAGE_CADASTRO, ServiceOrder::STAGE_ORCAMENTO, ServiceOrder::STAGE_FATURAMENTO, ServiceOrder::STAGE_POS_VENDA] as $etapa) {
        $this->get($lista($etapa))->assertOk();
    }

    $this->actingAs(perfilTesteUsuario('financeiro'), 'user');
    $this->get($lista(ServiceOrder::STAGE_FATURAMENTO))->assertOk();
    $this->get($lista(ServiceOrder::STAGE_DIAGNOSTICO))->assertForbidden();
});

it('B8: com o piloto desligado as listas por etapa seguem como sempre (quem tem "pos_venda" abre; sem ela, não)', function () {
    $comChave = $this->makeUser('consultor', ['pos_venda']);
    $semChave = $this->makeUser('consultor', ['diagnostico']);

    $this->actingAs($comChave, 'user')->get(route('admin.service_orders.stage.index', ['etapa' => ServiceOrder::STAGE_DIAGNOSTICO]))->assertOk();
    $this->actingAs($semChave, 'user')->get(route('admin.service_orders.stage.index', ['etapa' => ServiceOrder::STAGE_DIAGNOSTICO]))->assertForbidden();
});

it('B8: o administrador (função "todas as permissões") abre toda lista de etapa, com o piloto desligado e ligado', function () {
    $admin = $this->makeUser('proprietario');
    $admin->update(['role_id' => Role::create(['name' => 'Admin teste', 'permission_type' => 'all', 'permissions' => null])->id]);
    $this->actingAs($admin->refresh(), 'user');

    $etapas = [
        ServiceOrder::STAGE_ORDEM_SERVICO, ServiceOrder::STAGE_DIAGNOSTICO, ServiceOrder::STAGE_ORCAMENTO, ServiceOrder::STAGE_NEGOCIACAO,
        ServiceOrder::STAGE_EM_EXECUCAO, ServiceOrder::STAGE_VEICULO_TESTE, ServiceOrder::STAGE_FATURAMENTO, ServiceOrder::STAGE_POS_VENDA,
    ];

    // Piloto desligado é o estado das oficinas reais: antes era erro 500 em toda lista de etapa para o administrador.
    foreach ($etapas as $etapa) {
        $this->get(route('admin.service_orders.stage.index', ['etapa' => $etapa]))->assertOk();
    }

    $this->get(route('admin.service_orders.stage.index'))->assertOk();

    $this->enablePilot();

    foreach ($etapas as $etapa) {
        $this->get(route('admin.service_orders.stage.index', ['etapa' => $etapa]))->assertOk();
    }
});

it('B8: Financeiro, Peças, Zeladoria e Consultor entram e abrem as telas do seu trabalho e não as dos outros', function () {
    perfilTesteServico()->aplicar();
    $this->enablePilot();

    $this->actingAs(perfilTesteUsuario('financeiro'), 'user');
    $this->get(route('admin.financeiro.lancamentos'))->assertOk();
    $this->get(route('admin.financeiro.dre'))->assertOk();
    $this->get(route('admin.mentoria.registros'))->assertForbidden();
    $this->get(route('admin.colaboradores.index'))->assertForbidden();

    $this->actingAs(perfilTesteUsuario('zeladoria'), 'user');
    $this->get(route('admin.mentoria.registros'))->assertOk();
    $this->get(route('admin.financeiro.lancamentos'))->assertForbidden();
    $this->get(route('admin.commissions.index'))->assertForbidden();

    $this->actingAs(perfilTesteUsuario('consultor'), 'user');
    $this->get(route('admin.rotina.agenda'))->assertOk();
    $this->get(route('admin.marketing.index'))->assertOk();
    $this->get(route('admin.financeiro.dre'))->assertForbidden();
    $this->get(route('admin.commissions.index'))->assertForbidden();

    $this->actingAs(perfilTesteUsuario('gerente'), 'user');
    $this->get(route('admin.colaboradores.index'))->assertOk();
    $this->get(route('admin.commissions.index'))->assertOk();
    // Gerente: tudo da oficina, e a Ficha de Enquadramento só para leitura (a gravação leva 403).
    $this->get(route('admin.ficha.edit'))->assertOk();
    $this->put(route('admin.ficha.update'), ['ficha_porte' => 'pequena'])->assertForbidden();
});

it('B8: o Mentor só consulta (Visão do mentor e Painel do Dono) e qualquer gravação leva 403', function () {
    perfilTesteServico()->aplicar();
    $this->enablePilot();
    $mentor = perfilTesteUsuario('mentor');

    $this->actingAs($mentor, 'user');

    $this->get(route('admin.mentor.index', ['periodo' => '2026-11']))->assertOk();
    $this->get(route('admin.mentoria.painel'))->assertOk();
    $this->get(route('admin.commissions.index'))->assertForbidden();
    $this->get(route('admin.service_orders.index'))->assertForbidden();

    // Somente leitura: nenhuma ação que grava, nem a da própria tela do mentor.
    $this->post(route('admin.mentor.replicar_metas'))->assertForbidden();
    $this->post(route('admin.mentor.replicar_pesos'))->assertForbidden();
    $this->post(route('admin.workshop.perfis.aplicar'))->assertForbidden();
});
