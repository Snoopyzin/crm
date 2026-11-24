# Especificação — F04-perfis

**Frente:** F04-perfis — Perfis = cargos da mentoria (funções, migração de usuários, acúmulo e sincronização com Colaboradores)
**Item do PDF:** A4 (e a linha "Teste:" dele). Linhas do roteiro de 72 relacionadas: 2, 5, 6 (parte técnica), 7 (só a base), 71 (só a base do Mentor).
**Tamanho:** grande (muitos arquivos, nenhuma peça é difícil sozinha).
**Modo de teste ÷10:** não se aplica (esta frente não tem prazos).

## Resumo da decisão (leia primeiro)

1. **Catálogo central**: `Config/perfis.php` (dados) + `Services/Perfis/PerfilCatalog.php` (leitura). 10 perfis com chave estável: `proprietario, gerente, chefe, consultor, tecnico, pecas, financeiro, zeladoria` (os 8 cargos que o código já usa) + `mentor` e `suporte` (novos, não são cargos de colaborador). **A chave do Chefe de oficina é `chefe` (não `chefe_oficina`)**: é o valor que já está gravado em `roles.perfil_oficina`, `colaboradores.cargos`, `workshop_settings.bonus_cheio_percentual` e nos destinos das barreiras (`BarrierRule::DESTINOS`). Renomear exigiria reescrever dados; por isso fica `chefe`.
2. **Ação explícita e idempotente** "Criar/atualizar funções da mentoria": `Services/Perfis/PerfilService.php`, com **prévia** (dry-run), **aplicar** (transação, registro de tudo) e **desfazer** a migração dos usuários. Entradas: tela nova `Configurações > Perfis da mentoria` (cartão de atalho em "Indicadores e alertas") e comando `php artisan workshop:perfis`. Nunca roda sozinha, nunca em migration.
3. **Acúmulo de cargos = "função combinada gerida"**: o usuário continua com UM role (limite do Krayin). Para cada conjunto de perfis existe uma função gerida pelo sistema (`roles.gerenciada_em` preenchido, `roles.perfil_oficina = "chefe,tecnico"`), criada sob demanda, com as permissões = soma das permissões dos perfis; os valores financeiros só entram se algum perfil do conjunto der valores (regra do Técnico garantida pela estrutura, não por conferência manual).
4. **Sincronização Colaborador → usuário**: ao salvar o colaborador ligado a um usuário, o `ColaboradorController` chama `PerfilService::sincronizarColaborador()`, que coloca o usuário na função gerida dos cargos marcados. Só vale depois que o botão das funções foi usado ao menos uma vez (comportamento atual preservado antes disso), com travas de hierarquia, de autoalteração e do último Proprietário.
5. **Mentor** (somente leitura) e **Suporte Volmaster** (interno): perfis do catálogo, com função criada pela mesma ação; Mentor é reforçado por um middleware que recusa (403) qualquer escrita; Suporte não vê valores em R$ e não conta como colaborador.

---

## O que já existe

### Catálogo de papéis (parcial)
- `packages/Webkul/ServiceOrder/src/Http/Controllers/WorkshopSettingsController.php:24-33` — constante `PAPEIS` com os 8 papéis (proprietario, gerente, chefe, consultor, tecnico, pecas, financeiro, zeladoria) e os nomes do roteiro ("Auxiliar de copa e zeladoria" etc.). Usada em: `ColaboradorController.php:26,37,89`; `BonusController.php:43,56,174,219`; `FichaController.php:27,96,126`; `mentoria/painel.blade.php:104`; `HistoricoImportService.php:154`; no próprio controller `:48,170`.
- `Services/FichaEnquadramento.php:24` `CARGOS_OBRIGATORIOS`; `Models/BarrierRule.php:35-43` `DESTINOS` (usa `chefe`, `consultor`, `pecas`, `financeiro`, `gerente`, `proprietario` + `tecnico_da_os`); `Services/Indicators/IndicatorCatalog.php:18-28` `CARGOS` (grupos de indicadores, com `dono` e `dre`; **não são perfis**, não mexer).
- Não existe perfil `mentor` nem `suporte` em lugar nenhum.

### Funções (roles), permissões e perfil
- `roles.perfil_oficina`: criado `string(20)` em `Database/Migrations/2026_11_16_100400_...php:21-23`, ampliado para `string(120)` (lista separada por vírgula = acúmulo) em `2026_11_17_100300_allow_several_workshop_profiles_per_role.php:14-16`. Leitura com `FIND_IN_SET` em `Services/RecipientResolver.php:40-45` (`byPerfil`).
- Tela "Papéis e celulares": `Resources/views/settings/workshop.blade.php:163-204` (cada função com checkboxes dos 8 papéis, `:174-187`) e `WorkshopSettingsController::updateRoles` (`:164-195`; grava com `DB::table('roles')->update(...)` na linha 172, só entre as chaves de `PAPEIS`). **Só rotula a função; não cria função, não mexe em permissões, não migra usuário.**
- Migration `2026_11_16_100400`: `:43-60` `grantValoresFinanceirosToExistingRoles` deu `ver_valores_financeiros` a todas as funções `custom`, exceto as de nome de técnico (`isTecnicoName`, `:95-104`). **Consequência real: "Diagnóstico" e "Programador" VEEM valores hoje** (o regex não os reconhece como técnico). `:66-88` `guessPerfilOficina` só rotulou por nome (Administrador→proprietario, Consultor→consultor, Mecânico→tecnico, Financeiro→financeiro; Diagnóstico e Programador ficaram sem perfil).
- Funções reais do servidor: `packages/Webkul/Admin/src/Database/Migrations/2026_08_12_180000_seed_workshop_roles.php:15-72` (Financeiro, Mecânico, Consultor, Diagnóstico, Programador; todas `custom`; a descrição de Diagnóstico/Programador diz que "a separação de quem vê o quê é feita pelo Grupo do usuário") e `Installer/.../Seeders/User/RoleSeeder.php:24-29` (Administrador, `permission_type = all`). Em oficina nova (tenant) o `RoleSeeder` apaga todas as funções e recria só o Administrador (`Tenant/src/Jobs/ProvisionTenantJob.php:48-66`).
- `packages/Webkul/User/src/Models/Role.php:15-25`: `$fillable` **não** tem `perfil_oficina`; `permissions` é cast `array`. `Admin/.../Settings/RoleController.php:99-127` (`update`) não mexe em `perfil_oficina`; `:132-178` (`destroy`) recusa apagar função com usuários.
- **Armadilha grave**: `users.role_id` tem FK `onDelete('cascade')` (`packages/Webkul/User/src/Database/Migrations/2021_03_12_074857_create_users_table.php:23`). **Apagar uma função apaga os usuários dela.** Esta frente nunca apaga função.
- Quem decide acesso: `User::hasPermission` (`packages/Webkul/User/src/Models/User.php:117-124`), `Admin/Bouncer.php:15-26` (`all` passa tudo), `Admin/Http/Middleware/Bouncer.php:39-45,55-72` (**desloga** quem tem função `custom` com `permissions` vazio) e `:79-86` (rota sem permissão → `abort(401)`).
- ACL: `Config/acl.php` é lista plana de chaves; `acl()->getItems()` devolve só chaves de topo (`Core/Acl.php:27-36`); `Core/Acl::getRoles` mapeia rota→última chave que a cita (`:57-77`). `Core/Menu::getItems` filtra o menu por `bouncer()->hasPermission(chave do item)` (`Menu.php:59-70`); por isso itens de **Configurações** (`settings.workshop.geral` etc.) só aparecem para função `custom` se existir a chave-filha no ACL (hoje só funções `all` veem "Indicadores e alertas" na tela de Configurações).

### Quem lê `perfil_oficina` (todos com `explode(',')` próprio, sem catálogo)
`Http/Middleware/EntradaPorCargo.php:29-35` (proprietario → Painel do Dono; tecnico sem chefe/gerente → Minhas tarefas; resto → Minha rotina; só com piloto ligado), `Http/Middleware/DonoForaDoFluxo.php:39-43`, `Services/Alcada/AlcadaService.php:35-48` (`levelOf`: proprietario=dono, gerente=gerente), `Services/Bonus/BonusService.php:437-445` e `Services/Bonus/TravaService.php:254-259` (mesmo critério), `Services/Rotina/RotinaService.php:108-126` (`painelDoUsuario`: chefe, pecas, financeiro, consultor, gerente/proprietario, zeladoria; `default => []`) e `:478-481` (`ehConsultor`), `Http/Controllers/RotinaController.php:35`. Todos usam `match`/`in_array` e ignoram valores desconhecidos: **`mentor` e `suporte` são seguros** (caem no `default`).

### Valores financeiros e comissão
- `Services/ValoresFinanceiros.php:20-38` — `can()` é **verdadeiro com piloto desligado**; com piloto ligado exige `permission_type = all` ou a chave `ver_valores_financeiros` na função. É a única fonte da regra "Técnico sem valores".
- `Services/PainelComissao.php:36-60` e `Services/CommissionReportService.php:70,85-90` usam **`roles.comissao_percentual`** (por função). Trocar o usuário de função sem levar o percentual zera a comissão dele.

### Colaboradores
- Tabela `colaboradores` (`2026_11_17_100400_...php:13-36`): `user_id`, `mechanic_id`, `cargos` (CSV), `ativo`. Modelo `Models/Colaborador.php:32-63` (`user()`, `cargosLista()`, `temCargo()`, `scopeComCargo()`).
- `Http/Controllers/ColaboradorController.php`: `store/update` (`:44-65`) chamam `syncRelacionados` (`:132-145`) que só leva a CNH ao técnico e registra a admissão. **Nada liga os cargos ao perfil do usuário.** `dados()` (`:87-126`) já valida os cargos contra `PAPEIS` e grava na ordem canônica. View `colaboradores/index.blade.php:35-40` (select de usuário) e `:56-63` (cargos).
- A tela do técnico resolve o mecânico por `users.mechanic_id` (`Http/Controllers/TecnicoController.php:147-150`); "Meu bônus" exige colaborador ligado ao usuário (`BonusController.php:208-221`).

### Multi-oficina / Mentor
- Cada oficina tem banco próprio (`Tenant/src/Http/Middleware/ResolveTenant.php:187-199`): **usuários e funções vivem em cada banco**. `Services/MentorService.php:33-48` (`oficinas()`: na raiz lista "Matriz" + oficinas ativas; dentro de uma oficina só ela) e `:202-234` (`naOficina`, troca a conexão). `WorkshopTickCommand.php:58-91` é o padrão para percorrer oficinas (`--tenant=`).
- `Http/Controllers/MentorController.php:42-58` tem **ações de escrita** (`replicarMetas`, `replicarPesos`) sob a mesma chave `mentor_mentoria` (`Config/acl.php:192-196`) da leitura.

