# Especificação — F13-chamado-diaria

**Frente:** Chamado externo e diária de pátio automática (PDF B6, 2º e 3º bullets, mais a parte "caminhão pronto parado além do prazo ganha a diária" da linha Teste).
**Tamanho:** grande (1 serviço novo, 1 auxiliar, 2 modelos, 2 migrations, 1 parcial de tela, cerca de 25 arquivos pequenos alterados, 2 arquivos de teste).
**Legenda:** [EXISTE] já está no código e atende; [PARCIAL] existe mas não atende o PDF; [FALTA] não existe.
**Caminhos:** relativos a `packages/Webkul/ServiceOrder/src`, salvo os que começam por `tests/`, `public/` ou `packages/`.
**Regra de ouro desta frente:** tudo que é novo só age com o piloto ligado (`StageGate::active()`) e com as migrations aplicadas. Com o piloto desligado as telas atuais ficam idênticas, inclusive o botão manual "Lançar na OS" da Rotina. Banco sem as tabelas/colunas novas não gera erro 500: as novidades somem e o resto funciona.

Resumo do desenho:
- Chamado externo: a autorização do cliente passa a **bloquear** o registro da saída da viatura; ganham-se os campos de km e horário (saída e volta); o pré-diagnóstico de 30 min vira **barreira** (a 21ª, fora das 20 do cap. 11.2); CON-17 e CHE-15 passam a medir o que o PDF diz.
- Diária de pátio: um **cálculo idempotente por dia** (roda a cada minuto na `workshop:tick`) que inclui uma linha por diária na OS, avisa o Consultor (alerta sem R$) e permite **isentar** com justificativa e alçada do Gerente, sem apagar nada.
- A diária vira um **tipo de item próprio** (`'Diária de pátio'`), e não mais uma linha de "Serviço" com 1 h. Isso corrige defeitos do que existe hoje (seção "Defeitos e lacunas").

---

## O que já existe

### Chamado externo

- **[EXISTE]** `Services/WorkflowActions.php:54-59` ação `definir_tipo` (select `normal | chamado_externo | preventiva`, etapas Cadastro e Ordem de Serviço) → `setTipo()` em `:329-334`. Só grava `quotes.tipo_os`; não registra evento e não abre prazo nenhum.
- **[EXISTE]** `Services/WorkflowActions.php:225-230` ação `autorizar_chamado` ("Cliente autorizou a saída da viatura", `so_tipos = chamado_externo`, etapas Cadastro/OS/Diagnóstico) → `authorizeCall()` em `:619-626`: grava `quotes.chamado_autorizado_em = now()` e o evento `chamado_autorizado`. **Não bloqueia nada**, e pode ser repetida (sobrescreve o carimbo).
- **[EXISTE]** `Services/WorkflowActions.php:231-237` ação `pre_diagnostico_remoto` (campo `resumo`) → `preDiagnosis()` em `:628-635`: grava `quotes.pre_diagnostico_em` e o evento `pre_diagnostico_remoto`. Sem barreira; também sobrescreve o carimbo.
- **[EXISTE]** Colunas `quotes.tipo_os` (default `'normal'`), `chamado_autorizado_em`, `pre_diagnostico_em` em `Database/Migrations/2026_11_17_100000_extend_barriers_and_workflow_for_mentoria.php:31,50-51`; casts em `Models/ServiceOrder.php:124-125`; rótulos dos 2 eventos em `Models/ServiceOrderEvent.php:83-84`.
- **[PARCIAL]** `Services/Indicators/IndicatorCalculator.php:98` CON-17 = `chamado_autorizado_em` preenchido ÷ chamados criados no período. Não compara com a saída da viatura (que nem existe). `:115` CHE-15 = `pre_diagnostico_em <= created_at + 30 min` com 30 fixo no código: não usa barreira, horário comercial nem o modo de teste ÷10. Definições em `Services/Indicators/IndicatorCatalog.php:58,75`.
- **[FALTA]** Nenhum campo de km/horário da viatura, nenhuma barreira do pré-diagnóstico, nenhum bloqueio, nenhum teste (a busca por `chamado` em `tests/` dá zero resultados). A Ficha grava `ficha_veiculo_externo` (`Services/WorkshopSettings.php:66`, `Http/Controllers/FichaController.php:44,88`, `Resources/views/ficha/edit.blade.php:36-39`), mas **nenhum código lê essa chave**.
- Observação de acesso: o único jeito de marcar uma OS como chamado externo é o painel "Passos desta etapa" (`Resources/views/partials/workflow_actions.blade.php`), que só aparece com o piloto ligado (`Resources/views/view.blade.php:324-326` e `partials/workflow.blade.php:40`). Nenhum formulário de criação grava `tipo_os` (`ServiceOrder::$fillable` nem tem a coluna). Logo, com o piloto desligado, não existe chamado externo na prática.

### Diária de pátio

- **[EXISTE]** Ficha: chaves `diaria_patio_valor`, `diaria_patio_horas_pronto`, `diaria_patio_horas_nao_autorizado` (`Services/WorkshopSettings.php:68-70`, default `null`), validação e gravação em `Http/Controllers/FichaController.php:46-48,76-82`, campos em `Resources/views/ficha/edit.blade.php:48-50`. `null` = não cobra (estado do servidor hoje).
- **[PARCIAL]** `Services/Rotina/RotinaService.php:356-389` `diariasDePatio()`: lista OS que passaram do prazo: "pronto" = `checklist_saida_em` preenchido e `saida_em` nulo; "orçamento não autorizado" = `orcamento_enviado_em` preenchido e `aprovacao_em` **nulo** (recusa fica de fora) e `saida_em` nulo. Dias = `ceil(horas além do prazo ÷ 24)` (`diasAlemDoPrazo`, `:483-492`), em horas corridas. Não olha modo de teste, dia fechado, OS sem veículo, retorno/garantia nem chamado externo.
- **[PARCIAL]** `Services/Rotina/RotinaService.php:394-414` `lancarDiaria()`: só roda quando alguém clica (rota `admin.rotina.diaria`, `Routes/web.php:217`, `Http/Controllers/RotinaController.php:51-61`, ACL `rotina_painel` em `Config/acl.php:182-185`, botão em `Resources/views/rotina/painel.blade.php:43-49`). Cria **uma** linha "Diária de pátio (N dia(s), motivo)" com todos os dias de uma vez e **nunca mais** (`:404` recusa se já existe linha com esse nome): os dias seguintes nunca entram na OS.
- **[PARCIAL]** `Services/Rotina/RotinaService.php:259,266` o painel do Consultor mostra a seção "Diária de pátio a cobrar" (sem alerta).
- **[EXISTE]** Teste atual: `tests/Feature/Workshop/RotinaTest.php:109-162` (cálculo de 60 h com carência de 48 h = 1 diária de R$ 150; botão lança uma vez e a segunda tentativa dá erro; Técnico leva 403). **Deve continuar passando.**
- **[FALTA]** Job automático, aviso ao Consultor, isenção, alçada, modo de teste, opção de contar ou não dias fechados.

### Defeitos e lacunas do que existe (afetam o desenho)

1. A linha lançada hoje é `tipo = 'Serviço'` com `hours = 1`, criada por `ServiceOrderItemService::addItem` (`Services/ServiceOrderItemService.php:39,51-53`). Como `ServiceOrder::operations()` (`Models/ServiceOrder.php:327-330`) é "todo item Serviço", a diária vira uma **operação de oficina**:
   - `StageGate::execucaoParaTeste` (`Services/StageGate.php:235-239` → `OperationService::pendingOperations`, `:222-227`) trava Execução → Veículo em Teste com "Operação sem início/fim apontado: Diária de pátio…" (a diária de orçamento não autorizado nasce antes da execução);
   - `TecnicoTasks::operacoes` (`Services/TecnicoTasks.php:90-98`) mostra a diária como tarefa do técnico;
   - `WorkflowActions::exitChecklist` (`:502-505`) soma 1 h de tempo padrão; `IndicatorCalculator.php:616,711` somam as horas;
   - `DreService.php:79` a conta como receita de mão de obra (distorce DRE-06).
2. `addItem` põe `funcionario_id = mecânico da OS` (`ServiceOrderItemService.php:39`): `CommissionReportService.php:47-81` paga comissão de **execução** ao técnico sobre uma diária, e o consultor ganha comissão de **venda** sobre ela (`:83-101`).
3. A linha aceita desconto: `ServiceOrderStageController::updateNegociacao` (`:155-166`) mostra o campo para todo item. Um desconto de 100% na diária equivale a isentar **sem** a alçada do Gerente (e o cálculo de % da alçada, `:198-212`, ainda dilui a diária no bruto). O botão "×" (`Resources/views/view.blade.php:204-212`; `ServiceOrderController::destroyItem`, `:349-358`) apaga a linha.
4. A impressão separa itens só em Serviços e Peças (`Resources/views/print.blade.php:38-39,215-255`): um tipo novo ficaria fora das tabelas, mas dentro do Total.

---

## Requisitos do PDF → como serão atendidos

| # | Requisito (PDF B6) | Situação hoje | Como será atendido |
|---|---|---|---|
| 1 | Chamado externo: **autorização do cliente antes da saída da viatura**, com bloqueio e piloto ligado | PARCIAL: registro existe (`autorizar_chamado`); a saída da viatura não existe; nada bloqueia | Nova ação `saida_viatura`. Com o piloto ligado e sem `chamado_autorizado_em` ela lança `ValidationException` ("A viatura só pode sair depois da autorização do cliente…") e nada é gravado. Piloto desligado: sem bloqueio. As ações `autorizar_chamado` e `pre_diagnostico_remoto` deixam de poder ser repetidas (carimbo estável). |
| 2 | **Pré-diagnóstico remoto em 30 min** (barreira; ÷10 no modo de teste) | PARCIAL: ação existe; 30 min fixo só no CHE-15 | `setTipo` passa a gravar o evento `chamado_externo_aberto`, que abre a nova barreira `pre_diagnostico_chamado` (30 min, relógio corrido, Chefe no nível 1, Gerente +30 min). O motor de barreiras já trata horário comercial/relógio corrido por regra e herdará o ÷10 que a F03 criar. CHE-15 passa a ler essa barreira. |
| 3 | **Km e horário da viatura** (saída/volta) | FALTA | 5 colunas novas em `quotes`; ações `saida_viatura` e `volta_viatura` (horário = agora, km informado, km de volta ≥ km de saída); eventos na linha do tempo; cartão "Chamado externo" na OS; regra em `StageGate` exigindo a volta antes do Faturamento quando a viatura saiu. |
| 4 | Chamado externo **alimenta CON-17 e CHE-15** | PARCIAL: existem, medem outra coisa | CON-17: autorização registrada e anterior à saída da viatura (quando houver saída). CHE-15: lê a barreira `pre_diagnostico_chamado`, o mesmo número que gerou os alertas. |
| 5 | Diária de pátio: **calcular** a partir das horas da Ficha (após pronto, ou orçamento não autorizado) | PARCIAL: cálculo sob demanda | `DiariaPatioService` calcula sozinho a cada minuto (tick), por dia de pátio, com modo de teste ÷10 e opção "contar dias fechados" na Ficha. |
| 6 | **Avisar o Consultor** | FALTA | Alerta `diaria_patio` ao Consultor da OS, sem valores em R$ (um por rodada em que entrou diária nova). |
| 7 | **Incluir o valor na OS** | PARCIAL: botão manual, uma vez, como "Serviço" | Uma linha por diária (`tipo = 'Diária de pátio'`, qtd 1, preço = valor da Ficha no dia), idempotente por `(OS, motivo, ordinal)` com índice único; recálculo diário; totais da OS recalculados. O botão da Rotina passa a significar "lançar agora" e usa o mesmo serviço. |
| 8 | Opção de **isentar (alçada do Gerente)**, sem apagar | FALTA | Ação `isentar_diaria`: justificativa obrigatória (mín. 10 letras), assunto de alçada `isencao_diaria_patio` (Consultor pede, Gerente/Proprietário decidem; quem é Gerente executa direto). Cria um registro novo de isenção; as linhas ficam na OS com valor zero ("— isenta") e o valor original permanece no registro. Nada é apagado e a OS para de acumular diária. |
| 9 | Teste: **caminhão pronto parado além do prazo ganha a diária** (e o consultor é avisado) | FALTA como fluxo automático | Testes `DiariaPatioTest` (1 a 3). |
| 10 | Considerar **horário comercial / fim de semana** e o **modo de teste ÷10** | FALTA | Chave da Ficha `diaria_patio_dias_corridos` (padrão: sim = dias corridos, como já funciona). Modo de teste: carência e duração do "dia de pátio" divididas pelo divisor da F03 (24 h vira 2 h 24 min). |

