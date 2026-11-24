# Especificação — F06-credito

Frente: **F06-credito — Cor de crédito do cliente (item B1 do PDF)**. Documento de projeto (somente leitura): nada do projeto foi alterado. Tudo que está abaixo é o que o implementador deve fazer. Caminhos relativos a `d:\volmaster crm\volmaster crm`; "pacote" = `packages/Webkul/ServiceOrder/src`.

## Desenho em uma página

1. **Dois lugares guardam a cor, com papéis diferentes.**
   - **Cliente**: tabela nova `cliente_credito_registros`, histórico só de inserção. Cada análise é uma linha: cor, data/hora, usuário, observação, cor anterior, sugestão do sistema (cor + motivo), justificativa e a OS de onde veio. **Cor vigente do cliente = última linha.** Fica fora da tabela `persons` e fora dos atributos do Krayin de propósito: `Person` usa `LogsActivity` + atributos EAV, e uma mudança de cor apareceria na linha do tempo de atividades do cliente para qualquer perfil.
   - **OS**: continua com `quotes.cor_credito` + `cor_credito_em` (a "foto" da cor no momento da análise desta OS). É o que o bloqueio do Cadastro (`StageGate`) e o CON-03 já leem; **nada neles muda**. Única mudança: `cor_credito_em` passa a guardar a PRIMEIRA vez que a OS recebeu cor (CON-03 mede "antes da liberação"; alterar a cor depois não pode penalizar o consultor).
   - Registro feito pela OS grava as duas coisas (linha do cliente + cor da OS) e o evento `cor_credito_registrada` (fecha a barreira FIN-01). Registro feito pela página do cliente grava só a linha do cliente.
2. **Cor efetiva do cliente** (a que vale para bloquear prazo, calculada na hora, sem job): é a cor vigente; mas se a **sugestão do sistema ficou pior** que a cor registrada **e é diferente da sugestão que existia quando o Financeiro decidiu**, vale a sugestão (o cliente "fica Vermelho" sozinho quando um título passa de 15 dias). Decisão justificada do Financeiro contra a sugestão vale enquanto a sugestão não mudar. Cliente sem nenhuma análise: vale a sugestão se for amarelo/vermelho (verde vira "sem cor").
3. **"Títulos" = `service_order_boletos`** (boletos manuais e cobranças Asaas, mesma tabela). `Lancamento` (`financeiro_lancamentos`) é conta a PAGAR e não entra. Detalhes na seção do `CreditoSugestao`.
4. **Pedido de análise** = evento novo `analise_credito_pedida` (gravado sozinho ao abrir a OS pelo Novo Cadastro / Nova OS com o piloto ligado, e por botão do consultor na OS). Avisa o Financeiro e inicia a barreira FIN-01 (30 min), inclusive em OS sem veículo.
5. **Prazo × alçada**: condição "a prazo" usa a cor efetiva; amarelo vai ao Gerente (como hoje); **vermelho vai DIRETO à fila do Proprietário, com 24 h** (roteiro linha 31). Conferido também ao gerar boleto/cobrança no Faturamento.
6. **Sigilo**: permissão nova `credito_cliente` (Consultor, Financeiro, Gerente, Proprietário), checada no servidor, sem JSON, sem tela pública, sem impressão.

## O que já existe

Mapa do código atual relacionado ao B1 (o patrão achou "parcial"; abaixo está exatamente o que há e o que falta).

**Cor na OS**
- Colunas `quotes.cor_credito` (string 10) e `quotes.cor_credito_em` (datetime): `Database/Migrations/2026_11_16_100100_add_workflow_columns_to_quotes_table.php:37-38`. Cast em `Models/ServiceOrder.php:107`; constante `ServiceOrder::CORES_CREDITO` (`ServiceOrder.php:129`).
- Registro: rota `admin.service_orders.workflow.cor_credito` (`Routes/web.php:99`) → `WorkflowController::saveCorCredito` (`Http/Controllers/WorkflowController.php:56-65`, autoriza com `ValoresFinanceiros::authorize('cor de crédito')`, ou seja, **qualquer perfil que vê valores registra**) → `WorkflowService::setCorCredito` (`Services/WorkflowService.php:111-124`: grava cor + `cor_credito_em = now()`, evento só se a cor mudou). Sem usuário, observação, justificativa nem histórico: o segundo registro sobrescreve o primeiro.
- Tela: cartão "2. Cor de crédito" em `Resources/views/partials/workflow.blade.php:103-126` (só `@if ($canSeeValues)`, e esse parcial só entra com o piloto ligado: `view.blade.php:324-326`).
- Evento `cor_credito_registrada` (`Models/ServiceOrderEvent.php:31`), escondido de quem não vê valores na linha do tempo (`workflow.blade.php:19-21`).

**Barreira / indicadores**
- Barreira `cor_credito`: `Models/BarrierRule.php:67-79` (início `chegada_veiculo`, fim `cor_credito_registrada`, 30 min, nível 1 `financeiro`, nível 2 +15 min `gerente`). O motor abre/fecha em `Services/BarrierEngine.php:38-72` e deduplica por OS+código+referência (`:124-132`). **`chegada_veiculo` só é gravado em `CadastroController.php:227-230` e só quando há veículo**: OS criada pela tela "Nova OS" (`ServiceOrderController::store`, `:154-179`) ou serviço só de software nunca abre a barreira.
- CON-03: `Services/Indicators/IndicatorCalculator.php:84` (`cor_credito_em <= execucao_liberada`). FIN-01: `:151` (`barrier('cor_credito')`). Catálogo: `Services/Indicators/IndicatorCatalog.php:44` e `:111`. Fila do Financeiro: `Services/Rotina/RotinaService.php:209-211` (OS sem cor, limite 30 min). Relatório diário: `Services/Financeiro/FinanceiroService.php:319-321` ("exceções de crédito" = eventos amarelo/vermelho + pedidos de alçada `condicao_pagamento` do dia; **depende de `dados_json['cor']` no evento**).

**Bloqueios**
- Sair do Cadastro sem cor: `Services/StageGate.php:153-155` dentro de `cadastroParaOs` (`:112-158`); texto fixo "Cor de crédito (verde, amarelo ou vermelho)" que `tests/Feature/Workshop/StageGateTest.php:82-108` confere palavra por palavra. **Já atende o bloqueio do PDF; não mexer.**
- Prazo: tema de alçada `condicao_pagamento` (`Services/Alcada/AlcadaCatalog.php:32`, tipo `cor`: 0 verde, 1 amarelo, 2 vermelho) e `ServiceOrderStageController::checkAlcadas` (`Http/Controllers/ServiceOrderStageController.php:194-227`, parte do prazo em `:214-226`): só quando a condição muda; regex "é a prazo" (`dias|prazo|faturad|boleto|parcel|duplicata` e não `vista|pix|dinheiro|cartão|débito`); **usa a cor da OS** (`$serviceOrder->cor_credito`). Em `AlcadaService::open` (`Services/Alcada/AlcadaService.php:161-188`) o pedido do consultor nasce `pendente` na fila do **gerente**, com `nivel_necessario = proprietario`, e só chega ao dono (24 h) quando o gerente encaminha (`:166`, `:193-221`). O roteiro (linha 31) e o PDF dizem que vai ao Proprietário com prazo de 24 h.
- Não confere nada no Faturamento (`storeBoleto` `:489-509`, `storeBoletoViaAsaas` `:516-551`).

**Travas**
- `Services/Bonus/BonusCatalog.php:75` (`prazo_cliente_vermelho`), `:77` (`credito_comentado`), `:100` (`prazo_vermelho_sem_dono`), `:105` (`uso_indevido_credito`): existem como travas **manuais**; `TravaService::detectar` (`Services/Bonus/TravaService.php:100-110`) não detecta nenhuma delas.

**Títulos (fonte da sugestão automática)**
- `service_order_boletos` (`Models/ServiceOrderBoleto.php`; criada em `2026_09_01_000004_create_service_order_boletos_table.php`, campos Asaas em `2026_09_02_110000_add_asaas_fields_to_service_order_boletos_table.php`): `quote_id`, `valor`, `vencimento` (date, pode ser nulo), `status` Pendente/Pago/Cancelado. Cobrança Asaas entra na mesma tabela (`Services/AsaasClient.php:61-70`; webhook `Http/Controllers/AsaasWebhookController.php:41-48`; baixa manual `ServiceOrderStageController.php:553-568`).
- **Não existe `pago_em`**: o código inteiro usa `updated_at` do título pago como data do pagamento (`IndicatorCalculator.php:492` em `adimplencia`, `:1315` em `reguaCumprida`). Atraso em aberto já é definido em `FinanceiroService::reguaPendente` (`:228-247`: `status = Pendente`, `vencimento < hoje`, dias = `diffInDays`). `DreService::rankingClientes` (`Services/Financeiro/DreService.php:397-417`) já calcula inadimplência por cliente a partir dos mesmos títulos.
- `Models/Lancamento.php` é **conta a pagar** ("Conta a pagar" no docblock; usada em DRE/caixa): não serve como título a receber.

**O que NÃO vaza hoje (manter)**
- Link público: `Http/Controllers/PublicTrackingController.php:17-29` não carrega cor; teste `tests/Feature/Workshop/EntradaELgpdTest.php:36-49`. Impressão: `Resources/views/print.blade.php` não tem cor (teste de valores em `TecnicoValoresTest.php:81-100`). Técnico: `TecnicoTasks` é lista branca (`TecnicoValoresTest.php:102-126`). Lista de OS: `DataGrids/ServiceOrderDataGrid.php:42-53` seleciona colunas explícitas. Alertas: `Services/AlertService.php:20` ("textos nunca levam valores nem crédito").

