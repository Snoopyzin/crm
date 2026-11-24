<?php

return [
    [
        'key' => 'settings.other_settings.fiscal_documents',
        'name' => 'fiscal::app.acl.title',
        'route' => 'admin.fiscal_documents.index',
        'sort' => 4,
    ], [
        'key' => 'fiscal_documents',
        'name' => 'fiscal::app.acl.title',
        'route' => 'admin.fiscal_documents.index',
        'sort' => 1,
    ], [
        'key' => 'fiscal_documents.view',
        'name' => 'fiscal::app.acl.view',
        'route' => 'admin.fiscal_documents.view',
        'sort' => 1,
    ], [
        'key' => 'fiscal_documents.emit',
        'name' => 'fiscal::app.acl.emit',
        'route' => [
            'admin.fiscal_documents.create',
            'admin.fiscal_documents.emit-nfe',
            'admin.fiscal_documents.create-nfe-produto',
            'admin.fiscal_documents.emit-nfe-produto',
        ],
        'sort' => 2,
    ], [
        'key' => 'settings.other_settings.fiscal_settings',
        'name' => 'Configurações Fiscais',
        'route' => ['admin.settings.fiscal_settings.edit', 'admin.settings.fiscal_settings.update'],
        'sort' => 3,
    ],
];
