# Especificação — F00-oficinas

**Frente:** Oficinas (tenants), migrações por oficina e Visão do mentor — itens **A1** e a primeira parte da **B7** (Visão do mentor).
**Base:** leitura do código em `d:\volmaster crm\volmaster crm` (nada foi alterado, migrado ou executado). Todos os caminhos abaixo são relativos a essa pasta; os do pacote ServiceOrder começam em `packages/Webkul/ServiceOrder/src`.

## Resumo executivo (leia antes de abrir qualquer arquivo)

1. **A hipótese está confirmada pelo código.** `public/migrate-safe.php:44` chama só `$kernel->call('migrate', ['--force' => true])` na conexão padrão (o banco do `.env`). No console o middleware `ResolveTenant` não roda, então nenhum banco de oficina é tocado. **Não existe em lugar nenhum um caminho que migre oficinas já criadas**: o único `Artisan::call('migrate')` do pacote Tenant é o do provisionamento (`packages/Webkul/Tenant/src/Jobs/ProvisionTenantJob.php:48`), que só serve para a oficina recém-criada. Oficina criada antes das migrations de 16–17/11 não tem `indicator_definitions`, `mentoria_registros` etc.
   Caminho do erro: `MentorService::comparativo()` (`MentorService.php:61`) → `naOficina()` → `indicadoresPercentuais()` → `IndicatorService::definitions()` (`Services/Indicators/IndicatorService.php:40`) → `IndicatorDefinition::ensureDefaultsOnce()` → `ensureDefaults()` faz `static::pluck('codigo')` (`Models/IndicatorDefinition.php:49`) → `QueryException` (tabela inexistente) → cai no `catch` de `MentorService.php:74-77`, que grava o texto **"Não foi possível ler esta oficina."** (`MentorService.php:75`) e a view o mostra em vermelho (`Resources/views/mentor/index.blade.php:32` e `:63`). A exceção real já vai para `report()` (`MentorService.php:76`): a **prova final no servidor** é achar `SQLSTATE[42S02] ... indicator_definitions' doesn't exist` em `storage/logs/laravel.log` no horário do acesso (ver "Passo a passo"). A segunda causa possível (banco da oficina inalcançável: senha, host) cai no mesmo `catch`.
2. **Provisionar uma oficina nova: as migrations novas entram** (todas as registradas pelos providers, em ordem de nome), **mas o instalador as desfaz em parte**: `ProvisionTenantJob` roda `migrate` (L47-48) e **depois** `DatabaseSeeder` do instalador (L50-54), cujos seeders fazem `DELETE` de `attributes` (`Installer/.../Attribute/AttributeSeeder.php:19`), `roles` e `users` (`User/RoleSeeder.php:18-20`, `UserSeeder.php:19`), `workflows`, `email_templates`, `lead_*`, `countries`, `country_states` e só recriam o conjunto base do Krayin. Efeito na oficina nova: **somem os atributos `cpf_cnpj` e `endereco` do cliente** (o campo CPF/CNPJ do Cadastro deixa de gravar, pois o repositório só grava atributos que existem; com o piloto ligado `StageGate::cadastroParaOs()`, `StageGate.php:117`, passa a barrar a saída do Cadastro por "CPF/CNPJ do cliente"), **somem as 5 funções semeadas por migration** (`Admin/.../2026_08_12_180000_seed_workshop_roles.php`) e a função Administrador (id 1) nasce **sem `roles.perfil_oficina`** (a migration que adivinha o papel rodou antes de ela existir), então `RecipientResolver::byPerfil('proprietario')` (`Services/RecipientResolver.php:40-45`) não acha o dono. Como a Oficina Teste nasce por esse caminho, isto precisa ser corrigido aqui (gancho pós-semeadura, abaixo).
3. **O "painel de oficinas" só lista.** `Tenant/Http/Controllers/Admin/TenantController::index` (L18-30) e `Resources/views/admin/index.blade.php` não criam nada. A criação é a página pública `/signup` (`Tenant/Routes/web.php:6-10`, `SignupController::store`).
4. **Teste automatizado de isolamento é viável** (MariaDB local com `root` sem senha, `LocalProvisioner` já faz `CREATE DATABASE`), mas **fora de `tests/Feature/Workshop`** (lá cada teste roda dentro de `DatabaseTransactions` e `naOficina()` faz `DB::purge('mysql')`, o que derruba a transação).
5. **Achado extra que atinge a Oficina Teste (fotos do termo B2, laudo, checklist):** os arquivos de cada oficina são gravados em pasta própria (`ResolveTenant.php:107-123`), mas a URL pública é fixa na raiz (`config/filesystems.php:41`, `env('APP_URL').'/storage'`) e o `public/storage` é **um único link simbólico** criado uma vez e nunca reapontado (`ResolveTenant.php:131-148`, `if (File::exists($link)) return;`). Em subdomínio, as imagens provavelmente não aparecem (ou aparecem as de outra pasta). Correção proposta na seção "Achado adicional".

---

## O que já existe

### Como cada oficina tem o seu banco e como a conexão é trocada
- `config/database.php:46-64` conexão `mysql` (a que todo o código usa) e `:73-86` conexão fixa `landlord` (diretório `tenants`; por padrão usa as mesmas credenciais de `mysql`).
- `packages/Webkul/Tenant/src/Models/Tenant.php` — `$connection = 'landlord'` (L20), `db_password` com cast `encrypted` (L41), status `pending|provisioning|active|failed|suspended` (L10-18). Migration `2026_08_10_120000_create_tenants_table` **sem guarda** (`Schema::create`); como roda na conexão padrão, **cada banco de oficina também ganha uma tabela `tenants` vazia** (idem `fiscal_onboarding_requests`, ver docblock de `Fiscal/.../2026_10_15_100000_create_fiscal_onboarding_requests_table.php:7-12`). `TenantController::index` recusa dentro de oficina (`abort_if(app()->bound('currentTenant'), 404)`, L20) e exige função `permission_type === 'all'` (L22).
- `Tenant/Http/Middleware/ResolveTenant.php` — middleware global (`bootstrap/app.php:21`, `prepend`). `handle()` L27-69: sem subdomínio → principal; subdomínio inexistente → 404 com `tenant::errors.not-found` (L44-46); `pending|provisioning` → página "Preparando a conta" (L48-50); `failed` → L52-54; `suspended` → L56-58; ativo → `switchConnectionToTenant()` (L187-199: reescreve `database.connections.mysql.{host,database,username,password}` e faz `DB::purge('mysql')`), troca o cookie de sessão (`volmaster_{sub}_session`, L198) e a raiz do disco `public` (L107-123), e faz `app()->instance('currentTenant', $tenant)` (L64). `resolveSubdomain()` L156-179 usa o host de `APP_URL` como domínio central e ignora `www`.
- Outros jeitos de percorrer oficinas já existentes (modelos a seguir): `ServiceOrder/Console/Commands/WorkshopTickCommand.php` (`contexts()` L58-73, `runFor()` L75-118 — troca a conexão, **falha de uma oficina não para as outras**, `if (! Schema::hasTable('barrier_instances') ...) return 0` L94) e `WorkshopBackupCommand.php` (L53-83). Precedente de **conexão nomeada** sem mexer em `mysql`: `Fiscal/src/Services/FocusEmpresaService.php::storeTokens()` L117-160 (`TENANT_CONNECTION`).
- `ServiceOrder/Services/MentorService.php::naOficina()` L202-234 executa um closure no banco da oficina e restaura (`finally`); se a oficina usa o mesmo banco da conexão atual (`mesmoBanco()` L236-241), roda direto, sem trocar (para não derrubar a transação em curso, comentário L207-208).

### Migrations hoje
- `public/migrate-safe.php` (61 linhas): chave fixa `TROQUE-ESTA-CHAVE` com `hash_equals` (L18, L22-26), HTML pt-BR, `$kernel->call('migrate', ['--force' => true])` (L44), mostra a saída em `<pre>` (L47), códigos de saída (L49-53), aviso para apagar o arquivo (L59). Nunca roda `fresh/refresh/reset/rollback`.
- `ProvisionTenantJob::handle()` L31-79: `status=provisioning` → credenciais (`assignCredentials()` L85-102, nome do banco = `config('tenant.db_prefix')` + slug, limite de 32 caracteres) → `provisioner->createDatabase()` (cPanel UAPI ou `LocalProvisioner`) → aponta `mysql` para o banco novo (L104-112) → **`Artisan::call('migrate', ['--force' => true])`** → **`DatabaseSeeder`** → usuário admin id 1 → `active`. Em qualquer exceção `handleFailure()` (L119-135) grava `failed` e **apaga o banco** recém-criado (L128-134). O `finally` (L75-78) restaura `mysql`.
- Fila: `.env.example:26` `QUEUE_CONNECTION=sync` (a criação roda dentro da requisição do `/signup`; a página `provisioning.blade.php` recarrega a cada 5 s, o que sugere fila assíncrona — **confirmar no `.env` do servidor**).
- `CpanelProvisioner` (docblock L8-17): "NOT YET VERIFIED against the live HostGator account". `TENANT_PROVISIONER` padrão é `local` (`Tenant/src/Config/tenant.php:9`).
- **Só 3 das 79 migrations de 2026 têm guarda `hasTable/hasColumn`** (`2026_11_17_120000`, `_130000`, `_140000`; contagem por `Glob **/Database/Migrations/2026_*.php` + `Grep hasTable|hasColumn|hasIndex`); as demais usam `Schema::create/table` direto. A idempotência hoje vem só da tabela `migrations`. Não há `Migrator`/rota/comando que migre oficinas.
- Laravel 12.58 (`vendor/laravel/framework/.../Application.php:48`). **Atenção:** `MigrateCommand` (`vendor/laravel/framework/src/Illuminate/Database/Console/Migrations/MigrateCommand.php:238-281`), com `--force`, **cria o banco** (`CREATE DATABASE IF NOT EXISTS`) quando a conexão falha com "Unknown database" (erro 1049). Para um registro de oficina com `db_name` errado isso criaria um banco vazio e despejaria todo o esquema nele. O novo executor precisa barrar isso antes (abaixo).

