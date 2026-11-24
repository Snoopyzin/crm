# Especificação — F02-notificacao-sms

**Frente:** Notificação no celular (PWA/push) e SMS de reserva — itens **A2** e **A3** do Roteiro de Teste e Ajustes (18/11/2026).

**Convenções deste documento**

- `SO/` = `packages/Webkul/ServiceOrder/src/`. Testes em `tests/Feature/Workshop/`. Raiz do Laravel: `d:\volmaster crm\volmaster crm`.
- Números de linha valem para o código **antes** das mudanças desta rodada (18/11/2026).
- Tamanho: **médio** (5 arquivos de código novos, 3 de teste novos, cerca de 20 alterados). **Nenhuma migration.**
- Respeita o gate do piloto: com o piloto desligado **e** sem chaves de notificação geradas, nada muda para ninguém. O gate de tudo que é visual do push é `WebPush::habilitado()` (piloto ligado **ou** chaves já geradas; ver 0.2, A2.7, A2.9 a A2.11 e A2.16). Os alertas, e portanto o SMS, só existem com o piloto ligado.

---

## 0. Leia primeiro: o que a investigação encontrou

Itens que mudam o plano. O restante do documento detalha cada um.

1. **A criptografia do push já está correta.** Rodei, só em leitura (sem tocar em arquivo nem em banco), `WebPush::encrypt()` com o vetor oficial da RFC 8291 §5: o resultado é **idêntico byte a byte**. Também gerei um par VAPID, assinei com `privateKeyFromD` + `derToRaw` e a assinatura ES256 **verificou**. O que falta é prova automatizada: os testes atuais só usam `FakeWebPush` (`AlertChannelsTest.php:17-31`); `WebPush::send`, `encrypt` e `vapidAuthorization` não têm nenhum teste. Esta frente adiciona o teste com o vetor da RFC.
2. **Bug latente que derruba a tela de configuração (erro 500):** `SO/Resources/views/settings/workshop.blade.php:197` faz `$user->sms_aceite_em->format('d/m/Y')`, mas `User::$casts` (`packages/Webkul/User/src/Models/User.php:38-40`) só converte `onboarding_progress`. Conferi no código real: `gettype($user->sms_aceite_em)` é `string`. Assim que alguém marcar "aceita SMS" em "Papéis e celulares" e salvar, a página recarrega com erro. Nenhum teste pega porque nenhum usuário de teste tem aceite ao abrir a tela. É pré-requisito do A3 (sem isso não dá para cadastrar o aceite de SMS de ninguém).
3. **A instrução do iPhone existe, mas não cumpre o PDF:** está dentro do painel do sino (`#vm-bell-panel`, fechado até tocar no sino), não tem botão de dispensa (aparece sempre), a detecção ignora iPad moderno (o Safari do iPad se identifica como "Macintosh") e só roda nas páginas com layout do Admin. As duas telas por onde técnico e dono **entram pelo celular** não carregam nada de push: `tecnico/index.blade.php` só registra o service worker (linha 278) e `mentoria/painel-celular.blade.php` nem isso.
4. **O e-mail de contato (VAPID `sub`) não é validado.** A documentação da Apple lista `BadJwtToken` (403) quando o `sub` do JWT não é uma URL `https:` ou um `mailto:` válido. Hoje o campo aceita qualquer texto e o valor vai cru para o JWT (`SO/Services/WebPush.php:132`). Um "oficina@gmail.com" digitado sem `mailto:` leva a Apple a recusar o envio (403) e o sistema só registra "falhou" no alerta, sem mostrar o motivo (o Android pode aceitar o mesmo token, o que esconde o problema).
5. **O botão "Testar notificação" não existe** (nem rota, nem tela).
6. **SMS:** tabela, relatório, limite, custo e gateway Twilio existem. Faltam: teste com `Http::fake` (nenhum teste passa pelo `TwilioSmsGateway`), relatório com informação útil, rótulo "não enviado: limite", botão de SMS de teste, correção do `e164()` (um celular com DDD 55 vira número errado), e o horário de silêncio **não segura o SMS** de nível 3/nível 2 (só segura o push) — o que reprova a linha 60 do roteiro.
7. **Chaves por oficina:** cada oficina é um subdomínio com banco próprio (`packages/Webkul/Tenant/src/Http/Middleware/ResolveTenant.php:27-69`), então chaves VAPID, service worker e inscrições são por oficina. O comando `workshop:vapid-keys` só atua no banco principal; para as demais, o caminho é a tela.
8. **Nenhuma migration é necessária.** Tudo usa `push_subscriptions`, `sms_logs`, `workshop_settings` e as colunas `users.celular` / `users.sms_aceite_em`, que já existem.

---

## O que já existe

### Chaves, e-mail de contato e envio (A2)

| O quê | Onde | Estado |
|---|---|---|
| Geração do par VAPID | `SO/Services/WebPush.php:22` `generateVapidKeys()` | Funciona (conferido com RFC 8291). |
| Botão "Gerar chaves / Gerar novas chaves" com confirmação | `SO/Resources/views/settings/workshop.blade.php:230-239`; rota `SO/Routes/web.php:139` `admin.workshop.settings.vapid` → `WorkshopSettingsController::generateVapid` (`:228-246`) | Funciona. Apaga `push_subscriptions` (`:241`) sem checar se a tabela existe. |
| Comando | `SO/Console/Commands/WorkshopVapidKeysCommand.php:15-37` (`workshop:vapid-keys {--force}`), registrado em `ServiceOrderServiceProvider.php:83-85` | Só o banco principal; também apaga `push_subscriptions`. |
| Segredo criptografado | `SO/Services/WorkshopSettings.php:134` (`SECRETS` = `sms_twilio_token`, `push_vapid_private`) | OK. Se a `APP_KEY` mudar, `all()` devolve `''` para a privada (`:159-165`). |
| Selo "chaves geradas" | `workshop.blade.php:8` (`$temChaves = ! empty(push_vapid_public)`) e `:65` | Olha só a chave pública. |
| Campo do e-mail de contato | `workshop.blade.php:64` `push_vapid_subject`; validação `nullable\|string\|max:255` em `WorkshopSettingsController.php:70`; gravação em `:93-96`; uso em `WebPush.php:132` com fallback fixo `mailto:contato@volmastercrm.com` | Sem normalização nem validação de formato. |
| Cabeçalho VAPID | `WebPush.php:120-144` `vapidAuthorization()` | Correto (`aud`, `exp` 12 h, `sub`). |
| Envio criptografado | `WebPush.php:38-64` `send()`, `:71-115` `encrypt()` | Correto. 404/410 viram `gone`. |
| Inscrição do aparelho | `SO/Http/Controllers/AlertController.php:87-107` `subscribe` (rota `Routes/web.php:128` `admin.workshop.push.subscribe`, `updateOrCreate` por hash do endpoint; o último usuário logado no aparelho fica com ele), `:109-118` `unsubscribe` (rota `:129`, sem uso na tela) | OK. |
| Modelo / tabela | `SO/Models/PushSubscription.php` (só `hashEndpoint`); tabela em `SO/Database/Migrations/2026_11_16_100300_create_workshop_alert_tables.php:74-87` | OK. |
| Entrega de alertas por push | `SO/Services/AlertDispatcher.php:91-106` `dispatchPush`, `:124-146` `sendPushFor`, `:152-178` `pushTo` (remove inscrição morta) | OK. |

### PWA instalável (A2)

| O quê | Onde | Estado |
|---|---|---|
| Manifesto do app | `SO/Http/Controllers/PwaController.php:20-36` (`/workshop.webmanifest`, `display: standalone`, ícones 192/512, `start_url` = `/admin/workshop/alertas`) | OK. |
| Manifesto do dono | `PwaController.php:41-57` (`/dono.webmanifest`, `start_url` = painel-celular). Testado em `MentoriaPanelTest.php:55,76-79` | OK, **não mexer**. |
| Ícones 192/512 | `PwaController.php:63-89`, gerados com GD a partir de `SO/Resources/assets/volmaster-logo.png` (fundo azul, logo a 60%, serve para `any maskable`) | OK. Exige a extensão GD no servidor. |
| Service worker | `PwaController.php:91-98` renderiza `SO/Resources/views/pwa/service-worker.blade.php`; `install/activate` `:7-13`, `push` `:21-49`, `notificationclick` `:51-69` | Funciona, mas o clique é frágil (ver abaixo) e não há `fetch`. |
| Rotas públicas | `SO/Routes/public.php:22-30` (manifesto, SW, ícones, `workshop/push/chave`, `workshop/push/confirmar/{token}/{status}`) | OK. |
| `<link rel=manifest>` e metas | `SO/Resources/views/components/workshop-head.blade.php:1-8`, injetado por `ServiceOrderServiceProvider.php:60-63` (`admin.layout.head.after`) | **Só com o piloto ligado** (`StageGate::active()`). |
| Registro do SW + inscrição | `SO/Resources/views/components/workshop-bell.blade.php:119-170` (`prepararPush`), chamado em `:174`; o sino só existe com o piloto ligado (`:1`) | Só nas páginas com layout do Admin. |
| SW na tela do técnico | `SO/Resources/views/tecnico/index.blade.php:278` | Só registra; não inscreve, sem aviso. |
| Confirmação "entregue/aberto" | `PwaController.php:112-121` `ack` → `AlertService::acknowledge` (`SO/Services/AlertService.php:207-232`) | OK. |

### Aviso do iPhone (A2)

- Texto em `workshop-bell.blade.php:127-130` (dentro do painel do sino, que começa fechado) e detecção em `:95-96` (`/iphone|ipad|ipod/i`, falha no iPadOS moderno).

### Perfil do usuário (A2)

- Tela "Minha Conta": `packages/Webkul/Admin/src/Resources/views/user/account/edit.blade.php`; rota `admin.user.account.edit` em `packages/Webkul/Admin/src/Routes/Admin/rest-routes.php:60-64`; link no menu do avatar em `packages/Webkul/Admin/src/Resources/views/components/layouts/header/index.blade.php:144-149`.
- **Ponto de extensão sem editar o núcleo:** a view já dispara `view_render_event('admin.user.account.form.after')` (`edit.blade.php:200`), **fora** do `<form>` e sem parâmetros. Basta um `Event::listen` no `ServiceOrderServiceProvider` (mesmo padrão de `:53-63`).
- A rota não pertence a nenhuma chave de ACL (nenhum item do `config('acl')` a cita), então **qualquer usuário logado** abre (inclui Técnico). Regra do Bouncer: rota fora do ACL é livre (`packages/Webkul/Admin/src/Http/Middleware/Bouncer.php:79-86`).
- Importante: o conteúdo da página fica dentro de `<div id="app">` (`components/layouts/index.blade.php:91-140`), que o Vue monta no evento `load` (`:148-158`) e **reconstrói o DOM**. Por isso o código novo não guarda referência de elementos e usa lógica externa (arquivo `.js`) com consulta por `data-vm-push` na hora de pintar (mesmo cuidado do `cadastro/create.blade.php:426-499`, que usa delegação no `document`).

### SMS (A3)

| O quê | Onde | Estado |
|---|---|---|
| Padrão "Simulado" | `WorkshopSettings.php:45` (`sms_provedor = 'log'`), `SO/Services/Sms/LogSmsGateway.php:13-18` (não envia, devolve `simulado`) | OK. |
| Campos do provedor, **limite mensal** e **custo por SMS** | `workshop.blade.php:69-89` (select Simulado/Twilio; `sms_limite_mensal`; `sms_custo_unitario`; `sms_nao_aberto_minutos`; Twilio SID/token/origem); validação em `WorkshopSettingsController.php:64-67,71-73`; padrões 200 SMS e R$ 0,12 em `WorkshopSettings.php:46-47` | Existem. Sem aviso se o provedor real estiver incompleto. |
| Registro de cada SMS | `SO/Models/SmsLog.php`; tabela `sms_logs` (`2026_11_16_100300...php:89-105`: `alert_id`, `user_id`, `telefone`, `mensagem`, `provedor`, `status` string(12), `custo` decimal(8,4), `detalhe`) | OK. Status usados: `simulado`, `enviado`, `falhou`, `bloqueado`. |
| Envio, aceite e **bloqueio por limite** | `SO/Services/Sms/SmsManager.php:41-75` `sendAlert` (sem celular/aceite → `bloqueado`; `monthlyCount() >= limite` → `bloqueado` com detalhe "Limite mensal de SMS atingido."), `:80-85` `monthlyCount` (conta `enviado` + `simulado` do mês), `:91-118` `textFor` (até 160 caracteres, ASCII, sem R$), `:120-125` `e164`, `:127-130` `log` | Funciona. Bug no `e164` (DDD 55). `monthlyCount` quebra se `sms_logs` não existir. |
| Twilio | `SO/Services/Sms/TwilioSmsGateway.php:14-42` (POST `https://api.twilio.com/2010-04-01/Accounts/{sid}/Messages.json`, Basic auth, `To/From/Body`, timeout 15 s) | Nunca validado contra conta real; **sem teste**. |
| Quando sai SMS | `AlertDispatcher.php:34-36` (crítico ou nível ≥ 3 → imediato), `:184-203` `escalateLevelTwoToSms` (nível 2 não aberto em N min ou push falho/sem aparelho), `:205-212` `sendSms` (grava `sms_status` e `alert_deliveries`) | **Não respeita o horário de silêncio.** |
| Relatório | rota `Routes/web.php:141` `admin.workshop.settings.sms_report`; `WorkshopSettingsController::smsReport` (`:248-258`); view `SO/Resources/views/settings/sms-report.blade.php:1-48` | Mostra quando/telefone/mensagem/provedor/status/custo e o total do mês. Sem nome da pessoa, sem rótulo claro, sem filtro, sem aviso de modo simulado. |
| ACL | `SO/Config/acl.php:107-120` chave `settings.workshop` cobre a tela, o relatório e o `vapid` | OK. |
| Testes atuais | `tests/Feature/Workshop/AlertChannelsTest.php:86-128,170-182,198-248,250-267` (SMS com gateway falso), `HttpFlowTest.php:305-342` (configuração grava token criptografado e gera chaves) | Nenhum usa `Http::fake`. |

