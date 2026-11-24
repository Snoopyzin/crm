# Especificação — F01-correcoes

Frente: **Correções de erro 404, título da OS e datas em português** (PDF: item **B7**, exceto "Visão do mentor", que é da F00).

Resumo em uma frase: o sistema já está quase todo em português; o que o patrão viu em inglês vem de **quatro pontos centrais** (a página de erro, o formatador `core()->formatDate`, o título gravado pelo Cadastro e as mensagens de validação do Laravel/VeeValidate) mais algumas chaves de tradução esquecidas em telas herdadas do Krayin. Esta especificação corrige os pontos centrais (uma edição cada) e cobre o resto com testes de varredura que impedem a volta do problema.

Regras desta frente:
- **Nenhuma migration, nenhuma coluna, nenhuma chave de ACL/menu/rota nova.** Títulos antigos são corrigidos na exibição, sem reescrever `quotes.subject` (nada se apaga).
- **Vale com o piloto ligado e desligado.** Não há bloqueio, alçada, prazo nem barreira nesta frente (por isso o modo de teste ÷10 não se aplica).
- Técnico continua sem ver valores: nada aqui expõe valor; as páginas de erro não levam dado da OS.
- Todo o texto novo em pt-BR (e em `en`, onde o pacote já mantém o par).

---

## O que já existe

### (a) Página de erro e contato de suporte

- `packages/Webkul/Admin/src/Resources/views/errors/index.blade.php`
  - `:3-5` título da aba (`admin::app.errors.{código}.title`); `:24-26` descrição; `:33` link "Voltar" (`errors.go-back`); `:46` link para o início (`errors.dashboard`); `:44` tem erro de digitação na classe (`hover:underlsine`).
  - `:50-56` bloco de suporte com **`mailto:support@example.com`** (`:52-53`): é o texto que o patrão viu. É o único lugar do repositório com esse endereço (grep em `packages`, `app`, `config`, `routes`, `resources`).
  - Usa o layout `components/layouts/anonymous.blade.php`, que lê o banco 4 vezes (`:51`, `:68`, `:78`, `:120`, todas `core()->getConfigData`).
- `packages/Webkul/Admin/src/Exceptions/Handler.php` (ligado em `Providers/AdminServiceProvider.php:54`):
  - `:48-55` `render()` só usa a página própria com `app.debug = false`; com debug ligado cai na página do framework, em inglês. (Dedução: a produção está com debug desligado, porque o patrão viu a página do Krayin com o e-mail de exemplo.)
  - `:77-98` `renderCustomResponse()`: só trata `HttpException` com status 401/403/404/503; **qualquer outro código vira 500** (405, 413, 419, 429); `ModelNotFoundException` vira 404; todo o resto vira 500. Consequências reais: sessão vencida (`TokenMismatchException`, 419) mostra "500 Internal Server Error"; `AuthorizationException` mostra 500 em vez de 403; `HttpResponseException` e `AuthenticationException` também viram 500; upload grande demais (413, fotos e vídeos do Cadastro) mostra 500.
  - `:107-118` `response()`: o HTML sai por `response()->view('admin::errors.index', compact('errorCode'))` (**linha 117, sem status**): **toda página de erro HTML sai com HTTP 200**. Importante para o B8: o patrão confere a aba "Rede" do navegador e hoje ela mostraria 200 numa página "acesso negado".
  - `:63-70` `unauthenticated()` redireciona para `route('customer.session.index')`, rota que **não existe** no projeto (resto do Bagisto).
  - `:30-40` o construtor monta `$jsonErrorMessages` com `trans()` na construção (idioma errado se o middleware de idioma ainda não rodou). `:113` usa a chave `admin::app.common.something-went-wrong`, que **não existe** em nenhum idioma.
- `packages/Webkul/Admin/src/Resources/lang/pt_BR/app.php:2343-2369` (bloco `errors`): `401` e `unauthorized` já estão em português; `dashboard`, `go-back`, `support`, `404`, `403`, `500` e `503` estão **em inglês** (idênticos ao `en/app.php:2331-2357`). Não há chaves para 405, 413, 419 e 429.
- Idioma da página de erro: rota inexistente não passa pelo middleware `admin_locale` (`Http/Middleware/Locale.php:31-38`, aplicado nos grupos de rota em `AdminServiceProvider.php:39,43`), então a página usa o idioma padrão `config('app.locale')` = `env('APP_LOCALE', 'en')` (`config/app.php:95`). Ou seja: se o `.env` do servidor não tiver `APP_LOCALE=pt_BR`, a 404 sai em inglês mesmo com o resto do sistema em português.
- **Contato real de suporte que já existe no código** (nada será inventado):
  - `gabriel@volmastercrm.com` em `packages/Webkul/Admin/src/Config/support.php:9-10` e `:18` (página Ajuda e recursos, lida por `Http/Controllers/HelpController.php:15` e coberta por `tests/Feature/HelpTest.php:15`), e em `packages/Webkul/Tenant/src/Resources/views/errors/suspended.blade.php:17` e `failed.blade.php:17`.
  - Existem os campos `general.support.contact.whatsapp` (padrão vazio) e `general.support.contact.email` (padrão `suporte@volmastercrm.com`) em `packages/Webkul/Admin/src/Config/core_config.php:50-62`, mas **nada no código lê esses dois campos** (grep `general.support`): não servem de fonte, e o padrão `suporte@...` pode não existir de verdade.
  - Outros e-mails do domínio, usados para outras finalidades: `MAIL_FROM_ADDRESS=contato@volmastercrm.com` (`.env.example:42`) e o remetente padrão do VAPID (`ServiceOrder/src/Services/WebPush.php:132`). A landing (`resources/views/landing.blade.php`) não tem contato. **Não existe número de WhatsApp de suporte em lugar nenhum do repositório.**
- Bug vizinho de 401: `packages/Webkul/Admin/src/Http/Middleware/Bouncer.php:30` e `:42` gravam no flash `trans('admin::app.errors.401')`, que é um **array** (`description` + `title`). O componente `components/flash-group/index.blade.php:38` imprime `{{ session($key) }}`: com array dá `TypeError` e a **tela de login quebra (500)** logo depois que o administrador desativa um usuário que estava logado (ou que a função perde todas as permissões).
- `packages/Webkul/Admin/src/Bouncer.php:34-38` (`Bouncer::allow`) aborta com **401** quando falta permissão (não 403): assunto da F05; esta frente entrega a página 403/401 em português e o status HTTP real.

### (b) Título da OS

- O título da tela da OS é o campo `quotes.subject` impresso cru: `packages/Webkul/ServiceOrder/src/Resources/views/view.blade.php:3` (título da aba) e `:12` (cabeçalho).
- A causa: `packages/Webkul/ServiceOrder/src/Http/Controllers/CadastroController.php:221` grava `'subject' => 'Cadastro - '.($vehicle?->placa ?? (request('name') ?: request('cpf_cnpj')))` e **nada nunca atualiza** esse texto quando a OS muda de etapa. OS criadas em "Nova Ordem de Serviço" (`ServiceOrderController::store`, `:154-179`) usam o título digitado, sem etapa.
- Outros lugares que imprimem o `subject` cru (todos mostram "Cadastro - ABC1D23" numa OS que já passou do Cadastro):
  - `ServiceOrder/src/DataGrids/ServiceOrderDataGrid.php:81-88` (coluna "Serviço" de todas as listas, inclusive as 9 etapas);
  - `ServiceOrder/src/Resources/views/print.blade.php:203` ("Serviço solicitado / defeito relatado");
  - `ServiceOrder/src/Services/TecnicoTasks.php:58` (Minhas tarefas);
  - `ServiceOrder/src/Http/Controllers/ServiceOrderStageController.php:303` (assunto do e-mail do orçamento);
  - `ServiceOrder/src/Resources/views/partials/pos-venda.blade.php:44` (histórico do veículo);
  - Portal do cliente: `Portal/src/Resources/views/dashboard.blade.php:39`, `vehicles/view.blade.php:48`, `service-orders/index.blade.php:26`, `service-orders/view.blade.php:3` e `:7`; e `Portal/src/Resources/views/admin/service-requests/view.blade.php:94`.
  - **Não mexer**: `ServiceOrder/src/Resources/views/partials/orcamento-send.blade.php:11` reenvia o `subject` cru num campo oculto (`servico`) para `admin.service_orders.update`; trocar esse valor gravaria texto alterado.
- O nome da etapa atual já existe em dois formatos: `quotes.workshop_status` (constantes `ServiceOrder::STAGE_*`, `Models/ServiceOrder.php:23-46`) e a tradução `service_order::app.stages.<etapa>` (`lang/pt_BR/app.php:18-30`). A barra de etapas da tela (`partials/progress.blade.php:11-16`) e o cabeçalho da impressão (`print.blade.php:124`) já mostram a etapa certa.
- O modelo já tem helpers de texto de exibição no mesmo estilo (`previsaoTexto()`, `Models/ServiceOrder.php:309`).

### (c) Datas

