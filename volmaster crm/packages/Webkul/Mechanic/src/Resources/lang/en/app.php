<?php

return [
    'menu' => [
        'title' => 'Mechanics',
    ],

    'acl' => [
        'title' => 'Mechanics',
        'view' => 'View',
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
    ],

    'errors' => [
        'legend-status-locked' => 'This mechanic is a Legend and their status cannot be changed.',
        'legend-delete-locked' => 'This mechanic is a Legend and cannot be deleted.',
    ],

    'index' => [
        'title' => 'Mechanics',
        'create-btn' => 'Add Mechanic',
        'create-success' => 'Mechanic created successfully.',
        'update-success' => 'Mechanic updated successfully.',
        'delete-success' => 'Mechanic deleted successfully.',
        'delete-failed' => 'Mechanic deletion failed.',

        'datagrid' => [
            'nome' => 'Name',
            'especialidade' => 'Specialty',
            'years' => 'Years of Exp.',
            'avaliacao' => 'Rating',
            'status' => 'Status',
            'created-at' => 'Created At',
            'view' => 'View',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
    ],

    'create' => [
        'title' => 'Add Mechanic',
        'save-btn' => 'Save Mechanic',
    ],

    'edit' => [
        'title' => 'Edit Mechanic',
        'save-btn' => 'Save Mechanic',
    ],

    'view' => [
        'title' => 'Mechanic',
    ],

    'form' => [
        'general' => 'General',
        'nome' => 'Name',
        'especialidade' => 'Specialty',
        'years_of_experience' => 'Years of Experience',
        'avaliacao' => 'Rating (0-5)',
        'status' => 'Status',

        'especialidade-options' => [
            'motor' => 'Engine',
            'transmissao' => 'Transmission',
            'eletrica' => 'Electrical',
            'diagnostico' => 'Diagnostics',
            'suspensao' => 'Suspension',
            'freios' => 'Brakes',
            'hidraulica' => 'Hydraulics',
            'arrefecimento' => 'Cooling System',
        ],

        'status-options' => [
            'ativo' => 'Active',
            'lenda' => 'Legend',
        ],
    ],
];