### Testes e utilitários que tocam o assunto
`tests/Feature/Workshop/PerfisEAlertasPeriodicosTest.php:13-58` (acúmulo `chefe,tecnico`, `gerente,proprietario`, tela de papéis com `papel_inventado`), `CadastrosFichaTest.php:228-235` (acesso negado hoje = **401**), `TecnicoValoresTest.php` (cenário de OS sem valores), `Concerns/BuildsWorkshop.php:44` (zera `perfil_oficina` de todas as funções a cada teste) e `:105-117` (`makeRole`).
Padrões a reaproveitar: `Services/PrevisaoOs.php:17-25` (`disponivel()` com `Schema::hasColumn` memoizado), modelos só-inserção (`Models/MentoriaRegistro.php:103-112`, `Models/ServiceOrderEvent.php:108-116`), provider (`Providers/ServiceOrderServiceProvider.php:33` middleware no grupo `web`, `:83-85` comandos, `:118-120` `mergeConfigFrom`).

---

## Requisitos do PDF → como serão atendidos

Valores de `perfil_oficina` que o código já aceita (compatibilidade mantida, nada é renomeado): `proprietario`, `gerente`, `chefe`, `consultor`, `tecnico`, `pecas`, `financeiro`, `zeladoria`. Novos: `mentor`, `suporte`.

### A4-1 "Substituir as funções atuais pelos perfis ... manter Mentor (só leitura, todas as oficinas) e Suporte Volmaster (interno)" — **PARCIAL**
- Existe: 8 papéis em `PAPEIS`, vínculo função→papel (`perfil_oficina`), tela para marcar, consumidores.
- Falta: catálogo com Mentor/Suporte; criar as funções com permissões; matriz perfil→ACL; comportamento de Mentor e Suporte.
- Atendimento: `Config/perfis.php` + `PerfilCatalog` (catálogo e matriz) + `PerfilService::aplicar` (cria/atualiza as 10 funções) + tela `settings/perfis` + comando `workshop:perfis`. As funções antigas **não são apagadas nem esvaziadas**.
- **Mentor**: função `custom`, só chaves de leitura (`painel_dono`, `indicadores_mentoria`, `mentor_mentoria`), `ver_valores=false`, flag `somente_leitura` → middleware `PerfilSomenteLeitura` devolve 403 em qualquer método diferente de GET/HEAD/OPTIONS (exceto sair e as rotas de notificação listadas em `perfis.somente_leitura_permite`). "Todas as oficinas": o usuário Mentor mora no banco principal (Matriz), onde `MentorService::oficinas()` já lista todas as oficinas ativas; dentro de uma oficina o Mentor só enxerga aquela. Como o Painel do Dono de cada oficina é aberto pelo Mentor é assunto da F00 (interface: `PerfilCatalog::somenteLeitura($user)` e a chave de entrada `mentor_mentoria`).
- **Suporte Volmaster**: função `custom` com `incluir ['*']`, `ver_valores=false` (a trava de valores remove as chaves financeiras), `interno=true`: **não é cargo** (fora de `PAPEIS`, do select de colaboradores e da sincronização), não recebe alerta (nenhuma barreira o cita), não aprova alçada nem bônus (esses checks são por `proprietario`/`gerente`), e seus acessos às telas sensíveis já ficam registrados (LGPD, `RegistraAcessoSensivel.php:17-19`).

### A4-2 "Migrar os usuários existentes para o perfil equivalente. Programador vira Suporte Volmaster" — **FALTA**
- A migration antiga só rotulou `perfil_oficina`; nenhum usuário mudou de função e Diagnóstico/Programador ficaram sem perfil.
- Atendimento: o `plano()` sugere o destino por nome (config `origem`): **Administrador→Proprietário, Consultor→Consultor técnico, Diagnóstico→Chefe de oficina, Financeiro→Financeiro, Mecânico→Técnico, Programador→Suporte Volmaster** (aliases normalizados, sem acento e sem o sufixo " (antiga)"). Quando o nome não casa, usa o `perfil_oficina` já marcado na função (inclusive acumulado, ex.: `chefe,tecnico` → função combinada). Função sem equivalente **não é tocada**.
- O destino é **por usuário** (select na prévia; "Manter como está" é uma opção), porque a mesma função antiga pode ter gente de papéis diferentes (ex.: um "Administrador" que é da Volmaster deve ir para Suporte).
- Garantias "sem derrubar o acesso de ninguém": (a) nenhuma função é apagada/renomeada, exceto a colisão de nome descrita abaixo; (b) só move para função com permissões não vazias ou `all`; (c) o usuário que está aplicando só é movido se o destino for `all` (Proprietário); (d) nunca deixa a oficina sem nenhum usuário ativo com acesso total; (e) a comissão (`roles.comissao_percentual`) da função antiga é copiada para a nova quando esta não tem; (f) tudo em uma transação, com lote e botão **Desfazer** (devolve os usuários à função anterior).
- **Colisão de nome**: existe uma função antiga chamada "Financeiro". A nova também se chama "Financeiro" (nome do roteiro). Regra: a antiga é renomeada para "Financeiro (antiga)" (só o rótulo; registrada como `funcao_renomeada`); a nova leva o nome do roteiro. Vale para qualquer nome que colida (comparação sem acento/maiúscula).
- **Prévia (obrigatória antes de aplicar)**: por usuário mostra função atual → nova, o que perde/ganha de permissão (nomes legíveis), **"Valores em R$: Sim → Não"** quando for o caso, a visão de OS (`users.view_permission`: global/grupo/individual) com aviso se o perfil novo precisa ver todas as OS, comissão copiada e bloqueios. Há `assinatura` (sha1 do plano) para o "Aplicar" recusar uma prévia velha.
- **Registro do que mudou**: tabela nova `perfil_alteracoes` (só inserção), uma linha por função criada/atualizada/renomeada e por usuário movido, com `lote`, quem fez, de/para e detalhes.
- **Nunca automático**: nenhuma migration de banco move usuário ou cria função; a migration desta frente só cria a coluna/tabela.

### A4-3 "Acúmulo de cargos: soma das permissões, MAS Técnico sem outro perfil com valores continua sem valores" — **PARCIAL**
- Já existe o modelo (função com `perfil_oficina` CSV; resolver, alçada e entrada já tratam acúmulo) e a regra "sem valores" funciona por função (`ver_valores_financeiros`). **Falta** gerar a função certa e garantir a soma.
- **Representação recomendada: função combinada gerida** (uma função por conjunto de perfis, criada sob demanda, nome "Chefe de oficina + Técnico", `perfil_oficina = "chefe,tecnico"`, `gerenciada_em` preenchido, permissões calculadas). Justificativa:
  1. O Krayin tem **um** role por usuário; `Bouncer`, menu, `ValoresFinanceiros`, `PainelComissao`, `EntradaPorCargo`, `RecipientResolver` já leem esse único role. Zero mudança em pacote core.
  2. É exatamente o modelo que o capítulo 2 já adotou e testa (`PerfisEAlertasPeriodicosTest`).
  3. A soma sai de **uma só matriz**, por função pura e testável; a regra do Técnico é estrutural (ver abaixo), não depende de alguém desmarcar uma caixa.
  4. A sincronização com Colaboradores escolhe/cria a função sozinha; tudo auditável.
  - Alternativas descartadas: (B) vários roles por usuário (tabela pivô) exige alterar `User::hasPermission`, `Bouncer`, `ValoresFinanceiros`, `PainelComissao`, tela de Funções — invasivo e fora do `AGENTS.md`; (C) o admin montar a função combinada à mão (já é possível hoje) — errado com facilidade e sem garantia da regra.
- **Algoritmo da soma** (`PerfilCatalog::permissoes(array $perfis)`):
  1. Normaliza os perfis (válidos, únicos, ordem canônica). Vazio → `custom` sem permissões.
  2. Se algum perfil é `permission_type = all` → retorna `all` (acesso total e valores).
  3. Para **cada** perfil: conjunto = (`acl.incluir` expandido contra as chaves de `config('acl')`) + chaves-pai existentes − `acl.excluir`; **se o perfil tem `ver_valores=false`, remove também todas as `chaves_de_valores`** (e a `ver_valores_financeiros`).
  4. União dos conjuntos. Se algum perfil do conjunto tem `ver_valores=true`, garante `ver_valores_financeiros`; senão ela não existe. Ordena (JSON estável).
  - Resultado: `chefe+tecnico` e `zeladoria+tecnico` ficam **sem** valores; `consultor+tecnico` e `financeiro+tecnico` **com**; `proprietario+tecnico` é `all`. A trava vale mesmo que alguém erre a lista `incluir` do Técnico (a chave financeira é removida por perfil).
- Com o piloto desligado nada muda: `ValoresFinanceiros::can()` continua verdadeiro para todos.

### A4-4 "Colaboradores (cargos marcados) e perfil do usuário sincronizados" — **FALTA**
- Atendimento: `PerfilService::sincronizarColaborador(Colaborador $c, ?User $ator): array` chamado por `ColaboradorController::store/update` depois de salvar. Regras:
  1. Só age se `PerfilService::disponivel()` e `PerfilService::ativo()` (há ao menos uma função gerida, ou seja, o botão já foi usado). **Antes disso o comportamento é o de hoje.**
  2. Só age se o colaborador tem `user_id`. Os cargos esperados são a **união** dos cargos de todos os colaboradores **ativos** ligados ao mesmo usuário (somente os 8 cargos de trabalho). Sem cargos → não mexe ("Cargos salvos; perfil mantido").
  3. Se o conjunto esperado já é o conjunto de perfis da função atual do usuário → sem mudança (não troca função antiga por gerida à toa; quem migra funções antigas é o botão da tela).
  4. Senão: `funcaoPara($perfis)` (acha ou cria a função gerida), troca `users.role_id`, grava `perfil_alteracoes` (`usuario_sincronizado`, origem `colaborador`) e devolve mensagem.
  5. **Travas** (devolve `negado` com motivo, cargos continuam salvos): quem altera precisa de nível de gestão — Proprietário/`all`/Suporte altera qualquer um; **Gerente só atribui cargos operacionais** (`operacional=true` no catálogo) a usuários que hoje são operacionais; ninguém altera o **próprio** perfil; nunca tira o **único** Proprietário/acesso total; nunca mexe em usuário **interno** (Suporte/Mentor).
  6. Complemento pequeno e seguro: se o colaborador tem `mechanic_id` e o usuário não tem `mechanic_id`, preenche (nunca sobrescreve; só se nenhum outro usuário já usa aquele mecânico). Sem isso a função Técnico não vê as tarefas (`TecnicoController.php:147-150`).
  7. Desligar o colaborador (`desligar`) **não** desativa nem troca o usuário (não derruba acesso por engano); só avisa "O usuário X continua ativo; desative em Configurações > Usuários se for o caso".