### Visão do mentor
- Rotas `Routes/web.php:223-228` (`admin.mentor.index`, `.replicar_metas`, `.replicar_pesos`); ACL **uma só chave** `mentor_mentoria` para as três (`Config/acl.php:192-195`); menu `Config/menu.php:135-139`.
- `MentorController` (L21-58) e `MentorService`: `oficinas()` L33-48 (dentro de oficina devolve só ela; senão Matriz + `Tenant` ativos, `catch` que engole tudo, L41-45); `comparativo()` L61-95; `evolucao()` L102-138 (`Schema::hasTable('marco_zero')` L109, `catch` L132-136 com `'erro' => true`); `replicarMetasPadrao()` L146-169 e `replicarPesos()` L174-197 (**sem `try/catch`**: uma oficina desatualizada derruba a ação inteira com erro 500; `replicarPesos` faz `DB::table('bonus_marcadores')->delete()` L186 sem transação e **não semeia a origem antes**); `indicadoresPercentuais()` L246-271 (só `unidade === 'percent'`, logo nenhum "R$").
- Oficina **com** as tabelas e **sem** dados já aparece com traço: `IndicatorFormatter::value(null)` devolve `'—'` (`Services/Indicators/IndicatorFormatter.php:13-15`) e a situação é `sem_dado` (cinza). O que falta é o caso de **exceção**.
- Estado compartilhado em memória: `IndicatorDefinition/BarrierRule/BonusMarcador/AlcadaRegra/PlanoConta::$seeded` são indexados por nome do banco (seguro em laço de oficinas); `WorkshopSettings::flush()` já é chamado por `naOficina`. **Não** são indexados por banco: `Services/PrevisaoOs.php:17,22-25` e `Services/CodigoSequencial.php:24,92-95` (só usados em telas/DataGrids de uma requisição; o mentor e o executor de migrations não os tocam — qualquer código novo que memorize `Schema::hasColumn` em estático deve indexar pelo banco).

### Testes que já tocam nisso
- `tests/Feature/Workshop/MentorHistoricoTest.php`: `oficinaFalsa()` L194-202 (oficina que aponta para o **mesmo** banco do teste → `mesmoBanco()` evita a troca), comparativo L204-228, **`'oficina ilegível não derruba o comparativo: aparece com o erro'` L230-238 (afirma o texto de erro — será substituído)**, evolução L240-250, tela e replicação L252-279. Nenhum teste cobre um segundo banco de verdade.
- `tests/Pest.php:18` (`TestCase` em `Feature`) e `:21-23` (`DatabaseTransactions` + `BuildsWorkshop` só em `Feature/Workshop`). `BuildsWorkshop.php:42-63` já contorna os efeitos do semeador (`roles.perfil_oficina = null`, recria atributos `endereco` e `cpf_cnpj`) — é a evidência local do problema do item 2 do resumo.
- Dentro de `Feature/Workshop`, **não** simule oficina quebrada com banco inexistente seguido de gravações: `naOficina` faz `DB::purge('mysql')` (L223) e a transação do teste é perdida (leituras seguem, gravações passam a persistir em `krayin_test`). Use subclasse que lança dentro de `naOficina` (receita abaixo) ou o teste com banco real fora de `Feature/Workshop`.

### O que o seeder do instalador apaga (referência para todas as frentes)
`attributes`, `roles`, `users`, `workflows`, `email_templates`, `lead_types`, `lead_sources`, `lead_pipelines`, `lead_pipeline_stages`, `countries`, `country_states` (grep `->delete()` em `packages/Webkul/Installer/src/Database/Seeders`). **Qualquer dado que uma migration insira nessas tabelas não existe em oficina recém-criada.** Hoje isso afeta `attributes` (`cpf_cnpj`, `endereco`) e `roles` (funções semeadas e `perfil_oficina`).

---

## Requisitos do PDF → como serão atendidos

### A1 — Oficina de teste

| Bullet do PDF | Situação | Como será atendido |
|---|---|---|
| Criar a oficina "Oficina Teste Mentoria", separada das oficinas reais | **Parcial** | Isolamento por banco, sessão e pasta de arquivos **já existe** (`ResolveTenant`). A criação existe só em `/signup` (público). **Falta:** (a) botão "Nova oficina" no painel Clientes (`admin/index.blade.php`); (b) corrigir o que o semeador apaga em oficina nova (gancho `tenant.provision.after` → `OficinaPadrao::garantir()` + migration idempotente para as oficinas já existentes); (c) fazer as migrations futuras chegarem às oficinas (executor + `migrate-safe.php`); (d) `ignore_user_abort`/`set_time_limit` no provisionamento; (e) passo a passo (seção final); (f) achado do `/storage` (opcional recomendado). |
| Todo o roteiro de teste roda nela | **Falta** | Depende de (b), (c) e (f). Roteiro linha 1 ("pronta para o teste") só fica verdadeira com a Ficha e o piloto (F03) por cima desta base. Linha 2 (usuário por perfil) é F04, que precisa usar o mesmo gancho para criar as funções-cargo na oficina nova. |
| Teste: os dados dela não aparecem em nenhuma outra oficina | **Falta (o isolamento existe, o teste não)** | `Feature/Tenant/IsolamentoDasOficinasTest` (bancos temporários, com `ResolveTenant` via HTTP e com `naOficina`), `ProvisionamentoDaOficinaTest` (criação real) e verificação manual curta no passo a passo. |

### B7 — Visão do mentor (primeira parte; título da OS, datas e 404 são da F01)

| Bullet do PDF | Situação | Como será atendido |
|---|---|---|
| Corrigir "Não foi possível ler esta oficina" | **Falta** | (1) Causa raiz: executor de migrations por oficina (`TenantMigrator`, `tenants:migrate`, `migrate-safe.php`). (2) Sintoma: a tela nunca mostra texto de erro (abaixo). |
| Oficina sem dados deve aparecer com traços, nunca com erro | **Parcial** | Sem dados + tabelas existem: já aparece "—". **Falta** o caso de exceção: `comparativo()`/`evolucao()` passam a devolver `sem_dados` (sem texto de erro), a exceção vai para `report()` (uma vez por oficina), cada indicador que falhar vira traço sem derrubar o resto, e `replicar*` deixam de dar 500. |
| Roteiro linha 71: "mostra a Oficina Teste e as outras oficinas sem erro e sem valores em reais" | **Parcial** | Sem R$ já é verdade (só `unidade = percent`). Entra teste que prova (sobre os arrays do serviço — **não** use `assertDontSee('R$')` na página inteira: `service_order::components.money-mask` injeta "R$" em todo `admin.layout.body.after`). |

---

## Mudanças por arquivo

Ordem sugerida de implementação: **1** migration + `OficinaPadrao` + gancho (e testes); **2** `TenantMigrator` + comando; **3** `migrate-safe.php`; **4** mentor (serviço, controller, view); **5** botão no painel; **6** achado do `/storage` (se aprovado); **7** Pint e testes.

**Pronto quando:** (a) `php artisan tenants:migrate --simular` lista pendências por oficina e `--force` aplica sem tocar na conexão `mysql`; (b) nenhuma ocorrência de "Não foi possível ler esta oficina" no código ou nos testes, e a Visão do mentor nunca mostra texto de erro; (c) uma oficina criada por `ProvisionTenantJob` nasce sem migrations pendentes, com `cpf_cnpj`/`endereco` e com o Administrador como proprietário; (d) o teste de isolamento passa com dois bancos reais; (e) `--filter=Workshop` e a suíte inteira continuam verdes (a suíte tinha 289 testes) e `pint` limpo nos arquivos tocados.

### CRIAR

#### C1. `packages/Webkul/Tenant/src/Services/TenantMigrator.php`
Executor seguro. Regras: só `migrate` (nunca `fresh/refresh/reset/rollback/wipe`, nunca `--seed`); **nunca cria banco**; **não mexe na conexão `mysql`** (usa a conexão nomeada `tenant_migrate` e `migrate --database=`, que o `Migrator::usingConnection` — `vendor/.../Migrator.php:664-675` — troca e restaura como conexão padrão durante a execução, então `Schema/DB/Eloquent` das migrations caem no banco da oficina); isolamento por oficina; ordem fixa (`id` crescente); idempotente (a tabela `migrations` de cada banco decide o que falta); trava contra execução simultânea; senha nunca aparece na saída.

