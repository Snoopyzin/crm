# Apoio B8 — rota → chave ACL → perfis que podem acessar

> Gerado a partir do código real (`php artisan route:list` equivalente: 554 rotas, 486 protegidas pelo login do admin) cruzado com a matriz da especificação `spec-F05-menu-permissoes.md`. É o material de conferência da revisão. Leitura apenas: nada aqui altera o projeto.

## 0. Como ler

- **Perfis (colunas):** Prop = Proprietário, Ger = Gerente, Che = Chefe de oficina, Con = Consultor técnico, Téc = Técnico, Peç = Peças, Fin = Financeiro, Zel = Auxiliar de copa e zeladoria, Men = Mentor (somente leitura), Sup = Suporte Volmaster (interno). ✔ = a rota abre; — = acesso negado (403).
- **Chave ACL (depois da F05):** a chave de `Config/acl.php` que o Bouncer do Krayin confere (a de hoje, exceto as listas por etapa, que passam a pertencer a `service_orders`); `NOVA:` = rota que hoje NÃO tem chave nenhuma (qualquer usuário logado abre) e passa a exigir a chave indicada (qualquer uma das citadas); `+ chave` = exigência extra da F05; `+ valores` = exige também `ver_valores_financeiros` (`ValoresFinanceiros::can()`).
- **Quando vale:** todas as exigências novas (403, rotas sem chave, chave por etapa, chaves extras, valores, somente leitura do Mentor) só valem com o **piloto ligado** na oficina. Com o piloto desligado, vale o Bouncer de sempre (resposta 401) — comportamento de hoje. Proprietário e Suporte (permissão total) passam em tudo nos dois casos.
- Perfis com acúmulo recebem a **união** das chaves (regra do A4: sem nenhuma chave de valores nos perfis dele, os valores continuam ocultos).

## 1. Matriz perfil × chaves ACL

#### `proprietario` — Proprietário (permissão total: administrador da oficina)

Função com `permission_type = all`: passa em tudo (inclusive as chaves novas). Nada a listar.

#### `gerente` — Gerente

`dashboard`, `dashboard.faturamento_geral`, `service_orders`, `service_orders.view`, `service_orders.create`, `service_orders.edit`, `service_orders.auditar`, `service_orders.forcar_avanco`, `ver_valores_financeiros`, `cadastro`, `ordem_servico`, `diagnostico`, `orcamento`, `negociacao`, `em_execucao`, `veiculo_teste`, `faturamento`, `pos_venda`, `comissoes`, `marketing`, `painel_dono`, `indicadores_mentoria`, `alcadas`, `registros_mentoria`, `financeiro_rotina`, `financeiro_dre`, `rotina_patio`, `rotina_painel`, `rotina_agenda`, `bonus_mentoria`, `bonus_gerente`, `bonus_meu`, `colaboradores`, `estoque`, `parts`, `parts.view`, `parts.create`, `parts.edit`, `parts.movements`, `servicos_catalogo`, `services`, `services.create`, `services.edit`, `grupos`, `part_categories`, `part_categories.create`, `part_categories.edit`, `contacts`, `contacts.persons`, `contacts.persons.view`, `contacts.persons.create`, `contacts.persons.edit`, `vehicles`, `vehicles.view`, `vehicles.create`, `vehicles.edit`, `mechanics`, `mechanics.view`, `mechanics.create`, `mechanics.edit`, `work_bays`, `work_bays.view`, `work_bays.create`, `work_bays.edit`, `fiscal_documents`, `fiscal_documents.view`, `fiscal_documents.emit`, `service_requests`, `service_requests.view`, `service_requests.convert`, `service_requests.invite`, `settings`, `settings.import`, `settings.import.planilha`, `settings.workshop`, `settings.workshop.geral`, `settings.historico`, `settings.historico.importar`, `settings.ficha`, `settings.ficha.edit`, `settings.alcadas`, `settings.alcadas.matriz`, `settings.metas`, `settings.metas.tabela`, `settings.inventory`, `settings.inventory.warehouse`, `settings.inventory.warehouse.create`, `settings.inventory.warehouse.edit`, `settings.configuration`, `settings.configuration.general`, `help` (91 chaves)

#### `chefe` — Chefe de oficina

`service_orders`, `service_orders.view`, `service_orders.edit`, `service_orders.auditar`, `rotina_patio`, `diagnostico`, `orcamento`, `negociacao`, `em_execucao`, `veiculo_teste`, `rotina_painel`, `registros_mentoria`, `bonus_meu` (13 chaves)

#### `consultor` — Consultor técnico

`service_orders`, `service_orders.view`, `service_orders.create`, `service_orders.edit`, `ver_valores_financeiros`, `rotina_agenda`, `cadastro`, `ordem_servico`, `diagnostico`, `orcamento`, `negociacao`, `em_execucao`, `veiculo_teste`, `faturamento`, `pos_venda`, `marketing`, `rotina_painel`, `bonus_meu`, `contacts`, `contacts.persons`, `contacts.persons.view`, `contacts.persons.create`, `contacts.persons.edit`, `vehicles`, `vehicles.view`, `vehicles.create`, `vehicles.edit`, `service_requests`, `service_requests.view`, `service_requests.convert` (30 chaves)

#### `tecnico` — Técnico

`tecnico`, `bonus_meu` (2 chaves)

#### `pecas` — Peças

`service_orders`, `service_orders.view`, `service_orders.edit`, `ver_valores_financeiros`, `estoque`, `parts`, `parts.view`, `parts.create`, `parts.edit`, `parts.movements`, `grupos`, `part_categories`, `part_categories.create`, `part_categories.edit`, `rotina_painel`, `bonus_meu` (16 chaves)

#### `financeiro` — Financeiro

`service_orders`, `service_orders.view`, `service_orders.edit`, `ver_valores_financeiros`, `financeiro_rotina`, `financeiro_dre`, `faturamento`, `fiscal_documents`, `fiscal_documents.view`, `fiscal_documents.emit`, `rotina_painel`, `bonus_meu` (12 chaves)

#### `zeladoria` — Auxiliar de copa e zeladoria

`registros_mentoria`, `bonus_meu` (2 chaves)

#### `mentor` — Mentor (SOMENTE LEITURA)

`mentor_mentoria`, `painel_dono` (2 chaves)

#### `suporte` — Suporte Volmaster (permissão total: administrador da oficina)

Função com `permission_type = all`: passa em tudo (inclusive as chaves novas). Nada a listar.

Chaves de pacotes ainda por vir (outras frentes) entram nestas listas pela própria frente: Painel de Peças e estoque/pedidos (F10, F11), cor de crédito (F06), termo (F07), nota e saída (F09), retorno/chamado/diária (F12, F13), remuneração (F14).

## 2. Menu lateral: item × perfil

Ordem final do menu (campo `sort`): 1 Início, 10 Painel do Dono, 20 Minhas tarefas, 30 Painel de Peças, 40 Minha rotina, 50 Visão do mentor, 60 Agenda, 70 Quadro de pátio, 80 Cadastro, 90–160 as 8 etapas, 170 Estoque, 180 Grupos, 190 Serviços (catálogo), 200 Financeiro, 210 DRE e preços, 220 Comissões, 230 Marketing, 240 Fila de alçadas, 250 Indicadores, 260 Registros, 270 Bônus, 280 Meu bônus, 290 Colaboradores, 900 Configurações, 950 Ajuda e recursos. Impact e Recomendações saem do menu e aparecem em **Ajuda e recursos**.

| Item do menu (chave) | Prop | Ger | Che | Con | Téc | Peç | Fin | Zel | Men | Sup |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| Início (`dashboard`) | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| Painel do Dono (`painel_dono`) | ✔ | ✔ | — | — | — | — | — | — | ✔ | ✔ |
| Minhas tarefas (`tecnico`) | ✔ | — | — | — | ✔ | — | — | — | — | ✔ |
| Painel de Peças (F10) (`painel_pecas`) | ✔ (F10) | ✔ (F10) | — | — | — | ✔ (F10) | — | — | — | ✔ (F10) |
| Minha rotina (`rotina_painel`) | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| Visão do mentor (`mentor_mentoria`) | ✔ | — | — | — | — | — | — | — | ✔ | ✔ |
| Agenda (`rotina_agenda`) | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| Quadro de pátio (`rotina_patio`) | ✔ | ✔ | ✔ | — | — | — | — | — | — | ✔ |
| Cadastro (`cadastro`) | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| Ordem de Serviço (`ordem_servico`) | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| Diagnóstico (`diagnostico`) | ✔ | ✔ | ✔ | ✔ | — | — | — | — | — | ✔ |
| Orçamento (`orcamento`) | ✔ | ✔ | ✔ | ✔ | — | — | — | — | — | ✔ |
| Negociação (`negociacao`) | ✔ | ✔ | ✔ | ✔ | — | — | — | — | — | ✔ |
| Serviços em Execução (`em_execucao`) | ✔ | ✔ | ✔ | ✔ | — | — | — | — | — | ✔ |
| Veículo em Teste (`veiculo_teste`) | ✔ | ✔ | ✔ | ✔ | — | — | — | — | — | ✔ |
| Faturamento (`faturamento`) | ✔ | ✔ | — | ✔ | — | — | ✔ | — | — | ✔ |
| Pós-Venda (`pos_venda`) | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| Estoque (`estoque`) | ✔ | ✔ | — | — | — | ✔ | — | — | — | ✔ |
| Grupos (`grupos`) | ✔ | ✔ | — | — | — | ✔ | — | — | — | ✔ |
| Serviços (catálogo) (`servicos_catalogo`) | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| Financeiro (`financeiro_rotina`) | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| DRE e preços (`financeiro_dre`) | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| Comissões (`comissoes`) | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| Marketing (`marketing`) | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| Fila de alçadas (`alcadas`) | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| Indicadores (`indicadores_mentoria`) | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| Registros (`registros_mentoria`) | ✔ | ✔ | ✔ | — | — | — | — | ✔ | — | ✔ |
| Bônus (gestão) (`bonus_mentoria`) | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| Meu bônus (`bonus_meu`) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | — | ✔ |
| Colaboradores (`colaboradores`) | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| Configurações (`settings`) | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| Ajuda e recursos (`help`) | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| **Total de itens de topo** | **31** | **29** | **9** | **13** | **2** | **4** | **5** | **2** | **2** | **31** |

