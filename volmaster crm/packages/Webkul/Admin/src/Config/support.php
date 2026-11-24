<?php

return [
    'services' => [
        [
            'icon' => 'support',
            'title' => 'admin::app.help.index.services.support.title',
            'description' => 'admin::app.help.index.services.support.description',
            'url' => 'mailto:gabriel@volmastercrm.com',
            'url_label' => 'gabriel@volmastercrm.com',
        ],
    ],

    'resources' => [],

    'community' => [],

    'contact_url' => 'mailto:gabriel@volmastercrm.com',

    /*
     * Contato de suporte mostrado nas páginas de erro (404, 403, 500...).
     * Troque pelo .env sem mexer no código: VOLMASTER_SUPPORT_EMAIL e,
     * se existir, VOLMASTER_SUPPORT_WHATSAPP (com DDD, só números).
     */
    'contact_email' => env('VOLMASTER_SUPPORT_EMAIL', 'gabriel@volmastercrm.com'),

    'contact_whatsapp' => env('VOLMASTER_SUPPORT_WHATSAPP'),
];
