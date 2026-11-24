<?php

// Somente leitura: compara chaves entre en e pt_BR de cada pacote e lista
// valores de pt_BR que ainda parecem inglês (idênticos ao en ou com palavras inglesas comuns).

$base = 'd:/volmaster crm/volmaster crm/packages/Webkul';

function flat(array $a, string $prefix = ''): array
{
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
        if (is_array($v)) {
            $out += flat($v, $key);
        } else {
            $out[$key] = (string) $v;
        }
    }

    return $out;
}

$permitidos = ['CSV', 'XLS', 'XLSX', 'PDF', 'OK', 'SMS', 'NFS-e', 'NF-e', 'API', 'URL', 'ID', 'E-mail', 'Email', 'WhatsApp', 'Pix', 'Dashboard', 'Download', 'Google Contacts', 'Webhook', 'Webhooks', 'Token', 'Marketing', 'Status', 'Total', 'Cookie', 'Backup', 'Login', 'Logo', 'Online', 'Offline', 'Admin', 'Impact', 'Tags', 'Lead', 'Leads', 'Link', 'Chat'];

$only = $argv[1] ?? null;
$verbose = in_array('-v', $argv, true);

foreach (glob($base.'/*', GLOB_ONLYDIR) as $dir) {
    $pkg = basename($dir);
    $enFile = $dir.'/src/Resources/lang/en/app.php';
    $ptFile = $dir.'/src/Resources/lang/pt_BR/app.php';

    if (! is_file($enFile) && ! is_file($ptFile)) {
        continue;
    }

    if ($only && $only !== $pkg) {
        continue;
    }

    $en = is_file($enFile) ? flat(include $enFile) : [];
    $pt = is_file($ptFile) ? flat(include $ptFile) : [];

    $faltando = array_diff_key($en, $pt);
    $extras = array_diff_key($pt, $en);
    $iguais = [];

    foreach ($pt as $k => $v) {
        if (isset($en[$k]) && $en[$k] === $v && preg_match('/[A-Za-z]{3,}/', $v) && ! in_array($v, $permitidos, true)) {
            $iguais[$k] = $v;
        }
    }

    printf("== %s: en=%d pt_BR=%d | faltando em pt_BR=%d | so em pt_BR=%d | identicos ao en=%d\n", $pkg, count($en), count($pt), count($faltando), count($extras), count($iguais));

    if ($verbose) {
        foreach ($faltando as $k => $v) {
            echo "   FALTA  $k => ".mb_substr($v, 0, 90)."\n";
        }
        foreach ($iguais as $k => $v) {
            echo "   IGUAL  $k => ".mb_substr($v, 0, 90)."\n";
        }
    }
}