**Ganchos que serão reaproveitados**
- Página do cliente (Krayin): `packages/Webkul/Admin/src/Resources/views/contacts/persons/view.blade.php:92` dispara `view_render_event('admin.contact.persons.view.right.before', ['person' => $person])`, uma vez por página. O pacote Portal já usa esse padrão sem editar o core (`packages/Webkul/Portal/src/Providers/PortalServiceProvider.php:69-74`). **Não usar** `admin.contacts.persons.view.attributes.before`: ele é disparado duas vezes na mesma página (`persons/view/attributes.blade.php:1` e `:41`).
- `ViewRenderEventManager` entrega os parâmetros do evento (`$person`) ao template (`packages/Webkul/Core/src/ViewRenderEventManager.php:75-88`).
- Padrão "tolerar coluna/tabela ausente": `Services/PrevisaoOs::disponivel()`, `Services/CodigoSequencial::disponivel()`.

**Lacunas (o que falta de fato)**: campo/histórico no cliente; permissão própria; pedido do consultor; registro com usuário/observação/justificativa; sugestão automática; prazo vermelho direto ao dono com 24 h usando a cor do cliente; conferência no Faturamento; alimentação automática das travas; parâmetros da oficina (15 e 90 dias); proteção contra vazamento do histórico novo.

## Requisitos do PDF → como serão atendidos

**B1.1 — Campo no cadastro do cliente (Verde/Amarelo/Vermelho, com data, usuário e observação; só Consultor, Financeiro, Gerente e Proprietário).** Situação: **FALTA** (só existe cor na OS, sem usuário nem observação, visível a quem vê valores).
- Tabela `cliente_credito_registros` + modelo `ClienteCreditoRegistro` (só inserção) + serviço `CreditoCliente`.
- Cartão "Cor de crédito do cliente" na página do cliente (`persons/view`) via `Event::listen('admin.contact.persons.view.right.before')`, com cor vigente, data, usuário, observação, sugestão, últimos 5 registros e link para o histórico completo (`admin.credito.historico`). Formulário de registro para quem tem `credito_cliente.registrar`.
- Visibilidade: chaves ACL novas `credito_cliente` (ver e pedir análise) e `credito_cliente.registrar`; `CreditoCliente::podeVer()` exige a chave **e** `ValoresFinanceiros::can()` (quem não vê valores nunca vê crédito, mesmo que a função receba a chave por engano). O cartão da OS (que hoje usa `$canSeeValues`) passa a usar `podeVer()`.

**B1.2 — Na abertura da OS o consultor pede a análise; o Financeiro registra a cor (30 min, FIN-01).** Situação: **PARCIAL** (barreira de 30 min, registro pelo Financeiro e FIN-01 existem; falta o pedido do consultor, o aviso e a restrição de quem registra).
- Evento `analise_credito_pedida`. `CreditoCliente::pedirAnalise(ServiceOrder $os, bool $automatico = false)`: grava o evento, avisa o Financeiro (`AlertService::raise`, código `credito_analise_pedida`, sem cor nem valores, `unicoPorDia`).
- Chamado sozinho (piloto ligado) em `CadastroController::store` e `ServiceOrderController::store` logo após criar a OS ("na abertura da OS"), e manualmente pelo botão "Pedir análise de crédito ao Financeiro" no cartão da OS (rota `admin.service_orders.workflow.credito_pedir`). **Não** colocar no `ServiceOrderObserver::created` (quebra `AllBarriersTest.php:104-116`, que cria a OS antes de `emit(... 'chegada_veiculo', '08:00')` e depende de ser o primeiro evento a abrir a barreira).
- Barreira `cor_credito`: `evento_inicio` passa a `chegada_veiculo,analise_credito_pedida` (DEFAULTS + migration para linhas existentes). Quem abrir primeiro vale (o motor ignora a segunda abertura). Cobre OS sem veículo e OS criada pela "Nova OS".
- Registro só por quem tem `credito_cliente.registrar` (Financeiro, e Gerente como cobertura). A rota antiga continua; o controlador troca `ValoresFinanceiros::authorize` por `CreditoCliente::authorize('registrar')` (403).

**B1.3 — Sugestão automática (vencido há mais de 15 dias = Vermelho; atraso nos últimos 90 dias = Amarelo); Financeiro altera com justificativa.** Situação: **FALTA**.
- `CreditoSugestao::para($personId)` sobre `service_order_boletos` (ver seção própria). Limites **editáveis na Ficha** (`credito_vermelho_dias` = 15, `credito_amarelo_dias` = 90): "regra vem da configuração da oficina".
- A sugestão é recalculada no servidor no momento de registrar (nunca confiar no formulário) e gravada na linha (`sugestao_cor`, `sugestao_motivo`).
- Justificativa obrigatória quando a cor escolhida **difere da sugestão** ou **difere da cor anterior** (da OS, se já tem; senão a vigente do cliente). Texto livre até 500 caracteres, guardado na linha. Seguir a sugestão sem mudar a cor anterior não exige justificativa. Observação (opcional) é um campo separado.

**B1.4 — Bloqueios com o piloto ligado.**
- "A OS não sai do Cadastro sem cor registrada": **EXISTE** (`StageGate.php:153-155`). Não alterar; cobrir com teste novo (roteiro 12).
- "Condição a prazo para cliente Vermelho vai para a fila de alçadas do Proprietário": **PARCIAL**. Passa a usar a **cor efetiva do cliente** (não só a foto da OS), vai **direto ao dono com `prazo_dono_em` = pedido + 24 h** (`AlcadaCatalog::DIRETO_AO_DONO = ['condicao_pagamento']` + 1 linha em `AlcadaService::open`), e é conferida de novo ao gerar boleto/cobrança (`storeBoleto`, `storeBoletoViaAsaas`) para o caso de a cor ter piorado depois da negociação ou da condição ter sido gravada antes do pedido. Amarelo continua indo ao Gerente (alçada nível 1).
- Com o piloto desligado `AlcadaService::authorize` já retorna sem fazer nada (`AlcadaService.php:115-117`); nada novo bloqueia.

**B1.5 — A informação de crédito NUNCA aparece no link de acompanhamento, na OS impressa, para o Técnico nem em JSON.** Situação: **EXISTE para a cor da OS; o histórico novo precisa nascer protegido.**
- O histórico está em tabela própria e é renderizado só no servidor em telas com `podeVer()`; nenhuma rota nova devolve JSON; `print.blade.php`, `public/tracking.blade.php`, `TecnicoTasks` e o DataGrid não são tocados.
- Eventos de crédito (`cor_credito_registrada`, `analise_credito_pedida`) somem da linha do tempo de quem não tem `podeVer()`.
- Mensagens de alerta do pedido de análise não levam cor nem valor.
- Testes novos garantem tracking, impressão, busca de clientes (`PersonResource`), Minhas tarefas e alertas sem cor/observação/justificativa.

**B1.6 — Alimenta CON-03 e as travas do consultor e do financeiro.** Situação: **PARCIAL**.
- CON-03: continua lendo `cor_credito_em` (agora a primeira vez). FIN-01: barreira (agora iniciada também pelo pedido).
- Travas: `prazo_cliente_vermelho` (consultor) e `prazo_vermelho_sem_dono` (financeiro) passam a ser **detectadas automaticamente** em `TravaService::detectar` (OS com cor vermelha registrada, forma de pagamento / cobrança a prazo, sem pedido de alçada aprovado ou consumido, e com o tema ativo na matriz). `credito_comentado` e `uso_indevido_credito` continuam manuais, mas ganham evidência: `admin.credito.` entra no registro de acesso LGPD (`acessos_sensiveis`), com usuário, rota, método e IP.

**Teste do PDF: "cliente com boleto vencido há 20 dias aparece Vermelho; tentar faturar a prazo cria pedido na fila de alçadas."** Situação: **FALTA**; coberto por testes 1, 21 e 23 (seção de testes). Como reproduzir à mão: ver "Passo a passo".

## Mudanças por arquivo

Ordem sugerida: migrations → modelo e serviços → configurações/ACL/lang → controladores e rotas → views → ganchos nos arquivos compartilhados → testes → ajuste dos testes antigos → `pint` → filtros de teste.

### Criar

**1. `Models/ClienteCreditoRegistro.php`** (namespace `Webkul\ServiceOrder\Models`)
```php
class ClienteCreditoRegistro extends Model
{
    public const UPDATED_AT = null;               // só created_at
    protected $table = 'cliente_credito_registros';
    protected $guarded = [];
    protected $casts = ['registrado_em' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Registros de crédito não podem ser alterados; registre uma nova análise.'));
        static::deleting(fn () => throw new LogicException('Registros de crédito não podem ser apagados; registre uma nova análise.'));
    }

    public function usuario() { return $this->belongsTo(\Webkul\User\Models\User::class, 'user_id'); }
    public function getCorNomeAttribute(): string { return ServiceOrder::CORES_CREDITO[$this->cor] ?? $this->cor; }
}
```
Mesmo padrão de `ServiceOrderEvent` e `AlcadaPedido` (append-only por regra do model).

