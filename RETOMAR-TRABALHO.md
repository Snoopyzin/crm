# Volmaster Tech CRM — ponto de retomada (23/10/2026)

Documento para retomar o trabalho com o Claude depois da formatação do PC.
Ao voltar: abra a pasta `volmaster crm` no VS Code, inicie o Claude e diga
"leia o RETOMAR-TRABALHO.md e vamos continuar".

## Onde está cada coisa

| Pasta / arquivo | O que é |
|---|---|
| `volmaster crm/` | O CRM (Laravel/Krayin) — código-fonte completo, versão mais nova |
| `deploy-staging/` | Pacotes de deploy. Em 18/11/2026 a pasta foi limpa (zips antigos, pacote completo de produção e o `LEIA-ME-DEPLOY.md` foram para a Lixeira do Windows): ficou só `volmaster-atualizacao-2026-11-18-roteiro.zip`, que é o que se envia à HostGator. Um pacote completo novo se gera a partir de `volmaster crm/` |
| `gestão oficina volmaster/` | Sistema antigo (HTML/JS) + documentação em `documentacao-notion/` |
| `volmaster-admin-app/` | App de celular (Capacitor) |
| `volmastercrm-uploads/` | Uploads locais de teste |

Site no ar: **volmastercrm.com** (HostGator, cPanel). O banco de produção e o
`.env` de produção estão **no servidor** — a formatação do PC não os afeta.

## Como fazemos deploy (importante)

1. Montar um **zip de atualização** só com os arquivos alterados desde o último
   zip enviado — **nunca** incluir `.env` (o do servidor tem as senhas reais).
   Incluir sempre `public/migrate-safe.php`.
2. Na HostGator: Gerenciador de Arquivos → pasta do projeto (a que tem `app`,
   `packages`, `public`, `vendor`, `.env`) → Carregar o zip → Extrair ali mesmo
   (substituir). Não apagar nada.
3. Rodar **uma vez** `https://volmastercrm.com/migrate-safe.php?key=volmaster-setup-2026`
   (recarregar a página mostra "Nothing to migrate" — normal).
4. Apagar `public/migrate-safe.php` e o zip.

Último zip gerado: `deploy-staging/volmaster-atualizacao-2026-11-18-roteiro.zip` (módulo da
Mentoria + ajustes de 17/11/2026). Ele assume que as atualizações de 20/10, 21/10 e 27/10 já
estão no servidor: **confirmar com o usuário**. **Ainda não foi enviado ao servidor.**

## O que foi feito nesta rodada (out/2026)

- **Menu lateral** com barra de rolagem.
- **Nota fiscal self-service para oficinas novas** (multi-tenant): oficina
  preenche Configurações > Nota Fiscal (dados, endereço, certificado A1);
  Volmaster aprova em Configurações > Clientes, o que cria a empresa na Focus
  NFe via token principal (`FOCUSNFE_TOKEN_PRINCIPAL` no .env do servidor,
  `FOCUSNFE_EMPRESAS_DRY_RUN=1` = só simulação). A própria Volmaster continua
  usando `public/api-keys-manager.php`.
- **CNPJ da nota fiscal da Volmaster**: 34.307.718/0001-47 (Ferramentas de
  Diagnóstico) — é o cadastrado na Focus. NÃO é o 30.218.032/0001-29 (Treinamentos).
- **Página de acompanhamento do cliente** com caminhão animado (estilo flat).
- **Importar dados por planilha** (Configurações > Importar dados): clientes +
  veículos e peças + estoque, com reconhecimento de colunas e prévia.
- **Desconto geral distribuído entre os itens** na Negociação (editável por item)
  + campo "Executado por" em cada item.
- **Relatório de Comissões** (menu Comissões): % da Função sobre o valor final
  de cada item executado + % do consultor sobre o total da OS; conta pela data
  de faturamento; exporta Excel.
- **Valores em R$ 10.000,00** em todos os campos de dinheiro (máscara + conversão
  no servidor).
- **WhatsApp**: telefone no formato +55 (DDD) NÚMERO, mensagens de erro reais,
  número de destino visível no Orçamento.
