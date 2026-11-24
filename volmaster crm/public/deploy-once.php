<?php

/**
 * One-time, no-click installer -- does exactly what the "Bem-vindo ao
 * Volmaster Tech" visual wizard does (write the DB config into .env,
 * generate the app key, migrate, seed base data, create the admin user,
 * mark the app as installed), but via a single URL hit instead of
 * clicking through 6 screens. You do NOT need to edit .env by hand first.
 *
 * Usage:
 *   https://volmastercrm.com/deploy-once.php?key=TROQUE-ESTA-CHAVE
 *     &db_database=SEU_BANCO&db_username=SEU_USUARIO&db_password=SUA_SENHA
 *     &admin_name=SeuNome&admin_email=seu@email.com&admin_password=umaSenhaForte
 *
 * (db_host defaults to 127.0.0.1, db_port to 3306 -- only pass them if
 * HostGator gave you something different.)
 *
 * DELETE THIS FILE right after a successful run -- left in place, anyone
 * who knows (or guesses) the key can re-run it.
 */

$secretKey = 'TROQUE-ESTA-CHAVE';

header('Content-Type: text/html; charset=utf-8');

if (! hash_equals($secretKey, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo '<h1>403</h1><p>Chave ausente ou incorreta. Use ?key=... na URL.</p>';
    exit;
}

// Cheap check, before touching anything: refuse if this already ran.
if (file_exists(__DIR__.'/../storage/installed')) {
    http_response_code(200);
    echo '<h1>Já instalado</h1><p>O arquivo storage/installed já existe — nada foi executado, para não arriscar apagar dados reais. Se você realmente quer reinstalar do zero, apague esse arquivo manualmente pelo Gerenciador de Arquivos antes de acessar esta URL de novo.</p>';
    exit;
}

$dbHost = trim((string) ($_GET['db_host'] ?? '127.0.0.1'));
$dbPort = trim((string) ($_GET['db_port'] ?? '3306'));
$dbDatabase = trim((string) ($_GET['db_database'] ?? ''));
$dbUsername = trim((string) ($_GET['db_username'] ?? ''));
$dbPassword = (string) ($_GET['db_password'] ?? '');
$adminName = trim((string) ($_GET['admin_name'] ?? ''));
$adminEmail = trim((string) ($_GET['admin_email'] ?? ''));
$adminPassword = (string) ($_GET['admin_password'] ?? '');

$missing = [];
if ($dbDatabase === '') $missing[] = 'db_database';
if ($dbUsername === '') $missing[] = 'db_username';
if ($adminName === '') $missing[] = 'admin_name';
if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $missing[] = 'admin_email (válido)';
if (strlen($adminPassword) < 6) $missing[] = 'admin_password (mínimo 6 caracteres)';

if ($missing) {
    http_response_code(422);
    echo '<h1>422</h1><p>Faltando ou inválido: '.htmlspecialchars(implode(', ', $missing)).'</p>';
    exit;
}

echo "<!DOCTYPE html><html lang=\"pt-BR\"><head><meta charset=\"utf-8\"><title>Deploy — Volmaster Tech</title>";
echo '<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:640px;margin:40px auto;padding:0 16px;color:#1f2937}
.ok{color:#15803d}.fail{color:#b91c1c}.warn{background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-top:24px}
li{margin-bottom:6px}</style></head><body>';
echo '<h1>Instalação do Volmaster Tech</h1><ul>';

function step(string $label, callable $fn): void
{
    echo "<li><strong>{$label}</strong>: ";
    try {
        $fn();
        echo '<span class="ok">OK</span></li>';
    } catch (\Throwable $e) {
        echo '<span class="fail">FALHOU — '.htmlspecialchars($e->getMessage()).'</span></li>';
        echo '</ul><p class="fail">Instalação interrompida. Corrija o erro acima e acesse esta URL de novo (é seguro tentar de novo — nada foi criado ainda nas etapas seguintes).</p></body></html>';
        exit;
    }
}

// 1) Write the DB config straight into .env -- same regex-replace the
// visual wizard's "Store Configuration" step uses (EnvironmentManager),
// done here as a raw file edit so it takes effect before Laravel boots.
step('Configurando o banco de dados no .env', function () use ($dbHost, $dbPort, $dbDatabase, $dbUsername, $dbPassword) {
    $envPath = __DIR__.'/../.env';
    $data = file_get_contents($envPath);

    $params = [
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $dbHost,
        'DB_PORT' => $dbPort,
        'DB_DATABASE' => $dbDatabase,
        'DB_USERNAME' => $dbUsername,
        'DB_PASSWORD' => $dbPassword,
    ];

    foreach ($params as $key => $value) {
        if (preg_match('/\s/', $value)) {
            $value = '"'.$value.'"';
        }

        $data = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $data);
    }

    file_put_contents($envPath, $data);
});

require __DIR__.'/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../bootstrap/app.php';

/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 2) Real safety net: refuse if this DB is already populated, even if the
// storage/installed marker somehow got lost (e.g. you re-ran this pointing
// at a db_database that already has real data).
step('Verificando se o banco já está em uso', function () {
    try {
        \Illuminate\Support\Facades\DB::connection()->getPDO();
    } catch (\Throwable $e) {
        throw new \RuntimeException('Não conectou no banco -- confira db_host/db_database/db_username/db_password. Detalhe: '.$e->getMessage());
    }

    if (\Illuminate\Support\Facades\Schema::hasTable('users') && \Illuminate\Support\Facades\DB::table('users')->count() > 0) {
        throw new \RuntimeException('Esse banco já tem uma tabela "users" com dados -- não vou rodar migrate:fresh nele (isso apagaria tudo). Aponte pra um banco vazio.');
    }
});

step('Gerando a chave da aplicação (APP_KEY)', function () use ($app) {
    if (! env('APP_KEY')) {
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->call('key:generate', ['--force' => true]);
    }
});

step('Rodando as migrations', function () use ($app) {
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate:fresh', ['--force' => true]);
});

step('Semeando dados básicos (pipeline, funções, templates)', function () use ($app) {
    $app->make(\Webkul\Installer\Database\Seeders\DatabaseSeeder::class)->run([
        'locale' => 'pt_BR',
        'currency' => 'BRL',
    ]);

    $app->make(\Illuminate\Contracts\Console\Kernel::class)->call('storage:link');
});

step('Criando o usuário administrador', function () use ($adminName, $adminEmail, $adminPassword) {
    \Illuminate\Support\Facades\DB::table('users')->updateOrInsert(
        ['id' => 1],
        [
            'name' => $adminName,
            'email' => $adminEmail,
            'password' => password_hash($adminPassword, PASSWORD_BCRYPT, ['cost' => 10]),
            'role_id' => 1,
            'status' => 1,
        ]
    );
});

step('Marcando a aplicação como instalada', function () {
    file_put_contents(__DIR__.'/../storage/installed', 'Volmaster Tech is successfully installed');

    \Illuminate\Support\Facades\Event::dispatch('volmastertech.installed');
});

echo '</ul><p class="ok"><strong>Instalação concluída.</strong> Acesse <a href="/admin/login">/admin/login</a> e entre com o e-mail e senha que você acabou de definir.</p>';
echo '<div class="warn"><strong>Agora apague este arquivo</strong> (public/deploy-once.php) pelo Gerenciador de Arquivos do cPanel — deixá-lo no ar é um risco de segurança.</div>';
echo '</body></html>';