Observações: o Proprietário e o Suporte (permissão total) veem todos os itens de topo; o item **Início** do Gerente leva à "Minha rotina" com o piloto ligado. O **Mentor** tem só "Painel do Dono" e "Visão do mentor"; **Técnico** e **Zeladoria**, só dois itens cada.

## 3. Rotas (uma linha por rota protegida pelo login do admin)

### 3.1 Rotas da oficina (uma linha por rota)

| Rota | Método | URL | Chave ACL (depois da F05) | Prop | Ger | Che | Con | Téc | Peç | Fin | Zel | Men | Sup |
| --- | --- | --- | --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| `admin.impact.open` | GET | `/admin/impact` | `impact` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.fiscal_documents.index` | GET | `/admin/fiscal-documents` | `fiscal_documents` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.fiscal_documents.view` | GET | `/admin/fiscal-documents/{id}` | `fiscal_documents.view` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.fiscal_documents.create` | GET | `/admin/service-orders/{serviceOrderId}/fiscal/emit-nfse` | `fiscal_documents.emit` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.fiscal_documents.emit-nfe` | POST | `/admin/service-orders/{serviceOrderId}/fiscal/emit-nfse` | `fiscal_documents.emit` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.fiscal_documents.create-nfe-produto` | GET | `/admin/service-orders/{serviceOrderId}/fiscal/emit-nfe-produto` | `fiscal_documents.emit` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.fiscal_documents.emit-nfe-produto` | POST | `/admin/service-orders/{serviceOrderId}/fiscal/emit-nfe-produto` | `fiscal_documents.emit` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.settings.fiscal_settings.edit` | GET | `/admin/settings/fiscal-settings` | `settings.other_settings.fiscal_settings` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.settings.fiscal_settings.update` | PUT | `/admin/settings/fiscal-settings` | `settings.other_settings.fiscal_settings` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.mechanics.index` | GET | `/admin/mechanics` | `mechanics` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mechanics.create` | GET | `/admin/mechanics/create` | `mechanics.create` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mechanics.store` | POST | `/admin/mechanics` | `mechanics.create` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mechanics.view` | GET | `/admin/mechanics/{id}` | `mechanics.view` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mechanics.edit` | GET | `/admin/mechanics/{id}/edit` | `mechanics.edit` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mechanics.update` | PUT | `/admin/mechanics/{id}` | `mechanics.edit` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mechanics.destroy` | DELETE | `/admin/mechanics/{id}` | `mechanics.delete` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.parts.index` | GET | `/admin/parts` | `parts` | ✔ | ✔ | — | — | — | ✔ | — | — | — | ✔ |
| `admin.parts.create` | GET | `/admin/parts/create` | `parts.create` | ✔ | ✔ | — | — | — | ✔ | — | — | — | ✔ |
| `admin.parts.store` | POST | `/admin/parts` | `parts.create` | ✔ | ✔ | — | — | — | ✔ | — | — | — | ✔ |
| `admin.parts.view` | GET | `/admin/parts/{id}` | `parts.view` | ✔ | ✔ | — | — | — | ✔ | — | — | — | ✔ |
| `admin.parts.edit` | GET | `/admin/parts/{id}/edit` | `parts.edit` | ✔ | ✔ | — | — | — | ✔ | — | — | — | ✔ |
| `admin.parts.update` | PUT | `/admin/parts/{id}` | `parts.edit` | ✔ | ✔ | — | — | — | ✔ | — | — | — | ✔ |
| `admin.parts.destroy` | DELETE | `/admin/parts/{id}` | `parts.delete` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.parts.movements.store` | POST | `/admin/parts/{id}/movements` | `parts.movements` | ✔ | ✔ | — | — | — | ✔ | — | — | — | ✔ |
| `admin.services.index` | GET | `/admin/services-catalog` | `services` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.services.create` | GET | `/admin/services-catalog/create` | `services.create` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.services.store` | POST | `/admin/services-catalog` | `services.create` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.services.edit` | GET | `/admin/services-catalog/{id}/edit` | `services.edit` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.services.update` | PUT | `/admin/services-catalog/{id}` | `services.edit` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.services.destroy` | DELETE | `/admin/services-catalog/{id}` | `services.delete` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.part-categories.index` | GET | `/admin/part-categories` | `part_categories` | ✔ | ✔ | — | — | — | ✔ | — | — | — | ✔ |
| `admin.part-categories.store` | POST | `/admin/part-categories` | `part_categories.create` | ✔ | ✔ | — | — | — | ✔ | — | — | — | ✔ |
| `admin.part-categories.update` | PUT | `/admin/part-categories/{id}` | `part_categories.edit` | ✔ | ✔ | — | — | — | ✔ | — | — | — | ✔ |
| `admin.part-categories.destroy` | DELETE | `/admin/part-categories/{id}` | `part_categories.delete` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.portal.invite` | POST | `/admin/portal/persons/{personId}/invite` | `service_requests.invite` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.service_requests.index` | GET | `/admin/service-requests` | `service_requests` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.service_requests.view` | GET | `/admin/service-requests/{id}` | `service_requests.view` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.service_requests.convert` | POST | `/admin/service-requests/{id}/convert` | `service_requests.convert` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.service_requests.link_order` | POST | `/admin/service-requests/{id}/link-order` | `service_requests.convert` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.service_requests.reject` | POST | `/admin/service-requests/{id}/reject` | `service_requests.convert` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.remote_services.types.index` | GET | `/admin/remote-services/types` | `remote_services.types` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.types.create` | GET | `/admin/remote-services/types/create` | `remote_services.types` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.types.store` | POST | `/admin/remote-services/types/create` | `remote_services.types` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.types.edit` | GET | `/admin/remote-services/types/edit/{id}` | `remote_services.types` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.types.update` | PUT | `/admin/remote-services/types/edit/{id}` | `remote_services.types` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.types.destroy` | DELETE | `/admin/remote-services/types/{id}` | `remote_services.types` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.index` | GET | `/admin/remote-services` | `remote_services` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.create` | GET | `/admin/remote-services/create` | `remote_services.create` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.store` | POST | `/admin/remote-services` | `remote_services.create` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.vehicles-by-person` | GET | `/admin/remote-services/vehicles-by-person/{personId}` | NOVA: `remote_services.create` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.search-persons` | GET | `/admin/remote-services/search-persons` | NOVA: `remote_services.create` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.lookup-by-teamviewer` | GET | `/admin/remote-services/lookup-by-teamviewer/{teamviewerId}` | NOVA: `remote_services.create` ou `remote_services.finish` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.view` | GET | `/admin/remote-services/{id}` | `remote_services.view` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.finish` | POST | `/admin/remote-services/{id}/finish` | `remote_services.finish` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.start` | PUT | `/admin/remote-services/{id}/start` | NOVA: `remote_services.finish` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.approve` | PUT | `/admin/remote-services/{id}/approve` | `remote_services.approve` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.update-teamviewer` | PUT | `/admin/remote-services/{id}/teamviewer` | NOVA: `remote_services.create` ou `remote_services.finish` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.remote_services.destroy` | DELETE | `/admin/remote-services/{id}` | `remote_services.delete` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.service_orders.index` | GET | `/admin/service-orders` | `service_orders` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.create` | GET | `/admin/service-orders/create` | `service_orders.create` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.store` | POST | `/admin/service-orders` | `service_orders.create` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.stage.index` (`Cadastro`) | GET | `/admin/service-orders/stage/{etapa?}` | `service_orders` + `cadastro` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.stage.index` (`Ordem de Serviço`) | GET | `/admin/service-orders/stage/{etapa?}` | `service_orders` + `ordem_servico` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.stage.index` (`Diagnóstico`) | GET | `/admin/service-orders/stage/{etapa?}` | `service_orders` + `diagnostico` | ✔ | ✔ | ✔ | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.stage.index` (`Orçamento`) | GET | `/admin/service-orders/stage/{etapa?}` | `service_orders` + `orcamento` | ✔ | ✔ | ✔ | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.stage.index` (`Negociação`) | GET | `/admin/service-orders/stage/{etapa?}` | `service_orders` + `negociacao` | ✔ | ✔ | ✔ | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.stage.index` (`Em Execução`) | GET | `/admin/service-orders/stage/{etapa?}` | `service_orders` + `em_execucao` | ✔ | ✔ | ✔ | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.stage.index` (`Veículo em Teste`) | GET | `/admin/service-orders/stage/{etapa?}` | `service_orders` + `veiculo_teste` | ✔ | ✔ | ✔ | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.stage.index` (`Faturamento`) | GET | `/admin/service-orders/stage/{etapa?}` | `service_orders` + `faturamento` | ✔ | ✔ | — | ✔ | — | — | ✔ | — | — | ✔ |
| `admin.service_orders.stage.index` (`Pós-Venda`) | GET | `/admin/service-orders/stage/{etapa?}` | `service_orders` + `pos_venda` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.vehicles-by-person` | GET | `/admin/service-orders/vehicles-by-person/{personId}` | NOVA: `service_orders.create` ou `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.view` | GET | `/admin/service-orders/{id}` | `service_orders.view` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.edit` | GET | `/admin/service-orders/{id}/edit` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.update` | PUT | `/admin/service-orders/{id}` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.status.update` | PUT | `/admin/service-orders/{id}/status` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.stage.update` | PUT | `/admin/service-orders/{id}/stage` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.destroy` | DELETE | `/admin/service-orders/{id}` | `service_orders.delete` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.service_orders.items.store` | POST | `/admin/service-orders/{id}/items` | NOVA: `service_orders.edit` + valores | ✔ | ✔ | — | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.items.destroy` | DELETE | `/admin/service-orders/{id}/items/{itemId}` | NOVA: `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.print` | GET | `/admin/service-orders/{id}/print` | NOVA: `service_orders.view` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.cadastro.create` | GET | `/admin/service-orders-cadastro` | `cadastro` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.cadastro.store` | POST | `/admin/service-orders-cadastro` | `service_orders.create` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.cadastro.cep` | GET | `/admin/service-orders-cadastro/cep/{cep}` | `service_orders.create` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.service_orders.diagnostico.update` | PUT | `/admin/service-orders/{id}/diagnostico` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.diagnostico.whatsapp` | POST | `/admin/service-orders/{id}/diagnostico/whatsapp` | NOVA: `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.negociacao.update` | PUT | `/admin/service-orders/{id}/negociacao` | `service_orders.edit` + valores | ✔ | ✔ | — | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.execucao.tarefa.update` | PUT | `/admin/service-orders/{id}/execucao/tarefa` | NOVA: `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.items.alerta.update` | PUT | `/admin/service-orders/{id}/items/{itemId}/alerta` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.checklist.store` | POST | `/admin/service-orders/{id}/checklist` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.checklist.toggle` | PUT | `/admin/service-orders/{id}/checklist/{itemId}/toggle` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.checklist.destroy` | DELETE | `/admin/service-orders/{id}/checklist/{itemId}` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.orcamento.mail` | POST | `/admin/service-orders/{id}/orcamento/mail` | `service_orders.edit` + valores | ✔ | ✔ | — | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.orcamento.whatsapp` | POST | `/admin/service-orders/{id}/orcamento/whatsapp` | `service_orders.edit` + valores | ✔ | ✔ | — | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.timer.start` | POST | `/admin/service-orders/{id}/timer/start` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.timer.pause` | POST | `/admin/service-orders/{id}/timer/pause` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.timer.resume` | POST | `/admin/service-orders/{id}/timer/resume` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.timer.finish` | POST | `/admin/service-orders/{id}/timer/finish` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.teste.start` | POST | `/admin/service-orders/{id}/teste/start` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.teste.finish` | POST | `/admin/service-orders/{id}/teste/finish` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.boletos.store` | POST | `/admin/service-orders/{id}/boletos` | `service_orders.edit` + valores | ✔ | ✔ | — | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.boletos.asaas` | POST | `/admin/service-orders/{id}/boletos/asaas` | `service_orders.edit` + valores | ✔ | ✔ | — | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.boletos.update` | PUT | `/admin/service-orders/{id}/boletos/{boletoId}` | `service_orders.edit` + valores | ✔ | ✔ | — | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.pos_venda.send` | POST | `/admin/service-orders/{id}/pos-venda/send` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.termo` | POST | `/admin/service-orders/{id}/workflow/termo` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.cor_credito` | POST | `/admin/service-orders/{id}/workflow/cor-credito` | `service_orders.edit` + valores | ✔ | ✔ | — | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.designate` | POST | `/admin/service-orders/{id}/workflow/designar-tecnico` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.laudo` | POST | `/admin/service-orders/{id}/workflow/laudo` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.diagnostico.start` | POST | `/admin/service-orders/{id}/workflow/diagnostico/iniciar` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.diagnostico.finish` | POST | `/admin/service-orders/{id}/workflow/diagnostico/finalizar` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.diagnostico.audit` | POST | `/admin/service-orders/{id}/workflow/diagnostico/visto` | `service_orders.auditar` | ✔ | ✔ | ✔ | — | — | — | — | — | — | ✔ |
| `admin.service_orders.workflow.evidence` | POST | `/admin/service-orders/{id}/workflow/evidencia` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.parts` | POST | `/admin/service-orders/{id}/workflow/relacao-pecas` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.budget_sent` | POST | `/admin/service-orders/{id}/workflow/orcamento-enviado` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.approval` | POST | `/admin/service-orders/{id}/workflow/aprovacao` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.nf` | POST | `/admin/service-orders/{id}/workflow/nf` | `service_orders.edit` + valores | ✔ | ✔ | — | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.payment` | POST | `/admin/service-orders/{id}/workflow/pagamento` | `service_orders.edit` + valores | ✔ | ✔ | — | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.agreement` | POST | `/admin/service-orders/{id}/workflow/acordo` | `service_orders.edit` + valores | ✔ | ✔ | — | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.action` | POST | `/admin/service-orders/{id}/workflow/acao/{acao}` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.service_orders.workflow.operation.confirm_delay` | POST | `/admin/service-orders/{id}/workflow/operacoes/{itemId}/confirmar-atraso` | `service_orders.auditar` | ✔ | ✔ | ✔ | — | — | — | — | — | — | ✔ |
| `admin.service_orders.workflow.operation` | POST | `/admin/service-orders/{id}/workflow/operacoes/{itemId}/{acao}` | `service_orders.edit` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.workshop.alerts.index` | GET | `/admin/workshop/alertas` | (livre: qualquer usuário logado) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `admin.workshop.alerts.summary` | GET | `/admin/workshop/alertas/resumo` | (livre: qualquer usuário logado) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `admin.workshop.alerts.read_all` | POST | `/admin/workshop/alertas/lidos` | (livre: qualquer usuário logado) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `admin.workshop.alerts.ack` | POST | `/admin/workshop/alertas/{alertId}/ciente` | (livre: qualquer usuário logado) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `admin.workshop.push.subscribe` | POST | `/admin/workshop/push/inscrever` | (livre: qualquer usuário logado) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `admin.workshop.push.unsubscribe` | POST | `/admin/workshop/push/cancelar` | (livre: qualquer usuário logado) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `admin.workshop.settings.edit` | GET | `/admin/settings/workshop` | `settings.workshop` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.workshop.settings.general` | PUT | `/admin/settings/workshop/geral` | `settings.workshop` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.workshop.settings.rule` | PUT | `/admin/settings/workshop/barreiras/{ruleId}` | `settings.workshop` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.workshop.settings.roles` | PUT | `/admin/settings/workshop/papeis` | `settings.workshop` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.workshop.settings.complaints` | PUT | `/admin/settings/workshop/queixas` | `settings.workshop` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.workshop.settings.vapid` | POST | `/admin/settings/workshop/vapid` | `settings.workshop` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.workshop.settings.restore_rules` | POST | `/admin/settings/workshop/restaurar-barreiras` | `settings.workshop` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.workshop.settings.sms_report` | GET | `/admin/settings/workshop/sms` | `settings.workshop` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.painel` | GET | `/admin/mentoria/painel` | `painel_dono` | ✔ | ✔ | — | — | — | — | — | — | ✔ | ✔ |
| `admin.mentoria.painel.celular` | GET | `/admin/mentoria/painel-celular` | `painel_dono` | ✔ | ✔ | — | — | — | — | — | — | ✔ | ✔ |
| `admin.mentoria.painel.dados` | GET | `/admin/mentoria/painel.json` | `painel_dono` | ✔ | ✔ | — | — | — | — | — | — | ✔ | ✔ |
| `admin.mentoria.painel.pdf` | GET | `/admin/mentoria/painel.pdf` | `painel_dono` | ✔ | ✔ | — | — | — | — | — | — | ✔ | ✔ |
| `admin.mentoria.indicadores` | GET | `/admin/mentoria/indicadores` | `indicadores_mentoria` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.indicador` | GET | `/admin/mentoria/indicadores/{codigo}` | `indicadores_mentoria` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.registros` | GET | `/admin/mentoria/registros` | `registros_mentoria` | ✔ | ✔ | ✔ | — | — | — | — | ✔ | — | ✔ |
| `admin.mentoria.registros.store` | POST | `/admin/mentoria/registros` | `registros_mentoria` | ✔ | ✔ | ✔ | — | — | — | — | ✔ | — | ✔ |
| `admin.mentoria.registros.correct` | POST | `/admin/mentoria/registros/{id}/corrigir` | `registros_mentoria` | ✔ | ✔ | ✔ | — | — | — | — | ✔ | — | ✔ |
| `admin.mentoria.alcadas` | GET | `/admin/mentoria/alcadas` | `alcadas` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.alcadas.dados` | GET | `/admin/mentoria/alcadas.json` | `alcadas` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.alcadas.solicitar` | POST | `/admin/mentoria/alcadas/solicitar` | `alcadas` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.alcadas.decidir` | POST | `/admin/mentoria/alcadas/{id}/decidir` | `alcadas` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.alcadas.matriz` | GET | `/admin/mentoria/alcadas/matriz` | `settings.alcadas` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.alcadas.matriz.update` | PUT | `/admin/mentoria/alcadas/matriz` | `settings.alcadas` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus` | GET | `/admin/mentoria/bonus` | `bonus_mentoria` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus.exportar` | GET | `/admin/mentoria/bonus/exportar` | `bonus_dono` + valores | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus.meu` | GET | `/admin/mentoria/bonus/meu` | `bonus_meu` | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | — | ✔ |
| `admin.mentoria.bonus.travas` | GET | `/admin/mentoria/bonus/travas` | `bonus_mentoria` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus.travas.store` | POST | `/admin/mentoria/bonus/travas` | `bonus_gerente` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus.travas.decidir` | POST | `/admin/mentoria/bonus/travas/{id}/decidir` | `bonus_gerente` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus.calcular` | POST | `/admin/mentoria/bonus/calcular` | `bonus_gerente` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus.painel` | POST | `/admin/mentoria/bonus/consolidar-painel` | `bonus_gerente` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus.aprovar_todos` | POST | `/admin/mentoria/bonus/aprovar-todos` | `bonus_dono` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus.detalhe` | GET | `/admin/mentoria/bonus/{id}/detalhe` | `bonus_mentoria` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus.revisar` | POST | `/admin/mentoria/bonus/{id}/revisar` | `bonus_gerente` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus.aprovar` | POST | `/admin/mentoria/bonus/{id}/aprovar` | `bonus_dono` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus.estornar` | POST | `/admin/mentoria/bonus/{id}/estornar` | `bonus_dono` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.bonus.ciencia` | POST | `/admin/mentoria/bonus/{id}/ciencia` | `bonus_meu` | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | — | ✔ |
| `admin.mentoria.metas` | GET | `/admin/mentoria/metas` | `settings.metas` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.mentoria.metas.update` | PUT | `/admin/mentoria/metas` | `settings.metas` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.financeiro.lancamentos` | GET | `/admin/financeiro/lancamentos` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.lancamentos.store` | POST | `/admin/financeiro/lancamentos` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.classificar` | POST | `/admin/financeiro/lancamentos/{id}/classificar` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.pagar` | POST | `/admin/financeiro/lancamentos/{id}/pagar` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.contas` | GET | `/admin/financeiro/contas` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.subconta` | POST | `/admin/financeiro/contas/subconta` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.caixa` | GET | `/admin/financeiro/caixa` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.caixa.fechar` | POST | `/admin/financeiro/caixa/fechar` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.conciliar` | POST | `/admin/financeiro/caixa/conciliar` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.cobranca` | POST | `/admin/financeiro/cobranca/contato` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.relatorios` | GET | `/admin/financeiro/relatorios` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.relatorios.enviar` | POST | `/admin/financeiro/relatorios/enviar` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.relatorios.gerente` | POST | `/admin/financeiro/relatorios/gerente` | `financeiro_rotina` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.dre` | GET | `/admin/financeiro/dre` | `financeiro_dre` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.dre.congelar` | POST | `/admin/financeiro/dre/congelar` | `financeiro_dre` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.dre.reabrir` | POST | `/admin/financeiro/dre/reabrir` | `financeiro_dre` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.analises` | GET | `/admin/financeiro/analises` | `financeiro_dre` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.orcamento` | GET | `/admin/financeiro/orcamento` | `financeiro_dre` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.orcamento.salvar` | POST | `/admin/financeiro/orcamento` | `financeiro_dre` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.financeiro.marco_zero` | POST | `/admin/financeiro/marco-zero` | `financeiro_dre` + valores | ✔ | ✔ | — | — | — | — | ✔ | — | — | ✔ |
| `admin.rotina.patio` | GET | `/admin/rotina/patio` | `rotina_patio` | ✔ | ✔ | ✔ | — | — | — | — | — | — | ✔ |
| `admin.rotina.painel` | GET | `/admin/rotina/minha-rotina` | `rotina_painel` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.rotina.checklist` | POST | `/admin/rotina/checklist` | `rotina_painel` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.rotina.diaria` | POST | `/admin/rotina/diaria/{id}` | `rotina_painel` | ✔ | ✔ | ✔ | ✔ | — | ✔ | ✔ | — | — | ✔ |
| `admin.rotina.agenda` | GET | `/admin/rotina/agenda` | `rotina_agenda` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.rotina.agendar` | POST | `/admin/rotina/agenda` | `rotina_agenda` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.rotina.agenda.status` | POST | `/admin/rotina/agenda/{id}/status` | `rotina_agenda` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.mentor.index` | GET | `/admin/mentor` | `mentor_mentoria` | ✔ | — | — | — | — | — | — | — | ✔ | ✔ |
| `admin.mentor.replicar_metas` | POST | `/admin/mentor/replicar-metas` | `mentor_mentoria` + `mentor_mentoria.replicar` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.mentor.replicar_pesos` | POST | `/admin/mentor/replicar-pesos` | `mentor_mentoria` + `mentor_mentoria.replicar` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.historico.index` | GET | `/admin/settings/historico` | `settings.historico` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.historico.importar` | POST | `/admin/settings/historico` | `settings.historico` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.historico.modelo` | GET | `/admin/settings/historico/modelo/{tipo}` | `settings.historico` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.colaboradores.index` | GET | `/admin/colaboradores` | `colaboradores` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.colaboradores.store` | POST | `/admin/colaboradores` | `colaboradores` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.colaboradores.edit` | GET | `/admin/colaboradores/{id}/editar` | `colaboradores` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.colaboradores.update` | PUT | `/admin/colaboradores/{id}` | `colaboradores` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.colaboradores.desligar` | POST | `/admin/colaboradores/{id}/desligar` | `colaboradores` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.ficha.edit` | GET | `/admin/settings/ficha` | `settings.ficha` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.ficha.update` | PUT | `/admin/settings/ficha` | `settings.ficha` + `settings.ficha.alterar` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.ficha.pendencia` | POST | `/admin/settings/ficha/pendencias` | `settings.ficha` + `settings.ficha.alterar` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.ficha.pendencia.resolver` | POST | `/admin/settings/ficha/pendencias/{id}/resolver` | `settings.ficha` + `settings.ficha.alterar` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.ficha.margens` | POST | `/admin/settings/ficha/margens` | `settings.ficha` + `settings.ficha.alterar` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.ficha.acessos` | GET | `/admin/settings/ficha/acessos` | `settings.ficha` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.tecnico.index` | GET | `/admin/tecnico` | `tecnico` | ✔ | — | — | — | ✔ | — | — | — | — | ✔ |
| `admin.tecnico.tasks` | GET | `/admin/tecnico/tarefas` | `tecnico` | ✔ | — | — | — | ✔ | — | — | — | — | ✔ |
| `admin.tecnico.diagnostico.start` | POST | `/admin/tecnico/diagnostico/{id}/iniciar` | `tecnico` | ✔ | — | — | — | ✔ | — | — | — | — | ✔ |
| `admin.tecnico.diagnostico.laudo` | POST | `/admin/tecnico/diagnostico/{id}/laudo` | `tecnico` | ✔ | — | — | — | ✔ | — | — | — | — | ✔ |
| `admin.tecnico.diagnostico.evidence` | POST | `/admin/tecnico/diagnostico/{id}/evidencia` | `tecnico` | ✔ | — | — | — | ✔ | — | — | — | — | ✔ |
| `admin.tecnico.diagnostico.part` | POST | `/admin/tecnico/diagnostico/{id}/peca` | `tecnico` | ✔ | — | — | — | ✔ | — | — | — | — | ✔ |
| `admin.tecnico.diagnostico.parts` | POST | `/admin/tecnico/diagnostico/{id}/relacao-pecas` | `tecnico` | ✔ | — | — | — | ✔ | — | — | — | — | ✔ |
| `admin.tecnico.diagnostico.finish` | POST | `/admin/tecnico/diagnostico/{id}/finalizar` | `tecnico` | ✔ | — | — | — | ✔ | — | — | — | — | ✔ |
| `admin.tecnico.operation` | POST | `/admin/tecnico/operacao/{itemId}/{acao}` | `tecnico` | ✔ | — | — | — | ✔ | — | — | — | — | ✔ |
| `admin.import.index` | GET | `/admin/settings/import` | `settings.import` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.import.upload` | POST | `/admin/settings/import/upload` | `settings.import` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.import.mapping` | GET | `/admin/settings/import/mapping` | `settings.import` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.import.preview` | POST | `/admin/settings/import/preview` | `settings.import` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.import.run` | POST | `/admin/settings/import/run` | `settings.import` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.import.cancel` | POST | `/admin/settings/import/cancel` | `settings.import` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.import.template` | GET | `/admin/settings/import/template/{kind}` | `settings.import` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.commissions.index` | GET | `/admin/commissions` | `comissoes` + valores | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.commissions.export` | GET | `/admin/commissions/export` | `comissoes` + valores | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.marketing.index` | GET | `/admin/marketing` | `marketing` + valores | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.marketing.export` | GET | `/admin/marketing/export` | `marketing` + valores | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.tenants.index` | GET | `/admin/tenants` | (só administrador) | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.tenants.fiscal_requests.approve` | POST | `/admin/tenants/fiscal-requests/{id}/approve` | (só administrador) | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.tenants.fiscal_requests.reject` | POST | `/admin/tenants/fiscal-requests/{id}/reject` | (só administrador) | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.vehicles.index` | GET | `/admin/vehicles` | `vehicles` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.vehicles.create` | GET | `/admin/vehicles/create` | `vehicles.create` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.vehicles.store` | POST | `/admin/vehicles` | `vehicles.create` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.vehicles.view` | GET | `/admin/vehicles/{id}` | `vehicles.view` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.vehicles.edit` | GET | `/admin/vehicles/{id}/edit` | `vehicles.edit` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.vehicles.update` | PUT | `/admin/vehicles/{id}` | `vehicles.edit` | ✔ | ✔ | — | ✔ | — | — | — | — | — | ✔ |
| `admin.vehicles.destroy` | DELETE | `/admin/vehicles/{id}` | `vehicles.delete` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.settings.whatsapp_settings.edit` | GET | `/admin/settings/whatsapp-settings` | `settings.other_settings.whatsapp_settings` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.settings.whatsapp_settings.update` | PUT | `/admin/settings/whatsapp-settings` | `settings.other_settings.whatsapp_settings` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.work_bays.index` | GET | `/admin/work-bays` | `work_bays` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.work_bays.create` | GET | `/admin/work-bays/create` | `work_bays.create` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.work_bays.store` | POST | `/admin/work-bays` | `work_bays.create` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.work_bays.view` | GET | `/admin/work-bays/{id}` | `work_bays.view` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.work_bays.edit` | GET | `/admin/work-bays/{id}/edit` | `work_bays.edit` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.work_bays.update` | PUT | `/admin/work-bays/{id}` | `work_bays.edit` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.work_bays.destroy` | DELETE | `/admin/work-bays/{id}` | `work_bays.delete` | ✔ | — | — | — | — | — | — | — | — | ✔ |
| `admin.work_bays.assignments.store` | POST | `/admin/work-bays/{id}/assignments` | NOVA: `work_bays.edit` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |
| `admin.work_bays.assignments.destroy` | DELETE | `/admin/work-bays/{id}/assignments/{assignmentId}` | NOVA: `work_bays.edit` | ✔ | ✔ | — | — | — | — | — | — | — | ✔ |

### 3.2 Rotas do CRM padrão (Krayin), por família

Estas telas não fazem parte do dia a dia da oficina (já saíram do menu). Para todos os perfis personalizados o resultado é o da coluna; Proprietário e Suporte (permissão total) passam em tudo.

| Família | Rotas | Chaves ACL usadas | Quem passa (perfis personalizados) |
| --- | --- | --- | --- |
| `admin.activities.*` | 9 | NOVA: `activities`<br>`activities.create`<br>`activities.delete`<br>`activities.edit`<br>`settings.other_settings.activities` | Prop, Sup |
| `admin.configuration.*` | 4 | NOVA: `settings.configuration`<br>`settings.configuration.general` | Prop, Ger, Sup |
| `admin.contacts.*` | 21 | NOVA: `contacts.persons.edit`<br>NOVA: `contacts.persons.view`<br>NOVA: `contacts.persons` ou `leads.create` ou `leads.edit` ou `quotes.create` ou `quotes.edit` ou `activities.create`<br>`contacts.organizations.create.quick-create`<br>`contacts.organizations.delete`<br>`contacts.organizations.edit`<br>… | Prop, Ger, Con, Sup |
| `admin.dashboard.*` | 2 | NOVA: `dashboard`<br>`dashboard` | Prop, Ger, Sup |
| `admin.datagrid.*` | 5 | (livre: qualquer usuário logado) | Prop, Ger, Che, Con, Téc, Peç, Fin, Zel, Men, Sup |
| `admin.guides.*` | 2 | (livre: qualquer usuário logado) | Prop, Ger, Che, Con, Téc, Peç, Fin, Zel, Men, Sup |
| `admin.help.*` | 1 | `help` | Prop, Ger, Sup |
| `admin.leads.*` | 24 | NOVA: `leads.create`<br>NOVA: `leads.edit`<br>NOVA: `leads.view`<br>NOVA: `leads`<br>NOVA: `quotes.delete`<br>`leads.create.quick-create`<br>… | Prop, Sup |
| `admin.locale.*` | 1 | (livre: qualquer usuário logado) | Prop, Ger, Che, Con, Téc, Peç, Fin, Zel, Men, Sup |
| `admin.mail.*` | 10 | NOVA: `mail.edit`<br>NOVA: `mail.view`<br>`mail.compose.quick-create`<br>`mail.delete`<br>`mail.edit`<br>`mail.view`<br>… | Prop, Sup |
| `admin.products.*` | 14 | NOVA: `products.edit`<br>NOVA: `products.view`<br>NOVA: `products` ou `quotes.create` ou `quotes.edit` ou `leads.edit`<br>`products.create.quick-create`<br>`products.delete`<br>`products.edit`<br>… | Prop, Sup |
| `admin.quotes.*` | 10 | NOVA: `quotes.create` ou `quotes.edit`<br>NOVA: `quotes`<br>`quotes.create`<br>`quotes.delete`<br>`quotes.edit`<br>`quotes.print`<br>… | Prop, Sup |
| `admin.recommendations.*` | 1 | `recommendations` | Prop, Sup |
| `admin.settings.api_keys.*` | 6 | `settings.other_settings.api_keys.create`<br>`settings.other_settings.api_keys.delete`<br>`settings.other_settings.api_keys.edit`<br>`settings.other_settings.api_keys` | Prop, Sup |
| `admin.settings.attributes.*` | 13 | NOVA: `settings.automation.attributes.delete`<br>NOVA: `settings.automation.attributes`<br>NOVA: `settings.automation.attributes` ou `leads.create` ou `leads.edit` ou `contacts.persons.create` ou `contacts.persons.edit` ou `products.create` ou `products.edit`<br>NOVA: `settings.automation.attributes` ou `leads.create` ou `leads.edit` ou `contacts.persons.create` ou `contacts.persons.edit` ou `products.create` ou `products.edit` ou `quotes.create` ou `quotes.edit` ou `activities.create` ou `activities.edit`<br>`settings.automation.attributes.create`<br>`settings.automation.attributes.delete`<br>… | Prop, Ger, Con, Sup |
| `admin.settings.data_transfer.*` | 15 | NOVA: `settings.automation.data_transfer.imports.create`<br>NOVA: `settings.automation.data_transfer.imports.edit`<br>NOVA: `settings.automation.data_transfer.imports.import`<br>NOVA: `settings.automation.data_transfer.imports`<br>`settings.automation.data_transfer.imports.create`<br>`settings.automation.data_transfer.imports.delete`<br>… | Prop, Sup |
| `admin.settings.email_templates.*` | 6 | `settings.automation.email_templates.create`<br>`settings.automation.email_templates.delete`<br>`settings.automation.email_templates.edit`<br>`settings.automation.email_templates` | Prop, Sup |
| `admin.settings.google_contacts.*` | 4 | `settings.other_settings.google_contacts` | Prop, Sup |
| `admin.settings.groups.*` | 5 | `settings.user.groups.create`<br>`settings.user.groups.delete`<br>`settings.user.groups.edit`<br>`settings.user.groups` | Prop, Sup |
| `admin.settings.index.*` | 1 | `settings` | Prop, Ger, Sup |
| `admin.settings.locations.*` | 4 | NOVA: `settings.inventory.warehouse.create`<br>NOVA: `settings.inventory.warehouse.delete`<br>NOVA: `settings.inventory.warehouse.edit`<br>NOVA: `settings.inventory.warehouse` | Prop, Ger, Sup |
| `admin.settings.marketing.*` | 14 | NOVA: `settings.automation.campaigns`<br>`settings.automation.campaigns.create`<br>`settings.automation.campaigns.delete`<br>`settings.automation.campaigns.edit`<br>`settings.automation.campaigns`<br>`settings.automation.events.create`<br>… | Prop, Sup |
| `admin.settings.pipelines.*` | 6 | `settings.lead.pipelines.create`<br>`settings.lead.pipelines.delete`<br>`settings.lead.pipelines.edit`<br>`settings.lead.pipelines` | Prop, Sup |
| `admin.settings.roles.*` | 6 | `settings.user.roles.create`<br>`settings.user.roles.delete`<br>`settings.user.roles.edit`<br>`settings.user.roles` | Prop, Sup |
| `admin.settings.search.*` | 1 | NOVA: `settings` | Prop, Ger, Sup |
| `admin.settings.sources.*` | 5 | `settings.lead.sources.create`<br>`settings.lead.sources.delete`<br>`settings.lead.sources.edit`<br>`settings.lead.sources` | Prop, Sup |
| `admin.settings.tags.*` | 7 | NOVA: `settings.other_settings.tags` ou `leads.edit` ou `contacts.persons.edit` ou `products.edit`<br>`settings.other_settings.tags.create`<br>`settings.other_settings.tags.delete`<br>`settings.other_settings.tags.edit`<br>`settings.other_settings.tags` | Prop, Ger, Con, Sup |
| `admin.settings.types.*` | 5 | `settings.lead.types.create`<br>`settings.lead.types.delete`<br>`settings.lead.types.edit`<br>`settings.lead.types` | Prop, Sup |
| `admin.settings.users.*` | 8 | NOVA: `settings.user.users` ou `leads.create` ou `leads.edit` ou `contacts.persons.create` ou `contacts.persons.edit` ou `activities.create` ou `activities.edit` ou `quotes.create` ou `quotes.edit`<br>`settings.user.users.create`<br>`settings.user.users.delete`<br>`settings.user.users.edit`<br>`settings.user.users` | Prop, Ger, Con, Sup |
| `admin.settings.warehouse.*` | 1 | NOVA: `settings.inventory.warehouse` | Prop, Ger, Sup |
| `admin.settings.warehouses.*` | 11 | NOVA: `settings.inventory.warehouse.create`<br>NOVA: `settings.inventory.warehouse.delete`<br>NOVA: `settings.inventory.warehouse.edit`<br>NOVA: `settings.inventory.warehouse`<br>NOVA: `settings.inventory.warehouse` ou `parts.view` ou `products.edit` | Prop, Ger, Peç, Sup |
| `admin.settings.web_forms.*` | 7 | `settings.other_settings.web_forms.create`<br>`settings.other_settings.web_forms.delete`<br>`settings.other_settings.web_forms.edit`<br>`settings.other_settings.web_forms.view`<br>`settings.other_settings.web_forms` | Prop, Sup |
| `admin.settings.webhooks.*` | 6 | `settings.automation.webhooks.create`<br>`settings.automation.webhooks.delete`<br>`settings.automation.webhooks.edit`<br>`settings.automation.webhooks` | Prop, Sup |
| `admin.settings.workflows.*` | 6 | `settings.automation.workflows.create`<br>`settings.automation.workflows.delete`<br>`settings.automation.workflows.edit`<br>`settings.automation.workflows` | Prop, Sup |
| `admin.tinymce.*` | 1 | NOVA: `mail.compose` ou `settings.automation.email_templates.create` ou `settings.automation.email_templates.edit` ou `settings.automation.campaigns.create` ou `settings.automation.campaigns.edit` | Prop, Sup |
| `admin.user.*` | 2 | (livre: qualquer usuário logado) | Prop, Ger, Che, Con, Téc, Peç, Fin, Zel, Men, Sup |


## 4. Rotas que hoje NÃO têm chave ACL (qualquer usuário logado abre) e como ficam

São 105 rotas. É a lacuna principal do "esconder o menu não basta": o Bouncer do Krayin só confere rotas que aparecem em `Config/acl.php`.

| Rota | Método | URL | Regra nova |
| --- | --- | --- | --- |
| `admin.leads.create_by_ai` | POST | `/admin/leads/create-by-ai` | `leads.create` |
| `admin.leads.attributes.update` | PUT | `/admin/leads/attributes/edit/{id}` | `leads.edit` |
| `admin.leads.stage.update` | PUT | `/admin/leads/stage/edit/{id}` | `leads.edit` |
| `admin.leads.search` | GET | `/admin/leads/search` | `leads` |
| `admin.leads.get` | GET | `/admin/leads/get/{pipeline_id?}` | `leads` |
| `admin.leads.product.remove` | DELETE | `/admin/leads/product/{lead_id}` | `leads.edit` |
| `admin.leads.product.add` | PUT | `/admin/leads/product/{lead_id}` | `leads.edit` |
| `admin.leads.kanban.look_up` | GET | `/admin/leads/kanban/look-up` | `leads` |
| `admin.leads.activities.index` | GET | `/admin/leads/{id}/activities` | `leads.view` |
| `admin.leads.emails.store` | POST | `/admin/leads/{id}/emails` | `leads.edit` |
| `admin.leads.emails.detach` | DELETE | `/admin/leads/{id}/emails` | `leads.edit` |
| `admin.leads.quotes.delete` | DELETE | `/admin/leads/quotes/{quote_id?}` | `quotes.delete` |
| `admin.mail.attachment_download` | GET | `/admin/mail/attachment-download/{id?}` | `mail.view` |
| `admin.mail.mass_update` | POST | `/admin/mail/mass-update` | `mail.edit` |
| `admin.mail.tags.attach` | POST | `/admin/mail/{id}/tags` | `mail.edit` |
| `admin.mail.tags.detach` | DELETE | `/admin/mail/{id}/tags` | `mail.edit` |
| `admin.settings.search` | GET | `/admin/settings/settings/search` | `settings` |
| `admin.settings.tags.search` | GET | `/admin/settings/tags/search` | `settings.other_settings.tags` ou `leads.edit` ou `contacts.persons.edit` ou `products.edit` |
| `admin.settings.users.search` | GET | `/admin/settings/users/search` | `settings.user.users` ou `leads.create` ou `leads.edit` ou `contacts.persons.create` ou `contacts.persons.edit` ou `activities.create` ou `activities.edit` ou `quotes.create` ou `quotes.edit` |
| `admin.settings.attributes.check_unique_validation` | GET | `/admin/settings/attributes/check-unique-validation` | `settings.automation.attributes` ou `leads.create` ou `leads.edit` ou `contacts.persons.create` ou `contacts.persons.edit` ou `products.create` ou `products.edit` |
| `admin.settings.attributes.lookup` | GET | `/admin/settings/attributes/lookup/{lookup?}` | `settings.automation.attributes` ou `leads.create` ou `leads.edit` ou `contacts.persons.create` ou `contacts.persons.edit` ou `products.create` ou `products.edit` ou `quotes.create` ou `quotes.edit` ou `activities.create` ou `activities.edit` |
| `admin.settings.attributes.lookup_entity` | GET | `/admin/settings/attributes/lookup-entity/{lookup?}` | `settings.automation.attributes` ou `leads.create` ou `leads.edit` ou `contacts.persons.create` ou `contacts.persons.edit` ou `products.create` ou `products.edit` ou `quotes.create` ou `quotes.edit` ou `activities.create` ou `activities.edit` |
| `admin.settings.attributes.options` | GET | `/admin/settings/attributes/{id}/options` | `settings.automation.attributes` ou `leads.create` ou `leads.edit` ou `contacts.persons.create` ou `contacts.persons.edit` ou `products.create` ou `products.edit` |
| `admin.settings.attributes.mass_delete` | POST | `/admin/settings/attributes/mass-destroy` | `settings.automation.attributes.delete` |
| `admin.settings.attributes.download` | GET | `/admin/settings/attributes/download` | `settings.automation.attributes` |
| `admin.settings.warehouses.update` | PUT | `/admin/settings/warehouses/edit/{id}` | `settings.inventory.warehouse.edit` |
| `admin.settings.warehouses.index` | GET | `/admin/settings/warehouses` | `settings.inventory.warehouse` |
| `admin.settings.warehouses.search` | GET | `/admin/settings/warehouses/search` | `settings.inventory.warehouse` ou `parts.view` ou `products.edit` |
| `admin.settings.warehouses.products.index` | GET | `/admin/settings/warehouses/{id}/products` | `settings.inventory.warehouse` |
| `admin.settings.warehouses.create` | GET | `/admin/settings/warehouses/create` | `settings.inventory.warehouse.create` |
| `admin.settings.warehouses.store` | POST | `/admin/settings/warehouses/create` | `settings.inventory.warehouse.create` |
| `admin.settings.warehouses.view` | GET | `/admin/settings/warehouses/view/{id}` | `settings.inventory.warehouse` |
| `admin.settings.warehouses.edit` | GET | `/admin/settings/warehouses/edit/{id?}` | `settings.inventory.warehouse.edit` |
| `admin.settings.warehouses.delete` | DELETE | `/admin/settings/warehouses/{id}` | `settings.inventory.warehouse.delete` |
| `admin.settings.warehouses.tags.attach` | POST | `/admin/settings/warehouses/{id}/tags` | `settings.inventory.warehouse.edit` |
| `admin.settings.warehouses.tags.detach` | DELETE | `/admin/settings/warehouses/{id}/tags` | `settings.inventory.warehouse.edit` |
| `admin.settings.warehouse.activities.index` | GET | `/admin/settings/warehouses/{id}/activities` | `settings.inventory.warehouse` |
| `admin.settings.locations.search` | GET | `/admin/settings/locations/search` | `settings.inventory.warehouse` |
| `admin.settings.locations.store` | POST | `/admin/settings/locations/create` | `settings.inventory.warehouse.create` |
| `admin.settings.locations.update` | PUT | `/admin/settings/locations/edit/{id}` | `settings.inventory.warehouse.edit` |
| `admin.settings.locations.delete` | DELETE | `/admin/settings/locations/{id}` | `settings.inventory.warehouse.delete` |
| `admin.settings.marketing.campaigns.events` | GET | `/admin/settings/marketing/campaigns/events` | `settings.automation.campaigns` |
| `admin.settings.marketing.campaigns.email-templates` | GET | `/admin/settings/marketing/campaigns/email-templates` | `settings.automation.campaigns` |
| `admin.settings.data_transfer.imports.store` | POST | `/admin/settings/data-transfer/imports/create` | `settings.automation.data_transfer.imports.create` |
| `admin.settings.data_transfer.imports.update` | PUT | `/admin/settings/data-transfer/imports/update/{id}` | `settings.automation.data_transfer.imports.edit` |
| `admin.settings.data_transfer.imports.import` | GET | `/admin/settings/data-transfer/imports/import/{id}` | `settings.automation.data_transfer.imports.import` |
| `admin.settings.data_transfer.imports.validate` | GET | `/admin/settings/data-transfer/imports/validate/{id}` | `settings.automation.data_transfer.imports.import` |
| `admin.settings.data_transfer.imports.start` | GET | `/admin/settings/data-transfer/imports/start/{id}` | `settings.automation.data_transfer.imports.import` |
| `admin.settings.data_transfer.imports.link` | GET | `/admin/settings/data-transfer/imports/link/{id}` | `settings.automation.data_transfer.imports.import` |
| `admin.settings.data_transfer.imports.index_data` | GET | `/admin/settings/data-transfer/imports/index/{id}` | `settings.automation.data_transfer.imports` |
| `admin.settings.data_transfer.imports.stats` | GET | `/admin/settings/data-transfer/imports/stats/{id}/{state?}` | `settings.automation.data_transfer.imports` |
| `admin.settings.data_transfer.imports.download_sample` | GET | `/admin/settings/data-transfer/imports/download-sample/{sample?}` | `settings.automation.data_transfer.imports` |
| `admin.settings.data_transfer.imports.download` | GET | `/admin/settings/data-transfer/imports/download/{id}` | `settings.automation.data_transfer.imports` |
| `admin.settings.data_transfer.imports.download_error_report` | GET | `/admin/settings/data-transfer/imports/download-error-report/{id}` | `settings.automation.data_transfer.imports` |
| `admin.products.search` | GET | `/admin/products/search` | `products` ou `quotes.create` ou `quotes.edit` ou `leads.edit` |
| `admin.products.warehouses` | GET | `/admin/products/{id}/warehouses` | `products.view` |
| `admin.products.inventories.store` | POST | `/admin/products/{id}/inventories/{warehouseId?}` | `products.edit` |
| `admin.products.activities.index` | GET | `/admin/products/{id}/activities` | `products.view` |
| `admin.products.tags.attach` | POST | `/admin/products/{id}/tags` | `products.edit` |
| `admin.products.tags.detach` | DELETE | `/admin/products/{id}/tags` | `products.edit` |
| `admin.contacts.persons.search` | GET | `/admin/contacts/persons/search` | `contacts.persons` ou `leads.create` ou `leads.edit` ou `quotes.create` ou `quotes.edit` ou `activities.create` |
| `admin.contacts.persons.tags.attach` | POST | `/admin/contacts/persons/{id}/tags` | `contacts.persons.edit` |
| `admin.contacts.persons.tags.detach` | DELETE | `/admin/contacts/persons/{id}/tags` | `contacts.persons.edit` |
| `admin.contacts.persons.activities.index` | GET | `/admin/contacts/persons/{id}/activities` | `contacts.persons.view` |
| `admin.activities.get` | GET | `/admin/activities/get` | `activities` |
| `admin.activities.file_download` | GET | `/admin/activities/download/{id}` | `activities` |
| `admin.quotes.search` | GET | `/admin/quotes/search` | `quotes` |
| `admin.quotes.lead_products` | GET | `/admin/quotes/lead-products/{lead_id}` | `quotes.create` ou `quotes.edit` |
| `admin.locale.switch` | GET | `/admin/locale/switch/{code}` | livre (qualquer usuário logado) |
| `admin.configuration.search` | GET | `/admin/configuration/search` | `settings.configuration` |
| `admin.configuration.store` | POST | `/admin/configuration/{slug?}/{slug2?}` | `settings.configuration` |
| `admin.configuration.download` | GET | `/admin/configuration/{slug?}/{slug2?}/{path}` | `settings.configuration` |
| `admin.dashboard.stats` | GET | `/admin/dashboard/stats` | `dashboard` |
| `admin.guides.index` | GET | `/admin/guides` | livre (qualquer usuário logado) |
| `admin.guides.complete-step` | POST | `/admin/guides/{stepId}/complete` | livre (qualquer usuário logado) |
| `admin.datagrid.saved_filters.store` | POST | `/admin/datagrid/datagrid/saved-filters` | livre (qualquer usuário logado) |
| `admin.datagrid.saved_filters.index` | GET | `/admin/datagrid/datagrid/saved-filters` | livre (qualquer usuário logado) |
| `admin.datagrid.saved_filters.update` | PUT | `/admin/datagrid/datagrid/saved-filters/{id}` | livre (qualquer usuário logado) |
| `admin.datagrid.saved_filters.destroy` | DELETE | `/admin/datagrid/datagrid/saved-filters/{id}` | livre (qualquer usuário logado) |
| `admin.datagrid.look_up` | GET | `/admin/datagrid/datagrid/look-up` | livre (qualquer usuário logado) |
| `admin.tinymce.upload` | POST | `/admin/tinymce/upload` | `mail.compose` ou `settings.automation.email_templates.create` ou `settings.automation.email_templates.edit` ou `settings.automation.campaigns.create` ou `settings.automation.campaigns.edit` |
| `admin.user.account.edit` | GET | `/admin/account` | livre (qualquer usuário logado) |
| `admin.user.account.update` | PUT | `/admin/account/update` | livre (qualquer usuário logado) |
| `admin.service_orders.vehicles-by-person` | GET | `/admin/service-orders/vehicles-by-person/{personId}` | `service_orders.create` ou `service_orders.edit` |
| `admin.service_orders.items.store` | POST | `/admin/service-orders/{id}/items` | `service_orders.edit` |
| `admin.service_orders.items.destroy` | DELETE | `/admin/service-orders/{id}/items/{itemId}` | `service_orders.edit` |
| `admin.service_orders.print` | GET | `/admin/service-orders/{id}/print` | `service_orders.view` |
| `admin.service_orders.diagnostico.whatsapp` | POST | `/admin/service-orders/{id}/diagnostico/whatsapp` | `service_orders.edit` |
| `admin.service_orders.execucao.tarefa.update` | PUT | `/admin/service-orders/{id}/execucao/tarefa` | `service_orders.edit` |
| `admin.workshop.alerts.index` | GET | `/admin/workshop/alertas` | livre (qualquer usuário logado) |
| `admin.workshop.alerts.summary` | GET | `/admin/workshop/alertas/resumo` | livre (qualquer usuário logado) |
| `admin.workshop.alerts.read_all` | POST | `/admin/workshop/alertas/lidos` | livre (qualquer usuário logado) |
| `admin.workshop.alerts.ack` | POST | `/admin/workshop/alertas/{alertId}/ciente` | livre (qualquer usuário logado) |
| `admin.workshop.push.subscribe` | POST | `/admin/workshop/push/inscrever` | livre (qualquer usuário logado) |
| `admin.workshop.push.unsubscribe` | POST | `/admin/workshop/push/cancelar` | livre (qualquer usuário logado) |
| `admin.work_bays.assignments.store` | POST | `/admin/work-bays/{id}/assignments` | `work_bays.edit` |
| `admin.work_bays.assignments.destroy` | DELETE | `/admin/work-bays/{id}/assignments/{assignmentId}` | `work_bays.edit` |
| `admin.tenants.index` | GET | `/admin/tenants` | só administrador (a tela também confere e responde 404 dentro de uma oficina) |
| `admin.tenants.fiscal_requests.approve` | POST | `/admin/tenants/fiscal-requests/{id}/approve` | só administrador (a tela também confere e responde 404 dentro de uma oficina) |
| `admin.tenants.fiscal_requests.reject` | POST | `/admin/tenants/fiscal-requests/{id}/reject` | só administrador (a tela também confere e responde 404 dentro de uma oficina) |
| `admin.remote_services.vehicles-by-person` | GET | `/admin/remote-services/vehicles-by-person/{personId}` | `remote_services.create` |
| `admin.remote_services.search-persons` | GET | `/admin/remote-services/search-persons` | `remote_services.create` |
| `admin.remote_services.lookup-by-teamviewer` | GET | `/admin/remote-services/lookup-by-teamviewer/{teamviewerId}` | `remote_services.create` ou `remote_services.finish` |
| `admin.remote_services.start` | PUT | `/admin/remote-services/{id}/start` | `remote_services.finish` |
| `admin.remote_services.update-teamviewer` | PUT | `/admin/remote-services/{id}/teamviewer` | `remote_services.create` ou `remote_services.finish` |

## 5. Rotas liberadas a qualquer usuário logado (não exigem chave)

`admin.session.destroy`, `admin.user.account.edit`, `admin.user.account.update`, `admin.locale.switch`, `admin.guides.index`, `admin.guides.complete-step`, `admin.datagrid.saved_filters.store`, `admin.datagrid.saved_filters.index`, `admin.datagrid.saved_filters.update`, `admin.datagrid.saved_filters.destroy`, `admin.datagrid.look_up`, `admin.workshop.alerts.index`, `admin.workshop.alerts.summary`, `admin.workshop.alerts.read_all`, `admin.workshop.alerts.ack`, `admin.workshop.push.subscribe`, `admin.workshop.push.unsubscribe`

Só leitura/estado pessoal: conta, idioma, sair, guias, filtros salvos do DataGrid, sino de alertas e inscrição de notificação. Das que escrevem, as permitidas ao Mentor (`escrita_pessoal`): `admin.session.destroy`, `admin.user.account.update`, `admin.workshop.alerts.read_all`, `admin.workshop.alerts.ack`, `admin.workshop.push.subscribe`, `admin.workshop.push.unsubscribe`, `admin.guides.complete-step`, `admin.datagrid.saved_filters.store`, `admin.datagrid.saved_filters.update`, `admin.datagrid.saved_filters.destroy`.

## 6. Rotas só do administrador (permissão total)

`admin.tenants.index`, `admin.tenants.fiscal_requests.approve`, `admin.tenants.fiscal_requests.reject`

## 7. O que o Técnico alcança (lista fechada)

Com a função do Técnico da matriz (`tecnico`, `bonus_meu`) e o piloto ligado, **somente** estas rotas respondem diferente de 403. É o instantâneo que o teste `TecnicoSemDinheiroTest` congela:

- `GET /admin/locale/switch/{code}` (`admin.locale.switch`)
- `GET /admin/guides` (`admin.guides.index`)
- `POST /admin/guides/{stepId}/complete` (`admin.guides.complete-step`)
- `POST /admin/datagrid/datagrid/saved-filters` (`admin.datagrid.saved_filters.store`)
- `GET /admin/datagrid/datagrid/saved-filters` (`admin.datagrid.saved_filters.index`)
- `PUT /admin/datagrid/datagrid/saved-filters/{id}` (`admin.datagrid.saved_filters.update`)
- `DELETE /admin/datagrid/datagrid/saved-filters/{id}` (`admin.datagrid.saved_filters.destroy`)
- `GET /admin/datagrid/datagrid/look-up` (`admin.datagrid.look_up`)
- `GET /admin/account` (`admin.user.account.edit`)
- `PUT /admin/account/update` (`admin.user.account.update`)
- `GET /admin/workshop/alertas` (`admin.workshop.alerts.index`)
- `GET /admin/workshop/alertas/resumo` (`admin.workshop.alerts.summary`)
- `POST /admin/workshop/alertas/lidos` (`admin.workshop.alerts.read_all`)
- `POST /admin/workshop/alertas/{alertId}/ciente` (`admin.workshop.alerts.ack`)
- `POST /admin/workshop/push/inscrever` (`admin.workshop.push.subscribe`)
- `POST /admin/workshop/push/cancelar` (`admin.workshop.push.unsubscribe`)
- `GET /admin/mentoria/bonus/meu` (`admin.mentoria.bonus.meu`)
- `POST /admin/mentoria/bonus/{id}/ciencia` (`admin.mentoria.bonus.ciencia`)
- `GET /admin/tecnico` (`admin.tecnico.index`)
- `GET /admin/tecnico/tarefas` (`admin.tecnico.tasks`)
- `POST /admin/tecnico/diagnostico/{id}/iniciar` (`admin.tecnico.diagnostico.start`)
- `POST /admin/tecnico/diagnostico/{id}/laudo` (`admin.tecnico.diagnostico.laudo`)
- `POST /admin/tecnico/diagnostico/{id}/evidencia` (`admin.tecnico.diagnostico.evidence`)
- `POST /admin/tecnico/diagnostico/{id}/peca` (`admin.tecnico.diagnostico.part`)
- `POST /admin/tecnico/diagnostico/{id}/relacao-pecas` (`admin.tecnico.diagnostico.parts`)
- `POST /admin/tecnico/diagnostico/{id}/finalizar` (`admin.tecnico.diagnostico.finish`)
- `POST /admin/tecnico/operacao/{itemId}/{acao}` (`admin.tecnico.operation`)

Respostas dessas rotas e o que carregam: `admin.tecnico.tasks` (JSON montado campo a campo, sem preço/desconto/crédito/comissão: `TecnicoTasks`); `admin.tecnico.index` (HTML; lista de peças só com id, código e nome); `admin.mentoria.bonus.meu` (HTML; "valor a pagar" só aparece com `ValoresFinanceiros::can()`); alertas (`mensagem` nunca leva R$, regra do AlertService); demais são ações sem corpo de dados.

## 8. Mentor: nenhuma rota de escrita

Entre as 269 rotas de escrita (POST/PUT/PATCH/DELETE) do admin, o Mentor só alcança as de estado pessoal: `admin.guides.complete-step`, `admin.datagrid.saved_filters.store`, `admin.datagrid.saved_filters.update`, `admin.datagrid.saved_filters.destroy`, `admin.user.account.update`, `admin.workshop.alerts.read_all`, `admin.workshop.alerts.ack`, `admin.workshop.push.subscribe`, `admin.workshop.push.unsubscribe`. As outras 260 respondem 403 antes de chegar ao controlador (regra `somente_leitura` do perfil, independente das chaves).

## 9. Conferência rápida por perfil (roteiro de 5 minutos, piloto ligado)

| Perfil | Menu esperado | Tela ao entrar | Deve dar acesso negado (403) |
| --- | --- | --- | --- |
| Técnico | Minhas tarefas, Meu bônus | Minhas tarefas | `/admin/commissions`, `/admin/financeiro/dre`, `/admin/service-orders/{id}`, `/admin/service-orders`, `/admin/quotes/search?...` |
| Zeladoria | Registros, Meu bônus | Registros (só os 3 checklists dela) | `/admin/rotina/minha-rotina`, `/admin/financeiro/lancamentos`, POST em Registros com tipo de outro cargo |
| Peças | (Painel de Peças), Estoque, Grupos, Minha rotina, Meu bônus | Painel de Peças (F10) ou Minha rotina | `/admin/financeiro/dre`, `/admin/commissions`, `/admin/colaboradores` |
| Financeiro | Financeiro, DRE e preços, Faturamento, Minha rotina, Meu bônus | Minha rotina | `/admin/commissions`, `/admin/colaboradores`, `/admin/settings/ficha` |
| Chefe | Quadro de pátio, Diagnóstico, Orçamento, Negociação, Serviços em Execução, Veículo em Teste, Minha rotina, Registros (auditorias), Meu bônus | Minha rotina | `/admin/service-orders/stage/Faturamento`, `/admin/financeiro/dre`, `/admin/commissions` |
| Consultor | Agenda, Cadastro, as 8 etapas, Marketing, Minha rotina, Meu bônus | Minha rotina | `/admin/financeiro/dre`, `/admin/commissions`, `/admin/colaboradores` |
| Gerente | tudo da oficina (29 itens de topo) | Minha rotina | `/admin/settings/users`, `/admin/settings/roles`, `/admin/settings/groups`, PUT `/admin/settings/ficha` (a Ficha abre só para consulta), POST `/admin/mentoria/bonus/{id}/aprovar`, `/admin/mentor` |
| Proprietário | tudo (31 itens de topo) | Painel do Dono (versão celular no celular) | `/admin/tenants` dentro de uma oficina (404) |
| Mentor | Painel do Dono, Visão do mentor | Painel do Dono | qualquer POST/PUT/DELETE (ex.: `POST /admin/mentor/replicar-metas`), `/admin/mentoria/alcadas`, `/admin/financeiro/dre`, `/admin/service-orders` |