- **Divergências**: a tela `Perfis` lista colaboradores cujo conjunto de cargos difere do perfil do usuário (ex.: alguém trocou a função em Configurações > Usuários) com botão "Aplicar os cargos do colaborador" (só Proprietário/Suporte). Fonte da verdade quando há colaborador ligado: **o cadastro de Colaboradores**.

### A4 "Teste: cada perfil entra e vê só o que o item B8 define" — **PARCIAL (depende da F05 para o menu)**
- F04 entrega: as 10 funções com matriz inicial coerente com a B8, o teste "todos entram" (roteiro 2), o teste "Técnico/Chefe+Técnico abrem a OS sem nenhum valor" e a estrutura que a F05 refina (`acl` por perfil). O desenho final de menu/rota/API 403 é da F05.

---

## Mudanças por arquivo

Convenção: caminhos do pacote relativos a `packages/Webkul/ServiceOrder/src`.

### CRIAR

#### 1. `Config/perfis.php`
Conteúdo inicial (a F05 refina somente as listas `acl`, `chaves_de_valores`, `somente_leitura_permite`; o resto é desta frente). Sem closures nem `trans()` (precisa sobreviver a `config:cache`).

```php
<?php

/*
 | Catálogo central dos perfis da mentoria (cargos) e matriz perfil -> permissões (ACL).
 | - Ordem de 'perfis' = ordem canônica (a mesma de roles.perfil_oficina "chefe,tecnico" e de colaboradores.cargos).
 | - As chaves são estáveis e já estão gravadas no banco: nunca renomear.
 | - acl.incluir / acl.excluir (chaves de config('acl')). Padrões: '*' = todas; 'a.b' = a.b e todas as filhas;
 |   'a.b*' = curinga (Str::is). Cada perfil é resolvido sozinho e só depois somado aos outros do acúmulo.
 | - ver_valores=false: o perfil NUNCA contribui com 'ver_valores_financeiros' nem com 'chaves_de_valores'.
 */
return [
    'perfis' => [
        'proprietario' => [
            'nome' => 'Proprietário', 'funcao' => 'Proprietário',
            'descricao' => 'Administrador da oficina: acessa tudo e vê todos os valores. Começa no Painel do Dono.',
            'cargo' => true, 'operacional' => false, 'interno' => false, 'somente_leitura' => false, 'visao_global' => true,
            'permission_type' => 'all', 'ver_valores' => true,
            'origem' => ['administrador', 'administrator', 'admin', 'proprietario', 'dono'],
            'entrada' => ['chave' => 'painel_dono', 'rota' => 'admin.mentoria.painel'],
            'acl' => ['incluir' => [], 'excluir' => []],
        ],
        'gerente' => [
            'nome' => 'Gerente', 'funcao' => 'Gerente',
            'descricao' => 'Acessa tudo da oficina, menos usuários, funções, perfis e a edição da Ficha. Vê valores.',
            'cargo' => true, 'operacional' => false, 'interno' => false, 'somente_leitura' => false, 'visao_global' => true,
            'permission_type' => 'custom', 'ver_valores' => true,
            'origem' => ['gerente', 'manager'],
            'entrada' => ['chave' => 'rotina_painel', 'rota' => 'admin.rotina.painel'],
            'acl' => ['incluir' => ['*'], 'excluir' => ['settings.user', 'settings.perfis', 'settings.ficha', 'settings.other_settings.api_keys', 'mentor_mentoria']],
        ],
        'chefe' => [
            'nome' => 'Chefe de oficina', 'funcao' => 'Chefe de oficina',
            'descricao' => 'Quadro de pátio, etapas de Diagnóstico a Veículo em Teste, Minha rotina, Registros de auditoria e Meu bônus. Sem valores em R$.',
            'cargo' => true, 'operacional' => true, 'interno' => false, 'somente_leitura' => false, 'visao_global' => true,
            'permission_type' => 'custom', 'ver_valores' => false,
            'origem' => ['diagnostico', 'chefe', 'chefe de oficina'],
            'entrada' => ['chave' => 'rotina_painel', 'rota' => 'admin.rotina.painel'],
            'acl' => ['incluir' => ['rotina_patio', 'diagnostico', 'orcamento', 'negociacao', 'em_execucao', 'veiculo_teste', 'rotina_painel', 'registros_mentoria', 'bonus_meu', 'service_orders.view', 'service_orders.edit', 'service_orders.auditar'], 'excluir' => []],
        ],
        'consultor' => [
            'nome' => 'Consultor técnico', 'funcao' => 'Consultor técnico',
            'descricao' => 'Agenda, Cadastro, todas as etapas da OS, Marketing, Minha rotina e Meu bônus. Vê valores.',
            'cargo' => true, 'operacional' => true, 'interno' => false, 'somente_leitura' => false, 'visao_global' => false,
            'permission_type' => 'custom', 'ver_valores' => true,
            'origem' => ['consultor', 'consultor tecnico'],
            'entrada' => ['chave' => 'rotina_painel', 'rota' => 'admin.rotina.painel'],
            'acl' => ['incluir' => ['rotina_agenda', 'cadastro', 'ordem_servico', 'diagnostico', 'orcamento', 'negociacao', 'em_execucao', 'veiculo_teste', 'faturamento', 'pos_venda', 'marketing', 'rotina_painel', 'bonus_meu', 'service_orders.view', 'service_orders.create', 'service_orders.edit', 'contacts.persons.view', 'contacts.persons.create', 'contacts.persons.edit', 'vehicles.view', 'vehicles.create', 'vehicles.edit'], 'excluir' => []],
        ],
        'tecnico' => [
            'nome' => 'Técnico', 'funcao' => 'Técnico',
            'descricao' => 'Minhas tarefas (cronômetro) e Meu bônus. Nunca vê valores em R$.',
            'cargo' => true, 'operacional' => true, 'interno' => false, 'somente_leitura' => false, 'visao_global' => false,
            'permission_type' => 'custom', 'ver_valores' => false,
            'origem' => ['mecanico', 'tecnico', 'eletricista', 'mecatronico'],
            'entrada' => ['chave' => 'tecnico', 'rota' => 'admin.tecnico.index'],
            'acl' => ['incluir' => ['tecnico', 'bonus_meu', 'service_orders.view'], 'excluir' => []],
        ],
        'pecas' => [
            'nome' => 'Peças', 'funcao' => 'Peças',
            'descricao' => 'Painel de Peças, Estoque, Grupos, Minha rotina e Meu bônus. Vê custos e preços.',
            'cargo' => true, 'operacional' => true, 'interno' => false, 'somente_leitura' => false, 'visao_global' => true,
            'permission_type' => 'custom', 'ver_valores' => true,
            'origem' => ['pecas', 'estoquista'],
            'entrada' => ['chave' => 'rotina_painel', 'rota' => 'admin.rotina.painel'],
            'acl' => ['incluir' => ['estoque', 'parts', 'grupos', 'part_categories', 'rotina_painel', 'bonus_meu', 'service_orders.view', 'service_orders.edit'], 'excluir' => []],
        ],
        'financeiro' => [
            'nome' => 'Financeiro', 'funcao' => 'Financeiro',
            'descricao' => 'Financeiro (todas as abas), Faturamento, Minha rotina e Meu bônus. Vê valores.',
            'cargo' => true, 'operacional' => true, 'interno' => false, 'somente_leitura' => false, 'visao_global' => true,
            'permission_type' => 'custom', 'ver_valores' => true,
            'origem' => ['financeiro'],
            'entrada' => ['chave' => 'rotina_painel', 'rota' => 'admin.rotina.painel'],
            'acl' => ['incluir' => ['financeiro_rotina', 'financeiro_dre', 'faturamento', 'rotina_painel', 'bonus_meu', 'service_orders.view', 'service_orders.edit', 'fiscal_documents'], 'excluir' => []],
        ],
        'zeladoria' => [
            'nome' => 'Auxiliar de copa e zeladoria', 'funcao' => 'Auxiliar de copa e zeladoria',
            'descricao' => 'Checklists da própria rotina e Meu bônus. Sem valores.',
            'cargo' => true, 'operacional' => true, 'interno' => false, 'somente_leitura' => false, 'visao_global' => false,
            'permission_type' => 'custom', 'ver_valores' => false,
            'origem' => ['zeladoria', 'auxiliar de copa e zeladoria', 'copa'],
            'entrada' => ['chave' => 'rotina_painel', 'rota' => 'admin.rotina.painel'],
            'acl' => ['incluir' => ['rotina_painel', 'bonus_meu'], 'excluir' => []],
        ],
        'mentor' => [
            'nome' => 'Mentor', 'funcao' => 'Mentor',
            'descricao' => 'Somente leitura: Visão do mentor e Painel do Dono. Sem valores em R$. Nunca grava nada.',
            'cargo' => false, 'operacional' => false, 'interno' => true, 'somente_leitura' => true, 'visao_global' => true,
            'permission_type' => 'custom', 'ver_valores' => false,
            'origem' => ['mentor'],
            'entrada' => ['chave' => 'mentor_mentoria', 'rota' => 'admin.mentor.index'],
            'acl' => ['incluir' => ['painel_dono', 'indicadores_mentoria', 'mentor_mentoria'], 'excluir' => []],
        ],
        'suporte' => [
            'nome' => 'Suporte Volmaster', 'funcao' => 'Suporte Volmaster',
            'descricao' => 'Interno da Volmaster: configura e dá suporte. Não vê valores financeiros e não conta como colaborador.',
            'cargo' => false, 'operacional' => false, 'interno' => true, 'somente_leitura' => false, 'visao_global' => true,
            'permission_type' => 'custom', 'ver_valores' => false,
            'origem' => ['programador', 'suporte', 'suporte volmaster'],
            'entrada' => null,
            'acl' => ['incluir' => ['*'], 'excluir' => []],
        ],
    ],

    // Chaves cujas telas/APIs entregam dinheiro. Só perfis com ver_valores=true as contribuem.
    'chaves_de_valores' => [
        'ver_valores_financeiros', 'dashboard.faturamento_geral', 'comissoes', 'marketing', 'financeiro_rotina',
        'financeiro_dre', 'bonus_dono', 'colaboradores', 'settings.alcadas', 'alcadas', 'fiscal_documents',
    ],

    // Rotas que um perfil somente leitura ainda pode chamar com POST/PUT/DELETE (padrões Str::is).
    'somente_leitura_permite' => ['admin.session.destroy', 'admin.workshop.push.*', 'admin.workshop.alerts.*'],

    // Sufixo da função antiga quando o nome colide com o da nova.
    'sufixo_funcao_antiga' => ' (antiga)',
];
```
Observações para a F05: `acl.incluir` aceita chave-filha sem a mãe (a mãe é acrescentada automaticamente se existir no ACL e não estiver em `excluir`); `service_orders.view/edit` trazem `service_orders` como pai — se o Técnico não deve abrir a lista de OS, a F05 põe `service_orders` em `excluir` (a exclusão roda **depois** da expansão dos pais). Itens que o B8 não cita e ficam fora da matriz inicial (Contatos, Veículos, Atendimentos Remotos, Solicitações do portal, Catálogo de serviços, Mecânicos, Chaves de API...) são decisão da F05/patrão (ver "Decisões em aberto").