---

## Requisitos do PDF → como serão atendidos

### A2 — Chaves de notificação no celular

| # | Requisito do PDF | Situação | Como |
|---|---|---|---|
| A2.1 | Gerar as chaves VAPID em Configurações > Indicadores e alertas > Chaves das notificações no celular | **JÁ EXISTE** (botão, rota, comando; criptografia conferida). Ajustes pequenos | Selo passa a exigir pública **e** privada (`WebPush::configurado()`); guarda `Schema::hasTable` antes de apagar inscrições; aviso se faltar o e-mail; cartão passa a mostrar quem já ativou (apoia "celulares com o app instalado" do critério de liberação). Teste automatizado com o vetor RFC 8291. |
| A2.2 | Preencher o e-mail de contato | **PARCIAL** (campo existe, sem validação) | Normalizar para `mailto:` ou `https://`, recusar texto inválido e e-mails de exemplo, e usar o normalizado no JWT (`WebPush::normalizarAssunto()` / `assunto()`). |
| A2.3 | Sistema instalável (PWA): manifest, ícone e service worker | **JÁ EXISTE** no núcleo; **PARCIAL** nas bordas | (a) `<link rel=manifest>` e metas passam a valer com piloto ligado **ou** chaves geradas (A2 vem antes do A6 no PDF); (b) técnico e Painel do Dono celular passam a carregar o cliente de push (registro do SW incluído); (c) `fetch` sem interceptação no SW (convite automático de instalação do Chrome); (d) clique da notificação endurecido; (e) `start_url` do app = `/admin` para que `EntradaPorCargo` leve cada cargo à própria tela. |
| A2.4 | Instrução do iPhone na primeira vez que o usuário entrar pelo iPhone | **PARCIAL** (texto existe, escondido, sem dispensa) | Folha de aviso visível no rodapé da tela, na primeira vez, com "Entendi"; a dispensa fica gravada **por pessoa no navegador** e não reaparece. Roda em todas as telas de entrada do celular. Detecção inclui iPad moderno. |
| A2.5 | Botão "Testar notificação" no perfil do usuário | **FALTA** | Cartão "Notificações no celular" em **Minha Conta** (gancho do Admin, zero edição de núcleo) com "Ativar neste aparelho" e "Testar notificação". Resultado: enviado, falhou, sem aparelho cadastrado ou chaves ausentes, por aparelho, com confirmação de chegada e de toque. |
| A2.6 | Teste: chega no Android e no iPhone; tocar abre o sistema | **PARCIAL** | Código do toque endurecido (foco → navegar → `openWindow`); testes automatizados cobrem servidor, SW e cliente; a chegada em aparelho real é teste manual (roteiro 3 e 8), com passo a passo abaixo. |

### A3 — SMS de reserva

| # | Requisito do PDF | Situação | Como |
|---|---|---|---|
| A3.1 | Manter "Simulado" na oficina de teste até o fim do roteiro | **JÁ EXISTE** (padrão `log`) | Nada a construir. A tela passa a mostrar faixa "Modo simulado" no relatório e na configuração. F00 deve garantir que a oficina nova nasça em `log` (é o padrão de `WorkshopSettings`). |
| A3.2 | Configurar provedor, **limite mensal** e **custo por SMS** antes de liberar | **JÁ EXISTE** (campos e validação); **PARCIAL** na segurança | Aviso ao salvar se Twilio estiver selecionado e faltar SID/token/origem; botão "Enviar SMS de teste para o meu celular" (passa pelas mesmas regras de aceite e limite) para validar o provedor sem provocar um alerta crítico. Só existe gateway Twilio (ver Decisões). |
| A3.3 | Com "Simulado", o relatório registra o que teria sido enviado | **PARCIAL** (registra, mas o relatório é pobre) | Relatório com pessoa, telefone, alerta/OS, mensagem, situação rotulada, filtro de mês/situação, contadores, custo estimado dos simulados; faixa de modo simulado. Teste prova que nada sai pela rede (`Http::assertNothingSent`). |
| A3.4 | Bloqueio ao atingir o limite mensal ("não enviado: limite") | **JÁ EXISTE** (lógica); **PARCIAL** (rótulo) | Mantém `status = 'bloqueado'` com `detalhe = SmsLog::DETALHE_LIMITE` (os testes atuais continuam valendo) e passa a exibir **"Não enviado: limite mensal atingido"**; contador próprio no relatório. |
| A3.5 | Com o provedor real, um alerta crítico chega por SMS | **PARCIAL** (Twilio escrito, nunca testado) | Testes com `Http::fake` cobrindo requisição (URL com SID, Basic auth, `To/From/Body`), sucesso, erro HTTP, exceção de conexão, credenciais ausentes e limite. Correção do `e164` (DDD 55). Teste real: roteiro 72 com credenciais. |
| A3.x | (consequência do roteiro, linha 60) o comum espera, o crítico toca na hora | **FALTA** para SMS | Nível 3 e escalonamento do nível 2 respeitam o silêncio; crítico continua imediato. |
| A3.y | (pré-requisito para cadastrar aceite) | **BUG** | Cast `sms_aceite_em` como `datetime` em `User`. |

---

## Mudanças por arquivo

Ordem sugerida: bloco 0 (base) → A2 → A3 → lang → testes → `pint`.

### Bloco 0 — Correções de base

#### 0.1 `packages/Webkul/User/src/Models/User.php` (alterar — núcleo, edição mínima)

Linhas 38-40: acrescentar o cast. Precedente de edição do núcleo para o projeto: o mesmo arquivo já tem `mechanic_id` e `mechanic()`.

```php
protected $casts = [
    'onboarding_progress' => 'array',
    'sms_aceite_em' => 'datetime',   // coluna criada pela migration 2026_11_16_100400; sem a coluna o cast é inerte
];
```

Não mexer em `$fillable` (o código usa `forceFill`). Efeitos: `workshop.blade.php:196-197` passa a funcionar (`->format('d/m/Y')`); `SmsManager::sendAlert` continua usando só a veracidade (`! $user->sms_aceite_em`); `updateRoles` (`:188`) continua válido (`$user->sms_aceite_em ?? now()`).

#### 0.2 `SO/Services/WebPush.php` (alterar)

Acrescentar (métodos estáticos, sem estado):

```php
public const ASSUNTO_PADRAO = 'mailto:contato@volmastercrm.com';

/** Chaves gravadas e legíveis (a privada é criptografada com a APP_KEY: se ela mudar, volta '' e conta como ausente). */
public static function configurado(): bool
{
    return (string) WorkshopSettings::get('push_vapid_public') !== ''
        && (string) WorkshopSettings::get('push_vapid_private') !== '';
}

/** O app instalável e as notificações valem nesta oficina? Piloto ligado OU chaves já geradas (opt-in explícito). */
public static function habilitado(): bool
{
    return StageGate::active() || static::configurado();
}

/**
 * Valor válido para o campo "sub" do VAPID ou null.
 *  - "https://..." sem espaços é aceito como está;
 *  - e-mail, com ou sem "mailto:", com ou sem "< >" e espaços, vira "mailto:email" (a Apple recusa espaço e "< >");
 *  - recusa e-mail inválido e domínios reservados/de exemplo (example.com/.org/.net, *.local, *.localhost, *.test, *.invalid, *.example).
 */
public static function normalizarAssunto(?string $valor): ?string

/** Assunto efetivo do token: o configurado e normalizado, ou ASSUNTO_PADRAO. */
public static function assunto(): string
```

`vapidAuthorization()` (linhas 120-144):

- Linhas 122-127: trocar a checagem por `static::configurado()`; mensagem da exceção passa a ser `Chaves VAPID não configuradas (Configurações > Indicadores e alertas > Chaves das notificações no celular).` (nenhum teste depende do texto antigo).
- Linha 132: `$subject = static::assunto();`.
- Nada mais muda em `send`, `encrypt` ou nos helpers (já corretos).

Importar `StageGate` não é necessário (mesmo namespace `Webkul\ServiceOrder\Services`).

#### 0.3 `SO/Models/PushSubscription.php` (alterar)

```php
protected static ?bool $disponivel = null;

/** Tabela existe neste banco? (bancos que ainda não receberam a migration continuam funcionando) */
public static function disponivel(): bool
{
    return static::$disponivel ??= Schema::hasTable('push_subscriptions');
}

public function user() { return $this->belongsTo(\Webkul\User\Models\User::class, 'user_id'); }

/**
 * Nome amigável do aparelho, a partir do user_agent gravado na inscrição.
 * SO: iPhone | iPad | Android | Windows | Mac | Linux | "Aparelho"; navegador: Edge (Edg/, EdgA/, EdgiOS/) | Opera (OPR/) |
 * Firefox (Firefox/, FxiOS/) | Chrome (CriOS/, Chrome/) | Safari (Safari/).
 * Formato: "Android · Chrome". iPhone/iPad sem "Safari/" no user agent = app instalado na tela inicial
 * (o modo standalone do iOS não envia esse token): "iPhone (app instalado)".
 */
public function getRotuloAttribute(): string
```

#### 0.4 `SO/Models/SmsLog.php` (alterar)

```php
public const DETALHE_LIMITE = 'Limite mensal de SMS atingido.';
public const DETALHE_SEM_CONTATO = 'Sem celular cadastrado ou sem aceite de SMS.';
// Os textos continuam idênticos aos de hoje: AlertChannelsTest.php:235,247 faz toContain('aceite') e toContain('Limite mensal').

protected static ?bool $disponivel = null;
public static function disponivel(): bool            // Schema::hasTable('sms_logs') memoizado
public function user()                                // belongsTo(User::class, 'user_id')
public function alert()                               // belongsTo(WorkshopAlert::class, 'alert_id')

/** 'simulado' | 'enviado' | 'falhou' | 'limite' | 'sem_contato' (bloqueado por limite vs por falta de celular/aceite). */
public function situacao(): string
public function rotuloSituacao(): string              // trans('service_order::app.sms.situacao.<situacao>')
public function scopeSituacao($query, string $situacao)
// limite      => status 'bloqueado' E detalhe = DETALHE_LIMITE
// sem_contato => status 'bloqueado' E (detalhe nulo OU diferente de DETALHE_LIMITE)
// demais      => status = valor
```

### Bloco A2 — Chaves, e-mail, PWA, aviso do iPhone e botão de teste

#### A2.1 `SO/Http/Controllers/WorkshopSettingsController.php` (alterar)

**`updateGeneral()` (`:55-114`)**

1. Regra de `push_vapid_subject` (linha 70) vira array com closure:

```php
'push_vapid_subject' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
    if (filled($value) && WebPush::normalizarAssunto($value) === null) {
        $fail(trans('service_order::app.notificacao.config.assunto_invalido'));
    }
}],
```

2. Tirar `'push_vapid_subject'` da lista do `foreach` das linhas 92-96 e gravar à parte:
   `WorkshopSettings::set('push_vapid_subject', WebPush::normalizarAssunto(request('push_vapid_subject')) ?? '');`
3. Depois dos `set`, antes do `flash('success')` (`:111`): se `request('sms_provedor') === 'twilio'` e faltar SID, token ou origem (ler de volta com `WorkshopSettings::get`), `session()->flash('warning', trans('service_order::app.sms.aviso_credenciais', ['faltando' => 'o Account SID, o token e o número de origem' /* só os que faltam */]))`.

**`edit()` (`:37-53`)** — incluir na view:

```php
'push' => [
    'chaves' => WebPush::configurado(),
    'assunto' => WebPush::assunto(),
    'assuntoPadrao' => ! filled(WorkshopSettings::get('push_vapid_subject')),
    // user_id => {total, ultimo}; vazio se a tabela não existir
    'aparelhos' => PushSubscription::disponivel()
        ? PushSubscription::query()->selectRaw('user_id, count(*) as total, max(updated_at) as ultimo')->groupBy('user_id')->get()->keyBy('user_id')
        : collect(),
],
'smsResumo' => app(SmsManager::class)->resumoDoMes(),
```

**`generateVapid()` (`:228-246`)**

- Trocar `DB::table('push_subscriptions')->delete();` por `if (PushSubscription::disponivel()) { DB::table('push_subscriptions')->delete(); }`.
- Depois do `flash('success')`: `if (! filled(WorkshopSettings::get('push_vapid_subject'))) { session()->flash('warning', trans('service_order::app.notificacao.config.sem_email_aviso')); }`.
- Manter o texto atual do sucesso.

#### A2.2 `SO/Resources/views/settings/workshop.blade.php` (alterar — arquivo compartilhado, só estas regiões)