Roteiro de 72 linhas: **nenhuma linha trata de chamado externo ou diária**. As mais próximas são 45 (checklist de saída = a OS fica "pronta" e o relógio da diária começa), 46 a 50 (Faturamento/saída, F09) e 53 (OS de retorno, F12, que **não** cobra diária). Proposta de 4 linhas novas no fim do roteiro está em "Passo a passo para o usuário".

---

## Decisões de projeto (por que assim)

1. **Tipo de item próprio (`TIPO_DIARIA = 'Diária de pátio'`)**, e não linha de "Serviço" marcada. Por construção a diária some de `operations()` (execução, Minhas tarefas, horas padrão, produtividade), cai em "Outras receitas" da DRE (`DreService.php:81` já calcula `receita_outras = grand_total − MO − peças`) e não é vista como "serviço com valor de hora" pelas regras de F08. Em troca: impressão, tela da OS, comissão, Negociação e (se a contabilidade confirmar) NFS-e precisam conhecer o tipo (mudanças listadas abaixo).
2. **Uma linha por diária + registro (ledger) por diária.** A chave `(quote_id, motivo, ordinal)` torna o cálculo idempotente no banco. Mudar a Ficha depois não reescreve diárias antigas (cada uma guarda o valor do dia).
3. **Isenção é um registro novo** (`service_order_diaria_isencoes`), não uma edição: as diárias recebem o `isencao_id` uma única vez, o preço da linha vai a zero (não usamos desconto: ele polui os indicadores de desconto e é regravado pela Negociação) e o valor original fica no registro. Isentar vale para a OS inteira (todas as diárias lançadas e as futuras). Pode-se isentar antes da primeira diária (isenção preventiva).
4. **Aviso ao Consultor por alerta** (`AlertService::raise`), não por regra de barreira: a diária não é um prazo contado a partir de um passo.
5. **Cálculo roda na tick** (a cada minuto, só em oficinas com piloto ligado), reaproveitando a infraestrutura de `WorkshopTickCommand`. Nenhuma oficina com piloto desligado é tocada.
6. **Quais OS cobram**: só `tipo_os` `normal` e `preventiva`, com veículo, sem saída, fora de Concluída/Cancelada. Retorno/garantia (sem cobrança por padrão, PDF B6) e chamado externo (o caminhão não está no pátio) ficam fora. Ver decisão 1.
7. **"Pronto"** continua sendo `checklist_saida_em` (como no código atual) e **"não autorizado"** passa a incluir a recusa (o caminhão continua no pátio). Ver decisão 2.

### Ordem de implementação sugerida

1. Migrations, os 2 modelos novos, `ServiceOrderItem::TIPO_DIARIA/isDiariaPatio()/booted()`, `ServiceOrder::valorSemDiarias()`, novos tipos em `ServiceOrderEvent::TIPOS`, chave `diaria_patio_dias_corridos`, tema de alçada, tradução do tipo. Aplicar as migrations no `krayin_test`.
2. Chamado externo: `ChamadoExterno`, `WorkflowActions` (condições, `setTipo`, viatura), barreira `pre_diagnostico_chamado`, `StageGate`, CON-17/CHE-15, cartão na OS. Escrever `ChamadoExternoTest` (e atualizar a contagem de barreiras).
3. Diária: `ServiceOrderItemService::addDiaria`, `DiariaPatioService`, tick, `RotinaService`/painel, travas de desconto/remoção, comissão, CON-09, impressão, Negociação. Escrever `DiariaPatioTest`; rodar `RotinaTest` (deve passar sem edição).
4. Ficha (campo e teste), `pint` nos arquivos tocados, `--filter=Workshop`.

---

## Mudanças por arquivo

### Criar

#### 1. `Services/DiariaPatioService.php` (novo)

Responsabilidade única da diária. Dependências resolvidas com `app()` dentro dos métodos (evita ciclo com `AlcadaService`/`AlertService`).

```php
namespace Webkul\ServiceOrder\Services;

class DiariaPatioService
{
    public const MOTIVO_PRONTO = 'pronto';
    public const MOTIVO_NAO_AUTORIZADO = 'nao_autorizado';
    public const MOTIVOS = [
        self::MOTIVO_PRONTO => 'pronto e não retirado',
        self::MOTIVO_NAO_AUTORIZADO => 'orçamento não autorizado',
    ];
    /** Só estes tipos de OS cobram diária automática. */
    public const TIPOS_QUE_COBRAM = ['normal', 'preventiva'];

    public static function disponivel(): bool;                       // as 2 tabelas existem
    public static function simularDisponibilidade(?bool $valor): void; // só para testes; null esquece o memo
    public static function ativa(): bool;                            // StageGate::active() && disponivel()

    public function config(): array;
    public function candidatas(): Collection;                        // OS elegíveis
    public function motivoDe(ServiceOrder $os, ?array $cfg = null): ?string;
    public function iniciosDevidos(ServiceOrder $os, string $motivo, ?Carbon $agora = null, ?array $cfg = null): array;
    public function processar(ServiceOrder $os, ?Carbon $agora = null): array;  // ['novas' => int, 'itens' => Collection]
    public function processarTodas(?Carbon $agora = null): int;      // nº de OS que ganharam diária nova
    public function lancadas(ServiceOrder $os): Collection;          // ServiceOrderDiaria da OS
    public function isentada(ServiceOrder $os): bool;
    public function podeIsentar(ServiceOrder $os): bool;
    public function isentar(ServiceOrder $os, string $justificativa, ?User $user = null): ServiceOrderDiariaIsencao;
    public function painel(): Collection;                            // formato do antigo diariasDePatio()
    public function resumo(ServiceOrder $os): ?array;               // para a tela da OS (null = nada a mostrar)
}
```

Regras e algoritmos (seguir à risca):

- **`disponivel()`**: `Schema::hasTable('service_order_diarias') && Schema::hasTable('service_order_diaria_isencoes')`, memoizado em `static array $disponivel` **indexado por `DB::connection()->getDatabaseName()`** (a `workshop:tick` percorre várias oficinas no mesmo processo, então um `static bool` simples erraria). Mesmo padrão de `BarrierRule::$seeded`. `simularDisponibilidade(false)` grava `false` para o banco atual; `simularDisponibilidade(null)` zera o array.
- **`config()`** devolve:
  - `valor` = `round((float) (WorkshopSettings::get('diaria_patio_valor') ?? 0), 2)`;
  - `horas` = `['pronto' => WorkshopSettings::get('diaria_patio_horas_pronto'), 'nao_autorizado' => WorkshopSettings::get('diaria_patio_horas_nao_autorizado')]` (`null` = não cobra aquele motivo; `0` é válido e significa "cobra desde o primeiro instante");
  - `corridos` = `(bool) WorkshopSettings::get('diaria_patio_dias_corridos', true)`;
  - `divisor` = `max(1.0, (float) WorkshopSettings::divisorDePrazos())` (contrato com F03, ver "Riscos");
  - `minutos_por_dia` = `1440 / divisor`.
- **`elegivel(OS)`** (protegido): `vehicle_id` preenchido, `saida_em` nulo, `workshop_status` fora de `[Concluída, Cancelada]`, `tipo_os` (ou `'normal'` se vazio) em `TIPOS_QUE_COBRAM`.
- **`candidatas()`**: `ServiceOrder::query()` com `whereNotNull('quotes.vehicle_id')`, `whereNull('quotes.saida_em')`, `whereNotIn('quotes.workshop_status', [...])`, `whereIn('quotes.tipo_os', TIPOS_QUE_COBRAM)` e `where(fn ($q) => $q->whereNotNull('quotes.checklist_saida_em')->orWhere(fn ($w) => $w->whereNotNull('quotes.orcamento_enviado_em')->where(fn ($x) => $x->whereNull('quotes.aprovacao_em')->orWhere('quotes.aprovacao_tipo', 'recusa'))))`, com `with('vehicle:id,placa')`.
- **`motivoDe(OS)`**: se `checklist_saida_em` e `horas['pronto'] !== null` → `pronto`; senão, se `orcamento_enviado_em` e (`aprovacao_em` nulo **ou** `aprovacao_tipo === 'recusa'`) e `horas['nao_autorizado'] !== null` → `nao_autorizado`; senão `null`. Nunca dois motivos ao mesmo tempo (um dia de pátio é cobrado uma vez).
- **`iniciosDevidos()`** (devolve a lista de `Carbon`, o ordinal é posição + 1):

```php
$base = $motivo === self::MOTIVO_PRONTO ? $os->checklist_saida_em : $os->orcamento_enviado_em;
$horas = $cfg['horas'][$motivo];
if ($horas === null || ! $base) { return []; }

$diaSeg = (int) round($cfg['minutos_por_dia'] * 60);                              // 86400; 8640 no modo de teste
$primeiro = $base->copy()->addSeconds((int) round($horas * 3600 / $cfg['divisor'])); // fim da carência = início da 1ª diária
$decorrido = $agora->getTimestamp() - $primeiro->getTimestamp();
if ($decorrido <= 0) { return []; }

$blocos = (int) ceil($decorrido / $diaSeg);          // cada bloco INICIADO conta (como o ceil de hoje)
$horario = new BusinessHours;
$inicios = [];
for ($k = 0; $k < $blocos; $k++) {
    $inicio = $primeiro->copy()->addSeconds($k * $diaSeg);
    if ($cfg['corridos'] || $horario->isBusinessDay($inicio)) { $inicios[] = $inicio; }
}
return $inicios;
```

  `BusinessHours::isBusinessDay` (`Services/BusinessHours.php:164-167`) usa o `horario_comercial` da oficina (sábado de manhã conta como dia de funcionamento; feriados não são tratados, o Gerente isenta).