#### 2. `Services/Perfis/PerfilCatalog.php` (estático, sem estado, só lê `config('perfis')` e `config('acl')`)
Assinaturas:
```php
public static function todos(): array;                       // chave => definição (ordem canônica)
public static function chaves(): array;                      // 10 chaves
public static function nomes(): array;                       // chave => nome (os 10)
public static function cargos(): array;                      // chave => nome, só 'cargo' => true (== WorkshopSettingsController::PAPEIS)
public static function existe(string $chave): bool;
public static function nome(string $chave): string;
public static function normalizar(string|array|null $perfis): array;   // válidos, únicos, ordem canônica; aceita "chefe, tecnico "
public static function csv(string|array|null $perfis): ?string;        // "chefe,tecnico" ou null se vazio
public static function doUsuario(?\Webkul\User\Models\User $user = null): array;   // perfis da função do usuário (logado se null)
public static function temPerfil(?User $user, string ...$perfis): bool;            // qualquer um
public static function nivelDeGestao(?User $user): int;      // 3 = função 'all' ou proprietario/suporte; 2 = gerente; 0 = demais
public static function somenteLeitura(?User $user = null): bool;  // tem perfis e TODOS são somente_leitura
public static function interno(?User $user = null): bool;         // tem perfis e TODOS são interno
public static function nomeDaFuncao(array $perfis): string;        // 1 perfil: 'funcao'; vários: "Chefe de oficina + Técnico"
public static function descricaoDaFuncao(array $perfis): string;   // junta descrições + frase de valores ("Valores em R$: ocultos")
public static function perfilPorNomeDeFuncao(string $nome): ?string; // 'Mecânico' => 'tecnico'; ignora acento/maiúscula e o sufixo " (antiga)"
public static function permissoes(array $perfis): array;  // ['tipo' => 'all'|'custom', 'permissoes' => ?string[], 'ver_valores' => bool]
public static function verValores(array $perfis): bool;
public static function entrada(array $perfis): ?array;    // primeiro 'entrada' não nulo na ordem proprietario > ... (para a F05/EntradaPorCargo)
public static function todasAsChavesAcl(): array;         // collect(config('acl'))->pluck('key')->unique()
public static function casa(string $padrao, string $chave): bool;  // '*', 'a.b' (e filhas) ou curinga Str::is
public static function nomeDaChave(string $chave): string;  // "Ordens de Serviço › Visualizar" (nomes traduzidos de cada prefixo existente)
```
`nivelDeGestao` é o único lugar com a regra "quem é nível dono": `role->permission_type === 'all'` **ou** perfis contêm `proprietario`/`suporte` → 3; `gerente` → 2. (Os 8 leitores existentes de `perfil_oficina` **não** precisam ser trocados agora; novas partes devem usar `PerfilCatalog::doUsuario()`. Se uma frente já está editando um desses arquivos, pode trocar o `explode` por `PerfilCatalog::doUsuario($user)` sem mudar comportamento.)

#### 3. `Services/Perfis/PerfilService.php`
```php
public static function disponivel(): bool;   // Schema::hasColumn('roles','perfil_oficina') && hasColumn('roles','gerenciada_em') && hasTable('perfil_alteracoes'), memoizado em static (padrão PrevisaoOs)
public static function esquecer(): void;     // zera o memo (comando percorrendo oficinas, testes)
public function ativo(): bool;               // disponivel() && existe Role com gerenciada_em
public function situacao(): array;           // por perfil: ['role' => ?Role, 'usuarios' => int, 'estado' => 'criada'|'falta'|'diferente']
public function plano(array $opcoes = [], ?User $por = null): array;
public function aplicar(array $opcoes = [], ?User $por = null, string $origem = 'tela'): array;
public function desfazer(string $lote, ?User $por = null, string $origem = 'tela'): array;
public function funcaoPara(array $perfis, ?string $lote = null, ?User $por = null, string $origem = 'colaborador'): Role;
public function sincronizarColaborador(Colaborador $colaborador, ?User $ator = null): array;
public function divergencias(): Collection;
public function historico(int $limite = 30): Collection;
```
Formas de retorno:
- `$opcoes`: `['migrar_usuarios' => bool, 'usuarios' => [user_id => 'csv de perfis' | ''], 'manter' => [perfil, ...], 'assinatura' => ?string]`. `usuarios[id] = ''` = manter como está; ausente = usar a sugestão.
- `plano()` →
  ```php
  [
    'assinatura' => 'sha1...',
    'funcoes' => [ ['perfis' => ['gerente'], 'nome' => 'Gerente', 'acao' => 'criar'|'atualizar'|'igual', 'role_id' => ?int,
                     'adicionar' => [chave...], 'remover' => [chave...], 'tipo_muda' => bool, 'renomear_antiga' => ?['id' => 5, 'de' => 'Financeiro', 'para' => 'Financeiro (antiga)'], 'manter' => bool], ... ],
    'usuarios' => [ ['user_id', 'nome', 'email', 'ativo', 'funcao_atual' => ['id','nome','perfis'], 'sugestao' => ?csv, 'destino' => csv|'',
                      'funcao_nova' => ['id' => ?int, 'nome', 'criar' => bool], 'perde' => [rótulos], 'ganha' => [rótulos], 'perde_n', 'ganha_n',
                      'valores' => ['antes' => bool, 'depois' => bool], 'visao' => 'global|group|individual', 'precisa_visao_global' => bool,
                      'comissao' => ['antes' => ?float, 'depois' => ?float, 'copia' => bool], 'bloqueio' => ?string, 'avisos' => [string]], ... ],
    'sem_equivalente' => [ ['role_id', 'nome', 'usuarios' => int] ],
    'avisos' => [string],
  ]
  ```
- `aplicar()` → `['lote' => uuid, 'criadas' => n, 'atualizadas' => n, 'renomeadas' => n, 'usuarios_movidos' => n, 'usuarios_ignorados' => [['user_id', 'motivo']]]`. Lança `\DomainException('A prévia ficou desatualizada...')` se `assinatura` foi enviada e não bate com o plano recalculado.
- `sincronizarColaborador()` → `['status' => 'aplicado'|'sem_mudanca'|'ignorado'|'negado', 'mensagem' => ?string, 'role_id' => ?int]`.

Regras de implementação:
- **Função gerida de um conjunto**: `Role` com `gerenciada_em` não nulo e `perfil_oficina = csv`. Criar com `Role::unguarded(fn () => Role::create([...]))` (o `$fillable` não tem `perfil_oficina`/`gerenciada_em`): `name = nomeDaFuncao`, `description = descricaoDaFuncao`, `permission_type`/`permissions` de `PerfilCatalog::permissoes()` (`all` → `permissions = null`), `perfil_oficina = csv`, `gerenciada_em = now()`. **Atualizar** só `permission_type`, `permissions`, `gerenciada_em` (não renomeia, não mexe em descrição nem em `comissao_percentual`, para respeitar o que o admin ajustou). Atualiza **todas** as funções gerida (simples e combinadas) quando a matriz muda.
- **Colisão de nome** ao criar: procura função **não gerida** com mesmo nome normalizado (`Str::ascii`, minúscula, sem o sufixo); se achar, renomeia para `nome + sufixo_funcao_antiga` (se esse nome já existe, usa " (antiga 2)"...) e registra `funcao_renomeada`.
- **Sugestão de destino** de cada usuário (função não gerida com usuários): 1) `perfilPorNomeDeFuncao(nome)`; 2) senão `PerfilCatalog::normalizar($role->perfil_oficina)` (se vazio → "sem equivalente", não toca). Se a função tem `perfil_oficina` diferente do sugerido pelo nome, `avisos[] = "marcada como X; sugerida Y pelo nome"`.
- **Bloqueios** (`bloqueio` preenchido = o usuário não é movido): (a) é o usuário que aplica e a função nova não é `all`; (b) a mudança deixaria a oficina sem usuário ativo de acesso total (simula o resultado); (c) função de destino sem permissões (erro de matriz).
- **Comissão**: ao criar/mover, se a função nova tem `comissao_percentual` nulo e todas as funções antigas que mapeiam para ela têm o mesmo valor, copia (`comissao_copiada`); valores diferentes → aviso "defina em Funções", sem copiar.
- `aplicar()` roda em `DB::transaction`; gera `lote = (string) Str::uuid()`; grava `perfil_alteracoes` para cada ação; `WorkshopSettings` não é tocado; **nunca** chama `delete`.
- **Desfazer**: para cada `usuario_migrado`/`usuario_sincronizado` do lote em que `users.role_id == role_novo_id` ainda, volta para `role_anterior_id` e grava `usuario_revertido` (`detalhes.desfaz = lote`); quem mudou depois é listado como não revertido. Funções criadas/atualizadas **ficam** (nada se apaga); o `detalhes` da atualização guarda `permissoes_antes` para restauro manual.
- **`podeAtribuir`** (usado na sincronização): ator nulo (console) pode tudo; `nivelDeGestao >= 3` pode, exceto tirar de si mesmo o nível dono ou tirar o **único** Proprietário; `nivelDeGestao == 2` (gerente) só se ator ≠ usuário, perfis novos ⊆ operacionais e perfis atuais do usuário ⊆ operacionais (ou vazios); resto, não. Usuário `interno` nunca é sincronizado.
- **`divergencias()`**: para cada colaborador ativo com `user_id`, esperado = união de cargos dos colaboradores ativos do usuário (só `cargo=true`); atual = `normalizar(role->perfil_oficina)`; diverge se esperado ≠ ∅ e ≠ atual (como conjuntos). Só quando `ativo()`.
- Tolerância: se `disponivel()` é falso, `plano/aplicar/desfazer/sincronizar` não gravam (aplicar lança `DomainException` com a mensagem de "rode as atualizações do banco"); `sincronizarColaborador` devolve `ignorado` em silêncio; a tela mostra o aviso. Nada quebra com coluna ausente.

#### 4. `Models/PerfilAlteracao.php`
Só inserção (padrão `MentoriaRegistro`): `CREATED_AT = 'criado_em'`, `UPDATED_AT = null`, `$table = 'perfil_alteracoes'`, `$guarded = []`, casts `detalhes => array`, `criado_em => datetime`; `booted()` lança `LogicException` em `updating` e `deleting`. Constante `TIPOS` (rótulos pt-BR): `funcao_criada`, `funcao_atualizada`, `funcao_renomeada`, `usuario_migrado`, `usuario_sincronizado`, `usuario_revertido`, `comissao_copiada`. `public static function registrar(string $lote, string $tipo, array $dados, string $origem = 'tela', ?int $por = null): self` (usa o usuário logado se `$por` nulo). Relações `usuario()` (feito_por) e `afetado()` (user_id).

