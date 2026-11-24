<?php

return [
    [
        'key' => 'settings.other_settings.vehicles',
        'name' => 'vehicle::app.acl.title',
        'route' => 'admin.vehicles.index',
        'sort' => 6,
    ], [
        'key' => 'vehicles',
        'name' => 'vehicle::app.acl.title',
        'route' => 'admin.vehicles.index',
        'sort' => 1,
    ], [
        'key' => 'vehicles.view',
        'name' => 'vehicle::app.acl.view',
        'route' => 'admin.vehicles.view',
        'sort' => 1,
    ], [
        'key' => 'vehicles.create',
        'name' => 'vehicle::app.acl.create',
        'route' => ['admin.vehicles.create', 'admin.vehicles.store'],
        'sort' => 2,
    ], [
        'key' => 'vehicles.edit',
        'name' => 'vehicle::app.acl.edit',
        'route' => ['admin.vehicles.edit', 'admin.vehicles.update'],
        'sort' => 3,
    ], [
        'key' => 'vehicles.delete',
        'name' => 'vehicle::app.acl.delete',
        'route' => 'admin.vehicles.destroy',
        'sort' => 4,
    ],
];
