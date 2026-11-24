<?php

return [
    'layout' => [
        'title' => 'Portal do Cliente',
        'nav' => [
            'dashboard' => 'Painel',
            'vehicles' => 'Veículos',
            'service-orders' => 'Ordens de Serviço',
            'service-requests' => 'Solicitações de Serviço',
            'logout' => 'Sair',
        ],
    ],

    'sessions' => [
        'email' => 'E-mail',
        'password' => 'Senha',
        'login-error' => 'Essas credenciais não correspondem aos nossos registros.',
        'deactivated-error' => 'Seu acesso ao portal foi desativado. Entre em contato com a oficina.',
        'back-to-login' => 'Voltar para o login',
        'login' => [
            'title' => 'Entrar',
            'submit' => 'Entrar',
            'forgot-password' => 'Esqueceu sua senha?',
        ],
        'forgot-password' => [
            'title' => 'Esqueci minha senha',
            'info' => 'Informe seu e-mail e enviaremos um link para redefinir sua senha.',
            'submit' => 'Enviar link de redefinição',
            'reset-link-sent' => 'Um link de redefinição de senha foi enviado para seu e-mail.',
            'email-not-exist' => 'Não encontramos uma conta com esse e-mail.',
        ],
        'reset-password' => [
            'title' => 'Defina sua senha',
            'new-password' => 'Nova senha',
            'confirm-password' => 'Confirmar senha',
            'submit' => 'Salvar senha',
        ],
    ],

    'dashboard' => [
        'title' => 'Painel',
        'vehicle-count' => 'Veículos',
        'pending-approval-count' => 'Aguardando sua aprovação',
        'recent-service-orders' => 'Ordens de serviço recentes',
        'no-service-orders' => 'Nenhuma ordem de serviço ainda.',
        'view' => 'Ver',
    ],

    'vehicles' => [
        'index' => [
            'title' => 'Meus Veículos',
            'empty' => 'Nenhum veículo cadastrado ainda.',
            'placa' => 'Placa',
            'marca-modelo' => 'Marca / Modelo',
            'ano' => 'Ano',
            'status' => 'Status',
        ],
        'view' => [
            'cor' => 'Cor',
            'km' => 'Quilometragem (km)',
            'history' => 'Histórico de serviços',
        ],
    ],

    'service-orders' => [
        'index' => [
            'title' => 'Minhas Ordens de Serviço',
            'subject' => 'Assunto',
            'total' => 'Total',
            'status' => 'Status',
        ],
        'view' => [
            'vehicle' => 'Veículo',
            'mechanic' => 'Mecânico',
            'data-previsao' => 'Previsão de conclusão',
            'total' => 'Total',
            'items' => 'Itens',
            'no-items' => 'Nenhum item adicionado ainda.',
            'item-name' => 'Item',
            'item-type' => 'Tipo',
            'item-total' => 'Total',
            'decision-title' => 'Sua decisão é necessária',
            'decision-info' => 'Esta ordem de serviço está aguardando sua aprovação. Você pode aprovar para autorizar o serviço, ou recusar para cancelar.',
            'approve' => 'Aprovar orçamento',
            'reject' => 'Recusar',
            'approve-success' => 'Ordem de serviço aprovada. A oficina foi notificada.',
            'reject-success' => 'Ordem de serviço recusada.',
            'decision-locked' => 'Esta ordem de serviço não pode mais ser aprovada ou recusada.',
        ],
    ],

    'service-requests' => [
        'index' => [
            'title' => 'Solicitações de Serviço',
            'new' => 'Nova solicitação',
            'empty' => 'Você ainda não solicitou nenhum serviço.',
            'descricao' => 'Descrição',
            'data-preferencia' => 'Data preferida',
            'status' => 'Status',
        ],
        'create' => [
            'title' => 'Solicitar serviço',
            'vehicle' => 'Veículo',
            'vehicle-other' => '-- Ainda não cadastrado --',
            'vehicle-free-text' => 'Descrição do veículo (se não estiver na lista acima)',
            'vehicle-free-text-hint' => 'ex: Volvo FH 540, placa ainda não cadastrada',
            'descricao' => 'O que você precisa?',
            'data-preferencia' => 'Data preferida (opcional)',
            'submit' => 'Enviar solicitação',
            'success' => 'Sua solicitação foi enviada. A oficina entrará em contato em breve.',
        ],
    ],

    'emails' => [
        'invite' => [
            'subject' => 'Você foi convidado para o portal do cliente :app_name',
            'dear' => 'Olá, :name',
            'info' => 'Você foi convidado para acessar o portal do cliente :app_name, onde pode ver seus veículos e ordens de serviço, aprovar orçamentos e solicitar novos serviços.',
            'cta' => 'Definir minha senha',
            'thanks' => 'Obrigado por escolher a nossa oficina.',
        ],
        'reset-password' => [
            'subject' => 'Redefinir sua senha do portal',
            'dear' => 'Olá, :name',
            'info' => 'Você solicitou a redefinição da sua senha do portal do cliente. Clique no botão abaixo para escolher uma nova senha.',
            'cta' => 'Redefinir senha',
            'thanks' => 'Se você não solicitou isso, pode ignorar este e-mail com segurança.',
        ],
    ],

    'admin' => [
        'menu' => [
            'title' => 'Solicitações de Serviço',
        ],
        'acl' => [
            'title' => 'Solicitações de Serviço',
            'view' => 'Ver',
            'convert' => 'Converter / Gerenciar',
            'invite' => 'Convidar para o Portal',
        ],
        'invite' => [
            'no-email' => 'Este contato não possui e-mail cadastrado, portanto não pode ser convidado para o portal.',
            'success' => 'Convite enviado.',
        ],
        'invite-button' => [
            'active' => 'Portal: Ativo',
            'resend' => 'Reenviar convite',
            'invite' => 'Convidar para o Portal',
        ],
        'service-requests' => [
            'index' => [
                'title' => 'Solicitações de Serviço',
                'cliente' => 'Cliente',
                'veiculo' => 'Veículo',
                'descricao' => 'Descrição',
                'data-preferencia' => 'Data preferida',
                'status' => 'Status',
                'created-at' => 'Recebida em',
                'view' => 'Ver',
            ],
            'view' => [
                'convert' => 'Converter em Ordem de Serviço',
                'reject' => 'Recusar solicitação',
                'link-order' => 'Vincular Ordem de Serviço',
                'service-order-id' => 'Ordem de Serviço #',
                'link-success' => 'Solicitação vinculada à ordem de serviço.',
                'reject-success' => 'Solicitação recusada.',
            ],
            'convert' => [
                'servico-prefix' => 'Solicitação do cliente',
            ],
        ],
    ],
];