#### 5. `Database/Migrations/2026_11_18_104000_create_perfil_alteracoes_and_add_gerenciada_em_to_roles.php`
Ver seção "Banco (migrations)". (HHMMSS `104000` reservado à F04; se houver choque com outra frente, usar `1041xx`.)

#### 6. `Http/Controllers/PerfisController.php`
Estende `Webkul\Admin\Http\Controllers\Controller`; construtor com `PerfilService`.
- `index(Request $request): View` — `GET settings/perfis`. Mostra catálogo + situação, tabela "quem está em qual função", divergências, histórico e (com `?previa=1`) a prévia. Query da prévia: `previa=1&migrar=1&usuarios[ID]=csv&manter[]=perfil`.
- `aplicar(Request $request): RedirectResponse` — `POST settings/perfis/aplicar`. Valida `assinatura` (obrigatória), `confirmo` (`accepted`), `usuarios.*` (`regex:/^[a-z,]*$/` com array de regras), `manter.*` (`in:` chaves do catálogo). `abort_unless(PerfilCatalog::nivelDeGestao($ator) >= 3, 403, 'Só o Proprietário ou o Suporte Volmaster aplica os perfis.')`. Em `DomainException` (prévia velha) → `session()->flash('error', ...)` e volta à prévia. Sucesso → `session()->flash('success', "Funções da mentoria aplicadas: {$c} criadas, {$a} atualizadas, {$u} usuários movidos. Lote {$codigo}.")`.
- `desfazer(string $lote): RedirectResponse` — `POST settings/perfis/desfazer/{lote}` (mesmo `abort_unless`). Flash: "Migração desfeita: N usuários voltaram à função anterior."
- `aplicarColaborador(int $id): RedirectResponse` — `POST settings/perfis/colaboradores/{id}/aplicar` (mesmo `abort_unless`), chama `sincronizarColaborador` e mostra o resultado.
Não usar aspas duplas nem quebras de linha nos textos de flash (o componente de flash do Krayin põe a mensagem entre aspas duplas em JavaScript).

#### 7. `Resources/views/settings/perfis.blade.php`
`<x-admin::layouts>` com as mesmas classes de `settings/workshop.blade.php` (`$card`, `$h`, `$hint`, `$input`). Blocos:
1. Cabeçalho + avisos (banco sem atualização; piloto desligado).
2. Tabela "Perfis" (10 linhas): Perfil, O que acessa (descrição), Valores em R$ (Sim/Não), Função no sistema (nome + nº de usuários), Situação (Criada / Falta criar / Diferente do padrão).
3. Formulário GET "Ver prévia": checkbox "Passar também os usuários das funções antigas para as novas" (marcado) + botão. Sem JavaScript.
4. Prévia (quando houver): (a) tabela "Funções" (ação, permissões a adicionar/remover, renomear antiga, checkbox "Manter meus ajustes nesta função"); (b) tabela "Usuários" (uma linha por usuário com `select name="usuarios[ID]"`: sugestão, os 10 perfis, "Manter como está"), colunas Perde/Ganha, Valores (vermelho quando Sim→Não), Visão das OS, Comissão, Bloqueio/Avisos; botão "Atualizar prévia"; (c) lista "Funções antigas sem equivalente"; (d) formulário POST "Aplicar" com `assinatura`, as mesmas escolhas em campos ocultos, checkbox obrigatório "Entendi que isto muda o acesso das pessoas listadas" e botão "Aplicar agora".
5. "Cargos do colaborador × perfil do usuário" (divergências) com botão "Aplicar os cargos".
6. "Últimas alterações" (30) agrupadas por lote, com "Desfazer a migração dos usuários deste lote" quando houver `usuario_migrado` ainda reversível.

#### 8. `Console/Commands/WorkshopPerfisCommand.php`
```
php artisan workshop:perfis
    {--aplicar : Grava (sem isto só mostra a prévia)}
    {--migrar-usuarios : Também passa os usuários das funções antigas para as novas}
    {--usuario=* : id:perfil[,perfil]  (id: vazio = manter como está)}
    {--manter=* : perfil cuja função gerida tem ajustes manuais a preservar}
    {--tenant= : subdomínio de uma única oficina}
    {--todas : instalação principal e todas as oficinas ativas}
    {--desfazer= : código do lote cuja migração de usuários será desfeita}
    {--force : obrigatório com --todas --aplicar}
```
Padrão de `WorkshopTickCommand.php:58-91` (copiar `contexts()`/`runFor()`: trocar a conexão, `DB::purge`, `WorkshopSettings::flush()`, `PerfilService::esquecer()`; restaurar no `finally`); se a F00 entregar um executor "por oficina", usar o dela. Sem `--tenant`/`--todas` age só no banco da conexão atual. Imprime tabelas (`$this->table`) da prévia, "Nada foi gravado (prévia). Use --aplicar para gravar." ou o resumo com o `Lote`. Erro numa oficina é impresso e a próxima segue; retorno `FAILURE` se alguma falhou. `por = null`, origem `comando`.

#### 9. `Http/Middleware/PerfilSomenteLeitura.php`
Para métodos fora de GET/HEAD/OPTIONS: se `PerfilCatalog::somenteLeitura(auth()->guard('user')->user())` e o nome da rota não casa (`Str::is`) com `config('perfis.somente_leitura_permite')` → `abort(403, 'Seu perfil é somente leitura: nenhuma alteração é permitida.')`. Registrar com `$this->app['router']->pushMiddlewareToGroup('web', PerfilSomenteLeitura::class)` (mesmo padrão de `EntradaPorCargo`, linha 33 do provider). **Não depende do piloto**: o perfil é novo e não existe usuário nele antes de alguém criá-lo (única exceção deliberada à regra do piloto, sem efeito sobre quem usa o sistema hoje).

#### 10. Testes — ver seção "Testes Pest a escrever" (`tests/Feature/Workshop/PerfisMentoriaTest.php` e `tests/Feature/Workshop/PerfisSincroniaTest.php`).

### ALTERAR (menor edição possível; vários são arquivos compartilhados)

1. **`Providers/ServiceOrderServiceProvider.php`** — (a) `registerConfig()`: `$this->mergeConfigFrom(dirname(__DIR__).'/Config/perfis.php', 'perfis');` (junto da linha 118-120); (b) `boot()`: `pushMiddlewareToGroup('web', PerfilSomenteLeitura::class)` ao lado da linha 33; (c) `$this->commands([... , WorkshopPerfisCommand::class])` na linha 84 (+ `use`).
2. **`Routes/web.php`** — `use ...PerfisController;` e, depois do bloco `settings/workshop` (linhas 133-142):
   ```php
   Route::controller(PerfisController::class)->prefix('settings/perfis')->group(function () {
       Route::get('', 'index')->name('admin.perfis.index');
       Route::post('aplicar', 'aplicar')->name('admin.perfis.aplicar');
       Route::post('desfazer/{lote}', 'desfazer')->name('admin.perfis.desfazer');
       Route::post('colaboradores/{id}/aplicar', 'aplicarColaborador')->name('admin.perfis.colaborador');
   });
   ```
3. **`Config/acl.php`** — nova entrada (final do arquivo):
   ```php
   [
       'key' => 'settings.perfis',
       'name' => 'service_order::app.acl.settings_perfis',
       'route' => ['admin.perfis.index', 'admin.perfis.aplicar', 'admin.perfis.desfazer', 'admin.perfis.colaborador'],
       'sort' => 17,
   ],
   ```
4. **`Config/menu.php`** — dois itens de Configurações no padrão existente (`settings.workshop`, linhas 185-198): `settings.perfis` (nome "Perfis da mentoria", `info` "Funções de acesso por cargo: criar, atualizar e migrar os usuários", rota `admin.perfis.index`, `sort` 11) e `settings.perfis.funcoes` (filho, `sort` 1, `icon-class` `icon-setting`).
5. **`Http/Controllers/WorkshopSettingsController.php`** —
   - `PAPEIS` (linhas 24-33): **mantida como está** (constante não pode chamar função); comentário "fonte da verdade: `Config/perfis.php`; teste de igualdade em `PerfisMentoriaTest`".
   - `edit()` (linhas 37-53): `'roles'` passa a trazer `gerenciada_em` quando `PerfilService::disponivel()`; `'papeis' => PerfilCatalog::nomes()` (10); `'perfisResumo' => ['disponivel' => bool, 'criadas' => int, 'total' => 10]`.
   - `updateRoles()` (linhas 164-195): (a) validar contra `PerfilCatalog::chaves()` (10) em vez de `PAPEIS`; a gravação continua na ordem canônica (o teste existente espera `chefe,tecnico`); (b) **ignorar função gerida** (não vem no POST porque o checkbox fica `disabled`; e no servidor pular `roles.gerenciada_em` não nulo); (c) só aplica a parte `papel` se `PerfilCatalog::nivelDeGestao($ator) >= 3`; senão grava só os celulares e `flash('warning', 'Somente o Proprietário ou o Suporte Volmaster altera os papéis das funções.')` (fecha a brecha de um Gerente com `settings.workshop` marcar uma função como `proprietario`).
6. **`Resources/views/settings/workshop.blade.php`** — (a) novo cartão "Perfis da mentoria (funções de acesso)" entre "Barreiras" e "Papéis e celulares", visível só com `bouncer()->hasPermission('settings.perfis')`: texto, resumo "N de 10 funções da mentoria criadas" e botão/link "Criar/atualizar funções da mentoria" → `route('admin.perfis.index')`; (b) no cartão "Papéis e celulares" (linhas 163-204): os checkboxes passam a listar os 10 papéis e, para função gerida, ficam `disabled` com a legenda "Função gerida pela mentoria".
7. **`Http/Controllers/ColaboradorController.php`** — `store()`/`update()` (linhas 44-65): depois de `syncRelacionados`, `$resultado = app(PerfilService::class)->sincronizarColaborador($colaborador, auth()->guard('user')->user());` e flash adicional (`success` quando `aplicado`, `warning` quando `negado`). `desligar()` (71-82): se o colaborador tem usuário ativo, `flash('warning', 'O usuário X continua ativo; desative em Configurações > Usuários se for o caso.')`. `index()`/`edit()` (22-42): `usuarios` sem usuários **internos** (Suporte/Mentor) e `'perfisAtivos' => app(PerfilService::class)->ativo()`, `'divergentes' => ids dos colaboradores divergentes`.
8. **`Resources/views/colaboradores/index.blade.php`** — dica abaixo do select de usuário (linha 36-40), mostrada só se `$perfisAtivos`: "Ao salvar, o perfil de acesso desse usuário passa a seguir os cargos marcados abaixo."; na tabela, selo "perfil do usuário diferente dos cargos" para os divergentes.
9. **`Resources/lang/pt_BR/app.php` e `Resources/lang/en/app.php`** — `acl.settings_perfis`: pt "Perfis da mentoria (criar funções e migrar usuários)"; en "Mentoring profiles (create roles and migrate users)".
10. **`tests/Feature/Workshop/Concerns/BuildsWorkshop.php`** — (a) em `setUpWorkshop()` chamar `PerfilService::esquecer();`; (b) novo helper aditivo, para a F05 e as demais frentes testarem com a matriz oficial em vez de listas à mão:
    ```php
    protected function makeUserPerfil(string|array $perfis, array $extra = []): User
    {
        $role = app(PerfilService::class)->funcaoPara((array) $perfis);
        return User::create(array_merge([
            'name' => 'Perfil '.Str::random(4), 'email' => Str::random(8).'@teste.local', 'password' => bcrypt('senha-teste'),
            'status' => 1, 'role_id' => $role->id, 'view_permission' => 'global',
        ], $extra));
    }
    ```
