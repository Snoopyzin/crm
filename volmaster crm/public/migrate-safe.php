<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Webkul\Tenant\Services\TenantMigrator;

/**
 * Read-and-add-only migration runner -- unlike deploy-once.php, this does
 * NOT refuse on an already-installed app (that's the whole point: the app
 * can be installed with real users while still missing newer, additive
 * migrations that were never run because the visual installer / deploy-once
 * both skip migrating a database that already looks installed).
 *
 * Runs `php artisan migrate --force` ONLY -- first on the main installation
 * and then, one by one, on the database of EVERY active workshop (each
 * workshop has its own database; the plain `migrate` only sees the main one,
 * which left the workshops without the new tables). Never migrate:fresh,
 * never touches existing data, never creates a database -- Laravel's migrator
 * only runs migrations that haven't run yet and only ever adds tables/columns
 * here (nothing in this codebase's migrations drops or renames existing data).
 *
 * Optional parameters (all validated):
 *   simular=1                 only lists what is pending; changes nothing
 *   escopo=todas|principal|oficinas   (default: todas)
 *   oficina=<endereco>        a single workshop (implies escopo=oficinas)
 *
 * The raw framework output inside <pre>/<details> stays in English (it is the
 * Laravel log).
 *
 * DELETE THIS FILE right after use -- same reasoning as deploy-once.php.
 */
$secretKey = 'TROQUE-ESTA-CHAVE';

header('Content-Type: text/html; charset=utf-8');

if (! hash_equals($secretKey, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo '<h1>403</h1><p>Chave ausente ou incorreta. Use ?key=... na URL.</p>';
    exit;
}

// Closing the tab or running out of time would leave a database half-migrated.
ignore_user_abort(true);
@set_time_limit(0);

$e = static fn ($texto) => htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');

$simular = ! empty($_GET['simular']);
$escopo = (string) ($_GET['escopo'] ?? 'todas');
$oficina = isset($_GET['oficina']) ? (string) $_GET['oficina'] : '';

if (! in_array($escopo, ['todas', 'principal', 'oficinas'], true)
    || ($oficina !== '' && ! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $oficina))) {
    http_response_code(422);
    echo '<h1>422</h1><p>Parâmetros inválidos. Use escopo=todas|principal|oficinas e oficina=endereco-da-oficina.</p>';
    exit;
}