- **Ponto central:** `packages/Webkul/Core/src/Core.php:171-174` — `formatDate($date, $format = 'd M Y h:iA')` faz `Carbon::parse($date)->format($format)`. É daí que sai "20 Oct 2026 09:15AM". **Não existe `formatDateWithTimeZone`** nesta versão. São **32 chamadas em 25 arquivos**: 29 no formato padrão, 1 com `'d M Y'` (`Admin/src/DataGrids/Quote/QuoteDataGrid.php:165`) e 2 com `'d-m-Y'` (`Admin/.../quotes/pdf.blade.php:224,246`, já numérico). Principais: todas as listas (`ServiceOrderDataGrid.php:153`, Veículos, Mecânicos, Peças, Serviços, Grupos de peças, Boxes, Depósitos, Tags, Chaves de API, Documentos fiscais, Pedidos do portal, Atendimentos remotos, Atividades, Organizações), `PartInventory/.../view.blade.php:146`, `Fiscal/.../view.blade.php:70` e os blocos da OS `partials/diagnostico.blade.php:39`, `teste.blade.php:24,40`, `pos-venda.blade.php:20,44`, `faturamento.blade.php:10`.
- O fuso e o idioma do Carbon já seguem o Laravel: `Carbon\Laravel\ServiceProvider` está ativo (`bootstrap/cache/packages.php:108`) e troca o idioma do Carbon quando `app()->setLocale()` roda. Porém `->format('M')`, `'D'`, `'l'`, `'F'`, `'A'` do PHP **nunca** são traduzidos, só `translatedFormat()`/`isoFormat()`.
- A tela "Diagnóstico" do PDF: não existe tela com esse nome em Configurações ou Ajuda. É a **etapa Diagnóstico da OS**: item de menu `diagnostico` (`ServiceOrder/src/Config/menu.php:26-31`, lista `stage-index.blade.php`) e o bloco `partials/diagnostico.blade.php`, que (`view.blade.php:329-331`) **só aparece com o piloto desligado** — por isso a data `core()->formatDate($serviceOrder->diagnostico_concluido_em)` (`diagnostico.blade.php:39`) saiu em inglês na produção. Com o piloto ligado o bloco "Fluxo da OS" (`partials/workflow.blade.php:17,490`) já usa `d/m/Y H:i`. `public/diag.php` é um script técnico de implantação (texto simples, sem datas): não é a tela citada.
- O pacote ServiceOrder já usa `d/m/Y` / `d/m/Y H:i` em dezenas de pontos (`workflow.blade.php:17`, `alerts/index.blade.php:43`, `PainelDonoService.php:105,347,383,384`, `PainelComissao.php:87,108`...). `PainelDonoService.php:61` já força `->locale('pt_BR')`; `TecnicoTasks.php:159` usa `translatedFormat('F/Y')` dependendo do idioma da requisição.
- Pontos que geram data/hora em inglês fora do `Core::formatDate`:
  - `Attribute/src/Repositories/AttributeValueRepository.php:240,249,258` (`'D M d, Y'`, `'D M d, Y H:i A'`) e `Automation/src/Helpers/Entity/AbstractEntity.php:180,191` (mesmos formatos);
  - `Admin/src/DataGrids/Mail/EmailDataGrid.php:134-135` (`'h:i A'`, `'M d'`);
  - `Admin/src/Helpers/Dashboard.php:226` (`'d M'`, texto do período do Início) e `Admin/src/Helpers/Reporting/AbstractReporting.php:261-262, 308-309, 336-337` (`'d M'`);
  - `Admin/src/Helpers/Reporting/Lead.php:488-502`: rótulos de gráfico do Início `'M d'`, **`'Week '.…`** (palavra em inglês), `'M Y'`;
  - JavaScript: `Admin/src/Resources/assets/js/plugins/admin.js:86-145` (`$admin.formatDate`) usa `Intl.DateTimeFormat("en-US")` e `toLocaleString("en-US")` para o mês; é chamado só em `Admin/src/Resources/views/components/activities/index.blade.php:158` (2×) e `:228`, sempre com `'d MMM yyyy, h:mm A'` ("20 Oct 2026, 9:15 AM");
  - Calendários: `plugins/flatpickr.js:12-27` só traduz es/ar/fa/tr — **em pt_BR o calendário abre em inglês** (filtros de data de todas as listas, filtros do Início). `activities/index.blade.php:430` passa `locale="pt_BR"` ao vue-cal, que só conhece `pt-br`.
  - DataGrids com coluna de data **sem** formatação (saem como `2026-10-20 09:15:00` / `2026-10-20`): `Admin/.../Settings/UserDataGrid.php:81-89` (Configurações > Usuários), `Portal/.../ServiceRequestDataGrid.php:59-64` (`data_preferencia`) e `Admin/.../Lead/LeadDataGrid.php:277-285`.
- Os bundles compilados ficam em `public/admin/build` (versionados no zip). O time evita recompilar (comentário em `dashboard/index/pending-service-orders.blade.php:133`). Há Node e Vite instalados no PC (`packages/Webkul/Admin/node_modules`), mas **esta especificação não depende de recompilar** para datas e calendário (ver Fase 1 e Fase 2).

### Fuso e idioma configuráveis

- `config/app.php:82` `timezone = env('APP_TIMEZONE', 'Asia/Kolkata')`; `:95` `locale = env('APP_LOCALE', 'en')`; `:129` `fallback_locale = 'en'`. `.env.example:6-8` já tem `America/Sao_Paulo`, `pt_BR`, `BRL`. O idioma do painel é um valor **global da oficina** em `core_config` (`general.general.locale_settings.locale`), gravado por `Admin/src/Http/Controllers/LocaleController.php:20-32` quando alguém clica no seletor do cabeçalho (`components/layouts/header/index.blade.php:69` oferece `Português` e `English` a todos os usuários, sem checar permissão).
- `lang/` na raiz tem só `en` (`auth`, `pagination`, `passwords`, `validation`). **Não existe `lang/pt_BR`**: toda mensagem padrão do Laravel (validação do servidor, login, redefinição de senha) cai no inglês.

### (e) Varredura de textos em inglês (resultado)

Foram varridos, com scripts somente leitura, todas as views Blade e controllers de ServiceOrder, Fiscal, PartInventory, Mechanic, Vehicle, WorkBay, RemoteService, Portal, WhatsApp, Tenant, Marketing, Warehouse e Admin, mais os arquivos de idioma:

| # | Achado | Onde | Gravidade |
|---|--------|------|-----------|
| E1 | Página de erro: inglês, e-mail de exemplo, HTTP 200, 419/403/405/413/429 virando 500 | Handler + view + lang (itens (a)) | alta |
| E2 | Data "20 Oct 2026 09:15AM" | `Core::formatDate` e 11 pontos (item (c)) | alta |
| E3 | Título da OS com etapa velha | item (b) | média |
| E4 | Mensagens de validação do **servidor** em inglês ("The name field is required."): não há `lang/pt_BR/validation.php` (nem auth, passwords, pagination). Aparece no login, redefinição de senha e em todo `$this->validate()` sem mensagem própria | raiz `lang/` | alta |
| E5 | Mensagens de validação do **navegador** (VeeValidate) em inglês: o dicionário do bundle só tem ar/en/es/fa/tr; para `pt_BR` cai em "`<campo>` is not valid" (confirmado no bundle: sem "é obrigatório"/"é inválido"). Afeta 203 campos `rules="required"` e outros em Usuários, Funções, Grupos, Depósitos, Mecânicos, Veículos, Peças, Serviços, Boxes, Atendimento remoto, Contatos | `plugins/vee-validate.js:142-187` + bundle | média |
| E6 | `Admin/lang/pt_BR/app.php` com **145 valores idênticos ao inglês** (lista no Anexo A). Visíveis no dia a dia: `errors.*`, `users.not-permission` (tela de login), `dashboard.index.start-date/end-date` (filtro do Início), `settings.users.index.create.select-at-lest-one-group` (formulário de usuário), `validations.message.decimal`, `acl.data-transfer/imports/import` (árvore de permissões da Função), `layouts.events/campaigns/...` | lang Admin | média |
| E7 | **Chaves usadas no código e ausentes em pt_BR e en** (a tela mostraria o texto da chave): `admin::app.common.something-went-wrong` (Handler) e ~40 outras de telas do Krayin fora do menu (lista no Anexo B); mais dois erros de digitação: `Admin::app.settings.webhooks.edit.name` (`settings/webhook/edit.blade.php:305-306`, "A" maiúsculo) e `shop::app.mail.update-password.subject` (`Notifications/User/UserUpdatePassword.php:31`, namespace que não existe) | vários | média/baixa |
| E8 | Flash em array no `Bouncer` → login quebra | `Http/Middleware/Bouncer.php:30,42` | média |
| E9 | E-mail de redefinição de senha com assunto em inglês "User Reset Password" (o Laravel deriva o assunto do nome da classe quando `toMail` não define `->subject()`): `Admin/src/Notifications/User/UserResetPassword.php:22-26` | e-mail | média |
| E10 | Cabeçalho de coluna literal `Date`: `Fiscal/src/Resources/views/view.blade.php:63` e `PartInventory/src/Resources/views/view.blade.php:133` | views | baixa |
| E11 | Seletor de idioma oferece "English", mas o `en` está incompleto: ServiceOrder tem 81 chaves em `en` contra 200 em `pt_BR`; WhatsApp não tem `en`; Installer tem 71 chaves a menos. Quem clicar em English deixa a **oficina inteira** com chaves cruas na tela | header + lang | média |
| E12 | Calendário (Flatpickr/vue-cal) em inglês | item (c) | média |
| E13 | Rótulos de gráfico do Início ("Oct 20", "Week 42, 2026") | `Reporting/Lead.php` | baixa |
| E14 | `Handler::unauthenticated` aponta para rota inexistente | `Handler.php:69` | baixa |
| — | **Sem inglês**: todas as views e controllers de ServiceOrder, Fiscal, PartInventory, Mechanic, Vehicle, WorkBay, RemoteService, Portal, WhatsApp, Tenant (único termo técnico: "Twilio: Account SID", nome do provedor); mensagens `flash`/`validate` dos controllers (todas em português). Os e-mails (`emails/*.blade.php`) usam chaves pt_BR. | — | — |

---

## Requisitos do PDF → como serão atendidos

Itens do B7 desta frente (a "Visão do mentor" é da F00 e fica fora).

| Requisito do PDF | Situação hoje | Como será atendido |
|---|---|---|
| **Título da OS: mostrar a etapa ATUAL** (hoje "Cadastro" em OS que estão em Orçamento) | **Falta.** Existem a etapa e a tradução; o título é o texto gravado no Cadastro. | Novos métodos no modelo (`tituloAtual()`, `identificacao()`, `semPrefixoDeEtapa()`); a tela da OS passa a mostrar `#id · Orçamento - ABC1D23`; o Cadastro deixa de gravar a etapa; listas, impressão, Minhas tarefas, e-mail e Portal mostram o título sem a etapa velha. Nada é reescrito no banco. |
| **Datas em português em todo o sistema** (`20 Oct 2026 09:15AM` → `20/10/2026 09:15`) | **Parcial.** ServiceOrder já está em `d/m/Y H:i`; as telas herdadas e os blocos que usam `core()->formatDate` estão em inglês. | Um ponto central (`Core::formatDate` com padrão `d/m/Y H:i`, fuso da aplicação, e `translatedFormat` quando o formato pede nome de mês) + 11 pontos isolados (lista na seção de mudanças) + calendário em português + teste que impede formatos em inglês. |
| **Página de erro 404 em português, com o contato de suporte real** (hoje `support@example.com`) | **Parcial.** A página existe; os textos 404/403/500/503 estão em inglês; contato é de exemplo; sai com HTTP 200; 419/405/413/429 viram 500. | Textos completos em pt-BR (401, 403, 404, 405, 413, 419, 429, 500, 503), contato lido de `config('support.contact_email'/'contact_whatsapp')` (padrão = o e-mail que a página Ajuda já mostra; WhatsApp opcional via `.env`; texto neutro "suporte da Volmaster" se vazio), status HTTP real, idioma da oficina aplicado, rota de login corrigida. |
| **(d) Tela "Diagnóstico": corrigir data e textos em inglês** | **Parcial.** É a etapa Diagnóstico da OS (ver item (c)). | A data do bloco passa a `dd/mm/aaaa hh:mm` pelo ponto central; "Date" das tabelas Fiscal/Peças vira "Data". |
| **(e) Varredura de textos em inglês** nas telas principais (menu, OS, cadastro, financeiro, mentoria, erros, validações) | **Parcial.** Achados E1-E14. | Correções dos achados (lang pt_BR do Admin, chaves ausentes, `lang/pt_BR` do Laravel, assunto de e-mail, flash do login) + 4 arquivos de teste de varredura. |
| **Teste: navegar por todas as telas sem ver texto em inglês** | **Falta.** Não existe teste de varredura. | `TextosEmPortuguesTest` abre as telas do menu como proprietário e recusa frases em inglês conhecidas, nomes de mês em inglês, "AM/PM" e **chaves de tradução cruas**; mais varreduras estáticas (chave usada no código existe em pt_BR; nenhum formato de data em inglês; nenhum `support@example.com`). |

