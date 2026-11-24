<?php

return [
    [
        'key' => 'settings.other_settings.mechanics',
        'name' => 'mechanic::app.acl.title',
        'route' => 'admin.mechanics.index',
        'sort' => 7,
    ], [
        'key' => 'mechanics',
        'name' => 'mechanic::app.acl.title',
        'route' => 'admin.mechanics.index',
        'sort' => 1,
    ], [
        'key' => 'mechanics.view',
        'name' => 'mechanic::app.acl.view',
        'route' => 'admin.mechanics.view',
        'sort' => 1,
    ], [
        'key' => 'mechanics.create',
        'name' => 'mechanic::app.acl.create',
        'route' => ['admin.mechanics.create', 'admin.mechanics.store'],
        'sort' => 2,
    ], [
        'key' => 'mechanics.edit',
        'name' => 'mechanic::app.acl.edit',
        'route' => ['admin.mechanics.edit', 'admin.mechanics.update'],
        'sort' => 3,
    ], [
        'key' => 'mechanics.delete',
        'name' => 'mechanic::app.acl.delete',
        'route' => 'admin.mechanics.destroy',
        'sort' => 4,
    ],
];