11. **Opcionais, fora do caminho crítico (não fazer agora)**: trocar os 8 `explode` por `PerfilCatalog::doUsuario()`; `IndicatorCalculator::quadro()` (`:1074-1077`) contar usuários internos enquanto não há colaboradores (resolvido ao cadastrar os colaboradores, A5).

**Não alterar**: `WorkshopSettingsController::PAPEIS` (valores), `FichaEnquadramento::CARGOS_OBRIGATORIOS`, `BarrierRule::DESTINOS`, `IndicatorCatalog::CARGOS`, `ValoresFinanceiros`, `EntradaPorCargo` (a F05 decide se passa a ler `PerfilCatalog::entrada()`), os pacotes User/Admin/Core.

---

## Banco (migrations)

**`Database/Migrations/2026_11_18_104000_create_perfil_alteracoes_and_add_gerenciada_em_to_roles.php`** — aditiva, idempotente, sem mexer em dados:
```php
public function up(): void
{
    if (Schema::hasTable('roles') && ! Schema::hasColumn('roles', 'gerenciada_em')) {
        Schema::table('roles', function (Blueprint $table) {
            // Função criada/mantida pela ação "Criar/atualizar funções da mentoria" (data da última sincronia).
            $table->dateTime('gerenciada_em')->nullable();
        });
    }

    if (! Schema::hasTable('perfil_alteracoes')) {
        Schema::create('perfil_alteracoes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('lote', 36)->index();                 // uuid: um clique = um lote
            $table->string('tipo', 24);                          // ver PerfilAlteracao::TIPOS
            $table->unsignedInteger('role_id')->nullable()->index();   // função criada/atualizada/renomeada
            $table->unsignedInteger('user_id')->nullable()->index();   // usuário afetado
            $table->unsignedInteger('role_anterior_id')->nullable();
            $table->unsignedInteger('role_novo_id')->nullable();
            $table->string('perfis_anteriores', 120)->nullable();
            $table->string('perfis_novos', 120)->nullable();
            $table->json('detalhes')->nullable();                // adicionadas/removidas, permissoes_antes, nome antigo, comissão, motivo
            $table->string('origem', 12);                        // tela | comando | colaborador
            $table->unsignedInteger('feito_por')->nullable();
            $table->timestamp('criado_em')->useCurrent();
            $table->index(['tipo', 'criado_em']);
        });
    }
}

public function down(): void
{
    Schema::dropIfExists('perfil_alteracoes');
    if (Schema::hasColumn('roles', 'gerenciada_em')) {
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('gerenciada_em'));
    }
}
```
Sem chave estrangeira (o histórico sobrevive a qualquer exclusão). `perfil_oficina` e `comissao_percentual` já existem; nenhuma outra coluna nova; nenhuma chave nova em `WorkshopSettings`.

**Tolerância a coluna/tabela ausente** (o servidor só recebe a migration quando rodar o `migrate-safe.php`; **oficinas com banco próprio só recebem quando a F00 entregar o caminho de migração por oficina**):
- `PerfilService::disponivel()` (memoizado) protege tudo: tela mostra o aviso "Esta oficina ainda não recebeu a atualização do banco dos perfis. Rode as atualizações (migrate-safe) e volte aqui."; `sincronizarColaborador` vira no-op silencioso; `WorkshopSettingsController::edit` só consulta `gerenciada_em` se disponível.
- Telas por onde todo usuário passa (login, menu, OS, Colaboradores) **não** dependem das colunas novas; `PerfilCatalog::doUsuario()` só lê `perfil_oficina` (já existe).
- **Banco de teste**: aplicar antes dos testes: `DB_DATABASE=krayin_test php artisan migrate --path=packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_104000_create_perfil_alteracoes_and_add_gerenciada_em_to_roles.php`. Não rodar no `krayin_crm` sem instrução.
- Como `migrations` e `DatabaseTransactions` não se misturam (DDL confirma a transação), nenhum teste faz DDL; o cenário "coluna ausente" é simulado zerando o memo estático por reflexão.

---

## ACL, menu, rotas e configurações

- **ACL nova**: `settings.perfis` ("Perfis da mentoria (criar funções e migrar usuários)"), cobrindo `admin.perfis.index|aplicar|desfazer|colaborador`. Quem recebe: Proprietário (`all`) e Suporte (`*`). **Gerente: não** (excluído na matriz). Defesa em profundidade: o controller também exige `PerfilCatalog::nivelDeGestao($ator) >= 3`.
- **Menu**: `settings.perfis` + filho `settings.perfis.funcoes` (Configurações). Lembrete (limite já existente): para função `custom` o item só aparece na tela de Configurações se a chave-filha existir no ACL; hoje só funções `all` veem esses cartões. Como Proprietário é `all`, atende; o Suporte (`custom`) acessa por endereço/atalho do cartão em "Indicadores e alertas" (que usa `settings.workshop`) até a F05 resolver o menu de Configurações para funções `custom` de forma geral.
- **Rotas novas**: `admin.perfis.index` (GET `settings/perfis`), `admin.perfis.aplicar` (POST), `admin.perfis.desfazer` (POST `{lote}`), `admin.perfis.colaborador` (POST `colaboradores/{id}/aplicar`).
- **Perfis (valores de `perfil_oficina`)**: novos `mentor`, `suporte`; os 8 existentes inalterados.
- **Config nova**: `config('perfis')` (`perfis`, `chaves_de_valores`, `somente_leitura_permite`, `sufixo_funcao_antiga`). **Nenhuma** chave nova de `WorkshopSettings`; nenhum assunto de alçada; nenhum código de barreira; nenhum tipo de evento de OS.
- **Comando**: `workshop:perfis`.
- **Middleware**: `PerfilSomenteLeitura` (grupo `web`).
- **Proprietário = `permission_type: all`** (decisão): não derruba ninguém (a função "Administrador" de hoje também é `all`), ganha automaticamente toda chave nova de ACL que as outras frentes criarem (Painel de Peças, etc.), e `ValoresFinanceiros`/`PainelComissao` já tratam `all`. Dentro de uma oficina a "Visão do mentor" mostra só a própria oficina (`MentorService::oficinas()`), então não há vazamento entre oficinas; se o patrão não quiser ver o item no menu do Proprietário, a F05 esconde por regra de menu (ACL não consegue esconder de `all`).
- **Gerente/Suporte usam curinga `*`**: chaves novas entram sozinhas **depois** que alguém roda "Criar/atualizar funções" de novo (a ação é o mecanismo de atualização). Anotar na documentação: depois de cada deploy que cria chave de ACL, rodar a ação (prévia mostra "+N permissões").

---

## Textos da interface (pt-BR)

**Cartão em "Indicadores e alertas"**
- Título: `Perfis da mentoria (funções de acesso)`
- Texto: `As funções de acesso seguem os cargos da mentoria: Proprietário, Gerente, Chefe de oficina, Consultor técnico, Técnico, Peças, Financeiro e Auxiliar de copa e zeladoria, mais o Mentor (somente leitura) e o Suporte Volmaster (interno). As funções antigas continuam existindo; nada é apagado.`
- Resumo: `{n} de 10 funções da mentoria criadas nesta oficina`
- Botão: `Criar/atualizar funções da mentoria`

**Tela `Configurações > Perfis da mentoria`**
- Título: `Perfis da mentoria`; subtítulo: `Crie ou atualize a função de acesso de cada cargo e, se quiser, passe os usuários das funções antigas para as novas. Nada é apagado e tudo fica registrado.`
- Avisos: `Esta oficina ainda não recebeu a atualização do banco dos perfis. Rode as atualizações (migrate-safe) e volte aqui.` / `O piloto está desligado nesta oficina: o fluxo segue como sempre, mas as permissões de menu das novas funções valem assim que o usuário for movido para elas. Sem valores em R$ só passa a valer com o piloto ligado.`
- Situações: `Criada`, `Falta criar`, `Diferente do padrão (será restaurada)`.
- Formulário: `Passar também os usuários das funções antigas para as novas` / `Ver prévia do que vai mudar` / `Atualizar prévia` / `Manter meus ajustes nesta função` / `Manter como está` / `Entendi que isto muda o acesso das pessoas listadas` / `Aplicar agora`.
- Prévia, títulos e colunas: `Funções`, `Usuários`, `Funções antigas sem equivalente (não serão mexidas)`, `Função atual`, `Vai para`, `Perde`, `Ganha`, `Valores em R$`, `Visão das OS`, `Comissão`, `Bloqueio`, `Nada a fazer: as funções já estão como o padrão.`
- Avisos de linha: `Passa a NÃO ver valores em R$`, `Só enxerga as próprias OS ({visao}); este perfil normalmente precisa ver todas. Ajuste em Configurações > Usuários`, `Comissão de {x}% será copiada para a nova função`, `As funções de origem têm comissões diferentes; defina em Funções`, `Você não será movido(a) por aqui para não perder o acesso a esta tela; peça a outro Proprietário ou use o comando`, `A oficina ficaria sem nenhum usuário com acesso total`, `Marcada como {x}; sugerida {y} pelo nome`.
- Mensagens: sucesso `Funções da mentoria aplicadas: {c} criadas, {a} atualizadas, {u} usuários movidos. Lote {codigo}.`; erro `Algo mudou desde a prévia. Veja a prévia de novo antes de aplicar.`; desfazer `Migração desfeita: {n} usuários voltaram à função anterior.`; 403 `Só o Proprietário ou o Suporte Volmaster aplica os perfis.`
- Divergências: título `Cargos do colaborador × perfil do usuário`, botão `Aplicar os cargos do colaborador`. Histórico: `Últimas alterações`, `Desfazer a migração dos usuários deste lote`.

