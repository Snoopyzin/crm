<?php

return [
    /**
     * General.
     *
     * Deliberately restricted: this screen is reachable by the client
     * (the workshop owner), not just by Volmaster Tech. Only the brand
     * color is editable here -- logo/favicon, locale, footer text, menu
     * labels, Magic AI and IMAP email were all removed so a client can't
     * re-brand or misconfigure the software. Use Configurações (the other,
     * plural settings tree) for things the client legitimately needs to
     * manage day to day, like Usuários, Depósitos, Mecânicos etc.
     */
    [
        'key' => 'general',
        'name' => 'admin::app.configuration.index.general.title',
        'info' => 'admin::app.configuration.index.general.info',
        'sort' => 1,
    ], [
        'key' => 'general.settings',
        'name' => 'admin::app.configuration.index.general.settings.title',
        'info' => 'admin::app.configuration.index.general.settings.info',
        'icon' => 'icon-configuration',
        'sort' => 1,
    ], [
        'key' => 'general.settings.menu_color',
        'name' => 'admin::app.configuration.index.general.settings.menu-color.title',
        'info' => 'admin::app.configuration.index.general.settings.menu-color.info',
        'sort' => 1,
        'fields' => [
            [
                'name' => 'brand_color',
                'title' => 'admin::app.configuration.index.general.settings.menu-color.brand-color',
                'type' => 'color',
                'default' => '#0E90D9',
            ],
        ],
    ], [
        'key' => 'general.settings.oficina',
        'name' => 'Identidade da oficina',
        'info' => 'O nome e a foto da fachada da sua oficina aparecem no link de acompanhamento que o cliente recebe.',
        'sort' => 2,
        'fields' => [
            [
                'name' => 'nome',
                'title' => 'Nome da oficina (aparece no letreiro e no link do cliente)',
                'type' => 'text',
                'default' => '',
            ], [
                'name' => 'fachada',
                'title' => 'Foto da fachada da oficina (JPG, PNG ou WEBP, de preferência na horizontal)',
                'type' => 'image',
                'validation' => 'mimes:jpeg,jpg,png,webp|max:6144',
            ],
        ],
    ], [
        'key' => 'general.support',
        'name' => 'admin::app.configuration.index.general.support.title',
        'info' => 'admin::app.configuration.index.general.support.info',
        'icon' => 'icon-help',
        'sort' => 2,
    ], [
        'key' => 'general.support.contact',
        'name' => 'admin::app.configuration.index.general.support.contact.title',
        'info' => 'admin::app.configuration.index.general.support.contact.info',
        'sort' => 1,
        'fields' => [
            [
                'name' => 'whatsapp',
                'title' => 'admin::app.configuration.index.general.support.contact.whatsapp',
                'type' => 'text',
                'default' => '',
            ], [
                'name' => 'email',
                'title' => 'admin::app.configuration.index.general.support.contact.email',
                'type' => 'text',
                'default' => 'suporte@volmastercrm.com',
            ],
        ],
    ],
];
