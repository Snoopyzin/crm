<?php

/**
 * Read-only diagnostic -- reports what the app is actually seeing for the
 * DB config and whether it can connect, without changing anything.
 * DELETE after use, same as deploy-once.php.
 */

$secretKey = 'TROQUE-ESTA-CHAVE';

header('Content-Type: text/plain; charset=utf-8');

if (! hash_equals($secretKey, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo "403 -- use ?key=...\n";
    exit;
}

echo "=== 1) .env RAW no disco (o que o arquivo realmente tem) ===\n";
$envPath = __DIR__.'/../.env';
$rawEnv = file_get_contents($envPath);
foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'APP_URL', 'APP_ENV'] as $key) {
    if (preg_match("/^{$key}=(.*)$/m", $rawEnv, $m)) {
        $val = $key === 'DB_PASSWORD' ? '(oculto)' : trim($m[1]);
        echo "{$key} = {$val}\n";
    } else {
        echo "{$key} = (NÃO ENCONTRADO no arquivo)\n";
    }
}

echo "\n=== 2) Cache de config existente? (se sim, é isso que está travando) ===\n";
$configCache = __DIR__.'/../bootstrap/cache/config.php';
echo file_exists($configCache) ? "EXISTE bootstrap/cache/config.php -- config.php TEM PRIORIDADE sobre .env, isso explica tudo.\n" : "Não existe cache de config (ok, .env deveria valer).\n";

require __DIR__.'/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../bootstrap/app.php';

/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n=== 3) O que o Laravel está USANDO de verdade (depois de bootar) ===\n";
echo 'DB_CONNECTION (config) = '.config('database.default')."\n";
echo 'DB_HOST (config) = '.config('database.connections.mysql.host')."\n";
echo 'DB_PORT (config) = '.config('database.connections.mysql.port')."\n";
echo 'DB_DATABASE (config) = '.config('database.connections.mysql.database')."\n";
echo 'DB_USERNAME (config) = '.config('database.connections.mysql.username')."\n";
echo 'APP_URL (config) = '.config('app.url')."\n";
echo 'APP_ENV (config) = '.config('app.env')."\n";

echo "\n=== 4) Conexão com o banco ===\n";
try {
    \Illuminate\Support\Facades\DB::connection()->getPDO();
    echo "Conectou com sucesso no banco: ".\Illuminate\Support\Facades\DB::connection()->getDatabaseName()."\n";

    $hasUsers = \Illuminate\Support\Facades\Schema::hasTable('users');
    echo 'Tabela "users" existe? '.($hasUsers ? 'SIM' : 'NÃO')."\n";

    if ($hasUsers) {
        $count = \Illuminate\Support\Facades\DB::table('users')->count();
        echo "Quantidade de linhas em users: {$count}\n";

        if ($count > 0) {
            $emails = \Illuminate\Support\Facades\DB::table('users')->pluck('email');
            echo 'E-mails cadastrados: '.$emails->implode(', ')."\n";
        }
    }

    echo "\nResultado do isInstalled() (o que decide se mostra /install ou não): ";
    echo $app->make(\Webkul\Installer\Helpers\DatabaseManager::class)->isInstalled() ? "TRUE (deveria abrir o login)\n" : "FALSE (por isso está indo pro /install)\n";
} catch (\Throwable $e) {
    echo 'FALHOU AO CONECTAR: '.get_class($e).': '.$e->getMessage()."\n";
}
