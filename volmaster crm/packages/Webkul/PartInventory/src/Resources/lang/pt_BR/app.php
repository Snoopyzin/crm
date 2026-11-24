<?php

return [
    'menu' => [
        'title' => 'Estoque',
    ],

    'acl' => [
        'title' => 'Estoque',
        'view' => 'Visualizar',
        'create' => 'Criar',
        'edit' => 'Editar',
        'delete' => 'Excluir',
        'movements' => 'Movimentações de Estoque',
    ],

    'errors' => [
        'insufficient-stock' => 'Esta movimentação deixaria o estoque negativo.',
    ],

    'index' => [
        'title' => 'Estoque',
        'create-btn' => 'Adicionar Peça',
        'create-success' => 'Peça criada com sucesso.',
        'update-success' => 'Peça atualizada com sucesso.',
        'delete-success' => 'Peça excluída com sucesso.',
        'delete-failed' => 'Falha ao excluir a peça.',

        'datagrid' => [
            'codigo' => 'Código',
            'nome' => 'Nome',
            'categoria' => 'Categoria',
            'valor' => 'Valor Unitário',
            'estoque' => 'Em Estoque',
            'created-at' => 'Criado em',
            'view' => 'Visualizar',
            'edit' => 'Editar',
            'delete' => 'Excluir',
        ],
    ],

    'create' => [
        'title' => 'Adicionar Peça',
        'save-btn' => 'Salvar Peça',
    ],

    'edit' => [
        'title' => 'Editar Peça',
        'save-btn' => 'Salvar Peça',
    ],

    'view' => [
        'title' => 'Peça',
        'inventories' => 'Estoque por Depósito',
        'movements' => 'Movimentações Recentes',
        'add-stock' => 'Adicionar / Ajustar Estoque',
        'movement-success' => 'Movimentação registrada com sucesso.',
        'no-movements' => 'Nenhuma movimentação registrada ainda.',
    ],

    'form' => [
        'general' => 'Geral',
        'stock' => 'Estoque Inicial',
        'codigo' => 'Código',
        'nome' => 'Nome',
        'ncm' => 'NCM',
        'cest' => 'CEST',
        'part_category_id' => 'Grupo',
        'new-category-btn' => '+ Novo grupo',
        'use-existing-category-btn' => 'Usar grupo existente',
        'new-category-placeholder' => 'Nome do novo grupo',
        'valor_unitario' => 'Valor Unitário',
        'fornecedor' => 'Fornecedor',
        'quantidade_minima' => 'Quantidade Mínima',
        'warehouse_id' => 'Depósito',
        'in_stock' => 'Quantidade Inicial',
        'no-category' => 'Sem grupo',
        'movement-type' => 'Tipo',
        'movement-quantity' => 'Quantidade',
        'movement-reason' => 'Motivo',

        'movement-type-options' => [
            'entrada' => 'Entrada',
            'ajuste' => 'Ajuste',
        ],
    ],

    'services' => [
        'menu' => [
            'title' => 'Serviços',
        ],

        'acl' => [
            'title' => 'Serviços',
        ],

        'index' => [
            'title' => 'Serviços',
            'create-btn' => 'Adicionar Serviço',
            'create-success' => 'Serviço criado com sucesso.',
            'update-success' => 'Serviço atualizado com sucesso.',
            'delete-success' => 'Serviço excluído com sucesso.',
            'delete-failed' => 'Falha ao excluir o serviço.',

            'datagrid' => [
                'nome' => 'Nome',
                'grupo' => 'Grupo',
                'preco' => 'Preço Padrão',
                'created-at' => 'Criado em',
                'edit' => 'Editar',
                'delete' => 'Excluir',
            ],
        ],

        'create' => [
            'title' => 'Adicionar Serviço',
            'save-btn' => 'Salvar Serviço',
        ],

        'edit' => [
            'title' => 'Editar Serviço',
            'save-btn' => 'Salvar Serviço',
        ],

        'form' => [
            'nome' => 'Nome do Serviço',
            'descricao' => 'Descrição',
            'preco_padrao' => 'Preço Padrão',
        ],
    ],

    'categories' => [
        'menu' => [
            'title' => 'Grupos',
        ],

        'acl' => [
            'title' => 'Grupos',
        ],

        'index' => [
            'title' => 'Grupos',
            'create-title' => 'Criar novo grupo',
            'create-btn' => 'Adicionar Grupo',
            'create-success' => 'Grupo criado com sucesso.',
            'update-success' => 'Grupo atualizado com sucesso.',
            'delete-success' => 'Grupo excluído com sucesso.',
            'delete-failed' => 'Falha ao excluir o grupo.',

            'datagrid' => [
                'name' => 'Nome',
                'total-parts' => 'Peças',
                'total-services' => 'Serviços',
                'created-at' => 'Criado em',
                'delete' => 'Excluir',
            ],
        ],

        'form' => [
            'name' => 'Nome do Grupo',
            'name-placeholder' => 'Ex: Freios, Motor, Elétrica...',
        ],
    ],
];
