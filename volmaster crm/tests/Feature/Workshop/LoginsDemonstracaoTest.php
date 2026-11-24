<?php

use Illuminate\Support\Facades\Hash;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\ServiceOrder\Services\Perfis\LoginsDemonstracao;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

// Logins de demonstração para mostrar o sistema ao patrão: um por perfil, com a função oficial da mentoria.

it('cria um login por perfil com a função oficial da mentoria e senha sorteada', function () {
    $antes = User::count();

    $logins = app(LoginsDemonstracao::class)->criar();

    expect($logins)->toHaveCount(4)
        ->and(User::count())->toBe($antes + 4)
        ->and(collect($logins)->pluck('email')->all())->toBe(['gerente@demo.local', 'consultor@demo.local', 'chefe@demo.local', 'tecnico@demo.local'])
        ->and(collect($logins)->pluck('senha')->unique())->toHaveCount(4);

    foreach ($logins as $login) {
        $usuario = User::where('email', $login['email'])->firstOrFail();

        expect(Hash::check($login['senha'], $usuario->password))->toBeTrue()
            ->and((int) $usuario->status)->toBe(1)
            ->and($usuario->role->getAttribute('perfil_oficina'))->toBe($login['perfil'])
            ->and($usuario->role->description)->toStartWith('[Mentoria]')
            ->and($login['acao'])->toBe('criado');
    }

    // O técnico tem um mecânico ligado ("Minhas tarefas").
    expect(User::where('email', 'tecnico@demo.local')->firstOrFail()->mechanic_id)->toBe(Mechanic::where('nome', 'Técnico (demonstração)')->firstOrFail()->id);
});

it('rodar de novo troca as senhas e não duplica usuários, funções nem o mecânico', function () {
    $primeira = app(LoginsDemonstracao::class)->criar();
    $total = User::count();
    $funcoes = Role::count();

    $segunda = app(LoginsDemonstracao::class)->criar();

    expect(User::count())->toBe($total)
        ->and(Role::count())->toBe($funcoes)
        ->and(Mechanic::where('nome', 'Técnico (demonstração)')->count())->toBe(1)
        ->and(collect($segunda)->pluck('acao')->unique()->all())->toBe(['senha trocada']);

    $gerente = User::where('email', 'gerente@demo.local')->firstOrFail();

    expect(Hash::check($primeira[0]['senha'], $gerente->password))->toBeFalse()
        ->and(Hash::check($segunda[0]['senha'], $gerente->password))->toBeTrue();
});

it('a simulação não grava nada', function () {
    $antes = User::count();

    $linhas = app(LoginsDemonstracao::class)->simular();

    expect($linhas)->toHaveCount(4)
        ->and(collect($linhas)->pluck('existe')->unique()->all())->toBe([false])
        ->and(User::count())->toBe($antes);
});

it('cada login entra de verdade e tem o acesso do seu perfil', function () {
    $logins = collect(app(LoginsDemonstracao::class)->criar())->keyBy('perfil');

    // Gerente: entra, lê a Ficha (só leitura) e não abre Configurações de usuários.
    $this->post(route('admin.session.store'), ['email' => $logins['gerente']['email'], 'password' => $logins['gerente']['senha']]);

    expect(auth()->guard('user')->check())->toBeTrue();

    $this->get(route('admin.ficha.edit'))->assertOk()->assertSee('só o Proprietário altera');
    $this->get(route('admin.settings.users.index'))->assertForbidden();
    $this->put(route('admin.ficha.update'), ['ficha_porte' => 'pequena'])->assertForbidden();

    auth()->guard('user')->logout();

    // Técnico: entra, mas não abre a Ficha nem a busca de clientes.
    $this->post(route('admin.session.store'), ['email' => $logins['tecnico']['email'], 'password' => $logins['tecnico']['senha']]);

    expect(auth()->guard('user')->check())->toBeTrue();

    $this->get(route('admin.ficha.edit'))->assertForbidden();
    $this->get(route('admin.clientes.busca'))->assertForbidden();

    auth()->guard('user')->logout();

    // Senha errada não entra.
    $this->post(route('admin.session.store'), ['email' => $logins['consultor']['email'], 'password' => 'senha-errada']);

    expect(auth()->guard('user')->check())->toBeFalse();
});
