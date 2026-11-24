<?php

return [
    'menu' => [
        'title' => 'Fiscal Documents',
    ],

    'acl' => [
        'title' => 'Fiscal Documents',
        'view' => 'View',
        'emit' => 'Emit',
    ],

    'index' => [
        'title' => 'Fiscal Documents',

        'datagrid' => [
            'service-order' => 'Service Order',
            'type' => 'Type',
            'numero' => 'Number',
            'status' => 'Status',
            'created-at' => 'Created At',
            'view' => 'View',
        ],
    ],

    'view' => [
        'title' => 'Fiscal Document',
        'ref' => 'Focus Ref',
        'logs' => 'Log Trail',
        'chave' => 'Access Key',
        'last-error' => 'Last Error',
    ],

    'emit-btn' => 'Emit Invoice',
    'emit-result' => 'Invoice emission requested. Current status: :status.',
    'emit-failed' => 'Invoice emission failed: :message',
    'not-configured' => 'No Focus NFe key registered yet. Go to Settings → API Keys and register one with type "Focus NFe".',
];