**2. `Services/Credito/CreditoSugestao.php`**
```php
public function para(int $personId, ?Carbon $hoje = null): array
```
Retorno: `['cor' => 'verde|amarelo|vermelho', 'motivo' => string, 'titulos' => list<array{id:int, os:int, vencimento:string(Y-m-d), atraso_dias:int, em_aberto:bool, valor:float}>, 'sem_titulos' => bool, 'vermelho_dias' => int, 'amarelo_dias' => int]` (no máximo 5 títulos, os mais graves primeiro). Algoritmo:
```php
$hoje = ($hoje ?? now())->copy()->startOfDay();
$limite = max(0, (int) WorkshopSettings::get('credito_vermelho_dias', 15));   // "mais de N dias"
$janela = max(1, (int) WorkshopSettings::get('credito_amarelo_dias', 90));

$titulos = ServiceOrderBoleto::query()
    ->whereIn('quote_id', DB::table('quotes')->where('person_id', $personId)->select('id'))   // sem o escopo global da OS
    ->where('status', '!=', ServiceOrderBoleto::STATUS_CANCELADO)
    ->whereNotNull('vencimento')
    ->get();

foreach ($titulos as $t) {
    $venc = Carbon::parse($t->vencimento)->startOfDay();
    $aberto = $t->status === ServiceOrderBoleto::STATUS_PENDENTE;
    // Pago: o dia da baixa = updated_at (mesma convenção de IndicatorCalculator::adimplencia).
    $fim = $aberto ? $hoje : Carbon::parse($t->updated_at)->startOfDay();
    $atraso = (int) $venc->diffInDays($fim, false);          // Carbon 3: com sinal; > 0 = atrasou

    if ($atraso <= 0) continue;                               // no prazo (ou pago em dia / antes)
    if ($aberto && $atraso > $limite)   → vermelhos[]         // vencido há MAIS de N dias e ainda em aberto
    elseif ($aberto || $venc->gte($hoje->copy()->subDays($janela))) → atrasos[]   // atraso atual, ou atraso pago dentro da janela
}
```
- Se `vermelhos` ≠ vazio → `vermelho`, motivo = lang `credito.sugestao_motivo_vermelho` do pior título (+ " E mais N." se houver outros). Senão se `atrasos` ≠ vazio → `amarelo` (motivo `..._amarelo`: quantidade, janela, título mais recente). Senão `verde` (`..._verde`; se não há nenhum título, `sugestao_sem_historico`).
- Exatamente 15 dias de atraso é **Amarelo**, 16 é Vermelho ("mais de 15"). Título em aberto vencido ontem é Amarelo. Boleto cancelado, sem vencimento e de outro cliente nunca pesam.
- Aproximação conhecida: a data do pagamento é o `updated_at` do título pago (o sistema não tem `pago_em`). Isolar essa linha em um método protegido `diaDoPagamento($titulo)` para trocar por `pago_em` no futuro.
- Não depende de usuário nem de permissão; o `valor` vai no array, mas **a view só imprime se `ValoresFinanceiros::can()`**.

**3. `Services/Credito/CreditoCliente.php`** — serviço principal.
```php
class CreditoCliente
{
    public const PERMISSAO_VER = 'credito_cliente';
    public const PERMISSAO_REGISTRAR = 'credito_cliente.registrar';
    protected const NIVEIS = ['verde' => 0, 'amarelo' => 1, 'vermelho' => 2];
    protected static array $disponivel = [];      // por NOME DO BANCO (cada oficina tem o seu)

    public function __construct(protected CreditoSugestao $sugestao) {}

    // ---- banco e permissão (estáticos, no estilo de ValoresFinanceiros) ----
    public static function disponivel(): bool;                      // Schema::hasTable('cliente_credito_registros'), memoizado em static::$disponivel[DB::connection()->getDatabaseName()]
    public static function definirDisponivel(?bool $valor): void;   // só para teste (null limpa o cache)
    public static function podeVer(?User $user = null): bool;
    public static function podeRegistrar(?User $user = null): bool;
    public static function authorize(string $acao = 'ver'): void;   // 'ver' | 'registrar'; Log::warning('Acesso negado a dados de crédito', [usuario_id, acao, url]) + abort(403)

    // ---- consulta ----
    public function nivel(?string $cor): int;                       // verde 0, amarelo 1, vermelho 2, resto 0
    public function vigente(int $personId): ?ClienteCreditoRegistro;      // última linha (null sem tabela)
    public function historico(int $personId, int $limite = 200): Collection;   // mais recentes primeiro, with('usuario:id,name')
    public function situacao(int $personId, ?ServiceOrder $os = null): array;
    public function corEfetiva(ServiceOrder $os): ?string;

    // ---- ações ----
    public function pedirAnalise(ServiceOrder $os, bool $automatico = false): void;
    public function registrar(int $personId, string $cor, ?string $observacao = null, ?string $justificativa = null, ?ServiceOrder $os = null, ?User $user = null): ?ClienteCreditoRegistro;

    // ---- prazo × alçada ----
    public function condicaoEhAPrazo(?string $condicao): bool;
    public function conferirPrazo(ServiceOrder $os, string $condicao, ?User $user = null): void;       // negociação
    public function conferirPrazoNoFaturamento(ServiceOrder $os, ?User $user = null): void;           // boleto/cobrança
    public function temPrazoAutorizado(ServiceOrder $os, int $nivel): bool;
}
```
Regras de cada método:
- `podeVer`: usuário logado (ou o informado); sem usuário/função → `false`; **`ValoresFinanceiros::can($user)` falso → `false`**; `permission_type === 'all'` → `true`; senão `(bool) $role->permissions && $user->hasPermission(PERMISSAO_VER)`. `podeRegistrar`: `podeVer($user)` e (`all` ou `hasPermission(PERMISSAO_REGISTRAR)`). Independem do piloto (tela nova, só aparece para quem tem a chave).
- `situacao`: `$sug = $this->sugestao->para($personId)`; `$registro = disponivel() ? vigente($personId) : null`; `$registrada = $registro?->cor ?? $os?->cor_credito` (OS antiga sem histórico do cliente continua valendo); `$sugestaoDaAnalise = $registro?->sugestao_cor`.
  - `$registrada === null` → `efetiva = nivel($sug['cor']) > 0 ? $sug['cor'] : null`, `origem = 'sugestao'|null`, `revisao_pendente = efetiva !== null`.
  - `nivel($sug['cor']) > nivel($registrada)` **e** `$sug['cor'] !== $sugestaoDaAnalise` → `efetiva = $sug['cor']`, `origem = 'sugestao'`, `revisao_pendente = true`.
  - senão `efetiva = $registrada`, `origem = 'registrada'`, `revisao_pendente = false`.
  - Retorna `['registro','sugestao','registrada','efetiva','origem','revisao_pendente']`.
- `registrar`:
  1. cor fora de `ServiceOrder::CORES_CREDITO` → `ValidationException(['cor_credito' => [lang msg.cor_invalida]])` (mesma chave do código antigo).
  2. `$os` informada: precisa ser do mesmo `person_id` (`msg.os_de_outro_cliente`) e não estar `Concluída`/`Cancelada` (`msg.os_encerrada`).
  3. **Sem tabela ou sem `person_id`** (banco não migrado / OS antiga sem cliente): modo legado, só `gravarNaOs()` e retorna `null` (comportamento de hoje, sem justificativa).
  4. `$sug = sugestao->para($personId)`; `$anterior = $os?->cor_credito ?: vigente($personId)?->cor`; `$exige = $cor !== $sug['cor'] || ($anterior !== null && $cor !== $anterior)`; se `$exige` e justificativa vazia → `ValidationException(['justificativa' => [msg.justificativa_obrigatoria com :sugestao e :anterior]])`. Textos trimados; vazio vira `null`.
  5. Proteção contra duplo clique: se a última linha do cliente tem mesma cor, mesma OS, mesmo usuário, mesma observação e menos de 1 minuto, devolve essa linha sem gravar de novo.
  6. Em `DB::transaction`: cria a linha (`person_id`, `cor`, `cor_anterior`, `sugestao_cor`, `sugestao_motivo`, `justificativa`, `observacao`, `service_order_id`, `user_id`, `registrado_em = now()`); se `$os`, chama `gravarNaOs`.
  7. `gravarNaOs($os, $cor, $registro, $anterior)`: `forceFill(['cor_credito' => $cor, 'cor_credito_em' => $os->cor_credito_em ?? now()])->save()`; `ServiceOrderEvent::record($os, 'cor_credito_registrada', array_filter(['cor' => $cor, 'registro_id' => $registro?->id, 'sugestao' => $registro?->sugestao_cor, 'alterada' => $anterior !== null && $anterior !== $cor], fn ($v) => $v !== null))`. **Sempre grava o evento** (antes só se a cor mudasse): é ele que fecha a barreira quando o pedido novo reabre a análise. Nunca coloca justificativa/observação no evento.
- `pedirAnalise`:
  1. `$automatico && ! StageGate::active()` → retorna (piloto desligado, nada muda).
  2. OS já com `cor_credito`: automático retorna; manual lança `ValidationException(['cor_credito' => [msg.ja_registrada]])`.
  3. `ServiceOrderEvent::record($os, 'analise_credito_pedida', ['automatico' => $automatico])`.
  4. `app(AlertService::class)->raise('credito_analise_pedida', trans('...alerta.titulo'), trans('...alerta.mensagem', ['os','placa','cliente']), ['financeiro'], $os, unicoPorDia: true)`. Texto sem cor, sem valor e sem minutos (o prazo vem da barreira e pode ser dividido pelo modo de teste da F03).
- `condicaoEhAPrazo`: **mover** para cá, sem mudar o comportamento, as duas expressões de `checkAlcadas` (`/dias|prazo|faturad|boleto|parcel|duplicata/iu` verdadeira e `/vista|pix|dinheiro|cart[aã]o|d[eé]bito/iu` falsa). Vazio/null → `false`.
- `conferirPrazo($os, $condicao, $user)`: se não é a prazo, retorna; `$cor = corEfetiva($os)`; `$nivel = nivel($cor)`; se 0, retorna; `app(AlcadaService::class)->authorize('condicao_pagamento', $os, $nivel, trans('...alcada.prazo_negociacao', ['cor' => $cor]), $user)` (o `valor` do pedido é 1 ou 2, como hoje).
- `conferirPrazoNoFaturamento($os, $user)`: `$condicao = trim((string) $os->condicao_pagamento)`; a prazo? cor efetiva ≥ 1? `temPrazoAutorizado($os, $nivel)` → retorna (já foi aprovado/consumido na negociação); senão `authorize('condicao_pagamento', $os, $nivel, trans('...alcada.prazo_faturamento', ['condicao','cor']), $user)`.
- `temPrazoAutorizado($os, $nivel)`: existe `AlcadaPedido` `tema = condicao_pagamento`, `service_order_id = $os->id`, `status IN (APROVADO, CONSUMIDO)` e (`valor IS NULL` ou `valor >= $nivel`). Mesma ideia de `IndicatorCalculator::pecasAbaixoDoMinimo` (`:1246-1249`).