**Colaboradores**
- Dica: `Ao salvar, o perfil de acesso desse usuário passa a seguir os cargos marcados abaixo.`
- Sucesso: `Perfil de {nome} atualizado para {função}.`
- Aviso: `Cargos salvos, mas o perfil de acesso de {nome} não foi alterado: {motivo}.` Motivos: `só o Proprietário altera o perfil de um Gerente ou Proprietário`, `você não pode alterar o seu próprio perfil`, `ele é o único Proprietário desta oficina`, `é um usuário interno da Volmaster`, `o colaborador não tem cargos marcados`.
- Desligar: `O usuário {nome} continua ativo; desative em Configurações > Usuários se for o caso.`
- Selo: `perfil do usuário diferente dos cargos`.

**Papéis e celulares**: legenda `Função gerida pela mentoria`; aviso `Somente o Proprietário ou o Suporte Volmaster altera os papéis das funções.`

**Mentor (403)**: `Seu perfil é somente leitura: nenhuma alteração é permitida.`

**Comando**: `Prévia (nada foi gravado). Use --aplicar para gravar.` / `Funções criadas: {c} · atualizadas: {a} · usuários movidos: {u} · Lote: {codigo}` / `Use --force junto com --todas --aplicar.`

**Nomes das funções** (nome do roteiro): `Proprietário`, `Gerente`, `Chefe de oficina`, `Consultor técnico`, `Técnico`, `Peças`, `Financeiro`, `Auxiliar de copa e zeladoria`, `Mentor`, `Suporte Volmaster`; combinadas: `Chefe de oficina + Técnico` (nomes unidos por ` + `).

---

## Testes Pest a escrever

Arquivos: `tests/Feature/Workshop/PerfisMentoriaTest.php` e `tests/Feature/Workshop/PerfisSincroniaTest.php` (funções auxiliares globais com prefixo `perfisTeste`, nomes únicos). Rodar: os dois arquivos, depois `--filter=Workshop`, `pint` nos arquivos tocados. Dica: o `krayin_test` pode ter funções antigas pré-existentes; os testes afirmam sobre **as linhas que criam** (por id/nome), nunca sobre totais do banco.

**PerfisMentoriaTest.php** (catálogo, matriz, funções, migração, comando)
1. `o catálogo tem os 10 perfis na ordem do roteiro e os 8 cargos batem com WorkshopSettingsController::PAPEIS` (chaves e nomes; `CARGOS_OBRIGATORIOS` ⊂ cargos; destinos de `BarrierRule::DESTINOS` ⊂ perfis ∪ `tecnico_da_os`; chave do chefe é `chefe`). *A4-1.*
2. `toda chave citada na matriz existe no ACL e os padrões casam` (`incluir`/`excluir` sem curinga existem em `config('acl')`; `casa('settings.user','settings.user.roles.edit')` verdadeiro; `casa('a.b','a.bc')` falso).
3. `normaliza perfis: ordem canônica, sem repetidos, sem inválidos, CSV sem espaços` (`" tecnico, chefe,xyz"` → `chefe,tecnico`; vazio → null).
4. `a matriz do Técnico, do Chefe, da Zeladoria, do Mentor e do Suporte não tem nenhuma chave de valores`; `Proprietário é 'todas as permissões'`; `Gerente não tem settings.user, settings.perfis nem mentor_mentoria`. *A4-3 / B8.*
5. `acúmulo: valores só aparecem se algum perfil da combinação der valores` — `chefe+tecnico` e `zeladoria+tecnico` sem `ver_valores_financeiros`; `consultor+tecnico` e `financeiro+tecnico` com; `proprietario+tecnico` é `all`. *A4-3.*
6. `acúmulo: a exclusão de um perfil não tira o que outro perfil da combinação concede` e `um erro de matriz (Técnico com chave de valores no incluir) não vaza valores`.
7. `a prévia não grava nada` (contagem de `roles`, `users`, `perfil_alteracoes` antes e depois).
8. `aplicar cria as 10 funções com as permissões da matriz; rodar de novo não muda nada` (idempotência: 2ª vez `criadas = 0`, `atualizadas = 0`, sem novas linhas de histórico). *A4-1.*
9. `aplicar atualiza a função quando a matriz muda e registra o que mudou; "manter" preserva o ajuste manual` (`config(['perfis.perfis.gerente.acl.excluir' => [...]])`; `perfil_alteracoes` com `funcao_atualizada` e `permissoes_antes`).
10. `função antiga com o mesmo nome (Financeiro) vira "Financeiro (antiga)" e a nova leva o nome do roteiro; os usuários da antiga continuam nela até migrar`.
11. `migra os usuários das funções do servidor para as equivalentes do roteiro, sem apagar nenhuma função` — cria Administrador(all), Consultor, Diagnóstico, Financeiro, Mecânico, Programador com um usuário cada; após `aplicar(['migrar_usuarios' => true])`: Proprietário, Consultor técnico, Chefe de oficina, Financeiro, Técnico, **Suporte Volmaster** (Programador); `Role::count()` só cresce; nenhum usuário apagado. *A4-2.*
12. `a migração leva a comissão da função antiga e ninguém fica sem entrar` (cada usuário migrado abre a própria tela com `assertOk`; `comissao_percentual` copiada; se há duas origens com % diferentes, não copia e avisa).
13. `a prévia avisa quando o usuário deixa de ver valores (Diagnóstico → Chefe) e quando só enxerga as próprias OS` (`valores.antes=true/depois=false`; `precisa_visao_global`).
14. `não move quem está aplicando para função que não seja total, e não deixa a oficina sem acesso total` (bloqueios (a) e (b); sem ator no console, o destino `all` passa).
15. `desfazer devolve os usuários do lote à função anterior; quem mudou depois fica de fora; as funções criadas permanecem`.
16. `o histórico é só inserção` (`update`/`delete` lançam `LogicException`).
17. `comando workshop:perfis: sem opções só mostra a prévia; --aplicar --migrar-usuarios grava; --desfazer; --todas --aplicar sem --force recusa; --tenant inexistente falha com mensagem` (usar `$this->artisan(...)`; não usar `--todas` com bancos reais).
18. `piloto desligado: aplicar não liga nem desliga o piloto e o Técnico da mentoria continua vendo valores (comportamento atual); com o piloto ligado, não vê`. *Gate do piloto.*
19. `todos os perfis entram: um usuário em cada função da mentoria abre a própria tela` — Proprietário `admin.mentoria.painel`, Gerente/Peças/Zeladoria `admin.rotina.painel`, Chefe `admin.rotina.patio`, Consultor `admin.rotina.agenda`, Técnico `admin.tecnico.index`, Financeiro `admin.financeiro.lancamentos`, Mentor `admin.mentor.index`, Suporte `admin.workshop.settings.edit`; todos `assertOk` (nenhum é deslogado por permissões vazias). *Roteiro 2 e 5 (parte F04); A4 "Teste".*
20. `banco sem a coluna/tabela: a tela avisa, a sincronização não faz nada e nada dá erro 500` (zera o memo estático por reflexão e restaura no `finally`).

**PerfisSincroniaTest.php** (colaborador, tela, Mentor, B8 técnico)
21. `marcar cargos no colaborador ligado a um usuário atualiza a função do usuário; cargos acumulados usam a função combinada` (HTTP `POST colaboradores.store` e `PUT update`; `perfil_oficina = chefe,tecnico`; histórico origem `colaborador`; `RecipientResolver::byPerfil('chefe')` e `('tecnico')` incluem o usuário). *A4-4 / A4-3 / roteiro 2.*
22. `trocar os cargos move o usuário e reaproveita a função combinada já criada` (2 usuários com os mesmos cargos → mesma `role_id`; `Role` combinada criada uma vez).
23. `antes de usar o botão das funções, salvar o colaborador não mexe no usuário` (nenhuma função gerida → `role_id` igual; comportamento atual). *Gate.*
24. `colaborador sem usuário, sem cargos ou desligado não altera ninguém; várias fichas do mesmo usuário somam os cargos`.
25. `gerente só atribui cargos operacionais; não mexe em gerente nem proprietário; ninguém altera o próprio perfil nem tira o único Proprietário; usuário interno nunca é sincronizado` (cada `negado` com cargos salvos e `warning` na sessão).
26. `preenche mechanic_id do usuário a partir do colaborador só quando está vazio e o mecânico não é de outro usuário`.
27. `divergências: lista e aplica os cargos do colaborador (só Proprietário/Suporte)`; `desligar avisa e não desativa o usuário`.
28. `Mentor é somente leitura: GET abre o painel; POST/PUT/DELETE recebem 403 (mesmo com permissão de escrita por engano na função); sair e as rotas de notificação passam; usuário com Mentor + Gerente não fica somente leitura`. *A4-1 (Mentor).*
29. `Suporte Volmaster não vê valores em R$ (piloto ligado), não é cargo (fora de PAPEIS e do select de colaboradores) e não recebe alerta de nenhum destino`.
30. `B8 (técnico): com o piloto ligado, o Técnico da mentoria e o Chefe+Técnico abrem a OS e a impressão sem nenhum valor; Consultor+Técnico vê` (cenário próprio: OS com item de preço secreto, sem depender de funções globais de outro arquivo). *Roteiro 6; A4 "Teste".*
31. `a tela Perfis: só abre com a permissão (hoje 401; a F05 pode trocar para 403, ajustar junto), mostra a prévia, recusa "Aplicar" sem confirmar ou com assinatura velha, aplica com assinatura certa e mostra o lote`.
32. `Papéis e celulares: aceita Mentor e Suporte, ignora papel inventado (teste antigo continua verde), não altera função gerida e só o Proprietário muda papéis (o Gerente grava só celular e recebe aviso)`.

Todas as linhas "Teste:" do A4 ficam cobertas (1, 5, 19, 30 + 11, 21, 28). Linhas do roteiro: 2 (19, 21), 5 (19; menu final na F05), 6 (30), 71/7 (apenas a base: Mentor/Proprietário entram).

---

## Riscos e interações com outras frentes

