<?php

// Somente leitura: procura no código chaves de tradução literais (ns::app.x.y) e confere se existem em pt_BR e em en.

$base = 'd:/volmaster crm/volmaster crm/packages/Webkul';

$namespaces = [
    'admin' => 'Admin', 'service_order' => 'ServiceOrder', 'portal' => 'Portal', 'fiscal' => 'Fiscal',
    'partinventory' => 'PartInventory', 'mechanic' => 'Mechanic', 'vehicle' => 'Vehicle', 'workbay' => 'WorkBay',
    'whatsapp' => 'WhatsApp', 'installer' => 'Installer', 'core' => 'Core', 'data_transfer' => 'DataTransfer',
    'web_form' => 'WebForm', 'google_contact' => 'GoogleContact',
];

function achatar(array $a, string $prefix = ''): array
{
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
        if (is_array($v)) {
            $out[$key] = '__array__';
            $out += achatar($v, $key);
        } else {
            $out[$key] = (string) $v;
        }
    }

    return $out;
}

$dic = [];
foreach ($namespaces as $ns => $pkg) {
    foreach (['pt_BR', 'en'] as $loc) {
        $f = "$base/$pkg/src/Resources/lang/$loc/app.php";
        $dic[$ns][$loc] = is_file($f) ? achatar(include $f) : [];
    }
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
$faltam = [];
$desconhecidos = [];

foreach ($it as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/node_modules/') || str_contains($path, '/Migrations/') || str_contains($path, '/Resources/lang/')) {
        continue;
    }
    if (! preg_match('/\.php$/', $path)) {
        continue;
    }
    $c = file_get_contents($path);
    if (! preg_match_all('/([a-z_]+)::app\.([A-Za-z0-9_.\-]+)(?=[\'"])/', $c, $m, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($m as $hit) {
        [$all, $ns, $key] = $hit;
        $rel = str_replace($base.'/', '', $path);
        if (! isset($namespaces[$ns])) {
            $desconhecidos["$ns::app.$key"][$rel] = true;

            continue;
        }
        foreach (['pt_BR', 'en'] as $loc) {
            if (! isset($dic[$ns][$loc][$key])) {
                $faltam[$loc]["$ns::app.$key"][$rel] = true;
            }
        }
    }
}

foreach (['pt_BR', 'en'] as $loc) {
    echo "=== FALTAM em $loc: ".count($faltam[$loc] ?? [])."\n";
    foreach ($faltam[$loc] ?? [] as $k => $arqs) {
        echo "  $k   <- ".implode(', ', array_slice(array_keys($arqs), 0, 2))."\n";
    }
}

echo "=== NAMESPACE DESCONHECIDO: ".count($desconhecidos)."\n";
foreach ($desconhecidos as $k => $arqs) {
    echo "  $k   <- ".implode(', ', array_slice(array_keys($arqs), 0, 2))."\n";
}
