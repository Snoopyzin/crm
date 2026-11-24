<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Services\Perfis\LoginsDemonstracao;

/**
 * Cria os logins de demonstração (Gerente, Consultor, Chefe de oficina e Técnico) na instalação
 * principal, para mostrar o sistema ao patrão. Cada login usa a função oficial da mentoria do seu
 * perfil. As senhas são sorteadas, aparecem só nesta tela e rodar de novo troca as senhas.
 *
 *   ?key=...             obrigatório (a chave que você recebeu junto com este arquivo)
 *   &simular=1           só mostra o que seria criado; não grava nada
 *
 * Não apaga nem altera nenhum outro usuário. O arquivo se apaga sozinho depois de criar os logins
 * (se não conseguir, apague public/criar-logins-demo.php à mão).
 */
$secretKey = 'TROQUE-ESTA-CHAVE-DEMO';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

if (! hash_equals($secretKey, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo '<h1>403</h1><p>Chave ausente ou incorreta. Use ?key=... na URL.</p>';
    exit;
}

$e = static fn ($texto) => htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
$simular = ! empty($_GET['simular']);

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::prohibitDestructiveCommands(true);

echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Logins de demonstração — Volmaster Tech</title>';
echo '<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:860px;margin:40px auto;padding:0 16px;color:#1f2937}
table{border-collapse:collapse;width:100%;font-size:15px;margin-top:8px}th,td{border-bottom:1px solid #e5e7eb;padding:8px;text-align:left}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:15px}
.ok{color:#15803d}.fail{color:#b91c1c}.warn{background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-top:24px}</style></head><body>';

try {
    $logins = $app->make(LoginsDemonstracao::class);

    if ($simular) {
        echo '<h1>Simulação: logins de demonstração</h1><p><strong>Nada foi gravado.</strong> Seriam criados ou atualizados:</p><table><tr><th>Perfil</th><th>E-mail</th><th>Situação</th></tr>';

        foreach ($logins->simular() as $linha) {
            echo '<tr><td>'.$e($linha['perfil']).'</td><td>'.$e($linha['email']).'</td><td>'.($linha['existe'] ? 'já existe (a senha seria trocada)' : 'seria criado').'</td></tr>';
        }

        echo '</table></body></html>';
        exit;
    }

    $criados = $logins->criar();
} catch (Throwable $erro) {
    http_response_code(500);
    echo '<h1 class="fail">Não foi possível criar os logins</h1><p>Nenhum login foi alterado. Mande o texto abaixo para quem cuida do sistema:</p><pre>'.$e($erro->getMessage()).'</pre></body></html>';
    exit;
}

echo '<h1 class="ok">Logins de demonstração prontos</h1>';
echo '<p>Anote agora: <strong>as senhas aparecem só nesta tela.</strong> Se perder, rode este arquivo de novo (se ele ainda existir) e as senhas serão trocadas.</p>';
echo '<table><tr><th>Perfil</th><th>Entrar com</th><th>Senha</th><th>Função</th><th></th></tr>';

foreach ($criados as $linha) {
    echo '<tr><td>'.$e($linha['nome']).'</td><td><code>'.$e($linha['email']).'</code></td><td><code>'.$e($linha['senha']).'</code></td><td>'.$e($linha['funcao']).'</td><td>'.$e($linha['acao']).'</td></tr>';
}

echo '</table>';

$apagado = @unlink(__FILE__);

echo '<div class="warn">'.($apagado
    ? 'Este arquivo (<code>public/criar-logins-demo.php</code>) já foi apagado do servidor.'
    : '<strong>Apague agora</strong> o arquivo <code>public/criar-logins-demo.php</code> no Gerenciador de Arquivos: não foi possível apagá-lo sozinho.').'</div>';
echo '</body></html>';
