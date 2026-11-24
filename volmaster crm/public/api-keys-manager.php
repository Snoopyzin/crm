<?php

/**
 * Standalone, key-protected manager for Chaves de API (Focus NFe, Asaas
 * etc.) AND Dados Fiscais (CNPJ, inscrições, regime tributário etc.) --
 * lives OUTSIDE the admin panel entirely on purpose. Both are filled in by
 * Volmaster Tech itself for each client at onboarding, not by the client, so
 * no logged-in CRM user, regardless of role -- including a full
 * "Administrador" account, which bypasses normal ACL checks entirely -- can
 * see or edit either of these through the app itself anymore (see
 * Admin\Http\Controllers\Settings\ApiKeyController and
 * Fiscal\Http\Controllers\FiscalSettingsController, which now hard-block
 * every action). This script is the only way to manage them, reachable only
 * by whoever knows this exact URL + key.
 *
 * Unlike migrate-safe.php / deploy-once.php, do NOT delete this file after
 * use -- it's meant to stay available long-term. Just keep the URL + key to
 * yourself.
 */

$secretKey = 'volmaster-keys-2026-x7q';

header('Content-Type: text/html; charset=utf-8');

$providedKey = (string) ($_GET['key'] ?? $_POST['key'] ?? '');

if (! hash_equals($secretKey, $providedKey)) {
    http_response_code(403);
    echo '<h1>403</h1><p>Chave ausente ou incorreta. Use ?key=... na URL.</p>';
    exit;
}

require __DIR__.'/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../bootstrap/app.php';

/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Webkul\Admin\Models\ApiKey;
use Webkul\Fiscal\Models\FiscalSettings;

$message = null;
$messageType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = trim((string) ($_POST['id'] ?? ''));
            $nome = trim((string) ($_POST['nome'] ?? ''));
            $tipo = trim((string) ($_POST['tipo'] ?? ''));
            $valor = (string) ($_POST['valor'] ?? '');
            $observacoes = trim((string) ($_POST['observacoes'] ?? ''));

            if ($nome === '') {
                throw new \RuntimeException('Nome é obrigatório.');
            }

            if ($id !== '') {
                $apiKey = ApiKey::findOrFail((int) $id);

                $data = ['nome' => $nome, 'tipo' => $tipo ?: null, 'observacoes' => $observacoes ?: null];

                if ($valor !== '') {
                    $data['valor'] = $valor;
                }

                $apiKey->update($data);

                $message = 'Chave "'.htmlspecialchars($nome).'" atualizada.';
            } else {
                if ($valor === '') {
                    throw new \RuntimeException('Valor é obrigatório pra criar uma chave nova.');
                }

                ApiKey::create([
                    'nome' => $nome,
                    'tipo' => $tipo ?: null,
                    'valor' => $valor,
                    'observacoes' => $observacoes ?: null,
                ]);

                $message = 'Chave "'.htmlspecialchars($nome).'" criada.';
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $apiKey = ApiKey::findOrFail($id);
            $nome = $apiKey->nome;
            $apiKey->delete();

            $message = 'Chave "'.htmlspecialchars($nome).'" excluída.';
        } elseif ($action === 'save_fiscal') {
            $fiscalSettings = FiscalSettings::current();

            $fiscalSettings->update([
                'razao_social' => trim((string) ($_POST['razao_social'] ?? '')) ?: null,
                'nome_fantasia' => trim((string) ($_POST['nome_fantasia'] ?? '')) ?: null,
                'cnpj' => trim((string) ($_POST['cnpj'] ?? '')) ?: null,
                'inscricao_municipal' => trim((string) ($_POST['inscricao_municipal'] ?? '')) ?: null,
                'inscricao_estadual' => trim((string) ($_POST['inscricao_estadual'] ?? '')) ?: null,
                'cfop_padrao' => trim((string) ($_POST['cfop_padrao'] ?? '')) ?: '5102',
                'regime_tributario' => trim((string) ($_POST['regime_tributario'] ?? '')) ?: null,
                'codigo_municipio' => trim((string) ($_POST['codigo_municipio'] ?? '')) ?: null,
                'uf' => trim((string) ($_POST['uf'] ?? '')) ?: null,
                'optante_simples_nacional' => isset($_POST['optante_simples_nacional']),
                'item_lista_servico' => trim((string) ($_POST['item_lista_servico'] ?? '')) ?: null,
                'aliquota_iss' => trim((string) ($_POST['aliquota_iss'] ?? '')) ?: null,
            ]);

            $message = 'Dados fiscais atualizados.';
        }
    } catch (\Throwable $e) {
        $message = 'Erro: '.htmlspecialchars($e->getMessage());
        $messageType = 'fail';
    }
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = $editId ? ApiKey::find($editId) : null;