```php
<?php

namespace Webkul\Tenant\Services;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;
use Webkul\Tenant\Models\Tenant;

/**
 * Aplica as migrations pendentes no banco de CADA oficina (o `migrate` normal só enxerga o banco do .env).
 * Só adiciona; nunca apaga dados; nunca cria banco; uma oficina com problema não impede as outras.
 */
class TenantMigrator
{
    public const CONEXAO = 'tenant_migrate';

    public const EM_DIA = 'em_dia';

    public const MIGRADA = 'migrada';

    public const SIMULADA = 'simulada';

    public const FALHOU = 'falhou';

    public const IGNORADA = 'ignorada';

    public const ADIADA = 'adiada';

    public const ROTULOS = [
        self::EM_DIA => 'Em dia', self::MIGRADA => 'Migrada', self::SIMULADA => 'Simulação',
        self::FALHOU => 'FALHOU', self::IGNORADA => 'Ignorada', self::ADIADA => 'Adiada',
    ];

    /**
     * Ativas e suspensas (as suspensas voltam um dia e precisam estar em dia). pending/provisioning/failed não têm banco pronto.
     *
     * @return Collection<int, Tenant>
     */
    public function oficinas(?string $subdominio = null): Collection
    {
        if (! Schema::connection('landlord')->hasTable('tenants')) {
            return new Collection;
        }

        return Tenant::query()
            ->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])
            ->when($subdominio, fn ($query) => $query->where('subdomain', $subdominio))
            ->orderBy('id')
            ->get();
    }

    /** Pendentes da instalação principal (só leitura; usado pela simulação do migrate-safe). @return list<string> */
    public function pendentesDaPrincipal(): array
    {
        return $this->pendentes(null);
    }

    /**
     * @param  ?Collection<int, Tenant>  $oficinas  null = todas de oficinas() (testes passam modelos não salvos)
     * @return array{oficinas: list<array<string, mixed>>, resumo: array<string, int>, falhas: int, incompleto: bool}
     *
     * @throws RuntimeException já existe outra execução em andamento
     */
    public function migrarTodas(?Collection $oficinas = null, bool $simular = false, ?string $subdominio = null, ?int $tempoMaximo = null, ?Closure $progresso = null): array
    {
        $executar = function () use ($oficinas, $simular, $subdominio, $tempoMaximo, $progresso) {
            $oficinas ??= $this->oficinas($subdominio);
            $inicio = microtime(true);
            $linhas = [];

            foreach ($oficinas as $oficina) {
                // Passou do orçamento de tempo: as demais ficam para a próxima rodada (rodar de novo é seguro).
                $linha = $tempoMaximo !== null && (microtime(true) - $inicio) >= $tempoMaximo
                    ? array_merge($this->base($oficina), ['situacao' => self::ADIADA, 'mensagem' => 'Adiada por falta de tempo. Rode de novo para continuar.'])
                    : $this->migrar($oficina, $simular);

                $linhas[] = $linha;

                if ($progresso) {
                    $progresso($linha);
                }
            }

            $resumo = array_fill_keys(array_keys(self::ROTULOS), 0);

            foreach ($linhas as $linha) {
                $resumo[$linha['situacao']]++;
            }

            return [
                'oficinas' => $linhas,
                'resumo' => $resumo + ['total' => count($linhas)],
                'falhas' => $resumo[self::FALHOU],
                'incompleto' => $resumo[self::ADIADA] > 0,
            ];
        };

        return $simular ? $executar() : $this->comTrava($executar);
    }

    /**
     * Migra UMA oficina. Nunca lança: qualquer erro vira situação FALHOU (e vai para report()).
     *
     * @return array{oficina: string, subdominio: string, situacao: string, pendentes: list<string>, aplicadas: list<string>, mensagem: ?string, saida: string}
     */
    public function migrar(Tenant $tenant, bool $simular = false): array
    {
        $resultado = $this->base($tenant);

        if ($this->mesmoBancoDaPrincipal($tenant)) {
            return array_merge($resultado, ['situacao' => self::IGNORADA, 'mensagem' => 'Mesmo banco da instalação principal; já tratado no passo 1.']);
        }

        if (! preg_match('/^\w{1,64}$/', (string) $tenant->db_name) || (string) $tenant->db_username === '') {
            return array_merge($resultado, ['situacao' => self::IGNORADA, 'mensagem' => 'Credenciais do banco incompletas no cadastro da oficina. Nada foi feito.']);
        }

        $senha = null;
        $registrada = false;

        try {
            $senha = (string) $tenant->db_password; // pode lançar DecryptException (APP_KEY diferente)

            $this->registrarConexao($tenant, $senha);
            $registrada = true;

            // Conectar ANTES de qualquer coisa: com --force o `migrate` CRIARIA um banco inexistente (MigrateCommand.php:238-281).
            DB::connection(self::CONEXAO)->getPdo();

            $migrator = app('migrator');

            if (! $migrator->usingConnection(self::CONEXAO, fn () => $migrator->hasRunAnyMigrations())) {
                return array_merge($resultado, ['situacao' => self::IGNORADA, 'mensagem' => 'Banco sem histórico de migrations: não parece uma oficina instalada. Nada foi feito.']);
            }

            $pendentes = $resultado['pendentes'] = $this->pendentes(self::CONEXAO);

            if ($pendentes === []) {
                return array_merge($resultado, ['situacao' => self::EM_DIA, 'mensagem' => 'Nada a migrar.']);
            }

            if ($simular) {
                return array_merge($resultado, ['situacao' => self::SIMULADA, 'mensagem' => count($pendentes).' migration(s) pendente(s). Nada foi alterado (simulação).']);
            }

            $saida = new BufferedOutput;
            Artisan::call('migrate', ['--database' => self::CONEXAO, '--force' => true], $saida);

            $restantes = $this->pendentes(self::CONEXAO);
            $aplicadas = array_values(array_diff($pendentes, $restantes));

            return array_merge($resultado, [
                'situacao' => $restantes === [] ? self::MIGRADA : self::FALHOU,
                'aplicadas' => $aplicadas,
                'saida' => $saida->fetch(),
                'mensagem' => $restantes === []
                    ? count($aplicadas).' migration(s) aplicada(s).'
                    : 'Restaram '.count($restantes).' migration(s) pendente(s) (a primeira é '.$restantes[0].'). Veja o log.',
            ]);
        } catch (Throwable $erro) {
            report($erro);

            $resultado['situacao'] = self::FALHOU;
            $resultado['mensagem'] = $senha ? str_replace($senha, '***', $erro->getMessage()) : $erro->getMessage();

            // O que foi aplicado antes da falha continua registrado: confere para o relatório (diagnóstico; o erro já foi reportado).
            // Só se a conexão foi registrada para ESTA oficina (senão leria o banco da oficina anterior).
            if ($registrada) {
                try {
                    $restantes = $this->pendentes(self::CONEXAO);
                    $resultado['aplicadas'] = array_values(array_diff($resultado['pendentes'], $restantes));

                    if ($restantes && $resultado['pendentes']) {
                        $resultado['mensagem'] = 'Parou em '.$restantes[0].': '.$resultado['mensagem'].' As migrations seguintes não foram aplicadas e as anteriores ficaram registradas; corrigir e rodar de novo é seguro.';
                    }
                } catch (Throwable) {
                }
            }

            return $resultado;
        } finally {
            // Desfaz a conexão (a configuração também: um registro antigo nunca pode ser reaproveitado pela próxima oficina).
            DB::purge(self::CONEXAO);
            Config::set('database.connections.'.self::CONEXAO, null);
        }
    }

    /** @return list<string> nomes das migrations que existem em disco e ainda não constam em `migrations` da conexão */
    protected function pendentes(?string $conexao): array
    {
        $migrator = app('migrator');

        // Mesmos caminhos que o `migrate` usa (BaseCommand::getMigrationPaths): os dos providers + database/migrations.
        $arquivos = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));

        $executadas = $migrator->usingConnection($conexao, function () use ($migrator) {
            $repositorio = $migrator->getRepository();

            return $repositorio->repositoryExists() ? $repositorio->getRan() : [];
        });

        return array_values(array_diff(array_keys($arquivos), $executadas));
    }

    protected function registrarConexao(Tenant $tenant, string $senha): void
    {
        Config::set('database.connections.'.self::CONEXAO, array_merge(config('database.connections.mysql'), [
            'url' => null, // DATABASE_URL, se existisse, sobrescreveria host/database e apontaria de volta para a principal
            'host' => $tenant->db_host ?: config('database.connections.landlord.host'),
            'database' => $tenant->db_name,
            'username' => $tenant->db_username,
            'password' => $senha,
        ]));

        DB::purge(self::CONEXAO);
    }

    protected function mesmoBancoDaPrincipal(Tenant $tenant): bool
    {
        $principal = config('database.connections.mysql');

        return $tenant->db_name === ($principal['database'] ?? null)
            && ($tenant->db_host ?: config('database.connections.landlord.host')) === ($principal['host'] ?? null);
    }

    /** @return array<string, mixed> */
    protected function base(Tenant $tenant): array
    {
        return [
            'oficina' => (string) ($tenant->name ?: $tenant->subdomain),
            'subdominio' => (string) $tenant->subdomain,
            'situacao' => self::EM_DIA,
            'pendentes' => [],
            'aplicadas' => [],
            'mensagem' => null,
            'saida' => '',
        ];
    }

    /** Uma execução por vez (duas páginas abertas ou um duplo clique rodariam migrations ao mesmo tempo no mesmo banco). */
    protected function comTrava(Closure $acao): mixed
    {
        $trava = fopen(storage_path('framework/tenants-migrate.lock'), 'c');

        if ($trava === false || ! flock($trava, LOCK_EX | LOCK_NB)) {
            if (is_resource($trava)) {
                fclose($trava);
            }

            throw new RuntimeException('Já existe uma migração das oficinas em andamento. Aguarde terminar e tente de novo.');
        }

        try {
            return $acao();
        } finally {
            flock($trava, LOCK_UN);
            fclose($trava);
        }
    }
}
```

#### C2. `packages/Webkul/Tenant/src/Console/Commands/MigrateTenantsCommand.php`
Comando artisan equivalente (mesma classe de serviço — o que se faz no navegador é exatamente o que se faz no terminal).

- Assinatura: `tenants:migrate {--oficina= : Endereço (subdomínio) de uma única oficina} {--simular : Só lista o que está pendente; não altera nada} {--tempo= : Limite em segundos; passado o limite, as oficinas restantes ficam para a próxima rodada} {--force : Não pergunta antes de aplicar (necessário em produção)}`.
- `use Illuminate\Console\ConfirmableTrait;` — em `handle(TenantMigrator $migrator)`: `if (! $simular && ! $this->confirmToProceed()) return self::FAILURE;`.
- `$oficinas = $migrator->oficinas($this->option('oficina') ?: null)`; vazia → mensagem pt-BR (`Nenhuma oficina cadastrada para migrar.` / `Nenhuma oficina ativa ou suspensa com o endereço "x".`) e retorno `SUCCESS` (sem filtro) ou `FAILURE` (com `--oficina` inexistente).
- `$migrator->migrarTodas(oficinas: $oficinas, simular: $simular, tempoMaximo: ..., progresso: fn ($linha) => $this->imprimir($linha))` dentro de `try/catch (\RuntimeException $e)` → `$this->error($e->getMessage()); return self::FAILURE;` (trava).
- `imprimir($linha)`: `sprintf('%-26s %-10s %s', $linha['subdominio'], TenantMigrator::ROTULOS[$linha['situacao']], $linha['mensagem'])` (`error()` se `FALHOU`); com `--simular` (ou falha) lista até 8 nomes de `pendentes` (`    - nome`).
- Fim: linha de resumo `N oficina(s): A migrada(s), B em dia, C falhou/falharam, D ignorada(s), E adiada(s).`; retorno `FAILURE` se houve falha ou `incompleto`, senão `SUCCESS`.
- Texto da descrição: `Aplica as migrations pendentes no banco de cada oficina (só adiciona; nunca apaga dados)`.

#### C3. `ServiceOrder/Services/OficinaPadrao.php`
Idempotente, usa a conexão padrão (no gancho ela já aponta para a oficina nova). Refaz o que o semeador do instalador apagou.

```php
<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OficinaPadrao
{
    /** Atributos do cliente que as migrations criam e o AttributeSeeder do instalador apaga. */
    protected const ATRIBUTOS_CLIENTE = [
        'cpf_cnpj' => ['name' => 'CPF/CNPJ', 'type' => 'text', 'sort_order' => '5'],
        'endereco' => ['name' => 'Endereço', 'type' => 'address', 'sort_order' => '6'],
    ];

    /** @return array<string, bool> o que foi criado/ajustado agora */
    public function garantir(): array
    {
        $feito = [];

        foreach (self::ATRIBUTOS_CLIENTE as $codigo => $dados) {
            if (DB::table('attributes')->where('code', $codigo)->where('entity_type', 'persons')->exists()) {
                continue;
            }

            DB::table('attributes')->insert([
                'code' => $codigo, 'name' => $dados['name'], 'type' => $dados['type'], 'entity_type' => 'persons',
                'lookup_type' => null, 'validation' => null, 'sort_order' => $dados['sort_order'],
                'is_required' => '0', 'is_unique' => '0', 'quick_add' => '0', 'is_user_defined' => '1',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $feito[$codigo] = true;
        }

        // O Administrador do instalador (id 1) é o proprietário; a migration que adivinha o papel pelo nome rodou antes de ele existir.
        if (Schema::hasColumn('roles', 'perfil_oficina')) {
            $ajustadas = DB::table('roles')->where('id', 1)->where('permission_type', 'all')
                ->where(fn ($q) => $q->whereNull('perfil_oficina')->orWhere('perfil_oficina', ''))
                ->update(['perfil_oficina' => 'proprietario']);

            if ($ajustadas) {
                $feito['perfil_proprietario'] = true;
            }
        }

        // F04 (perfis): chamar aqui a rotina idempotente que cria as funções-cargo na oficina nova.

        return $feito;
    }
}
```
Mesmos dados das migrations `Installer/.../2026_08_13_100000_add_cpf_cnpj_attribute_in_attributes_table.php:16-33` e `ServiceOrder/.../2026_11_17_110000_ensure_endereco_attribute_on_persons.php:20-34`.