- `:8`: `$temChaves = $push['chaves'];`.
- `:61` (dica do silêncio): acrescentar "(vale também para o SMS de reserva)".
- `:63-66` (e-mail + selo): campo passa a ter rótulo "E-mail de contato das notificações", `placeholder="oficina@exemplo.com.br"`, texto de ajuda `notificacao.config.assunto_ajuda` com `WebPush::ASSUNTO_PADRAO`, e o selo usa `$push['chaves']` (`notificacao.config.chaves_ok` / `chaves_faltam`).
- `:230-239` (cartão das chaves): manter o botão; acrescentar abaixo a tabela **"Quem já ativou as notificações"** (`notificacao.config.quem_ativou` + dica `quem_ativou_dica`): para cada `$users->where('status', 1)`: nome, função (`$user->role->name`), nº de aparelhos (`$push['aparelhos'][$user->id]->total ?? 0`), último cadastro (`Carbon::parse($info->ultimo)->format('d/m/Y H:i')`) e selo "Ativou" / "Falta ativar".
- Não mexer em `:23-34` (chave do piloto — F03) nem em `:163-204` (papéis — F04). A correção do `:197` já vem do cast (bloco 0.1), sem editar a view.

#### A2.3 `SO/Http/Controllers/PwaController.php` (alterar)

- `manifest()` (`:20-36`): `'start_url' => '/'.config('app.admin_path')` (hoje `/admin/workshop/alertas`) e acrescentar `'lang' => 'pt-BR'`. **Não** mexer em `manifestDono()` nem em `scope`, `display`, `icons` (HttpFlowTest `:290` e MentoriaPanelTest `:76-79` dependem). Racional: abrir o app em `/admin` deixa o `EntradaPorCargo` (`SO/Http/Middleware/EntradaPorCargo.php`) levar cada cargo à própria tela (técnico → Minhas tarefas, dono → painel, demais → Minha rotina). Sem piloto, `/admin` é o dashboard de sempre.
- Novo método:

```php
public function pushClient(): Response
{
    return response(view('service_order::pwa.push-client')->render(), 200, [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'public, max-age=300',
    ]);
}
```

- `ack()` (`:112-121`): tokens do teste (começam com `PushTeste::PREFIXO`, `teste-`) vão para o cache; os demais seguem para o alerta. Tokens de alerta são `Str::random(40)` (só alfanuméricos), então não colidem com o prefixo.

```php
$ok = str_starts_with($token, PushTeste::PREFIXO)
    ? app(PushTeste::class)->confirmar($token, $mapa[$status])
    : app(AlertService::class)->acknowledge($token, $mapa[$status]);
```

#### A2.4 `SO/Routes/public.php` (alterar — compartilhado)

Depois da linha 25 (`workshop-icone-{size}.png`):

```php
Route::get('workshop-push.js', [PwaController::class, 'pushClient'])->name('workshop.pwa.push_client');
```

#### A2.5 `SO/Resources/views/pwa/service-worker.blade.php` (alterar)

Tudo continua dentro do `@verbatim`. O `push` (`:21-49`) não muda (os testes `HttpFlowTest.php:299` exigem `addEventListener('push'`, `notificationclick` e `requireInteraction`).

1. Depois do `activate` (`:11-13`), antes de `confirmar`:

```js
// O app não guarda nada para uso sem internet: toda página vem da rede. Este listener não intercepta nada;
// existe porque o Chrome só oferece o convite automático de instalação a quem tem um tratador de fetch
// (o menu "Instalar app" funciona mesmo sem ele).
self.addEventListener('fetch', function () {});
```

2. Substituir o `notificationclick` (`:51-69`):

```js
function abrirSistema(destino) {
    return self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (janelas) {
        var mesma = janelas.filter(function (j) { return j.url === destino && 'focus' in j; })[0];
        if (mesma) { return mesma.focus(); }

        var qualquer = janelas.filter(function (j) { return 'focus' in j && 'navigate' in j; })[0];
        if (qualquer) {
            return qualquer.focus()
                .then(function (janela) { return (janela || qualquer).navigate(destino); })
                .catch(function () { return self.clients.openWindow(destino); });   // navigate() rejeita em janela não controlada
        }

        return self.clients.openWindow(destino);
    });
}

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var info = event.notification.data || {};
    var destino = new URL(info.url || '/', self.location.origin).href;
    event.waitUntil(Promise.all([confirmar(info.tokens, 'aberto'), abrirSistema(destino)]));
});
```

Por quê: o código atual faz `navigate()` na primeira janela que o tenha e **não trata a rejeição** (`WindowClient.navigate` rejeita se a janela não é controlada pelo SW); nesse caso a notificação fecha e nada abre — exatamente o que o teste do PDF ("tocar nela abre o sistema") não pode admitir.

#### A2.6 `SO/Resources/views/pwa/push-client.blade.php` (criar) — cliente único de push

Servido por `PwaController::pushClient`. Primeira linha fora do `@verbatim`: `window.VmPushTextos = @json(trans('service_order::app.notificacao'));`; todo o resto dentro de `@verbatim ... @endverbatim`. Um IIFE que expõe só `window.VmPush` (as páginas já têm globais como `app`, `esc`, `aviso`, `CSRF`) e não faz nada se já existir (`if (global.VmPush) return;`).

**API pública**

```js
VmPush.iniciar({
  usuario: 12,                 // id do usuário: as chaves de armazenamento levam a pessoa
  csrf: '...',
  convite: true,               // false = só pintar os blocos data-vm-push (cartão de Minha Conta)
  caixa: 'vm-bell-push',       // id do elemento onde escrever a orientação permanente (painel do sino) ou null
  urls: { chave, inscrever, sw, testar, estado }
})                              // Promise<string> com o código da situação. Idempotente: a 2ª chamada não faz nada.
VmPush.situacao()               // Promise<{codigo, inscrito:boolean}>
VmPush.ativar()                 // Promise; rejeita com Error(texto pt-BR). DEVE ser chamado direto de um clique.
VmPush.testar()                 // Promise<json do servidor> (POST urls.testar)
```

**Códigos de situação** (`codigo()`): `sem_suporte` (sem service worker, sem contexto seguro/HTTPS, ou sem `PushManager`/`Notification`), `ios_instalar` (iPhone/iPad **fora** do modo instalado), `ios_antigo` (iPhone/iPad já instalado, mas sem `PushManager`: iOS anterior ao 16.4), `bloqueado` (`Notification.permission === 'denied'`), `permissao_pendente` (`'default'`), `permitido` (`'granted'`; vira `ativo` quando existe inscrição). Ordem de avaliação: sem service worker → `sem_suporte`; iOS não instalado → `ios_instalar`; sem `PushManager`/`Notification` → `ehIos() ? 'ios_antigo' : 'sem_suporte'`; `denied` → `bloqueado`; `default` → `permissao_pendente`; senão `permitido`.

**Textos no JavaScript**: `window.VmPushTextos` traz a seção `notificacao` inteira; os marcadores (`:n`, `:hora`, `:detalhe`, `:data`) são trocados no cliente com `texto.replace(':n', valor)`. Nada de HTML dentro dos textos: o cliente monta o DOM com `createElement`/`textContent`.

**Ambiente**

```js
function ehIos() { return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); } // inclui iPadOS moderno
function instalado() { return (global.matchMedia && global.matchMedia('(display-mode: standalone)').matches) || global.navigator.standalone === true; }
```

**Armazenamento (sempre em `try`, com a pessoa na chave; falhar aqui só significa "mostrar de novo")**

- `localStorage` `vm-push:<usuario>:ios-visto` e `vm-push:<usuario>:ativar-dispensado` (dispensa **por pessoa e por navegador**).
- `sessionStorage` `vm-push:<usuario>:sincronizado` (reenviar a inscrição ao servidor uma vez por sessão).

**Inscrição robusta (corrige um defeito que hoje quebra o aparelho após "Gerar novas chaves")**

```js
function assinar(registro, chavePublica) {
  return registro.pushManager.getSubscription().then(function (atual) {
    // Chrome rejeita subscribe() com InvalidStateError se já existe inscrição feita com OUTRA applicationServerKey.
    if (atual && !mesmaChave(atual, chavePublica)) { return atual.unsubscribe().then(function () { return null; }); }
    return atual;
  }).then(function (atual) {
    return atual || registro.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ParaBytes(chavePublica) });
  });
}
// mesmaChave: compara b64url(atual.options.applicationServerKey) com a chave do servidor; se o navegador não informar, assume igual.
```

- `sincronizar()` (sem pedir nada; roda a cada abertura): só se `codigo() === 'permitido'` e se a sessão ainda não sincronizou → `registrar()` + `buscarChave()` + `assinar()` + `POST urls.inscrever` (`inscricao.toJSON()`, cabeçalho `X-CSRF-TOKEN`, `credentials: 'same-origin'`). Chave vazia = não faz nada. Isso também **cura** a linha apagada por `generateVapid`/`--force` e reatribui o aparelho a quem entrou por último.
- `ativar()`: chamada direta do clique. Quando a permissão ainda é `default`, a **primeira instrução assíncrona é `Notification.requestPermission()`** (o iOS exige gesto direto; nenhum `fetch`/espera antes dela); se já é `granted`, segue direto. Depois: SW pronto, chave do servidor (vazia → erro `situacao.sem_chaves`), `assinar`, POST. Rejeita com a mensagem de `situacao.<codigo>` para `ios_instalar`, `ios_antigo`, `sem_suporte` e `bloqueado`, e com `situacao.bloqueado` se a pessoa recusar o pedido.
- `registrar()`: `navigator.serviceWorker.register(urls.sw, {scope: '/'})` seguido de `navigator.serviceWorker.ready`, memoizado.

**Folha de aviso (rodapé), só quando `convite` é verdadeiro e o servidor tem chaves (`buscarChave()` não vazio) — exceto a do iPhone, que aparece mesmo sem chaves porque a instalação é pré-requisito**

- `ios_instalar` e `ios-visto` ausente → folha `folha.ios` (título, corpo, 3 passos, nota do iOS < 16.4) com botão **Entendi** (grava `ios-visto` = data ISO; não reaparece).
- `permissao_pendente` e `ativar-dispensado` ausente → folha `folha.ativar` com **Ativar neste aparelho** (`botoes.ativar`; chama `ativar()`; sucesso fecha; erro escreve o texto na própria folha) e **Agora não** (grava `ativar-dispensado`). No iPhone, esta é a folha que aparece na primeira abertura **dentro do app instalado** (o armazenamento do app da tela inicial é separado do Safari).
- DOM criado por `createElement`/`textContent` (nunca `innerHTML` com texto dinâmico), `position: fixed; left/right: 12px; bottom: calc(12px + env(safe-area-inset-bottom)); max-width: 480px; margin: 0 auto; z-index: 2147483000` (acima do sino, z-index 60, e da barra do painel do dono, z-index 10), `role="dialog"`.
- Continua existindo a orientação permanente no painel do sino (`caixa`) e no cartão de Minha Conta, mesmo depois de dispensar a folha.

**Blocos que o cliente pinta (consultando `document.querySelectorAll('[data-vm-push="..."]')` **na hora**, nunca guardando referência; pintar de novo em `load` + `setTimeout(0)` para vir depois do `app.mount` do Vue)**

| atributo | conteúdo |
|---|---|
| `situacao` | texto `situacao.<codigo>` (ou `situacao.ativo` quando há inscrição) |
| `ativar` | botão; visível (`style.display`, **não** o atributo `hidden`, que perde para classes `flex`) quando `permissao_pendente` ("Ativar neste aparelho") ou `ativo` ("Reativar neste aparelho") |
| `testar` | botão; sempre visível; ao clicar chama `testar()` e mostra "Enviando…" |
| `resultado` | mensagem do servidor + uma linha por aparelho (`rotulo — status`, `detalhe`, `dica`) + confirmação de chegada/toque |
| `aparelhos` | lista dos aparelhos do usuário, atualizada após ativar/testar via `GET urls.estado` |

**Teste de ponta a ponta no cliente (`testar()`)**: `POST urls.testar`; 401/419 → `teste.sessao`; 429 → `teste.muitas`; se `resultado === 'enviado'`, acompanhar com `GET urls.estado?token=...` a cada 2 s por até 30 s: ao chegar `entregue_em` mostrar `teste.chegou`; ao chegar `aberto_em` mostrar `teste.aberta` e parar; se passar o tempo sem `entregue_em` mostrar `teste.sem_confirmacao`.

**Delegação de eventos**: um único `document.addEventListener('click', ...)` dentro de `iniciar` trata `[data-vm-push="ativar"]`, `[data-vm-push="testar"]`, `[data-vm-push="entendi"]`, `[data-vm-push="agora-nao"]`, `[data-vm-push="ativar-folha"]` (via `event.target.closest`). Isso sobrevive à reconstrução do DOM pelo Vue.

#### A2.7 `SO/Resources/views/components/workshop-push-boot.blade.php` (criar)

Inclui o cliente e inicia. Variáveis: `$convite` (padrão `true`), `$caixa` (padrão `null`).

```blade
@if (auth()->guard('user')->check() && \Webkul\ServiceOrder\Services\WebPush::habilitado())
    <script src="{{ route('workshop.pwa.push_client', [], false) }}"></script>
    <script>
        VmPush.iniciar({
            usuario: @json(auth()->guard('user')->id()),
            csrf: @json(csrf_token()),
            convite: @json($convite ?? true),
            caixa: @json($caixa ?? null),
            urls: {
                chave: @json(route('workshop.push.key', [], false)),
                inscrever: @json(route('admin.workshop.push.subscribe', [], false)),
                sw: @json(route('workshop.pwa.sw', [], false)),
                testar: @json(route('admin.workshop.push.test', [], false)),
                estado: @json(route('admin.workshop.push.state', [], false))
            }
        });
    </script>
@endif
```