- **`temLinhaLegada(OS)`** (protegido): `$os->items()->where('tipo', ServiceOrderItem::TIPO_SERVICO)->where('name', 'like', 'Diária de pátio%')->exists()`. OS que ganhou a linha antiga (lançada com o piloto desligado) **não** recebe a automática, para não cobrar em dobro.
- **`processar(OS, ?agora)`**: se `! static::ativa()` ou `config()['valor'] <= 0` → `['novas' => 0, 'itens' => collect()]`. Senão, dentro de `DB::transaction`, travar a OS (`ServiceOrder::whereKey($os->id)->lockForUpdate()->first()`, serializa tick × botão, mesmo recurso de `BarrierEngine::claimLevel`) e: sair se `! elegivel`, `isentada`, `temLinhaLegada` ou `motivoDe` nulo; calcular `iniciosDevidos`; ler os ordinais já existentes em `service_order_diarias` para `(OS, motivo)`; para cada ordinal que falta: criar a linha com `app(ServiceOrderItemService::class)->addDiaria($os, $this->descricao($ordinal, $motivo, $inicio), $cfg['valor'])` e o registro `ServiceOrderDiaria::create([quote_id, item_id, motivo, ordinal, inicio_em, valor, calculada_em])`. Se criou algo: `app(ServiceOrderRepository::class)->recalculateTotals($os)` **uma vez** e `ServiceOrderEvent::record($os, 'diaria_patio_incluida', ['motivo' => $motivo, 'ordinais' => [...], 'total_dias' => count($inicios), 'origem' => auth()->guard('user')->check() ? 'manual' : 'automatica'])` (sem valores em R$). **Fora** da transação (para o aviso não sair de uma transação desfeita): `avisarConsultor()`.
- **`descricao($ordinal, $motivo, $inicio)`**: `'Diária de pátio nº '.$ordinal.' ('.self::MOTIVOS[$motivo].', desde '.$inicio->format('d/m/Y H:i').')'` (a data/hora evita nome repetido no modo de teste, onde vários "dias" cabem no mesmo dia).
- **`avisarConsultor(OS, motivo)`**: lê os registros da OS com `avisada_em` nulo; se não houver, sai. Chama `app(AlertService::class)->raise('diaria_patio', 'Diária de pátio', $mensagem, ['consultor'], $os, responsavel: $os->user?->name ?? '-')` e marca `avisada_em = now()` nesses registros **mesmo se ninguém recebeu** (evita insistir a cada minuto). Mensagem: `"ALERTA - Diária de pátio. OS #{id} - {placa} - {cliente}. {n} diária(s) de pátio incluída(s) na OS ({motivo}); {total} dia(s) no total. Responsável: {nome}."` Nunca inclui `R$`. `RecipientResolver::consultor` já devolve o Consultor dono da OS, ou todos os Consultores se o dono da OS não tem esse papel (`Services/RecipientResolver.php:62-69`). O Proprietário nunca recebe (alerta não crítico, `AlertService::raise` já o filtra).
- **`processarTodas(?agora)`**: `if (! static::ativa() || config()['valor'] <= 0) return 0;` e, para cada `candidatas()`, `processar()`; soma as OS com `novas > 0`.
- **`podeIsentar(OS)`**: `StageGate::active() && disponivel() && ! isentada($os)` e OS fora de Concluída/Cancelada e (`lancadas($os)->isNotEmpty()` **ou** (`elegivel($os)` e `motivoDe($os) !== null`)). A segunda parte permite a isenção preventiva.
- **`isentar(OS, justificativa, ?User)`**:
  1. `ValoresFinanceiros::authorize('isenção de diária de pátio')` (Técnico: 403).
  2. Exigir `StageGate::active()` e `disponivel()`; justificativa `trim` com pelo menos 10 caracteres (`ValidationException` em `justificativa`: "Explique o motivo da isenção (mínimo de 10 letras)."); `! isentada($os)` (senão "A diária de pátio desta OS já foi isentada.").
  3. **Antes de abrir a transação** (o pedido de alçada precisa sobreviver ao bloqueio): `app(AlcadaService::class)->authorize('isencao_diaria_patio', $os, null, 'Isenção de diária de pátio ('.$n.' diária(s) lançada(s)): '.$justificativa, $user)`. Valor `null` de propósito: o tema é "sempre o Gerente decide" (`tipo = 'gerente'`) e a aprovação não "vence" se entrarem mais diárias entre o pedido e a decisão (`AlcadaService::consumeApproval` só compara valor quando ele existe). Consultor cai na fila do Gerente e a ação lança `ValidationException` ("passa da sua alçada… repita a ação depois da aprovação"); Gerente/Proprietário passam direto.
  4. `$pedidoId = AlcadaPedido::where('tema', 'isencao_diaria_patio')->where('service_order_id', $os->id)->where('status', AlcadaPedido::CONSUMIDO)->orderByDesc('consumido_em')->value('id')` (nulo quando o Gerente executou direto).
  5. `DB::transaction`: travar a OS; reler os registros sem `isencao_id`; criar `ServiceOrderDiariaIsencao` (`quote_id`, `justificativa`, `user_id`, `alcada_pedido_id`, `dias`, `valor_isento` = soma dos `valor`); para cada registro: `isencao_id = isencao.id` e, na linha da OS, `price = 0`, `total = 0`, `name = mb_substr($name, 0, 240).' — isenta'`; `recalculateTotals`; `ServiceOrderEvent::record($os, 'diaria_patio_isenta', ['dias' => n, 'justificativa' => ..., 'pedido_id' => ..., 'isencao_id' => ...])` (sem R$).
  6. Devolve a isenção. Depois dela `processar()` não cria mais nada para a OS (`isentada`).
- **`painel()`**: para cada candidata não isenta e sem linha legada, com `motivoDe` e `count(iniciosDevidos) > 0`: `['os', 'placa', 'motivo' => MOTIVOS[...], 'dias' => devidas, 'lancadas' => n, 'pendentes' => max(0, devidas − lancadas), 'valor' => round(soma dos valores já lançados + pendentes × valor atual, 2)]` (float). É o formato do antigo `diariasDePatio()` mais as 2 chaves novas; `RotinaService` o reaproveita.
- **`resumo(OS)`**: `null` se `! disponivel()` ou sem registros e sem isenção; senão `['linhas' => [['ordinal', 'motivo', 'inicio', 'valor', 'situacao' => 'cobrada'|'isenta']], 'total_cobrado' => float, 'total_isento' => float, 'isencao' => ?['em', 'por', 'justificativa']]`.

#### 2. `Services/ChamadoExterno.php` (novo, auxiliar pequeno)

```php
class ChamadoExterno
{
    public const TIPO = 'chamado_externo';
    public static function disponivel(): bool;                      // Schema::hasColumn('quotes', 'chamado_saida_em'), memo POR BANCO
    public static function simularDisponibilidade(?bool $valor): void;
    public static function kmRodados(ServiceOrder $os): ?int;       // chamado_km_volta − chamado_km_saida, ou null
    public static function resumo(ServiceOrder $os): ?array;        // null se a OS não é chamado externo ou o banco não tem as colunas
}
```

`resumo()` devolve `autorizado_em`, `pre_diagnostico_em`, `prazo_pre_diagnostico` (o `vence_em` da última `BarrierInstance` de `pre_diagnostico_chamado` da OS) e `pre_diagnostico_no_prazo` (`cumprida_no_prazo` dessa instância, ou `null`), `viatura`, `saida_em`, `km_saida`, `volta_em`, `km_volta`, `km_rodados`. Usado pelo parcial de tela.

#### 3. `Models/ServiceOrderDiaria.php` e 4. `Models/ServiceOrderDiariaIsencao.php` (novos)

- `ServiceOrderDiaria` (tabela `service_order_diarias`, `$guarded = []`, casts `inicio_em`/`calculada_em`/`avisada_em` datetime, `valor` float). Em `booted()`: `deleting` → `LogicException('Diárias de pátio não podem ser apagadas; use a isenção.')`; `updating` → só aceita mudar `avisada_em`, `item_id` e `isencao_id`, e cada um **uma única vez** (valor original `null`); qualquer outra mudança → `LogicException('Uma diária de pátio lançada não pode ser alterada.')`. Mesmo estilo de `Models/AlcadaPedido.php:39-52`. Helper `isenta(): bool` (`isencao_id !== null`).
- `ServiceOrderDiariaIsencao` (tabela `service_order_diaria_isencoes`, `const CREATED_AT = 'created_at'`, `const UPDATED_AT = null`, `$guarded = []`): `updating` e `deleting` lançam `LogicException` (somente inserção, como `MentoriaRegistro`/`ServiceOrderEvent`).

#### 5. `Database/Migrations/2026_11_18_130000_add_chamado_externo_columns_to_quotes_table.php` e 6. `2026_11_18_130100_create_service_order_diarias_tables.php`

Detalhes na seção "Banco (migrations)".

#### 7. `Resources/views/partials/chamado_diaria.blade.php` (novo parcial, só exibição)

Incluído em `partials/workflow.blade.php` (ver abaixo). Duas áreas independentes, cada uma só aparece se houver o que mostrar:

- **Cartão "Chamado externo"** quando `ChamadoExterno::resumo($serviceOrder)` não é nulo: linhas "Autorização do cliente" (feita em `d/m/Y H:i` ou "pendente: a viatura não sai sem ela"), "Pré-diagnóstico remoto" (feito em ... com selo "no prazo"/"fora do prazo", ou "pendente até hh:mm" a partir de `prazo_pre_diagnostico`), "Viatura" (identificação), "Saída" (data/hora e km), "Volta" (data/hora e km) e "Km rodados". Os formulários não ficam aqui: são os do painel "Passos desta etapa" (`partials/workflow_actions.blade.php`), que lê `WorkflowActions::availableFor`.
- **Cartão "Diária de pátio"** só se `$canSeeValues` e `DiariaPatioService::resumo($serviceOrder)` não é nulo: tabela (nº, motivo, "desde", valor, situação cobrada/isenta), totais cobrado e isento, e, se isenta, "Isenta em ... por ...: justificativa". O botão de isentar também é a ação `isentar_diaria` do painel de passos.

Estilo e classes Tailwind iguais aos de `partials/workflow.blade.php` (`$card`, `$title`, `$label`).

#### 8. `tests/Feature/Workshop/ChamadoExternoTest.php` e 9. `tests/Feature/Workshop/DiariaPatioTest.php`

Ver "Testes Pest a escrever".

### Alterar

