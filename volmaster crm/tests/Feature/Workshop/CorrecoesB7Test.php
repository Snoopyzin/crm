<?php

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;
use Webkul\ServiceOrder\Models\ServiceOrder;

/**
 * B7 do roteiro do patrão: página de erro em português com o contato real,
 * título da OS com a etapa atual e datas em português.
 */
beforeEach(function () {
    // Com o debug ligado o Laravel usa a página dele (em inglês); a produção roda com debug desligado.
    config(['app.debug' => false]);
    app()->setLocale('pt_BR');
});

// ---- Página de erro ------------------------------------------------------------------------

it('404 em português, com o contato real da Volmaster e sem o e-mail de exemplo', function () {
    $this->get('/rota-que-nao-existe-xyz')
        ->assertNotFound()
        ->assertSee('404 Página não encontrada')
        ->assertSee('Ir para o início')
        ->assertSee('gabriel@volmastercrm.com')
        ->assertDontSee('support@example.com')
        ->assertDontSee('Page Not Found');
});

it('o contato de suporte vem da configuração: WhatsApp aparece só se existir e o e-mail pode ser trocado', function () {
    config(['support.contact_email' => 'suporte@oficina.exemplo', 'support.contact_whatsapp' => '(11) 98888-7777']);

    $this->get('/rota-que-nao-existe-xyz')
        ->assertNotFound()
        ->assertSee('mailto:suporte@oficina.exemplo', false)
        ->assertSee('https://wa.me/11988887777', false)
        ->assertDontSee('gabriel@volmastercrm.com');

    config(['support.contact_email' => '', 'support.contact_whatsapp' => null]);

    $this->get('/rota-que-nao-existe-xyz')
        ->assertNotFound()
        ->assertSee('fale com o suporte da Volmaster')
        ->assertDontSee('wa.me');
});

it('erros comuns mostram a página certa com o status HTTP verdadeiro (antes tudo saía como 200 ou 500)', function () {
    Route::get('/_teste/sessao', fn () => throw new TokenMismatchException);
    Route::get('/_teste/negado', fn () => abort(403));
    Route::get('/_teste/so-get', fn () => 'ok');

    $this->get('/_teste/sessao')->assertStatus(419)->assertSee('419 Sessão expirada');
    $this->get('/_teste/negado')->assertForbidden()->assertSee('403 Acesso negado');
    $this->post('/_teste/so-get')->assertStatus(405)->assertSee('405 Ação não permitida');
});

it('o e-mail de exemplo não existe em lugar nenhum do código', function () {
    $achados = [];

    foreach ((new Finder)->files()->in(base_path('packages/Webkul'))->name(['*.php', '*.blade.php'])->exclude(['node_modules', 'vendor']) as $arquivo) {
        if (str_contains($arquivo->getContents(), 'support@example.com')) {
            $achados[] = $arquivo->getRelativePathname();
        }
    }

    expect($achados)->toBe([]);
});

it('as mensagens de erro existem em português e em inglês para todos os códigos que têm página', function () {
    foreach (['pt_BR', 'en'] as $idioma) {
        foreach ([401, 403, 404, 405, 413, 419, 429, 500, 503] as $codigo) {
            $titulo = trans("admin::app.errors.{$codigo}.title", [], $idioma);

            expect($titulo)->toBeString()->not->toBe("admin::app.errors.{$codigo}.title");
        }
    }

    expect(trans('admin::app.errors.403.title', [], 'pt_BR'))->not->toContain('Forbidden');
});

it('login depois de desativar o usuário não quebra: o aviso é um texto, não uma lista', function () {
    $usuario = $this->makeUser('consultor');
    $usuario->update(['status' => 0]);

    $this->actingAs($usuario, 'user')->get(route('admin.dashboard.index'))->assertRedirect(route('admin.session.create'));

    expect(session('error'))->toBeString();
});

// ---- Título da OS ----------------------------------------------------------------------------