`workshop.pwa.sw` = `/workshop-sw.js`: o teste `HttpFlowTest.php:261` (`toContain('/workshop-sw.js')` com o piloto ligado) continua passando por aqui. Sem `defer` (o script inline logo abaixo precisa do `VmPush` pronto).

#### A2.8 `SO/Resources/views/components/workshop-bell.blade.php` (alterar)

- `:5` apagar `$inscreverUrl` (não é mais usado).
- `:34-40` em `urls`: remover `inscrever`, `chave`, `sw` (ficam no boot).
- `:51-52` remover `guardar` e `ler`.
- `:85-170` remover **todo** o bloco "Notificação no celular (Web Push)" (`b64ParaBytes`, `ehIphone`, `instalado`, `inscrever`, `aviso`, `prepararPush`) e `:174` (`prepararPush();`).
- Manter o `<div id="vm-bell-push" ...>` (`:22`): agora é a `caixa` pintada pelo cliente.
- Logo antes do `@endif` final (`:177`), depois do `</script>` do sino: `@include('service_order::components.workshop-push-boot', ['convite' => true, 'caixa' => 'vm-bell-push'])`.

#### A2.9 `SO/Resources/views/components/workshop-head.blade.php` (alterar)

- Condição do `@if` (`:1`): `auth()->guard('user')->check() && \Webkul\ServiceOrder\Services\WebPush::habilitado()`.
- Acrescentar `<meta name="apple-mobile-web-app-title" content="Volmaster">` (nome do ícone no iPhone).
- Regressão a preservar: `HttpFlowTest.php:246-253` (piloto desligado e sem chaves → sem `vm-bell-btn` e sem `workshop.webmanifest`) e `:255-262` (piloto ligado → com manifesto, sino e `/workshop-sw.js`).

#### A2.10 `SO/Resources/views/tecnico/index.blade.php` (alterar — compartilhado com F05)

- Cabeçalho (`:47`): quando `WebPush::habilitado()`, acrescentar `&middot; <a href="{{ route('admin.user.account.edit') }}">Minha conta</a>` (é por onde o técnico chega ao botão de teste).
- Antes de `</body>` (`:280`, depois do `</script>` de `:279`): `@include('service_order::components.workshop-push-boot', ['convite' => true])`. Manter `:278`.
- Nenhum texto novo da tela pode conter valores em R$, crédito, desconto ou comissão (regra 6: o Técnico nunca vê dinheiro). `TecnicoValoresTest.php:102-126` confere o JSON de `admin.tecnico.tasks`; esta frente **não** altera esse JSON.

#### A2.11 `SO/Resources/views/mentoria/painel-celular.blade.php` (alterar — compartilhado com F05)

- Cabeçalho (`:40`): ao lado do link "Sistema", quando `WebPush::habilitado()`, link "Minha conta" com o mesmo estilo.
- Depois de `</script>` (`:208`), antes de `</body>` (`:209`): `@include('service_order::components.workshop-push-boot', ['convite' => true])`. Hoje esta tela não registra service worker algum.
- Cuidado: a página define o global `var app` (`:60`) e a barra `#abas` tem `z-index: 10`; a folha de aviso é um `div` anexado ao `body` (fora de `nav`/`main`), então não conflita com o manipulador de `nav button` (`:203`).

#### A2.12 `SO/Services/PushTeste.php` (criar)

Lógica do botão, testável sem HTTP.

```php
namespace Webkul\ServiceOrder\Services;

class PushTeste
{
    public const PREFIXO = 'teste-';
    public const RESULTADO_ENVIADO = 'enviado';
    public const RESULTADO_FALHOU = 'falhou';
    public const RESULTADO_SEM_APARELHO = 'sem_aparelho';
    public const RESULTADO_SEM_CHAVES = 'sem_chaves';

    public function __construct(protected WebPush $push) {}

    /**
     * @return array{resultado:string, ok:bool, mensagem:string, token:?string,
     *               aparelhos:array<int,array{id:int,rotulo:string,status:string,detalhe:?string,dica:?string}>}
     */
    public function enviar(User $user): array

    /** Chamado pelo ack público do service worker. false = token desconhecido/expirado. */
    public function confirmar(string $token, string $status): bool      // $status: entregue | aberto

    /** @return array{chaves:bool, aparelhos:array, teste:?array{entregue_em:?string, aberto_em:?string}} */
    public function estado(User $user, ?string $token = null): array
}
```

Regras de `enviar`, nesta ordem:

1. `! WebPush::configurado()` → `sem_chaves` (nada é enviado).
2. Sem tabela (`! PushSubscription::disponivel()`) ou sem linhas do usuário (`user_id` = o próprio) → `sem_aparelho`.
3. Gera `$token = self::PREFIXO.Str::random(24)` e grava no cache `push-teste:{token}` (10 min) com `['user_id' => $user->id, 'entregue_em' => null, 'aberto_em' => null]`. Cache padrão é `file` (`config/cache.php:18`; não depende do banco da oficina).
4. Payload (nunca leva valores): `title` = `notificacao.teste.titulo`, `body` = `notificacao.teste.corpo`, `url` = `route('admin.workshop.alerts.index', [], false)` (o sistema, logado), `tag` = `'teste-'.$user->id`, `critical` = `false`, `tokens` = `[$token]`.
5. Para **cada** aparelho do usuário: `$this->push->send($sub, $payload, ttl: 120, urgency: 'high')` (TTL curto: teste atrasado não serve). `ok` → `enviado`; `gone` (404/410) → apaga a inscrição e `removido`; senão `falhou` com `detalhe` = `$r['error']` e `dica`: `status` 400/401/403 → `teste.dica_chaves`; `status` 0 ou ≥ 500 → `teste.dica_rede`.
6. Resultado final: pelo menos um `enviado` → `enviado` (mensagem `teste.enviado` com `:n`); senão `falhou` (mensagem `teste.falhou`). `Log::info('push.teste', [...])` com usuário e resultado.

`confirmar`/`estado`: leem e atualizam a chave do cache; `estado` devolve `teste = null` quando o token não existe ou **pertence a outro usuário**. Horários em `H:i:s`, fuso `America/Sao_Paulo`.

Nenhum `try/catch` aqui: `WebPush::send` já devolve o erro em array.

#### A2.13 `SO/Http/Controllers/PushTesteController.php` (criar)

Estende `Webkul\Admin\Http\Controllers\Controller` (mesmo padrão de `AlertController`).

```php
public function enviar(PushTeste $teste): JsonResponse   // POST: response()->json($teste->enviar(auth()->guard('user')->user()))   (200 também para falhou/sem_aparelho/sem_chaves)
public function estado(PushTeste $teste): JsonResponse   // GET ?token=: response()->json($teste->estado(auth()->guard('user')->user(), request('token')))
```

#### A2.14 `SO/Routes/web.php` (alterar — compartilhado)

Depois do grupo de alertas (`:122-130`):

```php
// Teste da notificação no celular (qualquer usuário logado; sem ACL de propósito, como push/inscrever).
Route::controller(PushTesteController::class)->prefix('workshop/push')->group(function () {
    Route::post('testar', 'enviar')->middleware('throttle:10,1')->name('admin.workshop.push.test');
    Route::get('estado', 'estado')->middleware('throttle:60,1')->name('admin.workshop.push.state');
});
```

Import de `PushTesteController` no topo.

#### A2.15 `SO/Providers/ServiceOrderServiceProvider.php` (alterar — compartilhado)

Depois do `Event::listen('admin.layout.head.after'...)` (`:60-63`):

```php
// Cartão "Notificações no celular" em Minha Conta (gancho que o Admin já oferece: sem editar o núcleo).
Event::listen('admin.user.account.form.after', function ($viewRenderEventManager) {
    $viewRenderEventManager->addTemplate('service_order::components.workshop-push-perfil');
});
```

#### A2.16 `SO/Resources/views/components/workshop-push-perfil.blade.php` (criar)

Renderizado só se `auth()->guard('user')->check() && WebPush::habilitado()` (**gate**: piloto desligado e sem chaves = a tela de conta fica igual a hoje). Só HTML estático com atributos `data-vm-push` (sem `{{ }}` do Vue, `@click`, `:attr` nem `v-`: a página é compilada pelo Vue). Estrutura:

```blade
<div id="vm-push-perfil" class="box-shadow mt-3.5 rounded bg-white p-4 dark:bg-gray-900">
    <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">@lang('service_order::app.notificacao.titulo')</p>
    <p class="mb-3 text-xs text-gray-600 dark:text-gray-300">@lang('service_order::app.notificacao.intro')</p>

    <p class="mb-3 text-sm dark:text-white" data-vm-push="situacao">@lang('service_order::app.notificacao.situacao.verificando')</p>

    <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="secondary-button" data-vm-push="ativar" style="display:none">@lang('service_order::app.notificacao.botoes.ativar')</button>
        <button type="button" class="primary-button" data-vm-push="testar">@lang('service_order::app.notificacao.botoes.testar')</button>
    </div>

    <div class="mt-3 text-sm dark:text-white" data-vm-push="resultado" aria-live="polite" style="display:none"></div>

    <p class="mb-1 mt-4 text-sm font-semibold dark:text-white">@lang('service_order::app.notificacao.aparelhos_titulo')</p>
    <ul class="text-sm dark:text-gray-200" data-vm-push="aparelhos">
        {{-- renderizado no servidor: cada aparelho do usuário com $a->rotulo e "desde dd/mm/aaaa hh:mm"; vazio = aparelhos_vazio --}}
    </ul>

    <details class="mt-4 text-sm dark:text-gray-200">
        <summary class="cursor-pointer font-semibold">@lang('service_order::app.notificacao.como_instalar')</summary>
        <p class="mt-2">@lang('service_order::app.notificacao.instalar_android')</p>
        <p class="mt-2">@lang('service_order::app.notificacao.instalar_iphone')</p>
    </details>
</div>

@push('scripts')
    @include('service_order::components.workshop-push-boot', ['convite' => false])
@endpush
```

- A lista de aparelhos usa `PushSubscription::disponivel()` (vazia se a tabela não existir).
- O boot vai em `@push('scripts')` para ficar **fora** do `#app` (o Vue descarta `<script>` dentro de template). Se o sino também fez `iniciar`, a segunda chamada é ignorada (idempotente) — as duas pintam os mesmos blocos `data-vm-push` em `load`.
- A página inteira contém `R$` na meta `currency` do layout; qualquer teste de "sem valores" deve olhar o **parcial** (`view(...)->render()`) ou o JSON, não a página toda.

#### A2.17 `SO/Console/Commands/WorkshopVapidKeysCommand.php` (alterar)

- Linha 32: `if (PushSubscription::disponivel()) { DB::table('push_subscriptions')->delete(); }` (import do model).
- Depois de "Chaves geradas.": se `push_vapid_subject` estiver vazio, `$this->warn('Preencha o e-mail de contato em Configurações > Indicadores e alertas (campo "E-mail de contato das notificações").')`.
- Acrescentar ao `description` que o comando atua só na instalação principal; as oficinas geram pela tela.

### Bloco A3 — SMS de reserva

#### A3.1 `SO/Services/Sms/SmsManager.php` (alterar)

Refatorar o miolo de `sendAlert` (`:41-75`) em um método comum que devolve o `SmsLog`, para o SMS de teste usar **exatamente as mesmas regras**:

```php
public function sendAlert(WorkshopAlert $alert): string
{
    $text = $this->textFor($alert);

    return $this->deliver($alert->user, $text, ['alert_id' => $alert->id, 'user_id' => $alert->user_id, 'mensagem' => $text])->status;
}

public const TEXTO_TESTE = 'Teste Volmaster: o SMS de reserva da oficina esta funcionando.';   // ASCII, < 160

public function sendTest(User $user): SmsLog
{
    return $this->deliver($user, self::TEXTO_TESTE, ['alert_id' => null, 'user_id' => $user->id, 'mensagem' => self::TEXTO_TESTE]);
}

protected function deliver(?User $user, string $text, array $base): SmsLog
// Mesma sequência de hoje: (1) sem celular ou sem aceite -> bloqueado/DETALHE_SEM_CONTATO; (2) monthlyCount() >= limite ->
// bloqueado/DETALHE_LIMITE (nenhuma chamada ao provedor); (3) gateway()->send($this->e164(...), $text); status falhou|simulado|enviado,
// custo só quando 'enviado'. log() passa a devolver o SmsLog criado.
```

- `monthlyCount(?Carbon $mes = null): int` — aceita um mês; devolve 0 se `! SmsLog::disponivel()` (a tela de configuração não pode dar 500 em banco sem a tabela). Continua contando `enviado` + `simulado` do mês (decisão: o simulado consome o limite de propósito, para a oficina de teste reproduzir o bloqueio).
- `resumoDoMes(?Carbon $mes = null): array`:
  `['usados' => int, 'limite' => int, 'simulados' => int, 'enviados' => int, 'falharam' => int, 'limite_atingido' => int, 'sem_contato' => int, 'custo_real' => float /* soma de custo dos enviados */, 'custo_estimado' => float /* simulados × sms_custo_unitario atual */]`. Usa `whereBetween('created_at', [$mes->copy()->startOfMonth(), $mes->copy()->endOfMonth()])` (nunca `AAAA-MM-31`).
- **Correção do `e164` (`:120-125`)** — o DDD 55 (RS) hoje é lido como código do país:

```php
protected function e164(string $phone): string
{
    $digits = ltrim(preg_replace('/\D/', '', $phone), '0');

    // Com código do país são 12 ou 13 dígitos (55 + DDD + número); sem ele, 10 ou 11. "55" no começo de 11 dígitos é o DDD do RS.
    return strlen($digits) >= 12 && str_starts_with($digits, '55') ? '+'.$digits : '+55'.$digits;
}
```