if ($oficina !== '') {
    $escopo = 'oficinas';
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Safety net: this page never runs fresh/refresh/reset/rollback/wipe.
DB::prohibitDestructiveCommands(true);

$flush = static function () {
    if (ob_get_level() > 0) {
        @ob_flush();
    }

    @flush();
};

echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Migrar — Volmaster Tech</title>';
echo '<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:860px;margin:40px auto;padding:0 16px;color:#1f2937}
pre{background:#0b1220;color:#d7e2ec;padding:16px;border-radius:8px;overflow:auto;font-size:12.5px;line-height:1.5}
table{border-collapse:collapse;width:100%;font-size:14px;margin-top:8px}th,td{border-bottom:1px solid #e5e7eb;padding:6px 8px;text-align:left;vertical-align:top}
details{margin:2px 0 8px}summary{cursor:pointer;color:#475569;font-size:12.5px}
.ok{color:#15803d}.fail{color:#b91c1c}.warn{background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-top:24px}</style></head><body>';
echo '<h1>'.($simular ? 'Simulação: o que está pendente' : 'Rodando as migrations pendentes').'</h1>';

if ($simular) {
    echo '<p><strong>Simulação:</strong> nada será alterado em nenhum banco.</p>';
}

$principalOk = true;

// ---- Passo 1: instalação principal ---------------------------------------
if ($escopo !== 'oficinas') {
    echo '<h2>1. Instalação principal</h2>';

    try {
        if ($simular) {
            $pendentes = $app->make(TenantMigrator::class)->pendentesDaPrincipal();

            echo $pendentes === []
                ? '<p class="ok"><strong>Em dia.</strong> Nada pendente na instalação principal.</p>'
                : '<p>'.count($pendentes).' migration(s) pendente(s):</p><pre>'.$e(implode("\n", $pendentes)).'</pre>';
        } else {
            $exitCode = $kernel->call('migrate', ['--force' => true]);
            $output = $kernel->output();

            echo '<pre>'.$e($output).'</pre>';

            if ($exitCode === 0) {
                echo '<p class="ok"><strong>OK.</strong> Instalação principal em dia.</p>';
            } else {
                $principalOk = false;
                echo '<p class="fail"><strong>Terminou com código '.(int) $exitCode.'.</strong> Veja o log acima.</p>';
            }
        }
    } catch (Throwable $erro) {
        $principalOk = false;
        echo '<p class="fail">FALHOU: '.$e($erro->getMessage()).'</p>';
        echo '<pre>'.$e($erro->getTraceAsString()).'</pre>';
    }

    $flush();
}

// ---- Passo 2: bancos das oficinas ----------------------------------------
if ($escopo !== 'principal') {
    echo '<h2>2. Oficinas</h2>';

    if (! $principalOk && $escopo === 'todas') {
        echo '<p class="fail"><strong>As oficinas NÃO foram tocadas</strong> porque a instalação principal falhou. Corrija e rode de novo.</p>';
    } elseif (! class_exists(TenantMigrator::class)) {
        echo '<p>Este servidor não tem o cadastro de oficinas; nada a fazer aqui.</p>';
    } else {
        try {
            $limite = (int) ini_get('max_execution_time');
            $tempo = $limite > 0 ? max(10, (int) floor($limite * 0.7)) : null;

            echo '<table><tr><th>Oficina</th><th>Endereço</th><th>Pendentes</th><th>Resultado</th></tr>';

            $resultado = $app->make(TenantMigrator::class)->migrarTodas(
                null,
                $simular,
                $oficina !== '' ? $oficina : null,
                $tempo,
                function (array $linha) use ($e, $flush) {
                    $classe = $linha['situacao'] === TenantMigrator::FALHOU ? 'fail' : 'ok';

                    echo '<tr><td>'.$e($linha['oficina']).'</td><td>'.$e($linha['subdominio']).'</td><td>'.count($linha['pendentes']).'</td>'
                        .'<td class="'.$classe.'"><strong>'.$e(TenantMigrator::ROTULOS[$linha['situacao']]).'</strong> '.$e($linha['mensagem'])
                        .($linha['saida'] !== '' ? '<details><summary>Saída bruta</summary><pre>'.$e($linha['saida']).'</pre></details>' : '')
                        .'</td></tr>';

                    $flush();
                },
            );

            echo '</table>';

            $r = $resultado['resumo'];

            echo '<p>'.(int) $r['total'].' oficina(s): '.((int) $r[TenantMigrator::MIGRADA] + (int) $r[TenantMigrator::SIMULADA]).' com migrations '.($simular ? 'pendentes' : 'aplicadas')
                .', '.(int) $r[TenantMigrator::EM_DIA].' em dia, '.(int) $r[TenantMigrator::FALHOU].' falhou/falharam, '
                .(int) $r[TenantMigrator::IGNORADA].' ignorada(s), '.(int) $r[TenantMigrator::ADIADA].' adiada(s).</p>';

            if ($resultado['incompleto']) {
                echo '<p class="fail"><strong>Faltaram oficinas por falta de tempo.</strong> Recarregue esta página para continuar.</p>';
            } elseif ($resultado['falhas'] > 0) {
                echo '<p class="fail"><strong>Alguma oficina falhou.</strong> As outras foram migradas; veja a linha da oficina acima. Rodar de novo é seguro.</p>';
            } elseif ($r['total'] === 0) {
                echo '<p>Nenhuma oficina ativa ou suspensa encontrada.</p>';
            } elseif (! $simular) {
                echo '<p class="ok"><strong>OK.</strong> Oficinas em dia. Teste a Visão do mentor e o Cadastro / Nova OS de novo.</p>';
            }
        } catch (Throwable $erro) {
            echo '</table><p class="fail">FALHOU: '.$e($erro->getMessage()).'</p>';
        }
    }
}

echo '<div class="warn"><strong>Apague este arquivo</strong> (public/migrate-safe.php) depois de usar.</div>';
echo '</body></html>';
