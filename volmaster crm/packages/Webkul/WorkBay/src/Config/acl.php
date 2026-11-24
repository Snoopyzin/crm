<?php

return [
    [
        'key' => 'settings.other_settings.work_bays',
        'name' => 'workbay::app.acl.title',
        'route' => 'admin.work_bays.index',
        'sort' => 5,
    ], [
        'key' => 'work_bays',
        'name' => 'workbay::app.acl.title',
        'route' => 'admin.work_bays.index',
        'sort' => 1,
    ], [
        'key' => 'work_bays.view',
        'name' => 'workbay::app.acl.view',
        'route' => 'admin.work_bays.view',
        'sort' => 1,
    ], [
        'key' => 'work_bays.create',
        'name' => 'workbay::app.acl.create',
        'route' => ['admin.work_bays.create', 'admin.work_bays.store'],
        'sort' => 2,
    ], [
        'key' => 'work_bays.edit',
        'name' => 'workbay::app.acl.edit',
        'route' => ['admin.work_bays.edit', 'admin.work_bays.update'],
        'sort' => 3,
    ], [
        'key' => 'work_bays.delete',
        'name' => 'workbay::app.acl.delete',
        'route' => 'admin.work_bays.destroy',
        'sort' => 4,
    ],
];