**Riscos do próprio escopo**
1. **FK em cascata**: apagar função apaga usuários (`2021_03_12_074857...:23`). Nada nesta frente apaga função; qualquer nova ação de "limpar funções antigas" deve continuar proibida.
2. **Bloqueio de acesso**: `Bouncer` desloga função `custom` sem permissões. Mitigado por: destino nunca vazio, ator preservado, último acesso total preservado, Proprietário = `all`, prévia + desfazer.
3. **Escalada de privilégio**: Gerente alterando cargos de colaborador não pode se promover nem promover outro a Proprietário/Gerente/Suporte (travas da seção A4-4); o cartão "Papéis" não pode ser usado por Gerente para marcar função como `proprietario`.
4. **Diagnóstico → Chefe e Programador → Suporte** mudam muito o que as pessoas veem (Diagnóstico/Programador **veem valores hoje**; Programador é hoje técnico do grupo "Programações"). A prévia mostra por usuário e deixa trocar o destino; ver "Decisões em aberto".
5. **Visão das OS** (`users.view_permission`, `ServiceOrderController.php:188-197`): usuário migrado para Chefe/Financeiro/Peças continua `individual`/`group` e não vê as OS dos outros. A ação **não** amplia visão sozinha (aviso na prévia).
6. **Comissão por função**: se a comissão não acompanhar, `PainelComissao`/`CommissionReportService` zeram o ganho. Mitigado pela cópia; funções acumuladas **não** somam comissão (definir em Funções). A F14 (remuneração) deve saber disso.
7. **Matriz desatualizada**: perfis explícitos não ganham chave nova sozinhos; os de curinga (Gerente, Suporte) ganham ao rodar a ação de novo. Documentar "rodar a ação depois de cada deploy que cria ACL".
8. **Memo estático** `disponivel()` em processos que percorrem oficinas: `PerfilService::esquecer()` a cada troca de banco (comando e testes).
9. **Flash do Krayin** usa aspas duplas em JavaScript: não pôr aspas nem quebra de linha nas mensagens.
10. `MentorController::replicarPesos` dentro de uma oficina apaga e reinsere `bonus_marcadores` da própria oficina (`MentorService.php:176-196`, `mesmoBanco` curto-circuita). Não é desta frente; avisar a F00: as ações `replicar*` deveriam valer só na Matriz.

**Interfaces e arquivos compartilhados**
- **F05 (menu/permissões)**: dona das listas `acl.incluir/excluir`, `chaves_de_valores`, `somente_leitura_permite` e de `entrada`; usa `PerfilCatalog::permissoes()/entrada()/nivelDeGestao()/doUsuario()`. Deve (i) dividir `settings.ficha` em ver/editar para o Gerente "só leitura" (inicialmente a Ficha está **excluída** do Gerente); (ii) dividir `mentor_mentoria` em leitura e `mentor_mentoria.replicar` (as ações de escrita não podem ir para o Mentor); (iii) resolver o item de Configurações para função `custom` (chave-filha); (iv) se trocar 401 por 403, ajustar `CadastrosFichaTest.php:228-235` e meus testes 31. Sequência recomendada: **F04 antes da F05** (mesmo arquivo `Config/perfis.php`).
- **F00 (oficinas)**: banco de cada oficina precisa da migration (senão `disponivel()` falso); sugerido (opcional) chamar `PerfilService::aplicar` com `migrar_usuarios=false` ao fim de `ProvisionTenantJob` (oficina nova nasce só com "Administrador"; criar funções é inofensivo) e, para a "Oficina Teste Mentoria", `php artisan workshop:perfis --tenant=oficina-teste --aplicar --migrar-usuarios`; o usuário Mentor mora na Matriz.
- **F03 (piloto)**: "pelo menos um colaborador com perfil Gerente ou Proprietário" usa `Colaborador::ativos()->comCargo(...)` (inalterado). A "Ficha incompleta" não depende de funções.
- **F06 (crédito)**: "visível só para Consultor, Financeiro, Gerente e Proprietário" → `PerfilCatalog::temPerfil($user, 'consultor','financeiro','gerente','proprietario')` (cobre acúmulo).
- **F10/F11 (peças)**: ao criar a chave de ACL do Painel de Peças, acrescentá-la em `perfis.perfis.pecas.acl.incluir`.
- **F02 (notificação)**: se criar rota POST do "Testar notificação", acrescentar o nome em `somente_leitura_permite`.
- **F14 (remuneração)**: `comissoes` está em `chaves_de_valores`; função acumulada não soma comissão.
- **Arquivos compartilhados** (menor edição, conflitos prováveis): `Providers/ServiceOrderServiceProvider.php`, `Routes/web.php`, `Config/acl.php`, `Config/menu.php`, `Config/perfis.php`, `Http/Controllers/WorkshopSettingsController.php`, `Resources/views/settings/workshop.blade.php`, `Http/Controllers/ColaboradorController.php`, `Resources/views/colaboradores/index.blade.php`, `Resources/lang/{pt_BR,en}/app.php`, `tests/Feature/Workshop/Concerns/BuildsWorkshop.php`.

---

## Decisões em aberto

(Só o que depende do patrão/usuário; cada uma com a recomendação.)
1. **"Diagnóstico" vira "Chefe de oficina"?** A função "Diagnóstico" do servidor foi criada para técnicos do grupo Diagnóstico (e hoje vê valores). *Recomendo:* manter o padrão do roteiro como sugestão, mas conferir pessoa por pessoa na prévia; quem for técnico de diagnóstico vai para Técnico.
2. **"Programador" vira "Suporte Volmaster"** (PDF explícito). Mas a função hoje é de técnico do grupo Programações da oficina. *Recomendo:* aplicar só a quem é da Volmaster; técnico de programação da oficina vai para Técnico (escolha por usuário na prévia).
3. **Chefe de oficina vê valores em R$?** O B8 não diz. *Recomendo:* não (como o Técnico); assim "Chefe + Técnico" fica sem valores.
4. **Mentor e Suporte veem R$?** *Recomendo:* Mentor só percentuais (e o Painel do Dono sem caixa em R$ para ele — ajuste de F00/F05); Suporte sem financeiro, DRE, comissões, salários e fiscal.
5. **Itens que o B8 não cita**: Contatos, Veículos, Atendimentos Remotos, Solicitações do portal, Catálogo de serviços, Mecânicos, Chaves de API. Hoje o Consultor antigo tem Contatos, Veículos, Atendimentos Remotos e Solicitações. *Recomendo:* Consultor: Contatos e Veículos (já na matriz) + Atendimentos Remotos; Chefe: Atendimentos Remotos; Chaves de API só Proprietário/Suporte.
6. **Gerente pode mudar o perfil de acesso de quem for operacional marcando cargos em Colaboradores?** *Recomendo:* sim, só para cargos operacionais; Gerente e Proprietário só pelo Proprietário.
7. **Desligar o colaborador desativa o usuário?** *Recomendo:* não automático (só aviso), para não derrubar acesso por engano.
8. **Como o Mentor entra nas oficinas** (um login único na Matriz ou um usuário Mentor em cada oficina). *Recomendo:* login único na Matriz + usuário Mentor numa oficina só se ele precisar abrir o subdomínio (decisão conjunta com a F00).

---

## Passo a passo para o usuário

**Antes (uma vez, depois que o desenvolvimento entregar o zip)**
1. Subir o zip e rodar `public/migrate-safe.php` (cria a coluna `roles.gerenciada_em` e a tabela `perfil_alteracoes` no banco principal; apagar o arquivo depois). Oficinas com banco próprio: só funcionam depois que as atualizações rodarem no banco de cada uma (caminho definido pela F00); até lá a tela avisa e nada quebra.

**Na Oficina Teste Mentoria (primeiro) e depois em cada oficina real**
2. Entrar como Administrador. Ir em **Configurações > Perfis da mentoria** (ou no cartão do topo de "Indicadores e alertas").
3. Clicar em **Ver prévia do que vai mudar** (deixe marcado "Passar também os usuários das funções antigas para as novas"). Conferir:
   - as funções que serão criadas (as 10) e a renomeação "Financeiro (antiga)";
   - para cada pessoa: função atual → nova, o que perde, **"Valores em R$: Sim → Não"**, visão das OS e comissão;
   - quem é da Volmaster (escolher "Suporte Volmaster") e quem é técnico (escolher "Técnico"); "Manter como está" para quem não deve mudar.
4. Marcar "Entendi que isto muda o acesso das pessoas listadas" e **Aplicar agora**. Anotar o código do lote. Se algo ficou errado: **Desfazer a migração dos usuários deste lote** (as funções criadas permanecem; nada é apagado).
5. Em **Configurações > Funções**, abrir uma função nova para ver as permissões. Se o patrão ajustar uma função à mão, a próxima "atualização" mostra "Diferente do padrão" e pergunta se mantém.
6. Em **Colaboradores**, cadastrar um colaborador por cargo e escolher o **Usuário do sistema**: o perfil do usuário passa a seguir os cargos (cargos acumulados criam a função "Chefe de oficina + Técnico", por exemplo). Para o Técnico, escolher também o **Técnico vinculado** (a tela "Minhas tarefas" precisa dele).
7. Criar os usuários de teste (um por perfil, mais Mentor e Suporte) em Configurações > Usuários, cada um na função da mentoria correspondente, e entrar com cada um:

| Perfil | Deve ver | Não deve ver |
|---|---|---|
| Técnico | Minhas tarefas, Meu bônus | qualquer valor em R$, Financeiro, Comissões, DRE |
| Chefe de oficina | Quadro de pátio, etapas Diagnóstico a Veículo em Teste, Minha rotina, Registros, Meu bônus | valores, Financeiro, Configurações |
| Consultor técnico | Agenda, Cadastro, todas as etapas, Marketing, Minha rotina, Meu bônus | Financeiro (DRE), Configurações |
| Peças | Estoque, Grupos, Minha rotina, Meu bônus (Painel de Peças quando a F10 entregar) | Financeiro, Cadastro |
| Financeiro | Financeiro, Faturamento, Minha rotina, Meu bônus | Estoque, Configurações |
| Zeladoria | Checklists da própria rotina, Meu bônus | valores, Financeiro |
| Gerente | tudo da oficina, menos Usuários/Funções/Perfis e edição da Ficha | Configurações de usuários |
| Proprietário | tudo; abre no Painel do Dono | — |
| Mentor | Visão do mentor e Painel do Dono, só leitura | qualquer botão que grava (recebe 403) |
| Suporte Volmaster | configuração e suporte | valores financeiros |
(O menu final e os bloqueios 403 por rota/API são acabamento da F05; esta tabela é o roteiro de conferência do A4.)

8. Só depois de A4 conferido, seguir para o A5 (Ficha e Colaboradores) e A6 (ligar o piloto).

**Linha de comando (opcional, Volmaster)**: `php artisan workshop:perfis` (prévia), `php artisan workshop:perfis --aplicar --migrar-usuarios`, `--tenant=oficina-teste`, `--todas` (só prévia; para aplicar em todas exige `--force`), `--desfazer=<lote>`.

**Avisos para a documentação (RETOMAR-TRABALHO.md)**: tela `Configurações > Perfis da mentoria`; comando `workshop:perfis`; coluna `roles.gerenciada_em`; tabela `perfil_alteracoes`; migration `2026_11_18_104000`; perfis novos `mentor` e `suporte`; middleware `PerfilSomenteLeitura`; regra "rodar a ação depois de cada deploy que cria chave de ACL"; a regra de acúmulo e a de Técnico sem valores; funções antigas permanecem (nunca apagar função: a FK apaga usuários).