**4. `Http/Controllers/CreditoClienteController.php`** (estende `Webkul\Admin\Http\Controllers\Controller`; injeta `PersonRepository` e `CreditoCliente`)
- `historico(int $personId): View` — `CreditoCliente::authorize('ver')`; `abort_unless(CreditoCliente::disponivel(), 404, trans('service_order::app.credito.msg.banco_pendente'))`; `$person = findOrFail`; `abort_unless($this->podeAcessarCliente($person), 403)`; devolve `service_order::credito.historico` com `person`, `registros = historico($person->id, 200)`, `situacao`.
- `registrar(int $personId): RedirectResponse` — `authorize('registrar')`; mesma checagem de acesso; sem a tabela (`! disponivel()`) não há como gravar sem OS: `flash('error', msg.banco_pendente)` e volta para a página do cliente; `validate`: `cor_credito` `required|in:verde,amarelo,vermelho`, `observacao` `nullable|string|max:500`, `justificativa` `nullable|string|max:500`; chama `CreditoCliente::registrar($person->id, ..., null)`; `ValidationException` vira `session()->flash('error', primeira mensagem)` (padrão de `WorkflowController::run`); sucesso `flash('success', msg.registrada_cliente)`; redireciona para `route('admin.contacts.persons.view', $person->id)`.
- `parametros(): RedirectResponse` — valida `credito_vermelho_dias` e `credito_amarelo_dias` (`required|integer|min:1|max:3650`), grava com `WorkshopSettings::set(..., (int) ...)`, `flash('success', credito.parametros_salvos)`, volta para `admin.ficha.edit`.
- `protected function podeAcessarCliente(Person $person): bool` — `$ids = bouncer()->getAuthorizedUserIds()`; `null` → `true`; senão `in_array($person->user_id, $ids)` ou existe OS do cliente cujo `user_id` está em `$ids` (cliente criado pelo Novo Cadastro não tem `user_id`).

**5. Views em `Resources/views/credito/`** (classes Tailwind iguais às do `workflow.blade.php`; textos via `@lang('service_order::app.credito....')`; datas `d/m/Y H:i`; escapar tudo com `{{ }}`)
- `_formulario.blade.php` — parâmetros: `$action`, `$sit` (resultado de `situacao`), `$corInicial`, `$botao`. Campos: `select name="cor_credito" required` (Verde — paga em dia / Amarelo — atrasa às vezes / Vermelho — inadimplente; pré-selecionada `$corInicial`), `input name="observacao" maxlength=500`, `input name="justificativa" maxlength=500` com dica "obrigatória quando a cor for diferente da sugestão do sistema ou da cor anterior" (sem `required`: quem exige é o servidor), botão.
- `os-card.blade.php` — substitui o bloco `workflow.blade.php:103-126`. Herda `$serviceOrder`, `$dt`, `$ok`, `$pending`, `$title`, `$label`, `$input`. Estrutura:
  1. Só renderiza se `CreditoCliente::podeVer()`. Título `2. Cor de crédito` + selo feito/pendente (`$serviceOrder->cor_credito`).
  2. Se `! CreditoCliente::disponivel()`: apenas o formulário antigo (select + Registrar) para quem `podeRegistrar()`.
  3. Senão, com `$sit = $svc->situacao($serviceOrder->person_id, $serviceOrder)`: linha "Cor desta OS" (selo colorido + "Registrada em dd/mm/aaaa hh:mm por Fulano" tirado da última linha com `service_order_id` = OS; sem linha, `cor_credito_em`; sem cor, "Ainda sem cor registrada"); linha "Cor do cliente hoje" (selo da `efetiva` + origem; se `revisao_pendente`, faixa amarela com `credito.revisao_pendente`); linha "Sugestão do sistema" (selo + motivo + até 3 títulos; valor em R$ só se `ValoresFinanceiros::can()`).
  4. Se a OS não tem cor: linha "Análise pedida em ... por ..." (último evento `analise_credito_pedida`) ou "Ainda não pedida", e botão POST `admin.service_orders.workflow.credito_pedir` para quem `podeVer()`.
  5. Se `podeRegistrar()`: `@include('service_order::credito._formulario', ['action' => route('admin.service_orders.workflow.cor_credito', $serviceOrder->id), ...])`, botão "Registrar cor" (OS sem cor) ou "Alterar cor" (OS com cor; pré-seleciona a cor da OS).
  6. Link "Ver o histórico do cliente" → `admin.credito.historico`.
- `person-card.blade.php` — recebe `$person`. Só renderiza se `CreditoCliente::podeVer()` e `CreditoCliente::disponivel()`. Conteúdo: título; selo da cor efetiva + significado ("Paga em dia" etc.) e origem; "Registrada em ... por ..." + observação do último registro; faixa de revisão pendente; "Sugestão do sistema" com motivo e títulos que pesam; últimos 5 registros (quando, cor, quem, justificativa/observação) + link "Ver o histórico completo"; aviso "Há N OS aguardando análise: #12, #14" com links (OS do cliente com `cor_credito` nulo e fora de Concluída/Cancelada); e, para `podeRegistrar()`, o formulário com `action = route('admin.credito.registrar', $person->id)`.
- `historico.blade.php` — `x-admin::layouts`, título "Histórico de crédito — {nome}", tabela com todas as colunas (quando, cor anterior → cor, quem, OS com link, sugestão, justificativa, observação), nota "mostrando os 200 mais recentes", link de volta para a página do cliente.
- `parametros.blade.php` — cartão "Cor de crédito do cliente: regras da sugestão automática" com os dois campos numéricos e botão; formulário próprio (`PUT admin.credito.parametros`); recebe `$cfg`, `$card`, `$titulo`, `$rotulo`, `$campo` herdados da Ficha.

**6. Testes** — `tests/Feature/Workshop/CreditoSugestaoTest.php` e `CreditoClienteTest.php` (ver seção de testes).

**7. Migrations** — duas, em "Banco (migrations)".

### Alterar

**`Models/ServiceOrderEvent.php`** — em `TIPOS` (`:26-96`) acrescentar `'analise_credito_pedida' => 'Análise de crédito pedida'` (junto de `'cor_credito_registrada'`).

**`Models/BarrierRule.php`** — em `DEFAULTS`, regra `cor_credito` (`:67-79`): `'evento_inicio' => 'chegada_veiculo,analise_credito_pedida'` (coluna tem 60 caracteres; são 38). Não mexer no resto da regra (30 min, destinos).

**`Services/WorkshopSettings.php`** — em `DEFAULTS`, junto dos parâmetros da Ficha (perto de `:94`): `'credito_vermelho_dias' => 15, 'credito_amarelo_dias' => 90`, com comentário "Cor de crédito (B1): título vencido há MAIS de N dias deixa o cliente vermelho; atraso nos últimos N dias, amarelo".

**`Services/WorkflowService.php`** — `setCorCredito(ServiceOrder $os, string $cor, ?string $justificativa = null, ?string $observacao = null): void` passa a delegar: `app(CreditoCliente::class)->registrar((int) $os->person_id, $cor, $observacao, $justificativa, $os);`. A assinatura antiga (`$os, $cor`) continua válida.

**`Http/Controllers/WorkflowController.php`**
- `saveCorCredito` (`:56-65`): trocar `ValoresFinanceiros::authorize('cor de crédito')` por `CreditoCliente::authorize('registrar')`; validar também `justificativa` e `observacao` (`nullable|string|max:500`); chamar `setCorCredito($os, request('cor_credito'), request('justificativa'), request('observacao'))`; mensagem `credito.msg.registrada_os`. Mantém `order($id)` (visibilidade da OS) e `run()`.
- Novo `pedirAnaliseCredito(int $id): RedirectResponse`: `CreditoCliente::authorize('ver')`; `$os = $this->order($id)`; `run($id, fn () => app(CreditoCliente::class)->pedirAnalise($os), msg.pedida)`.

**`Http/Controllers/ServiceOrderStageController.php`**
- Imports: `use Webkul\ServiceOrder\Services\Credito\CreditoCliente;`.
- `checkAlcadas` (`:214-226`): substituir o bloco do prazo por:
  ```php
  $condicao = trim((string) request('condicao_pagamento'));

  // A prazo: amarelo vai ao gerente; vermelho, direto ao dono (24 h). Vale a cor EFETIVA do cliente.
  if ($condicao !== '' && $condicao !== (string) $serviceOrder->condicao_pagamento) {
      app(CreditoCliente::class)->conferirPrazo($serviceOrder, $condicao);
  }
  ```
  (a mensagem do pedido continua "Forma de pagamento a prazo para cliente de crédito {cor}").
- `storeBoleto` (`:489`) e `storeBoletoViaAsaas` (`:516`): logo depois de `ValoresFinanceiros::authorize('cobrança')` e da validação, chamar `if ($bloqueio = $this->conferirCreditoNoFaturamento($id)) { return $bloqueio; }`. O helper protegido carrega a OS (`$this->serviceOrderRepository->findOrFail($id)`), chama `conferirPrazoNoFaturamento` e, em `ValidationException`, faz `session()->flash('error', primeira mensagem)` e devolve `redirect()->route('admin.service_orders.view', $id)`; sem erro devolve `null`.

**`Http/Controllers/CadastroController.php`** — em `store()`, logo depois do bloco `chegada_veiculo` (`:227-230`): `app(CreditoCliente::class)->pedirAnalise($serviceOrder, automatico: true);` (o método ignora quando o piloto está desligado).

**`Http/Controllers/ServiceOrderController.php`** — em `store()` (`:154-179`), depois de `Event::dispatch('service_order.create.after', ...)`: mesma chamada `pedirAnalise($serviceOrder, automatico: true)`.