#### C4. `ServiceOrder/Database/Migrations/2026_11_18_080000_ensure_cpf_cnpj_attribute_on_persons.php`
Para as oficinas **já existentes** (o runner a aplica): mesmo molde de `2026_11_17_110000_ensure_endereco_attribute_on_persons.php`. `up()`: `if (! Schema::hasTable('attributes')) return;` → se já existe `code = cpf_cnpj` e `entity_type = persons`, `return`; senão `insert` com os dados acima. `down()`: comentário "nada a desfazer: o atributo pertence à migration original" (sem apagar). **Faixa de horário reservada à F00: `2026_11_18_08xxxx`.**

#### C5. Testes (ver seção própria)
`tests/Feature/Tenant/Concerns/CriaBancosDeOficina.php`, `MigracaoDasOficinasTest.php`, `IsolamentoDasOficinasTest.php`, `ProvisionamentoDaOficinaTest.php`, `MigrateSafeScriptTest.php`, `MentorComOficinaAtrasadaTest.php`; em `tests/Feature/Workshop/`: `MentorTracosTest.php`, `OficinaPadraoTest.php`.

### ALTERAR

#### A1. `public/migrate-safe.php` (reescrever mantendo as proteções que já tem)
Mantém: chave fixa + `hash_equals` + 403 (L18-26), cabeçalho/HTML pt-BR, só `migrate --force`, aviso para apagar. **Acrescenta:**
- Parâmetros opcionais (todos validados; `422` se inválidos): `simular=1` (só lista o pendente na principal e nas oficinas), `escopo=todas|principal|oficinas` (padrão `todas`; `escopo=principal` = comportamento antigo), `oficina=<endereço>` (regex `^[a-z0-9]+(-[a-z0-9]+)*$`; implica `escopo=oficinas`).
- Logo depois da chave: `ignore_user_abort(true); @set_time_limit(0);` (fechar a aba ou estourar o tempo deixaria um banco pela metade). Se o servidor não permitir `set_time_limit`, calcular orçamento: `$limite = (int) ini_get('max_execution_time'); $tempo = $limite > 0 ? max(10, (int) floor($limite * 0.7)) : null;` e passar a `migrarTodas(... tempoMaximo: $tempo)`.
- Depois de `$kernel->bootstrap();`: `\Illuminate\Support\Facades\DB::prohibitDestructiveCommands(true);` (rede de segurança: esta página nunca roda `fresh/refresh/reset/rollback/wipe`).
- **Passo 1 — Instalação principal** (se `escopo` ≠ `oficinas`): sem `simular`, exatamente como hoje (`$kernel->call('migrate', ['--force' => true])`, saída em `<pre>`, mensagem OK/FALHOU); com `simular`, lista `TenantMigrator::pendentesDaPrincipal()`. Se falhar (exceção ou código ≠ 0) → `$principalOk = false`.
- **Passo 2 — Oficinas** (se `escopo` ≠ `principal`): se `$principalOk === false` e o escopo é `todas`, **não toca nas oficinas** ("As oficinas NÃO foram tocadas porque a instalação principal falhou. Corrija e rode de novo."), pois um erro de migration repetiria em todas. Senão `$migrador = $app->make(\Webkul\Tenant\Services\TenantMigrator::class)` → `migrarTodas(null, $simular, $oficina ?: null, $tempo, fn ($linha) => ...)` imprimindo, a cada oficina concluída, uma linha de tabela (Oficina · Endereço · Pendentes · Resultado) e um `<details>` com a saída bruta (`htmlspecialchars`), seguido de `if (ob_get_level() > 0) { @ob_flush(); } @flush();`. Tudo escapado com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`. `RuntimeException` da trava → mensagem em `.fail`.
- Faixa final: resumo (`N oficina(s): ...`); se `incompleto`: "Faltaram N oficina(s) por falta de tempo. Recarregue esta página para continuar."; sempre o aviso amarelo de apagar `public/migrate-safe.php`.
- Docblock atualizado explicando os parâmetros e que a saída bruta do Laravel dentro de `<pre>/<details>` continua em inglês (é o log do framework).
- Não exibir nome do banco nem credenciais.

#### A2. `Tenant/src/Providers/TenantServiceProvider.php`
No `boot()` (após L29): `if ($this->app->runningInConsole()) { $this->commands([MigrateTenantsCommand::class]); }` + `use Webkul\Tenant\Console\Commands\MigrateTenantsCommand;`. (Registro só no console, como `ServiceOrderServiceProvider.php:83-85`; por isso o `migrate-safe.php`, que roda no SAPI web, chama o **serviço**, não o artisan.)

#### A3. `Tenant/src/Jobs/ProvisionTenantJob.php`
1. Início de `handle()`: `ignore_user_abort(true); @set_time_limit(0);` com comentário (fila `sync` roda dentro do `/signup`; fechar a aba deixaria banco criado e status `provisioning` para sempre — e o subdomínio não pode ser reutilizado por causa do `unique`).
2. Depois do `updateOrInsert` do admin (L56-66) e antes de marcar `active` (L68): `$this->markStep($tenant, 'finishing'); Event::dispatch('tenant.provision.after', $tenant);` (+ `use Illuminate\Support\Facades\Event;`). A conexão `mysql` ainda aponta para a oficina nova nesse ponto (o `finally` só restaura depois). Convenção de evento idêntica à do projeto (`service_order.create.after`). Os ouvintes rodam de forma síncrona e **uma exceção deles falha o provisionamento** (o `catch` marca `failed` e `handleFailure` apaga o banco recém-criado): ouvintes ligados a este evento devem fazer só gravações idempotentes e simples.
3. Nada mais muda (a migração do banco novo continua em L47-48; o `handleFailure` continua apagando o banco criado quando algo falha).

#### A4. `ServiceOrder/Providers/ServiceOrderServiceProvider.php` (arquivo compartilhado: 1 linha + 1 `use`)
No `boot()`, após o `FiscalDocument::saved(...)` (L65-81): `Event::listen('tenant.provision.after', fn () => app(OficinaPadrao::class)->garantir());` e `use Webkul\ServiceOrder\Services\OficinaPadrao;` (`Event` já está importado, L6).

#### A5. `ServiceOrder/Services/MentorService.php`
Imports novos: `Webkul\ServiceOrder\Models\BonusMarcador`. Propriedade nova `public array $falhas = [];`. Métodos (substituir):

```php
public function oficinas(): Collection
{
    if (app()->bound('currentTenant')) {
        return collect([app('currentTenant')]);
    }

    $oficinas = collect([null]);

    // Instalação de uma oficina só: não existe diretório de oficinas.
    if (! Schema::connection('landlord')->hasTable('tenants')) {
        return $oficinas;
    }

    try {
        return $oficinas->merge(Tenant::where('status', Tenant::STATUS_ACTIVE)->orderBy('subdomain')->get());
    } catch (\Throwable $exception) {
        report($exception); // a Matriz continua na tela

        return $oficinas;
    }
}
```

`comparativo()` — no laço (L68-78), trocar o bloco por:

```php
foreach ($oficinas as $oficina) {
    $nome = $this->nome($oficina);
    $coluna = ['nome' => $nome, 'sem_dados' => true];

    try {
        $linhas = $this->naOficina($oficina, fn () => $this->indicadoresPercentuais($periodo));

        $dados[$nome] = $linhas;
        $coluna['sem_dados'] = collect($linhas)->every(fn ($linha) => $linha['realizado'] === null);
    } catch (\Throwable $exception) {
        // Oficina sem as migrations, banco fora do ar etc.: a tela mostra traços; o erro real fica no log.
        report($exception);
    }

    $colunas[] = $coluna;
}
```
O restante de `comparativo()` (união de códigos e montagem das linhas, L80-94) **não muda**. A chave `erro` deixa de existir (só a view e o teste a usavam).

`indicadoresPercentuais()` — cada indicador isolado; um só `report()` por oficina (evita 60 linhas de log por visita):

```php
protected function indicadoresPercentuais(string $periodo): array
{
    $servico = app(IndicatorService::class);
    $linhas = [];
    $falhas = 0;
    $primeira = null;

    // Se faltar a tabela do catálogo, o erro sobe para comparativo() e a oficina inteira vira traços.
    foreach ($servico->definitions() as $definicao) {
        if ($definicao->unidade !== 'percent') {
            continue;
        }

        try {
            $avaliacao = $servico->evaluate($definicao, $periodo);

            $linhas[$definicao->codigo] = [
                'nome' => $definicao->nome,
                'cargo' => $definicao->cargo,
                'meta' => IndicatorFormatter::meta($avaliacao['meta'], $definicao->tipo_meta, $definicao->unidade),
                'realizado' => $avaliacao['resultado']->realizado,
                'realizado_fmt' => IndicatorFormatter::value($avaliacao['resultado']->realizado, 'percent'),
                'situacao' => $avaliacao['situacao'],
                'situacao_rotulo' => IndicatorStatus::ROTULOS[$avaliacao['situacao']],
                'cores' => IndicatorFormatter::cores($avaliacao['situacao']),
            ];
        } catch (\Throwable $exception) {
            $falhas++;
            $primeira ??= $exception;
            $linhas[$definicao->codigo] = $this->linhaSemDado($definicao);
        }
    }

    if ($primeira) {
        report(new \RuntimeException("Visão do mentor: {$falhas} indicador(es) sem leitura nesta oficina; primeiro erro: ".$primeira->getMessage(), 0, $primeira));
    }

    return $linhas;
}

