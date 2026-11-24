<?php

return [
    [
        'key' => 'settings.other_settings.remote_services',
        'name' => 'Serviços Remotos',
        'route' => 'admin.remote_services.index',
        'sort' => 9,
    ], [
        'key' => 'remote_services',
        'name' => 'Serviços Remotos',
        'route' => 'admin.remote_services.index',
        'sort' => 1,
    ], [
        'key' => 'remote_services.view',
        'name' => 'Ver',
        'route' => 'admin.remote_services.view',
        'sort' => 1,
    ], [
        'key' => 'remote_services.create',
        'name' => 'Criar',
        'route' => ['admin.remote_services.create', 'admin.remote_services.store'],
        'sort' => 2,
    ], [
        'key' => 'remote_services.finish',
        'name' => 'Finalizar',
        'route' => 'admin.remote_services.finish',
        'sort' => 3,
    ], [
        'key' => 'remote_services.approve',
        'name' => 'Aprovar',
        'route' => 'admin.remote_services.approve',
        'sort' => 3,
    ], [
        'key' => 'remote_services.delete',
        'name' => 'Excluir',
        'route' => 'admin.remote_services.destroy',
        'sort' => 4,
    ], [
        'key' => 'remote_services.types',
        'name' => 'Tipos de Atendimento',
        'route' => ['admin.remote_services.types.index', 'admin.remote_services.types.create', 'admin.remote_services.types.store', 'admin.remote_services.types.edit', 'admin.remote_services.types.update', 'admin.remote_services.types.destroy'],
        'sort' => 5,
    ],
];