Casos: `(55) 99999-8888` → `+5555999998888`; `+55 (11) 98888-7777` → `+5511988887777`; `(11) 98888-7777` → `+5511988887777` (já testado em `AlertChannelsTest.php:98`); `011 98888-7777` → `+5511988887777`.

- Texto (`textFor`) e limite de 160 caracteres: **não mudar**.

#### A3.2 `SO/Services/AlertDispatcher.php` (alterar — arquivo compartilhado)

O horário de silêncio passa a segurar também o SMS **não crítico**. O crítico segue imediato.

```php
public function dispatch(WorkshopAlert $alert): void
{
    $this->delivery($alert, 'in_app', 'alerta', 'enviado');
    $this->dispatchPush($alert);

    if ($alert->critico) {
        $this->sendSms($alert);
    } elseif ($alert->nivel >= 3 && ! $this->inQuietHours(now())) {
        $this->sendSms($alert);        // no silêncio fica com sms_status nulo e sai no flush
    }
}

public function flush(Carbon $now): void
{
    $this->flushPendingPush($now);
    $this->flushPendingLevelThreeSms($now);
    $this->escalateLevelTwoToSms($now);
}

/** Nível 3 que esperou o fim do silêncio. Só alerta ainda aberto (barreira cumprida no meio da noite não gera SMS velho). */
protected function flushPendingLevelThreeSms(Carbon $now): void
{
    if ($this->inQuietHours($now)) {
        return;
    }

    WorkshopAlert::abertos()->where('critico', false)->where('nivel', '>=', 3)->whereNull('sms_status')->get()
        ->each(fn (WorkshopAlert $alert) => $this->sendSms($alert));
}
```

`escalateLevelTwoToSms` (`:184-203`):

- Primeira linha: `if ($this->inQuietHours($now)) { return; }`.
- Dentro do `foreach`: `if ($alert->push_status === 'pendente') { continue; }` (a notificação ainda está segurada pelo agrupamento; o SMS espera ela sair).
- A espera passa a contar do **envio da notificação**: `$referencia = $alert->push_enviado_em ?? $alert->disparado_em; $venceuEspera = $referencia->lte($now->copy()->subMinutes($minutos));`. Sem isso, um nível 2 segurado a noite toda mandaria push e SMS juntos às 07:00.
- `falhou` (`falhou` ou `sem_dispositivo`) continua indo para SMS sem esperar, mas só fora do silêncio.
- Atualizar o docblock da classe (diz "silêncio segura tudo, menos os críticos": agora é verdade também para SMS).

Os testes atuais continuam válidos (todos rodam entre 11:00 e 13:00): `AlertChannelsTest.php:86-128,130-182,184-196`.

#### A3.3 `SO/Http/Controllers/WorkshopSettingsController.php` (alterar)

**`testSms()`** — nova ação (POST `admin.workshop.settings.sms_test`):

```php
public function testSms(): RedirectResponse
{
    $user = auth()->guard('user')->user();
    $log = app(SmsManager::class)->sendTest($user);

    [$tipo, $texto] = match ($log->situacao()) {
        'enviado' => ['success', trans('service_order::app.sms.teste.enviado', ['telefone' => $log->telefone, 'provedor' => $log->provedor])],
        'simulado' => ['warning', trans('service_order::app.sms.teste.simulado')],
        'falhou' => ['error', trans('service_order::app.sms.teste.falhou', ['motivo' => $log->detalhe])],
        'limite' => ['warning', trans('service_order::app.sms.teste.limite')],
        default => ['warning', trans('service_order::app.sms.teste.sem_contato')],
    };

    session()->flash($tipo, $texto);

    return back();
}
```

**`smsReport()` (`:248-258`)** — reescrever:

- Validação: `mes` = `nullable|date_format:Y-m`; `situacao` = `nullable|in:simulado,enviado,falhou,limite,sem_contato`.
- `$mes = Carbon::createFromFormat('Y-m-d', (request('mes') ?: now()->format('Y-m')).'-01')`.
- `$logs = SmsLog::with(['user:id,name', 'alert:id,titulo,service_order_id'])->whereBetween('created_at', [início, fim do mês])->when(request('situacao'), fn ($q, $s) => $q->situacao($s))->latest('id')->paginate(50)->withQueryString()`.
- View recebe: `logs`, `mes`, `situacao`, `resumo` (= `SmsManager::resumoDoMes($mes)`), `limite`, `provedor` (`WorkshopSettings::get('sms_provedor')`), `simulado` (bool), `veValores` (= `ValoresFinanceiros::can()`), `tabelaOk` (= `SmsLog::disponivel()`).

**`edit()`**: já recebe `smsMes` (`monthlyCount()`); acrescentar o `smsResumo` descrito em A2.1.

#### A3.4 `SO/Resources/views/settings/workshop.blade.php` (alterar — bloco SMS, `:69-89`)

- Dica (`:71`): acrescentar "Fora do horário de silêncio (os alertas críticos furam o silêncio)." e, quando `$settings['sms_provedor'] === 'log'`, faixa amarela `sms.modo_simulado`; quando `twilio`, faixa `sms.modo_real`.
- `:82`: rótulo "SMS no mês (enviados + simulados): **X** de LIMITE" (hoje diz "Enviados no mês", o que engana no modo simulado).
- Botão de teste dentro do bloco SMS, **sem formulário aninhado**: `<button type="submit" form="form-sms-teste" class="secondary-button">...sms.teste.botao...</button>`; o `<form id="form-sms-teste" method="POST" action="{{ route('admin.workshop.settings.sms_test') }}">@csrf</form>` vai logo depois do `</form>` geral (`:101`).
- Manter os campos Twilio e o comportamento do token (só regrava se digitado, `:106-109`).

#### A3.5 `SO/Resources/views/settings/sms-report.blade.php` (reescrever o conteúdo, mesmo layout `x-admin::layouts`)

```
Cabeçalho: "Relatório de SMS"   [Enviar SMS de teste para o meu celular] (form próprio)   [Voltar]
Faixa (se $simulado): sms.modo_simulado
Filtros (GET): <input type="month" name="mes">  <select name="situacao"> (todas, Simulado, Enviado, Falhou, Limite, Sem contato)  [Filtrar]
Cartões: Usados no mês X de LIMITE (só no mês corrente) | Simulados | Enviados | Falharam | Não enviados: limite | Não enviados: sem celular/aceite
Se $veValores: Custo real (enviados) R$ ... | Custo estimado dos simulados R$ ... (n × custo atual por SMS)
Tabela: Quando (d/m/Y H:i) | Pessoa | Telefone | Alerta / OS | Mensagem | Provedor | Situação | [Custo, só se $veValores]
```

- Pessoa = `$log->user?->name ?? '—'`; Alerta = `$log->alert?->titulo` + link para a OS (`admin.service_orders.view`) quando houver `service_order_id`; sem alerta = "Teste de SMS".
- Provedor `log` aparece como "Simulado"; Situação = `$log->rotuloSituacao()`, com `detalhe` entre parênteses quando `falhou`.
- Custo e totais em R$ **somem** para quem não vê valores (`ValoresFinanceiros::can()`; com o piloto desligado é sempre verdadeiro). O relatório não tem valor de OS nem crédito; a mensagem do SMS já não leva R$ (`SmsManager::textFor`).
- Se `! $tabelaOk`: caixa informativa "As tabelas de SMS ainda não existem neste banco. Rode a atualização do banco." em vez de erro.
- Datas `dd/mm/aaaa hh:mm`.

#### A3.6 `SO/Routes/web.php` e `SO/Config/acl.php` (alterar — compartilhados)

- `Routes/web.php:141` (grupo `settings/workshop`): `Route::post('sms-teste', 'testSms')->name('admin.workshop.settings.sms_test');`
- `Config/acl.php:110-119`: acrescentar `'admin.workshop.settings.sms_test'` na lista de rotas da chave `settings.workshop`. Lembrete: o mapa rota→chave usa a **última** chave que cita a rota; nenhuma outra chave cita essa rota.

### Bloco L — Textos

`SO/Resources/lang/pt_BR/app.php` e `SO/Resources/lang/en/app.php` (alterar — compartilhados): acrescentar as seções `notificacao` e `sms` antes do `];` final (pt_BR termina em `:278`; en em `:117`). Texto pt-BR completo na seção "Textos da interface". Em `en`, espelhar **as mesmas chaves** com tradução em inglês.

---

## Banco (migrations)

**Nenhuma migration.** Justificativa:

- A dispensa do aviso do iPhone é por pessoa no navegador (`localStorage`), não precisa de coluna.
- O resultado do teste de push vive 10 minutos no cache e a confirmação vem do service worker; nada é persistido.
- O rótulo "não enviado: limite" é derivado de `status = 'bloqueado'` + `detalhe` (constantes em `SmsLog`), preservando os registros e os testes atuais. Não se cria coluna `motivo` para não depender de o usuário rodar o `migrate-safe`.
- Tolerância a banco desatualizado (servidor só recebe migration quando o usuário rodar `public/migrate-safe.php`; oficinas só quando F00 migrar os bancos delas): `PushSubscription::disponivel()` e `SmsLog::disponivel()` (memoizados com `Schema::hasTable`, mesmo padrão de `Services/PrevisaoOs::disponivel()`) protegem o cartão de Minha Conta, a tela de configuração, o relatório de SMS, o comando e `monthlyCount()`. O cast de `sms_aceite_em` é inerte se a coluna não existir.
- Tabelas/colunas que já precisam existir para o push e o SMS funcionarem (migrations `2026_11_16_100300` e `2026_11_16_100400`): `push_subscriptions`, `sms_logs`, `workshop_alerts`, `alert_deliveries`, `users.celular`, `users.sms_aceite_em`. **Para F00:** o banco de cada oficina nova precisa delas.

---

## ACL, menu, rotas e configurações

**ACL**

- Nenhuma chave nova.
- `admin.workshop.settings.sms_test` entra na lista de rotas da chave existente `settings.workshop` (`Config/acl.php:110-119`).
- As rotas `admin.workshop.push.test`, `admin.workshop.push.state` (e as já existentes `admin.workshop.push.subscribe`, `admin.workshop.push.unsubscribe`, `admin.workshop.alerts.*`, `admin.user.account.*`) ficam **sem ACL de propósito**: toda pessoa logada precisa delas, inclusive Técnico, Auxiliar de copa e Mentor. **Aviso para F05:** se o mecanismo de "403 em rota" negar por padrão o que não está mapeado, estas rotas precisam entrar numa lista de exceções.

**Menu**

- Nenhum item novo. O botão vive em Minha Conta (menu do avatar) e o relatório continua em Configurações > Indicadores e alertas.

**Rotas novas**

| Nome | Método e caminho | Controlador | Acesso |
|---|---|---|---|
| `admin.workshop.push.test` | POST `/admin/workshop/push/testar` | `PushTesteController@enviar` (`throttle:10,1`) | qualquer usuário logado |
| `admin.workshop.push.state` | GET `/admin/workshop/push/estado` | `PushTesteController@estado` (`throttle:60,1`) | qualquer usuário logado |
| `workshop.pwa.push_client` | GET `/workshop-push.js` | `PwaController@pushClient` | público (sem segredo) |
| `admin.workshop.settings.sms_test` | POST `/admin/settings/workshop/sms-teste` | `WorkshopSettingsController@testSms` | `settings.workshop` |

**Chaves de `WorkshopSettings`**: nenhuma nova. Usadas: `push_vapid_public`, `push_vapid_private`, `push_vapid_subject`, `sms_provedor`, `sms_limite_mensal`, `sms_custo_unitario`, `sms_nao_aberto_minutos`, `sms_twilio_sid`, `sms_twilio_token`, `sms_twilio_from`, `silencio_ativo`, `silencio_inicio`, `silencio_fim`, `agrupar_alertas_minutos`.

**Gancho de view novo**: `admin.user.account.form.after` (listener no `ServiceOrderServiceProvider`).

**Chaves de armazenamento do navegador**: `vm-push:<usuarioId>:ios-visto`, `vm-push:<usuarioId>:ativar-dispensado` (localStorage); `vm-push:<usuarioId>:sincronizado` (sessionStorage). **Cache**: `push-teste:<token>` (10 min); tokens com prefixo `teste-`.

**Tipos de evento / assuntos de alçada / códigos de barreira**: nenhum novo.

---

## Textos da interface (pt-BR)

Colar em `SO/Resources/lang/pt_BR/app.php` (e espelhar em `en/app.php`).