Linhas do roteiro de 72 afetadas: **6** (a página "acesso negado" tem de ser em português e com status 403/401 real na aba Rede), **5, 9 a 66** (a mesma OS passa pelas etapas: o título deve mostrar cada etapa e todos os carimbos aparecem em `dd/mm/aaaa hh:mm`, por exemplo as linhas 14, 15, 28, 34, 44, 54), **67 a 70** (Painel do Dono: já está em português; a varredura protege). A linha 71 (Visão do mentor) é da F00.

---

## Mudanças por arquivo

Ordem sugerida: 1) idioma e página de erro; 2) `Core::formatDate` e pontos de data; 3) título da OS; 4) `lang/pt_BR` do Laravel; 5) calendário; 6) testes; 7) Fase 2 (opcional, recompilar).

### 1. Página de erro e contato

**1.1 `packages/Webkul/Admin/src/Exceptions/Handler.php`** (reescrever o corpo; as assinaturas públicas `__construct` e `render` continuam). Código alvo:

```php
<?php

namespace Webkul\Admin\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /** Códigos com página própria (admin::app.errors.<código>). Qualquer outro vira 500. */
    public const PAGINAS = [401, 403, 404, 405, 413, 419, 429, 500, 503];

    /** Mantido por compatibilidade; as mensagens JSON saem de mensagemJson(). */
    protected $jsonErrorMessages = [];

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    public function render($request, Throwable $exception)
    {
        if (config('app.debug')) {
            return parent::render($request, $exception);
        }

        return $this->renderCustomResponse($request, $exception);
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->mensagemJson(401)], 401);
        }

        return redirect()->guest(route('admin.session.create'));   // era customer.session.index (não existe)
    }

    private function renderCustomResponse($request, Throwable $exception)
    {
        // Mesmas conversões do Laravel: modelo não achado → 404, sem autorização → 403, CSRF vencido → 419.
        $exception = $this->prepareException($this->mapException($exception));

        if ($exception instanceof HttpResponseException) {
            return $exception->getResponse();
        }

        if ($exception instanceof AuthenticationException) {
            return $this->unauthenticated($request, $exception);
        }

        if ($exception instanceof ValidationException) {
            return parent::render($request, $exception);   // igual a antes
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

        return $this->response(in_array($status, self::PAGINAS, true) ? $status : 500);
    }

    private function response(int $errorCode)
    {
        if (request()->expectsJson()) {
            return response()->json(['message' => $this->mensagemJson($errorCode)], $errorCode);
        }

        $this->aplicarIdioma($errorCode);

        // ÚLTIMO argumento = status HTTP real (antes saía 200 em toda página de erro).
        return response()->view('admin::errors.index', compact('errorCode'), $errorCode);
    }

    private function mensagemJson(int $codigo): string
    {
        $chave = "admin::app.errors.{$codigo}.title";

        return Lang::has($chave) ? trans($chave) : trans('admin::app.common.something-went-wrong');
    }

    /**
     * Rota inexistente não passa pelo middleware admin_locale: aplica o idioma escolhido na oficina
     * (mesma regra de Webkul\Admin\Http\Middleware\Locale). Erros 5xx podem ser do próprio banco:
     * nesses não se consulta o banco, vale o idioma padrão do .env.
     */
    private function aplicarIdioma(int $codigo): void
    {
        if ($codigo < 500) {
            app()->setLocale(core()->getConfigData('general.general.locale_settings.locale') ?: app()->getLocale());
        }
    }
}
```

Tabela de decisão (antes → depois):

| Entrada | Antes | Depois |
|---|---|---|
| Rota inexistente / `abort(404)` | página 404 com HTTP 200 | página 404 com HTTP 404 |
| `ModelNotFoundException` | 404 (HTTP 200) | 404 (HTTP 404) |
| `AuthorizationException` (Gate/FormRequest) | 500 | **403** |
| `TokenMismatchException` (CSRF vencido) | 500 | **419** "Sessão expirada" |
| `abort(401/403/503)` | página própria, HTTP 200 | página própria, status real |
| 405, 413, 429 | 500 | página própria + status real |
| `HttpResponseException` | 500 | a resposta original |
| `AuthenticationException` | 500 | redireciona ao login (HTML) / 401 JSON |
| `ValidationException` | `parent::render` | igual |
| Qualquer outro erro | 500 | 500 (sem vazar a mensagem) |
| JSON | título do erro, mas 503 virava chave crua | título do erro; fallback traduzido |

**1.2 `packages/Webkul/Admin/src/Resources/views/errors/index.blade.php`**: trocar o bloco de suporte (linhas 50-56) e corrigir a classe da linha 44.

```blade
@php
    $suporteEmail = trim((string) config('support.contact_email'));
    $suporteWhatsapp = preg_replace('/\D/', '', (string) config('support.contact_whatsapp'));
    $suporteWhatsappTexto = $suporteWhatsapp === '' ? '' : (strlen($suporteWhatsapp) >= 12
        ? '+'.substr($suporteWhatsapp, 0, 2).' ('.substr($suporteWhatsapp, 2, 2).') '.substr($suporteWhatsapp, 4, -4).'-'.substr($suporteWhatsapp, -4)
        : '+'.$suporteWhatsapp);
    $classeLink = 'font-semibold text-blue-600 transition-all hover:underline';
@endphp

<p class="text-sm text-gray-800 dark:text-white">
    @if ($suporteEmail !== '')
        @lang('admin::app.errors.support', ['link' => e('mailto:'.$suporteEmail), 'email' => e($suporteEmail), 'class' => $classeLink])
    @else
        @lang('admin::app.errors.support-generic')
    @endif

    @if ($suporteWhatsapp !== '')
        @lang('admin::app.errors.support-whatsapp', ['link' => e('https://wa.me/'.$suporteWhatsapp), 'whatsapp' => e($suporteWhatsappTexto), 'class' => $classeLink])
    @endif
</p>
```
(`@lang` imprime sem escapar: por isso os valores passam por `e()`.) Linha 44: `hover:underlsine` → `hover:underline`. O restante da view não muda (nem o layout `anonymous`).

**1.3 `packages/Webkul/Admin/src/Config/support.php`** — uma única fonte para a página Ajuda e para a página de erro:

```php
<?php

// Contato de suporte da Volmaster. O padrão é o e-mail que a página Ajuda e recursos já mostrava;
// pode ser trocado no .env sem mexer em código. WhatsApp é opcional (só dígitos, com 55 + DDD).
$email = trim((string) env('VOLMASTER_SUPPORT_EMAIL', 'gabriel@volmastercrm.com'));
$whatsapp = preg_replace('/\D/', '', (string) env('VOLMASTER_SUPPORT_WHATSAPP', ''));
$link = $email !== '' ? 'mailto:'.$email : ($whatsapp !== '' ? 'https://wa.me/'.$whatsapp : '#');

return [
    'contact_email' => $email,
    'contact_whatsapp' => $whatsapp,

    'services' => [
        [
            'icon' => 'support',
            'title' => 'admin::app.help.index.services.support.title',
            'description' => 'admin::app.help.index.services.support.description',
            'url' => $link,
            'url_label' => $email !== '' ? $email : ($whatsapp !== '' ? '+'.$whatsapp : ''),
        ],
    ],

    'resources' => [],

    'community' => [],

    'contact_url' => $link,
];
```
`HelpTest` continua valendo (`contact_url` aparece na página). Sem `.env` definido o comportamento da Ajuda não muda (mesmo e-mail).

**1.4 `packages/Webkul/Tenant/src/Resources/views/errors/suspended.blade.php:17` e `failed.blade.php:17`**: trocar o e-mail fixo por `{{ config('support.contact_email') }}` (no `href` e no texto; se vazio, mostrar só "o suporte da Volmaster", sem link).

**1.5 `packages/Webkul/Admin/src/Http/Middleware/Bouncer.php:30` e `:42`**: `trans('admin::app.errors.401')` → `trans('admin::app.errors.unauthorized')` (string; o array derrubava a tela de login).

**1.6 `config/app.php:95`**: `'locale' => env('APP_LOCALE', 'pt_BR')` (o produto é só em português; evita a 404 e as páginas públicas em inglês se o `.env` não tiver a variável). **Não** alterar `timezone` nem `fallback_locale` (ver Riscos).

**1.7 E-mail de redefinição de senha** — `Admin/src/Notifications/User/UserResetPassword.php:22-26`: adicionar `->subject(trans('admin::app.emails.common.user.forget-password.subject'))` antes de `->view(...)`. Textos: pt_BR `Redefinição de senha` (hoje "Redefinir Senha do Cliente", `lang/pt_BR/app.php:2328`); en `Reset your password` (hoje "Customer Reset Password", `lang/en/app.php:2316`).

**1.8 `Admin/src/Notifications/User/UserUpdatePassword.php:31`**: trocar `shop::app.mail.update-password.subject` (namespace inexistente) por `admin::app.emails.common.user.update-password-subject` (nova chave; pt "Senha atualizada", en "Password updated"). A classe não é usada hoje (sem chamadas); a view `shop::emails.users.update-password` também não existe — registrado em Riscos, não corrigido aqui.

### 2. Datas

**2.1 `packages/Webkul/Core/src/Core.php:171-174`** (edição mínima e justificada: é o único ponto por onde passam 32 chamadas; o `core()` resolve direto esta classe, não há como estender por pacote sem trocar o binding):

```php
/**
 * Formata data/hora no padrão brasileiro (dd/mm/aaaa hh:mm), no fuso da aplicação.
 * Formatos com nome de mês ou de dia da semana (M, F, D, l) saem no idioma da tela.
 */
public function formatDate($date, $format = 'd/m/Y H:i')
{
    if ($date === null || $date === '') {
        return '';   // antes: Carbon::parse(null) devolvia "agora"
    }

    $data = Carbon::parse($date)->timezone(config('app.timezone'));

    return preg_match('/(?<!\\\\)[DlFM]/', $format)
        ? $data->translatedFormat($format)
        : $data->format($format);
}
```
Efeitos: "20 Oct 2026 09:15AM" → "20/10/2026 09:15" em todas as chamadas padrão; `'d-m-Y'` continua igual; objetos Carbon em UTC passam a sair no fuso da aplicação (`America/Sao_Paulo` no servidor, ver Passo a passo).

