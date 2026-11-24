<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Webkul\ServiceOrder\Console\Commands\WorkshopBackupCommand;
use Webkul\ServiceOrder\Models\AlcadaPedido;
use Webkul\ServiceOrder\Models\ServiceOrder;

it('com o piloto ligado, cada cargo entra direto na própria tela', function () {
    $this->enablePilot();

    $dono = $this->makeUser('proprietario', ['painel_dono', 'rotina_painel']);
    $chefe = $this->makeUser('chefe', ['rotina_painel']);
    $tecnico = $this->makeUser('tecnico', ['tecnico']);
    $semPapel = $this->makeUser('consultor', ['dashboard', 'rotina_painel']);
    DB::table('roles')->where('id', $semPapel->role_id)->update(['perfil_oficina' => null]);
    $semPermissao = $this->makeUser('financeiro', ['dashboard', 'service_orders']);

    $this->actingAs($dono, 'user')->get(route('admin.dashboard.index'))->assertRedirect(route('admin.mentoria.painel'));
    $this->actingAs($chefe, 'user')->get(route('admin.dashboard.index'))->assertRedirect(route('admin.rotina.painel'));
    $this->actingAs($tecnico, 'user')->get(route('admin.dashboard.index'))->assertRedirect(route('admin.tecnico.index'));

    // Sem papel da oficina, ou sem permissão para a tela do cargo: o dashboard de sempre.
    $this->actingAs($semPapel, 'user')->get(route('admin.dashboard.index'))->assertOk();
    $this->actingAs($semPermissao, 'user')->get(route('admin.dashboard.index'))->assertOk();
});

it('com o piloto desligado, o dashboard antigo continua igual', function () {
    $dono = $this->makeUser('proprietario', ['dashboard', 'painel_dono', 'rotina_painel']);

    $this->actingAs($dono, 'user')->get(route('admin.dashboard.index'))->assertOk();
});

it('a página de acompanhamento do cliente não mostra crédito, valores nem dados de outros clientes', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO]);
    $outra = $this->makeOs();
    $os->forceFill(['cor_credito' => 'vermelho', 'grand_total' => 98765.43, 'condicao_pagamento' => 'Boleto 30 dias', 'negociacao_observacoes' => 'Cliente negocia desconto'])->save();

    $html = $this->get(URL::signedRoute('service_orders.tracking.show', $os->id))->assertOk()->getContent();

    foreach (['vermelho', '98.765', '98765', 'Boleto 30 dias', 'Cliente negocia desconto', 'cor_credito', (string) $outra->vehicle->placa] as $proibido) {
        expect($html)->not->toContain($proibido);
    }

    // Sem assinatura, o link não abre.
    $this->get(route('service_orders.tracking.show', $os->id))->assertForbidden();
});

it('negociação: o cartão "descontos dentro da alçada" conta as OS com desconto que não precisaram de aprovação', function () {
    $this->enablePilot();
    $gestor = $this->makeUser('proprietario', ['indicadores_mentoria', 'cadastro', 'ordem_servico', 'diagnostico', 'orcamento', 'negociacao', 'em_execucao', 'veiculo_teste', 'faturamento', 'pos_venda']);
    $this->at('2026-11-18 10:00:00');

    $comPedido = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO]);
    $semPedido = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO]);

    foreach ([$comPedido, $semPedido] as $os) {
        DB::table('quote_items')->insert(['quote_id' => $os->id, 'product_id' => 0, 'name' => 'Serviço', 'sku' => 'X', 'quantity' => 1, 'price' => 100, 'discount_amount' => 10, 'total' => 90, 'tipo' => 'Serviço', 'created_at' => now(), 'updated_at' => now()]);
    }

    AlcadaPedido::create(['tema' => 'desconto', 'service_order_id' => $comPedido->id, 'descricao' => 'x', 'nivel_necessario' => 'gerente', 'status' => 'aprovado']);

    $this->actingAs($gestor, 'user')->get(route('admin.service_orders.stage.index', [ServiceOrder::STAGE_NEGOCIACAO, 'periodo' => '2026-11']))
        ->assertOk()
        ->assertSee('Descontos dentro da alçada')
        ->assertSee('50,0%');
});

it('backup: apaga os antigos e roda o mysqldump quando ele está disponível', function () {
    $pasta = storage_path('app/backups');
    File::ensureDirectoryExists($pasta);

    $velho = $pasta.'/teste-velho.sql.gz';
    $novo = $pasta.'/teste-novo.sql.gz';
    file_put_contents($velho, 'x');
    file_put_contents($novo, 'x');
    touch($velho, now()->subDays(30)->getTimestamp());

    $comando = new WorkshopBackupCommand;

    expect($comando->limpar(14))->toBeGreaterThanOrEqual(1)
        ->and(file_exists($velho))->toBeFalse()
        ->and(file_exists($novo))->toBeTrue();

    @unlink($novo);

    $candidatos = ['C:\Users\Usuario\dev-tools\mariadb\bin\mysqldump.exe', 'C:\Users\Usuario\dev-tools\mariadb\bin\mariadb-dump.exe'];
    $dump = collect($candidatos)->first(fn ($c) => file_exists($c));

    if (! $dump) {
        $this->markTestSkipped('mysqldump não encontrado nesta máquina.');
    }

    putenv('MYSQLDUMP_PATH='.$dump);

    $saida = Artisan::call('workshop:backup', ['--dias' => 14]);

    $arquivos = File::glob($pasta.'/principal-*.sql.gz');

    expect($saida)->toBe(0)
        ->and($arquivos)->not->toBeEmpty()
        ->and(filesize(end($arquivos)))->toBeGreaterThan(100);

    foreach ($arquivos as $arquivo) {
        @unlink($arquivo);
    }
});

it('backup sem mysqldump avisa o erro em vez de quebrar', function () {
    putenv('MYSQLDUMP_PATH=/caminho/que/nao/existe/mysqldump');

    $saida = Artisan::call('workshop:backup');

    expect($saida)->toBe(1)
        ->and(Artisan::output())->toContain('mysqldump falhou ou não foi encontrado');
});