#### `Models/ServiceOrderItem.php`
- `const TIPO_DIARIA = 'Diária de pátio';` e `public function isDiariaPatio(): bool { return $this->tipo === self::TIPO_DIARIA; }`.
- `protected static function booted(): void` com:
  - `saving`: se `isDiariaPatio()`, `discount_percent = 0` e `discount_amount = 0` (a diária não recebe desconto; a única forma de não cobrar é a isenção);
  - `deleting`: se `isDiariaPatio()` → `LogicException('A diária de pátio não pode ser apagada; use "Isentar a diária de pátio", que exige justificativa.')`.
- `getEffectiveQuantityAttribute` já devolve `quantity` para tipos diferentes de Serviço (`:77-82`): nenhuma mudança.

#### `Models/ServiceOrder.php`
- Casts novos em `$casts` (`:100-127`): `'chamado_saida_em' => 'datetime'`, `'chamado_volta_em' => 'datetime'` (inofensivos se a coluna não existir).
- `public function valorSemDiarias(): float`: `round(max(0, (float) $this->grand_total − $this->items->filter(fn ($i) => $i->isDiariaPatio())->sum(fn ($i) => $i->line_total)), 2)`. Usado por CON-09 e pela comissão de venda.

#### `Models/ServiceOrderEvent.php` (`TIPOS`, `:26-96`)
Acrescentar: `chamado_externo_aberto` ('Chamado externo aberto'), `chamado_externo_desfeito` ('Chamado externo desfeito (o tipo da OS mudou)'), `chamado_viatura_saida` ('Saída da viatura'), `chamado_viatura_volta` ('Volta da viatura'), `diaria_patio_incluida` ('Diária de pátio incluída na OS'), `diaria_patio_isenta` ('Diária de pátio isentada'). Sem isso `ServiceOrderEvent::record` lança `InvalidArgumentException`.

#### `Models/BarrierRule.php` (`DEFAULTS`, `:53-329`)
Acrescentar (depois de `reclamacao`, antes dos críticos) a barreira:

```php
[
    'codigo' => 'pre_diagnostico_chamado',
    'nome' => 'Pré-diagnóstico do chamado externo',
    'etapa' => 'Cadastro',
    'evento_inicio' => 'chamado_externo_aberto',
    'evento_fim' => 'pre_diagnostico_remoto,chamado_externo_desfeito',
    'modo' => self::MODO_MINUTOS,
    'prazo_minutos' => 30,
    'nivel1_destino' => 'chefe',
    'nivel2_apos_minutos' => 30,
    'nivel2_destino' => 'gerente',
    // Chamado pode chegar à noite ou no fim de semana: 30 min corridos.
    'conta_horario_comercial' => false,
    'critico' => false,
],
```
`BarrierRule::ensureDefaults()` (`:347-354`) semeia sozinha em cada oficina, sem migration. O motor (`BarrierEngine`) já aplica `conta_horario_comercial` (`:179-189`); o ÷10 vem da F03. Passam a ser **21** barreiras (ver teste a atualizar).

#### `Services/WorkflowActions.php`
- `definitions()`: documentar as chaves novas no docblock (`condicao`, `so_piloto`) e:
  - `autorizar_chamado`: acrescentar `'condicao' => 'chamado_sem_autorizacao'` e `'ajuda' => 'Obrigatório antes de a viatura sair: sem isto o sistema não deixa registrar a saída.'`.
  - `pre_diagnostico_remoto`: acrescentar `'condicao' => 'chamado_sem_pre_diagnostico'` e `'ajuda' => 'Meta: até 30 minutos depois da abertura do chamado (o prazo é o da barreira em Configurações > Indicadores e alertas).'`.
  - **novas**:

```php
'saida_viatura' => [
    'label' => 'Registrar saída da viatura',
    'etapas' => $etapas(ServiceOrder::STAGE_CADASTRO, ServiceOrder::STAGE_ORDEM_SERVICO, ServiceOrder::STAGE_DIAGNOSTICO),
    'campos' => [
        'km_saida' => ['label' => 'Km da viatura na saída', 'tipo' => 'number'],
        'viatura' => ['label' => 'Viatura (placa ou nome)', 'tipo' => 'text', 'opcional' => true],
    ],
    'regras' => ['km_saida' => 'required|integer|min:0|max:9999999', 'viatura' => 'nullable|string|max:60'],
    'so_tipos' => ['chamado_externo'],
    'condicao' => 'viatura_nao_saiu',
    'ajuda' => 'A viatura só sai com a autorização do cliente registrada. O horário gravado é o de agora.',
],
'volta_viatura' => [
    'label' => 'Registrar volta da viatura',
    'etapas' => null,
    'campos' => ['km_volta' => ['label' => 'Km da viatura na volta', 'tipo' => 'number']],
    'regras' => ['km_volta' => 'required|integer|min:0|max:9999999'],
    'so_tipos' => ['chamado_externo'],
    'condicao' => 'viatura_em_campo',
    'ajuda' => 'Grava o horário de agora e calcula os km rodados.',
],
'isentar_diaria' => [
    'label' => 'Isentar a diária de pátio',
    'etapas' => null,
    'campos' => ['justificativa' => ['label' => 'Por que isentar? (fica registrado)', 'tipo' => 'text']],
    'regras' => ['justificativa' => 'required|string|min:10|max:500'],
    'valores' => true,
    'so_piloto' => true,
    'condicao' => 'tem_diaria',
    'ajuda' => 'Quem não é Gerente pede a aprovação na fila de alçadas e repete a ação depois. A diária não é apagada: o valor sai da OS e o registro fica.',
],
```
- `isAvailable()` (`:253-276`): manter as verificações atuais e, **depois delas**, acrescentar:

```php
if (! empty($definition['so_piloto']) && ! StageGate::active()) { return false; }

return match ($definition['condicao'] ?? null) {
    null => true,
    'chamado_sem_autorizacao' => ! $serviceOrder->chamado_autorizado_em,
    'chamado_sem_pre_diagnostico' => ! $serviceOrder->pre_diagnostico_em,
    'viatura_nao_saiu' => ChamadoExterno::disponivel() && ! $serviceOrder->chamado_saida_em,
    'viatura_em_campo' => ChamadoExterno::disponivel() && $serviceOrder->chamado_saida_em && ! $serviceOrder->chamado_volta_em,
    'tem_diaria' => app(DiariaPatioService::class)->podeIsentar($serviceOrder),
    default => true,
};
```
- `perform()` (`:294-319`): três braços novos no `match`: `'saida_viatura' => $this->vehicleOut($os, $dados)`, `'volta_viatura' => $this->vehicleBack($os, $dados)`, `'isentar_diaria' => $this->exemptYardFee($os, $dados['justificativa'])`.
- `setTipo()` (`:329-334`) passa a:

```php
protected function setTipo(ServiceOrder $os, string $tipo): string
{
    $antes = $os->tipo_os;

    // Chamado que já tem autorização ou viatura na rua não volta a ser outro tipo (nada se apaga).
    if ($antes === 'chamado_externo' && $tipo !== $antes && ($os->chamado_autorizado_em || $os->chamado_saida_em)) {
        throw ValidationException::withMessages(['tipo_os' => ['Este chamado externo já tem autorização ou viatura registrada: o tipo de atendimento não pode mais mudar.']]);
    }

    $os->forceFill(['tipo_os' => $tipo])->save();

    if ($tipo === 'chamado_externo' && $antes !== $tipo) {
        ServiceOrderEvent::record($os, 'chamado_externo_aberto');      // abre a barreira de 30 min
    }

    if ($antes === 'chamado_externo' && $tipo !== $antes) {
        ServiceOrderEvent::record($os, 'chamado_externo_desfeito');    // fecha a barreira
    }

    return 'Tipo de atendimento atualizado.';
}
```
- Novos métodos protegidos:

```php
protected function vehicleOut(ServiceOrder $os, array $dados): string
{
    if (StageGate::active() && ! $os->chamado_autorizado_em) {
        throw ValidationException::withMessages(['acao' => ['A viatura só pode sair depois da autorização do cliente. Registre "Cliente autorizou a saída da viatura" antes.']]);
    }

    $os->forceFill([
        'chamado_saida_em' => now(),
        'chamado_km_saida' => (int) $dados['km_saida'],
        'chamado_viatura' => $dados['viatura'] ?? null,
    ])->save();

    ServiceOrderEvent::record($os, 'chamado_viatura_saida', ['km' => (int) $dados['km_saida'], 'viatura' => $dados['viatura'] ?? null]);

    return 'Saída da viatura registrada.';
}

protected function vehicleBack(ServiceOrder $os, array $dados): string
{
    if ((int) $dados['km_volta'] < (int) $os->chamado_km_saida) {
        throw ValidationException::withMessages(['km_volta' => ['O km de volta não pode ser menor que o km de saída ('.(int) $os->chamado_km_saida.').']]);
    }

    $os->forceFill(['chamado_volta_em' => now(), 'chamado_km_volta' => (int) $dados['km_volta']])->save();

    ServiceOrderEvent::record($os, 'chamado_viatura_volta', ['km' => (int) $dados['km_volta'], 'km_rodados' => ChamadoExterno::kmRodados($os->refresh())]);

    return 'Volta da viatura registrada. Km rodados: '.ChamadoExterno::kmRodados($os).'.';
}

protected function exemptYardFee(ServiceOrder $os, string $justificativa): string
{
    $isencao = app(DiariaPatioService::class)->isentar($os, $justificativa);

    return $isencao->dias > 0
        ? 'Diária de pátio isentada ('.$isencao->dias.' diária(s)). Nenhuma nova diária será lançada nesta OS.'
        : 'Isenção registrada: esta OS não pagará diária de pátio.';
}
```
Com o piloto desligado, `vehicleOut` não bloqueia (o `StageGate::active()` falso deixa passar). Importante: `isAvailable` já impede repetir `autorizar_chamado`/`pre_diagnostico_remoto`/`saida_viatura`/`volta_viatura` (mensagem genérica "Essa ação não está disponível agora…"), então não é preciso guardar o carimbo dentro de `authorizeCall`/`preDiagnosis`.

#### `Services/StageGate.php`
Em `testeParaFaturamento()` (`:253-286`), antes do `return $missing;`:

```php
// Chamado externo: se a viatura saiu, a volta (km e horário) precisa estar registrada.
if ($os->tipo_os === 'chamado_externo' && $os->chamado_saida_em && ! $os->chamado_volta_em) {
    $missing[] = 'Volta da viatura do chamado externo (km e horário)';
}
```
Aparece na lista "O que falta" do botão Avançar e pode ser forçada com justificativa, como qualquer bloqueio de etapa (`StageAdvanceService::advance`).

#### `Services/ServiceOrderItemService.php`
Novo método (não mexe em estoque, comissão nem horas):

```php
public function addDiaria(ServiceOrder $serviceOrder, string $descricao, float $valor): ServiceOrderItem
{
    $item = new ServiceOrderItem;
    $item->quote_id = $serviceOrder->id;
    $item->product_id = 0;
    $item->tipo = ServiceOrderItem::TIPO_DIARIA;
    $item->name = $descricao;
    $item->sku = 'DIA-PATIO';
    $item->price = $valor;
    $item->quantity = 1;
    $item->hours = null;
    $item->funcionario_id = null;
    $item->adicional = false;
    $item->discount_percent = 0;
    $item->discount_amount = 0;
    $item->total = 0;
    $item->save();
    $item->total = $item->line_total;
    $item->save();

    return $item;   // quem chama recalcula os totais da OS uma vez
}
```