```php
'notificacao' => [
    'titulo' => 'Notificações no celular',
    'intro' => 'Receba os alertas da oficina na tela do celular, mesmo com o sistema fechado. Ative uma vez em cada aparelho e use "Testar notificação" para conferir.',
    'aparelhos_titulo' => 'Seus aparelhos cadastrados',
    'aparelhos_vazio' => 'Nenhum aparelho cadastrado ainda.',
    'aparelho_desde' => 'desde :data',
    'como_instalar' => 'Como instalar o aplicativo no celular',
    'instalar_android' => 'Android (Chrome): toque nos três pontinhos do navegador e escolha "Instalar app" (ou "Adicionar à tela inicial"). Abra o Volmaster pelo ícone novo.',
    'instalar_iphone' => 'iPhone (iOS 16.4 ou superior): no Safari, toque em Compartilhar (o quadrado com a seta para cima), escolha "Adicionar à Tela de Início" e abra o Volmaster pelo ícone novo. Só assim o iPhone recebe as notificações. O aplicativo da tela inicial tem sessão própria: entre com seu usuário de novo.',

    'situacao' => [
        'verificando' => 'Verificando este aparelho…',
        'ativo' => 'Notificações ativadas neste aparelho.',
        'permissao_pendente' => 'As notificações ainda não foram ativadas neste aparelho.',
        'bloqueado' => 'As notificações estão bloqueadas neste aparelho. Libere nas configurações do navegador (no iPhone, em Ajustes > Notificações > Volmaster) e volte aqui.',
        'ios_instalar' => 'No iPhone e no iPad as notificações só funcionam com o sistema instalado na tela inicial (iOS 16.4 ou superior). Veja "Como instalar o aplicativo no celular" abaixo.',
        'ios_antigo' => 'Este iPhone ou iPad não recebe notificações: é preciso o iOS 16.4 ou superior (Ajustes > Geral > Atualização de Software). Enquanto isso, os alertas chegam pelo sino e por SMS.',
        'sem_suporte' => 'Este navegador não recebe notificações. Use o Chrome no Android ou o Safari no iPhone. Se você abriu o link pelo WhatsApp, abra-o no navegador.',
        'sem_chaves' => 'As chaves das notificações ainda não foram geradas. Peça ao proprietário ou ao suporte para gerar em Configurações > Indicadores e alertas.',
    ],

    'botoes' => [
        'ativar' => 'Ativar neste aparelho',
        'reativar' => 'Reativar neste aparelho',
        'testar' => 'Testar notificação',
        'testando' => 'Enviando…',
        'entendi' => 'Entendi',
        'agora_nao' => 'Agora não',
    ],

    'teste' => [
        'titulo' => 'Teste de notificação',
        'corpo' => 'Está funcionando! Toque aqui para abrir o sistema.',
        'enviado' => 'Notificação enviada para :n aparelho(s). Olhe a tela do celular: ela deve chegar em instantes. Toque nela para abrir o sistema.',
        'falhou' => 'Não foi possível enviar a notificação de teste. Veja o motivo de cada aparelho abaixo.',
        'sem_aparelho' => 'Você ainda não tem nenhum aparelho cadastrado. No celular em que quer receber os alertas, toque em "Ativar neste aparelho" e teste de novo.',
        'sem_chaves' => 'As chaves das notificações ainda não foram geradas. Peça ao proprietário ou ao suporte para gerar em Configurações > Indicadores e alertas > Chaves das notificações no celular.',
        'aparelho_enviado' => 'enviada',
        'aparelho_falhou' => 'falhou (:detalhe)',
        'aparelho_removido' => 'removido: o app foi desinstalado ou a permissão foi retirada',
        'dica_chaves' => 'Dica: as chaves mudaram depois que este aparelho foi ativado, ou o e-mail de contato é inválido. Toque em "Reativar neste aparelho" no celular e teste de novo.',
        'dica_rede' => 'Dica: o serviço de notificações do navegador não respondeu. Tente de novo em instantes.',
        'chegou' => 'Chegou ao aparelho às :hora.',
        'aberta' => 'Você tocou na notificação às :hora: o sistema abriu.',
        'aguardando' => 'Aguardando o aparelho confirmar…',
        'sem_confirmacao' => 'O serviço aceitou o envio, mas o aparelho ainda não confirmou. Confira se o celular está com internet, fora do modo "Não perturbe" e com as notificações do Volmaster permitidas.',
        'sessao' => 'Sua sessão expirou. Entre de novo no sistema.',
        'muitas' => 'Muitos testes seguidos. Espere um minuto e tente de novo.',
        'erro_servidor' => 'Não foi possível cadastrar este aparelho. Tente de novo.',
    ],

    'folha' => [
        'ios' => [
            'titulo' => 'Receba os alertas no iPhone',
            'corpo' => 'No iPhone, as notificações só funcionam com o sistema adicionado à tela inicial (iOS 16.4 ou superior).',
            'passos' => [
                'Toque em Compartilhar no Safari (o quadrado com a seta para cima).',
                'Escolha "Adicionar à Tela de Início" e confirme.',
                'Abra o Volmaster pelo ícone novo, entre com seu usuário e toque em "Ativar neste aparelho".',
            ],
            'nota' => 'Se o seu iPhone tem iOS mais antigo que 16.4, atualize em Ajustes > Geral > Atualização de Software.',
        ],
        'ativar' => [
            'titulo' => 'Ative as notificações',
            'corpo' => 'Receba os alertas da oficina no celular, mesmo com o sistema fechado.',
        ],
    ],

    'config' => [
        'assunto_invalido' => 'Informe um e-mail real (ex.: oficina@exemplo.com.br) ou um endereço que comece com https://. E-mails de exemplo não são aceitos.',
        'assunto_ajuda' => 'Digite só o e-mail: o sistema acrescenta "mailto:" sozinho. O iPhone recusa notificações sem um contato válido. Em branco, usamos :padrao.',
        'chaves_ok' => 'Chaves de notificação geradas',
        'chaves_faltam' => 'Chaves de notificação ainda não geradas',
        'sem_email_aviso' => 'Chaves geradas. Falta preencher o e-mail de contato das notificações (no bloco "Notificações", acima) e salvar.',
        'quem_ativou' => 'Quem já ativou as notificações',
        'quem_ativou_dica' => 'Antes de ligar o piloto numa oficina real, cada pessoa precisa ter o aplicativo instalado e as notificações permitidas.',
        'ativou' => 'Ativou',
        'falta_ativar' => 'Falta ativar',
    ],
],

'sms' => [
    'situacao' => [
        'simulado' => 'Simulado (não enviado)',
        'enviado' => 'Enviado',
        'falhou' => 'Falhou',
        'limite' => 'Não enviado: limite mensal atingido',
        'sem_contato' => 'Não enviado: sem celular ou sem aceite de SMS',
    ],
    'modo_simulado' => 'Modo simulado: nenhum SMS é enviado de verdade. A lista abaixo mostra o que TERIA sido enviado.',
    'modo_real' => 'Provedor ativo: :provedor. Os SMS são enviados de verdade e cobrados pelo provedor.',
    'aviso_credenciais' => 'O provedor Twilio está selecionado, mas faltam :faltando. Os SMS vão falhar até você preencher.',
    'relatorio' => [
        'titulo' => 'Relatório de SMS',
        'usados' => 'Usados no mês',
        'simulados' => 'Simulados',
        'enviados' => 'Enviados',
        'falharam' => 'Falharam',
        'limite' => 'Não enviados: limite',
        'sem_contato' => 'Não enviados: sem celular/aceite',
        'custo_real' => 'Custo real (enviados)',
        'custo_estimado' => 'Custo estimado dos simulados',
        'pessoa' => 'Pessoa',
        'alerta' => 'Alerta / OS',
        'teste' => 'Teste de SMS',
        'sem_dados' => 'Nenhum SMS neste período.',
        'sem_tabela' => 'As tabelas de SMS ainda não existem neste banco. Rode a atualização do banco.',
    ],
    'teste' => [
        'botao' => 'Enviar SMS de teste para o meu celular',
        'enviado' => 'SMS de teste enviado para :telefone pelo provedor :provedor. Se não chegar em alguns minutos, veja o motivo no Relatório de SMS.',
        'simulado' => 'Modo simulado: o SMS de teste foi apenas registrado no Relatório de SMS (nada foi enviado ao celular).',
        'falhou' => 'O provedor recusou o SMS de teste: :motivo',
        'limite' => 'Não enviado: o limite mensal de SMS já foi atingido.',
        'sem_contato' => 'Não enviado: cadastre o seu celular e marque "aceita SMS" em "Papéis e celulares" e tente de novo.',
    ],
],
```

---

## Testes Pest a escrever

**Regras**: três arquivos novos em `tests/Feature/Workshop/` (herdam `DatabaseTransactions` + `BuildsWorkshop` via `tests/Pest.php:21-23`). Nomes globais **únicos**: funções auxiliares com prefixo `f02Push…`/`f02Sms…`; classes de teste com prefixo `F02…`. **Não** redeclarar `FakeWebPush`, `FakeSmsGateway`, `channels`, `subscribe`, `withPhone`, `diagnosisEndedAt` (`AlertChannelsTest.php`) nem `staff` (`HttpFlowTest.php:18`), senão dá "Cannot declare class". Usuário Técnico de teste: `$this->makeUser('tecnico', ['tecnico'])` (permissões vazias deslogam: `Bouncer::isPermissionsEmpty`). Usuário com configuração mas sem valores: `$this->makeUser('gerente', ['settings', 'settings.workshop'])` (role com `settings.xxx` precisa também de `settings`). Se `GET admin.user.account.edit` não renderizar no ambiente de teste por causa dos assets do Vite, trocar por duas verificações: o parcial isolado (`view('service_order::components.workshop-push-perfil')->render()` com `actingAs`) e o disparo do gancho (`view_render_event('admin.user.account.form.after')` devolve o HTML do cartão); as demais páginas do Admin já renderizam nos testes (`HttpFlowTest.php:251,259`).

### `WebPushProtocolTest.php` — prova do protocolo (A2.1, A2.3)

| Teste | Cobre | Como |
|---|---|---|
| RFC 8291 §5: a criptografia confere com o vetor oficial | A2.1/A2.6 (o push chega ao aparelho) | `(new WebPush)->encrypt(...)` com os valores abaixo; esperar a string exata. |
| Cabeçalho VAPID: `aud`, `sub`, `exp` ≤ 24 h, assinatura ES256 válida | A2.1/A2.2 | `WorkshopSettings::set` com par de `generateVapidKeys()`; `vapidAuthorization('https://push.example.test/abc')`; separar `vapid t=<jwt>, k=<pub>`; decodificar claims; converter r‖s em DER (`0x30`, dois `0x02`, acrescentando `0x00` se o 1º byte tiver o bit alto) e `openssl_verify` com `pemFromPublicPoint` (via `ReflectionMethod`). |
| `configurado()` exige pública e privada; `habilitado()` = piloto ou chaves | A2.1/A2.3 | Só pública → falso; as duas → verdadeiro; piloto ligado sem chaves → `habilitado()` verdadeiro, `configurado()` falso. |
| `normalizarAssunto` / `assunto` | A2.2 | `oficina@exemplo.com.br` → `mailto:oficina@exemplo.com.br`; `mailto: <a@b.com>` → `mailto:a@b.com`; `https://volmastercrm.com/suporte` igual; `abc` → null; `x@example.com` e `x@oficina.local` → null; vazio → `assunto()` = `WebPush::ASSUNTO_PADRAO`; valor antigo inválido gravado no banco → `assunto()` cai no padrão. |

Vetor (conferido em 18/11/2026 rodando o código real):

```
mensagem  : "When I grow up, I want to be a watermelon"
UA public : BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4
auth      : BTBZMqHH6r4Tts7J_aSIgg
salt      : DGv6ra1nlYgDCS1FRnbzlw
AS d      : yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw
AS public : BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8
esperado  : DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN
```

(`encrypt($payload, $uaPublic, $auth, $salt, ['d' => ..., 'public' => ...])` com tudo decodificado por `WebPush::b64uDecode`.)

### `PushNotificationTest.php` — A2 (linha de teste do PDF: "o botão faz chegar uma notificação em um Android e em um iPhone; tocar nela abre o sistema")

Preparação comum: classe `F02FakeWebPush extends WebPush` (grava `sent[]` com `user_subscription`, `payload`, `urgency`, `ttl`; `fail`, `status`, `gone` configuráveis), ligada com `app()->instance(WebPush::class, $fake)`; auxiliar `f02PushAssinar($userId, $userAgent)` cria `PushSubscription` com UA de Android/Chrome, iPhone instalado (sem `Safari/`) e iPad; auxiliar `f02PushChaves()` grava par de chaves em `WorkshopSettings`.