$mask = function (?string $value): string {
    if (! $value) {
        return '-';
    }

    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }

    return substr($value, 0, 4).str_repeat('*', max(4, strlen($value) - 8)).substr($value, -4);
};

$keys = ApiKey::orderBy('nome')->get();

$fiscalSettings = FiscalSettings::current();

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Configuração de Cliente — Volmaster Tech</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:820px;margin:40px auto;padding:0 16px;color:#1f2937}
h1{margin-bottom:4px}
.hint{color:#6b7280;font-size:13px;margin-bottom:24px}
table{width:100%;border-collapse:collapse;margin-bottom:32px;font-size:14px}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #e5e7eb}
th{color:#6b7280;font-weight:600;font-size:12px;text-transform:uppercase}
code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:12.5px}
form.inline{display:inline}
a.btn,button{font-size:12.5px;padding:4px 10px;border-radius:6px;border:1px solid #d1d5db;background:#fff;cursor:pointer;color:#1f2937;text-decoration:none}
button.danger{border-color:#fca5a5;color:#b91c1c}
.card{border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:24px}
.card h2{margin-top:0;font-size:16px}
label{display:block;font-size:12.5px;color:#374151;margin:12px 0 4px}
input[type=text],select,textarea{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px}
.row{display:flex;gap:12px}
.row > div{flex:1}
.primary{background:#0e90d9;color:#fff;border-color:#0e90d9;margin-top:16px}
.ok{color:#15803d}.fail{color:#b91c1c}
.warn{background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-top:24px;font-size:13px}
</style>
</head>
<body>

<h1>Configuração de Cliente</h1>
<p class="hint">Chaves de API e Dados Fiscais. Nada disso existe dentro do painel — só quem tem esse link consegue acessar.</p>

<?php if ($message && ($action ?? '') !== 'save_fiscal'): ?>
    <p class="<?= $messageType ?>"><strong><?= $message ?></strong></p>
<?php endif; ?>

<h2 style="margin-bottom:4px">Chaves de API</h2>
<p class="hint" style="margin-top:0">Focus NFe, Asaas etc.</p>

<table>
    <thead>
        <tr><th>Nome</th><th>Tipo</th><th>Valor</th><th>Observações</th><th></th></tr>
    </thead>
    <tbody>
        <?php if ($keys->isEmpty()): ?>
            <tr><td colspan="5">Nenhuma chave cadastrada ainda.</td></tr>
        <?php endif; ?>

        <?php foreach ($keys as $key): ?>
            <tr>
                <td><?= htmlspecialchars($key->nome) ?></td>
                <td><?= htmlspecialchars($key->tipo ?: '-') ?></td>
                <td><code><?= htmlspecialchars($mask($key->valor)) ?></code></td>
                <td><?= htmlspecialchars($key->observacoes ?: '-') ?></td>
                <td style="white-space:nowrap">
                    <a class="btn" href="?key=<?= urlencode($providedKey) ?>&edit=<?= $key->id ?>">editar</a>
                    <form class="inline" method="POST" onsubmit="return confirm('Excluir esta chave?');">
                        <input type="hidden" name="key" value="<?= htmlspecialchars($providedKey) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $key->id ?>">
                        <button type="submit" class="danger">excluir</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="card">
    <h2><?= $editing ? 'Editar chave' : 'Nova chave' ?></h2>

    <form method="POST">
        <input type="hidden" name="key" value="<?= htmlspecialchars($providedKey) ?>">
        <input type="hidden" name="action" value="save">
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= $editing->id ?>">
        <?php endif; ?>

        <div class="row">
            <div>
                <label>Nome</label>
                <input type="text" name="nome" value="<?= htmlspecialchars($editing->nome ?? '') ?>" required>
            </div>
            <div>
                <label>Tipo</label>
                <select name="tipo">
                    <option value="" <?= ! ($editing->tipo ?? null) ? 'selected' : '' ?>>Outro / genérico</option>
                    <option value="focusnfe" <?= ($editing->tipo ?? '') === 'focusnfe' ? 'selected' : '' ?>>Focus NFe (nota fiscal)</option>
                    <option value="asaas" <?= ($editing->tipo ?? '') === 'asaas' ? 'selected' : '' ?>>Asaas (boleto/Pix/cartão)</option>
                </select>
            </div>
        </div>

        <label>Valor <?= $editing ? '(deixe em branco pra manter o valor atual)' : '' ?></label>
        <input type="text" name="valor" <?= $editing ? '' : 'required' ?>>

        <label>Observações</label>
        <textarea name="observacoes" rows="2"><?= htmlspecialchars($editing->observacoes ?? '') ?></textarea>

        <button type="submit" class="primary"><?= $editing ? 'Salvar alterações' : 'Criar chave' ?></button>
        <?php if ($editing): ?>
            <a class="btn" href="?key=<?= urlencode($providedKey) ?>">cancelar</a>
        <?php endif; ?>
    </form>
</div>

<h2 id="dados-fiscais" style="margin-bottom:4px">Dados Fiscais</h2>
<p class="hint" style="margin-top:0">CNPJ, inscrições e regime tributário -- preenche uma vez por cliente, na hora do onboarding.</p>

<?php if ($message && ($action ?? '') === 'save_fiscal'): ?>
    <p class="<?= $messageType ?>"><strong><?= $message ?></strong></p>
<?php endif; ?>

<div class="card">
    <form method="POST" action="#dados-fiscais">
        <input type="hidden" name="key" value="<?= htmlspecialchars($providedKey) ?>">
        <input type="hidden" name="action" value="save_fiscal">

        <div class="row">
            <div>
                <label>Razão Social</label>
                <input type="text" name="razao_social" value="<?= htmlspecialchars($fiscalSettings->razao_social ?? '') ?>">
            </div>
            <div>
                <label>Nome Fantasia</label>
                <input type="text" name="nome_fantasia" value="<?= htmlspecialchars($fiscalSettings->nome_fantasia ?? '') ?>">
            </div>
        </div>

        <div class="row">
            <div>
                <label>CNPJ</label>
                <input type="text" name="cnpj" value="<?= htmlspecialchars($fiscalSettings->cnpj ?? '') ?>">
            </div>
            <div>
                <label>UF</label>
                <input type="text" name="uf" maxlength="2" value="<?= htmlspecialchars($fiscalSettings->uf ?? '') ?>">
            </div>
        </div>

        <p class="hint" style="margin:16px 0 4px">Pra NFS-e (serviço)</p>
        <div class="row">
            <div>
                <label>Inscrição Municipal</label>
                <input type="text" name="inscricao_municipal" value="<?= htmlspecialchars($fiscalSettings->inscricao_municipal ?? '') ?>">
            </div>
            <div>
                <label>Código do Município (IBGE)</label>
                <input type="text" name="codigo_municipio" value="<?= htmlspecialchars($fiscalSettings->codigo_municipio ?? '') ?>">
            </div>
        </div>
        <div class="row">
            <div>
                <label>Item da Lista de Serviço (LC 116)</label>
                <input type="text" name="item_lista_servico" placeholder="14.01" value="<?= htmlspecialchars($fiscalSettings->item_lista_servico ?? '') ?>">
            </div>
            <div>
                <label>Alíquota ISS (%)</label>
                <input type="text" name="aliquota_iss" value="<?= htmlspecialchars($fiscalSettings->aliquota_iss ?? '') ?>">
            </div>
        </div>

        <p class="hint" style="margin:16px 0 4px">Pra NF-e (peça)</p>
        <div class="row">
            <div>
                <label>Inscrição Estadual</label>
                <input type="text" name="inscricao_estadual" value="<?= htmlspecialchars($fiscalSettings->inscricao_estadual ?? '') ?>">
            </div>
            <div>
                <label>CFOP Padrão</label>
                <input type="text" name="cfop_padrao" placeholder="5102" value="<?= htmlspecialchars($fiscalSettings->cfop_padrao ?? '') ?>">
            </div>
        </div>
        <div class="row">
            <div>
                <label>Regime Tributário</label>
                <select name="regime_tributario">
                    <option value="" <?= ! $fiscalSettings->regime_tributario ? 'selected' : '' ?>>-</option>
                    <option value="1" <?= $fiscalSettings->regime_tributario === '1' ? 'selected' : '' ?>>1 - Simples Nacional</option>
                    <option value="2" <?= $fiscalSettings->regime_tributario === '2' ? 'selected' : '' ?>>2 - Simples Nacional (excesso de sublimite)</option>
                    <option value="3" <?= $fiscalSettings->regime_tributario === '3' ? 'selected' : '' ?>>3 - Regime Normal</option>
                </select>
            </div>
            <div>
                <label style="margin-top:26px">
                    <input type="checkbox" name="optante_simples_nacional" style="width:auto;display:inline-block" <?= $fiscalSettings->optante_simples_nacional ? 'checked' : '' ?>>
                    Optante pelo Simples Nacional
                </label>
            </div>
        </div>

        <button type="submit" class="primary">Salvar Dados Fiscais</button>
    </form>
</div>

<div class="warn">
    Guarde esse link só com você. Ele nunca deve ir pro cliente que comprar o sistema — é assim que a gente garante que ele não vê nem mexe nas credenciais da Focus NFe/Asaas nem nos dados fiscais da empresa.
</div>

</body>
</html>
