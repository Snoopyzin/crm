<?php

return [
    /**
     * Dashboard.
     */
    [
        'key' => 'dashboard',
        'name' => 'admin::app.layouts.dashboard',
        'route' => 'admin.dashboard.index',
        'sort' => 1,
        'icon-class' => 'icon-dashboard',
    ],

    /**
     * Settings -- deliberately trimmed to just Usuários (Grupos/Funções/
     * Usuários), Depósitos, and Configuração (cor da marca + suporte --
     * merged in here so there's a single "Configurações" entry in the
     * sidebar instead of two confusingly similar ones). Everything else
     * that used to live here (Leads, Cotações, Mail, Atividades, Contatos,
     * Produtos, Funis, Origens, Tipos, Atributos, Modelos de E-mail,
     * Eventos, Campanhas, Webhooks, Fluxos de Trabalho, Data Transfer,
     * Tags, Chaves de API) was removed from the client-facing menu on
     * request -- nothing was deleted, every screen is still reachable
     * directly by URL, just not from the sidebar.
     */
    [
        'key' => 'settings',
        'name' => 'admin::app.layouts.settings',
        'route' => 'admin.settings.index',
        'sort' => 15,
        'icon-class' => 'icon-setting',
    ], [
        'key' => 'settings.user',
        'name' => 'admin::app.layouts.user',
        'route' => 'admin.settings.groups.index',
        'info' => 'admin::app.layouts.user-info',
        'sort' => 1,
        'icon-class' => 'icon-settings-group',
    ], [
        'key' => 'settings.user.groups',
        'name' => 'admin::app.layouts.groups',
        'info' => 'admin::app.layouts.groups-info',
        'route' => 'admin.settings.groups.index',
        'sort' => 1,
        'icon-class' => 'icon-settings-group',
    ], [
        'key' => 'settings.user.roles',
        'name' => 'admin::app.layouts.roles',
        'info' => 'admin::app.layouts.roles-info',
        'route' => 'admin.settings.roles.index',
        'sort' => 2,
        'icon-class' => 'icon-role',
    ], [
        'key' => 'settings.user.users',
        'name' => 'admin::app.layouts.users',
        'info' => 'admin::app.layouts.users-info',
        'route' => 'admin.settings.users.index',
        'sort' => 3,
        'icon-class' => 'icon-user',
    ], [
        'key' => 'settings.inventory',
        'name' => 'admin::app.layouts.inventory',
        'info' => 'admin::app.layouts.inventory-info',
        'route' => 'admin.settings.warehouses.index',
        'icon-class' => '',
        'sort' => 2,
    ], [
        'key' => 'settings.inventory.warehouse',
        'name' => 'admin::app.layouts.warehouses',
        'info' => 'admin::app.layouts.warehouses-info',
        'route' => 'admin.settings.warehouses.index',
        'sort' => 1,
        'icon-class' => 'icon-settings-warehouse',
    ], [
        'key' => 'settings.configuration',
        'name' => 'admin::app.layouts.configuration',
        'info' => 'admin::app.layouts.configuration-info',
        'route' => 'admin.configuration.index',
        'sort' => 3,
        'icon-class' => 'icon-configuration',
    ], [
        'key' => 'settings.configuration.general',
        'name' => 'admin::app.layouts.configuration',
        'info' => 'admin::app.layouts.configuration-info',
        'route' => 'admin.configuration.index',
        'sort' => 1,
        'icon-class' => 'icon-configuration',
    ],

    /**
     * Help.
     */
    [
        'key' => 'help',
        'name' => 'admin::app.layouts.help',
        'route' => 'admin.help.index',
        'sort' => 17,
        'icon-class' => 'icon-help',
    ],
];