it('título da OS mostra a etapa ATUAL e tira o prefixo velho que o Cadastro gravou', function () {
    $os = $this->makeOs(['subject' => 'Cadastro - ABC1D23', 'workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    expect($os->identificacao())->toBe('ABC1D23')
        ->and($os->tituloAtual())->toBe('#'.$os->id.' · Orçamento em andamento - ABC1D23');

    $livre = $this->makeOs(['subject' => 'Troca de embreagem', 'workshop_status' => ServiceOrder::STAGE_EM_EXECUCAO]);

    expect($livre->tituloAtual())->toBe('#'.$livre->id.' · Em Execução - Troca de embreagem');

    // Sem sobra de texto: cai na placa do veículo.
    $vazio = $this->makeOs(['subject' => 'Cadastro - ', 'workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    expect($vazio->identificacao())->toBe($vazio->vehicle->placa)
        ->and(ServiceOrder::semPrefixoDeEtapa('Pós-Venda - XYZ9A88'))->toBe('XYZ9A88')
        // Só tira prefixo de etapa de verdade, no começo e seguido de " - ".
        ->and(ServiceOrder::semPrefixoDeEtapa('Revisão do Cadastro - antigo'))->toBe('Revisão do Cadastro - antigo');
});

it('a tela da OS e a lista mostram a etapa atual no título, nunca "Cadastro" numa OS que já está adiante', function () {
    $os = $this->makeOs(['subject' => 'Cadastro - ABC1D23', 'workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    $this->actingAs($this->makeUser('proprietario'), 'user');

    $this->get(route('admin.service_orders.view', $os->id))
        ->assertOk()
        ->assertSee('#'.$os->id.' · Orçamento em andamento - ABC1D23');

    // A lista (coluna "Serviço") usa o mesmo método e mostra só a identificação: a etapa tem coluna própria.
    expect(ServiceOrder::semPrefixoDeEtapa($os->subject))->toBe('ABC1D23');
});

// ---- Datas em português ----------------------------------------------------------------------

it('datas do sistema saem em dd/mm/aaaa hh:mm, sem mês em inglês nem AM/PM', function () {
    expect(core()->formatDate('2026-10-20 09:15:00'))->toBe('20/10/2026 09:15')
        ->and(core()->formatDate('2026-10-20 21:05:00'))->toBe('20/10/2026 21:05')
        ->and(core()->formatDate('2026-10-20 21:05:00', 'd/m/Y'))->toBe('20/10/2026');

    // Nenhum formato de data em inglês nos pontos que alimentam as telas do dia a dia.
    $proibidos = ["'d M Y h:iA'", "'D M d, Y", "'M d, Y"];
    $achados = [];

    foreach ([
        'packages/Webkul/Core/src/Core.php',
        'packages/Webkul/Attribute/src/Repositories/AttributeValueRepository.php',
        'packages/Webkul/Automation/src/Helpers/Entity/AbstractEntity.php',
        'packages/Webkul/Admin/src/DataGrids/Quote/QuoteDataGrid.php',
    ] as $arquivo) {
        $texto = file_get_contents(base_path($arquivo));

        foreach ($proibidos as $formato) {
            if (str_contains($texto, $formato)) {
                $achados[] = $arquivo.' '.$formato;
            }
        }
    }

    expect($achados)->toBe([]);
});

// ---- Mensagens padrão do Laravel em português ------------------------------------------------

it('mensagens de validação, login e senha do Laravel existem em português para todas as regras do inglês', function () {
    foreach (['validation', 'auth', 'passwords', 'pagination'] as $arquivo) {
        $ingles = require base_path("lang/en/{$arquivo}.php");
        $portugues = require base_path("lang/pt_BR/{$arquivo}.php");

        foreach (array_keys($ingles) as $chave) {
            expect($portugues)->toHaveKey($chave);
        }
    }

    expect(trans('validation.required', ['attribute' => 'nome'], 'pt_BR'))->toBe('O campo nome é obrigatório.')
        ->and(trans('validation.max.string', ['attribute' => 'nome', 'max' => 5], 'pt_BR'))->toBe('O campo nome não deve ter mais de 5 caracteres.')
        ->and(trans('auth.failed', [], 'pt_BR'))->toBe('E-mail ou senha incorretos.')
        ->and(trans('passwords.sent', [], 'pt_BR'))->not->toContain('We have');
});
