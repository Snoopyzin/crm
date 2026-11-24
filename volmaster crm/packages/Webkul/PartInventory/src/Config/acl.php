<?php

return [
    [
        'key' => 'estoque',
        'name' => 'partinventory::app.acl.title',
        'route' => 'admin.parts.index',
        'sort' => 11,
    ], [
        'key' => 'parts',
        'name' => 'partinventory::app.acl.title',
        'route' => 'admin.parts.index',
        'sort' => 1,
    ], [
        'key' => 'parts.view',
        'name' => 'partinventory::app.acl.view',
        'route' => 'admin.parts.view',
        'sort' => 1,
    ], [
        'key' => 'parts.create',
        'name' => 'partinventory::app.acl.create',
        'route' => ['admin.parts.create', 'admin.parts.store'],
        'sort' => 2,
    ], [
        'key' => 'parts.edit',
        'name' => 'partinventory::app.acl.edit',
        'route' => ['admin.parts.edit', 'admin.parts.update'],
        'sort' => 3,
    ], [
        'key' => 'parts.delete',
        'name' => 'partinventory::app.acl.delete',
        'route' => 'admin.parts.destroy',
        'sort' => 4,
    ], [
        'key' => 'parts.movements',
        'name' => 'partinventory::app.acl.movements',
        'route' => 'admin.parts.movements.store',
        'sort' => 5,
    ], [
        'key' => 'servicos_catalogo',
        'name' => 'partinventory::app.services.acl.title',
        'route' => 'admin.services.index',
        'sort' => 12,
    ], [
        'key' => 'services',
        'name' => 'partinventory::app.services.acl.title',
        'route' => 'admin.services.index',
        'sort' => 1,
    ], [
        'key' => 'services.create',
        'name' => 'partinventory::app.acl.create',
        'route' => ['admin.services.create', 'admin.services.store'],
        'sort' => 1,
    ], [
        'key' => 'services.edit',
        'name' => 'partinventory::app.acl.edit',
        'route' => ['admin.services.edit', 'admin.services.update'],
        'sort' => 2,
    ], [
        'key' => 'services.delete',
        'name' => 'partinventory::app.acl.delete',
        'route' => 'admin.services.destroy',
        'sort' => 3,
    ], [
        'key' => 'grupos',
        'name' => 'partinventory::app.categories.acl.title',
        'route' => 'admin.part-categories.index',
        'sort' => 13,
    ], [
        'key' => 'part_categories',
        'name' => 'partinventory::app.categories.acl.title',
        'route' => 'admin.part-categories.index',
        'sort' => 1,
    ], [
        'key' => 'part_categories.create',
        'name' => 'partinventory::app.acl.create',
        'route' => 'admin.part-categories.store',
        'sort' => 1,
    ], [
        'key' => 'part_categories.edit',
        'name' => 'partinventory::app.acl.edit',
        'route' => 'admin.part-categories.update',
        'sort' => 2,
    ], [
        'key' => 'part_categories.delete',
        'name' => 'partinventory::app.acl.delete',
        'route' => 'admin.part-categories.destroy',
        'sort' => 3,
    ],
];
