<?php

return [
    [
        'key' => 'service_orders',
        'name' => 'service_order::app.acl.title',
        'route' => 'admin.service_orders.index',
        'sort' => 1,
    ], [
        'key' => 'service_orders.view',
        'name' => 'service_order::app.acl.view',
        'route' => 'admin.service_orders.view',
        'sort' => 1,
    ], [
        'key' => 'service_orders.create',
        'name' => 'service_order::app.acl.create',
        'route' => ['admin.service_orders.create', 'admin.service_orders.store', 'admin.service_orders.cadastro.create', 'admin.service_orders.cadastro.store', 'admin.service_orders.cadastro.cep', 'admin.clientes.pesquisar'],
        'sort' => 2,
    ], [
        'key' => 'service_orders.edit',
        'name' => 'service_order::app.acl.edit',
        'route' => [
            'admin.service_orders.edit',
            'admin.service_orders.update',
            'admin.service_orders.status.update',
            'admin.service_orders.stage.update',
            'admin.service_orders.items.alerta.update',
            'admin.service_orders.diagnostico.update',
            'admin.service_orders.negociacao.update',
            'admin.service_orders.checklist.store',
            'admin.service_orders.checklist.toggle',
            'admin.service_orders.checklist.destroy',
            'admin.service_orders.orcamento.mail',
            'admin.service_orders.orcamento.whatsapp',
            'admin.service_orders.timer.start',
            'admin.service_orders.timer.pause',
            'admin.service_orders.timer.resume',
            'admin.service_orders.timer.finish',
            'admin.service_orders.teste.start',
            'admin.service_orders.teste.finish',
            'admin.service_orders.boletos.store',
            'admin.service_orders.boletos.asaas',
            'admin.service_orders.boletos.update',
            'admin.service_orders.pos_venda.send',
            'admin.service_orders.workflow.termo',
            'admin.service_orders.workflow.termo_link',
            'admin.service_orders.workflow.cor_credito',
            'admin.service_orders.workflow.designate',
            'admin.service_orders.workflow.laudo',
            'admin.service_orders.workflow.diagnostico.start',
            'admin.service_orders.workflow.diagnostico.finish',
            'admin.service_orders.workflow.evidence',
            'admin.service_orders.workflow.diagnostico.midia',
            'admin.service_orders.workflow.diagnostico.horarios',
            'admin.service_orders.defeito.update',
            'admin.service_orders.extras.store',
            'admin.service_orders.extras.destroy',
            'admin.service_orders.workflow.parts',
            'admin.service_orders.workflow.budget_sent',
            'admin.service_orders.workflow.approval',
            'admin.service_orders.workflow.nf',
            'admin.service_orders.workflow.payment',
            'admin.service_orders.workflow.agreement',
            'admin.service_orders.workflow.operation',
            'admin.service_orders.workflow.action',
        ],
        'sort' => 3,
    ], [
        // Visto do chefe no diagnóstico e confirmação de atraso de operação.
        'key' => 'service_orders.auditar',
        'name' => 'service_order::app.acl.auditar',
        'route' => [
            'admin.service_orders.workflow.diagnostico.audit',
            'admin.service_orders.workflow.operation.confirm_delay',
        ],
        'sort' => 5,
    ], [
        // Forçar o avanço de etapa com justificativa (gera alerta crítico).
        'key' => 'service_orders.forcar_avanco',
        'name' => 'service_order::app.acl.forcar_avanco',
        'route' => 'admin.service_orders.forcar_avanco',
        'sort' => 6,
    ], [
        // Valores em reais, descontos, crédito, forma de pagamento e comissões.
        // O perfil Técnico fica sem esta permissão.
        'key' => 'ver_valores_financeiros',
        'name' => 'service_order::app.acl.ver_valores_financeiros',
        'route' => 'admin.service_orders.ver_valores_financeiros',
        'sort' => 7,
    ], [
        // Início: faturamento geral da oficina. Quem tem % de comissão na Função
        // só vê a própria comissão, a menos que receba esta permissão.
        'key' => 'dashboard.faturamento_geral',
        'name' => 'service_order::app.acl.faturamento_geral',
        'route' => 'admin.dashboard.faturamento_geral',
        'sort' => 2,
    ], [
        // Tela "Minhas tarefas" do técnico (celular ou tablet no box).
        'key' => 'tecnico',
        'name' => 'service_order::app.menu.tecnico',
        'route' => [
            'admin.tecnico.index',
            'admin.tecnico.tasks',
            'admin.tecnico.diagnostico.start',
            'admin.tecnico.diagnostico.laudo',
            'admin.tecnico.diagnostico.evidence',
            'admin.tecnico.diagnostico.part',
            'admin.tecnico.diagnostico.parts',
            'admin.tecnico.diagnostico.finish',
            'admin.tecnico.operation',
        ],
        'sort' => 1,
    ], [
        'key' => 'settings.workshop',
        'name' => 'service_order::app.acl.settings_workshop',
        'route' => [
            'admin.workshop.settings.edit',
            'admin.workshop.settings.general',
            'admin.workshop.settings.rule',
            'admin.workshop.settings.roles',
            'admin.workshop.settings.complaints',
            'admin.workshop.settings.vapid',
            'admin.workshop.settings.restore_rules',
            'admin.workshop.settings.sms_report',
        ],
        'sort' => 12,
    ], [
        'key' => 'painel_dono',
        'name' => 'Painel do Dono',
        'route' => ['admin.mentoria.painel', 'admin.mentoria.painel.celular', 'admin.mentoria.painel.dados', 'admin.mentoria.painel.pdf'],
        'sort' => 1,
    ], [
        'key' => 'indicadores_mentoria',
        'name' => 'Indicadores',
        'route' => ['admin.mentoria.indicadores', 'admin.mentoria.indicador'],
        'sort' => 2,
    ], [
        'key' => 'registros_mentoria',
        'name' => 'Registros do dia a dia',
        'route' => ['admin.mentoria.registros', 'admin.mentoria.registros.store', 'admin.mentoria.registros.correct'],
        'sort' => 3,
    ], [
        // Bônus: ver as notas, revisar (gerente), aprovar e exportar a folha (dono), "meu bônus" (cada colaborador).
        'key' => 'bonus_mentoria',
        'name' => 'Bônus (ver notas e travas)',
        'route' => ['admin.mentoria.bonus', 'admin.mentoria.bonus.detalhe', 'admin.mentoria.bonus.travas'],
        'sort' => 6,
    ], [
        'key' => 'bonus_gerente',
        'name' => 'Bônus (calcular, revisar e registrar travas)',
        'route' => ['admin.mentoria.bonus.calcular', 'admin.mentoria.bonus.revisar', 'admin.mentoria.bonus.travas.store', 'admin.mentoria.bonus.travas.decidir', 'admin.mentoria.bonus.painel'],
        'sort' => 7,
    ], [
        'key' => 'bonus_dono',
        'name' => 'Bônus (aprovar, estornar e exportar a folha)',
        'route' => ['admin.mentoria.bonus.aprovar', 'admin.mentoria.bonus.aprovar_todos', 'admin.mentoria.bonus.estornar', 'admin.mentoria.bonus.exportar'],
        'sort' => 8,
    ], [
        'key' => 'bonus_meu',
        'name' => 'Meu bônus',
        'route' => ['admin.mentoria.bonus.meu', 'admin.mentoria.bonus.ciencia'],
        'sort' => 9,
    ], [
        // Financeiro da rotina: contas a pagar, caixa, conciliação, cobrança e relatórios diários.
        'key' => 'financeiro_rotina',
        'name' => 'Financeiro (contas, caixa, cobrança e relatórios)',
        'route' => [
            'admin.financeiro.lancamentos', 'admin.financeiro.lancamentos.store', 'admin.financeiro.classificar', 'admin.financeiro.pagar',
            'admin.financeiro.contas', 'admin.financeiro.subconta', 'admin.financeiro.caixa', 'admin.financeiro.caixa.fechar',
            'admin.financeiro.conciliar', 'admin.financeiro.cobranca', 'admin.financeiro.relatorios', 'admin.financeiro.relatorios.enviar',
            'admin.financeiro.relatorios.gerente',
        ],
        'sort' => 10,
    ], [
        'key' => 'financeiro_dre',
        'name' => 'DRE, preço da hora e orçamento',
        'route' => [
            'admin.financeiro.dre', 'admin.financeiro.dre.congelar', 'admin.financeiro.dre.reabrir', 'admin.financeiro.analises',
            'admin.financeiro.orcamento', 'admin.financeiro.orcamento.salvar', 'admin.financeiro.marco_zero',
        ],
        'sort' => 11,
    ], [
        'key' => 'rotina_patio',
        'name' => 'Quadro de pátio',
        'route' => 'admin.rotina.patio',
        'sort' => 17,
    ], [
        'key' => 'rotina_painel',
        'name' => 'Minha rotina (painel do cargo e checklists)',
        'route' => ['admin.rotina.painel', 'admin.rotina.checklist', 'admin.rotina.diaria'],
        'sort' => 18,
    ], [
        'key' => 'rotina_agenda',
        'name' => 'Agenda (etapa 00)',
        'route' => ['admin.rotina.agenda', 'admin.rotina.agendar', 'admin.rotina.agenda.status'],
        'sort' => 19,
    ], [
        'key' => 'mentor_mentoria',
        'name' => 'Visão do mentor (comparativo entre oficinas)',
        'route' => ['admin.mentor.index', 'admin.mentor.replicar_metas', 'admin.mentor.replicar_pesos'],
        'sort' => 12,
    ], [
        // Cor de crédito do cliente (B1): ver o cartão no cadastro do cliente e registrar a análise.
        'key' => 'credito_cliente',
        'name' => 'Cor de crédito do cliente (ver)',
        'route' => 'admin.credito.ver',
        'sort' => 13,
    ], [
        'key' => 'credito_cliente.registrar',
        'name' => 'Cor de crédito do cliente (registrar a análise)',
        'route' => 'admin.credito.registrar',
        'sort' => 14,
    ], [
        // Funções dos cargos da mentoria e migração dos usuários (A4).
        'key' => 'settings.perfis',
        'name' => 'Perfis da mentoria',
        'route' => ['admin.workshop.perfis.index', 'admin.workshop.perfis.aplicar', 'admin.workshop.perfis.migrar', 'admin.workshop.perfis.desfazer'],
        'sort' => 12,
    ], [
        'key' => 'settings.historico',
        'name' => 'Importar o histórico das planilhas',
        'route' => ['admin.historico.index', 'admin.historico.importar', 'admin.historico.modelo'],
        'sort' => 16,
    ], [
        'key' => 'colaboradores',
        'name' => 'Colaboradores',
        'route' => ['admin.colaboradores.index', 'admin.colaboradores.store', 'admin.colaboradores.edit', 'admin.colaboradores.update', 'admin.colaboradores.desligar'],
        'sort' => 5,
    ], [
        // Ver a Ficha (o Gerente lê, não altera).
        'key' => 'settings.ficha',
        'name' => 'Ficha de Enquadramento',
        'route' => ['admin.ficha.edit'],
        'sort' => 15,
    ], [
        // Alterar a Ficha, as pendências, a tabela de margem e ver o registro de acesso (LGPD).
        'key' => 'settings.ficha.editar',
        'name' => 'Editar a Ficha de Enquadramento',
        'route' => ['admin.ficha.update', 'admin.ficha.pendencia', 'admin.ficha.pendencia.resolver', 'admin.ficha.margens', 'admin.ficha.acessos'],
        'sort' => 1,
    ], [
        'key' => 'alcadas',
        'name' => 'Fila de alçadas',
        'route' => ['admin.mentoria.alcadas', 'admin.mentoria.alcadas.dados', 'admin.mentoria.alcadas.solicitar', 'admin.mentoria.alcadas.decidir'],
        'sort' => 4,
    ], [
        'key' => 'settings.alcadas',
        'name' => 'Matriz de alçadas',
        'route' => ['admin.mentoria.alcadas.matriz', 'admin.mentoria.alcadas.matriz.update'],
        'sort' => 14,
    ], [
        'key' => 'settings.metas',
        'name' => 'Tabela de metas',
        'route' => ['admin.mentoria.metas', 'admin.mentoria.metas.update'],
        'sort' => 13,
    ], [
        'key' => 'service_orders.delete',
        'name' => 'service_order::app.acl.delete',
        'route' => 'admin.service_orders.destroy',
        'sort' => 4,
    ], [
        'key' => 'cadastro',
        'name' => 'service_order::app.menu.cadastro',
        'route' => 'admin.service_orders.cadastro.create',
        'sort' => 2,
    ], [
        'key' => 'clientes_busca',
        'name' => 'service_order::app.menu.clientes_busca',
        'route' => 'admin.clientes.busca',
        'sort' => 2,
    ], [
        'key' => 'diagnostico',
        'name' => 'service_order::app.menu.diagnostico',
        // A lista da etapa é conferida no controller (stageIndex), etapa por etapa.
        'route' => 'admin.service_orders.stage.index.diagnostico',
        'sort' => 3,
    ], [
        'key' => 'ordem_servico',
        'name' => 'service_order::app.menu.ordem_servico',
        // A lista da etapa é conferida no controller (stageIndex), etapa por etapa.
        'route' => 'admin.service_orders.stage.index.ordem_servico',
        'sort' => 4,
    ], [
        'key' => 'orcamento',
        'name' => 'service_order::app.menu.orcamento',
        // A lista da etapa é conferida no controller (stageIndex), etapa por etapa.
        'route' => 'admin.service_orders.stage.index.orcamento',
        'sort' => 5,
    ], [
        'key' => 'negociacao',
        'name' => 'service_order::app.menu.negociacao',
        // A lista da etapa é conferida no controller (stageIndex), etapa por etapa.
        'route' => 'admin.service_orders.stage.index.negociacao',
        'sort' => 6,
    ], [
        'key' => 'em_execucao',
        'name' => 'service_order::app.menu.em_execucao',
        // A lista da etapa é conferida no controller (stageIndex), etapa por etapa.
        'route' => 'admin.service_orders.stage.index.em_execucao',
        'sort' => 7,
    ], [
        'key' => 'veiculo_teste',
        'name' => 'service_order::app.menu.veiculo_teste',
        // A lista da etapa é conferida no controller (stageIndex), etapa por etapa.
        'route' => 'admin.service_orders.stage.index.veiculo_teste',
        'sort' => 8,
    ], [
        'key' => 'faturamento',
        'name' => 'service_order::app.menu.faturamento',
        // A lista da etapa é conferida no controller (stageIndex), etapa por etapa.
        'route' => 'admin.service_orders.stage.index.faturamento',
        'sort' => 9,
    ], [
        'key' => 'pos_venda',
        'name' => 'service_order::app.menu.pos_venda',
        // A lista da etapa é conferida no controller (stageIndex), etapa por etapa.
        'route' => 'admin.service_orders.stage.index.pos_venda',
        'sort' => 10,
    ], [
        'key' => 'comissoes',
        'name' => 'Comissões',
        'route' => ['admin.commissions.index', 'admin.commissions.export'],
        'sort' => 12,
    ], [
        'key' => 'marketing',
        'name' => 'Marketing',
        'route' => ['admin.marketing.index', 'admin.marketing.export'],
        'sort' => 13,
    ], [
        'key' => 'impact',
        'name' => 'Impact (abrir o Portal)',
        'route' => 'admin.impact.open',
        'sort' => 14,
    ], [
        'key' => 'settings.import',
        'name' => 'Importar dados',
        'route' => [
            'admin.import.index',
            'admin.import.upload',
            'admin.import.mapping',
            'admin.import.preview',
            'admin.import.run',
            'admin.import.cancel',
            'admin.import.template',
        ],
        'sort' => 11,
    ],
];