#### `Services/Rotina/RotinaService.php`
- `diariasDePatio()` (`:356`): primeira linha do método: `if (DiariaPatioService::ativa()) { return app(DiariaPatioService::class)->painel(); }`; o restante fica **exatamente** como está (caminho do piloto desligado).
- `lancarDiaria()` (`:394`): depois de `ValoresFinanceiros::authorize('diária de pátio')`, se `DiariaPatioService::ativa()`:

```php
$servico = app(DiariaPatioService::class);
$resultado = $servico->processar($os);

if ($resultado['novas'] === 0) {
    throw ValidationException::withMessages(['diaria' => [
        $servico->lancadas($os)->isNotEmpty() ? 'A diária de pátio já foi lançada nesta OS.' : 'Esta OS não tem diária de pátio a cobrar.',
    ]]);
}

return $resultado['itens']->last();
```
  O caminho antigo (piloto desligado) segue intacto abaixo.
- `doConsultor()` (`:259`): acrescentar ao texto `(($d['pendentes'] ?? null) === 0 ? ' (já incluída na OS)' : '')`, mantendo `'… — N dia(s) de diária'` (o teste existente usa `toContain('1 dia(s) de diária')`).

#### `Services/Alcada/AlcadaCatalog.php` (`temas()`, `:26-52`)
Acrescentar (junto de `preco_abaixo_minimo`):

```php
'isencao_diaria_patio' => $t('Isenção de diária de pátio', 'gerente', 'nenhuma', '—', 'Aprova a isenção, com justificativa', '—', 'Isentar a diária de pátio na OS', true),
```
`AlcadaRegra::ensureDefaults()` semeia a linha da matriz sozinha. Tipo `gerente`: o Gerente decide (e pode encaminhar ao dono); sem limite em R$.

#### `Services/WorkshopSettings.php` (`DEFAULTS`)
Acrescentar junto de `diaria_patio_*` (`:68-70`): `'diaria_patio_dias_corridos' => true,`.

#### `Http/Controllers/FichaController.php` e `Resources/views/ficha/edit.blade.php`
- Controller: validação `'diaria_patio_dias_corridos' => 'nullable|in:0,1'` (junto de `:46-48`) e `WorkshopSettings::set('diaria_patio_dias_corridos', ($dados['diaria_patio_dias_corridos'] ?? '1') === '1');`. Ausente = `true` (formulários antigos e `CadastrosFichaTest` continuam passando).
- View: depois do campo "Horas para cobrar com orçamento não autorizado" (`:50`), um `select name="diaria_patio_dias_corridos"` com as opções "Sim, todos os dias (dias corridos)" (`1`) e "Não, só nos dias de funcionamento" (`0`), rótulo "Contar a diária nos dias em que a oficina está fechada?" e a nota: "A diária começa quando acaba o prazo acima e dura 24 horas (2 h 24 min no modo de teste). Sem o valor e as horas preenchidos o sistema não cobra diária."

#### `Services/Indicators/IndicatorCalculator.php`
- `:98` CON-17: manter a seleção (chamados do período) e trocar o teste por `fn ($os) => $os->chamado_autorizado_em && (! $os->chamado_saida_em || $os->chamado_autorizado_em->lte($os->chamado_saida_em))`.
- `:115` CHE-15: `fn () => $this->barrier('pre_diagnostico_chamado')`.
- `taxaAprovacao()` (`:459-471`): trocar `(float) $item->grand_total` por `$item->valorSemDiarias()` nos dois somatórios (a diária de orçamento não autorizado entra na OS depois do envio e distorceria CON-09).

#### `Services/CommissionReportService.php`
- No laço de itens (`:47`), primeira linha: `if ($item->isDiariaPatio()) { continue; }`.
- Comissão de venda (`:83-101`): `'gross'` = `sub_total` menos a soma de `gross_total` dos itens diária; `'base'` = `$order->valorSemDiarias()`.
(F14 mexe nesta classe: reaproveitar `isDiariaPatio()` e `valorSemDiarias()`.)

#### `Console/Commands/WorkshopTickCommand.php`
Em `runFor()` (`:98-101`), logo depois de `app(PeriodicAlerts::class)->runIfDue();`:

```php
// Diária de pátio: calcula, inclui na OS (uma vez por dia de pátio) e avisa o consultor.
$fired += app(DiariaPatioService::class)->processarTodas();
```
(com o `use` do serviço). Já está dentro do `try` por oficina e depois do `! StageGate::active()` que encerra oficinas com piloto desligado. `processarTodas()` não faz nada se as tabelas ainda não existirem naquele banco.

#### `Http/Controllers/ServiceOrderController.php`
`destroyItem()` (`:349-358`): antes de `removeItem`, `if ($item->isDiariaPatio()) { session()->flash('error', 'A diária de pátio não pode ser removida. Use "Isentar a diária de pátio": exige justificativa e a aprovação do Gerente.'); return redirect()->route('admin.service_orders.view', $id); }`. (`storeItem` já só aceita `Peça,Serviço`, `:329`.)

#### `Http/Controllers/ServiceOrderStageController.php`
`checkAlcadas()` (`:198`): `$bruto` passa a ignorar as diárias: `$serviceOrder->items->reject(fn ($item) => $item->isDiariaPatio())->sum(...)`. Isso evita que o valor da diária "dilua" o percentual de desconto e deixe passar desconto acima da alçada. `updateNegociacao()` não precisa mudar: o item diária não tem campo no formulário e o hook `saving` mantém desconto zero.

#### `Resources/views/view.blade.php`
- Laço dos itens (`:161`): primeira linha `@continue($item->isDiariaPatio() && ! $canSeeValues)`.
- Na coluna "Alerta" (`:177-203`) e no "×" (`:204-212`): para `isDiariaPatio()` mostrar só um traço e **não** mostrar o botão de remover.
- O rótulo do tipo (`:163`) usa a chave de tradução nova (ver textos).

#### `Resources/views/print.blade.php`
- `:38-40`: `$diarias = $mostrarValores ? $serviceOrder->items->where('tipo', \Webkul\ServiceOrder\Models\ServiceOrderItem::TIPO_DIARIA) : collect();`
- `:215`: `@foreach ([['Serviços', $servicos, true], ['Peças / produtos', $pecas, false], ['Diárias de pátio', $diarias, false]] as [...])`.
- Caixa de totais (`:280-284`): linha "Diárias de pátio" com `$brl($diarias->sum(fn ($i) => $i->line_total))` quando `$diarias->isNotEmpty()`, para o Total continuar batendo. O Técnico (sem `$mostrarValores`) não vê a seção.

#### `Resources/views/partials/negociacao.blade.php`
`:2`: `$items = $serviceOrder->items->reject(fn ($item) => $item->isDiariaPatio());` e `:8`: `$legacyDiscount = round(max(0, $items->sum(fn ($item) => $item->line_total) - ((float) $serviceOrder->grand_total - $serviceOrder->items->filter(fn ($i) => $i->isDiariaPatio())->sum(fn ($i) => $i->line_total))), 2);`. Diária não aparece no rateio de desconto.

#### `Resources/views/partials/workflow.blade.php`
- `:20` `$eventosRestritos`: acrescentar `'diaria_patio_incluida'` e `'diaria_patio_isenta'` (o Técnico não vê eventos financeiros).
- Antes do fechamento do grid (depois do cartão "8. Operações e tempos", `:480`): `@include('service_order::partials.chamado_diaria')`.

#### `Resources/views/rotina/painel.blade.php`
`:43-49`: no `@foreach` das diárias, `@continue(($d['pendentes'] ?? 1) < 1)` (com o piloto ligado o que já foi incluído não precisa de botão) e trocar o texto do botão para "Lançar agora" quando `isset($d['pendentes'])`. Piloto desligado: textos e botão iguais aos de hoje.

#### `Resources/lang/pt_BR/app.php` e `Resources/lang/en/app.php`
Chave nova em `form.tipo-options` (`pt_BR:119-122`, `en:103-106`): `'Diária de pátio' => 'Diária de pátio'` (pt_BR) e `'Diária de pátio' => 'Yard fee'` (en). Sem ela `trans('service_order::app.form.tipo-options.'.$item->tipo)` (`view.blade.php:163`, `negociacao.blade.php:74`) mostra a chave crua.

#### Fora do pacote (só se a decisão 6 for "sim")
`packages/Webkul/Fiscal/src/Http/Controllers/FiscalController.php:71-73` e `:79-82`: trocar `->where('tipo', ServiceOrderItem::TIPO_SERVICO)` por `->whereIn('tipo', [ServiceOrderItem::TIPO_SERVICO, ServiceOrderItem::TIPO_DIARIA])` para a diária entrar no valor e na discriminação sugeridos da NFS-e. Edição mínima e condicionada; coordenar com F09, dona da nota fiscal.

#### Testes existentes a atualizar
- `tests/Feature/Workshop/AllBarriersTest.php:59-62`: título e contagem passam de 20 para **21** (preferir `count(BarrierRule::DEFAULTS)` no `expect`, para não quebrar de novo se outra frente acrescentar barreira). O trecho `foreach (BarrierRule::where('critico', false)->get() as $rule) expect($rule->destinos(2))->not->toContain('proprietario')` continua valendo para a nova.
- `tests/Feature/Workshop/RotinaTest.php:109-162`: **não deve precisar de mudança**; rodar para confirmar (ver "Testes").
- `tests/Feature/Workshop/CadastrosFichaTest.php:115-145`: acrescentar `'diaria_patio_dias_corridos' => '0'` ao PUT e a asserção `WorkshopSettings::get('diaria_patio_dias_corridos')` igual a `false` (e, sem enviar o campo, `true`).

---

## Banco (migrations)

Ambas dentro de `Database/Migrations`, aditivas e idempotentes (guard com `Schema::hasTable/hasColumn`), `down()` seguro. Horários `130000` e `130100` escolhidos porque hoje não existe nenhuma migration `2026_11_18_*` em nenhum pacote; se outra frente já tiver usado, subir para o próximo livre.

### `2026_11_18_130000_add_chamado_externo_columns_to_quotes_table.php`
Para cada coluna, `if (! Schema::hasColumn('quotes', $nome)) { Schema::table(...) }`:

| Coluna | Definição |
|---|---|
| `chamado_viatura` | `string(60)` nullable |
| `chamado_saida_em` | `dateTime` nullable |
| `chamado_km_saida` | `unsignedInteger` nullable |
| `chamado_volta_em` | `dateTime` nullable |
| `chamado_km_volta` | `unsignedInteger` nullable |

`down()`: `dropColumn` só das que existirem.

### `2026_11_18_130100_create_service_order_diarias_tables.php`

