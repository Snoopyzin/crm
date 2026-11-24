# Contexto comum a todos os agentes — Ajustes do Roteiro de Teste (Mentoria)

Leia este arquivo inteiro antes de qualquer coisa. Responda e escreva textos de interface SEMPRE em português do Brasil.

## O projeto
- **Volmaster CRM**: sistema de gestão de oficinas de caminhão diesel. Laravel 12 / PHP 8.3 / Krayin CRM (Webkul), multi-oficina (pacote `Tenant`).
- Raiz do Laravel: `d:\volmaster crm\volmaster crm` (é a pasta de trabalho). Uma pasta acima (`d:\volmaster crm`) ficam `RETOMAR-TRABALHO.md` e `deploy-staging` — NÃO mexa nelas.
- Tudo da mentoria vive em `packages/Webkul/ServiceOrder` (OS = tabela `quotes`; `ServiceOrder extends Quote`), com pequenas edições nos pacotes Admin, Mechanic, Portal, Vehicle, Core. Peças/estoque: pacotes `PartInventory`, `Product`, `Warehouse`; fiscal: pacote `Fiscal` (Focus NFe). Oficinas: pacote `Tenant`.
- O texto integral do PDF do patrão ("Roteiro de Teste e Ajustes do Software", 18/11/2026: Parte A = A1-A6, Parte B = B1-B9, roteiro de 72 linhas, critério de liberação) está em `C:\Users\Usuario\AppData\Local\Temp\claude\5b7f4ef2ecf4cd89\19bfa105-212f-467f-918b-fd9e0becaacc\scratchpad\roteiro\roteiro-pdf.txt`. É a especificação: siga-a ao pé da letra e atenda a TODOS os bullets do(s) item(ns) da sua frente, incluindo a linha "Teste:".
- Estado atual: os capítulos 1-12 da mentoria (piloto, 20 barreiras, 116 indicadores, Painel do Dono, alçadas, colaboradores/Ficha, bônus/travas, financeiro/DRE, mentor, rotina, LGPD) já estão implementados e testados localmente (suíte de 289 testes passando). `d:\volmaster crm\RETOMAR-TRABALHO.md` descreve o módulo (leia só as seções que precisar).