**2.2 Chamadas que precisam de ajuste por causa do novo padrão (data pura, sem hora):**
- `ServiceOrder/src/Resources/views/partials/faturamento.blade.php:10`: `core()->formatDate($boleto->vencimento)` → `core()->formatDate($boleto->vencimento, 'd/m/Y')` (vencimento é data; senão sairia "20/10/2026 00:00").
- `Admin/src/DataGrids/Quote/QuoteDataGrid.php:165`: `'d M Y'` → `'d/m/Y'`.

**2.3 Formatos em inglês isolados** (trocar o literal):

| Arquivo:linha | Hoje | Novo |
|---|---|---|
| `Attribute/src/Repositories/AttributeValueRepository.php:240` | `'D M d, Y'` | `'d/m/Y'` |
| idem `:249` e `:258` | `'D M d, Y H:i A'` | `'d/m/Y H:i'` |
| `Automation/src/Helpers/Entity/AbstractEntity.php:180` | `'D M d, Y'` | `'d/m/Y'` |
| idem `:191` | `'D M d, Y H:i A'` | `'d/m/Y H:i'` |
| `Admin/src/DataGrids/Mail/EmailDataGrid.php:134` | `'h:i A'` | `'H:i'` |
| idem `:135` | `'M d'` | `'d/m'` |
| `Admin/src/Helpers/Dashboard.php:226` | `format('d M')` (2×) | `format('d/m/Y')` (2×) |
| `Admin/src/Helpers/Reporting/AbstractReporting.php:261,262,308,309,336,337` | `'d M'` | `'d/m'` |
| `Admin/src/Helpers/Reporting/Lead.php:492` e `:500` | `'M d'` | `'d/m'` |
| idem `:494` | `'Week '.$date->format('W, Y')` | `'Sem. '.$date->format('W/Y')` |
| idem `:496` | `'M Y'` | `'m/Y'` |
| `ServiceOrder/src/Services/TecnicoTasks.php:159` | `$start->translatedFormat('F/Y')` | `$start->locale('pt_BR')->translatedFormat('F/Y')` (igual ao `PainelDonoService.php:61`) |

**2.4 Colunas de data sem formatação** (adicionar `closure`, como as demais listas):
- `Admin/src/DataGrids/Settings/UserDataGrid.php:81-89`: `'closure' => fn ($row) => core()->formatDate($row->created_at),`
- `Portal/src/DataGrids/ServiceRequestDataGrid.php:59-64` (`data_preferencia`): `'closure' => fn ($row) => $row->data_preferencia ? core()->formatDate($row->data_preferencia, 'd/m/Y') : '-',`
- `Admin/src/DataGrids/Lead/LeadDataGrid.php:277-285` (`created_at`, tela fora do menu): mesmo closure do usuário.

**2.5 Cabeçalhos `Date`:** `Fiscal/src/Resources/views/view.blade.php:63` e `PartInventory/src/Resources/views/view.blade.php:133`: `Date` → `Data`.

**2.6 `$admin.formatDate` sem recompilar:** em `Admin/src/Resources/views/components/activities/index.blade.php:158` (2×) e `:228`, trocar o formato `'d MMM yyyy, h:mm A'` por `'DD/MM/yyyy HH:mm'`. O `formatDate` do bundle usa só os números nesses tokens (`DD`, `MM`, `yyyy`, `HH`, `mm`), sem nome de mês: sai "20/10/2026 09:15" no fuso `config('app.timezone')` que o componente já passa (`:518`). Limitação herdada do bundle: o `Intl.DateTimeFormat` do `formatDate` usa `hour12: false` e alguns navegadores mostram "24:05" à meia-noite; isso só é corrigido na Fase 2 (`hourCycle: 'h23'` em `plugins/admin.js`).

**2.7 Calendário (Flatpickr) em português sem recompilar** — criar `packages/Webkul/ServiceOrder/src/Resources/views/components/calendario-pt-br.blade.php` e registrar no listener que já existe em `ServiceOrder/src/Providers/ServiceOrderServiceProvider.php:53-58` (`admin.layout.body.after`), junto de `money-mask` e `workshop-bell`:

```php
$viewRenderEventManager->addTemplate('service_order::components.calendario-pt-br');
```
Conteúdo da view (o `<script type="module">` executa depois do módulo `app.js` do cabeçalho e antes do `load` que monta o Vue, que é quando os calendários são criados):

```blade
{{-- Calendário (Flatpickr) em português: o bundle só traduz es/ar/fa/tr. Vale só com o painel em português. --}}
@if (str_starts_with(app()->getLocale(), 'pt'))
    <script type="module">
        if (window.Flatpickr && typeof window.Flatpickr.localize === 'function') {
            window.Flatpickr.localize({
                weekdays: {
                    shorthand: ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'],
                    longhand: ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'],
                },
                months: {
                    shorthand: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'],
                    longhand: ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
                },
                firstDayOfWeek: 0,
                rangeSeparator: ' até ',
                weekAbbreviation: 'Sem',
                scrollTitle: 'Role para aumentar',
                toggleTitle: 'Clique para alternar',
                time_24hr: true,
            });
        }
    </script>
@endif
```
`localize()` mescla com o padrão do Flatpickr, então a lista parcial basta. O valor gravado continua `AAAA-MM-DD` (só os nomes mudam).

**2.8 (fora do menu, opcional)** `Admin/src/Resources/views/activities/index.blade.php:430` → `locale="{{ str_replace('_', '-', strtolower(app()->getLocale())) }}"` (vue-cal conhece `pt-br`); `:435` título em inglês "Drag to change start time" → "Arraste para mudar o horário de início"; `:861` o `toLocaleDateString('en-US', …)` do rótulo de exibição → `'pt-BR'` (as duas chamadas de `:582` são parâmetros de API e **não** devem mudar).

### 3. Título da OS

**3.1 `packages/Webkul/ServiceOrder/src/Models/ServiceOrder.php`** — adicionar, logo depois de `previsaoTexto()` (`:309-312`):

```php
/**
 * Tira do título o nome de etapa que o Cadastro gravava na frente ("Cadastro - ABC1D23" → "ABC1D23").
 * Só remove quando a etapa vem seguida de separador (" - ", " – ", " — " ou ":"): "Diagnóstico de motor" fica inteiro.
 */
public static function semPrefixoDeEtapa(?string $titulo): string
{
    $titulo = trim((string) $titulo);

    $etapas = collect(array_merge(self::stagesFlow(), [self::STAGE_CONCLUIDA, self::STAGE_CANCELADA]))
        ->map(fn (string $etapa) => preg_quote($etapa, '/'))
        ->implode('|');

    return trim(preg_replace('/^(?:'.$etapas.')\s*[-–—:]\s*/iu', '', $titulo) ?? $titulo);
}

/** Nome da etapa atual para exibição ("Orçamento"); vazio se a OS não tem etapa. */
public function etapaRotulo(): string
{
    $status = (string) $this->workshop_status;
    $chave = 'service_order::app.stages.'.$status;

    return $status === '' ? '' : (\Illuminate\Support\Facades\Lang::has($chave) ? trans($chave) : $status);
}

/** Identificação da OS sem a etapa: título limpo ou, se vazio, placa ou nome do cliente. */
public function identificacao(): string
{
    $base = self::semPrefixoDeEtapa($this->subject);

    return $base !== '' ? $base : (string) ($this->vehicle?->placa ?: $this->person?->name ?: '');
}

/** Título das telas: "<Etapa atual> - <identificação>" (ex.: "Orçamento - ABC1D23"). */
public function tituloAtual(): string
{
    $etapa = $this->etapaRotulo();
    $identificacao = $this->identificacao();

    return $etapa !== '' && $identificacao !== '' ? $etapa.' - '.$identificacao : $etapa.$identificacao;
}
```
(Atenção ao carregar: `identificacao()` acessa `vehicle`/`person` só quando o título limpo é vazio.)

**3.2 `ServiceOrder/src/Resources/views/view.blade.php:3` e `:12`:** `{{ $serviceOrder->subject }}` → `{{ $serviceOrder->tituloAtual() }}` (título da aba e cabeçalho). Resultado: `#11 · Orçamento - ABC1D23`.

**3.3 `ServiceOrder/src/Resources/views/edit.blade.php`:** logo abaixo do título (`:12-14`) acrescentar uma linha discreta `#{{ $serviceOrder->id }} &middot; {{ $serviceOrder->tituloAtual() }}` (`text-sm text-gray-500`). O campo "Título" do formulário (`:122`) continua com o valor gravado (cru).

**3.4 `ServiceOrder/src/Http/Controllers/CadastroController.php:221`:** parar de gravar a etapa: `'subject' => $vehicle?->placa ?? (request('name') ?: request('cpf_cnpj')),`. As OS antigas continuam com "Cadastro - ..." no banco e são corrigidas na exibição por `semPrefixoDeEtapa()`.

**3.5 Listas e demais telas (usam o título sem etapa, porque a etapa tem coluna própria):**
- `ServiceOrder/src/DataGrids/ServiceOrderDataGrid.php:81-88`: acrescentar na coluna `subject` `'closure' => fn ($row) => ServiceOrder::semPrefixoDeEtapa($row->subject),` (importar `Webkul\ServiceOrder\Models\ServiceOrder`). Busca e ordenação continuam no SQL cru.
- `ServiceOrder/src/Resources/views/print.blade.php:203`: `$serviceOrder->subject` → `$serviceOrder->identificacao()`.
- `ServiceOrder/src/Services/TecnicoTasks.php:58`: `'servico' => ServiceOrder::semPrefixoDeEtapa($os->subject),`.
- `ServiceOrder/src/Http/Controllers/ServiceOrderStageController.php:303`: `... .' - '.$serviceOrder->identificacao()`.
- `ServiceOrder/src/Resources/views/partials/pos-venda.blade.php:44`: `$pastOrder->subject` → `$pastOrder->identificacao()`.
- Portal (a etapa já aparece no selo `status-badge`): `dashboard.blade.php:39`, `vehicles/view.blade.php:48`, `service-orders/index.blade.php:26`, `service-orders/view.blade.php:3` e `:7` → `$serviceOrder->identificacao()`.
- `Portal/src/Resources/views/admin/service-requests/view.blade.php:94` → `$serviceRequest->serviceOrder->tituloAtual()`.
- **Não** alterar `partials/orcamento-send.blade.php:11`.

### 4. Textos (arquivos de idioma)

**4.1 `packages/Webkul/Admin/src/Resources/lang/pt_BR/app.php`** e **`en/app.php`** (manter o par): ver a seção "Textos da interface (pt-BR)" e os Anexos A e B. Edição por blocos com `Edit` (o arquivo tem ~2.380 linhas): bloco `errors` (`pt_BR:2343-2369`, `en:2331-2357`), `users.not-permission` (`pt_BR:55`), `dashboard.index` (`:2110-2111`), `common` (`:1763`, acrescentar chaves), demais do Anexo A.

