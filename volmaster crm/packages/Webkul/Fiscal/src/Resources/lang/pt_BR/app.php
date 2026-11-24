<?php

return [
    'menu' => [
        'title' => 'Documentos Fiscais',
    ],

    'acl' => [
        'title' => 'Documentos Fiscais',
        'view' => 'Visualizar',
        'emit' => 'Emitir',
    ],

    'index' => [
        'title' => 'Documentos Fiscais',

        'datagrid' => [
            'service-order' => 'Ordem de Serviço',
            'type' => 'Tipo',
            'numero' => 'Número',
            'status' => 'Status',
            'created-at' => 'Criado em',
            'view' => 'Visualizar',
        ],
    ],

    'view' => [
        'title' => 'Documento Fiscal',
        'ref' => 'Referência Focus',
        'logs' => 'Histórico',
        'chave' => 'Chave de Acesso',
        'last-error' => 'Último Erro',
    ],

    'emit-btn' => 'Emitir Nota Fiscal',
    'emit-btn-nfe' => 'Emitir NF-e de Produto',
    'emit-result' => 'Emissão de nota fiscal solicitada. Status atual: :status.',
    'emit-failed' => 'Falha ao emitir nota fiscal: :message',
    'not-configured' => 'Nenhuma chave da Focus NFe cadastrada ainda. Vá em Configurações → Chaves de API e cadastre uma com o tipo "Focus NFe".',
];