## Regras INEGOCIÁVEIS
1. **Nada de deploy.** Não acesse servidor/HostGator, não toque em `.env`, não gere zip, não mexa em `d:\volmaster crm\deploy-staging`, `RETOMAR-TRABALHO.md` nem nos arquivos de memória. Registre o que for relevante para a documentação no seu relatório final.
2. **Piloto desligado = comportamento atual.** O servidor real roda com `WorkshopSettings 'piloto_ativo'` DESLIGADO. Todo bloqueio/trava/barreira/regra nova só vale com o piloto ligado (`WorkshopSettings::get('piloto_ativo')` / o helper que o código já usa). Com o piloto desligado, as telas atuais devem funcionar exatamente como hoje. Telas e campos novos podem existir, mas sem bloquear ninguém.
3. **Migrations seguras**: só aditivas, idempotentes (guard com `Schema::hasTable`/`hasColumn`), nunca apagam nem reescrevem dados existentes, `down()` seguro. Nome: `2026_11_18_HHMMSS_descricao.php` (HHMMSS único e crescente; escolha um horário que não exista ainda — veja a pasta de migrations) dentro de `packages/Webkul/<Pacote>/src/Database/Migrations`. **O servidor real só recebe as migrations quando o usuário rodar `public/migrate-safe.php`**: logo, o código tem de tolerar coluna/tabela ausente sem erro 500 (use o mesmo padrão de `Services/PrevisaoOs::disponivel()`: `Schema::hasColumn` memoizado) — principalmente em telas por onde TODO usuário passa.
4. **Nunca** rode `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, `db:seed` ou SQL destrutivo (`DROP`, `TRUNCATE`, `DELETE` sem WHERE) nos bancos `krayin_crm` e `krayin_test`. Para aplicar SUA migration use `php artisan migrate --path=<caminho relativo da sua migration> ` com `DB_DATABASE=krayin_test` (e só rode no `krayin_crm` se a sua instrução mandar). `krayin_crm` guarda os dados de demonstração.
5. **Nada se apaga.** Nenhum registro de validação, trava, alçada, alerta ou movimento de estoque é apagado; correções geram novo registro com justificativa. Tudo que é regra (prazos, percentuais, metas, valores) vem da configuração da oficina (Ficha/`WorkshopSettings`/tabelas de regra), nunca fixo no código.
6. **Técnico nunca vê dinheiro**: valores, descontos, comissões, margens, crédito e dados financeiros não podem sair para o perfil Técnico, nem em telas, nem em JSON/API. **Crédito do cliente NUNCA** aparece no link de acompanhamento nem na OS impressa. Mensagens de alerta nunca levam valores em R$. O Dono nunca recebe aviso operacional (só nível 3, críticos e o resumo opcional).
7. **Não quebre o que funciona** e preserve retrocompatibilidade. Siga `AGENTS.md` do projeto: prefira estender via pacote `ServiceOrder`; só edite arquivos de outros pacotes (Admin/Core/...) quando for inevitável e com a menor edição possível. Não apague arquivos existentes.
8. **Não encerre processos** (MariaDB PID 21472 e `php artisan serve` na porta 8000 devem continuar rodando) e não instale pacotes (sem Composer/npm).
9. **Sem espírito de gambiarra**: nada de `try/catch` engolindo erro para "passar teste", nada de `skip`/`todo` em testes, nada de desligar validação. Se algo for impossível, diga no relatório.

## Ambiente local (Windows, PowerShell 5.1)
```powershell
$env:PATH = "C:\Users\Usuario\dev-tools\php83;" + $env:PATH
$env:OPENSSL_CONF = 'C:\Users\Usuario\dev-tools\php83\extras\ssl\openssl.cnf'
$env:DB_DATABASE = 'krayin_test'          # SEMPRE para testes
Set-Location "d:\volmaster crm\volmaster crm"
php artisan test --filter=NomeDoTeste     # um teste/arquivo
php artisan test --filter=Workshop        # testes da mentoria
php artisan test --compact                # suíte inteira (leva 1,5 a 3+ min: use timeout de 600000 ms)
php vendor/bin/pint <arquivos alterados>  # formatação (Laravel Pint)
```
- MariaDB já está ligado (usuário `root`, sem senha). Bancos: `krayin_crm` (desenvolvimento/demonstração, NÃO rode testes nele) e `krayin_test` (testes).
- Sem Git no PC. Há um backup do código ANTERIOR às mudanças desta rodada em `C:\Users\Usuario\AppData\Local\Temp\claude\5b7f4ef2ecf4cd89\19bfa105-212f-467f-918b-fd9e0becaacc\scratchpad\backup-pre-roteiro` (mesma estrutura de pastas: `packages\Webkul`, `app`, `config`, `routes`, `resources`, `database`, `lang`, `tests`). Use-o para comparar (`fc` ou leitura) se precisar saber como era um arquivo.
- PowerShell 5.1: sem `&&`/`||`; sem `2>&1` em exe nativo; use `Select-Object -First/-Last` em vez de head/tail. Para buscar/ler/editar arquivos prefira as ferramentas Grep/Glob/Read/Edit/Write.
- Comandos longos: sempre com `timeout` explícito.

## Convenções do código (aprendidas ao longo do projeto)
- ACL: chaves PLANAS em `config('acl')` (arquivo `packages/Webkul/ServiceOrder/src/Config/acl.php`); `acl()->getItems()` devolve só chaves de topo — filhas vêm do config; o mapa rota→chave usa a ÚLTIMA chave que cita a rota (a rota `admin.service_orders.stage.index` pertence a várias chaves; vale a última, `pos_venda`). Itens de MENU de topo precisam de chave sem ponto, senão `Menu.php` quebra. Role de teste com `settings.xxx` precisa também de `settings`.
- Perfis da oficina: coluna `roles.perfil_oficina` (lista separada por vírgula; o usuário tem UM role, e o role pode ter vários perfis = acúmulo). `RecipientResolver` usa `FIND_IN_SET`. `comissao_percentual` também fica em `roles`. Middleware `EntradaPorCargo` (cada cargo entra na própria tela com o piloto ligado) e `DonoForaDoFluxo`.
- `ValoresFinanceiros::can()` decide quem vê valores (verdadeiro com piloto desligado). `PainelComissao` (início de quem ganha comissão). `StageGate`/`StageAdvanceService`/`WorkflowActions`/`WorkflowService`/`WorkflowController` formam o fluxo das 8 etapas, bloqueios e ações de tela; `BarrierEngine` + `BarrierRule` + `BarrierConditions` formam o motor de barreiras/alertas (`cumprida_no_prazo = nivel_atual === 0`); `AlertService`/`AlertDispatcher`/`WebPush` enviam alertas; `AlcadaCatalog`/`AlcadaService` = fila de alçadas; `FichaEnquadramento`/`WorkshopSettings` = configurações da oficina (cada oficina tem o seu banco/tenant); `IndicatorCatalog`/`IndicatorCalculator` = 116 indicadores; `Rotina/RotinaService`, `Bonus/*`, `Financeiro/*` (DRE), `MentorService`, `PainelDonoService`.
- Testes: Pest + `DatabaseTransactions` + trait/helper `BuildsWorkshop` (em `tests/`), arquivos em `tests/Feature/Workshop/`. Nomes de funções auxiliares globais do Pest precisam ser ÚNICOS entre arquivos (prefixe com o assunto). Use `forgetSeeded()` das tabelas padrão a cada teste (já em `BuildsWorkshop::setUpWorkshop`). JSON do Laravel descarta `.0` (use `toEqual`, não `toBe`). As classes de relatório do dashboard guardam o período por container (um `it` por período). Datas `AAAA-MM-31` quebram no MariaDB (use o fim real do mês). Validação `regex:` com `|` exige array de regras. `array + array` não sobrescreve chaves (use `array_merge`). `assertSee` de texto acentuado em JSON precisa de cuidado (a árvore da ACL sai como JSON escapado: asserte a chave).
- Textos da interface em pt-BR nos arquivos `Resources/lang/pt_BR/app.php` e `.../en/app.php` do pacote (mantenha os dois). Views em Blade + Vue 3 inline (o padrão do projeto) com Tailwind.
- Datas: `dd/mm/aaaa hh:mm`, fuso `America/Sao_Paulo`.

## O que você deve entregar (todo implementador)
1. Código + migrations + views + textos pt-BR + **testes Pest** que cubram cada linha "Teste:" do item e as regras de bloqueio com o piloto ligado E o comportamento inalterado com o piloto desligado.
2. Rodar: seus testes novos, depois `--filter=Workshop`, e `pint` nos arquivos que você tocou. Corrigir tudo que quebrar (inclusive testes antigos afetados — nunca apague teste sem substituí-lo por um equivalente correto).
3. Relatório final objetivo (o que foi feito por item do PDF, arquivos criados/alterados, migrations, chaves novas de ACL/WorkshopSettings/rotas, o que ficou de fora e por quê, riscos conhecidos, avisos para a documentação).