**4.2 Raiz `lang/pt_BR/`** (arquivos novos; a pasta `lang/` é do projeto, não do Krayin): `auth.php`, `passwords.php`, `pagination.php`, `validation.php` — conteúdo na seção de textos. Nada de `lang/pt_BR.json` agora (as frases em JSON só aparecem nas páginas de erro do próprio framework, que ficam fora de uso com `debug = false`).

**4.3 Outros pacotes:** `WebForm/src/Resources/lang/{pt_BR,en}/app.php` chave `catalog.attributes.edit.is-required` ("Obrigatório" / "Required"); correção `Admin::` → `admin::` em `Admin/src/Resources/views/settings/webhook/edit.blade.php:305-306` (e o equivalente em `create.blade.php`, conferir com grep).

### 5. Seletor de idioma (se a decisão 3 for aprovada)

- `config/app.php`: nova chave `'painel_locales' => ['pt_BR' => 'Português'],` (para reativar o inglês: `+ 'en' => 'English'`).
- `Admin/src/Resources/views/components/layouts/header/index.blade.php:46-79`: ler `config('app.painel_locales')`, percorrer essa lista no `@foreach` (hoje fixo em `:69`) e envolver o menu todo em `@if (count($painelLocales) > 1)`.
- `Admin/src/Http/Controllers/LocaleController.php:22`: validar contra `config('app.painel_locales', config('app.available_locales'))` (404 fora da lista).
- Se alguém já trocou para English no servidor, abrir uma vez `/admin/locale/switch/pt_BR` (ver Passo a passo).

### 6. Fase 2 (opcional, exige recompilar o pacote Admin)

Só para as mensagens de validação **do navegador** em português (achado E5), que não têm solução sem recompilar o bundle. Editar a fonte e rodar `npm run build` (não instala nada):
- `packages/Webkul/Admin/src/Resources/assets/js/plugins/vee-validate.js:142-187`, dentro de `localize({...})`, ao lado de `tr: {...}`:

```js
pt_BR: {
    ...pt_BR,   // já importado na linha 20
    messages: {
        ...pt_BR.messages,
        phone: "O campo {field} deve ser um telefone válido",
        after: "O campo {field} deve ser uma data de hoje ou futura",
        decimal: "O campo {field} deve ser um número decimal válido",
        postcode: "O campo {field} deve ser um CEP válido",
        address: "O campo {field} deve ser um endereço válido",
        date_format: "O campo {field} deve estar no formato AAAA-MM-DD",
        _default: "O campo {field} é inválido",
    },
},
```
- (Opcional, torna a Fase 1 do calendário redundante) `plugins/flatpickr.js`: `import { Portuguese } from "flatpickr/dist/l10n/pt.js";` e no `localeMap` `pt: Portuguese, pt_BR: Portuguese`; `plugins/admin.js:122-129`: trocar `"en-US"` por `document.documentElement.lang.replace('_', '-')` e, em `:91-100`, trocar `hour12: false` por `hourCycle: 'h23'` (evita "24:05" à meia-noite).
- Comando (no PowerShell, a partir da raiz): `Set-Location packages\Webkul\Admin; npm run build`. O `vite.config.js` do pacote usa `emptyOutDir: true` e grava em `public/admin/build` (regenera `manifest.json` e os arquivos com hash novo; os antigos são removidos). Depois: `php artisan test --filter=HelpTest` (a página renderiza o layout com o `manifest.json` novo) e conferir no navegador.
- Se o build não for autorizado, esta fase fica pendente e os avisos de campo vazio nas telas com validação no navegador seguem como "Nome is not valid" (registrado como pendência; o servidor já devolve português pelo item 4.2).

---

## Banco (migrations)

**Nenhuma.** Sem tabela, coluna ou dado novo. O título antigo "Cadastro - ABC1D23" **não** é reescrito: é interpretado na exibição (`semPrefixoDeEtapa`), o que preserva "nada se apaga", dispensa o `public/migrate-safe.php` e dispensa qualquer ação nos bancos das oficinas (nenhuma dependência da F00).

---

## ACL, menu, rotas e configurações

- **ACL:** nenhuma chave nova. **Menu:** nenhuma alteração. **Rotas:** nenhuma nova. **`WorkshopSettings`:** nenhuma chave nova. **Eventos de OS, alçadas e barreiras:** nenhum.
- **Configuração (arquivos):**
  - `support.contact_email` (novo; padrão `gabriel@volmastercrm.com`, o mesmo da Ajuda) e `support.contact_whatsapp` (novo; padrão vazio) em `Admin/src/Config/support.php`;
  - `.env` (opcional): `VOLMASTER_SUPPORT_EMAIL`, `VOLMASTER_SUPPORT_WHATSAPP` (somente dígitos, com `55` + DDD; ex.: `5562999990000`). Variável presente e vazia = mostra o texto neutro "suporte da Volmaster", sem link;
  - `config/app.php`: `locale` padrão `pt_BR`; `painel_locales` (novo, se a decisão 3 for aprovada).
- **Hook novo de view:** apenas mais um template no listener existente `admin.layout.body.after` (`ServiceOrderServiceProvider.php:53-58`).
- **Métodos novos no modelo:** `ServiceOrder::semPrefixoDeEtapa()` (estático), `etapaRotulo()`, `identificacao()`, `tituloAtual()`.
- **Regra para as outras frentes** (garantida pelos testes novos): datas só por `core()->formatDate(...)` ou formatos numéricos (`d/m/Y H:i`); nunca `M`, `F`, `D`, `l`, `A` em `format()`; todo texto novo em `lang/pt_BR` (e `en` onde o pacote mantém o par); nenhuma chave de tradução usada no código pode faltar em `pt_BR`.

---

## Textos da interface (pt-BR)

### Bloco `errors` (substitui `pt_BR/app.php:2343-2369`; `en` no mesmo formato, em inglês)

```php
'errors' => [
    'dashboard' => 'Início',
    'go-back' => 'Voltar',
    'support' => 'Se o problema continuar, fale com o suporte da Volmaster: <a href=":link" class=":class">:email</a>.',
    'support-generic' => 'Se o problema continuar, fale com o suporte da Volmaster.',
    'support-whatsapp' => 'Você também pode chamar no WhatsApp: <a href=":link" class=":class" target="_blank" rel="noopener">:whatsapp</a>.',
    'unauthorized' => 'Esta ação não está autorizada.',

    '401' => ['title' => '401 Não autorizado', 'description' => 'Ops! Você precisa entrar no sistema, ou não tem permissão para abrir esta página.'],
    '403' => ['title' => '403 Acesso negado', 'description' => 'Ops! Esta página é restrita. O seu perfil não tem permissão para ver este conteúdo.'],
    '404' => ['title' => '404 Página não encontrada', 'description' => 'Ops! Não encontramos a página que você procura. Confira o endereço ou volte para o início.'],
    '405' => ['title' => '405 Ação não permitida', 'description' => 'Ops! Esta página não pode ser aberta desse jeito. Volte e use os botões do sistema.'],
    '413' => ['title' => '413 Arquivo grande demais', 'description' => 'Ops! O envio passou do tamanho que o servidor aceita. Mande menos arquivos de uma vez ou fotos menores.'],
    '419' => ['title' => '419 Sessão expirada', 'description' => 'Sua sessão expirou por inatividade. Volte, atualize a página e tente de novo.'],
    '429' => ['title' => '429 Muitas tentativas', 'description' => 'Foram muitas tentativas em pouco tempo. Aguarde um minuto e tente de novo.'],
    '500' => ['title' => '500 Erro interno', 'description' => 'Ops! Algo deu errado do nosso lado ao abrir esta página. Tente de novo em alguns instantes.'],
    '503' => ['title' => '503 Sistema em manutenção', 'description' => 'Ops! O sistema está em manutenção por alguns minutos. Volte logo.'],
],
```
Versão `en`: mesmas chaves ("404 Page not found", "If the problem persists, contact Volmaster support: ...", etc.).

### Chaves novas do Admin (pt_BR / en)

- `common.something-went-wrong`: "Algo deu errado. Tente novamente em instantes." / "Something went wrong. Please try again shortly."
- `emails.common.user.forget-password.subject` (ajuste): "Redefinição de senha" / "Reset your password"; `emails.common.user.update-password-subject` (novo): "Senha atualizada" / "Password updated".
- `users.not-permission`: "Você não tem permissão para acessar o painel."
- `dashboard.index.start-date`: "Data inicial"; `end-date`: "Data final".
- Demais correções e chaves ausentes: **Anexos A e B**.

### Arquivos `lang/pt_BR/` na raiz (novos)

- `auth.php`: `failed` "Essas credenciais não correspondem aos nossos registros."; `password` "A senha informada está incorreta."; `throttle` "Muitas tentativas de acesso. Tente novamente em :seconds segundos."
- `passwords.php`: `reset` "Sua senha foi redefinida!"; `sent` "Enviamos por e-mail o link para redefinir sua senha!"; `throttled` "Aguarde antes de tentar novamente."; `token` "O código de redefinição de senha é inválido."; `user` "Não encontramos um usuário com esse e-mail."
- `pagination.php`: `previous` "&laquo; Anterior"; `next` "Próxima &raquo;".
- `validation.php`: **todas** as chaves do arquivo-base `vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php` (inclusive subchaves `between/gt/gte/lt/lte/max/min/size/password` e as regras novas `decimal`, `enum`, `lowercase`, `uppercase`, `extensions`, `max_digits`, `min_digits`, `prohibited*`, `present_*`, `missing*`...) traduzidas com as mesmas variáveis (`:attribute`, `:min`, `:max`, `:values`, `:other`, `:value`, `:format`, `:date`, `:digits`, `:size`, `:decimal`, `:encoding`). Frases de referência (as demais seguem o mesmo padrão):
  - `required` "O campo :attribute é obrigatório."; `email` "O campo :attribute deve ser um e-mail válido."; `string` "O campo :attribute deve ser um texto."; `integer` "O campo :attribute deve ser um número inteiro."; `numeric` "O campo :attribute deve ser um número."; `date` "O campo :attribute deve ser uma data válida."; `confirmed` "A confirmação do campo :attribute não confere."; `unique` "O valor informado em :attribute já está em uso."; `exists`/`in`/`not_in`/`enum` "O valor escolhido para :attribute é inválido."; `file` "O campo :attribute deve ser um arquivo."; `image` "O campo :attribute deve ser uma imagem."; `uploaded` "Não foi possível enviar o arquivo de :attribute."; `mimes` "O campo :attribute deve ser um arquivo do tipo: :values."; `boolean` "O campo :attribute deve ser verdadeiro ou falso."; `array` "O campo :attribute deve ser uma lista."; `accepted` "O campo :attribute deve ser aceito."; `url`/`active_url` "O campo :attribute deve ser uma URL válida."; `regex`/`not_regex` "O formato do campo :attribute é inválido."; `digits` "O campo :attribute deve ter :digits dígitos."; `after` "O campo :attribute deve ser uma data posterior a :date."; `date_format` "O campo :attribute deve estar no formato :format."; `current_password` "A senha está incorreta."
  - `between` → `numeric` "O campo :attribute deve estar entre :min e :max."; `string` "O campo :attribute deve ter entre :min e :max caracteres."; `file` "... entre :min e :max kilobytes."; `array` "... entre :min e :max itens." Idem `min` ("pelo menos :min ..."), `max` ("não deve ser maior que :max" / "não deve ter mais de :max caracteres/itens"), `size`, `gt/gte/lt/lte`.
  - `custom` => `[]` e `attributes` => (rótulos usados nos formulários da oficina): `name` nome; `email` e-mail; `password` senha; `password_confirmation` confirmação da senha; `current_password` senha atual; `cpf_cnpj` CPF/CNPJ; `whatsapp` WhatsApp; `telefone_fixo` telefone fixo; `telefone_2` 2º telefone; `cep` CEP; `logradouro` rua/avenida; `numero` número; `complemento` complemento; `bairro` bairro; `cidade` cidade; `estado` estado; `placa` placa; `marca` marca; `modelo` modelo; `ano` ano; `tipo` tipo; `chassi` chassi; `km_entrada` km de entrada; `combustivel_entrada` combustível; `descricao_entrada` descrição do veículo; `itens_entrada` itens deixados no veículo; `fotos` fotos; `videos` vídeos; `origem_cliente` origem do cliente; `servico` título; `description` descrição; `descricao` descrição; `person_id` cliente; `vehicle_id` veículo; `mechanic_id` mecânico; `data_previsao` previsão de conclusão; `hora_previsao` horário da previsão; `justificativa` justificativa; `preco_unitario` valor unitário; `quantidade` quantidade; `hours` horas; `part_id` peça; `service_id` serviço; `status` situação; `role_id` função; `view_permission` permissão de visualização.