**`Services/Alcada/AlcadaCatalog.php`** — acrescentar (sem mexer em `temas()` nem no closure `$t`, para não conflitar com as outras frentes que adicionam temas):
```php
/** Temas cujo pedido acima da alçada do gerente vai direto à fila do dono, com 24 h (roteiro, linha 31). */
public const DIRETO_AO_DONO = ['condicao_pagamento'];

public static function vaiDiretoAoDono(string $tema): bool
{
    return in_array($tema, self::DIRETO_AO_DONO, true);
}
```

**`Services/Alcada/AlcadaService.php`** — em `open()` (`:166`):
```php
// Pedido que o próprio gerente faz acima da alçada dele já nasce no dono; os temas "diretos"
// (prazo para cliente vermelho) também, mesmo quando quem pede é o consultor ou o financeiro.
$direto = $necessario === AlcadaCatalog::NIVEL_DONO
    && ($nivelUsuario >= AlcadaCatalog::NIVEL_GERENTE || AlcadaCatalog::vaiDiretoAoDono($tema));
```
O resto do método não muda: o pedido nasce `encaminhado`, com `prazo_dono_em = now() + 24 h`, avisa o dono com alerta crítico (`notifyOwner`) e entra na fila do dono (`queueFor`). O gerente continua enxergando o pedido na fila dele (`queueFor` do gerente lista os abertos), sem poder aprovar. Tema amarelo (nível 1) não muda.

**`Services/Bonus/BonusCatalog.php`** — `prazo_cliente_vermelho` (`:75`) e `prazo_vermelho_sem_dono` (`:100`) passam a `$t('...', true)` (automáticas). Rótulo cosmético: o catálogo não usa a flag para decidir nada (`grep automatica`).

**`Services/Bonus/TravaService.php`** — `detectar()` (`:105-109`) soma `+ $this->prazosParaClienteVermelho($periodo, $from, $to)`; novo método:
```php
protected function prazosParaClienteVermelho(string $periodo, Carbon $from, Carbon $to): int
{
    $credito = app(CreditoCliente::class);

    // Se a matriz não exige o dono para o prazo ao cliente vermelho (tema desligado), não há trava.
    if (app(AlcadaService::class)->required('condicao_pagamento', 2)['nivel'] !== AlcadaCatalog::NIVEL_DONO) {
        return 0;
    }

    $novas = 0;

    // Consultor: forma de pagamento a prazo registrada em OS de cliente vermelho sem pedido aprovado/consumido.
    foreach (ServiceOrderEvent::where('tipo_evento', 'forma_pagamento_registrada')->whereBetween('ocorrido_em', [$from, $to])->get() as $evento) {
        $os = ServiceOrder::find($evento->service_order_id);
        if (! $os || $os->cor_credito !== 'vermelho' || ! $credito->condicaoEhAPrazo($evento->dados_json['forma'] ?? null) || $credito->temPrazoAutorizado($os, 2)) continue;
        $colaborador = Colaborador::ativos()->where('user_id', $evento->usuario_id ?: $os->user_id)->comCargo('consultor')->first();
        if ($colaborador) $novas += $this->criarAutomatica($periodo, $colaborador, 'consultor', 'prazo_cliente_vermelho', "vermelho_prazo:{$evento->id}", "OS #{$os->id} a prazo para cliente vermelho sem a aprovação do dono.", $os->id);
    }

    // Financeiro: cobrança gerada em OS vermelha com condição a prazo, sem aprovação do dono.
    foreach (ServiceOrderEvent::where('tipo_evento', 'boleto_ou_cobranca_gerada')->whereBetween('ocorrido_em', [$from, $to])->get() as $evento) {
        $os = ServiceOrder::find($evento->service_order_id);
        if (! $os || $os->cor_credito !== 'vermelho' || ! $credito->condicaoEhAPrazo($os->condicao_pagamento) || $credito->temPrazoAutorizado($os, 2)) continue;
        $colaborador = Colaborador::ativos()->where('user_id', $evento->usuario_id)->comCargo('financeiro')->first();
        if ($colaborador) $novas += $this->criarAutomatica($periodo, $colaborador, 'financeiro', 'prazo_vermelho_sem_dono', "vermelho_cobranca:{$evento->id}", "Cobrança a prazo na OS #{$os->id} (cliente vermelho) sem a aprovação do dono.", $os->id);
    }

    return $novas;
}
```
Idempotente pela `chave_unica`. Importar `Colaborador`, `ServiceOrder`, `AlcadaService`, `AlcadaCatalog`, `CreditoCliente`. Texto da descrição via lang `credito.travas.*`.

**`Http/Middleware/RegistraAcessoSensivel.php`** — `PREFIXOS` (`:17-19`) += `'admin.credito.'` (histórico, registro e parâmetros ficam no registro de acesso LGPD; o nome mais longo, `admin.credito.historico`, cabe nos 80 caracteres de `acessos_sensiveis.rota`).

**`Providers/ServiceOrderServiceProvider.php`** — em `boot()` (perto de `:52-63`):
```php
// Cartão "Cor de crédito" na página do cliente (o próprio template só aparece para quem tem a permissão).
Event::listen('admin.contact.persons.view.right.before', function ($viewRenderEventManager) {
    $viewRenderEventManager->addTemplate('service_order::credito.person-card');
});
```

**`Routes/web.php`** — ver "ACL, menu, rotas e configurações".

**`Config/acl.php`** — ver mesma seção.

**`Resources/views/partials/workflow.blade.php`**
- Linhas `:19-21`: manter `$eventosRestritos` (inclui `cor_credito_registrada`), acrescentar `$verCredito = \Webkul\ServiceOrder\Services\Credito\CreditoCliente::podeVer();` e `$eventosCredito = ['cor_credito_registrada', 'analise_credito_pedida'];` e trocar o `reject` para `fn ($e) => (! $canSeeValues && in_array($e->tipo_evento, $eventosRestritos, true)) || (! $verCredito && in_array($e->tipo_evento, $eventosCredito, true))`.
- Linhas `:103-126`: substituir o bloco inteiro por `@include('service_order::credito.os-card')`.

**`Resources/views/ficha/edit.blade.php`** — uma linha, antes do comentário `{{-- Tabela de margem de peças --}}` (`:137`): `@include('service_order::credito.parametros')` (fora do `<form>` principal, que fecha em `:94`).

**`Resources/lang/pt_BR/app.php` e `Resources/lang/en/app.php`** — chaves em `acl` e a seção `credito` (ver "Textos da interface"). Manter os dois arquivos com as mesmas chaves.

**Testes antigos a ajustar** (efeito direto da nova permissão; nada é apagado):
- `tests/Feature/Workshop/HttpFlowTest.php:97-101` ("termo de entrada"): o passo da cor passa a ser feito por um Financeiro com as chaves: `staff('financeiro', ['credito_cliente', 'credito_cliente.registrar'])`; o restante do teste fica igual.
- `tests/Feature/Workshop/TecnicoValoresTest.php:26-28` (`valuesScenario`): acrescentar `'credito_cliente'` à lista do gerente (o teste da linha 78 espera ver "Cor de crédito"). A linha 138 (Técnico leva 403 na rota de cor) continua passando: a rota antiga segue em `service_orders.edit` e o 403 vem de `CreditoCliente::authorize('registrar')`.

**Não alterar**: `StageGate.php` (já bloqueia o Cadastro), `IndicatorCalculator.php`/`IndicatorCatalog.php`, `print.blade.php`, `public/tracking.blade.php`, `PublicTrackingController`, `TecnicoTasks`, `ServiceOrderDataGrid`, `RotinaService` (a lista "Créditos a analisar" já serve; o cronômetro dela conta desde a criação da OS, praticamente igual ao pedido automático), pacotes Admin/Contact/Core.

## Banco (migrations)

Faixa de horário `06xxxx` reservada a esta frente (a F06) para não colidir com as outras (sugestão: cada frente usa o próprio número como hora). Pasta: `packages/Webkul/ServiceOrder/src/Database/Migrations/`.

**1. `2026_11_18_060000_create_cliente_credito_registros_table.php`**
```php
public function up(): void
{
    if (Schema::hasTable('cliente_credito_registros')) {
        return;
    }

    Schema::create('cliente_credito_registros', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->unsignedInteger('person_id');                       // sem FK de propósito: o histórico sobrevive a qualquer exclusão
        $table->string('cor', 10);                                   // verde | amarelo | vermelho
        $table->string('cor_anterior', 10)->nullable();
        $table->string('sugestao_cor', 10)->nullable();
        $table->string('sugestao_motivo', 500)->nullable();
        $table->string('justificativa', 500)->nullable();
        $table->string('observacao', 500)->nullable();
        $table->unsignedInteger('service_order_id')->nullable();    // OS que originou a análise (vazio = feita pela página do cliente)
        $table->unsignedInteger('user_id')->nullable();
        $table->dateTime('registrado_em');
        $table->timestamp('created_at')->useCurrent();

        $table->index(['person_id', 'id']);
        $table->index('service_order_id');
        $table->index('registrado_em');
    });
}

public function down(): void
{
    // Histórico de crédito nunca se apaga: só remove a tabela se ela ainda estiver vazia.
    if (Schema::hasTable('cliente_credito_registros') && ! DB::table('cliente_credito_registros')->exists()) {
        Schema::drop('cliente_credito_registros');
    }
}
```