| Teste | Cobre | Como |
|---|---|---|
| Sem chaves: o botão avisa e não envia | A2.5 / roteiro 8 | piloto ligado, usuário com aparelho, sem chaves → POST `admin.workshop.push.test` → `resultado = sem_chaves`, `ok = false`, `sent` vazio. |
| Sem aparelho cadastrado | A2.5 | chaves ok, sem linhas → `sem_aparelho`; ainda: linhas de **outro** usuário não contam. |
| Enviado a todos os aparelhos do próprio usuário (Android e iPhone) e só dele | A2.5/A2.6 / roteiro 3 e 8 | duas inscrições do usuário + uma de outro → `sent` com 2; `resultado = enviado`; `aparelhos[*].rotulo` = "Android · Chrome" e "iPhone (app instalado)"; `urgency = high`; `ttl = 120`; `payload.url = /admin/workshop/alertas` (o sistema); `payload.tokens[0]` começa com `teste-`; `token` do JSON igual. |
| Falha do serviço de notificações | A2.5 | `fail = true` (503) → `resultado = falhou`, aparelho com `status = falhou`, `detalhe = HTTP 503`; inscrição **não** é apagada. |
| Aparelho morto (410) é removido e informado | A2.5 | `gone = true` → `status = removido`, linha apagada. |
| Dica de chaves para 400/401/403 | A2.5 | `status = 403` → `dica` com o texto `teste.dica_chaves`. |
| Confirmação de chegada e de toque | A2.6 | após o POST, `POST route('workshop.push.ack', [$token, 'entregue'])` (sem sessão, como o SW) → 200; `GET admin.workshop.push.state?token=` → `teste.entregue_em` preenchido, `aberto_em` nulo; depois `aberto`; token inventado (`teste-xxx`) → 404; token de **outro usuário** → `teste = null`; token de alerta continua indo para `AlertService` (ack de `HttpFlowTest.php:281-288` segue passando). |
| O payload do teste não leva valores nem crédito | regra 6 | `json_encode($sent)` sem `R$`, `crédito`, números de OS. |
| Qualquer perfil abre Minha Conta com o cartão e usa o botão, inclusive Técnico sem valores | A2.5 / roteiro 6 e 8 | piloto ligado; Técnico (`['tecnico']`); `GET admin.user.account.edit` → 200 com `data-vm-push="testar"`; `POST` do teste → 200; o **parcial** renderizado (`view('service_order::components.workshop-push-perfil')->render()`) sem `R$`. |
| Gate: piloto desligado e sem chaves → nada muda | regra 2 | `GET admin.user.account.edit` sem `vm-push-perfil`; cabeçalho sem `workshop.webmanifest`; páginas `admin.tecnico.index` e `admin.mentoria.painel.celular` sem `workshop-push.js`. |
| Piloto desligado **com** chaves geradas → app instalável e cartão | A2.3 (A2 antes do A6) | `WorkshopSettings::set` das chaves, piloto desligado → cabeçalho traz o manifesto e o cartão aparece. |
| Telas de entrada do celular carregam o cliente | A2.4 | piloto ligado: `admin.tecnico.index`, `admin.mentoria.painel.celular` e uma página do Admin contêm `workshop-push.js`, `VmPush.iniciar` e `/workshop-sw.js`; técnico e dono celular têm o link "Minha conta". |
| Cliente e service worker servidos corretamente | A2.3/A2.4/A2.6 | `/workshop-push.js` → 200, `Content-Type` contém `javascript`, corpo com `VmPush`, `requestPermission`, `getSubscription`, `ios_instalar`, `maxTouchPoints`; `/workshop-sw.js` com `addEventListener('fetch'`, `openWindow`, `navigate`, `focus` além de `push`/`notificationclick`/`requireInteraction`; `/workshop.webmanifest` com `display = standalone`, ícones 192 e 512, `start_url = /admin`, `lang = pt-BR`; `/workshop-icone-512.png` PNG; `/workshop-icone-100.png` → 404 (já em `HttpFlowTest.php:290-294`, repetir só o novo). |
| E-mail de contato validado na tela | A2.2 | `PUT admin.workshop.settings.general` com dados mínimos válidos (copiar o payload de `HttpFlowTest.php:314-324`) e `push_vapid_subject` `oficina@exemplo.com.br` → grava `mailto:oficina@exemplo.com.br`; `abc` → `session('errors')`, valor anterior mantido; vazio → `''`. |
| Gerar chaves pela tela | A2.1 | `POST admin.workshop.settings.vapid` → par de 65/32 bytes, selo "geradas" na tela; flash `warning` se o e-mail estiver vazio; apaga inscrições existentes; com a chave privada vazia a tela mostra "ainda não geradas". |
| Lista "Quem já ativou" | A2.1 | usuário com 2 aparelhos e outro sem → a tela de configuração mostra "Ativou" e "Falta ativar". |
| Rótulo do aparelho | A2.5 | Android Chrome (`...Android 13; ...) Chrome/120... Mobile Safari/537.36`) → `Android · Chrome`; iPhone no Safari (`...iPhone OS 16_4... Version/16.4 Mobile/15E148 Safari/604.1`) → `iPhone · Safari`; iPhone instalado (`...iPhone OS 16_4... Mobile/15E148`, sem `Safari/`) → `iPhone (app instalado)`; iPad instalado → `iPad (app instalado)`; Windows Edge (`Edg/`) → `Windows · Edge`; user agent vazio → `Aparelho`. Limitação aceita: iPad em modo "site para computador" se identifica como Macintosh e vira `Mac · Safari`. |
| Comando de chaves | A2.1 | `artisan('workshop:vapid-keys')` gera; segunda vez sem `--force` falha; com `--force` troca. |

### `SmsReservaTest.php` — A3 (linha de teste do PDF: "com 'Simulado', o relatório registra o que teria sido enviado; com o provedor real, um alerta crítico chega por SMS")

Preparação: `Http::fake()` por teste; auxiliar `f02SmsUsuario($perfil, $aceite = true)` (cria usuário com `celular '(11) 98888-7777'` e `sms_aceite_em` via `forceFill`); para tempo fixo usar `$this->at('2026-11-18 13:00:00')` (fora do silêncio 20:00-07:00, que é padrão).

| Teste | Cobre | Como |
|---|---|---|
| Simulado registra o que teria sido enviado e nada sai pela rede | A3.1/A3.3 / roteiro 27 | provedor padrão; alerta nível 3 do Proprietário → `SmsLog` com `status simulado`, `provedor log`, `custo 0`, `mensagem` do modelo `ALERTA ...`; `Http::assertNothingSent()`; `GET admin.workshop.settings.sms_report` mostra nome da pessoa, telefone, mensagem, "Simulado (não enviado)" e a faixa de modo simulado. |
| Relatório: contadores, filtro e custo estimado | A3.3 | 2 simulados + 1 bloqueado por limite + 1 sem aceite → cartões corretos; `?situacao=limite` mostra só o de limite; `?mes=2026-10` não mostra os de novembro; custo estimado = simulados × `sms_custo_unitario`. |
| Custo só para quem vê valores | regra 6 | piloto ligado; usuário `['settings','settings.workshop']` sem `ver_valores_financeiros` → relatório sem "R$" nos cartões de custo e sem coluna Custo; Proprietário vê. Piloto desligado: todos veem. |
| Limite mensal bloqueia e rotula "Não enviado: limite" | A3.2/A3.4 | `sms_limite_mensal = 2`: 3 alertas → 2 `simulado` + 1 `bloqueado` com `detalhe = SmsLog::DETALHE_LIMITE`; `workshop_alerts.sms_status = bloqueado`; `AlertDelivery` canal `sms` com status `falhou`; relatório mostra "Não enviado: limite mensal atingido"; no mês seguinte (`at('2026-12-02 10:00')`) volta a enviar. |
| Sem celular ou sem aceite não consome limite | A3.4 | usuário sem aceite → `bloqueado`/`DETALHE_SEM_CONTATO`, `monthlyCount()` não muda; rótulo "sem celular ou sem aceite". |
| Provedor real (Twilio): alerta crítico chega por SMS | A3.5 / roteiro 48 e 72 | `Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM0001'], 201)])`; `enablePilot(['sms_provedor' => 'twilio', 'sms_twilio_sid' => 'ACtest123', 'sms_twilio_token' => 'token-secreto', 'sms_twilio_from' => '+15005550006', 'sms_limite_mensal' => 50, 'sms_custo_unitario' => 0.12])`; Gerente e Proprietário com celular; `StageAdvanceService::advance($os, STAGE_POS_VENDA, true, '...')` (como `AlertChannelsTest.php:208`); `Http::assertSent` com URL `https://api.twilio.com/2010-04-01/Accounts/ACtest123/Messages.json`, cabeçalho `Authorization: Basic base64('ACtest123:token-secreto')`, `To = +5511988887777`, `From = +15005550006`, `Body` começando com `ALERTA`; `SmsLog` `enviado`, `provedor twilio`, `custo 0.12`, `detalhe SM0001`; `sms_status = enviado`. O token não aparece em nenhum HTML da tela de configuração. |
| Twilio recusa (400 e 401) | A3.5 | `Http::response(['code' => 21211, 'message' => "The 'To' number is not a valid phone number."], 400)` → `falhou`, `detalhe` = `HTTP 400: The 'To' number is not a valid phone number.`; `custo 0`; não conta no limite; o alerta continua no sino. |
| Twilio fora do ar | A3.5 | `Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'))` → `falhou` com a mensagem; sem exceção para quem disparou o alerta. |
| Twilio sem credenciais | A3.2/A3.5 | provedor `twilio`, sem SID → `falhou`, `detalhe` "Twilio não configurado (SID, token e número de origem).", `Http::assertNothingSent()`. |
| Limite com Twilio não chama a API | A3.4/A3.5 | `sms_limite_mensal = 1`, dois alertas → `Http::assertSentCount(1)`. |
| `e164` | A3.5 | via requisição Twilio: celular `(55) 99999-8888` → `To = +5555999998888`; `+55 (11) 98888-7777` → `+5511988887777`; `011 98888-7777` → `+5511988887777`. |
| SMS de teste | A3.2 | sem aceite → flash `warning` e `SmsLog` `bloqueado`; simulado → flash e `SmsLog` `simulado` com `alert_id` nulo; Twilio fake → `enviado`; limite atingido → `limite`; usuário sem `settings.workshop` → `assertUnauthorized()` (padrão de `HttpFlowTest.php:344-350`). |
| Aviso ao salvar com Twilio incompleto | A3.2 | `PUT ...general` com `sms_provedor = twilio` e sem SID → `session('warning')` com "faltam"; com tudo preenchido → sem aviso; token só é regravado se digitado. |
| Tela de configuração abre com pessoa que aceitou SMS | bug do cast | usuário com `sms_aceite_em` preenchido → `GET admin.workshop.settings.edit` → 200 e "desde 18/11/2026" (regressão do `workshop.blade.php:197`). |
| Silêncio segura o SMS não crítico (roteiro 60) | A3.x | silêncio 20:00-07:00: alerta nível 3 às 21:00 → nenhum `SmsLog`, `sms_status` nulo; `flush(at('2026-11-19 07:10'))` → 1 SMS; novo `flush` não duplica; alerta resolvido de madrugada **não** gera SMS; **crítico** às 21:00 → SMS na hora. |
| Escalonamento do nível 2 espera o silêncio e conta do envio do push | A3.x / roteiro 10 | nível 2 criado às 21:00 (push `pendente`) → `flush` às 21:30 sem SMS; `flush` às 07:00 libera o push (sem SMS no mesmo minuto); `flush` às 07:09 sem SMS; às 07:10 SMS. Push falho/sem aparelho fora do silêncio continua indo direto (casos já cobertos em `AlertChannelsTest.php:153-182`). |
| SMS e push não levam valores nem crédito | regra 6 | OS com `grand_total` e `cor_credito`; texto do `SmsLog` sem `R$`, `54321`, `amarelo`. |

### Testes existentes que precisam continuar passando (não editar)

`HttpFlowTest.php:246-262` (sino e manifesto só com piloto; `/workshop-sw.js` presente), `:264-303` (inscrição, ack, manifesto, ícones, SW), `:305-342` (configuração); `AlertChannelsTest.php` inteiro (inclusive `:222-248`, com `bloqueado` nos dois casos); `MentoriaPanelTest.php:55,76-79` (manifesto do dono); `TecnicoValoresTest.php:102-126` (JSON de Minhas tarefas sem valores; esta frente não o altera) e `EntradaELgpdTest.php:23` (técnico entra em `admin.tecnico.index`).

### Comandos de validação

```powershell
$env:PATH = "C:\Users\Usuario\dev-tools\php83;" + $env:PATH
$env:OPENSSL_CONF = 'C:\Users\Usuario\dev-tools\php83\extras\ssl\openssl.cnf'
$env:DB_DATABASE = 'krayin_test'
Set-Location "d:\volmaster crm\volmaster crm"
php artisan test --filter=WebPushProtocol
php artisan test --filter=PushNotification
php artisan test --filter=SmsReserva
php artisan test --filter=AlertChannels
php artisan test --filter=HttpFlow
php artisan test --filter=Workshop
php vendor/bin/pint <arquivos alterados>
```

---

## Riscos e interações com outras frentes

### Arquivos compartilhados (quem mais deve editar)

| Arquivo | Região desta frente | Outras frentes prováveis |
|---|---|---|
| `SO/Config/acl.php` | lista de rotas de `settings.workshop` (`:110-119`) | F04, F05 (chaves e mapa rota→chave: vale a **última** chave que cita a rota) |
| `SO/Routes/web.php` | depois de `:130` (push) e dentro de `:133-142` (sms-teste) | todas |
| `SO/Routes/public.php` | depois de `:25` | F01 (404) |
| `SO/Resources/views/settings/workshop.blade.php` | `:8`, `:61`, `:63-66`, `:69-89`, `:230-239` | F03 (chave do piloto `:23-34`), F04 (papéis `:163-204`) |
| `SO/Http/Controllers/WorkshopSettingsController.php` | `edit`, `updateGeneral` (só `push_vapid_subject`, `sms_*`), `generateVapid`, `smsReport`, `testSms` | F03 (condições para ligar o piloto em `updateGeneral`), F04 (`updateRoles`) |
| `SO/Services/AlertDispatcher.php` | `dispatch`, `flush`, `escalateLevelTwoToSms` | F03 (modo de teste), F09 (alertas críticos de saída) |
| `SO/Providers/ServiceOrderServiceProvider.php` | listener novo depois de `:63` | todas |
| `SO/Resources/lang/{pt_BR,en}/app.php` | seções novas `notificacao` e `sms` no fim | todas |
| `SO/Resources/views/tecnico/index.blade.php` | `:47`, `:280` | F05 (menu do Técnico: Minhas tarefas e Meu bônus) |
| `SO/Resources/views/mentoria/painel-celular.blade.php` | `:40`, `:209` | F05 (Painel do Dono celular) |
| `packages/Webkul/User/src/Models/User.php` (núcleo) | `$casts` | F04 (perfis/relacionamentos do usuário) |

### Interfaces que esta frente oferece

- `WebPush::configurado()` e `WebPush::habilitado()` — F03 pode usar `configurado()` como **aviso** (não bloqueio, o PDF não lista chaves entre as condições do piloto) na checklist "o que falta para ligar".
- `SmsManager::monthlyCount()`, `resumoDoMes()`, `sendTest()`; constantes `SmsLog::DETALHE_*`.
- Nomes de rota e chaves de lang listados acima.