### Textos fixos de tela

- Título da OS: `#11 · Orçamento - ABC1D23`.
- Cabeçalho `Date` → `Data` (Fiscal e Peças).
- Seletor de idioma (se aprovado): somente "Português" (o menu some quando há uma opção só).
- Assunto do e-mail de senha: "Redefinição de senha".

### Anexo A — valores do `Admin/pt_BR` idênticos ao inglês (145)

**Obrigatórios** (visíveis nas telas do menu):

| Chave (dentro de `admin::app`) | Novo texto pt-BR |
|---|---|
| `errors.*` | bloco acima |
| `users.not-permission` | Você não tem permissão para acessar o painel. |
| `dashboard.index.start-date` / `end-date` | Data inicial / Data final |
| `settings.users.index.create.select-at-lest-one-group` | Selecione ao menos um grupo |
| `validations.message.decimal` | O campo :attribute deve ser um número decimal. |
| `acl.data-transfer` / `acl.imports` / `acl.import` | Transferência de dados / Importações / Importar |
| `components.media.images.placeholders.zoom` | Ampliar |
| `layouts.events` / `events-info` | Eventos / Adicione, edite ou exclua eventos |
| `layouts.campaigns` / `campaigns-info` | Campanhas / Adicione, edite ou exclua campanhas |
| `layouts.webhooks-info` | Adicione, edite ou exclua webhooks |
| `layouts.data_transfer` / `data_transfer_info` | Transferência de dados / Gerencie as configurações de transferência de dados de pessoas, produtos e leads |

**Recomendados** (telas do Krayin fora do menu; mesmo assim traduzir):

| Chave | Novo texto |
|---|---|
| `activities.mass-destroy-success` / `mass-delete-failed` | Atividades excluídas com sucesso. / Não foi possível excluir as atividades. |
| `mail.index.datagrid.content` / `attachments` / `date` | Conteúdo / Anexos / Data |
| `leads.update-failed` (usada em `LeadController.php:441`, atualização em massa) | Não foi possível atualizar os negócios. |
| `leads.view.quotes.mail-body` | Segue em anexo o PDF da cotação. E-mail gerado pelo sistema. |
| `configuration.index.email.imap.account.host` | Servidor |
| `settings.workflows.index.title` / `helpers.update-quote` | Fluxos de trabalho / Atualizar cotação |
| `settings.webforms.index.title` | Formulários web |
| bloco `settings.data-transfer.imports.*` (`create`, `edit`, `index`, `import` e mensagens) — dicionário valor→tradução abaixo | |

Dicionário do bloco `settings.data-transfer.imports.*` (aplicar a toda chave cujo valor seja exatamente o inglês): Action → Ação; Allowed Errors → Erros permitidos; Back → Voltar; Create/Update → Criar/Atualizar; Delete → Excluir; Download Sample → Baixar modelo; Field Separator → Separador de campos; File → Arquivo; General → Geral; Images Directory Path → Caminho da pasta de imagens; Process In Queue → Processar em fila; Results → Resultados; Save Import → Salvar importação; Settings → Configurações; Skip Errors → Ignorar erros; Stop on Errors → Parar nos erros; Create Import → Criar importação; Edit Import → Editar importação; Imports → Importações; Type → Tipo; Validation Strategy → Estratégia de validação; Actions → Ações; Completed At → Concluída em; Created → Criados; Deleted → Excluídos; Edit → Editar; Error File → Arquivo de erros; Started At → Iniciada em; State → Situação; Summary → Resumo; Updated → Atualizados; Uploaded File → Arquivo enviado; Total Batches Completed: → Total de lotes concluídos:; Download Full Report → Baixar relatório completo; Congratulations! Your import was successful. → Parabéns! Sua importação foi concluída com sucesso.; Import In Process → Importação em andamento; Resources Indexing (Price, Inventory and Elastic Search) In Progress → Indexação dos recursos (preço, estoque e busca) em andamento; Resources Linking In Progress → Vinculação dos recursos em andamento; Progress: → Progresso:; Import (título da tela) → Importação; Total Batches: → Total de lotes:; Total Records Created: → Total de registros criados:; Total Records Deleted: → Total de registros excluídos:; Total Errors: → Total de erros:; Total Invalid Rows: → Total de linhas inválidas:; Total Rows Processed: → Total de linhas processadas:; Total Records Updated: → Total de registros atualizados:; Validate → Validar; Click on Validate Data to check your import. → Clique em Validar para conferir sua importação.; The data started reading and Validating → A leitura e a validação dos dados foram iniciadas.; Your import is invalid. Please fix the following errors and try again. → Sua importação é inválida. Corrija os erros abaixo e tente de novo.; Your import is valid. Click on Import to start the import process. → Sua importação é válida. Clique em Importar para iniciar.; Import created successfully. → Importação criada com sucesso.; Import deletion failed unexpectedly. → Não foi possível excluir a importação.; Import deleted successfully. → Importação excluída com sucesso.; Import is invalid → A importação é inválida; There are no resources to import. → Não há nada para importar.; Please change your queue driver to "database" or "redis" to start the import process. → Troque o driver de fila para "database" ou "redis" para iniciar a importação.; Import updated successfully. → Importação atualizada com sucesso.

**Podem ficar iguais** (nomes técnicos ou de marca; entram na lista permitida do teste): `components.media.images.allowed-types`, `components.media.videos.allowed-types`, `quotes.index.datagrid.subtotal` ("Subtotal"), `contacts.persons.view.title` e `mail.view.subject` (só `:name`/`:subject`), `settings.users.index.create.global`/`individual`, `settings.webhooks.create|edit.post|put|x-www-form-urlencoded|raw|json`, `settings.attributes.create|edit.decimal`, `configuration.index.magic-ai.*` (nomes de modelos), `export.csv|xls|xlsx|google-contacts`. Trocar `export.download` ("Download") por "Baixar".

### Anexo B — chaves usadas no código e ausentes em pt_BR **e** en

Adicionar nos dois arquivos (`Admin`, bloco indicado; pt-BR / en):

| Chave (`admin::app`) | pt-BR | en |
|---|---|---|
| `common.something-went-wrong` | Algo deu errado. Tente novamente em instantes. | Something went wrong. Please try again shortly. |
| `common.yes` / `common.no` | Sim / Não | Yes / No |
| `common.select-options` | Selecione uma opção | Select an option |
| `common.select-users` / `select-organization` / `select-warehouse` / `select-type` | Selecione o usuário / a organização / o depósito / o tipo | Select user / organization / warehouse / type |
| `common.select-call` / `select-meeting` / `select-lunch` | Ligação / Reunião / Almoço | Call / Meeting / Lunch |
| `common.system_attribute` / `custom_attribute` | Atributo do sistema / Atributo personalizado | System attribute / Custom attribute |
| `common.start-typing` | Comece a digitar... | Start typing... |
| `datagrid.active` / `datagrid.inactive` | Ativo / Inativo | Active / Inactive |
| `settings.roles.all` / `settings.roles.custom` | Todas as permissões / Personalizado | All / Custom |
| `activities.destroy-failed` | Não foi possível excluir a atividade. | Activity could not be deleted. |
| `contacts.persons.index.delete-success` | Contato excluído com sucesso. | Contact deleted successfully. |
| `leads.no-valid-files` | Nenhum arquivo válido foi enviado. | No valid files were uploaded. |
| `leads.quote-create-success` | Cotação criada com sucesso. | Quote created successfully. |
| `leads.selected-product-not-exist` / `product-name-required` / `product-price-required` / `product-quantity-required` | O produto escolhido não existe. / Informe o nome do produto. / Informe o preço do produto. / Informe a quantidade do produto. | The selected product does not exist. / The product name is required. / The product price is required. / The product quantity is required. |
| `leads.file.data-extraction-failed` | Não foi possível extrair os dados do arquivo. | Could not extract data from the file. |
| `leads.index.datagrid.view` / `quotes.index.datagrid.mail` / `settings.webforms.index.datagrid.view` / `settings.data-transfer.imports.index.datagrid.import` | Ver / Enviar por e-mail / Ver / Importar | View / Send by e-mail / View / Import |
| `mail.saved-to-draft` | E-mail salvo como rascunho. | E-mail saved as draft. |
| `settings.pipelines.duplicate-name` | Já existe um funil com esse nome. | A pipeline with this name already exists. |
| `settings.marketing.events.index.partial-delete-warning` / `none-delete-warning` / `no-selection` / `mass-delete-failed` | Alguns eventos não puderam ser excluídos. / Nenhum dos eventos escolhidos pôde ser excluído. / Escolha ao menos um evento. / Não foi possível excluir os eventos. | Some events could not be deleted. / None of the selected events could be deleted. / Select at least one event. / Events could not be deleted. |
| `customers.reviews.index.datagrid.mass-delete-success` | Tags excluídas com sucesso. | Tags deleted successfully. |
| `warehouse.view.tags.create-success` | Tag adicionada com sucesso. | Tag added successfully. |
| `catalog.attributes.create.option-deleted` | Opção excluída. | Option deleted. |
| `settings.email-template.create.subject-placeholder` / `edit.subject-placeholder` | Assunto do e-mail | E-mail subject |
| `emails.common.user.update-password-subject` | Senha atualizada | Password updated |

