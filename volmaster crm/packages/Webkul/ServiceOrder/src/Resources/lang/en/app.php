<?php

return [
    'menu' => [
        'title' => 'Service Orders',
        'tecnico' => 'My tasks',
        'cadastro' => 'Registration',
        'diagnostico' => 'Diagnosis',
        'ordem_servico' => 'Service Order',
        'orcamento' => 'Quote',
        'negociacao' => 'Negotiation',
        'em_execucao' => 'In Progress',
        'veiculo_teste' => 'Test Drive',
        'faturamento' => 'Billing',
        'pos_venda' => 'After-Sales',
    ],

    'acl' => [
        'title' => 'Service Orders',
        'view' => 'View',
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'auditar' => 'Audit diagnosis (chief approval)',
        'forcar_avanco' => 'Force stage advance (with justification)',
        'ver_valores_financeiros' => 'See financial values (prices, discounts, credit, payment)',
        'faturamento_geral' => 'See overall revenue on the dashboard (commissioned roles only see their own)',
        'settings_workshop' => 'Configure indicators and alerts',
    ],

    'errors' => [
        'completed-status-locked' => 'A completed service order cannot have its status changed.',
    ],

    'index' => [
        'title' => 'Service Orders',
        'create-btn' => 'New Service Order',
        'create-success' => 'Service order created successfully.',
        'update-success' => 'Service order updated successfully.',
        'delete-success' => 'Service order deleted successfully.',
        'delete-failed' => 'Service order deletion failed.',

        'datagrid' => [
            'numero' => '#',
            'servico' => 'Service',
            'cliente' => 'Client',
            'veiculo' => 'Vehicle',
            'mecanico' => 'Mechanic',
            'status' => 'Status',
            'total' => 'Total',
            'created-at' => 'Opened At',
            'view' => 'View',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
    ],

    'create' => [
        'title' => 'New Service Order',
        'save-btn' => 'Create Service Order',
    ],

    'edit' => [
        'title' => 'Edit Service Order',
        'save-btn' => 'Save',
    ],

    'view' => [
        'title' => 'Service Order',
        'status-updated' => 'Status updated successfully.',
        'item-added' => 'Item added successfully.',
        'item-removed' => 'Item removed successfully.',
        'items-title' => 'Items',
        'add-item' => 'Add Item',
        'print-btn' => 'Print',
        'change-status' => 'Change Status',
        'tipo' => 'Type',
        'descricao' => 'Description',
        'quantidade' => 'Qty',
        'part' => 'Part',
        'hours' => 'Hours',
        'preco_unitario' => 'Unit Price',
        'total' => 'Total',
        'funcionario' => 'Performed By',
        'situacao' => 'Status',
        'no-items' => 'No items added yet.',
        'sub-total' => 'Subtotal',
        'grand-total' => 'Grand Total',
    ],

    'form' => [
        'general' => 'General',
        'person' => 'Client',
        'vehicle' => 'Vehicle',
        'mechanic' => 'Mechanic',
        'servico' => 'Title',
        'description' => 'Defect Description',
        'data_previsao' => 'Estimated Completion',
        'hora_previsao' => 'Time',
        'hora_previsao_dica' => 'Optional time: use it for vehicles that arrive and leave on the same day. Without a time, the deadline is the end of the day.',
        'select-vehicle-hint' => 'Select a client first',

        'tipo-options' => [
            'Peça' => 'Part',
            'Serviço' => 'Labor',
        ],

        'status-options' => [
            'Aberta' => 'Open',
            'Autorizado' => 'Authorized',
            'Em Andamento' => 'In Progress',
            'Aguardando Peças' => 'Awaiting Parts',
            'Concluída' => 'Completed',
            'Cancelada' => 'Cancelled',
        ],
    ],
];
