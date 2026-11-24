<?php

return [
    'menu' => [
        'title' => 'Mecânicos',
    ],

    'acl' => [
        'title' => 'Mecânicos',
        'view' => 'Visualizar',
        'create' => 'Criar',
        'edit' => 'Editar',
        'delete' => 'Excluir',
    ],

    'errors' => [
        'legend-status-locked' => 'Este mecânico é uma Lenda e seu status não pode ser alterado.',
        'legend-delete-locked' => 'Este mecânico é uma Lenda e não pode ser excluído.',
    ],

    'index' => [
        'title' => 'Mecânicos',
        'create-btn' => 'Adicionar Mecânico',
        'create-success' => 'Mecânico criado com sucesso.',
        'update-success' => 'Mecânico atualizado com sucesso.',
        'delete-success' => 'Mecânico excluído com sucesso.',
        'delete-failed' => 'Falha ao excluir o mecânico.',

        'datagrid' => [
            'nome' => 'Nome',
            'especialidade' => 'Especialidade',
            'years' => 'Anos de Exp.',
            'avaliacao' => 'Avaliação',
            'status' => 'Status',
            'created-at' => 'Criado em',
            'view' => 'Visualizar',
            'edit' => 'Editar',
            'delete' => 'Excluir',
        ],
    ],

    'create' => [
        'title' => 'Adicionar Mecânico',
        'save-btn' => 'Salvar Mecânico',
    ],

    'edit' => [
        'title' => 'Editar Mecânico',
        'save-btn' => 'Salvar Mecânico',
    ],

    'view' => [
        'title' => 'Mecânico',
    ],

    'form' => [
        'general' => 'Geral',
        'nome' => 'Nome',
        'especialidade' => 'Especialidade',
        'years_of_experience' => 'Anos de Experiência',
        'avaliacao' => 'Avaliação (0-5)',
        'status' => 'Status',

        'especialidade-options' => [
            'motor' => 'Motor',
            'transmissao' => 'Transmissão',
            'eletrica' => 'Elétrica',
            'diagnostico' => 'Diagnóstico',
            'suspensao' => 'Suspensão',
            'freios' => 'Freios',
            'hidraulica' => 'Hidráulica',
            'arrefecimento' => 'Arrefecimento',
        ],

        'status-options' => [
            'ativo' => 'Ativo',
            'lenda' => 'Lenda',
        ],
    ],
];