```php
if (! Schema::hasTable('service_order_diaria_isencoes')) {
    Schema::create('service_order_diaria_isencoes', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('quote_id')->index();
        $table->string('justificativa', 500);
        $table->unsignedInteger('user_id')->nullable();
        $table->unsignedInteger('alcada_pedido_id')->nullable();   // aprovação consumida (null = Gerente/Proprietário executou direto)
        $table->unsignedSmallInteger('dias')->default(0);
        $table->decimal('valor_isento', 12, 2)->default(0);
        $table->timestamp('created_at')->useCurrent();              // só inserção: sem updated_at
    });
}

if (! Schema::hasTable('service_order_diarias')) {
    Schema::create('service_order_diarias', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('quote_id')->index();
        $table->unsignedInteger('item_id')->nullable()->index();    // linha em quote_items
        $table->string('motivo', 20);                               // pronto | nao_autorizado
        $table->unsignedSmallInteger('ordinal');                    // 1ª, 2ª, 3ª diária do motivo
        $table->dateTime('inicio_em');                              // início do dia de pátio
        $table->decimal('valor', 12, 2);                            // valor da Ficha no dia (não muda depois)
        $table->dateTime('calculada_em');
        $table->dateTime('avisada_em')->nullable();                 // quando o Consultor foi avisado
        $table->unsignedInteger('isencao_id')->nullable()->index(); // preenchido uma única vez na isenção
        $table->timestamps();
        $table->unique(['quote_id', 'motivo', 'ordinal'], 'diarias_os_motivo_ordinal_unique');
    });
}
```
`down()`: `Schema::dropIfExists` das duas. Sem chave estrangeira (padrão das tabelas da mentoria, ex. `service_order_reclamacoes`).

Tolerância a banco sem a migration: `DiariaPatioService::disponivel()` e `ChamadoExterno::disponivel()` (memoizados por banco); ações novas ficam indisponíveis; o cartão da OS some; `WorkshopTickCommand` não faz nada; `RotinaService` cai no caminho antigo. Nenhuma consulta nova roda em telas por onde todo usuário passa sem antes checar `disponivel()`.

Aplicação local: `DB_DATABASE=krayin_test` + `php artisan migrate --path=packages/Webkul/ServiceOrder/src/Database/Migrations/<arquivo>` (uma por vez). O `krayin_crm` (demonstração) só se a instrução da rodada mandar: sem as migrations a tela da OS apenas esconde as novidades.

---

## ACL, menu, rotas e configurações

- **ACL:** nenhuma chave nova. Reaproveitadas: `service_orders.edit` (rota `admin.service_orders.workflow.action`, `Config/acl.php:59`, cobre as 5 ações novas/alteradas), `rotina_painel` (rota `admin.rotina.diaria`, `:182-185`), `alcadas` (fila de alçadas, para o Gerente aprovar), `ver_valores_financeiros` (exigida por `isentar_diaria`, pelo botão "Lançar na OS" e para ver as linhas/valores). Perfis: Consultor, Financeiro, Gerente e Proprietário precisam de `ver_valores_financeiros` + `service_orders.edit` para pedir/executar a isenção; Gerente e Proprietário precisam de `alcadas` para decidir; **Técnico não tem nada disso** (403 na isenção e não vê as linhas de diária). Informar F05 (menu/permissões).
- **Menu:** nenhum item novo.
- **Rotas:** nenhuma nova. Tudo passa por `POST admin/service-orders/{id}/workflow/acao/{acao}` (`Routes/web.php:116`) e `POST admin/rotina/diaria/{id}` (`:217`).
- **WorkshopSettings:** `diaria_patio_dias_corridos` (bool, padrão `true`). Reaproveitadas: `diaria_patio_valor`, `diaria_patio_horas_pronto`, `diaria_patio_horas_nao_autorizado`, `horario_comercial`, `piloto_ativo`.
- **Alçada:** assunto `isencao_diaria_patio` (tipo `gerente`).
- **Barreira:** `pre_diagnostico_chamado` (configurável em Configurações > Indicadores e alertas, como as demais).
- **Eventos novos:** `chamado_externo_aberto`, `chamado_externo_desfeito`, `chamado_viatura_saida`, `chamado_viatura_volta`, `diaria_patio_incluida`, `diaria_patio_isenta`.
- **Ações de OS novas:** `saida_viatura`, `volta_viatura`, `isentar_diaria`. **Código de alerta:** `diaria_patio`. **Tipo de item:** `'Diária de pátio'`. **Tabelas:** `service_order_diarias`, `service_order_diaria_isencoes`. **Colunas em `quotes`:** `chamado_viatura`, `chamado_saida_em`, `chamado_km_saida`, `chamado_volta_em`, `chamado_km_volta`.
- **Contrato exigido da F03 (modo de teste):** `WorkshopSettings::divisorDePrazos(): float`, que devolve `1.0` com o modo de teste desligado e `10.0` ligado (só faz sentido com o piloto ligado). F13 só **consome** esse método em `DiariaPatioService::config()`. Se F03 escolher outro nome, mudar apenas essa linha.

---

## Textos da interface (pt-BR)

**Ações (painel "Passos desta etapa"):**
- "Registrar saída da viatura", campos "Km da viatura na saída" e "Viatura (placa ou nome)"; ajuda "A viatura só sai com a autorização do cliente registrada. O horário gravado é o de agora."
- "Registrar volta da viatura", campo "Km da viatura na volta"; ajuda "Grava o horário de agora e calcula os km rodados."
- "Isentar a diária de pátio", campo "Por que isentar? (fica registrado)"; ajuda "Quem não é Gerente pede a aprovação na fila de alçadas e repete a ação depois. A diária não é apagada: o valor sai da OS e o registro fica."
- Ajuda nova em "Cliente autorizou a saída da viatura": "Obrigatório antes de a viatura sair: sem isto o sistema não deixa registrar a saída."
- Ajuda nova em "Registrar pré-diagnóstico remoto": "Meta: até 30 minutos depois da abertura do chamado (o prazo é o da barreira em Configurações > Indicadores e alertas)."

**Mensagens de sucesso:** "Saída da viatura registrada." / "Volta da viatura registrada. Km rodados: N." / "Diária de pátio isentada (N diária(s)). Nenhuma nova diária será lançada nesta OS." / "Isenção registrada: esta OS não pagará diária de pátio." / "Diária de pátio lançada na OS." (já existe).

**Mensagens de erro:**
- "A viatura só pode sair depois da autorização do cliente. Registre "Cliente autorizou a saída da viatura" antes."
- "O km de volta não pode ser menor que o km de saída (N)."
- "Este chamado externo já tem autorização ou viatura registrada: o tipo de atendimento não pode mais mudar."
- "Explique o motivo da isenção (mínimo de 10 letras)." / "A diária de pátio desta OS já foi isentada." / "A isenção de diária de pátio só vale com o piloto ligado e o banco atualizado."
- "Esta ação passa da sua alçada (Isenção de diária de pátio). O pedido foi enviado para aprovação do gerente; repita a ação depois da aprovação." (já gerada por `AlcadaService`).
- "A diária de pátio já foi lançada nesta OS." / "Esta OS não tem diária de pátio a cobrar." (já existem; mantidas).
- "A diária de pátio não pode ser removida. Use "Isentar a diária de pátio": exige justificativa e a aprovação do Gerente."
- Bloqueio de etapa (lista "O que falta"): "Volta da viatura do chamado externo (km e horário)".

**Alerta ao Consultor:** título "Diária de pátio"; mensagem "ALERTA - Diária de pátio. OS #12 - ABC1D23 - Transportes Sul. 2 diária(s) de pátio incluída(s) na OS (pronto e não retirado); 5 dia(s) no total. Responsável: Ana." (sem R$).

**Linha de item:** "Diária de pátio nº 2 (pronto e não retirado, desde 19/11/2026 08:00)"; isenta: "… — isenta".

**Rótulos de evento (linha do tempo):** ver `ServiceOrderEvent::TIPOS` acima.

**Barreira:** nome "Pré-diagnóstico do chamado externo". **Alçada:** nome "Isenção de diária de pátio", gatilho "Isentar a diária de pátio na OS", Gerente "Aprova a isenção, com justificativa".

**Ficha:** rótulo "Contar a diária nos dias em que a oficina está fechada?", opções "Sim, todos os dias (dias corridos)" e "Não, só nos dias de funcionamento"; nota "A diária começa quando acaba o prazo acima e dura 24 horas (2 h 24 min no modo de teste). Sem o valor e as horas preenchidos o sistema não cobra diária."

**Tela da OS (parcial novo):** cartão "Chamado externo" com "Autorização do cliente", "Pré-diagnóstico remoto (meta 30 min)", "Viatura", "Saída", "Volta", "Km rodados"; cartão "Diária de pátio" com "Nº", "Motivo", "Desde", "Valor", "Situação" (Cobrada/Isenta), "Total cobrado", "Total isento". **Impressão:** seção "Diárias de pátio", "Subtotal diárias de pátio", linha "Diárias de pátio" nos totais. **Rotina:** "(já incluída na OS)" e botão "Lançar agora" (piloto ligado). **Idioma en:** só `tipo-options` ("Yard fee").

---

## Testes Pest a escrever

Convenções: `tests/Feature/Workshop/`, `DatabaseTransactions`, trait `BuildsWorkshop` (`enablePilot`, `makeUser`, `makeOs`, `at`), funções auxiliares globais com **nomes únicos** (prefixos `chamadoExt…` e `diariaPatio…`). Datas: 18/11/2026 é quarta-feira. **Não chamar `workshop:tick` nos testes**: o comando faz `DB::purge('mysql')` e perderia a transação do teste; testar `DiariaPatioService::processarTodas()` e o motor de barreiras direto. Antes de rodar: aplicar as 2 migrations no `krayin_test`.

### `ChamadoExternoTest.php`

