<?php

/*
 * Perfis = cargos da mentoria (A4 e B8 do roteiro de 18/11/2026).
 *
 * Cada perfil diz quais chaves de acesso (config/acl) a função dele recebe. A função do
 * usuário é a soma dos perfis dele (acúmulo de cargos): como cada perfil lista só o que
 * é do trabalho dele, a regra "Técnico sem valores" é estrutural: quem só acumula perfis
 * que não têm 'ver_valores_financeiros' nunca recebe valores.
 *
 * As chaves que não existem no sistema são ignoradas (cada pacote traz as suas).
 */
return [
    // Marca que identifica uma função criada pelo sistema (na descrição da função).
    'marcador' => '[Mentoria] ',

    // Chave do perfil => definição. A chave é a mesma gravada em roles.perfil_oficina
    // e em colaboradores.cargos (a do Chefe de oficina é "chefe").
    'perfis' => [
        'proprietario' => [
            'nome' => 'Proprietário',
            'descricao' => 'Administrador da oficina: acesso total. Tela inicial: Painel do Dono.',
            'todos' => true,
            'operacional' => false,
        ],
        'gerente' => [
            'nome' => 'Gerente',
            'descricao' => 'Tudo da oficina, exceto usuários. A Ficha de Enquadramento só para leitura.',
            'operacional' => false,
            'chaves' => [
                'dashboard', 'dashboard.faturamento_geral', 'service_orders', 'service_orders.view', 'service_orders.create',
                'service_orders.edit', 'service_orders.auditar', 'service_orders.forcar_avanco', 'ver_valores_financeiros', 'credito_cliente', 'credito_cliente.registrar',
                'cadastro', 'clientes_busca', 'ordem_servico', 'diagnostico', 'orcamento', 'negociacao', 'em_execucao', 'veiculo_teste',
                'faturamento', 'pos_venda', 'comissoes', 'marketing', 'painel_dono', 'indicadores_mentoria', 'alcadas',
                'registros_mentoria', 'financeiro_rotina', 'financeiro_dre', 'rotina_patio', 'rotina_painel', 'rotina_agenda',
                'bonus_mentoria', 'bonus_gerente', 'bonus_meu', 'colaboradores', 'estoque', 'parts', 'parts.view',
                'parts.create', 'parts.edit', 'parts.movements', 'servicos_catalogo', 'services', 'services.create',
                'services.edit', 'grupos', 'part_categories', 'part_categories.create', 'part_categories.edit', 'contacts',
                'contacts.persons', 'contacts.persons.view', 'contacts.persons.create', 'contacts.persons.edit', 'vehicles',
                'vehicles.view', 'vehicles.create', 'vehicles.edit', 'mechanics', 'mechanics.view', 'mechanics.create',
                'mechanics.edit', 'work_bays', 'work_bays.view', 'work_bays.create', 'work_bays.edit', 'fiscal_documents',
                'fiscal_documents.view', 'fiscal_documents.emit', 'service_requests', 'service_requests.view',
                'service_requests.convert', 'service_requests.invite', 'settings', 'settings.ficha', 'settings.import', 'settings.workshop', 'settings.historico', 'settings.alcadas', 'settings.metas', 'settings.inventory',
                'settings.inventory.warehouse', 'settings.inventory.warehouse.create', 'settings.inventory.warehouse.edit',
                'settings.configuration', 'settings.configuration.general', 'help',
            ],
        ],
        'chefe' => [
            'nome' => 'Chefe de oficina',
            'descricao' => 'Pátio, etapas do Diagnóstico ao Veículo em Teste, rotina, registros e bônus. Sem valores em reais.',
            'operacional' => true,
            'chaves' => [
                'service_orders', 'service_orders.view', 'service_orders.edit', 'service_orders.auditar', 'rotina_patio',
                'diagnostico', 'orcamento', 'negociacao', 'em_execucao', 'veiculo_teste', 'rotina_painel',
                'registros_mentoria', 'bonus_meu',
            ],
        ],
        'consultor' => [
            'nome' => 'Consultor técnico',
            'descricao' => 'Agenda, cadastro, todas as etapas da OS, marketing, rotina e bônus.',
            'operacional' => true,
            'chaves' => [
                'service_orders', 'service_orders.view', 'service_orders.create', 'service_orders.edit', 'ver_valores_financeiros', 'credito_cliente',
                'rotina_agenda', 'cadastro', 'clientes_busca', 'ordem_servico', 'diagnostico', 'orcamento', 'negociacao', 'em_execucao',
                'veiculo_teste', 'faturamento', 'pos_venda', 'marketing', 'rotina_painel', 'bonus_meu', 'contacts',
                'contacts.persons', 'contacts.persons.view', 'contacts.persons.create', 'contacts.persons.edit', 'vehicles',
                'vehicles.view', 'vehicles.create', 'vehicles.edit', 'service_requests', 'service_requests.view',
                'service_requests.convert',
            ],
        ],
        'tecnico' => [
            'nome' => 'Técnico',
            'descricao' => 'Minhas tarefas (cronômetro) e Meu bônus. Nada de valores.',
            'operacional' => true,
            'chaves' => ['tecnico', 'bonus_meu'],
        ],
        'pecas' => [
            'nome' => 'Peças',
            'descricao' => 'Estoque, grupos, rotina e bônus.',
            'operacional' => true,
            'chaves' => [
                'service_orders', 'service_orders.view', 'service_orders.edit', 'ver_valores_financeiros', 'estoque', 'parts',
                'parts.view', 'parts.create', 'parts.edit', 'parts.movements', 'grupos', 'part_categories',
                'part_categories.create', 'part_categories.edit', 'rotina_painel', 'bonus_meu',
            ],
        ],
        'financeiro' => [
            'nome' => 'Financeiro',
            'descricao' => 'Financeiro (todas as abas), faturamento, rotina e bônus.',
            'operacional' => true,
            'chaves' => [
                'service_orders', 'service_orders.view', 'service_orders.edit', 'ver_valores_financeiros', 'credito_cliente', 'credito_cliente.registrar', 'financeiro_rotina',
                'financeiro_dre', 'faturamento', 'fiscal_documents', 'fiscal_documents.view', 'fiscal_documents.emit',
                'rotina_painel', 'bonus_meu',
            ],
        ],
        'zeladoria' => [
            'nome' => 'Auxiliar de copa e zeladoria',
            'descricao' => 'Registros (os checklists dela) e Meu bônus.',
            'operacional' => true,
            'chaves' => ['registros_mentoria', 'bonus_meu'],
        ],
        'mentor' => [
            'nome' => 'Mentor',
            'descricao' => 'Somente leitura: Visão do mentor e Painel do Dono.',
            'operacional' => false,
            'interno' => true,
            'somente_leitura' => true,
            'chaves' => ['mentor_mentoria', 'painel_dono'],
        ],
        'suporte' => [
            'nome' => 'Suporte Volmaster',
            'descricao' => 'Interno da Volmaster: acesso total à oficina. Não é cargo e não recebe alertas.',
            'todos' => true,
            'operacional' => false,
            'interno' => true,
        ],
    ],

    // Nome da função antiga (sem acento, minúsculo) => perfil sugerido ao migrar os usuários.
    'origem' => [
        'administrador' => 'proprietario',
        'administrator' => 'proprietario',
        'consultor' => 'consultor',
        'diagnostico' => 'chefe',
        'financeiro' => 'financeiro',
        'mecanico' => 'tecnico',
        'programador' => 'suporte',
    ],
];
