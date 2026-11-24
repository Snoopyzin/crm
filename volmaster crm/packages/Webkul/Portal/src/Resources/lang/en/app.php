<?php

return [
    'layout' => [
        'title' => 'Client Portal',
        'nav' => [
            'dashboard' => 'Dashboard',
            'vehicles' => 'Vehicles',
            'service-orders' => 'Service Orders',
            'service-requests' => 'Service Requests',
            'logout' => 'Sign out',
        ],
    ],

    'sessions' => [
        'email' => 'Email',
        'password' => 'Password',
        'login-error' => 'These credentials do not match our records.',
        'deactivated-error' => 'Your portal access has been deactivated. Please contact the workshop.',
        'back-to-login' => 'Back to sign in',
        'login' => [
            'title' => 'Sign in',
            'submit' => 'Sign in',
            'forgot-password' => 'Forgot your password?',
        ],
        'forgot-password' => [
            'title' => 'Forgot password',
            'info' => 'Enter your email and we will send you a link to reset your password.',
            'submit' => 'Send reset link',
            'reset-link-sent' => 'A password reset link has been sent to your email.',
            'email-not-exist' => 'We could not find an account with that email.',
        ],
        'reset-password' => [
            'title' => 'Set your password',
            'new-password' => 'New password',
            'confirm-password' => 'Confirm password',
            'submit' => 'Save password',
        ],
    ],

    'dashboard' => [
        'title' => 'Dashboard',
        'vehicle-count' => 'Vehicles',
        'pending-approval-count' => 'Awaiting your approval',
        'recent-service-orders' => 'Recent service orders',
        'no-service-orders' => 'No service orders yet.',
        'view' => 'View',
    ],

    'vehicles' => [
        'index' => [
            'title' => 'My Vehicles',
            'empty' => 'No vehicles registered yet.',
            'placa' => 'Plate',
            'marca-modelo' => 'Make / Model',
            'ano' => 'Year',
            'status' => 'Status',
        ],
        'view' => [
            'cor' => 'Color',
            'km' => 'Mileage (km)',
            'history' => 'Service history',
        ],
    ],

    'service-orders' => [
        'index' => [
            'title' => 'My Service Orders',
            'subject' => 'Subject',
            'total' => 'Total',
            'status' => 'Status',
        ],
        'view' => [
            'vehicle' => 'Vehicle',
            'mechanic' => 'Mechanic',
            'data-previsao' => 'Expected date',
            'total' => 'Total',
            'items' => 'Items',
            'no-items' => 'No items added yet.',
            'item-name' => 'Item',
            'item-type' => 'Type',
            'item-total' => 'Total',
            'decision-title' => 'Your decision is needed',
            'decision-info' => 'This service order is awaiting your approval. You may approve it to authorize the work, or reject it to cancel.',
            'approve' => 'Approve budget',
            'reject' => 'Reject',
            'approve-success' => 'Service order approved. The workshop has been notified.',
            'reject-success' => 'Service order rejected.',
            'decision-locked' => 'This service order can no longer be approved or rejected.',
        ],
    ],

    'service-requests' => [
        'index' => [
            'title' => 'Service Requests',
            'new' => 'New request',
            'empty' => 'You haven\'t requested any service yet.',
            'descricao' => 'Description',
            'data-preferencia' => 'Preferred date',
            'status' => 'Status',
        ],
        'create' => [
            'title' => 'Request service',
            'vehicle' => 'Vehicle',
            'vehicle-other' => '-- Not registered yet --',
            'vehicle-free-text' => 'Vehicle description (if not listed above)',
            'vehicle-free-text-hint' => 'e.g. Volvo FH 540, plate not yet registered',
            'descricao' => 'What do you need?',
            'data-preferencia' => 'Preferred date (optional)',
            'submit' => 'Send request',
            'success' => 'Your request has been sent. The workshop will get in touch soon.',
        ],
    ],

    'emails' => [
        'invite' => [
            'subject' => 'You have been invited to the :app_name client portal',
            'dear' => 'Hello, :name',
            'info' => 'You have been invited to access the :app_name client portal, where you can view your vehicles and service orders, approve budgets, and request new service.',
            'cta' => 'Set your password',
            'thanks' => 'Thank you for choosing us.',
        ],
        'reset-password' => [
            'subject' => 'Reset your portal password',
            'dear' => 'Hello, :name',
            'info' => 'You requested to reset your client portal password. Click the button below to choose a new one.',
            'cta' => 'Reset password',
            'thanks' => 'If you did not request this, you can safely ignore this email.',
        ],
    ],

    'admin' => [
        'menu' => [
            'title' => 'Service Requests',
        ],
        'acl' => [
            'title' => 'Service Requests',
            'view' => 'View',
            'convert' => 'Convert / Manage',
            'invite' => 'Invite to Portal',
        ],
        'invite' => [
            'no-email' => 'This contact has no email address on file, so they cannot be invited to the portal.',
            'success' => 'Invite sent.',
        ],
        'invite-button' => [
            'active' => 'Portal: Active',
            'resend' => 'Resend invite',
            'invite' => 'Invite to Portal',
        ],
        'service-requests' => [
            'index' => [
                'title' => 'Service Requests',
                'cliente' => 'Client',
                'veiculo' => 'Vehicle',
                'descricao' => 'Description',
                'data-preferencia' => 'Preferred date',
                'status' => 'Status',
                'created-at' => 'Received on',
                'view' => 'View',
            ],
            'view' => [
                'convert' => 'Convert to Service Order',
                'reject' => 'Reject request',
                'link-order' => 'Link Service Order',
                'service-order-id' => 'Service Order #',
                'link-success' => 'Request linked to the service order.',
                'reject-success' => 'Request rejected.',
            ],
            'convert' => [
                'servico-prefix' => 'Client request',
            ],
        ],
    ],
];