**2. `2026_11_18_060100_add_analise_credito_to_cor_credito_barrier.php`** — só DML, idempotente:
```php
public function up(): void
{
    if (! Schema::hasTable('barrier_rules')) {
        return;
    }

    // Só a linha que ainda está no padrão antigo; regra personalizada pela oficina não é tocada.
    DB::table('barrier_rules')
        ->where('codigo', 'cor_credito')
        ->where('evento_inicio', 'chegada_veiculo')
        ->update(['evento_inicio' => 'chegada_veiculo,analise_credito_pedida', 'updated_at' => now()]);
}

public function down(): void
{
    if (! Schema::hasTable('barrier_rules')) {
        return;
    }

    DB::table('barrier_rules')
        ->where('codigo', 'cor_credito')
        ->where('evento_inicio', 'chegada_veiculo,analise_credito_pedida')
        ->update(['evento_inicio' => 'chegada_veiculo', 'updated_at' => now()]);
}
```
Por que migration: `BarrierRule::ensureDefaults()` usa `firstOrCreate` e **não atualiza** linhas existentes (`BarrierRule.php:347-354`), e "Restaurar as padrão que faltam" (`WorkshopSettingsController.php:151-158`) também só cria as ausentes. Oficinas novas (F00) já nascem com o padrão novo pelo `DEFAULTS`.

**Tolerância a banco sem a migration** (o servidor só recebe com o `migrate-safe.php`):
- Sem `cliente_credito_registros`: `CreditoCliente::disponivel()` é falso → `registrar` cai no modo legado (só grava na OS, como hoje), `situacao` usa só a cor da OS, o cartão da OS mostra o formulário antigo, o cartão do cliente e as rotas `admin.credito.historico|registrar` ficam inertes (a view/rota não consulta a tabela; `historico` devolve 404 com mensagem "Atualização do banco pendente"), `TravaService` e `corEfetiva` não consultam a tabela. Nenhuma tela por onde todo usuário passa (OS, página do cliente) quebra.
- Sem a migration 2: a barreira continua começando só em `chegada_veiculo` (comportamento de hoje); o pedido automático ainda grava o evento e avisa o Financeiro.
- A memoização de `disponivel()` é **por nome do banco** (`DB::connection()->getDatabaseName()`), porque cada oficina tem o seu banco (F00).

## ACL, menu, rotas e configurações

**ACL — `Config/acl.php`** (chaves planas, arquivo do pacote)
```php
[
    'key' => 'credito_cliente',
    'name' => 'service_order::app.acl.credito_cliente',
    'route' => ['admin.credito.historico'],
    'sort' => 21,
], [
    'key' => 'credito_cliente.registrar',
    'name' => 'service_order::app.acl.credito_registrar',
    'route' => ['admin.credito.registrar'],
    'sort' => 1,
],
```
- Acrescentar `'admin.service_orders.workflow.credito_pedir'` à lista de `service_orders.edit` (`acl.php:22-60`), como todas as rotas do fluxo da OS. **Manter** `admin.service_orders.workflow.cor_credito` nessa lista (`:46`): o teste do Técnico espera 403 (e não 401) nessa rota; o 403 vem do controlador.
- Acrescentar `'admin.credito.parametros'` à lista de `settings.ficha` (`acl.php:207-211`): quem edita a Ficha edita os limites (na F05 o Gerente tem a Ficha só em leitura; o cartão `parametros` deve seguir o modo somente leitura que a F05 definir).
- Efeito: Bouncer devolve **401** a quem não tem a chave nas rotas `admin.credito.*`; o controlador repete a checagem (`CreditoCliente::authorize`) e devolve **403** + `Log::warning`. Quem fecha o "403 em rota e API" de forma geral é a F05.
- Permissões que as funções devem receber (entregar à F04/F05): `credito_cliente` para Consultor, Financeiro, Gerente e Proprietário; `credito_cliente.registrar` para Financeiro e Gerente (decisão 2). Técnico, Peças, Chefe de oficina, Zeladoria, Mentor e Suporte **não** recebem. Função "Administrador" (`permission_type = all`) já passa.

**Rotas — `Routes/web.php`** (grupo `admin`, já com `user` e `RegistraAcessoSensivel`)
```php
// Dentro do grupo WorkflowController (prefix service-orders/{id}/workflow), junto de cor-credito (:99):
Route::post('credito/pedir-analise', 'pedirAnaliseCredito')->name('admin.service_orders.workflow.credito_pedir');

// No fim do arquivo:
// Cor de crédito do cliente: histórico, registro (Financeiro) e regras da sugestão (Ficha).
Route::controller(CreditoClienteController::class)->prefix('credito')->group(function () {
    Route::get('clientes/{personId}/historico', 'historico')->name('admin.credito.historico');
    Route::post('clientes/{personId}', 'registrar')->name('admin.credito.registrar');
    Route::put('parametros', 'parametros')->name('admin.credito.parametros');
});
```
Importar `CreditoClienteController` no topo. Rota antiga `admin.service_orders.workflow.cor_credito` (`:99`) continua, agora aceitando `justificativa` e `observacao`.

**Menu**: nenhum item novo. A cor aparece no cartão da OS (Fluxo da OS) e na página do cliente (Contatos > Pessoas). O Financeiro chega à OS pelo aviso, por "Minha rotina > Créditos a analisar" ou pelo Faturamento; o histórico abre pelo link do cartão da OS (não depende de ter o menu Contatos).

**Configurações (`WorkshopSettings`)**: `credito_vermelho_dias` (padrão 15), `credito_amarelo_dias` (padrão 90), editadas no cartão novo da Ficha de Enquadramento (somente o dono edita). Nada de prazo/percentual fixo no código: os 30 min vêm da barreira `cor_credito` (já configurável), as 24 h do dono continuam em `AlcadaService::PRAZO_DONO_HORAS`.

**Códigos novos**: evento `analise_credito_pedida`; alerta `credito_analise_pedida`; rotas `admin.credito.historico`, `admin.credito.registrar`, `admin.credito.parametros`, `admin.service_orders.workflow.credito_pedir`; tabela `cliente_credito_registros`; constante `AlcadaCatalog::DIRETO_AO_DONO`.

## Textos da interface (pt-BR)

Seção `credito` em `Resources/lang/pt_BR/app.php` (e a tradução equivalente em `en/app.php`, mesmas chaves) e duas chaves em `acl`.

