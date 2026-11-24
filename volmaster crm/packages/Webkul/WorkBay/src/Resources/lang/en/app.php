<?php

return [
    'menu' => [
        'title' => 'Work Bays',
    ],

    'acl' => [
        'title' => 'Work Bays',
        'view' => 'View',
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
    ],

    'errors' => [
        'conflict' => 'This bay is already booked during the selected time window.',
    ],

    'index' => [
        'title' => 'Work Bays',
        'create-btn' => 'Add Bay',
        'create-success' => 'Bay created successfully.',
        'update-success' => 'Bay updated successfully.',
        'delete-success' => 'Bay deleted successfully.',
        'delete-failed' => 'Bay deletion failed.',

        'datagrid' => [
            'name' => 'Name',
            'capacity' => 'Capacity',
            'status' => 'Status',
            'created-at' => 'Created At',
            'view' => 'View',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
    ],

    'create' => [
        'title' => 'Add Bay',
        'save-btn' => 'Save Bay',
    ],

    'edit' => [
        'title' => 'Edit Bay',
        'save-btn' => 'Save Bay',
    ],

    'view' => [
        'title' => 'Work Bay',
        'assignments' => 'Assignments',
        'assign-service-order' => 'Assign Service Order',
        'assign-success' => 'Service order assigned successfully.',
        'assignment-cancelled' => 'Assignment cancelled.',
        'no-assignments' => 'No active assignments.',
        'starts-at' => 'Starts At',
        'ends-at' => 'Ends At',
    ],

    'form' => [
        'general' => 'General',
        'name' => 'Name',
        'capacity' => 'Capacity',
        'status' => 'Status',

        'status-options' => [
            'ativa' => 'Active',
            'inativa' => 'Inactive',
        ],
    ],
];