### Interfaces que esta frente consome

- **F00 (oficinas):** cada oficina tem chaves VAPID próprias (geradas pela tela no subdomínio dela); a oficina nova deve nascer com `sms_provedor = log` (padrão do código) e com as migrations `2026_11_16_100300` e `2026_11_16_100400` aplicadas. `workshop:vapid-keys` não atua em oficinas: o caminho é a tela (ou uma opção `--tenant=`, fora do escopo).
- **F01 (correções):** o e-mail real de suporte da Volmaster. `WebPush::ASSUNTO_PADRAO` (`mailto:contato@volmastercrm.com`) deve ser o mesmo contato (ou vir da mesma configuração) quando F01 definir.
- **F03 (piloto e modo de teste ÷10):** a espera do SMS do nível 2 (`sms_nao_aberto_minutos`, 10) e a janela de agrupamento (`agrupar_alertas_minutos`, 5) **não** são prazos de barreira; recomendo não dividi-las automaticamente (ver Decisões).
- **F04 (perfis):** manter `users.celular` e `users.sms_aceite_em` com o mesmo significado (o `SmsManager` os lê), e o formulário "Papéis e celulares" (campos `celular[id]` e `sms_aceite[id]`) ou um substituto equivalente. `RecipientResolver` continua filtrando `status = 1`, então usuário desativado não recebe push nem SMS.
- **F05 (menu e permissões):** as rotas `admin.workshop.push.*`, `admin.workshop.alerts.*`, `admin.user.account.*` e as públicas `workshop*` precisam ficar abertas a **todo perfil logado** (Técnico, Copa e Mentor também recebem alerta e usam o teste). Se F05 trocar o cabeçalho do Técnico ou do Painel do Dono celular, manter o link "Minha conta" e o `@include` do boot.

### Riscos

1. **iOS só com app instalado e iOS ≥ 16.4.** iPhone mais antigo não recebe push, só SMS/sino. A permissão só pode ser pedida por toque direto, dentro do app instalado (por isso o botão "Ativar" e a ordem das chamadas em `ativar()`). O app da tela inicial tem sessão própria no iPhone: a pessoa entra de novo.
2. **HTTP 201 do serviço de push = aceito, não entregue.** Por isso o teste tem a confirmação de chegada/toque pelo service worker. Sem ela, "Enviado" pode enganar (Modo Foco, bateria, permissão revogada).
3. **Trocar as chaves quebra os aparelhos já inscritos.** O servidor apaga as inscrições; o cliente novo desinscreve e reinscreve sozinho quando a chave do servidor muda (hoje o `subscribe()` falha em silêncio com `InvalidStateError`). Orientar: gerar as chaves **uma vez** por oficina, antes de instalar nos celulares.
4. **Cada oficina é um subdomínio com certificado próprio.** Service worker e push exigem HTTPS; o certificado precisa cobrir o subdomínio da oficina de teste. Nada a fazer no código; conferir no servidor.
5. **Envios são síncronos.** `AlertDispatcher` chama push (timeout 10 s) e SMS (15 s) dentro da requisição de quem forçou o avanço ou do `workshop:tick`. Serviço lento atrasa a tela. Fora do escopo; se virar problema, reduzir `timeout`/`connectTimeout` em `WebPush::send` e `TwilioSmsGateway`.
6. **Twilio nunca foi validado com conta real.** Pontos a conferir na primeira conta: número de origem com suporte a envio para o Brasil; `From` do tipo Messaging Service (`MG...`) exigiria `MessagingServiceSid` em vez de `From`; "enviado" = aceito pela Twilio (sem retorno de entrega; não há webhook de status).
7. **Mudança de comportamento no silêncio (A3.x).** O Proprietário deixa de receber SMS de **nível 3** durante o silêncio (20:00-07:00 por padrão); recebe quando o silêncio acaba. Crítico não muda. Quem quiser SMS à noite desliga o silêncio ou marca a barreira como crítica.
8. **Correção do `e164`** muda o número de quem tem DDD 55 (o novo está certo; o antigo estava errado).
9. **Núcleo editado:** `User::$casts` (uma linha, inerte sem a coluna). Alternativa sem núcleo, caso se queira evitar: `User::retrieved(fn ($u) => $u->mergeCasts(['sms_aceite_em' => 'datetime']))` no provider.
10. **Vue reconstrói o DOM da página** (`#app`): por isso o cliente é um `.js` externo, consulta `data-vm-push` na hora, delega eventos no `document` e o boot do cartão vai em `@push('scripts')`.
11. **GD no servidor.** Os ícones do app dependem da extensão GD (`PwaController::icon`); sem ela o manifesto fica sem ícone e o app não instala. Conferir abrindo `/workshop-icone-512.png`.
12. **Aparelho compartilhado:** a inscrição fica com quem entrou por último naquele aparelho, mas não é desfeita ao sair. Quem sai continua recebendo até o próximo login ali.
13. **LGPD:** `sms_logs` guarda telefone (já era assim); o relatório só aparece para quem tem `settings.workshop`. `push_subscriptions` guarda o `user_agent`.
14. **`fetch` vazio no service worker** adiciona uma pequena latência de despertar do SW em cada navegação. Se for notado no Painel do Dono no 4G (roteiro 70), remover a linha: o menu "Instalar app" do Chrome continua funcionando.
15. **Silêncio e "Resolvido":** `dispatchResolved` manda o push "Resolvido" mesmo no silêncio. Existe hoje; fora do escopo (barreiras só contam em horário comercial, então é raro).

---

## Decisões em aberto

(Somente o que depende do patrão ou do usuário.)

1. **Qual provedor de SMS será contratado?** O código só tem Twilio. **Recomendação:** configurar a Twilio já implementada se o patrão aceitar; se preferir um provedor brasileiro (Zenvia, Comtele ou outro), a equipe escreve um gateway a mais (`SmsGateway`, cerca de 40 linhas + testes com `Http::fake`) e o `sms_provedor` ganha a opção. Decidir antes do piloto numa oficina real.
2. **Limite mensal e custo por SMS das oficinas reais.** Os padrões do sistema (200 SMS e R$ 0,12) são só ponto de partida. **Recomendação:** manter na oficina de teste; nas reais, usar o preço da tabela do provedor contratado e um limite com folga sobre o uso das duas primeiras semanas (o relatório mostra o consumo).
3. **E-mail de contato das notificações (VAPID).** **Recomendação:** o e-mail de suporte real da Volmaster (o mesmo que F01 colocar na página 404). Cada oficina pode trocar.
4. **Alerta crítico pode furar o limite mensal de SMS?** O PDF manda bloquear ao atingir o limite, então a especificação **bloqueia também os críticos** (continuam no sino e no push). **Recomendação:** manter assim; se o patrão preferir uma reserva para críticos, é um ajuste pequeno em `SmsManager::deliver` (ignorar o limite quando o alerta é crítico, ou reservar N SMS por mês).
5. **No modo de teste ÷10 (F03), a espera de 10 min do SMS de nível 2 e a janela de 5 min de agrupamento também devem ser divididas?** **Recomendação:** não dividir automaticamente (não são prazos de barreira); quem precisar testar mais rápido ajusta os dois campos na própria tela de configuração.
6. **O silêncio deve segurar o SMS de nível 3 do Proprietário?** O PDF diz que "o comum espera; o crítico toca na hora" (roteiro 60) e a tela promete "no silêncio só os críticos tocam"; esta especificação segura o SMS não crítico. **Recomendação:** manter (cumpre a linha 60). Se o patrão quiser ser acordado por nível 3, a saída é desligar o silêncio daquela oficina ou marcar a barreira como crítica.

---

## Passo a passo para o usuário

### 1. Depois do deploy (nenhuma migration nova nesta frente)

1. Subir o zip como de costume. **Não há migration nova nesta frente**: o `public/migrate-safe.php` só roda `migrate --force` no banco principal (não limpa cache nem mexe nas oficinas), então não precisa rodar por causa desta entrega. Se existir `bootstrap/cache/config.php` ou `bootstrap/cache/routes-v7.php` no servidor, apagar (são caches e escondem rotas e configurações novas; no projeto local só existem `packages.php` e `services.php`).
2. Conferir que o cron de 1 minuto (`schedule:run`) continua ativo (é ele que escalona alertas e SMS).
3. Abrir `https://<oficina>.volmastercrm.com/workshop-icone-512.png`: deve aparecer um quadrado azul com a logo. Se der erro, a extensão GD do PHP está desligada (chamar o suporte da hospedagem).

### 2. Chaves das notificações e e-mail de contato (A2) — uma vez por oficina

1. Entrar como Proprietário na oficina (a de teste primeiro) > **Configurações > Indicadores e alertas**.
2. No bloco **Notificações**, preencher **E-mail de contato das notificações** com um e-mail real (o de suporte da Volmaster) e **Salvar configurações**. Digitar só o e-mail; o sistema coloca o "mailto:".
3. Rolar até **Chaves das notificações no celular** e tocar **Gerar chaves**. Deve aparecer a mensagem verde e o selo "Chaves de notificação geradas". **Não gerar de novo** depois de instalar nos celulares (desfaz as inscrições).

### 3. Preparar os celulares (roteiro, linha 3)

- **Android (Chrome):** abrir o endereço da oficina, entrar, tocar nos três pontinhos > **Instalar app**; abrir pelo ícone novo.
- **iPhone (iOS 16.4 ou superior):** abrir o endereço no **Safari**, entrar, tocar em **Compartilhar** > **Adicionar à Tela de Início**; abrir **pelo ícone novo** (não pelo Safari) e entrar de novo. Na primeira vez no iPhone o sistema mostra um aviso com esses passos; tocar **Entendi** (não volta mais).
- Em cada celular: **Minha Conta** (menu do avatar; o técnico usa o link "Minha conta" no topo de Minhas tarefas) > **Notificações no celular** > **Ativar neste aparelho** > **Permitir**.

### 4. Testar a notificação (roteiro, linha 8)

1. Em **Minha Conta > Notificações no celular**, tocar **Testar notificação**.
2. Esperado: a tela mostra "Notificação enviada para N aparelho(s)" e, em instantes, **"Chegou ao aparelho às hh:mm:ss"**; a notificação "Teste de notificação" aparece no celular. Tocar nela deve abrir o sistema, e a tela mostra **"Você tocou na notificação..."**.
3. Se não chegar: ver a linha de cada aparelho. "Sem aparelho" = tocar em Ativar; "chaves" = tocar em **Reativar neste aparelho**; "bloqueadas" = liberar em Ajustes > Notificações (iPhone) ou nas configurações do site (Android); celular em "Não perturbe/Foco" ou sem internet também impedem.
4. Repetir no Android e no iPhone e registrar o resultado.

### 5. SMS de reserva (A3)

1. **Oficina de teste:** manter o provedor em **Simulado** até o fim do roteiro. Em **Configurações > Indicadores e alertas > SMS de reserva** conferir limite e custo; em **Papéis e celulares** cadastrar o celular e marcar **aceita SMS** das pessoas que devem receber; salvar.
2. Para ver o que "teria sido enviado": **Relatório de SMS** (botão no topo da tela de configuração). Cada linha mostra pessoa, telefone, mensagem e a situação. "Não enviado: limite mensal atingido" aparece quando o limite acaba. Para ver isso sem esperar alertas: baixe o limite para 2, salve e toque 3 vezes em **Enviar SMS de teste para o meu celular**; a terceira linha sai como "Não enviado: limite mensal atingido". Depois volte o limite ao valor certo.
3. **Antes de liberar às oficinas reais:** contratar o provedor (ver Decisões), escolher **Twilio** (ou o que a equipe liberar), preencher **Account SID, Auth token e número de origem**, definir **limite mensal** e **custo por SMS** com os valores do contrato e salvar. Se faltar algum campo, o sistema avisa.
4. Tocar **Enviar SMS de teste para o meu celular**. Deve chegar o texto "Teste Volmaster: o SMS de reserva da oficina esta funcionando." Se aparecer "Não enviado", ler o motivo (celular ou aceite faltando, limite, ou recusa do provedor).
5. **Roteiro, linha 72** (alerta crítico por SMS): com o provedor real ligado, desligar o Wi-Fi e os dados móveis do celular de quem vai receber (**não** usar o modo avião: o SMS precisa do sinal da operadora, só não precisa de internet) e forçar um avanço de etapa com justificativa; o Gerente e o Proprietário devem receber o SMS. Conferir a linha "Enviado" no Relatório de SMS. "Enviado" significa que o provedor aceitou; a chegada ao celular se confirma olhando o aparelho.
6. Voltar o provedor para **Simulado** na oficina de teste quando terminar, se o roteiro ainda não acabou.

### Avisos para a documentação

- O botão "Testar notificação" fica em **Minha Conta**; aparece só quando o piloto está ligado **ou** as chaves já foram geradas.
- O aplicativo instalável abre em `/admin` e cada cargo cai na própria tela; o app do dono continua abrindo no Painel do Dono celular.
- A dispensa do aviso do iPhone é por pessoa **e por navegador**; limpar os dados do Safari faz o aviso voltar uma vez.
- SMS fora do horário de silêncio: nível 3 e escalonamento do nível 2 esperam o fim do silêncio; crítico toca na hora.
- O limite mensal conta enviados e simulados do mês corrente.
- Chaves de notificação são por oficina; gerar de novo exige que cada pessoa abra o app para reativar.