Mais, no pacote WebForm: `web_form::app.catalog.attributes.edit.is-required` → "Obrigatório" / "Required". (As chaves do Installer ficam fora: o instalador só roda na implantação.)

Somente em `en` (paridade): `configuration.index.general.support.title|info|contact.title|contact.info|contact.whatsapp|contact.email`, `settings.roles.create.comissao`, `settings.roles.edit.comissao`, `settings.users.index.create.mechanic`, `mechanic-hint`; e `fiscal::app.emit-btn-nfe`. Só se o inglês continuar disponível (decisão 3).

---

## Testes Pest a escrever

Pasta `tests/Feature/Workshop/` (herda `DatabaseTransactions` e `BuildsWorkshop`: `makeUser`, `makeOs`, `makePerson`, `at`). Funções auxiliares globais com prefixo único por arquivo (`erro_`, `titulo_`, `datas_`, `textos_`). Nos testes de página de erro: `config(['app.debug' => false]); app()->setLocale('pt_BR');` (o `.env` de teste pode estar com debug ligado).

### `PaginaDeErroTest.php` (PDF B7: "404 em português com o contato real"; roteiro 6)

1. **404 em português**: `get('/admin/rota-que-nao-existe')` → `assertNotFound()` (HTTP 404, não 200), vê "404 Página não encontrada" e "Voltar"; não vê `Page Not Found`, `Oops!`, `Go Back`, `example.com`.
2. **Contato vem da configuração**: `config(['support.contact_email' => 'suporte@oficina-teste.local', 'support.contact_whatsapp' => '5562999990000'])` → vê `mailto:suporte@oficina-teste.local` e `https://wa.me/5562999990000` (`assertSee(..., false)`).
3. **Sem contato configurado**: `config(['support.contact_email' => '', 'support.contact_whatsapp' => ''])` → vê "suporte da Volmaster" e não há `mailto:`.
4. **Padrão = contato da Ajuda**: `config('support.contact_url')` contém `config('support.contact_email')`; a página Ajuda (`admin.help.index`) não mostra `support@example.com`.
5. **Páginas por código** (dataset 401, 403, 404, 405, 413, 419, 429, 500, 503): rota de teste `Route::middleware('web')->get('/_teste-erro/{codigo}', fn (int $codigo) => abort($codigo))->whereNumber('codigo');` → `assertStatus($codigo)`, título em português (`trans("admin::app.errors.{$codigo}.title")`), sem `Oops!`.
6. **CSRF vencido vira 419**: rota que faz `throw new \Illuminate\Session\TokenMismatchException` → status 419 e vê "Sessão expirada".
7. **Sem autorização vira 403**: rota que faz `throw new \Illuminate\Auth\Access\AuthorizationException` → status 403 e vê "Acesso negado".
8. **Erro inesperado vira 500 sem vazar detalhe**: rota que lança `new RuntimeException('segredo-interno')` → status 500, não vê `segredo-interno`.
9. **JSON em português**: `getJson('/_teste-erro/403')` → 403 e `message` = `trans('admin::app.errors.403.title')`; `getJson('/_teste-erro/503')` → mensagem sem `::` (nunca a chave crua); rota inexistente com `getJson` → 404 com "404 Página não encontrada".
10. **Idioma da oficina vale na rota inexistente**: com `config(['app.locale' => 'en'])` e a linha `general.general.locale_settings.locale = pt_BR` em `core_config` (via `app(CoreConfigRepository::class)->updateOrCreate(['code' => 'general.general.locale_settings.locale'], ['value' => 'pt_BR'])`), a 404 sai em português; com valor `en` sai em inglês.
11. **Login não quebra depois de desativar usuário** (E8): usuário com `status = 0` abre `admin.dashboard.index` → `assertRedirect(route('admin.session.create'))` e `assertSessionHas('error', fn ($v) => is_string($v) && $v === trans('admin::app.errors.unauthorized'))`; depois `withSession(['error' => trans('admin::app.errors.unauthorized')])->get(route('admin.session.create'))->assertOk()->assertSee('Esta ação não está autorizada.')`.
12. **Visitante sem login em rota que exige autenticação** (E14): chamada que gera `AuthenticationException` (rota de teste com `throw new AuthenticationException`) → HTML: redireciona para `route('admin.session.create')`; JSON: 401.
13. **Assunto do e-mail de redefinição** (E9): `(new UserResetPassword('token'))->toMail($usuario)->subject` é `trans('admin::app.emails.common.user.forget-password.subject')` e não "User Reset Password".

### `TituloEtapaOsTest.php` (PDF B7: "Título da OS: mostrar a etapa ATUAL"; roteiro 9-66)

1. **`semPrefixoDeEtapa`** (dataset): `Cadastro - ABC1D23` → `ABC1D23`; `Orçamento – ABC1D23` (travessão) → `ABC1D23`; `ORÇAMENTO: ABC1D23` → `ABC1D23`; `Em Execução - Troca de embreagem` → `Troca de embreagem`; `Diagnóstico de motor` → igual; `Cadastro de cliente novo` → igual; `Revisão geral` → igual; `  Cadastro -  ` → vazio; `null` → vazio.
2. **Todas as etapas**: para cada etapa de `ServiceOrder::stagesFlow()` + Concluída + Cancelada, OS criada com `makeOs(['subject' => 'Cadastro - ABC1D23'])` e etapa trocada por `DB::table('quotes')->where('id', $os->id)->update(['workshop_status' => $etapa])` (sem disparar o observer); como proprietário (`makeUser('proprietario')`), `get(route('admin.service_orders.view', $os->id))` → vê `"{$etapaTraduzida} - ABC1D23"`; fora da etapa Cadastro não vê `Cadastro - ABC1D23`.
3. **Título da aba e cabeçalho**: o HTML casa com `/<title>\s*#{id} &middot; Orçamento - ABC1D23\s*<\/title>/` e o cabeçalho tem o mesmo texto.
4. **Título digitado à mão** ("Troca de embreagem") na etapa Negociação → "Negociação - Troca de embreagem".
5. **Cadastro não grava mais a etapa**: `post(route('admin.service_orders.cadastro.store'), [... 'placa' => 'ABC1D23'])` → `ServiceOrder::latest('id')->first()->subject` é `ABC1D23` (não começa com "Cadastro") e a tela da OS mostra "Cadastro - ABC1D23".
6. **Lista sem a etapa velha**: `getJson(route('admin.service_orders.index'), ['X-Requested-With' => 'XMLHttpRequest'])` → o registro tem `subject` = `ABC1D23` (padrão de `TecnicoValoresTest.php:106`).
7. **Impressão e Minhas tarefas**: `identificacao()` na impressão; `TecnicoTasks` devolve `servico` sem o prefixo.
8. **E-mail do orçamento**: o assunto é `Orçamento #id - ABC1D23` (sem "Cadastro").
9. **Portal**: a tela do cliente mostra `ABC1D23` + selo da etapa, nunca "Cadastro - ABC1D23".

### `DatasEmPortuguesTest.php` (PDF B7: "datas em português"; roteiro 14, 15, 28, 34, 44, 54)