| # | Teste | Cobre |
|---|---|---|
| 1 | "definir o tipo chamado externo grava o evento e abre o prazo de 30 min do pré-diagnóstico": `enablePilot([], ['pre_diagnostico_chamado'])`; Consultor (`service_orders*`) posta `definir_tipo`; 1 evento `chamado_externo_aberto`; `vence_em` = +30 min **mesmo às 21:00** (relógio corrido); postar o mesmo tipo de novo não duplica o evento | requisito 2 |
| 2 | "pré-diagnóstico: Chefe aos 30 min, Gerente +30 min; registrar a tempo fecha a barreira e resolve os alertas": ticks em +30 e +60; Dono não recebe; outra OS registrada em 20 min fica `cumprida_no_prazo = true` | requisito 2 |
| 3 | "autorização e pré-diagnóstico não podem ser repetidos" (segundo POST devolve erro e o carimbo `chamado_autorizado_em`/`pre_diagnostico_em` não muda) | carimbo estável |
| 4 | **"chamado externo sem autorização bloqueia a saída da viatura com o piloto ligado; com a autorização registra km e horário"**: erro com a mensagem do bloqueio, `chamado_saida_em` nulo, nenhum evento; depois de `autorizar_chamado` o POST grava `chamado_saida_em`, `chamado_km_saida`, `chamado_viatura` e o evento `chamado_viatura_saida` | requisito 1 e 3 (Teste do PDF) |
| 5 | "piloto desligado: a saída da viatura não é bloqueada" (chamar `WorkflowActions::perform` direto, pois o painel não aparece com o piloto desligado) | gate do piloto |
| 6 | "volta da viatura: só depois da saída, km menor que o de saída é recusado, grava horário e km rodados, uma vez só" (`ChamadoExterno::kmRodados`) | requisito 3 |
| 7 | "Veículo em Teste → Faturamento exige a volta da viatura quando ela saiu" (lista contém 'Volta da viatura do chamado externo (km e horário)'; some depois de registrar a volta; chamado sem viatura não exige) | requisito 3 |
| 8 | "trocar o tipo desfaz o chamado: grava `chamado_externo_desfeito`, fecha a barreira sem alerta; com autorização ou viatura registrada o tipo não muda" | integridade |
| 9 | "CON-17: autorização anterior à saída conta; autorização depois da saída (ou ausente) não conta" (criar auxiliar próprio `chamadoExtIndicador()` com `app(IndicatorService::class)->evaluate($codigo, '2026-11')['resultado']->realizado`; não depender de `realizado()` de `IndicatorsTest.php`, que só existe se aquele arquivo for carregado) | requisito 4 |
| 10 | "CHE-15 lê a mesma barreira que gerou os alertas": 2 chamados, um registrado em 20 min e outro depois de o alerta disparar → 50,0 | requisito 4 |
| 11 | "modo de teste: o prazo do pré-diagnóstico cai para 3 min" usando a chave que a F03 definir (escrever junto com F03; não deixar `skip`) | requisito 2 (÷10) |
| 12 | "banco sem as colunas do chamado: as ações de viatura somem e a tela da OS abre": `ChamadoExterno::simularDisponibilidade(false)` (restaurar com `null` em `afterEach`) | tolerância |
| 13 | "a tela da OS mostra o cartão Chamado externo" (`assertSee('Chamado externo')`, `assertSee('Autorização do cliente')`) | UI |

### `DiariaPatioTest.php`

| # | Teste | Cobre |
|---|---|---|
| 1 | **"caminhão pronto parado além do prazo ganha a diária e o consultor é avisado"**: piloto ligado; Ficha `diaria_patio_valor` 150, `horas_pronto` 24; OS em Faturamento, `user_id` = Consultor, `checklist_saida_em` = 18/11 08:00. Às 19/11 07:59: `processarTodas()` = 0. Às 19/11 08:01: 1 registro (ordinal 1, `inicio_em` 19/11 08:00, valor 150), 1 item `tipo = 'Diária de pátio'` com preço 150, `grand_total` 150, evento `diaria_patio_incluida`, 1 alerta `diaria_patio` só para o Consultor (nem Gerente nem Dono), mensagem com 'OS #' e placa e **sem 'R$'**; Técnico não é alertado | requisitos 5, 6, 7, 9 |
| 2 | "recalcula por dia e nunca duplica": rodar 3 vezes no mesmo instante → 1 registro, 1 item, 1 alerta; às 20/11 08:01 → ordinal 2, `grand_total` 300, 2º alerta; às 20/11 20:00 nada novo; botão da Rotina logo depois → erro "já foi lançada" | idempotência |
| 3 | "orçamento não autorizado: depois das horas da Ficha; recusa continua cobrando; aprovação interrompe": `orcamento_enviado_em` 16/11 12:00, horas 48; 18/11 11:59 = 0, 12:01 = 1; `aprovacao_tipo = 'recusa'` e dia seguinte = ordinal 2; `aprovacao_tipo = 'total'` → não cria a 3ª | requisito 5 |
| 4 | "não cobra": piloto desligado (`processarTodas` = 0 e tick nem chama), valor da Ficha vazio ou 0, horas `null`, OS sem veículo, `tipo_os` `retorno`/`garantia`/`chamado_externo`, `saida_em` preenchido, Concluída/Cancelada | gates |
| 5 | "OS que já tem a linha antiga de diária (lançada com o piloto desligado) não recebe a automática" (item `Serviço` 'Diária de pátio (2 dia(s)…)') | sem cobrança em dobro |
| 6 | "dias corridos × só dias de funcionamento": `horas_pronto = 0`, `checklist_saida_em` = sexta 20/11 10:00, agora = segunda 23/11 10:30 → corridos = 4 registros; `diaria_patio_dias_corridos = false` = 3 (domingo fica de fora; sábado conta pelo horário comercial padrão) | requisito 10 |
| 7 | "modo de teste ÷10": chave da F03 ligada; `horas_pronto = 24`, pronto às 10:00 → 12:24 ainda 0, 12:25 = 1 diária, 14:49 = 2 (escrever junto com F03) | requisito 10 |
| 8 | "isentar: o Técnico leva 403 e nada muda" | permissões |
| 9 | **"isenção exige alçada do Gerente"**: Consultor posta `isentar_diaria` com justificativa → erro de alçada; existe `AlcadaPedido` (`tema isencao_diaria_patio`, `pendente`, `nivel_necessario gerente`, `service_order_id`, `solicitante_id`) e o Gerente é alertado; `grand_total` e linhas intactos. Gerente `AlcadaService::decide(..., true)`; Consultor repete → sucesso: linhas com preço 0 e nome "— isenta", `grand_total` 0, registro de isenção (`dias` 2, `valor_isento` 300.00, `user_id`, `alcada_pedido_id` = pedido, justificativa), registros `isencao_id` preenchidos e `valor` ainda 150, pedido `consumido`, evento `diaria_patio_isenta` sem 'R$' nem '300', mais tarde `processarTodas()` não cria nada, 2ª isenção dá erro "já foi isentada", contagem de itens e de registros igual à de antes (nada apagado) | requisito 8 |
| 10 | "o Gerente isenta direto, sem pedido de alçada; justificativa curta (menos de 10 letras) é recusada; isenção preventiva (antes da 1ª diária) impede a cobrança futura" | requisito 8 |
| 11 | "a diária não pode ser removida nem receber desconto": `DELETE admin.service_orders.items.destroy` → flash de erro e o item continua; `$item->delete()` lança `LogicException`; `ServiceOrderItem::update(['discount_amount' => 50])` fica 0; `PUT negociacao` com `item_discounts[<diária>]` não grava desconto; registros do ledger não apagam nem mudam (`LogicException`) | integridade |
| 12 | "a diária não é operação de oficina": não aparece em `TecnicoTasks::operacoes`, `StageGate::missing(Em Execução → Teste)` não cita a diária, `exitChecklist` não soma hora, `ServiceOrder::operations()` não a inclui | defeito 1 |
| 13 | "a diária fica fora da comissão": `CommissionReportService::build` não lista a diária para o técnico e a base de venda do consultor exclui o valor | defeito 2 |
| 14 | "DRE e CON-09": OS faturada com Serviço 100 + diária 150 → `receita_mo` 100 e `receita_outras` 150; `valorSemDiarias()` = 100; CON-09 não conta a diária no "orçado" | defeito 1 |
| 15 | "tela e impressão": Gerente vê a seção 'Diárias de pátio' na impressão e a linha na OS; **Técnico não vê** (tela e `view('service_order::print')`), e a OS do Técnico não tem nenhum valor (criar auxiliar próprio `diariaPatioSemDinheiro()` com um valor "secreto" novo, como `noMoney` de `TecnicoValoresTest`, sem depender dele) | técnico sem dinheiro |
| 16 | "botão Lançar na OS (Rotina) com o piloto ligado cria o que falta e avisa o Consultor; segunda tentativa dá erro" | compat. `RotinaTest` |
| 17 | "tabelas ausentes: nada quebra": `DiariaPatioService::simularDisponibilidade(false)` (restaurar em `afterEach`); `processarTodas()` = 0; `GET admin.rotina.painel` e a tela da OS respondem 200 sem a ação de isentar | tolerância |
| 18 | "Ficha salva a opção de dias corridos" (em `CadastrosFichaTest`, ver acima) | requisito 10 |

### Mapeamento ao PDF e ao roteiro de 72 linhas
- Linha "Teste:" do B6, parte "caminhão pronto parado além do prazo ganha a diária": `DiariaPatioTest` 1, 2, 3.
- Pedidos do orquestrador: "consultor é avisado" → `DiariaPatioTest` 1; "isenção exige alçada" → 9; "chamado externo sem autorização bloqueia a saída com o piloto ligado" → `ChamadoExternoTest` 4.
- Roteiro de 72 linhas: **nenhuma linha cobre estes itens**. Ficam relacionados à linha 45 (o checklist de saída é quem marca a OS como "pronta" e começa a contar) e às 46-50 (Faturamento/saída, F09). Sugestão de linhas 73 a 76 em "Passo a passo para o usuário".

### Comandos
```powershell
$env:PATH = "C:\Users\Usuario\dev-tools\php83;" + $env:PATH
$env:OPENSSL_CONF = 'C:\Users\Usuario\dev-tools\php83\extras\ssl\openssl.cnf'
$env:DB_DATABASE = 'krayin_test'
Set-Location "d:\volmaster crm\volmaster crm"
php artisan migrate --path=packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_130000_add_chamado_externo_columns_to_quotes_table.php
php artisan migrate --path=packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_130100_create_service_order_diarias_tables.php
php artisan test --filter=ChamadoExterno
php artisan test --filter=DiariaPatio
php artisan test --filter=RotinaTest
php artisan test --filter=Workshop
php vendor/bin/pint <arquivos alterados>
```

---

## Riscos e interações com outras frentes

**Dependência dura**
- **F03 (modo de teste):** F13 precisa de `WorkshopSettings::divisorDePrazos()` (ver "ACL, menu, rotas e configurações"). Sem ela os testes 11 (chamado) e 7 (diária) não podem ser escritos de verdade. Ordem sugerida: F03 antes de F13; se F13 vier primeiro, criar a função devolvendo `1.0` e deixar a F03 substituí-la. A barreira nova herda o ÷10 do `BarrierEngine`; a diária o aplica sozinha em `DiariaPatioService::config()` (carência e duração do dia).
- **F00 (oficinas/migrations por oficina):** o `migrate-safe.php` só migra o banco principal; sem a rotina da F00 as oficinas ficam sem as tabelas/colunas e, por desenho, sem as novidades (nunca com erro). As 2 migrations são comuns, então entram sozinhas no mecanismo que a F00 criar.

**Arquivos compartilhados (risco de conflito de edição, todos em pontos pequenos):** `Models/ServiceOrderEvent.php` (`TIPOS`), `Models/BarrierRule.php` (`DEFAULTS`), `Services/WorkflowActions.php` (definições, `isAvailable`, `perform`, `setTipo`), `Services/StageGate.php` (`testeParaFaturamento`), `Services/Indicators/IndicatorCalculator.php` (linhas CON-17, CHE-15, `taxaAprovacao`), `Services/Alcada/AlcadaCatalog.php`, `Services/WorkshopSettings.php`, `Http/Controllers/FichaController.php` + `ficha/edit.blade.php`, `Services/CommissionReportService.php` (F14), `Services/ServiceOrderItemService.php` (F08), `Models/ServiceOrderItem.php` e `Models/ServiceOrder.php`, `Console/Commands/WorkshopTickCommand.php` (F00/F03), `Resources/views/view.blade.php`, `print.blade.php`, `partials/workflow.blade.php`, `partials/negociacao.blade.php`, `Resources/lang/*/app.php`, e `tests/Feature/Workshop/AllBarriersTest.php:59-62` (contagem de barreiras).

