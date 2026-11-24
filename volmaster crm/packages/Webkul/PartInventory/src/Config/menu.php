<?php

return [
    [
        'key' => 'estoque',
        'name' => 'partinventory::app.menu.title',
        'route' => 'admin.parts.index',
        'sort' => 11,
        'icon-class' => 'icon-settings-warehouse',
    ], [
        'key' => 'servicos_catalogo',
        'name' => 'partinventory::app.services.menu.title',
        'route' => 'admin.services.index',
        'sort' => 12,
        'icon-class' => 'icon-settings-flow',
    ], [
        'key' => 'grupos',
        'name' => 'partinventory::app.categories.menu.title',
        'route' => 'admin.part-categories.index',
        'sort' => 13,
        'icon-class' => 'icon-settings-tag',
    ],
];
