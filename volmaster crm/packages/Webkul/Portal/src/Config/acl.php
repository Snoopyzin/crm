<?php

return [
    [
        'key' => 'settings.other_settings.service_requests',
        'name' => 'portal::app.admin.acl.title',
        'route' => 'admin.service_requests.index',
        'sort' => 10,
    ], [
        'key' => 'service_requests',
        'name' => 'portal::app.admin.acl.title',
        'route' => 'admin.service_requests.index',
        'sort' => 1,
    ], [
        'key' => 'service_requests.view',
        'name' => 'portal::app.admin.acl.view',
        'route' => 'admin.service_requests.view',
        'sort' => 1,
    ], [
        'key' => 'service_requests.convert',
        'name' => 'portal::app.admin.acl.convert',
        'route' => ['admin.service_requests.convert', 'admin.service_requests.link_order', 'admin.service_requests.reject'],
        'sort' => 2,
    ], [
        'key' => 'service_requests.invite',
        'name' => 'portal::app.admin.acl.invite',
        'route' => 'admin.portal.invite',
        'sort' => 3,
    ],
];
