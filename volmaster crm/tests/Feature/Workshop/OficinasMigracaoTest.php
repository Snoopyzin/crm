<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Symfony\Component\Process\Process;
use Webkul\ServiceOrder\Services\OficinaPadrao;
use Webkul\Tenant\Models\Tenant;
use Webkul\Tenant\Services\TenantMigrator;

/**
 * Migrations por oficina (cada oficina tem o seu banco): o `migrate` comum só
 * enxerga o banco do .env, e foi isso que deixou a Visão do mentor sem ler as oficinas.
 */
function oficinaParaMigrar(array $campos = []): Tenant
{
    $banco = config('database.connections.mysql');

    return (new Tenant)->setRawAttributes(array_merge([
        'name' => 'Oficina X', 'subdomain' => 'oficina-x', 'db_host' => $banco['host'], 'db_name' => 'krayin_inexistente_x',
        'db_username' => $banco['username'], 'db_password' => Crypt::encryptString((string) $banco['password']), 'status' => 'active',
    ], $campos));
}

it('migração das oficinas ignora a oficina que usa o mesmo banco da instalação principal', function () {
    $resultado = app(TenantMigrator::class)->migrar(oficinaParaMigrar(['db_name' => config('database.connections.mysql.database')]));

    expect($resultado['situacao'])->toBe(TenantMigrator::IGNORADA)
        ->and($resultado['mensagem'])->toContain('instalação principal');
});

it('migração das oficinas ignora cadastro com credenciais incompletas, sem tentar conectar', function () {
    foreach ([['db_name' => 'nome invalido;'], ['db_name' => ''], ['db_username' => '']] as $campos) {
        $resultado = app(TenantMigrator::class)->migrar(oficinaParaMigrar($campos));

        expect($resultado['situacao'])->toBe(TenantMigrator::IGNORADA)
            ->and($resultado['mensagem'])->toContain('Nada foi feito');
    }
});

it('migração das oficinas NUNCA cria um banco que não existe (o migrate --force criaria)', function () {
    Exceptions::fake();

    $nome = 'krayin_inexistente_'.uniqid();

    $resultado = app(TenantMigrator::class)->migrar(oficinaParaMigrar(['db_name' => $nome]));

    expect($resultado['situacao'])->toBe(TenantMigrator::FALHOU)
        ->and(DB::select('select SCHEMA_NAME from information_schema.SCHEMATA where SCHEMA_NAME = ?', [$nome]))->toBeEmpty()
        // A conexão temporária e a configuração dela somem: a próxima oficina nunca a reaproveita.
        ->and(config('database.connections.'.TenantMigrator::CONEXAO))->toBeNull();

    Exceptions::assertReportedCount(1);
});

it('migração das oficinas: a falha de uma oficina não impede as outras e a senha nunca aparece', function () {
    Exceptions::fake();

    $oficinas = collect([
        oficinaParaMigrar(['subdomain' => 'a', 'db_name' => 'krayin_inexistente_a', 'db_password' => Crypt::encryptString('segredo-123')]),
        oficinaParaMigrar(['subdomain' => 'b', 'db_name' => config('database.connections.mysql.database')]),
    ]);

    $resultado = app(TenantMigrator::class)->migrarTodas($oficinas, simular: true);

    expect(collect($resultado['oficinas'])->pluck('situacao')->all())->toBe([TenantMigrator::FALHOU, TenantMigrator::IGNORADA])
        ->and($resultado['falhas'])->toBe(1)
        ->and(json_encode($resultado))->not->toContain('segredo-123');
});

it('tenants:migrate avisa quando a oficina pedida não existe e não toca em nada', function () {
    $this->artisan('tenants:migrate', ['--oficina' => 'nao-existe-xyz', '--simular' => true])
        ->expectsOutputToContain('Nenhuma oficina ativa ou suspensa com o endereço "nao-existe-xyz"')
        ->assertFailed();
});

it('migrate-safe.php: recusa sem a chave, valida parâmetros e a simulação não altera nada', function () {
    $roda = function (array $get): string {
        $codigo = '$_GET = '.var_export($get, true).'; require '.var_export(base_path('public/migrate-safe.php'), true).';';
        $processo = new Process([PHP_BINARY, '-r', $codigo], base_path());
        $processo->run();

        return $processo->getOutput();
    };

    expect($roda([]))->toContain('403')
        ->and($roda(['key' => 'TROQUE-ESTA-CHAVE', 'escopo' => 'tudo']))->toContain('422')
        ->and($roda(['key' => 'TROQUE-ESTA-CHAVE', 'oficina' => 'Nome Inválido!']))->toContain('422');

    $saida = $roda(['key' => 'TROQUE-ESTA-CHAVE', 'simular' => '1', 'escopo' => 'principal']);

    expect($saida)->toContain('Simulação')
        ->and($saida)->toContain('Instalação principal')
        ->and($saida)->toContain('Apague este arquivo')
        ->and($saida)->not->toContain('<h2>2. Oficinas</h2>');
});

// ---- Oficina nova: o que o semeador do instalador apaga -------------------------------

it('oficina nova: refaz os atributos do cliente e o papel do proprietário que o semeador apaga', function () {
    DB::table('attributes')->where('entity_type', 'persons')->whereIn('code', ['cpf_cnpj', 'endereco'])->delete();
    DB::table('roles')->where('id', 1)->update(['permission_type' => 'all', 'perfil_oficina' => null]);

    $feito = app(OficinaPadrao::class)->garantir();

    expect($feito)->toHaveKeys(['cpf_cnpj', 'endereco', 'perfil_proprietario'])
        ->and(DB::table('attributes')->where('entity_type', 'persons')->where('code', 'cpf_cnpj')->count())->toBe(1)
        ->and(DB::table('attributes')->where('entity_type', 'persons')->where('code', 'endereco')->count())->toBe(1)
        ->and(DB::table('roles')->where('id', 1)->value('perfil_oficina'))->toBe('proprietario');

    // Idempotente: rodar de novo não duplica nem muda nada.
    expect(app(OficinaPadrao::class)->garantir())->toBe([])
        ->and(DB::table('attributes')->where('entity_type', 'persons')->where('code', 'cpf_cnpj')->count())->toBe(1);
});

it('oficina nova: o evento de provisionamento aciona o ajuste padrão', function () {
    DB::table('attributes')->where('entity_type', 'persons')->where('code', 'cpf_cnpj')->delete();

    Event::dispatch('tenant.provision.after', new Tenant);

    expect(DB::table('attributes')->where('entity_type', 'persons')->where('code', 'cpf_cnpj')->exists())->toBeTrue();
});
