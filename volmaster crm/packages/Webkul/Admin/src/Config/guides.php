<?php

return [
    [
        'id' => 'bem-vindo',
        'icon' => 'wave',
        'title' => 'Bem-vindo ao sistema',
        'description' => 'Visão geral rápida de como tudo se conecta',
        'steps' => [
            [
                'id' => 'bem-vindo.1',
                'title' => 'Como o sistema funciona',
                'content' => 'O coração do sistema é a <strong>Ordem de Serviço</strong>: cada veículo que entra na oficina vira uma OS, onde você lança as peças e serviços realizados, acompanha o status e, no fim, gera o valor cobrado do cliente.',
            ],
            [
                'id' => 'bem-vindo.2',
                'title' => 'Comece pelo início',
                'content' => 'A tela inicial (Dashboard) mostra o que está pendente agora e o faturamento de Ordens de Serviço concluídas e canceladas. É o primeiro lugar pra olhar todo dia.',
            ],
        ],
    ],

    [
        'id' => 'ordens-servico',
        'icon' => 'wrench',
        'title' => 'Ordens de Serviço',
        'description' => 'O fluxo principal: do veículo na oficina até o serviço cobrado',
        'steps' => [
            [
                'id' => 'ordens-servico.1',
                'title' => 'Criando uma Ordem de Serviço',
                'content' => 'Em <strong>Ordens de Serviço</strong>, clique em criar, escolha o cliente e o veículo (ou cadastre na hora) e descreva o problema relatado.',
            ],
            [
                'id' => 'ordens-servico.2',
                'title' => 'Lançando peças e serviços',
                'content' => 'Dentro da OS, adicione os itens usados: peças (que descontam do estoque automaticamente) e serviços/mão de obra. O valor total é calculado sozinho conforme você adiciona itens.',
            ],
            [
                'id' => 'ordens-servico.3',
                'title' => 'Acompanhando o status',
                'content' => 'Mude o status conforme o andamento: Aberta → Autorizado → Em Andamento → Aguardando Peças → Concluída (ou Cancelada). Uma OS marcada como Concluída não pode voltar atrás.',
            ],
            [
                'id' => 'ordens-servico.4',
                'title' => 'O que acontece ao concluir',
                'content' => 'Ao concluir, a data de conclusão é registrada automaticamente e o valor entra no card de faturamento da Dashboard. Peças reservadas são baixadas do estoque de verdade nesse momento.',
            ],
        ],
    ],

    [
        'id' => 'clientes-veiculos',
        'icon' => 'car',
        'title' => 'Clientes e Veículos',
        'description' => 'Cadastro de quem e do que você atende',
        'steps' => [
            [
                'id' => 'clientes-veiculos.1',
                'title' => 'Cadastrando um cliente',
                'content' => 'Em <strong>Contatos</strong>, cadastre a pessoa (particular) ou vincule a uma empresa/organização, caso o cliente seja uma frota.',
            ],
            [
                'id' => 'clientes-veiculos.2',
                'title' => 'Cadastrando um veículo',
                'content' => 'Em <strong>Veículos</strong>, cadastre marca, modelo e placa, vinculando ao cliente dono. Esse veículo passa a aparecer nas opções ao criar uma nova Ordem de Serviço para esse cliente.',
            ],
        ],
    ],

    [
        'id' => 'pecas-estoque',
        'icon' => 'box',
        'title' => 'Peças & Estoque',
        'description' => 'Controle de quantidade disponível de cada peça',
        'steps' => [
            [
                'id' => 'pecas-estoque.1',
                'title' => 'Cadastrando peças',
                'content' => 'Em <strong>Peças</strong>, cadastre o item com sua quantidade em estoque. Ao lançar essa peça numa Ordem de Serviço, o sistema reserva a quantidade automaticamente.',
            ],
            [
                'id' => 'pecas-estoque.2',
                'title' => 'Reserva x baixa de estoque',
                'content' => 'Enquanto a OS está em andamento, a peça fica só <em>reservada</em> (allocated). A baixa definitiva do estoque só acontece quando a OS é marcada como Concluída. Se a OS for cancelada, a reserva é liberada de volta.',
            ],
        ],
    ],

    [
        'id' => 'servicos-remotos',
        'icon' => 'monitor',
        'title' => 'Serviços Remotos',
        'description' => 'Atendimentos via TeamViewer com O.S. automática',
        'steps' => [
            [
                'id' => 'servicos-remotos.1',
                'title' => 'Registrando um atendimento',
                'content' => 'Em <strong>Serviços Remotos</strong>, registre o cliente, o ID do TeamViewer e o tipo de atendimento (ex: atualização de APCI). Não precisa ter veículo cadastrado pra começar.',
            ],
            [
                'id' => 'servicos-remotos.2',
                'title' => 'Conectando com um clique',
                'content' => 'Na tela do atendimento, o botão "Conectar via TeamViewer" já abre o aplicativo TeamViewer direto no ID cadastrado (o app precisa estar instalado no seu computador).',
            ],
            [
                'id' => 'servicos-remotos.3',
                'title' => 'Finalizando gera a Ordem de Serviço',
                'content' => 'Ao finalizar o atendimento com a solução e o valor cobrado, uma Ordem de Serviço de verdade é criada automaticamente — sem precisar lançar tudo de novo manualmente.',
            ],
        ],
    ],

    [
        'id' => 'dashboard',
        'icon' => 'chart',
        'title' => 'Entendendo a Dashboard',
        'description' => 'O que cada card da tela inicial está te mostrando',
        'steps' => [
            [
                'id' => 'dashboard.1',
                'title' => 'OS em andamento',
                'content' => 'Lista as Ordens de Serviço que ainda não foram concluídas nem canceladas, das mais antigas para as mais novas — é o que está "faltando terminar" agora. A cor da etapa mostra o prazo: <strong>verde</strong> = dentro do prazo, <strong>vermelho</strong> = prazo estourado e <strong>cinza</strong> = OS sem prazo definido. O prazo é o da regra da etapa (quando os alertas do piloto estão ligados) ou, se não houver, a previsão de entrega da OS. Passe o mouse sobre a etapa para ver a data.',
            ],
            [
                'id' => 'dashboard.2',
                'title' => 'Faturamento de Ordens de Serviço',
                'content' => 'Mostra quanto foi <strong>Concluído</strong> (verde) e quanto foi <strong>Cancelado</strong> (vermelho) em valor real de Ordens de Serviço, no período selecionado no topo da página.',
            ],
        ],
    ],
];