| Chave | Texto pt-BR |
|---|---|
| `acl.credito_cliente` | Cor de crédito do cliente (ver e pedir análise) |
| `acl.credito_registrar` | Registrar e alterar a cor de crédito (Financeiro) |
| `credito.titulo` | Cor de crédito do cliente |
| `credito.titulo_os` | Cor de crédito |
| `credito.cores.verde/amarelo/vermelho` | Verde / Amarelo / Vermelho |
| `credito.significado.verde` | Paga em dia |
| `credito.significado.amarelo` | Atrasa às vezes |
| `credito.significado.vermelho` | Inadimplente |
| `credito.sem_analise` | Nenhuma análise de crédito registrada para este cliente. |
| `credito.registrada` | Registrada em :data por :usuario |
| `credito.cor_desta_os` | Cor desta OS |
| `credito.cor_do_cliente_hoje` | Cor do cliente hoje |
| `credito.origem_registrada` | registrada pelo Financeiro |
| `credito.origem_sugestao` | calculada pelo sistema, aguardando o Financeiro |
| `credito.sem_cor_na_os` | Ainda sem cor registrada nesta OS. |
| `credito.sugestao` | Sugestão do sistema |
| `credito.sugestao_motivo_vermelho` | Título #:titulo (OS #:os) vencido há :dias dias (limite: :limite dias). |
| `credito.sugestao_motivo_amarelo` | :quantidade título(s) com atraso nos últimos :janela dias (mais recente: título #:titulo, vencido em :data). |
| `credito.sugestao_motivo_verde` | Nenhum título vencido há mais de :limite dias e nenhum atraso nos últimos :janela dias. |
| `credito.sugestao_sem_historico` | Cliente sem títulos registrados: ainda não há histórico de pagamento. |
| `credito.revisao_pendente` | Revisão pendente: desde a última análise o sistema indica :cor. Peça uma nova análise ao Financeiro. |
| `credito.titulos_que_pesam` | Títulos que pesam na sugestão |
| `credito.titulo_linha` | Título #:id · OS #:os · venceu em :vencimento · :dias dia(s) de atraso |
| `credito.os_aguardando` | Há :quantidade OS aguardando análise: |
| `credito.historico` | Histórico de análises |
| `credito.historico_completo` | Ver o histórico completo |
| `credito.historico_titulo` | Histórico de crédito de :cliente |
| `credito.historico_vazio` | Ainda não há análises registradas. |
| `credito.historico_limite` | Mostrando os 200 registros mais recentes. Nada é apagado: cada mudança é um registro novo. |
| `credito.colunas.quando/cor/quem/os/sugestao/justificativa/observacao` | Quando / Cor / Quem / OS / Sugestão do sistema / Justificativa / Observação |
| `credito.registrar_titulo` | Registrar a cor de crédito |
| `credito.cor` | Cor |
| `credito.observacao` | Observação (opcional) |
| `credito.justificativa` | Justificativa |
| `credito.justificativa_dica` | Obrigatória quando a cor for diferente da sugestão do sistema ou da cor anterior. |
| `credito.registrar_btn` | Registrar cor |
| `credito.alterar_btn` | Alterar cor |
| `credito.pedir_analise_btn` | Pedir análise de crédito ao Financeiro |
| `credito.analise_pedida` | Análise pedida em :data por :usuario. |
| `credito.analise_nao_pedida` | Análise ainda não pedida ao Financeiro. |
| `credito.parametros_titulo` | Cor de crédito do cliente: regras da sugestão automática |
| `credito.parametros_vermelho` | Cliente fica Vermelho com título vencido há mais de (dias) |
| `credito.parametros_amarelo` | Cliente fica Amarelo com atraso nos últimos (dias) |
| `credito.parametros_ajuda` | Vale para os títulos (boletos e cobranças Asaas) do cliente. O Financeiro pode alterar a cor, sempre com justificativa. |
| `credito.parametros_salvos` | Regras da cor de crédito salvas. |
| `credito.msg.registrada_os` | Cor de crédito registrada. |
| `credito.msg.registrada_cliente` | Cor de crédito do cliente registrada. |
| `credito.msg.pedida` | Análise de crédito pedida ao Financeiro. |
| `credito.msg.ja_registrada` | Esta OS já tem a cor de crédito registrada. |
| `credito.msg.cor_invalida` | Cor de crédito inválida. |
| `credito.msg.justificativa_obrigatoria` | Informe a justificativa: a cor escolhida é diferente da sugestão do sistema (:sugestao) ou da cor anterior (:anterior). (sem a parte "ou da cor anterior" quando não há cor anterior) |
| `credito.msg.os_encerrada` | Esta OS já foi encerrada. |
| `credito.msg.os_de_outro_cliente` | Esta OS não é deste cliente. |
| `credito.msg.sem_permissao` | Seu perfil não tem acesso à cor de crédito. |
| `credito.msg.banco_pendente` | Atualização do banco pendente: peça para rodar a atualização do sistema. |
| `credito.alerta.titulo` | Análise de crédito pedida |
| `credito.alerta.mensagem` | Análise de crédito pedida na OS #:os - :placa - :cliente. Registre a cor de crédito no prazo. |
| `credito.alcada.prazo_negociacao` | Forma de pagamento a prazo para cliente de crédito :cor |
| `credito.alcada.prazo_faturamento` | Faturamento a prazo (:condicao) para cliente de crédito :cor |
| `credito.travas.prazo_consultor` | OS #:os a prazo para cliente vermelho sem a aprovação do dono. |
| `credito.travas.prazo_financeiro` | Cobrança a prazo na OS #:os (cliente vermelho) sem a aprovação do dono. |
| Evento da linha do tempo `analise_credito_pedida` | Análise de crédito pedida |

Mensagens de alerta e de evento **nunca** levam cor, observação, justificativa nem R$. Selo das cores: verde `bg-green-100 text-green-700`, amarelo `bg-yellow-100 text-yellow-800`, vermelho `bg-red-100 text-red-700` (mesmas classes de `workflow.blade.php:434`), sempre com o nome da cor escrito.

## Testes Pest a escrever

Arquivos `tests/Feature/Workshop/CreditoSugestaoTest.php` e `CreditoClienteTest.php`. Funções auxiliares globais com prefixo `credito` (nomes únicos entre arquivos): `creditoBoleto($os, $vencimento, $status = 'Pendente', $pagoEm = null)` (cria o título; para "pago" ajusta `updated_at` por `DB::table`), `creditoEquipe()` (usuários consultor, financeiro, gerente, dono, técnico, peças com as chaves certas), `creditoAvanco()` (`app(StageAdvanceService::class)`). Usar `$this->at('2026-11-18 10:00:00')` para congelar o relógio; o anti-duplo-clique (1 min) exige variar cor/observação ou avançar o relógio entre dois registros iguais.

| # | Teste | Cobre |
|---|---|---|
| 1 | sugestão: boleto em aberto vencido há 20 dias deixa o cliente vermelho (motivo cita o título e os 20 dias) | PDF "boleto vencido há 20 dias aparece Vermelho" |
| 2 | sugestão: "mais de 15 dias": 15 dias é amarelo, 16 é vermelho | B1.3 |
| 3 | sugestão: pago com atraso dentro de 90 dias é amarelo; fora da janela volta a verde; vencido ontem em aberto é amarelo | B1.3 |
| 4 | sugestão: cancelado, sem vencimento, pago em dia e título de outro cliente não pesam; cliente sem títulos é verde "sem histórico" | B1.3 |
| 5 | sugestão: os limites vêm da configuração da oficina (20 dias com limite 30 vira amarelo) | regra vem da oficina |
| 6 | registrar grava data, usuário, observação e a sugestão; o histórico acumula; update/delete lançam `LogicException` | B1.1, "nada se apaga" |
| 7 | justificativa: obrigatória quando difere da sugestão ou da cor anterior; sem ela nada é gravado; seguir a sugestão não exige | B1.3 |
| 8 | registrar pela OS preenche `cor_credito`, grava `cor_credito_registrada` (com `cor`), mantém a primeira `cor_credito_em` e grava a linha do cliente com a OS | B1.2, CON-03 |
| 9 | cor efetiva: sem análise vale a sugestão; análise antiga é superada quando a sugestão piora; decisão justificada vale enquanto a sugestão não muda; OS antiga só com cor na OS continua valendo | B1.4 |
| 10 | banco sem a tabela (`definirDisponivel(false)`): registrar grava só na OS, cartões não quebram, OS e página do cliente abrem | tolerância a tabela ausente |
| 11 | permissões: Consultor/Financeiro/Gerente com a chave veem; Técnico, Peças com valores mas sem a chave, e quem tem a chave mas não vê valores (piloto ligado) não veem; só Financeiro/Gerente com `.registrar` registram; função `all` passa | B1.1 "visível só para..." |
| 12 | página do cliente (`admin.contacts.persons.view`) mostra o cartão ao Gerente e não ao Técnico (HTML sem "Cor de crédito do cliente"); testar também o parcial com `view('service_order::credito.person-card', ['person' => $p])->render()` | B1.1 |
| 13 | tela da OS: cartão só com a chave; evento de crédito some da linha do tempo de quem não tem; Técnico não vê "Análise de crédito" nem "Cor de crédito" | B1.5 |
| 14 | registrar: Técnico leva 403 na rota antiga da OS; Financeiro registra pela OS e pela página do cliente (`admin.credito.registrar`); sem a chave a rota nova dá 401; histórico `admin.credito.historico` abre para quem pode e grava em `acessos_sensiveis` | B1.5, LGPD |
| 15 | vazamento: link de acompanhamento assinado, impressão (`print`), `PersonResource` (busca de clientes, JSON), `admin.tecnico.tasks` e mensagens de alerta não contêm cor, observação nem justificativa (cliente com registro vermelho e observação "SEGREDO-CREDITO") | B1.5 |
| 16 | consultor pede a análise: evento, alerta ao Financeiro (texto sem cor), um só aviso por dia, recusa se a OS já tem cor, e a barreira `cor_credito` abre mesmo em OS sem veículo e sem `chegada_veiculo` | B1.2 |
| 17 | Financeiro registra em 20 min: barreira cumprida no prazo, FIN-01 e CON-03 em 100%; em 40 min: FIN-01 fora do prazo. Usar `$this->enablePilot([], ['cor_credito'])`, `IndicatorService::evaluate` (como `AlcadasTest.php:169`) e gravar `execucao_liberada` depois do registro (sem esse evento o CON-03 não tem OS no cálculo); `$engine->tick(...)` depois dos 30 min para o caso fora do prazo | roteiro 13, FIN-01, CON-03 |
| 18 | a OS não sai do Cadastro sem a cor (`faltando` contém "Cor de crédito (verde, amarelo ou vermelho)"); depois do registro do Financeiro, avança | roteiro 12 |
| 19 | abrir pelo Novo Cadastro com o piloto ligado já pede a análise (evento `automatico`); com o piloto desligado nenhum evento/alerta novo | B1.2, piloto desligado |
| 20 | a prazo para cliente vermelho (boleto vencido há 20 dias, sem análise): consultor tenta "Boleto 30 dias" na negociação → erro, condição não gravada, pedido `condicao_pagamento` com `status = encaminhado`, `nivel_necessario = proprietario`, `prazo_dono_em` = pedido + 24 h, na fila do dono e visível ao gerente; alerta crítico ao dono | roteiro 31, PDF |
| 21 | a prazo: dono aprova → consultor repete e grava (aprovação consumida); amarelo continua indo ao gerente (`pendente`); verde e "À vista (PIX)" passam; pedido aberto repetido não duplica | B1.4 |
| 22 | faturar a prazo: `boletos.store` e `boletos.asaas` (com `Http::fake`) para OS de cliente vermelho cuja condição é a prazo e sem pedido aprovado/consumido criam pedido e não geram o título; com pedido consumido passam; piloto desligado passa | PDF "tentar faturar a prazo cria pedido" |
| 23 | piloto desligado: sem pedidos, sem bloqueio, página do cliente funciona para quem tem a chave | piloto desligado |
| 24 | travas automáticas: `prazo_cliente_vermelho` (consultor) e `prazo_vermelho_sem_dono` (financeiro) para OS vermelha a prazo sem aprovação; não duplicam; não criam com pedido aprovado/consumido nem com o tema desligado na matriz | B1.6 |
| 25 | Ficha: `PUT admin.credito.parametros` salva 15/90 editados, valida (inteiro ≥ 1) e exige a permissão da Ficha | regra vem da oficina |
| 26 | migrations: a segunda estende só a barreira `cor_credito` que ainda está em `chegada_veiculo` (rodar `up()` de novo não muda nada; regra personalizada fica intacta; `down()` volta); rodar `up()` da primeira com a tabela já existente é inofensivo | migrations seguras |

Também: o teste `StageGateTest` "Cadastro exige ..." e o `AllBarriersTest` "cor de crédito: financeiro aos 30 min..." continuam passando sem mudança (garantia de que o `StageGate` e o início por `chegada_veiculo` seguem intactos).

**Roteiro (72 linhas)**: linhas 12 (teste 18), 13 (teste 17), 31 (teste 20) e, por tabela, 5 (teste 11/12/13: cada usuário vê só o que é seu), 6 (testes 13 e 15). Linhas 11 e 14 são da F07.

Rodar (PowerShell, conforme o contexto comum): `$env:DB_DATABASE='krayin_test'`; aplicar as duas migrations com `php artisan migrate --path=<caminho relativo de cada uma>`; `php artisan test --filter=Credito`; `php artisan test --filter=Workshop`; `php vendor/bin/pint` nos arquivos tocados. Não rodar nada no `krayin_crm`.

## Riscos e interações com outras frentes

**Riscos**
- **Quem registra a cor muda**: hoje qualquer perfil que vê valores registra; passa a exigir `credito_cliente.registrar`. Funções existentes precisam receber as chaves (ver "Passo a passo") e os dois testes antigos acima mudam. Com o piloto desligado o cartão da OS nem aparece, então produção hoje não perde nada.
- **Pedido a prazo para Vermelho vai direto ao dono**: muda a regra "o dono só vê o que o gerente encaminhou" só para este tema; DON-05 (decidiu sem o gerente) deixa de contar esses pedidos e DON-06 (24 h) passa a contá-los desde a criação. Decisão 1.
- **Data do pagamento = `updated_at` do título** (aproximação já usada por `adimplencia`/`reguaCumprida`). Se alguma frente passar a editar um título já pago (ex.: F09 gravando NF ou link na linha do boleto), a data muda e um pagamento antigo em dia pode parecer atrasado. Mitigação: isolado em `diaDoPagamento()`; alternativa futura é a coluna `pago_em` (guardar com `Schema::hasColumn`).
- **Baixa esquecida**: título pago e não baixado deixa o cliente vermelho/amarelo. O Financeiro corrige com justificativa e a régua de cobrança (FIN-11) já cobra a baixa.
- **Migration da barreira reescreve uma linha de configuração**, só quando ela ainda tem o valor padrão; é a única forma de as oficinas já semeadas ganharem o novo início. Sem ela, o sistema funciona como hoje.
- **Evento sempre gravado** em `cor_credito_registrada` (antes só se mudasse): o contador "exceções de crédito do dia" (`FinanceiroService.php:319-321`) passa a contar também reconfirmações de amarelo/vermelho. Aceito: é o que o roteiro pede (cada análise conta).
- **Memoização por banco** de `CreditoCliente::disponivel()` (F00): se a F00 trocar de banco no mesmo processo, a chave pelo nome do banco evita herdar a resposta da oficina anterior.
- O rótulo de pendência "Cor de crédito (verde, amarelo ou vermelho)" continua aparecendo na lista "falta" para qualquer perfil que abra a OS em Cadastro (é o nome do requisito, não o dado do cliente); mantido para não quebrar o teste do StageGate.
- O cartão da página do cliente depende de `view_render_event('admin.contact.persons.view.right.before')` do core do Krayin; se o core mudar esse nome em atualização, o cartão some (o histórico e a OS seguem funcionando).

**Arquivos compartilhados com outras frentes** (edições pequenas e localizadas): `Config/acl.php`, `Routes/web.php`, `Providers/ServiceOrderServiceProvider.php`, `Services/WorkshopSettings.php` (DEFAULTS), `Models/ServiceOrderEvent.php` (TIPOS), `Models/BarrierRule.php` (DEFAULTS), `Services/Alcada/AlcadaCatalog.php` e `AlcadaService.php`, `Services/Bonus/BonusCatalog.php` e `TravaService.php`, `Http/Controllers/CadastroController.php`, `ServiceOrderController.php`, `ServiceOrderStageController.php`, `WorkflowController.php`, `Services/WorkflowService.php`, `Http/Middleware/RegistraAcessoSensivel.php`, `Resources/views/partials/workflow.blade.php`, `Resources/views/ficha/edit.blade.php`, `Resources/lang/*/app.php`, `tests/Feature/Workshop/HttpFlowTest.php` e `TecnicoValoresTest.php`.

**Interfaces por frente**
- **F04-perfis / F05-menu-permissoes**: entregar as chaves `credito_cliente` e `credito_cliente.registrar` aos perfis (Consultor, Financeiro, Gerente, Proprietário veem; Financeiro e Gerente registram). Perfis acumulados somam permissões; `podeVer()` ainda exige `ValoresFinanceiros::can()`, então "Técnico + outro perfil sem valores" continua sem crédito. Nossas rotas dão 401 pelo Bouncer e 403 no controlador; a normalização geral é da F05. Mentor (só leitura) e Suporte não recebem.
- **F03-piloto-modo-teste**: o aviso do pedido de análise não escreve minutos (o modo ÷10 dos prazos das barreiras vale para o FIN-01 sem mudança). As **24 h do dono** (`AlcadaService::PRAZO_DONO_HORAS`) não são prazo de barreira e o roteiro (linha 31) não as divide; a F03 não deve dividi-las. Os parâmetros de crédito têm padrão e **não** entram nas condições para ligar o piloto.
- **F07-termo-entrada**: não tocamos `StageGate`. Compartilhamos `workflow.blade.php` (nosso `@include` no lugar do bloco da cor) e `CadastroController::store` (uma linha depois de `chegada_veiculo`). O texto exato do requisito de cor no `StageGate` deve ser mantido.
- **F09-nota-saida**: compartilhamos `ServiceOrderStageController::storeBoleto/storeBoletoViaAsaas` (nossa conferência de prazo antes de criar o título) e a tabela `service_order_boletos` (não editar título pago; se criarem `pago_em`, usar `Schema::hasColumn` e avisar para trocarmos `diaDoPagamento()`). A saída "cliente frota com faturamento mensal" continua independente da cor; a negociação de prazo para frota vermelha passa pela mesma alçada.
- **F12-retorno**: `WorkflowActions::openReturn` (`:588-608`) cria a OS de retorno em Cadastro e o `StageGate` exige cor. A F12 decide: copiar a cor da OS de origem chamando `CreditoCliente::registrar($retorno->person_id, <cor>, ..., $retorno)` com justificativa "retorno/garantia", ou dispensar. **Não** chamar `pedirAnalise(automatico)` no retorno.
- **F00-oficinas**: a migration nova precisa rodar no banco de cada oficina; a oficina nova nasce com `BarrierRule::DEFAULTS` já com o início novo. A Visão do mentor não mostra crédito.
- **F01-correcoes**: as views novas já usam `d/m/Y H:i` e textos em português.
- **F02-notificacao-sms**: o aviso é um alerta comum (não crítico), sai por sino e push, sem SMS.
- **F10/F11/F13/F14**: sem interação além dos arquivos compartilhados acima (a mudança em `AlcadaCatalog`/`AlcadaService` só afeta o tema `condicao_pagamento`).

## Decisões em aberto

1. **O pedido de prazo para cliente Vermelho vai direto ao Proprietário (24 h) ou passa antes pelo Gerente?** O roteiro (linha 31) e o PDF dizem "vai ao Proprietário, com prazo de 24 h"; o capítulo 7 original diz que o dono só vê o que o gerente encaminhou. **Recomendação: direto ao Proprietário** (como o roteiro), com o Gerente enxergando o pedido na fila dele. Se preferirem o caminho antigo, basta esvaziar `AlcadaCatalog::DIRETO_AO_DONO` (uma linha) e o teste 20 passa a esperar `pendente`.
2. **Quem pode registrar e alterar a cor: só o Financeiro ou também o Gerente?** **Recomendação: Financeiro e Gerente** (cobre falta do Financeiro). O Proprietário não deve registrar: toda ação dele dentro da OS conta como "dono fora do fluxo" (DON-05).
3. **Detalhes da sugestão que o PDF não fecha.** **Recomendação**: (a) atraso = qualquer dia depois do vencimento, sem tolerância; (b) cliente novo, sem nenhum título, começa Verde com o aviso "sem histórico" (o Financeiro pode marcar Amarelo com justificativa); (c) data do pagamento = dia da baixa do título no sistema. Os dois limites (15 e 90 dias) ficam editáveis na Ficha.

## Passo a passo para o usuário

Depois que a equipe de desenvolvimento entregar o pacote:

1. **Atualizar o banco**: subir o zip e rodar `public/migrate-safe.php` (banco principal). Rodar também nos bancos das oficinas (inclusive a Oficina Teste Mentoria); como migrar os bancos das oficinas é o assunto da frente F00. Sem isso, o cartão do cliente fica inerte e a OS usa o formulário antigo da cor, sem erro.
2. **Dar as permissões** em Configurações > Funções: marcar "Cor de crédito do cliente (ver e pedir análise)" para Consultor, Financeiro, Gerente e Proprietário, e "Registrar e alterar a cor de crédito" para Financeiro e Gerente. Deixar desmarcado para Técnico, Peças, Chefe de oficina e Zeladoria (a frente de perfis deve fazer isso ao criar as funções novas).
3. **Conferir as regras** em Configurações > Ficha de Enquadramento, cartão "Cor de crédito do cliente: regras da sugestão automática" (padrão: Vermelho com título vencido há mais de 15 dias; Amarelo com atraso nos últimos 90 dias).
4. **Conferir a barreira** em Configurações > Indicadores e alertas: "Cor de crédito" (30 min, aviso ao Financeiro, depois ao Gerente) e, em Matriz de alçadas, que "Condição de pagamento" está ativo.
5. **Testar o roteiro** na Oficina Teste Mentoria, com o piloto ligado:
   - Linha 12: abrir um Novo Cadastro e tentar avançar sem a cor: o botão lista "Cor de crédito (verde, amarelo ou vermelho)" e fica travado. O Financeiro recebe o aviso "Análise de crédito pedida".
   - Linha 13: o Financeiro abre a OS, vai ao cartão "2. Cor de crédito", vê a sugestão do sistema e registra a cor dentro do prazo (CON-03 e FIN-01 cumpridos no Painel).
   - Teste do PDF: na OS de um cliente de teste, em Faturamento, usar "Registrar Boleto" com vencimento 20 dias atrás (status Pendente). Abrir o cliente em Contatos > Pessoas: o cartão mostra **Vermelho** (sugestão, aguardando o Financeiro) com o título que pesa. Em outra OS desse cliente, na Negociação, escolher "Boleto 30 dias" como condição: o sistema recusa e cria o pedido na Fila de alçadas do Proprietário com prazo de 24 h (linha 31). O Proprietário aprova, o consultor repete e salva.
   - Conferir que o link de acompanhamento e a OS impressa não mostram a cor, e que um usuário Técnico não vê o cartão.
6. **Treinar** Consultor e Financeiro: o consultor pede a análise (ou ela já sai pedida ao abrir a OS); o Financeiro registra; qualquer mudança contra a sugestão pede justificativa e fica no histórico (nada se apaga).
