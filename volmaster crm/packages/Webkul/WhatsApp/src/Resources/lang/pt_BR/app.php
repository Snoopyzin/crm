<?php

return [
    'menu' => [
        'title' => 'WhatsApp',
    ],

    'settings' => [
        'title' => 'Configurações do WhatsApp',
        'update-success' => 'Configurações do WhatsApp atualizadas com sucesso.',
        'not-configured' => 'WhatsApp não configurado. Preencha as credenciais em Configurações > WhatsApp.',
        'access_token' => 'Token de Acesso',
        'access_token-hint' => 'Token permanente de um Usuário do Sistema, gerado no Meta Business Suite.',
        'phone_number_id' => 'ID do Número de Telefone',
        'business_account_id' => 'ID da Conta Comercial (WABA)',
        'webhook_verify_token' => 'Token de Verificação do Webhook',
        'webhook_verify_token-hint' => 'Uma senha qualquer, escolhida por você, usada só para a Meta confirmar que o webhook é seu.',
        'webhook-url' => 'URL do Webhook (cole isso no painel da Meta)',
        'template_orcamento' => 'Nome do Template: Orçamento Pronto',
        'template_diagnostico' => 'Nome do Template: Diagnóstico Pronto',
        'template_pos_venda' => 'Nome do Template: Pesquisa de Satisfação',
        'save-btn' => 'Salvar',
        'requirements-title' => 'Antes de usar',
        'requirements' => 'É preciso ter uma conta comercial verificada na Meta, um número de telefone dedicado ao WhatsApp Business e os templates de mensagem abaixo aprovados pela Meta -- sem isso, o envio automático de orçamento e da pesquisa de pós-venda fica indisponível (o resto do sistema funciona normalmente).',
    ],
];
