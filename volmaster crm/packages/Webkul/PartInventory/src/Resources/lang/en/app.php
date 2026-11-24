<?php

return [
    'menu' => [
        'title' => 'Parts',
    ],

    'acl' => [
        'title' => 'Parts',
        'view' => 'View',
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'movements' => 'Stock Movements',
    ],

    'errors' => [
        'insufficient-stock' => 'This movement would leave stock negative.',
    ],

    'index' => [
        'title' => 'Parts',
        'create-btn' => 'Add Part',
        'create-success' => 'Part created successfully.',
        'update-success' => 'Part updated successfully.',
        'delete-success' => 'Part deleted successfully.',
        'delete-failed' => 'Part deletion failed.',

        'datagrid' => [
            'codigo' => 'Code',
            'nome' => 'Name',
            'categoria' => 'Category',
            'valor' => 'Unit Price',
            'estoque' => 'In Stock',
            'created-at' => 'Created At',
            'view' => 'View',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
    ],

    'create' => [
        'title' => 'Add Part',
        'save-btn' => 'Save Part',
    ],

    'edit' => [
        'title' => 'Edit Part',
        'save-btn' => 'Save Part',
    ],

    'view' => [
        'title' => 'Part',
        'inventories' => 'Stock by Warehouse',
        'movements' => 'Recent Movements',
        'add-stock' => 'Add / Adjust Stock',
        'movement-success' => 'Stock movement registered successfully.',
        'no-movements' => 'No movements recorded yet.',
    ],

    'form' => [
        'general' => 'General',
        'stock' => 'Initial Stock',
        'codigo' => 'Code',
        'nome' => 'Name',
        'ncm' => 'NCM',
        'cest' => 'CEST',
        'part_category_id' => 'Group',
        'new-category-btn' => '+ New group',
        'use-existing-category-btn' => 'Use existing group',
        'new-category-placeholder' => 'New group name',
        'valor_unitario' => 'Unit Price',
        'fornecedor' => 'Supplier',
        'quantidade_minima' => 'Minimum Quantity',
        'warehouse_id' => 'Warehouse',
        'in_stock' => 'Initial Quantity',
        'no-category' => 'No group',
        'movement-type' => 'Type',
        'movement-quantity' => 'Quantity',
        'movement-reason' => 'Reason',

        'movement-type-options' => [
            'entrada' => 'Stock In',
            'ajuste' => 'Adjustment',
        ],
    ],

    'services' => [
        'menu' => [
            'title' => 'Services',
        ],

        'acl' => [
            'title' => 'Services',
        ],

        'index' => [
            'title' => 'Services',
            'create-btn' => 'Add Service',
            'create-success' => 'Service created successfully.',
            'update-success' => 'Service updated successfully.',
            'delete-success' => 'Service deleted successfully.',
            'delete-failed' => 'Service deletion failed.',

            'datagrid' => [
                'nome' => 'Name',
                'grupo' => 'Group',
                'preco' => 'Default Price',
                'created-at' => 'Created At',
                'edit' => 'Edit',
                'delete' => 'Delete',
            ],
        ],

        'create' => [
            'title' => 'Add Service',
            'save-btn' => 'Save Service',
        ],

        'edit' => [
            'title' => 'Edit Service',
            'save-btn' => 'Save Service',
        ],

        'form' => [
            'nome' => 'Service Name',
            'descricao' => 'Description',
            'preco_padrao' => 'Default Price',
        ],
    ],

    'categories' => [
        'menu' => [
            'title' => 'Groups',
        ],

        'acl' => [
            'title' => 'Groups',
        ],

        'index' => [
            'title' => 'Groups',
            'create-title' => 'Create new group',
            'create-btn' => 'Add Group',
            'create-success' => 'Group created successfully.',
            'update-success' => 'Group updated successfully.',
            'delete-success' => 'Group deleted successfully.',
            'delete-failed' => 'Group deletion failed.',

            'datagrid' => [
                'name' => 'Name',
                'total-parts' => 'Parts',
                'total-services' => 'Services',
                'created-at' => 'Created At',
                'delete' => 'Delete',
            ],
        ],

        'form' => [
            'name' => 'Group Name',
            'name-placeholder' => 'E.g: Brakes, Engine, Electrical...',
        ],
    ],
];