1. `core()->formatDate('2026-10-20 09:15:00')` → `20/10/2026 09:15`; `formatDate(Carbon::parse('2026-10-20 21:05:00'))` → `20/10/2026 21:05` (24 h, sem AM/PM).
2. **Fuso**: com `config(['app.timezone' => 'America/Sao_Paulo'])`, `formatDate(Carbon::parse('2026-10-20 12:15:00', 'UTC'))` → `20/10/2026 09:15`.
3. **Formato com mês por extenso sai em português**: `app()->setLocale('pt_BR')`; `formatDate('2026-10-20', 'd \d\e F \d\e Y')` → `20 de outubro de 2026`. `formatDate('2026-10-20', 'd-m-Y')` → `20-10-2026`. `formatDate(null)` → vazio.
4. **Bloco Diagnóstico da OS (piloto desligado)**: OS com `diagnostico_concluido_em = '2026-10-20 09:15:00'` → a tela mostra `20/10/2026 09:15` e não mostra `20 Oct 2026` nem `09:15AM`.
5. **Listas em dd/mm/aaaa hh:mm**: para `admin.service_orders.index`, `admin.mechanics.index`, `admin.settings.users.index`, `admin.settings.warehouses.index` (confirmar os nomes com `php artisan route:list --name=`), cada `records.*.created_at` casa `/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/`.
6. **Início**: `Dashboard::getDateRange()` não tem nome de mês; resposta de `admin.dashboard.stats` com `type=total-leads` tem `over_time[*].label` casando `/^\d{2}\/\d{2}$/` (conferir o caminho do JSON na implementação) e nenhum "Week".
7. **Guarda estática** (impede a volta): percorre `packages/Webkul/*/src/**/*.php` (exclui `node_modules`, `Migrations`, `lang`) e falha se encontrar literal de `->format('…')` ou de `formatDate(…, '…')` com `[DlFMaA]` não precedido de `\` (ex.: `d M Y`, `h:iA`, `D M d, Y`). `Ymd\THis\Z` e `Y-m-d` passam.
8. **Calendário em português**: com o painel em pt_BR, qualquer tela admin contém `Segunda-feira` e `Flatpickr.localize`; com `app()->setLocale('en')` não contém.
9. **Atividades**: `components/activities/index.blade.php` não contém `MMM` nem `h:mm A`.

### `TextosEmPortuguesTest.php` (PDF B7: "Teste: navegar por todas as telas sem ver texto em inglês")

1. **Varredura de telas**: como proprietário, para cada rota do menu — `admin.dashboard.index`, `admin.help.index`, `admin.settings.index`, `admin.settings.users.index`, `admin.settings.roles.index`, `admin.settings.groups.index`, `admin.settings.warehouses.index`, `admin.configuration.index`, `admin.tecnico.index`, `admin.service_orders.cadastro.create`, `admin.service_orders.create`, `admin.service_orders.index`, as 9 etapas de `admin.service_orders.stage.index`, `admin.commissions.index`, `admin.marketing.index`, `admin.mentoria.painel`, `admin.mentoria.indicadores`, `admin.mentoria.alcadas`, `admin.mentoria.alcadas.matriz`, `admin.mentoria.metas`, `admin.mentoria.bonus`, `admin.mentoria.bonus.meu`, `admin.mentoria.registros`, `admin.colaboradores.index`, `admin.financeiro.lancamentos`, `admin.financeiro.dre`, `admin.rotina.painel`, `admin.rotina.patio`, `admin.rotina.agenda`, `admin.workshop.settings.edit`, `admin.ficha.edit`, `admin.import.index`, `admin.historico.index`, `admin.impact.open`, `admin.recommendations.index` — a resposta é 200 e o HTML **não** contém: `Oops!`, `Go Back`, `Page Not Found`, `Start Date`, `End Date`, `Internal Server Error`, `Service Unavailable`, `is not valid`, `This action is unauthorized`, `You do not have permission`, `support@example.com`; nem `/\b\d{1,2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\b/`; nem `/\b\d{1,2}:\d{2}\s?(AM|PM)\b/`; nem **chave de tradução crua** (`/\b(admin|service_order|portal|fiscal|partinventory|mechanic|vehicle|workbay|whatsapp|web_form)::app\./`). Se alguma rota redirecionar por regra de negócio, sai da lista com comentário (não dar `skip`).
2. **Chave usada no código existe em pt_BR**: percorre `packages/Webkul/*/src` (exclui `node_modules`, `Migrations`, `Resources/lang`, `Installer`), captura `/\b([a-z_]+)::app\.([A-Za-z0-9_.\-]+)(?=['"])/`, ignora chaves terminadas em `.` (prefixo dinâmico) e exige `Lang::has($chave, 'pt_BR', false)`; namespace desconhecido (`shop`) falha. Mapa de namespaces: `admin→Admin`, `service_order→ServiceOrder`, `portal`, `fiscal`, `partinventory`, `mechanic`, `vehicle`, `workbay`, `whatsapp`, `core`, `data_transfer`, `web_form`, `google_contact`. Esperado: zero faltando (inclui `common.something-went-wrong` e o typo `Admin::`).
3. **pt_BR cobre o en e não sobra inglês**: para Admin, Core, DataTransfer, Fiscal, GoogleContact, Mechanic, PartInventory, Portal, Vehicle, WebForm, WorkBay: toda chave de `en/app.php` existe em `pt_BR/app.php`; valor igual ao inglês só se estiver na lista permitida do próprio teste (Anexo A, "podem ficar iguais") ou for sigla (`/^[A-Z0-9 \-\/\.\(\):,]+$/`) ou só `:variável`. A lista final sai de uma rodada do teste.
4. **Validação do Laravel em português**: toda chave (com `Arr::dot`) de `vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php` existe em `lang/pt_BR/validation.php`; nenhuma frase começa com `The `; `trans('validation.required', ['attribute' => 'nome'], 'pt_BR')` é `O campo nome é obrigatório.`; `post(route('admin.session.store'), [])` devolve erro de `email` com "obrigatório".
5. **Sem `support@example.com`**: varredura estática em `packages`, `app`, `config`, `routes`, `resources` (exclui `node_modules`): zero ocorrências.
6. **Seletor de idioma** (se aprovado): a tela admin não contém `English`; `get(route('admin.locale.switch', 'en'))` → 404.

Para rodar: `php artisan test --filter=PaginaDeErro`, `--filter=TituloEtapaOs`, `--filter=DatasEmPortugues`, `--filter=TextosEmPortugues`, depois `--filter=Workshop` e `php vendor/bin/pint` nos arquivos tocados, com `DB_DATABASE=krayin_test`.

---

## Riscos e interações com outras frentes

- **HTTP 200 → status real nas páginas de erro.** Qualquer coisa que dependia de "200 com HTML" passa a receber 404/403/500. Não há teste nem fluxo conhecido que dependa disso; testes novos fixam o comportamento. Para o B8 isso é uma **vantagem**: a aba Rede passa a mostrar 403/401 de verdade.
- **Handler (`Admin/Exceptions/Handler.php`) é da F01.** A F05 (403 em rota e API) deve apenas **usar** a página 403/401 e, se quiser, trocar o `abort(401)` por `abort(403)` em `Bouncer::allow` (`Admin/src/Bouncer.php:37`). A F01 mexe só nas linhas 30 e 42 de `Http/Middleware/Bouncer.php`: arquivo **compartilhado** com a F05, editar com `Edit` cirúrgico.
- **`Core.php` é núcleo do Krayin.** Uma única função muda; justificado porque 32 chamadas dependem dela e o `core()` não é extensível por pacote. Telas novas das outras frentes (Peças, Painel de Peças, Faturamento...) herdam o formato certo sem esforço.
- **Fuso e idioma dependem do `.env` do servidor.** `config/app.php` tem `Asia/Kolkata` e `en` como padrão. **Não** alterar o padrão do fuso no código: se o servidor estiver sem `APP_TIMEZONE`, os horários já gravados estão no fuso padrão e mudar o padrão deslocaria tudo em 8h30. Por isso a verificação do `.env` fica no Passo a passo. O idioma padrão `pt_BR` no código é seguro (só vale quando não há variável).
- **Páginas de erro 5xx e banco fora do ar.** O layout `anonymous` consulta `core_config` (4 chamadas): se o erro for o próprio banco, a página de erro também falha (já era assim). Não resolvido aqui; solução futura é uma página de erro autônoma (HTML simples, como as de `Tenant/errors`) para 500/503. Registrado como dívida.
- **Seletor de idioma:** o idioma é global da oficina. Hoje qualquer usuário que clicar em English deixa a oficina inteira com chaves cruas (ServiceOrder `en` com 81 de 200 chaves). Esconder o English (decisão 3) elimina o risco; se for mantido, completar os `en` (119 chaves do ServiceOrder, WhatsApp inteiro, Fiscal, 6 do Admin).
- **Fase 2 (recompilar).** `emptyOutDir: true` apaga e recria `public/admin/build`; o zip precisa levar a pasta nova inteira. Se o executor não puder/ não for autorizado a rodar `npm run build`, a Fase 2 fica pendente e deve constar no relatório final.
- **Títulos antigos.** Registros com "Cadastro - ..." continuam no banco. Quem pesquisa por "Cadastro" na lista ainda acha todas as OS antigas (a busca é no valor cru) — comportamento aceitável e sem perda de dados.
- **Testes de varredura valem para todas as frentes.** Teste 2 (chave existe em pt_BR) e a guarda estática de datas falham se outra frente criar `@lang('…')` sem texto em pt_BR ou usar `format('d M')`. Avisar as outras frentes; rodar a suíte `Workshop` completa ao integrar.
- **Arquivos compartilhados** (conflito provável): `Admin/Resources/lang/pt_BR/app.php` e `en/app.php` (F04/F05/F14 também acrescentam textos: usar blocos novos e `Edit` cirúrgico); `ServiceOrder/Models/ServiceOrder.php` (F06/F07/F12 acrescentam casts/campos; F01 só acrescenta 4 métodos); `ServiceOrder/Resources/views/view.blade.php` (F01 só troca as linhas 3 e 12); `CadastroController.php` (F01 só a linha 221; F06/F07 mexem na validação e no formulário); `ServiceOrderDataGrid.php` (F01 só a coluna `subject`); `TecnicoTasks.php` (F05: técnico sem valores; F01 só a linha 58); `ServiceOrderServiceProvider.php` (F02 acrescenta listeners; F01 acrescenta um template); `Http/Middleware/Bouncer.php` (F05).
- **Dívidas fora do escopo, só registradas:** `Notifications/User/UserUpdatePassword.php` usa a view inexistente `shop::emails.users.update-password` (classe sem uso); `public/diag.php`, `public/deploy-once.php`, `public/api-keys-manager.php` têm chave fixa no código e os próprios cabeçalhos mandam apagar após o uso (apagar do servidor é ação do usuário); o rodapé do `Installer` e os `en` incompletos.

---

## Decisões em aberto

1. **Qual contato de suporte mostrar na página de erro?** Recomendação: manter por enquanto `gabriel@volmastercrm.com` (o mesmo que a página Ajuda e as páginas de conta suspensa já mostram) e, se houver, informar um **WhatsApp de suporte** (com DDD) para aparecer ao lado. O e-mail/WhatsApp podem ser trocados depois só no `.env` (`VOLMASTER_SUPPORT_EMAIL`, `VOLMASTER_SUPPORT_WHATSAPP`), sem nova versão do código. Sem resposta, o sistema mostra o e-mail acima e nenhum WhatsApp.
2. **Formato do título da OS.** Recomendação (já adotada na especificação): `#11 · Orçamento - ABC1D23` na tela da OS (etapa atual + placa/título), e, nas listas, só `ABC1D23` (a etapa já tem coluna). Alternativa: título só com a placa e um selo colorido da etapa ao lado. Confirmar com o patrão.
3. **Esconder a opção "English" do seletor de idioma do cabeçalho?** Recomendação: **sim** (fica só Português; o menu de idioma some). O inglês está incompleto e a escolha vale para a oficina inteira.
4. **Autorizar recompilar o bundle do Admin (`npm run build`, sem instalar nada)** para as mensagens de campo obrigatório/inválido do navegador saírem em português? Recomendação: **sim**, é a única forma; sem isso o servidor já devolve português, mas o aviso ao deixar um campo vazio nas telas do Krayin continua "Nome is not valid".

---

## Passo a passo para o usuário

Nenhum comando de banco e **nenhum `migrate-safe.php`** para esta frente.

1. **Antes de subir o zip (no servidor, arquivo `.env`)** — conferir/acrescentar estas linhas (use o gerenciador de arquivos da HostGator):
   - `APP_TIMEZONE=America/Sao_Paulo`
   - `APP_LOCALE=pt_BR`
   - `APP_DEBUG=false`
   - (opcional) `VOLMASTER_SUPPORT_EMAIL=...` e `VOLMASTER_SUPPORT_WHATSAPP=5562999990000` (só números, com 55 e DDD)
   - Se existir o arquivo `bootstrap/cache/config.php` no servidor, apague-o (ele tem prioridade sobre o `.env`; é o que o `public/diag.php` avisa).
2. **Se o seletor de idioma já foi trocado para English** em alguma oficina: depois de subir o zip, entrar na oficina e abrir `https://<oficina>/admin/locale/switch/pt_BR` uma vez.
3. **Se a decisão 4 for "sim" e o executor não rodar o build:** no PC, em PowerShell: `Set-Location "d:\volmaster crm\volmaster crm\packages\Webkul\Admin"` e `npm run build`; depois incluir a pasta `public\admin\build` inteira no zip.
4. **Conferir depois do deploy (2 minutos):**
   - abrir `https://<oficina>/admin/qualquer-coisa` → página "404 Página não encontrada" em português, com o contato de suporte (e o WhatsApp, se informado) e, na aba Rede do navegador, status 404;
   - abrir uma OS que está em Orçamento → título `#número · Orçamento - placa`;
   - abrir a lista da etapa Diagnóstico → coluna "Aberta em" como `20/10/2026 09:15`; abrir uma OS com diagnóstico concluído (piloto desligado) → mesma forma na data do bloco Diagnóstico;
   - clicar no filtro de data de qualquer lista → calendário com meses e dias da semana em português;
   - tentar entrar no login com e-mail vazio → mensagem em português;
   - (com o build da Fase 2) Configurações > Usuários > criar, deixando o nome vazio → "O campo Nome é obrigatório".
5. **Informar à equipe:** o contato de suporte definitivo (decisão 1) e se o patrão aprova o formato do título (decisão 2).
