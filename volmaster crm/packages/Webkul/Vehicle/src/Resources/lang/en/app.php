<?php

return [
    'menu' => [
        'title' => 'Vehicles',
    ],

    'acl' => [
        'title' => 'Vehicles',
        'view' => 'View',
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
    ],

    'index' => [
        'title' => 'Vehicles',
        'create-btn' => 'Add Vehicle',
        'create-success' => 'Vehicle created successfully.',
        'update-success' => 'Vehicle updated successfully.',
        'delete-success' => 'Vehicle deleted successfully.',
        'delete-failed' => 'Vehicle deletion failed.',

        'datagrid' => [
            'placa' => 'Plate',
            'marca' => 'Brand / Model',
            'ano' => 'Year',
            'cliente' => 'Client',
            'status' => 'Status',
            'created-at' => 'Created At',
            'view' => 'View',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
    ],

    'create' => [
        'title' => 'Add Vehicle',
        'save-btn' => 'Save Vehicle',
    ],

    'edit' => [
        'title' => 'Edit Vehicle',
        'save-btn' => 'Save Vehicle',
    ],

    'view' => [
        'title' => 'Vehicle',
    ],

    'form' => [
        'general' => 'General',
        'person' => 'Client',
        'marca' => 'Brand',
        'modelo' => 'Model',
        'placa' => 'License Plate',
        'ano' => 'Year',
        'chassi' => 'Chassis / VIN',
        'km' => 'Mileage (km)',
        'cor' => 'Color',
        'tipo' => 'Type',
        'combustivel' => 'Fuel',
        'status' => 'Status',
        'photo' => 'Photo',

        'tipo-options' => [
            'carros' => 'Car',
            'motos' => 'Motorcycle',
            'caminhoes' => 'Truck',
        ],

        'status-options' => [
            'ativo' => 'Active',
            'inativo' => 'Inactive',
        ],
    ],
];