- **OS impressa (PDF)**: cabeçalho com CNPJ da oficina, rodapé Volmaster (sem
  marca d'água), dados completos do cliente e veículo (ano, cor, chassi, KM,
  frota), serviços e peças separados com código e subtotal, condição de
  pagamento, campo de assinatura/documento na retirada.
- Correção: cadastrar cliente sem WhatsApp dava erro.
- **Origem do cliente / Marketing** (27/10): pergunta obrigatória "Por qual meio
  você chegou à nossa empresa?" (Instagram, Facebook, Google, TikTok, Indicação,
  Outros) no Cadastro e na Nova OS, editável na tela da OS; menu **Marketing**
  com OS abertas, faturadas, conversão, faturamento e ticket médio por canal.
  Zip: `deploy-staging/volmaster-atualizacao-2026-10-27.zip` (precisa migrate-safe).
- **Cadastro** (27/10): só CPF/CNPJ e WhatsApp são obrigatórios; veículo, nome e
  origem opcionais (serviço só de software não tem veículo — OS sem veículo
  aparece normal nas listas; PDF troca "Retirada do veículo" por "Aceite do
  serviço"). Cliente que volta (mesmo CPF/CNPJ ou WhatsApp) é reaproveitado.
- **Portal Impact (Otochip)**: atalho em `volmaster crm/impact/Portal.lnk` para
  um app de desktop instalado em `AppData\Local\Programs\Portal` — NÃO é
  backupeado pela pasta; reinstalar com o instalador/licença da Otochip.
  **Botão "Impact" no menu do CRM** abre o Portal instalado na máquina via link
  `volmaster-impact://`. Em cada máquina de cliente (depois de instalar o
  Portal), rodar `impact/instalar-atalho-impact.cmd` uma vez (sem admin);
  `remover-atalho-impact.cmd` desfaz.
- Ideias anotadas da concorrente Bruning Eixo (ver lista na conversa de 27/10):
  consulta de placa automática, agenda, financeiro (contas a pagar/receber,
  fluxo de caixa, DRE), lembrete de revisão, checklist com fotos/assinatura no app.

## Piloto de indicadores e alertas (16/11/2026)

Especificação do patrão (capítulos 1 a 12). Escopo implementado: o **piloto do
capítulo 12**, em uma oficina. Todo o código novo está em
`packages/Webkul/ServiceOrder` (sem tocar no autoload do Composer).
Zip de atualização: `deploy-staging/volmaster-atualizacao-2026-11-16-piloto.zip`
(precisa do `migrate-safe.php`, já incluído). **Ainda não foi enviado ao servidor.**

- **Chave do piloto**: Configurações > Indicadores e alertas. Vem **desligada**;
  desligada, a oficina usa o fluxo de sempre. Ligada, valem bloqueios por etapa,
  barreiras, alertas e a restrição de valores do perfil Técnico.
- **Log de eventos** (`service_order_events`): só inserção; correção = novo evento.
- **Bloqueios do Avançar** (`StageGate`): lista o que falta; forçar exige a permissão
  "Forçar avanço" + justificativa e gera alerta crítico (gerente; dono se for saída).
- **Barreiras** (`barrier_rules`): "Orçamento em 2 horas" e "Operação acima do tempo",
  mais os críticos. Prazos e destinos editáveis na tela. O **job a cada minuto** é
  `php artisan workshop:tick` (agendado). **Na HostGator precisa de cron de 1 minuto**:
  `* * * * * cd /caminho/do/projeto && php artisan schedule:run >/dev/null 2>&1`
- **Alertas**: sino (canto inferior direito), push (PWA, chaves VAPID geradas na tela
  de configuração) e SMS de reserva. SMS está em modo "Simulado" até haver provedor;
  o driver Twilio existe mas **nunca foi testado numa conta real**.
- **Papéis**: na tela de configuração, dizer qual papel (chefe, gerente, proprietário...)
  cada função representa e cadastrar celular + aceite de SMS dos colaboradores.
- **Perfil Técnico sem valores**: permissão `ver_valores_financeiros` (checada no
  servidor). Para o técnico, desmarcar essa permissão e marcar "Minhas tarefas"
  (`tecnico`) na função dele; vincular o usuário ao mecânico.
- Testes: `tests/Feature/Workshop` (53 testes). Rodar com PHP 8.3 e um banco de
  teste (não o de produção): `DB_DATABASE=krayin_test php artisan test`.
  Ambiente local usado: PHP/MariaDB portáteis em `C:\Users\Usuario\dev-tools`
  (no Windows, definir `OPENSSL_CONF` para o `extras\ssl\openssl.cnf` do PHP).
- Fora do piloto (próximas entregas): demais barreiras, indicadores no topo de cada
  etapa, Painel do Dono, alçadas, financeiro/DRE, bônus.
- Em aberto com o mentor: provedor de SMS; PWA ou app de loja; horário comercial
  da oficina piloto.

## Módulo da Mentoria completo (17/11/2026)

Depois do piloto, foi implementado o restante da especificação (capítulos 1 a 11).
Tudo continua em `packages/Webkul/ServiceOrder` (sem tocar no autoload do Composer),
atrás da **chave do piloto** (Configurações > Indicadores e alertas). Desligada, a
oficina usa o fluxo de sempre. **Nada disso foi enviado ao servidor ainda.**
Zip: `deploy-staging/volmaster-atualizacao-2026-11-18-roteiro.zip` (inclui o
`public/migrate-safe.php`; substitui o zip do piloto, que fica contido nele).

O que existe (menu lateral e Configurações):

- **Barreiras (20)**: as do capítulo 11.2, com prazo dinâmico, fim do dia, data-alvo,
  relógio corrido e escalada em %; todas editáveis em Indicadores e alertas.
  Passos do fluxo que alimentam as barreiras: "Passos desta etapa" na tela da OS
  (`WorkflowActions`). Bloqueios novos: Orçamento→Negociação (peças precificadas e
  orçamento enviado), Test drive→Faturamento (autorização, CNH, km, conferência),
  motor/transmissão (checklist de montagem), saída (checklist de saída).
- **Indicadores (116)**: `Services/Indicators`. Telas: Indicadores (por cargo, com as
  OS que formam o número), Registros do dia a dia (auditorias com nota, ocorrências,
  checklists; só inserção, correção com justificativa), Tabela de metas (Configurações).
  Cartões coloridos no topo de cada etapa e semáforo de prazo na lista de OS.
- **Painel do Dono**: `Mentoria > Painel do Dono` (6 blocos, comparação com o mês anterior,
  PDF) e versão do celular instalável (`/dono.webmanifest`): Hoje, Alertas, Alçadas (aprovar
  no celular), Indicadores e Financeiro.
- **Alçadas**: matriz por oficina (limites em branco até o dono preencher: em branco não
  bloqueia), fila (gerente decide ou encaminha; dono só vê o encaminhado; 24 h), bloqueio de
  desconto, forma de pagamento a prazo para crédito amarelo/vermelho, compra de peça sem
  autorização, preço de peça abaixo do mínimo, reabertura da DRE. Ação do dono dentro da
  OS gera "ação do dono fora do fluxo" (DON-05).
- **Cadastros**: Colaboradores (cargos acumulados, vencimentos, CNH, competências),
  Ficha de Enquadramento (estrutura, valores, garantia, parâmetros financeiros, bônus,
  início da mentoria), cargos sem responsável (pendência com prazo), tabela de margem de peças.
- **Bônus**: pesos do capítulo 6.2 (editáveis), travas (automáticas e manuais, confirmadas
  pelo gerente; a do gerente pelo dono), notas, faixas, fechamento no dia 1, revisão do
  gerente com feedback, aprovação do dono do dia 1 ao 5º dia útil, estorno justificado,
  exportação da folha (CSV), "Meu bônus" com ciência do colaborador.
- **Financeiro**: contas a pagar classificadas no plano de contas, conciliação, caixa do
  dia (diferença avisa gerente e dono), régua de cobrança, relatório diário ao dono e o de
  5 linhas do gerente, DRE gerencial (congela no fechamento), ponto de equilíbrio, preço
  mínimo da hora e da peça, margem por OS e por cliente, simulador "e se", caixa projetado
  (90 dias), orçamento anual, Marco Zero.
- **Rotina**: Quadro de pátio, Minha rotina (painel do chefe, peças, financeiro, consultor,
  gerente e checklists de um toque da zeladoria), Agenda (etapa 00, 80% da capacidade),
  diária de pátio. Com o piloto ligado, cada cargo cai direto na própria tela.
- **Mentor**: `Visão do mentor` (comparativo entre oficinas só com %, evolução contra o
  Marco Zero, replicação das metas e pesos padrão) e `Importar o histórico das planilhas`.
- **LGPD**: registro de acesso (Ficha > Registro de acesso), backup diário
  (`php artisan workshop:backup`, precisa de `mysqldump` no servidor; senão informe
  `MYSQLDUMP_PATH` no `.env`), link de acompanhamento sem crédito nem valores.

Para ativar numa oficina (nesta ordem):
1. Subir o zip e rodar `migrate-safe.php` uma vez. **Atenção:** ele migra o banco da
   instalação principal. Se a oficina piloto for um subdomínio com banco próprio, as
   migrations também precisam rodar nesse banco (confirmar como as atualizações anteriores
   chegaram aos bancos das oficinas).
2. Cron de 1 minuto (`php artisan schedule:run`): barreiras, alertas periódicos,
   fechamento do dia 1 e backup às 2h30 dependem dele.
3. Configurações > Indicadores e alertas: papéis de cada função (pode marcar vários),
   celulares e aceite de SMS, chaves de notificação, horário comercial.
4. Configurações > Ficha de Enquadramento e Colaboradores; Matriz de alçadas; Tabela de metas.
5. Dar as permissões novas às funções (Painel do Dono, Indicadores, Registros, Bônus,
   Financeiro, DRE, Rotina, Agenda...), desmarcando "Ver valores financeiros" do técnico.
6. Ligar a chave do piloto.

Limites conhecidos: SMS segue em modo simulado até haver provedor (driver Twilio não foi
testado em conta real); notificação no celular precisa de teste em Android/iPhone com HTTPS;
o PDF e o CSV usam o que o servidor tiver (DomPDF já vem no projeto); integração com banco
(conciliação por extrato) não existe: a conciliação é por dia e conta, feita pelo financeiro.
Testes: `tests/Feature/Workshop` (rodar com `DB_DATABASE=krayin_test php artisan test --filter=Workshop`).
Última rodada (17/11/2026): suíte completa com 224 testes passando e 1 falha antiga e alheia
(`tests/Feature/HelpTest`, que espera textos em inglês do Krayin). O zip da mentoria leva a pasta
`packages/Webkul/ServiceOrder/src` inteira (210 arquivos, 10 migrations novas `2026_11_17_*`) e o
`public/migrate-safe.php`; não leva `.env`, `vendor`, testes nem `storage`.

### Ajustes de 17/11/2026 (depois do módulo da mentoria; nada enviado ao servidor)

- **Início > "OS em andamento"** (antes "Pendências"): o selo da etapa fica verde no prazo,
  vermelho com prazo estourado e cinza sem prazo. O prazo vem da barreira aberta que vence
  primeiro (piloto ligado) ou da previsão de entrega da OS (`Services/PrazoOs.php`).
- **Origem do cliente**: nova opção "Outdoor Rodovia" (`ServiceOrder::ORIGENS`). O relatório de
  Marketing ganhou os botões "Exportar resumo (Excel)" e "Exportar OS por canal (Excel)"
  (rota `admin.marketing.export`, mesma permissão de Marketing; técnico sem acesso a valores leva 403).
- **Novo Cadastro**: "Nome / Razão Social"; telefones WhatsApp, fixo e 2º telefone, cada um com o
  nome do responsável (gravados em `persons.contact_numbers`, WhatsApp sempre primeiro); endereço
  (CEP, rua, número, complemento, bairro, cidade, estado) no atributo "Endereço" do cliente, com
  preenchimento automático pelo CEP (consulta pelo servidor: ViaCEP, BrasilAPI de reserva, cache de
  30 dias). Cliente que volta só ganha os telefones novos e o endereço que faltava
  (`Services/ClienteContatos.php`). Migration `2026_11_17_110000` garante o atributo "Endereço".
- **Código de cada cliente e de cada mecânico** (1, 2, 3... na ordem de cadastro): colunas
  `persons.codigo` e `mechanics.codigo` + tabela `codigo_sequencias` (migration `2026_11_17_120000`,
  que numera quem já existe pela ordem do id). Os novos recebem o código sozinhos, de qualquer
  tela ou importação (`Services/CodigoSequencial.php`, evento `creating`; contador travado, nunca
  repete e não reaproveita número de excluído). Aparece: no Novo Cadastro (próximo código e tamanho
  da carteira), no aviso após salvar, na tela e na impressão da OS, na lista de clientes
  (`admin/contacts/persons`, que não está no menu) e na lista, ficha e edição do mecânico
  (`admin/mechanics`, também fora do menu). O código não é editável.
- **A origem do cliente ("Por qual meio chegou à empresa?") só é perguntada no Novo Cadastro.**
  Saiu da Nova OS, da tela da OS e a rota de editar pela OS foi removida. A OS nova de um cliente
  antigo herda a primeira origem registrada para ele (`ServiceOrder::origemDoCliente`). A impressão
  da OS ainda mostra as opções com a marcada.
- **Checklist de entrada no Novo Cadastro**: km, combustível (Reserva a Cheio), chassi (grava no
  veículo, exige placa), descrição do veículo (`quotes.descricao_entrada`, migration
  `2026_11_17_130000`), itens/avarias, fotos (até 20, 10 MB) e vídeos (até 3, 100 MB; anexo
  `termo_video`). Km, combustível, itens e fotos caem nos mesmos campos do termo de entrada da OS
  (o gate Cadastro > OS já os considera; assinatura e autorização de rodagem continuam na OS).
  Evento `checklist_entrada` no histórico. Cartão "Checklist de entrada do veículo" na tela da OS
  (`partials/checklist-entrada.blade.php`). O formulário confere o limite de upload do PHP
  (`upload_max_filesize`/`post_max_size`) antes de enviar; vídeo grande exige ajustar o servidor.
- **Nova OS: "Descrição" virou "Descrição do Defeito"** (rótulo `service_order::app.form.description`,
  vale na criação, edição e tela da OS).
- **Horário na previsão de conclusão** (veículo que entra e sai no mesmo dia): campo opcional
  "Horário" ao lado da data, na Nova OS e na edição. Coluna `quotes.hora_previsao` (TIME, migration
  `2026_11_17_140000`; a data continua em `data_previsao`). Sem horário, o prazo vale até o fim do
  dia, como sempre valeu; com horário, vence naquela hora. Vale para: card "OS em andamento" do
  Início (vermelho depois do horário), prazo prometido gravado no evento `prazo_informado`
  (`prazo_prometido_em`, usado pela barreira "prazo prometido" e pelo indicador CHE-07), tela da OS,
  impressão, acompanhamento do cliente e portal. Tudo em `Services/PrevisaoOs.php`; banco sem a
  migration segue só com a data (o campo nem aparece).
- **Início de quem ganha comissão (18/11/2026)**: perfil que não é administrador e tem **% de
  comissão na Função** (ou, com o piloto ligado, não tem "ver valores") vê só a própria comissão:
  o cartão **"Minhas comissões"** (por OS faturada: cliente, serviço, data e quanto ganhou, sem
  valor da OS, desconto nem percentual; mecânico ganha pela execução, consultor pela "Venda da
  OS") e o card "OS em andamento" sem nenhum valor. Os blocos de faturamento (Faturamento de OS,
  Negócios ganhos/perdidos, receita por origem/tipo, produtos, clientes) somem da tela **e a API
  `admin.dashboard.stats` responde 403** para esse perfil. Quem não tem comissão (dono, gerente...)
  vê tudo como antes. Exceção: permissão nova **"Ver o faturamento geral no Início"**
  (`dashboard.faturamento_geral`, no cadastro de Funções) para quem tem comissão mas precisa ver
  tudo. Código: `Services/PainelComissao.php`, `DashboardController`, `Helpers/Dashboard.php`,
  `dashboard/index.blade.php` e `dashboard/index/minhas-comissoes.blade.php` (pacote Admin).
  **Limite conhecido:** só o Início foi tratado. Lista e tela da OS ainda mostram o total conforme
  a permissão "Ver valores financeiros", que só vale com o piloto ligado. A permissão
  `ver_valores_financeiros` do técnico (spec) esconde comissão; aqui a comissão própria é exceção.
- Testes novos: `PrazoDashboardTest`, `MarketingOrigemTest`, `CadastroContatosTest`, `CodigosTest`,
  `ChecklistCadastroTest`, `PrevisaoHorarioTest`, `PainelComissaoTest`. O `HelpTest` (antigo, esperava textos em inglês do Krayin) foi atualizado
  para a página de ajuda em português: antes ele só falhava, e a falha com a página inteira
  travava a suíte (a impressão do erro ficava lenta demais com ~400 KB de HTML).
  Suíte completa: 289 passam, nenhuma falha. O zip da mentoria já leva tudo isso
  (237 arquivos, ~482 KB, refeito em 18/11/2026 e conferido arquivo a arquivo contra o código; mais arquivos dos pacotes Admin, Mechanic, Portal e Vehicle (frota): Início e seu
  controller, guia, helper do dashboard, lista de clientes, telas do mecânico e a OS no portal do
  cliente). O zip foi extraído e migrado no servidor em 18/11/2026; a versão com o Início de
  comissão é uma segunda extração (sem migration nova; o `migrate-safe.php` volta junto no zip e
  deve ser apagado de novo). **Depois de subir o zip, rodar o `migrate-safe.php`** (cria as colunas
  e numera os já cadastrados); enquanto a migration não rodar, as telas funcionam sem o código e sem o horário.

## Roteiro de Teste e Ajustes do patrão (18/11/2026)

O patrão mandou o PDF "Roteiro de Teste e Ajustes do Software" (Parte A = ligar/configurar, A1-A6;
Parte B = construir/corrigir, B1-B9; roteiro de 72 linhas). As especificações detalhadas de cada
bloco estão em `d:\volmaster crm\ajustes-roteiro-18-11\especificacoes\` (não vão no zip).
Zip desta rodada: `deploy-staging\volmaster-atualizacao-2026-11-18-roteiro.zip` (288 arquivos,
599 KB, conferido arquivo por arquivo, sem `.env`). Suíte: 365 testes passando.

**Para subir (nada foi enviado ao servidor por nós):** extrair o zip na pasta do projeto,
abrir `/migrate-safe.php?key=...` (agora migra a instalação principal E o banco de cada oficina;
`&simular=1` só lista o que está pendente; `&escopo=principal` é o comportamento antigo), apagar
`public/migrate-safe.php` e o zip, e esvaziar `storage/framework/views` (Ctrl+F5 no navegador).
Antes de ligar o piloto numa oficina: Ficha (valor da hora, impostos, início da mentoria) e um
colaborador Gerente ou Proprietário (a tela agora recusa ligar sem isso).

**Feito e testado (por item do PDF):**
- A1 / B7 (Visão do mentor): `Tenant/Services/TenantMigrator` + `php artisan tenants:migrate`; a causa
  do "Não foi possível ler esta oficina" era que o migrate-safe só migrava o banco principal. Oficina
  nova refaz CPF/CNPJ, endereço e o perfil do administrador (`OficinaPadrao`). Visão do mentor mostra traços.
- B7: páginas de erro em português com HTTP verdadeiro (401/403/404/405/413/419/429/500/503) e contato
  em `config('support.contact_email')` (env `VOLMASTER_SUPPORT_EMAIL` / `VOLMASTER_SUPPORT_WHATSAPP`);
  título da OS com a etapa atual (`ServiceOrder::tituloAtual()`); datas `dd/mm/aaaa hh:mm` em
  `Core::formatDate`; `lang/pt_BR` do Laravel (validação, login, senha).
- A5/A6: `PilotoRequisitos` impede ligar o piloto sem valor da hora, impostos, início da mentoria e
  gerente/proprietário; "Modo de teste" (prazos ÷ 10, `Services/ModoTeste`) com selo vermelho em todas as telas.
- A2/A3: botão "Testar notificação" em Minha Conta; folha do iPhone ("Entendi"); e-mail de contato do
  VAPID normalizado (`mailto:`); relatório de SMS rotulado (Simulado, limite mensal); celular com DDD 55;
  o SMS comum espera o horário de silêncio. Corrigido: tela de configuração dava erro 500 com aceite de SMS.
- A4/B8: `Config/perfis.php` (matriz perfil → chaves de acesso), tela Configurações > Perfis da mentoria
  (criar as funções, prévia e migração dos usuários com "Desfazer", `Programador` → Suporte Volmaster),
  função combinada para acúmulo de cargos (Chefe + Técnico continua sem valores), sincronização
  Colaborador → perfil do usuário, Mentor somente leitura (`PerfilSomenteLeitura`), cada etapa da OS
  conferida por perfil (`ServiceOrderController::stageIndex`), Impact e Recomendações saíram do menu
  (ficam em Ajuda e recursos).
- B1: cor de crédito no CLIENTE (`cliente_credito_registros`, só inserção), sugestão automática pelos
  boletos (vencido há mais de 15 dias = Vermelho; atraso em 90 dias = Amarelo; limites `credito_dias_*`),
  cartão na página do cliente, prazo para cliente Vermelho vai direto ao Proprietário com 24 h, chaves
  `credito_cliente` e `credito_cliente.registrar`; nunca vai ao link do cliente nem à OS impressa.
- B5: serviço entra com o valor da hora da Ficha e as horas do tempo padrão do catálogo
  (`services.tempo_padrao_horas`), aviso acima de 3 vezes a Ficha (`valor_hora_limite_multiplo`) e alçada
  `hora_abaixo_minimo` abaixo do preço mínimo da DRE. A OS #11 do servidor precisa ser corrigida/cancelada
  à mão pela tela.

- B2: Cadastro só libera a OS com CPF/CNPJ de dígitos válidos (Support/Documento), placa, km, combustível,
  itens do veículo, 4 fotos (	ermo_fotos_minimas) e termo aceito; aceite pelo link (/termo/{token}, enviado
  pelo botão "Gerar link do termo" na OS, com assinatura na tela, data, hora, IP e aparelho em
  service_order_termos; só o hash do token fica no banco; vale 7 dias). A resposta sim/não da rodagem
  é registrada e só trava o teste de rodagem.

**NÃO feito nesta rodada (continuam abertos):** B3 nota fiscal e saída do caminhão (já existe em boa parte no
pacote Fiscal), B4 módulo de Peças (Painel de Peças, compras, inventário), B6 OS de retorno/chamado
externo/diária, B9 modelo de remuneração variável. Limites conhecidos: o Gerente ficou SEM a Ficha (o PDF
pede "só leitura"); acesso negado continua HTTP 401 (o PDF fala 403); a barreira de crédito (FIN-01) só abre
em OS com veículo; criação de oficina nova ainda é pelo /signup.

## Pendências conhecidas

- NF-e de peças com Lucro Presumido: impostos estão fixos no padrão do Simples
  (ICMS 102, PIS/COFINS 07) em `packages/Webkul/Fiscal/src/Http/Controllers/FiscalController.php`
  — precisa ajustar com o contador antes de emitir NF-e de peças.
- Dados Fiscais da Volmaster precisam ser refeitos com o CNPJ 34.307.718/0001-47
  (e IE/IM dessa empresa) em `api-keys-manager.php`.
- Token da Focus da Volmaster ainda não cadastrado em Chaves de API
  (usar o de homologação primeiro).
- E-mail (Titan) com erro de autenticação; ideia do usuário: migrar para Google Workspace.
- Para as comissões: preencher "Comissão (%)" em cada Função e vincular cada
  mecânico ao usuário dele.
- Ideia futura: importar histórico de OS antigas.

## Para reinstalar o ambiente de desenvolvimento

- PHP 8.3, Composer, MariaDB, Node, Chromium (o usuário usava Scoop: `php83`,
  `composer`, `mariadb`, `chromium`), Git Bash.
- Banco local de testes: `krayin_crm` (usuário root, sem senha) — só teste, pode
  recriar com `php artisan migrate`. O `.env` local NÃO tem segredos de produção.