**Interações por frente**
- **F08 (valor da hora):** a diária **não** é `Serviço`; as regras de "valor da hora" (aviso de 3× a Ficha, abaixo do mínimo exige alçada) devem olhar só `TIPO_SERVICO` e ignorar `TIPO_DIARIA`. Hoje a diária manual sairia como "serviço de 1 h" e dispararia essas regras.
- **F09 (nota e saída):** a diária pode entrar na OS **depois** de nota/cobrança emitidas (ex.: o caminhão fica parado após o Faturamento): o `grand_total` passa a diferir do valor da NF e do boleto (o valor do boleto sugerido é `grand_total` no momento, `partials/faturamento.blade.php:40`). Ver decisão 7. `FiscalController::create` (`:71-73`) soma só itens `Serviço` na NFS-e (ver decisão 6). `StageAdvanceService` marca `saida_em` na saída, o que encerra a diária.
- **F12 (retorno/garantia):** `tipo_os` `retorno`/`garantia` não cobram diária automática (decisão 1). F12 e F13 compartilham `WorkflowActions`/`BarrierRule`; a barreira `diagnostico_retorno` já existe e não é tocada.
- **F14 (remuneração):** a diária não conta na comissão de execução nem na de venda; F14 deve usar `ServiceOrderItem::isDiariaPatio()` e `ServiceOrder::valorSemDiarias()` ao reescrever `CommissionReportService`.
- **F05 (menu/permissões):** `isentar_diaria` e as linhas de diária exigem `ver_valores_financeiros`; Técnico fica sem nenhuma. As rotas usadas já estão em chaves existentes. O item "Quadro de pátio"/"Minha rotina" não muda.
- **F07 (termo de entrada):** o chamado externo nasce em Cadastro com o caminhão ainda na estrada. A saída da viatura (`saida_viatura`) é registrada com a OS em Cadastro/OS/Diagnóstico e **não depende** de fotos, km ou assinatura do termo; F07 não deve exigir o termo antes da saída da viatura (ele só poderá ser preenchido quando o técnico chegar ao veículo).
- **F06 (crédito):** nenhuma dependência; a análise de crédito não bloqueia a saída da viatura (fora do PDF).
- **F10/F11 (peças):** nenhuma. A diária não tem `part_id`, não mexe em estoque.
- **F02 (notificações):** o aviso usa o pipeline padrão (`AlertService::raise` + `AlertDispatcher`), não crítico (respeita o horário de silêncio).

**Riscos próprios**
1. **Cobrança retroativa ao ligar:** preencher valor e horas na Ficha de uma oficina com OS já paradas faz a primeira rodada incluir todas as diárias acumuladas de uma vez (uma linha por dia). Orientação no passo a passo; o Gerente isenta se for o caso.
2. **Isenção não tem "desfazer"** nesta rodada (nada é apagado, mas também não existe reverter): se isentar por engano, o Gerente lança manualmente uma linha de serviço. Registrar como limitação.
3. **Uma viagem por chamado:** os campos de viatura guardam uma saída e uma volta por OS; segunda viagem exige nova OS.
4. **O horário da viatura é o do registro** (`now()`); registrar a volta no dia seguinte grava a hora do registro. Um campo de horário manual exigiria um tipo de campo novo no parcial de ações (`workflow_actions.blade.php` só conhece select, number, date, money e texto).
5. **DRE congelada:** diária incluída numa OS faturada em mês já congelado soma ao `grand_total`, mas o snapshot do mês não muda; a receita não aparece em nenhuma DRE. Caso raro, anotar.
6. **CHE-15 e CON-17 mudam de definição:** os números do mês corrente podem mudar de valor. Nenhum teste atual cobre os dois (busca vazia), então o risco é só de leitura de histórico.
7. **`ficha_veiculo_externo` continua sem efeito.** Se a oficina marcar "Não", os indicadores CON-17/CHE-15 ficam "sem dado" por não haver chamados; não há bloqueio de tipo.
8. **Performance:** `processarTodas()` roda a cada minuto por oficina com piloto; a consulta de candidatas é filtrada em SQL e a lista é pequena. Se um dia pesar, adicionar um throttle por `WorkshopSettings` (não escrever no banco a cada minuto sem necessidade; por isso o desenho não grava "última execução").
9. **Hook de apagar item:** `ServiceOrderItem::deleting` lança `LogicException` para diária; qualquer outro caminho que apague itens via modelo vai estourar. Hoje só `removeItem` apaga e é tratado em `destroyItem`.

---

## Decisões em aberto

(Só o que depende do patrão; a opção recomendada vem primeiro.)

1. **Quais OS cobram diária automática?** Recomendado: só atendimento `normal` e `preventiva`/frota. Retorno/garantia (o PDF diz "sem cobrança por padrão") e chamado externo (o caminhão atendido no local não usa o pátio) ficam fora. Alternativa: cobrar também esses tipos, ou só o chamado externo que foi rebocado para a oficina (o sistema hoje não sabe quando isso aconteceu; exigiria um campo "veículo no pátio").
2. **Orçamento recusado conta como "orçamento não autorizado"?** Recomendado: sim (o caminhão continua no pátio, a recusa não o tira de lá). Hoje o código antigo deixa a recusa de fora, sem que isso venha do PDF.
3. **Padrão da Ficha para contar os dias:** recomendado "dias corridos" (como já funciona, diária de pátio corre todo dia). A oficina pode trocar para "só dias de funcionamento".
4. **Barreira nova do pré-diagnóstico do chamado** (não está nas 20 do cap. 11.2): recomendado 30 min em relógio corrido, aviso ao Chefe aos 30 min e ao Gerente +30 min. Confirmar destinatários e se deve contar só em horário comercial.
5. **Comissão sobre a diária:** recomendado fora da comissão do técnico e do consultor. Alternativa: manter só a de venda do consultor.
6. **A diária entra na NFS-e junto com os serviços?** Recomendado: sim (guarda/estacionamento de veículo é serviço), mas **confirmar com a contabilidade**. Se sim, aplicar a edição mínima no `FiscalController` descrita acima (coordenada com F09); se não, a nota segue só com os serviços e o Financeiro trata a diária à parte.
7. **Avisar também o Financeiro quando a diária entra numa OS que já tem nota fiscal ou cobrança?** Recomendado: sim, só nesse caso e sem valores (evita nota/boleto menores que a OS). Fora do PDF (que só pede aviso ao Consultor): **implementar somente se confirmado** (acrescentar `'financeiro'` aos destinos de `avisarConsultor` quando `enviada_financeiro_em`, nota ou boleto existirem).

---

## Passo a passo para o usuário

(Ações manuais dele ou do patrão; nada aqui é feito pelo código.)

1. **Depois do deploy, rodar as migrations.** Abrir `public/migrate-safe.php?key=...` uma vez (banco principal) e apagar o arquivo depois. **Cada oficina tem banco próprio:** o `migrate-safe.php` não chega nelas. Seguir o procedimento que a frente F00-oficinas entregar para migrar o banco de cada oficina (inclusive a "Oficina Teste Mentoria"). Enquanto isso não for feito, a oficina continua funcionando, só sem chamado externo (viatura) e sem a diária automática.
2. **Conferir o agendador:** o cron de 1 minuto (`php artisan schedule:run`) precisa estar ativo na HostGator; é ele que roda as barreiras, os alertas e, agora, o cálculo da diária.
3. **Ficha de Enquadramento de cada oficina** (Configurações > Ficha): preencher "Diária de pátio (R$/dia)", "Horas para cobrar após pronto" e "Horas para cobrar com orçamento não autorizado" (vazio = não cobra) e conferir "Contar a diária nos dias em que a oficina está fechada?". **Atenção:** ao preencher, as OS que já estiverem prontas e paradas há dias recebem de uma vez as diárias acumuladas e o Consultor é avisado. Se não quiser isso, preencha só depois de resolver as OS paradas, ou use a isenção.
4. **Configurações > Indicadores e alertas:** conferir a nova barreira "Pré-diagnóstico do chamado externo" (30 min; Chefe e depois Gerente). Se a oficina não atende chamado externo, pode desativá-la.
5. **Funções (Configurações > Funções):** Consultor, Financeiro, Gerente e Proprietário precisam de "ver valores financeiros" e "editar OS"; Gerente e Proprietário precisam da permissão da fila de alçadas para aprovar isenções. O Técnico não recebe nenhuma dessas.
6. **Matriz de alçadas (Mentoria > Alçadas > Matriz):** aparece a linha nova "Isenção de diária de pátio" (decide o Gerente); não precisa preencher limites.
7. **Teste manual na "Oficina Teste Mentoria"** (com o modo de teste ÷10 ligado, 24 h vira 2 h 24 min), sugestão de **linhas novas do roteiro**:
   - **73 | Marcar a OS como "Chamado externo" e tentar registrar a saída da viatura sem a autorização do cliente | bloqueado, com a mensagem da regra;**
   - **74 | Registrar a autorização, depois a saída da viatura (km) e a volta (km) | carimbos, km rodados e o pré-diagnóstico dentro de 3 min cumprem CHE-15; fora do prazo alerta o Chefe;**
   - **75 | Deixar a OS pronta (checklist de saída) e esperar além do prazo da Ficha | a diária entra na OS, o Consultor é avisado (sem valores) e cada novo "dia" de 2 h 24 min acrescenta outra;**
   - **76 | Consultor pede a isenção; Gerente aprova na fila de alçadas; Consultor repete | o valor sai da OS, o histórico fica e nenhuma nova diária é lançada.**
8. **Informar a equipe:** diária não se apaga nem recebe desconto; o caminho é "Isentar a diária de pátio", com justificativa e aprovação do Gerente.

---

## Notas para a documentação (RETOMAR-TRABALHO.md, a cargo do orquestrador)

- Novos: serviço `DiariaPatioService` (cálculo na `workshop:tick`, a cada minuto), tipo de item `Diária de pátio`, tabelas `service_order_diarias` e `service_order_diaria_isencoes`, colunas `quotes.chamado_*`, barreira `pre_diagnostico_chamado` (agora 21 barreiras), assunto de alçada `isencao_diaria_patio`, chave da Ficha `diaria_patio_dias_corridos`, ações de OS `saida_viatura`/`volta_viatura`/`isentar_diaria`, 6 tipos de evento, código de alerta `diaria_patio`.
- Mudou: CON-17 (autorização anterior à saída), CHE-15 (lê a barreira), CON-09 (ignora diária), comissão (ignora diária), `RotinaService::diariasDePatio()/lancarDiaria()` (usam o serviço com o piloto ligado; piloto desligado idêntico).
- Limitações: sem "desfazer isenção"; uma viagem por chamado; horário da viatura = horário do registro; feriados não tratados na contagem de dias de funcionamento.