protected function linhaSemDado(IndicatorDefinition $definicao): array
{
    return [
        'nome' => $definicao->nome,
        'cargo' => $definicao->cargo,
        'meta' => IndicatorFormatter::meta($definicao->meta(), $definicao->tipo_meta, $definicao->unidade),
        'realizado' => null,
        'realizado_fmt' => IndicatorFormatter::value(null, 'percent'),
        'situacao' => IndicatorStatus::SEM_DADO,
        'situacao_rotulo' => IndicatorStatus::ROTULOS[IndicatorStatus::SEM_DADO],
        'cores' => IndicatorFormatter::cores(IndicatorStatus::SEM_DADO),
    ];
}
```

`evolucao()` — no `catch` (L132-136): `report($exception); return ['oficina' => $this->nome($oficina), 'marco_zero' => null, 'sem_dados' => true];` (a chave `erro` é substituída por `sem_dados`).

`replicarMetasPadrao()` — começa com `$this->falhas = []; app(IndicatorService::class)->definitions(apenasAtivos: false);` (garante o padrão semeado na origem, como o teste atual já faz à mão); cada oficina em `try { naOficina(...); $atualizadas++; } catch (\Throwable $e) { report($e); $this->falhas[] = $this->nome($oficina); }`.

`replicarPesos()` — começa com `$this->falhas = []; BonusMarcador::ensureDefaultsOnce();`; lê `$marcadores` da origem e, **se vier vazio, devolve 0 sem tocar nas oficinas** (nunca esvaziar os pesos delas); por oficina, `try` como acima e, dentro do `naOficina`, `DB::transaction(function () use ($marcadores) { DB::table('bonus_marcadores')->delete(); foreach (...) insert(...) })` (falha no meio não deixa a oficina sem pesos). `bonus_apuracoes.detalhe_json` guarda o detalhe do marcador inline (`2026_11_17_100500_create_bonus_tables.php:69`), então substituir os pesos não orfana histórico.

`naOficina()`, `mesmoBanco()`, `nome()` **não mudam**.

#### A6. `ServiceOrder/Http/Controllers/MentorController.php`
Em `replicarMetas()` (L42-49) e `replicarPesos()` (L51-58), depois do `flash('success', ...)` existente: `if ($this->mentor->falhas) { session()->flash('warning', 'Estas oficinas ficaram de fora e precisam ser atualizadas depois: '.implode(', ', $this->mentor->falhas).'.'); }` (o `flash-group` do admin já exibe `warning`: `Admin/.../components/flash-group/index.blade.php:36`; `FinanceiroController.php:149` já usa).

#### A7. `ServiceOrder/Resources/views/mentor/index.blade.php`
- L32 (cabeçalho da oficina): trocar o `@if ($oficina['erro'])...text-red-600` por `@if ($oficina['sem_dados'])<span class="block text-xs font-normal normal-case text-gray-400">sem dados</span>@endif`.
- L43 (`@else—@endif`): `@else<span class="text-gray-400">—</span>@endif`.
- Logo após o `</table>` do comparativo (L51): `<p class="mt-3 text-xs text-gray-400">— indica oficina ou indicador sem dados no período.</p>`.
- Evolução (L59-70): na célula do nome, `@if (empty($linha['marco_zero']) && empty($linha['sem_dados']))<span class="block text-xs text-gray-400">Marco Zero ainda não registrado</span>@endif`; no ramo `@if (empty($linha['marco_zero']))` trocar o `<td colspan="3">` com o texto de erro por `@foreach (['hora_sobre_minimo', 'produtividade', 'retrabalho'] as $chave)<td class="py-1.5 text-gray-400">—</td>@endforeach`. O ramo `@else` não muda.
- **Nenhuma ocorrência de "Não foi possível ler esta oficina" pode restar na view, no serviço ou nos testes.** Textos seguem hard-coded em pt-BR como no restante do módulo (o pacote não tem chaves `mentor.*` em `Resources/lang`; não criar só para isto).

#### A8. `Tenant/src/Resources/views/admin/index.blade.php`
No cabeçalho (L7-9), ao lado do título: `<a href="{{ route('tenant.signup.create') }}" target="_blank" class="primary-button">Nova oficina</a>`. (O painel passa a ser a porta de entrada que o PDF supõe; a página de cadastro é a mesma de hoje.)

#### A9. `tests/Feature/Workshop/MentorHistoricoTest.php`
Substituir o teste de L230-238 por equivalente correto (não apagar sem substituir): **`'oficina ilegível não derruba o comparativo: aparece com traços e o erro vai para o log'`** — usa a receita de "oficina que não lê" (subclasse de `MentorService` cujo `naOficina` lança para o subdomínio `quebrada`), `Exceptions::fake()`, afirma `$comparativo['oficinas'][1]['sem_dados']` verdadeiro, `[0]['sem_dados']` falso, `Exceptions::assertReportedCount(1)` e que `Config::get('database.connections.mysql.database')` não mudou. No teste de L252-279 acrescentar `->assertDontSee('Não foi possível ler esta oficina')`. Demais testes do arquivo não mudam.

### OPCIONAL (recomendado) — achado adicional: arquivos (`/storage`) por oficina
**Problema (código):** `ResolveTenant::configurePersistentStorage()` (L107-123) aponta o disco `public` para a pasta da oficina, mas (a) `filesystems.disks.public.url` fica fixo em `APP_URL/storage` (`config/filesystems.php:41`); (b) `ensureStorageLinkExists()` (L131-148) cria **um** link `public/storage` e nunca mais o altera (`if (File::exists($link)) return;`). Um link só não serve várias pastas: fotos do termo/laudo/checklist de uma oficina (`WorkflowService.php:396` grava em `Storage::disk('public')`, `ServiceOrderAttachment.php:28` monta a URL) só aparecem se o link, por acaso, apontar para a pasta dela.
**Correção proposta (Tenant):** (1) `Tenant/Http/Controllers/StorageController@show(string $path)`: `$disco = Storage::disk('public'); abort_unless($disco->exists($path), 404); return $disco->response($path);`, rota `Route::get('storage/{path}', ...)->where('path', '.*')->name('tenant.storage')` em `Tenant/Routes/web.php`; (2) em `ResolveTenant`, `Config::set('filesystems.disks.public.url', rtrim($request->getSchemeAndHttpHost(), '/').'/storage')` (antes do `Storage::purge('public')`), e `ensureStorageLinkExists()` passa a **remover** link antigo (`if (is_link($link)) { @unlink($link); }`) em vez de criar — com o link ausente o `.htaccess` (L19-21, `!-f`) entrega `/storage/*` ao Laravel; (3) teste: gravar um arquivo pela oficina A e pedir `/storage/x` nos hosts A (200), B (404) e raiz (404). **Custo:** arquivos passam a sair pelo PHP (vídeos sem *range*). **Ação do usuário** se aprovado: apagar `public/storage` uma vez no Gerenciador de Arquivos (o middleware tenta, mas pode não ter permissão). Avaliar com o usuário/F07 antes de implementar.

### OPCIONAL — fechar o cadastro público (ver "Decisões em aberto")
`Tenant/Config/tenant.php`: `'signup_enabled' => env('TENANT_SIGNUP_ENABLED', true)` (padrão ligado = nenhuma mudança). `SignupController::create/store`: `abort_unless(config('tenant.signup_enabled'), 404)`. Se o patrão quiser fechar: rotas internas `admin.tenants.create` (GET) e `admin.tenants.store` (POST) em `Tenant/Routes/admin.php`, em `TenantController` (mesmas guardas de `index`: fora de oficina e `permission_type === 'all'`), reaproveitando as regras de validação do `SignupController` e `ProvisionTenantJob::dispatch(...)`; o botão "Nova oficina" passa a apontar para `admin.tenants.create`; view `tenant::admin.create`.

---

## Banco (migrations)

- **Uma migration nova:** `2026_11_18_080000_ensure_cpf_cnpj_attribute_on_persons.php` (ServiceOrder) — insere o atributo `cpf_cnpj` de `persons` se faltar (aditiva, idempotente, `down()` sem apagar). Necessária porque **toda** oficina existente está sem ele ou pode estar (provisionadas antes de 13/08, ou depois, quando o semeador o apagou); a migration `Installer/.../2026_08_13_100000_add_cpf_cnpj_attribute_in_attributes_table.php` não tem `exists` e só roda uma vez por banco.
- Nenhuma tabela/coluna nova de esquema. Não é preciso `tenants.tipo` para marcar "oficina de teste": o piloto e o modo de teste ÷10 (F03) são `WorkshopSettings` e cada oficina tem o próprio banco.
- **Aviso a todas as frentes:** as migrations novas só chegam às oficinas depois que o `migrate-safe.php` (agora com o passo 2) ou `php artisan tenants:migrate --force` rodar. **Todo código novo precisa tolerar coluna/tabela ausente** em oficina ainda não migrada (regra 3 do contexto comum; padrão `PrevisaoOs::disponivel()`, mas indexando a memória pelo nome do banco se for usada em laço de oficinas) e **toda migration nova precisa de guarda** `hasTable/hasColumn/hasIndex` (só 3 das 79 de 2026 têm; a idempotência de uma reexecução parcial depende disso).
- Não editar migrations antigas nesta frente (endurecer as 76 sem guarda é grande e de baixo retorno: cada `Schema::table` vira um único `ALTER`, as tabelas de oficinas novas são pequenas, e o executor já isola falhas e mostra em qual migration parou).

---

## ACL, menu, rotas e configurações

- **ACL / menu:** nenhuma chave nova nesta frente. **Recomendação para a F05 (arquivos dela):** dividir `mentor_mentoria` (`Config/acl.php:192-195`) em `mentor_mentoria` (só `admin.mentor.index`) e **`mentor_padrao`** (`admin.mentor.replicar_metas`, `admin.mentor.replicar_pesos`). O perfil Mentor do PDF é "só leitura", mas hoje a mesma chave libera **gravar metas e pesos em todas as oficinas**. O `MentorController` não precisa mudar para isso (a barreira é por rota).
- **Rotas:** nenhuma nova (opcionais: `tenant.storage`; `admin.tenants.create/store`).
- **Artisan:** `tenants:migrate` (`--oficina`, `--simular`, `--tempo`, `--force`). Exemplo de uso em terminal: `php artisan tenants:migrate --simular` e depois `php artisan tenants:migrate --force`.
- **Parâmetros do `migrate-safe.php`:** `key`, `simular`, `escopo`, `oficina`.
- **Evento:** `tenant.provision.after` (payload `Tenant`; disparado com a conexão `mysql` ainda na oficina nova). **F04 e qualquer frente que precise de dados-base em oficina nova ouve este evento (ou estende `OficinaPadrao::garantir()`).**
- **Conexão nomeada:** `tenant_migrate` (registrada e destruída por oficina). **Arquivo de trava:** `storage/framework/tenants-migrate.lock`.
- **Chaves de `WorkshopSettings`:** nenhuma. **`.env`:** nenhuma obrigatória (opcional `TENANT_SIGNUP_ENABLED`).
- **Passo de provisionamento novo:** `provisioning_step = 'finishing'` (coluna livre).
- **Cron:** nada novo. (Não agendar `tenants:migrate`: migração de produção deve ser supervisionada, com simulação antes.)

---

## Textos da interface (pt-BR)

**Visão do mentor** (hard-coded na view, como o resto do módulo; não há `en` para mentoria):
- Cabeçalho da oficina sem leitura: `sem dados` (cinza, sem vermelho).
- Legenda: `— indica oficina ou indicador sem dados no período.`
- Evolução: três células `—`; nota `Marco Zero ainda não registrado` (só quando é isso, e não falha de leitura).
- Após replicar (aviso, só se houver falha): `Estas oficinas ficaram de fora e precisam ser atualizadas depois: Norte, Sul.` (mantidos: `Metas padrão da mentoria replicadas para N oficina(s).` e `Pesos do bônus replicados para N oficina(s).`).
- **Removido de todo o sistema:** `Não foi possível ler esta oficina.`

**Executor / migrate-safe / terminal:**
- Situações: `Em dia`, `Migrada`, `Simulação`, `FALHOU`, `Ignorada`, `Adiada`.
- `Nada a migrar.` · `N migration(s) aplicada(s).` · `N migration(s) pendente(s). Nada foi alterado (simulação).`
- `Parou em {migration}: {erro} As migrations seguintes não foram aplicadas e as anteriores ficaram registradas; corrigir e rodar de novo é seguro.`
- `Mesmo banco da instalação principal; já tratado no passo 1.` · `Credenciais do banco incompletas no cadastro da oficina. Nada foi feito.` · `Banco sem histórico de migrations: não parece uma oficina instalada. Nada foi feito.`
- `Adiada por falta de tempo. Rode de novo para continuar.` · `Já existe uma migração das oficinas em andamento. Aguarde terminar e tente de novo.`
- `Nenhuma migration pendente na instalação principal.` (simulação) · `Nenhuma oficina cadastrada para migrar.` · `Nenhuma oficina ativa ou suspensa com o endereço "x".`
- Página: `Rodando as migrations pendentes` · `1. Instalação principal` · `2. Oficinas` · `SIMULAÇÃO: nada será alterado. Só estou listando o que está pendente.` · `As oficinas NÃO foram tocadas porque a instalação principal falhou. Corrija e rode de novo.` · `Faltaram N oficina(s) por falta de tempo. Recarregue esta página para continuar.` · `Apague este arquivo (public/migrate-safe.php) depois de usar.` · `Parâmetro inválido. Use escopo=todas|principal|oficinas e oficina=endereco-da-oficina.`
- Resumo: `N oficina(s): A migrada(s), B em dia, C falhou(aram), D ignorada(s), E adiada(s).`

**Painel Clientes:** botão `Nova oficina`.

---

## Testes Pest a escrever

Convenções: nomes de funções auxiliares globais únicos (prefixo `oficinaBanco…`/`tenantTeste…`); sem `skip/todo`; `Exceptions::fake()` (facade `Illuminate\Support\Facades\Exceptions`, `assertReported`, `assertReportedCount`, `assertNothingReported`) quando a falha é esperada; `IndicatorDefinition::forgetSeeded()` no `beforeEach` dos testes de `Feature/Tenant`.

### Receita de infraestrutura (para `Feature/Tenant`, fora de transação)
Trait `tests/Feature/Tenant/Concerns/CriaBancosDeOficina.php` (cada arquivo usa `uses(CriaBancosDeOficina::class);` — sem editar `tests/Pest.php`):
- Prefixo único `krayin_test_oficina_`. **Todo `DROP/ALTER/CREATE` valida `str_starts_with($banco, 'krayin_test_oficina_')` e `/^[a-z0-9_]{1,64}$/` antes de executar; nunca toca em `krayin_test` nem `krayin_crm`.**
- `clonarComoOficina(string $sufixo, string $nome, string $subdominio): Tenant` — pela conexão `landlord` (root): `DROP DATABASE IF EXISTS` do banco **temporário** (nome com o prefixo), `CREATE DATABASE ... utf8mb4_unicode_ci`, para cada tabela de `SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'` do banco atual: ``CREATE TABLE `novo`.`t` LIKE `krayin_test`.`t` ``; ``INSERT INTO `novo`.`migrations` SELECT * FROM `krayin_test`.`migrations` ``; insere 1 usuário (a oficina real sempre tem; evita `CanInstall` mandar para `/install`). Devolve `Tenant` **não salvo**, criado com `new Tenant([...])` (campos `fillable`; o cast `encrypted` cifra a senha com `Crypt::encryptString`) com `db_name` do clone e as credenciais de `landlord`. **Armadilha:** não copiar o `oficinaFalsa()` de `MentorHistoricoTest.php:194-202` (`setRawAttributes` + helper `encrypt()`): `encrypt()` serializa o valor e o cast devolveria `s:0:"";` como senha — aquele teste só funciona porque nunca chega a conectar. `salvarOficina($tenant)` grava a linha em `tenants` só nos testes HTTP/comando (subdomínio `tt-*`).
- `limparOficinasDeTeste()` em `beforeEach` e `afterEach`: `SHOW DATABASES LIKE 'krayin\_test\_oficina\_%'` → `DROP DATABASE`; `DROP USER IF EXISTS` dos usuários criados pelo teste de provisionamento; `Tenant::where('subdomain', 'like', 'tt-%')->delete()` (com WHERE); apagar `dirname(base_path()).'/volmastercrm-uploads/tt-*` (criadas pelo `ResolveTenant`).
- Config dos testes HTTP: `config(['app.url' => 'http://volmaster.test'])` antes do `$this->get('http://tt-a.volmaster.test/admin/login')`.

### `tests/Feature/Tenant/MigracaoDasOficinasTest.php` (`->group('tenant-banco')`) — roteiro 1 e 71 (pré-requisito)
1. `simulação lista as migrations pendentes da oficina e não altera nada` — clone com atraso simulado (`ALTER TABLE quotes DROP COLUMN hora_previsao` + `DELETE FROM migrations WHERE migration = '2026_11_17_140000_add_hora_previsao_to_quotes_table'` **no clone**); `migrar($t, true)`: `situacao = simulada`, `pendentes` contém o nome, coluna continua ausente, linha de `migrations` continua ausente.
2. `aplica as pendentes, registra no histórico e a segunda rodada não faz nada` — mesma preparação: `migrada`, `aplicadas` contém o nome, coluna existe (`SHOW COLUMNS FROM clone.quotes LIKE 'hora_previsao'` pela `landlord`), linha em `migrations`; segunda chamada: `em_dia` e `mensagem = 'Nada a migrar.'`.
3. `falha em uma oficina não impede as outras e banco inexistente não é criado` — `[semBanco (db_name 'krayin_test_oficina_naoexiste'), atrasada]` em `migrarTodas(collect(...))`: linha 0 `falhou`, linha 1 `migrada`, `falhas = 1`; `SHOW DATABASES LIKE 'krayin_test_oficina_naoexiste'` vazio; `Exceptions::assertReportedCount(1)`.
4. `banco sem histórico de migrations é ignorado e continua vazio` — banco temporário vazio: `ignorada`, `SHOW TABLES` vazio.
5. `oficina que usa o mesmo banco da principal é ignorada` (modelo do `oficinaFalsa`).
6. `credenciais incompletas são ignoradas sem tentar conectar`.
7. `o limite de tempo adia as oficinas restantes` — `tempoMaximo: 0` → todas `adiada`, `incompleto = true`.
8. `a conexão principal e a padrão voltam ao normal` — `config('database.default')`, `config('database.connections.mysql')`, `DB::getDefaultConnection()` iguais antes/depois; `array_key_exists('tenant_migrate', DB::getConnections())` falso.
9. `senha da oficina não aparece na mensagem de falha` — oficina com senha errada `segredo123`: `falhou` e `mensagem` não contém `segredo123`.
10. `só oficinas ativas e suspensas entram; --oficina filtra` — salva 4 linhas `tt-*` (active, suspended, failed, pending): `oficinas()` devolve 2; `oficinas('tt-x')` devolve 1.
11. `comando tenants:migrate: simula, filtra por oficina e falha com oficina inexistente` — `$this->artisan('tenants:migrate', ['--simular' => true, '--oficina' => 'tt-a'])->expectsOutputToContain('tt-a')->assertExitCode(0)`; com `nao-existe` → `assertExitCode(1)`.
12. `a trava recusa uma segunda execução simultânea` — o teste abre `storage/framework/tenants-migrate.lock` com `flock(LOCK_EX)`; `migrarTodas(collect(), false)` lança `RuntimeException` com a mensagem pt-BR.

### `tests/Feature/Tenant/MentorComOficinaAtrasadaTest.php` (`->group('tenant-banco')`) — **prova da causa raiz**; roteiro 71
- `oficina sem as tabelas novas aparece com traços e, depois do executor, é lida sem erro`: clone; para simular oficina antiga **no clone**, `DROP TABLE` de `indicator_definitions`, `indicator_snapshots`, `mentoria_registros`, **`ALTER TABLE bonus_apuracoes DROP COLUMN origem`** (a migration `_100700` também mexe nessa tabela; sem isso ela falharia por coluna duplicada, porque não tem guarda) e remover de `migrations` as linhas `2026_11_17_100100_create_indicator_tables` e `2026_11_17_100700_add_origem_to_history_tables`. Isto mostra, na prática, o risco das migrations sem guarda: o cenário só é reconstituível desfazendo **tudo** que a migration toca. Com `Exceptions::fake()`: `app(MentorService::class)->comparativo('2026-11', collect([$tenant]))` → `oficinas[0]['sem_dados'] === true`, todas as células `null`, `assertReportedCount(1)`. Depois `app(TenantMigrator::class)->migrar($tenant)` → `migrada` com os 2 nomes. Novo `comparativo(...)` → `Exceptions::assertNothingReported()` após `Exceptions::fake()` novo, linhas com `situacao` ∈ {verde, amarelo, vermelho, sem_dado, sem_meta}. **Não passar `null` (Matriz) na coleção** (evita semear `krayin_test` fora de transação).

### `tests/Feature/Tenant/IsolamentoDasOficinasTest.php` (`->group('tenant-banco')`) — **A1 "Teste"**; roteiro 1
1. `dados gravados numa oficina não aparecem em outra nem na principal` — clones A e B; em A: `app(MentorService::class)->naOficina($a, fn () => WorkshopSettings::set('marcador_isolamento', 'A'))` e um dado de negócio (`DB::table('groups')->insert(['name' => 'Grupo só da Oficina A', 'description' => 'x', 'created_at' => now(), 'updated_at' => now()])`, tabela e colunas que a migration `2026_08_12_190000_seed_workshop_groups` já usa); lê pelo mesmo caminho em A (vê os dois), B (não vê) e **depois** na principal (`naOficina(null, ...)` — não vê); a conexão `mysql` e `currentTenant` voltam ao normal. Como é um teste sem transação, o grupo gravado vive só no banco temporário (que é apagado).
2. `o endereço escolhe o banco, o cookie de sessão e a pasta de arquivos` — HTTP em `tt-a.volmaster.test` e `tt-b.volmaster.test` (linhas salvas, status `active`): após cada `GET /admin/login` (200): `app('currentTenant')->subdomain`, `DB::connection()->getDatabaseName()` = banco da oficina, `config('session.cookie') === 'volmaster_tt-a_session'`, `config('filesystems.disks.public.root')` termina em `/volmastercrm-uploads/tt-a/app/public`; lendo o marcador do teste 1 só A o vê; host raiz e `www.volmaster.test` não resolvem oficina; host de outro domínio (`localhost`) idem.
3. `endereço desconhecido mostra "Não encontramos essa conta" e oficina em criação, com falha ou suspensa mostra a própria página sem trocar de banco` — `tt-x` inexistente → 404 + texto; `pending|provisioning` → 200 `Preparando a conta de`; `failed` → `Não conseguimos preparar a conta de`; `suspended` → `suspensa`; `DB::connection()->getDatabaseName()` continua o da principal.
4. (se o achado do `/storage` for adotado) `arquivos enviados ficam e são servidos só pela própria oficina`.

### `tests/Feature/Tenant/ProvisionamentoDaOficinaTest.php` (`->group('tenant-provisionamento')`, ~15-40 s) — **A1**; "as migrations novas entram?"
- `criar uma oficina nova aplica todas as migrations e deixa o banco pronto para uso` — `config(['tenant.db_prefix' => 'krayin_test_oficina_'])`; `Tenant::create([... 'subdomain' => 'tt-prov', status pending, db_* vazios])`; `(new ProvisionTenantJob($tenant, Hash::make('Senha@12345')))->handle(new LocalProvisioner)` (passa o provisionador explícito: ignora `TENANT_PROVISIONER` do `.env`). Afirma: `status = active`, `provisioning_step = done`; `TenantMigrator::migrar($tenant, true)['situacao'] === 'em_dia'` (nada pendente = **as migrations novas entraram**); usuário id 1 com o e-mail do admin; **atributos `cpf_cnpj` e `endereco` existem** e `roles.id=1.perfil_oficina = 'proprietario'` (o gancho funcionou); tabela `tenants` existe vazia no banco novo; a conexão `mysql` do teste voltou ao banco de teste. Limpeza: `LocalProvisioner::destroyDatabase(...)`.

### `tests/Feature/Tenant/MigrateSafeScriptTest.php` (`->group('tenant-banco')`) — processo separado (`Symfony\Component\Process\Process`, `PHP_BINARY`, `-r` com `$_GET` e `require base_path('public/migrate-safe.php')`; o `DB_DATABASE` do ambiente é herdado)
1. `sem chave ou com chave errada responde 403 e não toca no banco` (saída contém `403` e `Chave ausente ou incorreta`).
2. `a simulação roda de ponta a ponta` — `key` correta, `simular=1`: saída contém `SIMULAÇÃO` e `1. Instalação principal` e `2. Oficinas`, nenhuma exceção/fatal; `escopo=principal&simular=1` não contém `2. Oficinas`; `oficina=Inválido!` responde `422`.

### `tests/Feature/Workshop/MentorTracosTest.php` — **B7 "Teste"** e roteiro 71 (dentro de transação: sem banco real)
Receita da oficina que não lê, sem trocar de banco: subclasse anônima `new class extends MentorService { public function naOficina(?Tenant $tenant, Closure $fn): mixed { if ($tenant?->subdomain === 'quebrada') { throw new \RuntimeException('simulado'); } return parent::naOficina($tenant, $fn); } }` e `Tenant` não salvo `quebrada` (`setRawAttributes`). **A oficina que não lê vai por último** na coleção.
1. `oficina que não lê aparece com traços e o erro vai para o log` — `comparativo('2026-11', collect([null, $quebrada]))`: `oficinas[1]['sem_dados'] === true`, células da Quebrada `null`, `Exceptions::assertReportedCount(1)`, nenhuma chave `erro`.
2. `um indicador que falha vira traço e os outros seguem` — binding de `IndicatorService` por subclasse que lança em `evaluate()` para `CON-01`; a linha `CON-01` existe com `realizado = null`, `realizado_fmt = '—'`, `situacao = 'sem_dado'`; as demais calculadas; **um** `report` (consolidado).
3. `tela do mentor não mostra texto de erro` — `app()->bind(MentorService::class, fn () => <subclasse com oficinas() = [null, $quebrada]>)`; `GET admin.mentor.index` → `assertOk()->assertSee('Quebrada')->assertSee('<span class="block text-xs font-normal normal-case text-gray-400">sem dados</span>', false)->assertDontSee('Não foi possível ler esta oficina')` (o fragmento do cabeçalho, com `escape = false`: a legenda "sem dados" aparece sempre e não provaria nada); ACL via `makeUser('proprietario', ['mentor_mentoria'])` como no teste existente.
4. `o mentor só enxerga porcentagens, nunca valores em reais` — todos os `realizado_fmt`/`meta` do comparativo satisfazem `/%$|^—$|^0$|≥|≤/` e **nenhum contém `R$`**; evolução idem (roteiro 71 "sem valores em reais").
5. `evolução contra o Marco Zero: oficina que não lê aparece com traços` — `evolucao()` devolve `sem_dados = true`; a tela exibe `—`.
6. `replicar metas e pesos: a oficina que falha fica de fora e as outras são atualizadas` — duas oficinas (`oficinaFalsa` válida + `quebrada`): `replicarMetasPadrao()` e `replicarPesos()` devolvem 1, `falhas = ['Quebrada']`, `Exceptions::assertReportedCount(1)` por chamada; `POST admin.mentor.replicar_metas` (com o `MentorService` substituído por uma subclasse cujo `oficinas()` devolve as duas) redireciona e a sessão traz o aviso `Estas oficinas ficaram de fora`.
7. `replicar pesos nunca esvazia as oficinas quando a origem está vazia` — `BonusMarcador::ensureDefaults()` (marca o banco como semeado) e, dentro da transação, `DB::table('bonus_marcadores')->delete()`; com uma subclasse que conta as chamadas a `naOficina()`, `replicarPesos(collect([$valida]))` devolve 0 e `naOficina` não é chamado nenhuma vez.

### `tests/Feature/Workshop/OficinaPadraoTest.php`
1. `garantir() recria os atributos do cliente e o papel do proprietário sem duplicar` — apaga `cpf_cnpj`/`endereco` e zera `perfil_oficina` do role 1 dentro da transação; chama duas vezes; existe exatamente 1 de cada; segunda chamada devolve `[]`.
2. `o evento tenant.provision.after chama a rotina` — apaga o atributo, `Event::dispatch('tenant.provision.after', new Tenant)`, atributo volta.
3. A migration C4: roda `up()` (via `(include $arquivo)->up()`) duas vezes sem duplicar.

### Mapa para o PDF e para o roteiro
| Linha "Teste:" do PDF / roteiro | Teste |
|---|---|
| A1 — "os dados dela não aparecem em nenhuma outra oficina" | `IsolamentoDasOficinasTest` 1-3, `ProvisionamentoDaOficinaTest`, passo manual |
| B7 — "Uma oficina sem dados deve aparecer com traços, nunca com erro" | `MentorTracosTest` 1-5, `MentorComOficinaAtrasadaTest`, `MentorHistoricoTest` (substituído) |
| Roteiro 1 (oficina pronta) — parte A1 | `ProvisionamentoDaOficinaTest`, `MigracaoDasOficinasTest` |
| Roteiro 71 (Visão do mentor sem erro e sem R$) | `MentorTracosTest` 3-4, `MentorComOficinaAtrasadaTest` |
| (infra) migrations por oficina | `MigracaoDasOficinasTest`, `MigrateSafeScriptTest` |

**Rodar ao final:** `php artisan test tests/Feature/Tenant`, `php artisan test --filter=Mentor`, `php artisan test --filter=OficinaPadrao`, `php artisan test --filter=Workshop`, depois a suíte inteira (`--compact`, timeout 600000 ms) e `php vendor/bin/pint` nos arquivos tocados (PowerShell 5.1, `DB_DATABASE=krayin_test`).

---

## Riscos e interações com outras frentes

**Riscos**
1. **Migração parcial.** DDL do MariaDB não é transacional e 76 das 79 migrations de 2026 não têm guarda: se uma falhar no meio (queda, tempo), a repetição da *mesma* migration pode dar "already exists". Mitigações: simulação antes; `ignore_user_abort`/`set_time_limit`; orçamento de tempo entre oficinas; a principal é o "canário" (se falhar, as oficinas não são tocadas); mensagem diz em qual migration parou; reparo manual (SQL) fica com o desenvolvimento. Migrations novas **devem** ter guarda.
2. **`migrate --force` cria banco inexistente** (`MigrateCommand.php:238-281`). Barrado por `getPdo()` antes + exigência de histórico em `migrations` (`hasRunAnyMigrations`). Cobertos pelos testes 3 e 4.
3. **`DATABASE_URL`** no `.env` sobrescreveria host/banco da conexão derivada; por isso `'url' => null` em `registrarConexao()`.
4. **Chave fixa pública** (`TROQUE-ESTA-CHAVE`, está no repositório e nos documentos): o script passa a atingir todas as oficinas. Apagar o arquivo logo após o uso é obrigatório (aviso mantido); considerar chave por `.env` numa próxima rodada.
5. **Migrations mudam dados** (ex.: `2026_11_16_100400` dá `ver_valores_financeiros` a funções personalizadas e chuta `perfil_oficina`): em oficina antiga isso acontece de uma vez ao migrar; conferir Configurações > Funções depois.
6. **Provisionamento síncrono** (`QUEUE_CONNECTION=sync`): ~170 migrations + semeadura dentro de uma requisição; em hospedagem compartilhada o tempo pode estourar. Sem `ignore_user_abort`, fechar a aba deixa a oficina em `provisioning` e o subdomínio queimado (`unique`). Oficina `failed` não pode ser repetida com o mesmo endereço (a senha do admin não é guardada): usar outro subdomínio. Verificar também: `TENANT_PROVISIONER=cpanel`, `CPANEL_*` e cota de bancos MySQL do plano ("NOT YET VERIFIED" no `CpanelProvisioner`).
7. **`/signup` público** consome cota de bancos e cria contas sem controle (ver decisões).
8. **Testes com banco real** só fora de `Feature/Workshop` (`naOficina`/`ProvisionTenantJob` fazem `DB::purge('mysql')`); sempre prefixo `krayin_test_oficina_`; limpeza em `beforeEach/afterEach`; uma queda no meio pode deixar bancos temporários (a limpeza do próximo teste os remove).
9. **Cada oficina é uma origem diferente** (subdomínio): cookie de sessão, PWA/service worker, assinatura de notificação e chaves VAPID são **por oficina** — a Oficina Teste precisa das próprias chaves (F02).
10. **`/storage` compartilhado** (achado adicional): sem a correção, fotos de oficinas não aparecem de forma confiável; atinge B2 (4 fotos) e o laudo na Oficina Teste.
11. **Memória estática** por processo (`PrevisaoOs`, `CodigoSequencial`): não usar em laço de oficinas sem indexar por banco.
12. **`replicarPesos`** substitui pesos de todas as oficinas (confirmação já existe na view L80); agora é atômico e não esvazia a origem — continua sendo uma ação de escrita que o perfil Mentor "só leitura" não deveria ter (F05).

**Interações**
- **F01 (correções):** não edita nada desta frente. O contato de suporte "real" já aparece nas páginas do pacote Tenant (`errors/failed.blade.php:17` e `errors/suspended.blade.php:17` usam `gabriel@volmastercrm.com`) — candidato a fonte do e-mail do 404 (decisão do usuário, é da F01).
- **F02 (notificação/SMS):** `push_subscriptions` e VAPID por banco; a PWA é instalada **por oficina**; o passo 3 da preparação (Android/iPhone) acontece no endereço da Oficina Teste.
- **F03 (piloto/modo ÷10):** o piloto e o modo de teste são `WorkshopSettings` (já por oficina); "só na oficina de teste" se garante por onde a chave é ligada. A Ficha completa e o piloto da Oficina Teste ficam com F03 depois do passo a passo daqui.
- **F04 (perfis):** (a) suas funções-cargo **não podem nascer só em migration** — o instalador as apaga em oficina nova; ligue a criação ao gancho `tenant.provision.after` (`OficinaPadrao::garantir()` tem o ponto marcado) **e** a uma migration idempotente para as oficinas existentes; (b) a migration de migração de usuários só chega às oficinas pelo executor; (c) o Mentor vive na instalação principal.
- **F05 (menu/permissões):** dividir `mentor_mentoria`/`mentor_padrao` (acima). "Painel do Dono de cada oficina" para o Mentor **não existe**: sessões e usuários são por banco e por cookie; se for feito, reaproveitar `MentorService::naOficina()` (público) numa rota da principal, somente leitura (decisão abaixo).
- **F06-F14 (B1-B9):** suas migrations (a) precisam de guarda; (b) só chegam às oficinas pelo `migrate-safe.php`/`tenants:migrate`; (c) o código precisa tolerar coluna ausente em oficina ainda não migrada (principalmente telas por onde todos passam); (d) dados-base devem entrar também pelo gancho de oficina nova. F07 (fotos): ver `/storage`. F13 (diária/cron) e todas as que tenham tarefas agendadas: `workshop:tick` já percorre as oficinas ativas e é tolerante a tabela ausente (`WorkshopTickCommand.php:94`) — manter esse padrão.

**Avisos para a documentação** (esta frente não edita `RETOMAR-TRABALHO.md`; quem fechar a rodada atualiza): (1) "Como fazemos deploy" passo 3 (`RETOMAR-TRABALHO.md:28`) passa a migrar a principal **e** as oficinas e ganha a simulação (`&simular=1`); (2) "Para ativar numa oficina", passo 1 (`:170-173`, "confirmar como as atualizações anteriores chegaram aos bancos das oficinas") fica resolvido: não chegavam, agora há o executor; (3) o item "Em aberto" da memória do projeto sobre migrations por oficina fica resolvido; (4) registrar o comando `tenants:migrate`, o evento `tenant.provision.after` e a regra "toda migration nova com guarda"; (5) registrar que `/storage` por oficina é um limite conhecido enquanto o achado adicional não for aplicado.

**Arquivos compartilhados que outras frentes também editam:** `ServiceOrder/Providers/ServiceOrderServiceProvider.php` (1 linha minha), `Tenant/Jobs/ProvisionTenantJob.php`, `Tenant/Providers/TenantServiceProvider.php`, `Config/acl.php` (recomendação para a F05), `Tenant/Http/Middleware/ResolveTenant.php` e `Tenant/Config/tenant.php` (só se os opcionais forem adotados), `tests/Feature/Workshop/MentorHistoricoTest.php`. O `public/migrate-safe.php` é de uso de todas as frentes (deploy) mas só esta o edita.

---

## Decisões em aberto

1. **Manter o cadastro público `/signup` aberto?** Cada envio cria banco e usuário MySQL na conta da HostGator e a cota é limitada. **Recomendação:** fechar (`TENANT_SIGNUP_ENABLED=false`) assim que a Oficina Teste e as oficinas reais existirem, e criar oficinas novas só pela equipe, no painel Clientes (implementar as rotas internas do item opcional). Se o plano comercial é autoatendimento, manter aberto.
2. **Como o Mentor verá o "Painel do Dono de cada oficina" (B8)?** Opção (a) **recomendada:** um único login do Mentor na instalação principal, com tela somente leitura que lê o banco da oficina escolhida via `MentorService::naOficina()`. Opção (b): criar um usuário Mentor dentro de cada oficina (login por subdomínio, sessões e senhas separadas).
3. **Correção do `/storage` por oficina (fotos):** aprovar a solução por rota do Laravel (arquivos passam a sair pelo PHP) e a remoção única de `public/storage` no servidor? **Recomendação:** sim, antes de testar B2 na Oficina Teste (ou entregar à F07). Se preferirem manter o link, é preciso um subdomínio com pasta pública própria por oficina (configuração do cPanel, fora do código).

---

## Passo a passo para o usuário

> Sempre com backup recente conferido no cPanel (Backup) antes de mexer em banco. Faça fora do horário de uso. **Nada abaixo apaga dados.**

### Parte 1 — Atualizar a instalação principal **e** todas as oficinas (depois de subir o zip)
1. Suba e extraia o zip como de costume (não apague nada). O zip deve incluir `public/migrate-safe.php` e os arquivos novos de `packages/Webkul/Tenant`.
2. **Primeiro, só olhar (não altera nada):** abra no navegador
   `https://volmastercrm.com/migrate-safe.php?key=TROQUE-ESTA-CHAVE&simular=1`
   Deve listar a instalação principal e cada oficina com "N migration(s) pendente(s)". Oficina com `Ignorada` ou `Adiada` é explicada na própria linha.
3. **Aplicar:** abra o mesmo endereço **sem** `&simular=1`. Aguarde a página terminar (não feche a aba). Cada oficina termina em `Migrada` ou `Em dia`.
   - Se aparecer **FALHOU** em alguma, copie a linha inteira e mande para o desenvolvimento. As outras oficinas já foram atualizadas e rodar de novo é seguro.
   - Se aparecer "Faltaram N oficina(s) por falta de tempo", **recarregue a página** até todas ficarem concluídas.
   - Só uma oficina: acrescente `&oficina=endereco-da-oficina`. Só a principal: `&escopo=principal`.
4. **Apague `public/migrate-safe.php` e o zip** (Gerenciador de Arquivos do cPanel).
5. (Opcional, para conferir a causa do erro antigo) No Gerenciador de Arquivos abra `storage/logs/laravel.log` e procure `indicator_definitions`: se aparecer `Base table or view not found` na hora em que a Visão do mentor mostrou o erro, era a falta de migrations nas oficinas.

### Parte 2 — Criar a "Oficina Teste Mentoria"
Antes: confirme com quem cuida do servidor que (a) o `.env` está com `TENANT_PROVISIONER=cpanel` e os dados do cPanel (`CPANEL_HOST`, `CPANEL_USERNAME`, `CPANEL_API_TOKEN`) — o sistema usa isso para criar o banco da oficina; (b) o plano tem espaço para mais um banco MySQL; (c) endereços do tipo `qualquer-nome.volmastercrm.com` abrem (subdomínio curinga no cPanel/DNS).
1. Entre na instalação principal (`https://volmastercrm.com/admin`) com o administrador → **Configurações → Clientes** → botão **Nova oficina** (abre a página de cadastro).
2. Preencha: **Nome da oficina:** `Oficina Teste Mentoria` · **Endereço da conta:** `teste-mentoria` · seu nome, e-mail e senha (mínimo 8 caracteres) do administrador da oficina de teste. Clique em **Criar minha conta** e **espere** a página responder (leva alguns minutos; não feche).
3. Volte a **Configurações → Clientes**: a oficina deve estar com status **active** (verde). Se estiver `failed`, o motivo aparece na linha — anote e mande ao desenvolvimento; para tentar de novo use **outro** endereço (ex.: `teste-mentoria2`). Se ficar em `provisioning` por mais de 10 minutos, avise o desenvolvimento.
4. Abra `https://teste-mentoria.volmastercrm.com/admin` e entre com o e-mail e a senha do passo 2.
5. Confira em 1 minuto: **Cadastro → Novo cadastro** mostra o campo **CPF/CNPJ** e ele grava (cadastre um cliente de teste e reabra); **Configurações → Funções** abre; **Configurações → Indicadores e alertas** abre.
6. **Visão do mentor** (na instalação principal, com um usuário que tenha essa permissão): a coluna "Oficina Teste Mentoria" aparece com traços (não com erro).
7. **Teste de isolamento (2 minutos):** na Oficina Teste cadastre o cliente `ZZ Isolamento`. Entre na instalação principal e em uma oficina real: ele **não pode aparecer** em Cadastro nem em Contatos. Faça o inverso com um cliente da principal.
8. Depois disso seguem os itens A2 a A6 (notificação, SMS, perfis, Ficha e piloto), sempre **dentro** da Oficina Teste.

> Para a Visão do mentor mostrar **todas** as oficinas, o usuário Mentor precisa existir na **instalação principal** (volmastercrm.com), não dentro de uma oficina.
