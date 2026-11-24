# Especificação — F12-retorno

Frente F12 — **OS de retorno/garantia** (item B6 do PDF, parte "retorno/garantia"). Projeto somente de leitura feito em 18/11/2026 sobre o código real de `d:\volmaster crm\volmaster crm`. Caminhos abaixo são relativos a essa pasta; `SO/` = `packages/Webkul/ServiceOrder/src`.

## Decisões de projeto (valem para o resto do documento)

1. **O que é "OS entregue"**: OS em `Pós-Venda` ou `Concluída` (ou com `saida_em` preenchido). É o mesmo critério que a ação atual já usa (`WorkflowActions.php:210`).
2. **Um botão só, "Abrir OS de retorno"**. O seletor "Retorno | Garantia" some. O sistema decide sozinho se a OS de retorno é garantia: `tipo_os = 'garantia'` quando está coberta, `'retorno'` quando não. Os dois valores continuam contando igual nos indicadores (hoje já contam: `IndicatorCalculator.php:592`).
3. **Quando vale a garantia**: a garantia conta da saída do veículo (`saida_em`; se faltar, `data_conclusao`; se faltar, `faturado_em`) até **+N dias da Ficha**, avaliada **na data em que o retorno foi aberto** (não muda depois). A Ficha já tem **dois** prazos (`garantia_servico_dias` e `garantia_pecas_dias`, padrão 90 e 90, `WorkshopSettings.php:71-72`): serviço e peças são avaliados separadamente. `0` dia = sem garantia.
4. **"Uso do cliente" NÃO é garantia**: mesmo dentro do prazo, a OS de retorno com essa causa segue a cobrança normal. A garantia só vale para as causas `execucao`, `peca` e `diagnostico`. Fora do prazo, cobrança normal.
5. **"Sem cobrança por padrão" = linhas marcadas como garantia, não preço zerado**. Cada linha da OS (peça ou serviço) ganha a marca `em_garantia`. O preço de tabela continua gravado; só o valor cobrado (`line_total`) vira 0. Assim o total da OS fica zerado, o custo da garantia continua visível (peças com `custo_unitario`, horas reais) e **nada é sobrescrito nem apagado**. Descontos comerciais e garantia ficam separados (garantia não é desconto).
6. **Exceção com registro (isenção)**: a gerência pode isentar a cobrança de um retorno não coberto (fora do prazo ou uso do cliente). Exige justificativa, passa pela alçada já existente `retorno_garantia` (`AlcadaCatalog.php:37`, hoje sem gatilho) e grava evento. Em branco (limites não preenchidos) a alçada não bloqueia, como nas demais.
7. **Causa e responsável são pedidos na abertura** (obrigatórios; o responsável só é dispensado em "uso do cliente"). Corrigir depois exige justificativa, fica no histórico e avisa o Gerente — porque a causa mexe no bônus do Chefe (CHE-10) e do Técnico (TEC-10, TEC-02).
8. **Piloto desligado = comportamento atual**. A abertura, a garantia, a isenção e as travas só valem com o piloto ligado. Os trechos de tela que ficam fora do piloto são só leitura e não consultam coluna nova sem checar se ela existe.
9. **Nenhuma regra fixa no código**: prazos da garantia vêm da Ficha; prazo de diagnóstico (2 h) vem da barreira `diagnostico_retorno`; limites de isenção vêm da matriz de alçadas.

## O que já existe

**Banco**
- `SO/Database/Migrations/2026_11_17_100000_extend_barriers_and_workflow_for_mentoria.php:30-34` cria em `quotes`: `tipo_os` (`normal|retorno|garantia|chamado_externo|preventiva`, padrão `normal`), `os_origem_id` (nullable) e `retorno_causa` (`execucao|peca|diagnostico|uso_cliente`); índices em `:58-59`. Nenhuma coluna de responsável, de prazo de garantia nem de marca por linha.

**Abertura e classificação (já funcionam, mas incompletas)**
- `SO/Services/WorkflowActions.php:26-31` `RETORNO_CAUSAS` (as 4 causas, textos em português já certos).
- `WorkflowActions.php:208-216` ação `abrir_retorno`: só nas etapas Pós-Venda/Concluída; campos `tipo` (retorno|garantia) e `motivo`; **sem causa, sem responsável, sem checagem de duplicidade, sem garantia**.
- `WorkflowActions.php:588-608` `openReturn()`: cria a OS (cliente, veículo, consultor `user_id` e `mechanic_id` da origem, `description = motivo`, etapa Cadastro, assunto "Retorno da OS #N - PLACA"), grava `tipo_os` e `os_origem_id` com `forceFill` e o evento `retorno_aberto`; devolve o id na sessão (`retorno_id`) e o controller redireciona para a OS nova (`WorkflowController.php:260`).
- `WorkflowActions.php:217-224` e `:610-617` ação `classificar_retorno`: exige `service_orders.auditar` (Chefe), qualquer etapa, **opcional, sem justificativa**; grava `retorno_causa` e o evento `retorno_classificado`. Por isso o CHE-14 ("retornos classificados com causa") existe.
- `SO/Observers/ServiceOrderObserver.php:30-36` grava `retorno_aberto` se a OS já nascer com `tipo_os` retorno/garantia. É caminho morto hoje: `tipo_os` não está em `$fillable` (`SO/Models/ServiceOrder.php:65-98`), por isso `openReturn` grava depois e registra o evento ele mesmo.
- Selo do tipo na tela: `SO/Resources/views/partials/workflow_actions.blade.php:14-16` (texto cru em minúsculas, **sem link** para a origem, só dentro do bloco do piloto).

**Prazo de diagnóstico de 2 h (barreira) — já existe**
- `SO/Models/BarrierRule.php:267-279` regra `diagnostico_retorno`: abre em `retorno_aberto`, fecha em `diagnostico_fim`, `MODO_MINUTOS`, 120 min, nível 1 = `chefe`, nível 2 = `gerente` +60 min, conta horário comercial (padrão da coluna). Editável em Configurações > Indicadores e alertas. Teste: `tests/Feature/Workshop/AllBarriersTest.php:380-396`.
- O motor reage ao evento: `SO/Services/BarrierEngine.php:38-72` (abre) e `:293-313` (fecha e marca `cumprida_no_prazo`). O modo de teste ÷10 (frente F03) escala `prazo_minutos` de todas as regras; esta regra é `MODO_MINUTOS`, então entra sem trabalho extra.

**Indicadores (definição e cálculo atuais)**
- `SO/Services/Indicators/IndicatorCatalog.php`: `:70` CHE-10 Retrabalho = retornos por falha de execução ÷ OS entregues (teto 2%); `:73` CHE-13 = OS de retorno diagnosticadas em 2 h ÷ OS de retorno (mín. 100%); `:74` CHE-14 = retornos com causa ÷ retornos; `:80` TEC-02 Assertividade; `:88` TEC-10 Retorno de serviço; `:130` GER-05 (= CHE-10 da oficina inteira).
- `IndicatorCalculator.php:110` CHE-10 → `retrabalho()` (`:600-611`: `retornos()->where('retorno_causa','execucao')` ÷ OS com evento `saida_veiculo` no período); `:113` CHE-13 → `barrier('diagnostico_retorno')`; `:114` CHE-14 → `taxa(retornos(), retorno_causa preenchida)`; `:120` TEC-02 → `assertividade()` (`:652-667`: retorno com `os_origem_id` da OS, causa `diagnostico`, até 30 dias após o fim do diagnóstico); `:128` TEC-10 → `retornoDeServico()` (`:677-692`: retorno com causa `execucao`, até 30 dias da 1ª operação concluída); `:169` GER-05.
- `retornos()` (`:589-595`) = `tipo_os in (retorno, garantia)` criadas no período. **Não exclui OS canceladas** (lacuna).
- Bônus: CHE-10 pesa 15 na fase 2 do Chefe (`SO/Services/Bonus/BonusCatalog.php:36`); TEC-02 (20) e TEC-10 (15) na fase 2 do Técnico (`:40`). Travas manuais já previstas: Técnico `retorno_negligencia` (`:91`) e Chefe `retrabalho_escondido` (`:83`).
- Os testes de indicador criam o retorno com `forceFill(['tipo_os','os_origem_id','retorno_causa'])` direto (`IndicatorsTest.php:159-180`): **os cálculos não podem passar a depender de coluna nova**.

**Garantia da Ficha (existe, ninguém usa)**
- `WorkshopSettings.php:71-72` (`garantia_servico_dias`, `garantia_pecas_dias`, 90/90); `SO/Http/Controllers/FichaController.php:49-50` e `:76-78` salvam; `SO/Resources/views/ficha/edit.blade.php:51-52` mostram. **Nenhum código lê esses valores**.

**Alçada**
- `SO/Services/Alcada/AlcadaCatalog.php:37` tema `retorno_garantia` ("Chefe: decisão técnica | Custo até R$ __ | Acima"), `automatico = false` ("pedido manual"), **sem nenhum gatilho no código**. `AlcadaService::authorize()` (`SO/Services/Alcada/AlcadaService.php:111-143`) passa em silêncio com piloto desligado, limite em branco ou aprovação a consumir.

**Totais, comissão e DRE**
- `SO/Models/ServiceOrderItem.php:104-125` `gross_total`, `discount_total` e `line_total` (tudo calculado do preço × quantidade − desconto). `SO/Repositories/ServiceOrderRepository.php:64-82` `recalculateTotals()` soma `gross_total` em `sub_total` e `line_total` em `grand_total`. `SO/Services/ServiceOrderItemService.php:26-79` `addItem()` (também usado pelo técnico em `TecnicoController::addPart`, `:94-109`, e pela diária de pátio em `RotinaService.php:408`).
- `SO/Services/CommissionReportService.php:47-101`: comissão = % da função × `line_total` (execução) ou × `grand_total` (venda). Total zero ⇒ comissão zero.
- `SO/Services/Financeiro/DreService.php:79-85`: receita por `line_total`; custo de peças por `custo_unitario × quantidade` das OS faturadas no mês (o custo da garantia entra, a receita não).
- `SO/Http/Controllers/ServiceOrderStageController.php:155-166` (`updateNegociacao`) **regrava `discount_amount` de todas as linhas** a partir do formulário, e `:194-227` (`checkAlcadas`) calcula o % de desconto sobre `gross_total` de todas as linhas.

**Gates de etapa (só com piloto)**
- `SO/Services/StageGate.php:112-158` Cadastro→OS (exige cor de crédito), `:165-196` Diagnóstico→Orçamento, `:198-213` Negociação→Execução (exige forma de pagamento), `:288-310` Faturamento→Saída (exige NF e pagamento/acordo). `SO/Services/StageAdvanceService.php:62-64` marca `sem_nf` no evento de saída (dispara alerta crítico, trava automática e reprova FIN-02).

**Painel, impressão, acompanhamento, técnico**
- `SO/Services/Indicators/PainelDonoService.php:54-76` (`build`), `:212-241` (cartões; ticket médio em `:235`), `:28-37` `FLUXO` ("08 Pós-venda" já mostra CHE-10). **Não há "retornos por causa"**.
- `SO/Resources/views/print.blade.php:22` (`$mostrarValores = ValoresFinanceiros::can()`), `:121-126` cabeçalho, `:233-244` linhas, `:258-292` totais. Não imprime crédito.
- `SO/Resources/views/public/tracking.blade.php:159` mostra só "OS #N", etapa, placa, previsão. Não mostra crédito nem valores (teste em `tests/Feature/Workshop/EntradaELgpdTest.php:36-49`).
- `SO/Services/TecnicoTasks.php:44-84` e `:90-122` montam a lista do técnico campo a campo (lista branca, sem valores); teste em `TecnicoValoresTest.php:102-126` proíbe chaves `total`, `preco`, `desconto`, `credito`, `comissao`, `grand`.

**Testes que já tocam o assunto**
- `AllBarriersTest.php:380-396` (barreira) e `:605-630` (abre retorno por HTTP, consultor não classifica, chefe classifica) — **o segundo precisa ser atualizado** (ver "Testes").
- `IndicatorsTest.php:159-180` (CHE-10/GER-05/CHE-14).
- `MentoriaPanelTest.php:72` exige que a aba "Indicadores" do painel do celular tenha **exatamente** os blocos `['Peças e estoque','Caixa e financeiro','Gestão e RH','Dono']` e 8 cartões: **não acrescentar bloco nem cartão**; o novo dado vai em chave própria (`retornos`).

## Requisitos do PDF → como serão atendidos

| # | Requisito (B6) | Situação hoje | Como será atendido |
|---|---|---|---|
| 1 | Botão "Abrir OS de retorno" a partir de uma OS **entregue** | **Parcial**: a ação existe (Pós-Venda/Concluída) mas escondida no bloco genérico "Passos desta etapa" (só com piloto), com seletor Tipo, sem duplicidade e sem mostrar a garantia | Cartão próprio "Retorno e garantia" na tela da OS (`partials/retorno.blade.php`), botão "Abrir OS de retorno", resumo da garantia (entrega, prazos, dentro/fora), trava de duplicidade (um retorno aberto por vez) e piloto obrigatório |
| 2 | Vincula à OS de origem (e, pelo pedido da frente, mostrar o vínculo nas duas OS) | **Parcial**: `os_origem_id` gravado, mas só aparece como texto no assunto e um selo sem link | Retorno mostra "Retorno da OS #N" com link; origem mostra a lista "Retornos desta OS" com link; evento `retorno_vinculado` na origem; impressão traz "Retorno da OS nº…"; chip RETORNO no Quadro de pátio e na tela do técnico |
| 3 | Prazo de diagnóstico de 2 h (barreira, configurável, ÷10 no modo de teste) | **Já existe** (`diagnostico_retorno`, 120 min) | Nada a recriar. Acrescenta: aviso ao Chefe na abertura (alerta nível 1, sem valores) e exibição do prazo/estado da barreira no cartão. ÷10: sai da F03 sem código aqui |
| 4 | Classificação **obrigatória** da causa (execução, peça, diagnóstico, uso do cliente) | **Parcial/falta**: classificar é ação à parte, opcional, só do Chefe | Causa obrigatória na abertura (validação no controller + no serviço); trava `StageGate` (Diagnóstico→Orçamento) para OS de retorno sem causa; correção posterior só com justificativa, histórico e aviso ao Gerente |
| 5 | …e **responsável** | **Falta** | Coluna `quotes.retorno_responsavel_id` (colaborador). Obrigatório, exceto "uso do cliente". Sugestão pré-selecionada: colaborador ligado ao técnico da OS de origem |
| 6 | Dentro da garantia da Ficha (90 dias), **sem cobrança por padrão** | **Falta** (a Ficha guarda os dias, nada os lê) | Serviço `RetornoGarantia`: prazos por tipo, avaliados na abertura; linhas `em_garantia` com cobrança zero; NF/boleto/pagamento/crédito dispensados quando não há nada a cobrar; comissão zero; isenção por exceção com justificativa e alçada. Fora do prazo e "uso do cliente": cobrança normal |
| 7 | Alimenta CHE-10, CHE-13, CHE-14, TEC-10, TEC-02 | **Já existe** o cálculo; falta a OS chegar completa | Como a abertura grava `tipo_os`, `os_origem_id`, `retorno_causa` e o evento `retorno_aberto`, os cinco indicadores passam a ser alimentados sem mudar fórmula. Ajustes: `retornos()` ignora OS cancelada; garantia não distorce CHE-12, PEC-12, DRE-07, FIN-02 nem o ticket médio |
| 8 | Teste: "OS de retorno aparece ligada à original e entra no indicador de retrabalho" (roteiro 53) | **Parcial** (testes só de partes) | Arquivo novo `RetornoGarantiaTest.php` + ajuste do teste antigo (ver "Testes") |

**Cuidados pedidos pela frente**
- **Comissão/bônus**: garantia não gera comissão extra (total zero ⇒ base zero). O relatório de comissões pula as linhas em garantia para não mostrar "valor cheio sem base". Os indicadores que viram bônus (CHE-12, PEC-12) não contam horas/peças de garantia como faturadas. O mau serviço continua pesando em CHE-10/TEC-10/TEC-02, e a trava manual "retorno por negligência" (Bônus > Travas) segue disponível para o responsável informado.
- **Painel do Dono**: novo bloco "Retornos e garantias do mês" (por causa e por responsável), na tela, no PDF e no JSON do celular (chave `retornos`).
- **Impressão**: sem crédito; para quem vê valores, linhas em garantia rotuladas "Garantia" e linha "Coberto pela garantia"; técnico não vê nada de garantia/valores.
- **Acompanhamento público**: só o selo "Retorno" ao lado do número; sem causa, garantia, isenção, crédito ou valores.
- **Técnico**: vê que é retorno, a OS de origem e o laudo anterior; não vê causa, responsável, cobertura nem valores; leva 403 se tentar isentar.

## Mudanças por arquivo

Ordem sugerida de implementação: migration → modelos → serviço → ações → telas → indicadores/painel → textos → testes.

### Criar

#### 1. `SO/Services/RetornoGarantia.php` (núcleo)

Contrato público (namespace `Webkul\ServiceOrder\Services`; injeção: `ServiceOrderRepository $repository`, `AlertService $alerts`):

```php
class RetornoGarantia
{
    public const CAUSAS = [
        'execucao' => 'Falha de execução',
        'peca' => 'Peça',
        'diagnostico' => 'Diagnóstico',
        'uso_cliente' => 'Uso do cliente',
    ];

    public const COBERTURAS = [
        'garantia' => 'Garantia: sem cobrança',
        'fora_do_prazo' => 'Fora do prazo da garantia: cobrança normal',
        'uso_cliente' => 'Uso do cliente: não é garantia, cobrança normal',
        'isento' => 'Isento pela gerência: sem cobrança',
    ];

    public const CONDICAO_GARANTIA = 'Garantia (sem cobrança)';
    public const CONDICAO_ISENTO = 'Isento pela gerência (sem cobrança)';

    protected static array $memo = [];

    /** Colunas antigas (migration 2026_11_17_100000): tipo_os e os_origem_id. Memoizado por banco. */
    public static function temVinculo(): bool;

    /** Colunas desta frente (migration nova). Memoizado por banco (chave = DB::connection()->getDatabaseName()). */
    public static function disponivel(): bool;

    /** Para testes: limpa o memo. */
    public static function esquecer(): void;

    /** @return array{entrega: ?Carbon, servico_dias: int, pecas_dias: int, servico_ate: ?Carbon, pecas_ate: ?Carbon} */
    public function prazos(ServiceOrder $origem): array;

    /** Para o formulário: prazos + se hoje está dentro de cada um. */
    public function resumo(ServiceOrder $origem): array;

    /** Motivo da recusa em texto ou null se pode abrir. */
    public function podeAbrir(ServiceOrder $origem): ?string;

    /** O usuário logado pode abrir esta OS? Mesma regra de WorkflowController::order() (:266-282) e ServiceOrderController::view() (:188-197). */
    public function podeVer(ServiceOrder $os): bool;

    public function responsavelSugerido(ServiceOrder $origem): ?Colaborador;

    public function abrir(ServiceOrder $origem, string $motivo, string $causa, ?int $responsavelId): ServiceOrder;

    public function reclassificar(ServiceOrder $retorno, string $causa, ?int $responsavelId, ?string $justificativa): void;

    public function isentar(ServiceOrder $retorno, string $justificativa): void;

    /** Marca/desmarca `em_garantia` nas linhas conforme a cobertura atual, recalcula totais, sincroniza `tipo_os` e a condição de pagamento automática. Devolve quantas linhas mudaram. */
    public function aplicarCobertura(ServiceOrder $retorno): int;

    /** Frase curta para a mensagem de sucesso; vazia para quem não pode ver valores. */
    public function mensagemCobertura(ServiceOrder $retorno): string;
}
```

Regras de cada método:

- `disponivel()`: `Schema::hasColumn` das 5 colunas novas (`quotes.retorno_responsavel_id`, `quotes.garantia_servico_ate`, `quotes.garantia_pecas_ate`, `quotes.retorno_isento`, `quote_items.em_garantia`), memoizado em `static::$memo[$banco.':novo'] ??= …` (false também fica memoizado). `temVinculo()` idem para `tipo_os` e `os_origem_id` (`:vinculo`). Mesmo espírito de `SO/Services/PrevisaoOs.php:22-25`, mas com a chave do banco porque cada oficina tem o seu.
- `prazos()`: `entrega = $origem->entregueEm()`; dias = `(int) WorkshopSettings::get('garantia_servico_dias', 90)` e `…('garantia_pecas_dias', 90)`; `*_ate = dias > 0 && entrega ? entrega->copy()->startOfDay()->addDays(dias) : null`. Sem data de entrega ⇒ `*_ate = null` (tratado como fora do prazo; o evento registra "data de entrega não registrada").
- `resumo()`: acrescenta `dentro` por tipo = `*_ate !== null && now()->startOfDay()->lte(*_ate)`.
- `podeAbrir()`, nesta ordem: OS `Cancelada` → "Esta OS foi cancelada: não há o que retornar."; `! isEntregue()` → "Só dá para abrir retorno de uma OS entregue (Pós-Venda ou Concluída)."; existe retorno aberto (`os_origem_id = id` e etapa fora de `Concluída/Cancelada`) → "Já existe a OS de retorno #N aberta para esta OS."; senão `null`.
- `podeVer()`: `$ids = bouncer()->getAuthorizedUserIds(); if ($ids === null) return true; $mechanicId = auth()->guard('user')->user()?->mechanic_id; return in_array($os->user_id, $ids) || ($mechanicId && $os->mechanic_id == $mechanicId);` (é para decidir se mostra link da origem; quem não puder ver vê só o número).
- `responsavelSugerido()`: `Colaborador::ativos()->where('mechanic_id', $origem->tecnicoDesignadoId())->first()`.
- `abrir()`:
  1. Se `! static::disponivel()` → `ValidationException` ['acao' => 'O banco desta oficina ainda não recebeu a atualização da garantia (migrate-safe). Avise o suporte.'] (a ação só existe com o piloto; as telas de todos continuam normais).
  2. `validarCausaEResponsavel($causa, $responsavelId)`: causa em `CAUSAS`; se `causa !== 'uso_cliente'` o responsável é obrigatório; se informado, precisa ser `Colaborador::ativos()` ("O colaborador escolhido não está ativo.").
  3. Os passos 3, 4 e 5 acontecem dentro de um único `DB::transaction` (o motor de barreiras reage aos eventos dentro dela). Passo 3: relê a origem com `lockForUpdate()`; repete `podeAbrir()` (evita duplo clique); cria a OS com `$this->repository->create([...])` **com os mesmos campos de hoje** (`person_id`, `vehicle_id`, `subject => 'Retorno da OS #'.$origem->id.' - '.(placa ?? nome)`, `workshop_status => Cadastro`, `user_id`, `mechanic_id` da origem, `description => $motivo`); `forceFill` + `save` de `tipo_os='retorno'`, `os_origem_id`, `retorno_causa`, `retorno_responsavel_id`, `garantia_servico_ate`, `garantia_pecas_ate` (datas de `prazos()`), `retorno_isento=false`. **Não** passar `tipo_os` no `create` (evita o evento duplicado do Observer).
  4. Eventos, nesta ordem: `retorno_aberto` na OS nova (dados: `tipo`, `os_origem_id`, `motivo`, `causa`, `responsavel_id`, `responsavel`) — é ele que abre a barreira de 2 h —; `retorno_cobertura` (dados: `cobertura`, `entrega_em`, `servico_dias`, `servico_ate`, `pecas_dias`, `pecas_ate`; **sem valores em R$**); `retorno_vinculado` **na origem** (dados: `retorno_id`, `causa`).
  5. `aplicarCobertura($retorno)` (define `tipo_os` final e, se a cobertura for total, `condicao_pagamento = CONDICAO_GARANTIA`). Se a cobertura for total e a origem tiver `cor_credito`, copia `cor_credito` e `cor_credito_em` da origem (a análise de crédito não se repete porque não há cobrança; a cor nunca aparece fora da tela de quem vê valores).
  6. Fora da transação: `AlertService::raise('retorno_aberto_aviso', 'OS de retorno aberta', 'Retorno da OS #{origem} ({placa}) aberto como OS #{id}. Diagnosticar até {dd/mm/aaaa hh:mm}.', ['chefe'], $retorno)` — o horário vem da instância `diagnostico_retorno` recém-criada (já respeita horário comercial e o modo de teste); se não houver instância, omite a frase do prazo. Só com `StageGate::active()`.
- `reclassificar()`:
  - OS precisa ser retorno e não estar `Concluída/Cancelada` ("Esta OS de retorno já está encerrada.").
  - Valida causa/responsável como na abertura.
  - Se nada mudou → "Nada mudou: escolha outra causa ou outro responsável." Se já havia causa e algo mudou, `justificativa` é obrigatória ("Explique o motivo da mudança.").
  - Mudar **de/para** `uso_cliente` altera a cobrança; isso só é permitido **antes da liberação da execução** (`execucao_liberada_em` nulo) ou quando a OS está isenta. Depois disso: "A execução já foi liberada: a cobrança não muda mais. Só dá para trocar entre execução, peça e diagnóstico."
  - Grava `retorno_causa`/`retorno_responsavel_id`, evento `retorno_classificado` (dados: `causa`, `causa_anterior`, `responsavel_id`, `responsavel_anterior_id`, `justificativa`, `os_origem_id`), chama `aplicarCobertura`. Se havia causa anterior e ela mudou: `AlertService::raise('retorno_causa_alterada', 'Causa do retorno alterada', 'OS #{id} (retorno da OS #{origem}): causa mudou de "{antes}" para "{depois}" por {usuário}. Motivo: {justificativa}.', ['gerente'], $retorno)`.
- `isentar()`:
  - OS precisa ser retorno aberto e **cobrável** (`$retorno->retornoCobravel()`), senão "Esta OS já está sem cobrança."
  - `valor` = soma do `gross_total` das linhas que passariam a `em_garantia` (hoje não estão e `sujeitoAGarantia()`); linhas futuras também entram, mas o valor da alçada é o de agora.
  - **Antes de qualquer transação** (o pedido de alçada precisa sobreviver à exceção): `app(AlcadaService::class)->authorize('retorno_garantia', $retorno, $valor, 'Isenção da cobrança do retorno da OS #{origem}: '.$justificativa)`.
  - Depois: `retorno_isento = true`, `condicao_pagamento = CONDICAO_ISENTO`, `aplicarCobertura`, evento `retorno_isento` (dados: `justificativa`, `linhas`, `ja_coberto_por_garantia` — **sem valores**).
- `aplicarCobertura()`: para cada linha `$item->sujeitoAGarantia() && $retorno->cobre($item->tipo)` define `em_garantia`; quando muda, regrava `total = line_total`; depois `$this->repository->recalculateTotals($retorno->refresh())`, `sincronizarTipo()` (`tipo_os = coberturaDoRetorno()==='garantia' ? 'garantia' : 'retorno'`) e a condição de pagamento automática: se `coberturaTotal()` e a condição está vazia ou é uma das duas automáticas → preenche; se deixou de ser total e a condição é uma das automáticas → `null` (para a trava "Forma de pagamento" voltar a pedir).
- `mensagemCobertura()`: `''` se `! ValoresFinanceiros::can()`; senão "Dentro da garantia: sem cobrança." / "Fora do prazo da garantia: cobrança normal." / "Uso do cliente não é garantia: cobrança normal." / "Isento pela gerência: sem cobrança.".

#### 2. `SO/Resources/views/partials/retorno.blade.php` (cartão "Retorno e garantia")

Incluído por `view.blade.php` (ver abaixo). Variáveis: `$serviceOrder`, `$pilot`, `$canSeeValues`. Esqueleto (classes iguais às de `workflow.blade.php:23-26`):

```blade
@php
    $svc = app(\Webkul\ServiceOrder\Services\RetornoGarantia::class);
    $vinculo = \Webkul\ServiceOrder\Services\RetornoGarantia::temVinculo();
    $acoes = app(\Webkul\ServiceOrder\Services\WorkflowActions::class)->availableFor($serviceOrder);
    $ehRetorno = $serviceOrder->isRetorno();
    $origem = $vinculo && $serviceOrder->os_origem_id ? $serviceOrder->osOrigem : null;
    $filhos = $vinculo && ($ehRetorno || $serviceOrder->isEntregue()) ? $serviceOrder->osRetornos()->get() : collect();
    $podeVerGestao = $canSeeValues || bouncer()->hasPermission('service_orders.auditar'); // causa e responsável
@endphp

@if ($ehRetorno || $filhos->isNotEmpty() || isset($acoes['abrir_retorno']))
    <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" id="retorno-garantia">
        {{-- título + selo do tipo (rótulo de ServiceOrder::TIPOS_OS) --}}
        ...
    </div>
@endif
```

Conteúdo, nesta ordem:

1. **Se é retorno**: linha "Esta OS é um retorno da OS **#N**" (link `admin.service_orders.view` só se o usuário puder ver a origem — usar o mesmo teste de `WorkflowController::order()` `:266-282`, extraído para `RetornoGarantia::podeVer(ServiceOrder $os): bool`; senão só o texto). Abaixo, o laudo da origem (`diagnostico`, recolhido em `<details>`).
2. **Se `$podeVerGestao`**: "Causa: {rótulo}" e "Responsável: {nome}" (de `osResponsavel`, ou "Ninguém (uso do cliente)").
3. **Se `$canSeeValues`**: "Cobrança: {COBERTURAS[coberturaDoRetorno()]}" e, quando houver, "Garantia do serviço até dd/mm/aaaa · peças até dd/mm/aaaa" e, se `valorEmGarantia() > 0`, "Valor de tabela coberto: R$ …".
4. **Se `$pilot`**: prazo de diagnóstico a partir da `BarrierInstance` (`barrier_code = 'diagnostico_retorno'`, mais recente): "Diagnóstico até dd/mm/aaaa hh:mm" + selo conforme `semaforo()`/`cumprida_no_prazo` ("dentro do prazo", "a vencer", "vencido", "diagnosticado no prazo", "diagnosticado fora do prazo").
5. **Retornos desta OS** (`$filhos`): uma linha por retorno: `#id · {tipo} · {causa se $podeVerGestao} · {etapa} · aberta em dd/mm/aaaa hh:mm`, com link.
6. **Formulário "Abrir OS de retorno"** (somente se `isset($acoes['abrir_retorno'])`):
   - se `$svc->podeAbrir($serviceOrder)` devolver texto → mostra o texto em vez do formulário;
   - senão: bloco de resumo (só `$canSeeValues`): "Entregue em dd/mm/aaaa. Garantia do serviço: N dias, até dd/mm/aaaa (dentro do prazo/fora do prazo). Garantia das peças: …" e a regra de cobrança;
   - `POST route('admin.service_orders.workflow.action', [$serviceOrder->id, 'abrir_retorno'])` com `@csrf`; campos `motivo` (texto, `required`, `old()`), `causa` (select **sem opção vazia selecionada**: primeira opção "Escolha...", `required`), `responsavel_id` (select de `Colaborador::ativos()->orderBy('nome')`, cada opção "Nome — Cargo(s)", pré-selecionado `responsavelSugerido()`, primeira opção "Ninguém (uso do cliente)" com valor vazio); botão `primary-button` "Abrir OS de retorno".
   - Mostrar `@error('causa')`, `@error('responsavel_id')`, `@error('motivo')` (a barra de avisos do layout **não** exibe `$errors`, só `session('error')`: `Admin/.../flash-group/index.blade.php:36-40`).
7. **Formulário "Corrigir causa ou responsável"** (somente se `isset($acoes['classificar_retorno'])`): `causa`, `responsavel_id`, `justificativa` (texto), botão "Registrar correção".
8. **Formulário "Isentar a cobrança"** (somente se `isset($acoes['isentar_retorno'])`): `justificativa` (mín. 10 caracteres), botão "Isentar cobrança", texto de ajuda sobre a alçada.

O cartão **não aparece** para OS comum não entregue, nem com o piloto desligado e sem vínculos.

#### 3. `SO/Database/Migrations/2026_11_18_121200_add_retorno_garantia_to_quotes_and_quote_items.php`
Ver "Banco (migrations)".

#### 4. `tests/Feature/Workshop/RetornoGarantiaTest.php`
Ver "Testes Pest a escrever".

### Alterar

#### `SO/Models/ServiceOrder.php`
- `use Illuminate\Support\Carbon;` (`Colaborador` e `ServiceOrderItem` estão no mesmo namespace `Webkul\ServiceOrder\Models`: sem import).
- `$casts` (`:100-127`): `'garantia_servico_ate' => 'date'`, `'garantia_pecas_ate' => 'date'`, `'retorno_isento' => 'boolean'`.
- Constante `TIPOS_OS` (rótulos; a F13 pode reaproveitar): `['normal' => 'Normal', 'retorno' => 'Retorno', 'garantia' => 'Retorno em garantia', 'chamado_externo' => 'Chamado externo', 'preventiva' => 'Preventiva / frota']`.
- Métodos novos (todos só leem atributos; **não consultam coluna ausente**):

```php
public function isRetorno(): bool { return in_array($this->tipo_os, ['retorno', 'garantia'], true); }

public function isEntregue(): bool
{
    return in_array($this->workshop_status, [self::STAGE_POS_VENDA, self::STAGE_CONCLUIDA], true) || $this->saida_em !== null;
}

public function entregueEm(): ?Carbon
{
    $quando = $this->saida_em ?? $this->data_conclusao ?? $this->faturado_em;
    return $quando ? Carbon::parse($quando) : null;
}

public function osOrigem()   { return $this->belongsTo(static::class, 'os_origem_id'); }
public function osRetornos() { return $this->hasMany(static::class, 'os_origem_id')->orderBy('id'); }
public function osResponsavel() { return $this->belongsTo(Colaborador::class, 'retorno_responsavel_id'); }

/** A garantia desta OS de retorno cobre linhas deste tipo ('Serviço' ou 'Peça')? */
public function cobre(string $tipoItem): bool
{
    if (! $this->isRetorno()) return false;
    if ($this->retorno_isento) return true;
    if (in_array($this->retorno_causa, [null, '', 'uso_cliente'], true)) return false;

    $ate = $tipoItem === ServiceOrderItem::TIPO_SERVICO ? $this->garantia_servico_ate : $this->garantia_pecas_ate;

    return $ate !== null && $this->created_at !== null && $this->created_at->toDateString() <= $ate->toDateString();
}

public function coberturaTotal(): bool   { return $this->isRetorno() && ($this->retorno_isento || ($this->cobre(ServiceOrderItem::TIPO_SERVICO) && $this->cobre(ServiceOrderItem::TIPO_PECA))); }
public function retornoCobravel(): bool  { return $this->isRetorno() && ! $this->coberturaTotal(); }
public function semNadaACobrar(): bool   { return $this->isRetorno() && (float) $this->grand_total <= 0.0; }
public function valorEmGarantia(): float { return round((float) $this->items->where('em_garantia', true)->sum(fn ($i) => $i->gross_total), 2); }

/** 'garantia' | 'fora_do_prazo' | 'uso_cliente' | 'isento' | null (não é retorno ou retorno antigo sem prazos gravados) */
public function coberturaDoRetorno(): ?string
{
    if (! $this->isRetorno()) return null;
    if ($this->retorno_isento) return 'isento';
    if ($this->retorno_causa === 'uso_cliente') return 'uso_cliente';
    if ($this->cobre(ServiceOrderItem::TIPO_SERVICO) || $this->cobre(ServiceOrderItem::TIPO_PECA)) return 'garantia';

    return ($this->garantia_servico_ate || $this->garantia_pecas_ate) ? 'fora_do_prazo' : null;
}

public function rotuloTipo(): string { return self::TIPOS_OS[$this->tipo_os] ?? (string) $this->tipo_os; }
```

> `semNadaACobrar()` é o predicado que as outras frentes devem usar para "não há nota, boleto nem pagamento a exigir". `coberturaTotal()` serve para decisões que acontecem antes de existirem linhas (ex.: crédito).

#### `SO/Models/ServiceOrderItem.php`
- `$casts` (`:51-57`): `'em_garantia' => 'boolean'`.
- Constante `FORA_DA_GARANTIA = ['Diária de pátio']` (prefixos de nome que nunca entram na garantia; `RotinaService::lancarDiaria` já nomeia assim — `RotinaService.php:404,410`).
- Método `sujeitoAGarantia(): bool` → tipo Peça ou Serviço e nome sem nenhum dos prefixos.
- `getDiscountTotalAttribute()` (`:113-116`): `if ($this->em_garantia) return 0.0;` antes do cálculo (garantia **não é desconto**).
- `getLineTotalAttribute()` (`:118-125`): `if ($this->em_garantia) return 0.0;` no início.
- `getGrossTotalAttribute()` fica **igual** (valor de tabela).

#### `SO/Models/ServiceOrderEvent.php` (`TIPOS`, após `:82`)
```php
'retorno_vinculado' => 'OS de retorno ligada a esta OS',
'retorno_cobertura' => 'Garantia do retorno avaliada',
'retorno_isento' => 'Cobrança do retorno isentada',
```
(`record()` lança exceção para tipo desconhecido — `ServiceOrderEvent.php:141-143`.)

#### `SO/Services/WorkflowActions.php`
- Construtor (`:35-38`): injetar `protected RetornoGarantia $retornos`.
- `RETORNO_CAUSAS` (`:26-31`) passa a ser `public const RETORNO_CAUSAS = RetornoGarantia::CAUSAS;`.
- `definitions()` (atualizar também o docblock `@return` de `:40-42` com as chaves novas `mensagens`, `piloto`, `oculto`, `so_retorno_cobravel`):
  - `abrir_retorno` (`:208-216`) vira:

```php
'abrir_retorno' => [
    'label' => 'Abrir OS de retorno',
    'etapas' => $etapas(ServiceOrder::STAGE_POS_VENDA, ServiceOrder::STAGE_CONCLUIDA),
    'campos' => [
        'motivo' => ['label' => 'O que o cliente relatou', 'tipo' => 'text'],
        'causa' => ['label' => 'Causa do retorno', 'tipo' => 'select', 'opcoes' => self::RETORNO_CAUSAS],
        'responsavel_id' => ['label' => 'Responsável pela falha', 'tipo' => 'colaborador', 'opcional' => true],
    ],
    'regras' => [
        'motivo' => 'required|string|max:255',
        'causa' => 'required|in:'.implode(',', array_keys(self::RETORNO_CAUSAS)),
        'responsavel_id' => 'required_unless:causa,uso_cliente|nullable|integer|exists:colaboradores,id',
    ],
    'mensagens' => [
        'motivo.required' => 'Descreva o que o cliente relatou.',
        'causa.required' => 'Informe a causa do retorno (execução, peça, diagnóstico ou uso do cliente).',
        'causa.in' => 'Escolha uma das causas da lista.',
        'responsavel_id.required_unless' => 'Informe o responsável pela falha. Só o "Uso do cliente" dispensa.',
        'responsavel_id.exists' => 'Escolha um colaborador da lista.',
    ],
    'piloto' => true,
    'oculto' => true,   // tem cartão próprio (partials/retorno.blade.php)
],
```
  - `classificar_retorno` (`:217-224`): rótulo "Corrigir causa e responsável do retorno"; campos `causa`, `responsavel_id` (opcional), `justificativa` (`text`, opcional); regras `causa required|in:…`, `responsavel_id nullable|integer|exists:colaboradores,id`, `justificativa nullable|string|max:255`; manter `so_tipos`, `auditar => true`; acrescentar `piloto => true`, `oculto => true`. A regra "justificativa obrigatória quando já havia causa" fica no serviço (a regra estática não sabe disso).
  - Nova `isentar_retorno`: rótulo "Isentar a cobrança do retorno"; `etapas` Cadastro, Ordem de Serviço, Diagnóstico, Orçamento, Negociação, Em Execução, Veículo em Teste, Faturamento; campo `justificativa` (`required|string|min:10|max:255`); `so_tipos => ['retorno','garantia']`; `so_retorno_cobravel => true`; `auditar => true`; `valores => true`; `piloto => true`; `oculto => true`.
- `isAvailable()` (`:253-276`), antes dos testes de `valores`/`auditar`:

```php
if (! empty($definition['piloto']) && ! StageGate::active()) return false;
if (! empty($definition['so_retorno_cobravel']) && ! $serviceOrder->retornoCobravel()) return false;
```
- `perform()` (`:294-319`): `'abrir_retorno' => $this->openReturn($os, $dados)`, `'classificar_retorno' => $this->classifyReturn($os, $dados)`, `'isentar_retorno' => $this->exemptReturn($os, $dados['justificativa'])`.
- `openReturn()` (`:588-608`) reescrito: chama `$this->retornos->abrir($os, $dados['motivo'], $dados['causa'], isset($dados['responsavel_id']) ? (int) $dados['responsavel_id'] : null)`, mantém `session()->flash('retorno_id', $retorno->id)` e devolve `'OS de retorno #'.$retorno->id.' aberta e ligada a esta OS. '.$this->retornos->mensagemCobertura($retorno)`.
- `classifyReturn()` (`:610-617`) → `$this->retornos->reclassificar($os, $dados['causa'], …, $dados['justificativa'] ?? null)`; retorno "Causa e responsável do retorno registrados."
- `exemptReturn()` novo → `$this->retornos->isentar(...)`; retorno "Cobrança do retorno isentada e registrada."
- `price()` (`:383-415`): pular o `authorize('preco_abaixo_minimo', …)` (`:387-397`) quando `$item->em_garantia` (não há venda).
- `setTipo()` (`:329-334`): `if ($os->isRetorno()) throw ValidationException::withMessages(['acao' => ['OS de retorno não muda de tipo.']]);`

#### `SO/Http/Controllers/WorkflowController.php`
- `action()` (`:251`): `$this->validate(request(), $definition['regras'], $definition['mensagens'] ?? [])` (única mudança; os testes que esperam `assertSessionHasErrors('nota')` continuam valendo).

#### `SO/Resources/views/partials/workflow_actions.blade.php`
- `:2`: `$acoes = collect(app(WorkflowActions::class)->availableFor($serviceOrder))->reject(fn ($a) => ! empty($a['oculto']))->all();`
- `:14-16`: trocar `str_replace('_',' ',$serviceOrder->tipo_os)` por `{{ $serviceOrder->rotuloTipo() }}` e envolver em `<a href="#retorno-garantia">` quando for retorno.

#### `SO/Resources/views/view.blade.php`
- `:27`: acrescentar `&& ! $serviceOrder->semNadaACobrar()` à condição dos botões NFS-e/NF-e (não há nota de OS sem cobrança).
- Após o cartão de dados (`:136`, antes de `checklist-entrada` em `:138`): `@include('service_order::partials.retorno', ['serviceOrder' => $serviceOrder, 'pilot' => $pilot, 'canSeeValues' => $canSeeValues])`.
- Linha de total do item (`:175`): `{{ core()->formatBasePrice($item->line_total) }}` + `@if ($item->em_garantia) <span class="ml-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700">Garantia</span> @endif` (já está dentro de `@if ($canSeeValues)`).
- Rodapé da tabela (após `:233`): linha "Coberto pela garantia" com `valorEmGarantia()` quando `> 0` (dentro de `@if ($canSeeValues)`). "Descontos" (`:227`) passa a mostrar só desconto comercial porque `sub_total` deixa de contar a garantia.
- Dica no bloco "Adicionar item" (`:243-247`): se `$serviceOrder->cobre('Serviço') || $serviceOrder->cobre('Peça')`, texto "Os itens lançados nesta OS entram em garantia (sem cobrança)." só se `$canSeeValues`.
- Faturamento (`:344-346`): `@if ($canSeeValues && ! $serviceOrder->semNadaACobrar())` (some o formulário de boleto/Asaas).

#### `SO/Resources/views/partials/workflow.blade.php`
- `$eventosRestritos` (`:20`): acrescentar `'retorno_cobertura'`, `'retorno_isento'`, `'retorno_classificado'` (o técnico não vê causa nem cobertura).
- Linha do tempo (`:491-499`): para `retorno_classificado` mostrar "(Falha de execução; antes: Peça) — justificativa"; para `retorno_cobertura` mostrar o rótulo de `COBERTURAS`. Tudo a partir de `dados_json` (sem valores).

#### `SO/Repositories/ServiceOrderRepository.php`
- `recalculateTotals()` (`:64-82`): `sub_total` passa a somar só as linhas cobradas:

```php
$cobradas = $serviceOrder->items->reject(fn ($item) => $item->em_garantia);
$subTotal = round($cobradas->sum(fn ($item) => $item->gross_total), 2);
```
(`$netItems` continua somando `line_total`, que já é 0 nas linhas em garantia). Sem linhas em garantia o resultado é idêntico ao de hoje.

#### `SO/Services/ServiceOrderItemService.php`
- `addItem()` (`:26-79`), logo depois de `$item->adicional = …` (`:57`) e antes do primeiro `save()` (`tipo` e `name` já estão definidos):

```php
// OS de retorno coberta pela garantia: a linha nasce sem cobrança (o preço de tabela fica gravado).
$deveSerGarantia = array_key_exists('garantia', $data) ? (bool) $data['garantia'] : $serviceOrder->cobre($data['tipo']);

if ($deveSerGarantia && $item->sujeitoAGarantia()) {
    $item->em_garantia = true;
}
```
O `$item->total = $item->line_total` que já existe (`:61`) passa a gravar 0. A chave opcional `garantia` serve a quem precisar forçar (`false` nunca marca).

#### `SO/Http/Controllers/ServiceOrderStageController.php`
- `updateNegociacao()` (`:155-166`): dentro do `foreach`, depois de tratar `item_funcionarios`, `if ($item->em_garantia) { continue; }` (linha em garantia não recebe desconto; sem isso o formulário poderia gravar um `discount_amount` que sobreviveria se a garantia fosse retirada).
- `checkAlcadas()` (`:198-205`): `$bruto`, `$novo` e `$atual` passam a ignorar as linhas `em_garantia` (senão o % de desconto da parte cobrada fica diluído).

#### `SO/Resources/views/partials/negociacao.blade.php`
- `:4`: `$grossTotal` ignora linhas `em_garantia`.
- Linha da tabela (`:71`): `data-gross="{{ $item->em_garantia ? 0 : $item->gross_total }}"`.
- Célula do desconto (`:85-93`): para linha `em_garantia`, mostrar o selo "Garantia" e manter um `<input type="text" name="item_discounts[id]" value="0" readonly class="item-discount …">` (o JS da página faz `row.querySelector('.item-discount').value`; sem o input dá erro). `$initial` (`:10-14`) já vem 0 porque `discount_total` é 0 na garantia.

#### `SO/Services/StageGate.php`
- `diagnosticoParaOrcamento()` (`:165-196`), antes do `return $missing`:

```php
if ($os->isRetorno()) {
    if (! $os->retorno_causa) {
        $missing[] = 'Causa do retorno (execução, peça, diagnóstico ou uso do cliente)';
    } elseif ($os->retorno_causa !== 'uso_cliente' && ! $os->retorno_responsavel_id) {
        $missing[] = 'Responsável pelo retorno';
    }
}
```
- `faturamentoParaSaida()` (`:288-310`), logo depois do teste do checklist de saída e antes da exceção de frota:

```php
// OS de retorno sem nada a cobrar (garantia ou isenção): não há nota nem pagamento a exigir.
if ($os->semNadaACobrar()) {
    return $missing;
}
```
- `negociacaoParaExecucao()` e `cadastroParaOs()` **não mudam**: a condição de pagamento automática ("Garantia (sem cobrança)") e a cor de crédito herdada da origem já satisfazem os dois testes de hoje.

#### `SO/Services/StageAdvanceService.php`
- `:62-64`: `$semNf = … && ! $this->gate->hasNotaFiscal($serviceOrder) && ! $serviceOrder->semNadaACobrar();` (retorno sem cobrança não dispara o alerta crítico "Saída sem nota fiscal" nem a trava automática).

#### `SO/Services/Indicators/IndicatorCalculator.php`
- `retornos()` (`:589-595`): excluir canceladas — `->where(fn ($q) => $q->whereNull('quotes.workshop_status')->orWhere('quotes.workshop_status', '!=', ServiceOrder::STAGE_CANCELADA))` (mesmo padrão de `osBase()`, `:224-225`). Efeito: retorno aberto por engano e cancelado deixa de contar em CHE-10 e CHE-14.
- `produtividade()` (`:613-616`): numerador sem linhas em garantia: `->where('tipo', SERVICO)->reject(fn ($i) => $i->em_garantia)->sum(…hours)` (hora de garantia não é "hora padrão faturada"; o retrabalho tem de aparecer como produtividade menor, não maior).
- `margemDePecas()` (`:856-868`): `$itens = …->get()->reject(fn ($i) => $i->em_garantia);` (peça de garantia não entra na margem de venda do PEC-12, que alimenta bônus).
- `pecasAbaixoDoMinimo()` (`:1231-1253`): `$itens` sem as `em_garantia` (DRE-07).
- `nfAntesDaSaida()` (`:874-880`): o `filter` passa a ter `&& ! $item->semNadaACobrar()` (OS sem cobrança não tem nota; fica fora da base do FIN-02).
- **Não alterar** as fórmulas de CHE-10, CHE-13, CHE-14, TEC-10, TEC-02 nem `osBase()`.

#### `SO/Services/Indicators/PainelDonoService.php`
- `build()` (`:54-76`): nova chave `'retornos' => $this->retornos($from, $to)`.
- Novo método:

```php
/**
 * Retornos do mês por causa e por responsável (OS de retorno abertas no período, sem as canceladas).
 *
 * @return array{total: int, em_garantia: int, sem_causa: int, por_causa: list<array{causa: string, rotulo: string, quantidade: int, percentual: ?float, os_ids: list<int>}>, por_responsavel: list<array{nome: string, quantidade: int}>, valor_nao_cobrado: float, valor_nao_cobrado_fmt: string}
 */
public function retornos(Carbon $from, Carbon $to): array
```
  Sem `RetornoGarantia::temVinculo()` devolve a estrutura zerada. `em_garantia` = retornos com `coberturaDoRetorno()` em `garantia|isento` e `semNadaACobrar()`. `por_responsavel` só com `RetornoGarantia::disponivel()` (top 10 por quantidade, nome do `Colaborador`). `valor_nao_cobrado` = soma de `valorEmGarantia()`. Usa `IndicatorFormatter::value($v, 'brl')` para o texto.
- `cartoes()` (`:214-235`): ticket médio ignora OS sem nada a cobrar: `$comCobranca = $faturadas->reject(fn ($os) => $os->semNadaACobrar());` e `… $comCobranca->count() ? $faturamento / $comCobranca->count() : null`.

#### `SO/Resources/views/mentoria/painel.blade.php`
Novo cartão logo após o bloco "Fluxo do caminhão" (`:62`): título "Retornos e garantias do mês" com o resumo ("N OS de retorno · M em garantia (sem cobrança)"), tabela **Por causa** (rótulo, quantidade, %, e os números das OS como links `admin.service_orders.view`), tabela **Por responsável**, rodapé "Valor de tabela não cobrado: R$ … · Só a falha de execução entra no retrabalho (CHE-10)". Sem retornos: "Nenhuma OS de retorno aberta neste mês."

#### `SO/Resources/views/mentoria/painel-pdf.blade.php`
Mesmo bloco como `<h2>Retornos e garantias do mês</h2>` + duas tabelas simples, depois do fluxo (`:40`) — o roteiro 69 exige PDF "completo".

#### `SO/Http/Controllers/PainelDonoController.php` e `SO/Resources/views/mentoria/painel-celular.blade.php`
- `indicadores()` (`:79-99`): acrescentar `'retornos' => $painel['retornos']` **no nível de cima** (não em `blocos`, para não quebrar `MentoriaPanelTest.php:72`).
- `renderIndicadores()` (`painel-celular.blade.php:106-124`): depois do laço de `blocos`, se `d.retornos && d.retornos.total`, `h += '<h2>Retornos do mês</h2>'` e um `<div class="card row">` por causa (rótulo + quantidade).

#### `SO/Resources/views/print.blade.php`
- Cabeçalho (`:121-126`): sob o número da OS, `@if ($serviceOrder->isRetorno() && $serviceOrder->os_origem_id) <div class="shop-line"><strong>Retorno da OS nº {{ str_pad((string) $serviceOrder->os_origem_id, 6, '0', STR_PAD_LEFT) }}</strong></div> @endif`.
- Linhas (`:238-242`), só dentro de `@if ($mostrarValores)`: coluna "Desconto" mostra `Garantia` quando `$item->em_garantia`.
- Totais (`:279-288`), dentro de `$mostrarValores`: após "Total", linha pequena "Serviços e peças cobertos pela garantia: R$ … (valor de tabela, não cobrado)" quando `valorEmGarantia() > 0`. `$discountTotal` (`:40`) continua `sub_total - grand_total`, que agora é só desconto comercial.
- O técnico (`$mostrarValores` falso) não vê "Garantia", condição de pagamento nem valores (como hoje). Nenhuma informação de crédito entra no documento.

#### `SO/Resources/views/public/tracking.blade.php`
- `:159`: `<div class="stage-kicker">OS #{{ $serviceOrder->id }}@if ($serviceOrder->isRetorno()) &middot; Retorno @endif</div>`. Nada de causa, garantia, isenção, crédito ou valores.

#### `SO/Services/TecnicoTasks.php` e `SO/Resources/views/tecnico/index.blade.php`
- `diagnosticos()` (`:54-82`): chave `'retorno' => $os->isRetorno() ? ['origem_os' => $os->os_origem_id, 'laudo_origem' => <texto do laudo da origem, Str::limit 600>] : null`. O laudo vem de `ServiceOrder::withoutGlobalScopes()->whereKey($os->os_origem_id)->value('diagnostico')`.
- `operacoes()` (`:103-117`): `'retorno' => (bool) $item->serviceOrder?->isRetorno()`.
- **Não** expor causa, responsável, cobertura nem qualquer nome de campo que comece com `total`, `preco`, `price`, `desconto`, `discount`, `credito`, `comissao`, `grand`, `valor_unitario` (teste `TecnicoValoresTest.php:118`).
- `tecnico/index.blade.php`: no cartão de diagnóstico (`:205`) acrescentar `(d.retorno ? ' · <b>RETORNO da OS #' + d.retorno.origem_os + '</b>' : '')` (com `esc`) e um `<details>` "Laudo da OS de origem" com `esc(d.retorno.laudo_origem)`; no cartão de operação (`:146`) o mesmo chip quando `op.retorno`.

#### `SO/Services/CommissionReportService.php`
- Laço de itens (`:47-81`): `if ($item->em_garantia) { continue; }` logo no início (antes do aviso `sem_funcionario`).
- Linha de venda (`:83-101`): acrescentar `&& ! $order->semNadaACobrar()` à condição de `$owner` (não lista "Total da OS 0,00" para o consultor).
- Efeito: a tela e o CSV de Comissões, e o cartão "Minhas comissões" (`PainelComissao.php:69-113`, que lê este relatório), não trazem linhas de garantia. Garantia nunca soma base.

#### `SO/Http/Controllers/MarketingController.php`
- `orders()` (`:140-146`) e a consulta detalhada do `export()` (`:57-65`): excluir retorno/garantia — retorno não é canal de aquisição e um retorno gratuito derrubaria o ticket médio do canal "Não informado":

```php
->when(RetornoGarantia::temVinculo(), fn ($q) => $q->where(fn ($w) => $w->whereNull('tipo_os')->orWhereNotIn('tipo_os', ['retorno', 'garantia'])))
```

#### `SO/Services/Rotina/RotinaService.php` e `SO/Resources/views/rotina/patio.blade.php`
- `quadroDePatio()` (`:88-96`): `'retorno' => $os->isRetorno()`.
- `patio.blade.php:22-27`: `@if (! empty($caminhao['retorno'])) <b class="rounded bg-red-600 px-1 text-white">RETORNO</b> @endif` ao lado da placa (o Chefe prioriza o diagnóstico de 2 h).

#### `SO/Services/Alcada/AlcadaCatalog.php`
- `:37`: o tema `retorno_garantia` passa a `automatico = true` (último argumento de `$t(…)`), porque agora a isenção bloqueia de fato até a aprovação (a matriz mostra "bloqueia automaticamente"). Textos e limites ficam como o patrão escreveu. Defaults da regra continuam em branco (não bloqueia até o dono preencher).

#### `SO/Http/Controllers/ServiceOrderController.php`
- `destroy()` (`:297-316`): com o piloto ligado, recusar (JSON 400, mensagem "Esta OS tem OS de retorno ligadas e não pode ser excluída.") se `RetornoGarantia::temVinculo()` e existir OS com `os_origem_id = $id` — apagar a origem destruiria o vínculo que alimenta CHE-10/TEC-10. Piloto desligado: como hoje.

#### `SO/Resources/views/ficha/edit.blade.php`
- `:51-52`: `title="Usado ao abrir OS de retorno: dentro do prazo e com causa de execução, peça ou diagnóstico, o retorno não é cobrado. 0 = sem garantia."` nos dois campos (sem mudar nome nem valor).

#### `SO/Resources/lang/pt_BR/app.php` e `SO/Resources/lang/en/app.php`
Chave nova `retorno` (ver "Textos da interface"). Manter os dois arquivos.

#### `tests/Feature/Workshop/AllBarriersTest.php`
Atualizar `:605-630` (ver "Testes").

## Banco (migrations)

**Uma migration**, aditiva e idempotente: `SO/Database/Migrations/2026_11_18_121200_add_retorno_garantia_to_quotes_and_quote_items.php` (horário escolhido por não existir ainda nenhum `2026_11_18_*`; se outra frente pegar o mesmo, renumerar mantendo a ordem crescente — ela só depende de `2026_11_17_100000` e `2026_11_17_100400`).

```php
public function up(): void
{
    $quotes = [
        'retorno_responsavel_id' => fn (Blueprint $t) => $t->unsignedInteger('retorno_responsavel_id')->nullable()->index(),
        'garantia_servico_ate' => fn (Blueprint $t) => $t->date('garantia_servico_ate')->nullable(),
        'garantia_pecas_ate' => fn (Blueprint $t) => $t->date('garantia_pecas_ate')->nullable(),
        'retorno_isento' => fn (Blueprint $t) => $t->boolean('retorno_isento')->default(false),
    ];

    foreach ($quotes as $coluna => $definir) {
        if (! Schema::hasColumn('quotes', $coluna)) {
            Schema::table('quotes', $definir);
        }
    }

    if (! Schema::hasColumn('quote_items', 'em_garantia')) {
        Schema::table('quote_items', fn (Blueprint $t) => $t->boolean('em_garantia')->default(false));
    }
}

public function down(): void   // só remove o que existir
{
    foreach (['retorno_responsavel_id', 'garantia_servico_ate', 'garantia_pecas_ate', 'retorno_isento'] as $coluna) {
        if (Schema::hasColumn('quotes', $coluna)) {
            Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn($coluna));
        }
    }

    if (Schema::hasColumn('quote_items', 'em_garantia')) {
        Schema::table('quote_items', fn (Blueprint $t) => $t->dropColumn('em_garantia'));
    }
}
```

- Significado: `retorno_responsavel_id` = `colaboradores.id` (sem FK, como as demais colunas de vínculo do projeto); `garantia_servico_ate`/`garantia_pecas_ate` = último dia coberto, calculado na abertura (auditável: a Ficha pode mudar depois); `retorno_isento` = isenção por exceção; `quote_items.em_garantia` = linha sem cobrança por garantia/isenção.
- Sem backfill: não há retorno em produção ainda; retorno antigo (sem as datas) simplesmente não cobre nada e segue cobrança normal.
- Aplicar nos testes: `$env:DB_DATABASE='krayin_test'; php artisan migrate --path=packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_121200_add_retorno_garantia_to_quotes_and_quote_items.php`. No `krayin_crm` só se a instrução mandar.
- **Tolerância a coluna ausente** (servidor recebe o código antes do `migrate-safe`): atributos novos só são lidos (retornam `null`); nenhuma consulta SQL nas colunas novas roda sem `RetornoGarantia::disponivel()`; consultas em `tipo_os`/`os_origem_id` só com `RetornoGarantia::temVinculo()`; a ação de abrir lança erro de validação claro em vez de 500. Telas que todo usuário abre (`view.blade.php`, Marketing, Painel) seguem funcionando.
- Cada oficina tem banco próprio: a migration precisa rodar também nos bancos das oficinas (como isso é feito é da F00).

## ACL, menu, rotas e configurações

- **ACL**: nenhuma chave nova. Reaproveita `service_orders.edit` (rota `admin.service_orders.workflow.action`, `SO/Config/acl.php:59`), `service_orders.auditar` (corrigir causa/responsável e isentar, checado em `WorkflowController.php:247-249`) e `ver_valores_financeiros` (isentar e ver cobertura). Para a F05, perfil por perfil:
  - Consultor técnico: `service_orders.edit` (abre o retorno); com `ver_valores_financeiros` vê a cobertura.
  - Chefe de oficina: `service_orders.auditar` (corrige causa/responsável).
  - Gerente e Proprietário: `service_orders.edit`, `service_orders.auditar` e `ver_valores_financeiros` (isentam).
  - Técnico: nenhuma dessas; vê só o chip "Retorno" e o laudo da origem.
- **Menu**: nada novo (o cartão fica na tela da OS; o Painel do Dono ganha um bloco).
- **Rotas**: nenhuma nova. Tudo passa por `POST /admin/service-orders/{id}/workflow/acao/{acao}` (`SO/Routes/web.php:116`, nome `admin.service_orders.workflow.action`) com as ações `abrir_retorno`, `classificar_retorno` e `isentar_retorno`.
- **WorkshopSettings**: nenhuma chave nova. Passa a **usar** `garantia_servico_dias` e `garantia_pecas_dias` (Ficha). O prazo de 2 h é a regra `diagnostico_retorno` em Configurações > Indicadores e alertas (`prazo_minutos = 120`, destino `chefe`, nível 2 `gerente` +60).
- **Matriz de alçadas**: tema `retorno_garantia` (existente) passa a bloquear a isenção. Quem preenche: "Chefe decide sozinho até R$ A" (coluna "sozinho"), "Gerente até R$ B" (coluna "gerente"); acima disso, Proprietário. O valor comparado é o **valor de tabela que deixa de ser cobrado** (ver "Decisões em aberto").
- **Eventos novos**: `retorno_vinculado`, `retorno_cobertura`, `retorno_isento`.
- **Códigos de alerta novos**: `retorno_aberto_aviso` (Chefe) e `retorno_causa_alterada` (Gerente); nenhum leva valor em R$; o proprietário não recebe (`AlertService::raise` já retira o dono de alertas não críticos).

## Textos da interface (pt-BR)

Tudo em `Resources/lang/pt_BR/app.php` e espelhado em `en/app.php` sob a chave `retorno` (as etiquetas dentro de `WorkflowActions::definitions()` e as mensagens do serviço seguem o estilo do arquivo: texto direto em português).

**Cartão da OS**
- Título: "Retorno e garantia". Selo do tipo: "Retorno" / "Retorno em garantia" (`ServiceOrder::TIPOS_OS`).
- Vínculo: "Esta OS é um retorno da OS #N" · "Retornos desta OS" · "Nenhum retorno aberto para esta OS." · "Laudo da OS de origem".
- Rótulos: "Causa", "Responsável", "Cobrança", "Diagnóstico até dd/mm/aaaa hh:mm", "Diagnosticado no prazo", "Diagnosticado fora do prazo", "A vencer", "Vencido".
- Coberturas (`RetornoGarantia::COBERTURAS`): "Garantia: sem cobrança" · "Fora do prazo da garantia: cobrança normal" · "Uso do cliente: não é garantia, cobrança normal" · "Isento pela gerência: sem cobrança".
- Abrir: título "Abrir OS de retorno"; ajuda "Use quando o cliente volta por causa de um serviço desta OS. A nova OS nasce no Cadastro, fica ligada a esta e o diagnóstico tem prazo de 2 h."; campos "O que o cliente relatou", "Causa do retorno" (primeira opção "Escolha..."), "Responsável pela falha" (ajuda: "Quem executou, diagnosticou ou comprou a peça. Só o 'Uso do cliente' dispensa."; opção vazia "Ninguém (uso do cliente)"); botão "Abrir OS de retorno".
- Resumo da garantia: "Entregue em dd/mm/aaaa. Garantia do serviço: N dias, até dd/mm/aaaa (dentro do prazo | fora do prazo | sem garantia na Ficha). Garantia das peças: N dias, até dd/mm/aaaa (…)." e "Dentro do prazo e com causa de execução, peça ou diagnóstico, o retorno não é cobrado. Uso do cliente e fora do prazo seguem a cobrança normal."
- Corrigir: título "Corrigir causa ou responsável"; campo "Justificativa"; botão "Registrar correção".
- Isentar: título "Isentar a cobrança"; ajuda "A gerência assume o custo deste retorno. Fica registrado quem isentou e por quê; acima da alçada 'Retorno e garantia' o pedido vai para aprovação."; botão "Isentar cobrança".
- Selos de linha: "Garantia". Rodapé: "Coberto pela garantia". Dica ao lançar item: "Os itens lançados nesta OS entram em garantia (sem cobrança)."
- Chips: "RETORNO" (pátio) e "RETORNO da OS #N" (técnico).

**Mensagens de sucesso (flash)**
- "OS de retorno #N aberta e ligada a esta OS. Dentro da garantia: sem cobrança." / "…Fora do prazo da garantia: cobrança normal." / "…Uso do cliente não é garantia: cobrança normal." / "…Isento pela gerência: sem cobrança." (a frase final some para quem não vê valores).
- "Causa e responsável do retorno registrados." · "Cobrança do retorno isentada e registrada."

**Mensagens de erro**
- "Descreva o que o cliente relatou." · "Informe a causa do retorno (execução, peça, diagnóstico ou uso do cliente)." · "Escolha uma das causas da lista." · "Informe o responsável pela falha. Só o \"Uso do cliente\" dispensa." · "Escolha um colaborador da lista." · "O colaborador escolhido não está ativo."
- "Só dá para abrir retorno de uma OS entregue (Pós-Venda ou Concluída)." · "Esta OS foi cancelada: não há o que retornar." · "Já existe a OS de retorno #N aberta para esta OS."
- "Nada mudou: escolha outra causa ou outro responsável." · "Explique o motivo da mudança." · "A execução já foi liberada: a cobrança não muda mais. Só dá para trocar entre execução, peça e diagnóstico." · "Esta OS de retorno já está encerrada." · "Esta OS já está sem cobrança." · "A justificativa precisa ter pelo menos 10 caracteres." · "OS de retorno não muda de tipo."
- "O banco desta oficina ainda não recebeu a atualização da garantia (migrate-safe). Avise o suporte." · "Esta OS tem OS de retorno ligadas e não pode ser excluída."
- Trava do Avançar: "Causa do retorno (execução, peça, diagnóstico ou uso do cliente)" · "Responsável pelo retorno".

**Eventos (linha do tempo)**: "OS de retorno aberta" (existente) · "Causa do retorno classificada" (existente) · "OS de retorno ligada a esta OS" · "Garantia do retorno avaliada" · "Cobrança do retorno isentada".

**Alertas**: "OS de retorno aberta — Retorno da OS #N (PLACA) aberto como OS #M. Diagnosticar até dd/mm/aaaa hh:mm." · "Causa do retorno alterada — OS #M (retorno da OS #N): causa mudou de \"Peça\" para \"Falha de execução\" por Fulano. Motivo: …"

**Painel do Dono**: "Retornos e garantias do mês" · "OS de retorno" · "em garantia (sem cobrança)" · "Por causa" · "Por responsável" · "Valor de tabela não cobrado" · "Só a falha de execução entra no retrabalho (CHE-10)." · "Nenhuma OS de retorno aberta neste mês." · (celular) "Retornos do mês".

**Impressão**: "Retorno da OS nº 000010" · "Garantia" · "Serviços e peças cobertos pela garantia: R$ … (valor de tabela, não cobrado)". **Acompanhamento**: "OS #12 · Retorno".

**Ficha (dica)**: "Usado ao abrir OS de retorno: dentro do prazo e com causa de execução, peça ou diagnóstico, o retorno não é cobrado. 0 = sem garantia."

## Testes Pest a escrever

Arquivo novo `tests/Feature/Workshop/RetornoGarantiaTest.php` (usa `BuildsWorkshop`: `enablePilot`, `makeOs`, `makeUser`, `makeMechanic`, `at`). Funções auxiliares globais com prefixo `retorno` (nomes únicos entre arquivos): `retornoCenario(array $ficha = [], int $diasDesdeSaida = 20): array` (liga o piloto com `enablePilot(['garantia_servico_dias' => 90, 'garantia_pecas_dias' => 90] + $ficha, ['diagnostico_retorno'])`, fixa `at('2026-11-18 10:00:00')` — quarta-feira —, cria `Mechanic` + `Colaborador` ativo ligado a ele, usuários consultor (`service_orders`, `service_orders.view`, `service_orders.edit`, `ver_valores_financeiros`), chefe (`…edit`, `service_orders.auditar`), gerente (`…edit`, `service_orders.auditar`, `ver_valores_financeiros`), técnico (`tecnico`, `view_permission individual`, sem valores) e uma OS de origem em `Pós-Venda` com `saida_em = now()->subDays($diasDesdeSaida)` e `cor_credito = 'verde'`), `retornoAbrir(array $c, array $dados = [], $quem = null)` (POST em `admin.service_orders.workflow.action` com `abrir_retorno`).

Mapeamento: **Teste do PDF (B6)** = T1, T2, T13; **roteiro linha 53** = T1, T3, T13; roteiro 6 (técnico) = T18; roteiro 68 (lista as OS) = T13; roteiro 69 (PDF completo) = T19.

| # | Título (pt-BR) | O que verifica |
|---|---|---|
| T1 | `abrir retorno de uma OS entregue cria a OS ligada à original, com causa, responsável e o prazo de diagnóstico de 2 h` | Redireciona para a OS nova; `tipo_os='garantia'` (20 dias da saída, causa execução), `os_origem_id`, `retorno_causa`, `retorno_responsavel_id`, mesmo `vehicle_id`/`person_id`/`user_id`, etapa Cadastro, `garantia_servico_ate`/`garantia_pecas_ate` = saída + 90 dias; eventos: `retorno_aberto` (1 na nova), `retorno_cobertura` (nova), `retorno_vinculado` (origem); `BarrierInstance` `diagnostico_retorno` aberta com `vence_em = 2026-11-18 12:00`; alerta `retorno_aberto_aviso` ao chefe (sem "R$" na mensagem) |
| T2 | `a OS de origem e a de retorno mostram o vínculo uma na outra` | GET da origem contém "Retornos desta OS" e o link da OS nova; GET da nova contém "Esta OS é um retorno da OS #N" e o link da origem |
| T3 | `a causa é obrigatória, e o responsável também, menos no uso do cliente` | Erros de **regra de validação** (controller) aparecem em `$errors`: sem `causa` → `assertSessionHasErrors('causa')` e nenhuma OS criada; causa inválida → idem; `execucao` sem responsável → `assertSessionHasErrors('responsavel_id')`; `uso_cliente` sem responsável → abre. Erros de **regra de negócio** (serviço) vêm como `session('error')`: colaborador inativo → `assertSessionHas('error')` com "O colaborador escolhido não está ativo." |
| T4 | `só OS entregue abre retorno, um retorno aberto por vez, e sem piloto nada muda` | OS em Orçamento → ação indisponível (sessão `error`), 0 retornos; segundo POST com o primeiro aberto → erro "Já existe a OS de retorno #N"; depois de o primeiro ir a `Cancelada`, abre; piloto desligado → indisponível |
| T5 | `diagnóstico de retorno em 2 h: chefe ao vencer, gerente 1 h depois, e concluir a tempo cumpre` | `tick(12:00)` → alerta nível 1 ao chefe; `tick(13:00)` → nível 2 ao gerente; outro retorno com `diagnostico_fim` às 11:30 → `cumprida_no_prazo = true` e CHE-13 = 100 |
| T6 | `dentro da garantia da Ficha o retorno não é cobrado` | Serviço (2 h × 500 = 1000) e peça (2 × 300 = 600) lançados com `ServiceOrderItemService::addItem` → `em_garantia = true` nas duas, `line_total = 0`, `grand_total = 0`, `sub_total = 0`, `valorEmGarantia() = 1600`, `condicao_pagamento = 'Garantia (sem cobrança)'`, `cor_credito` herdada da origem; peça lançada pelo técnico pela rota `admin.tecnico.diagnostico.part` (criar uma `Part` com `valor_unitario`; o `mechanic_id` do técnico precisa ser o designado da OS de retorno, que herda o da origem) também nasce em garantia, sem o técnico ver preço |
| T7 | `serviço e peças têm prazos próprios na Ficha` | Ficha 90/30 e 70 dias desde a saída: serviço em garantia, peça cobrada; `grand_total` = só a peça; `coberturaDoRetorno()='garantia'` mas `coberturaTotal()=false` (não herda crédito) |
| T8 | `fora do prazo da garantia segue a cobrança normal` | 120 dias desde a saída → `tipo_os='retorno'`, `coberturaDoRetorno()='fora_do_prazo'`, linhas cobradas, forma de pagamento volta a ser exigida; evento `retorno_cobertura` com `cobertura='fora_do_prazo'` |
| T9 | `uso do cliente não é garantia, mesmo dentro do prazo` | 20 dias, `causa=uso_cliente` → cobrança normal, `coberturaDoRetorno()='uso_cliente'`; ficha com 0 dia (serviço e peças) → nunca cobre |
| T10 | `isenção: a gerência isenta com justificativa, a alçada vale quando há limite e o técnico leva 403` | Gerente isenta retorno fora do prazo → linhas em garantia, `retorno_isento`, evento `retorno_isento`, condição "Isento…"; justificativa vazia ou com menos de 10 caracteres → `assertSessionHasErrors('justificativa')`; técnico → 403; retorno já totalmente coberto → ação indisponível (`session('error')`). Alçada: preencher `AlcadaRegra` `retorno_garantia` com `limite_sozinho = 100` e `limite_gerente = 5000`; um usuário de perfil `chefe` com `service_orders.auditar` **e** `ver_valores_financeiros` (nível 0) tenta isentar um retorno com 1000 de valor de tabela → `AlcadaPedido` criado (`tema = retorno_garantia`, `service_order_id` da OS), nada isentado; o gerente aprova com `AlcadaService::decide`; o chefe repete → passa e o pedido vira `consumido`. Sem limite preenchido a isenção passa direto |
| T11 | `corrigir causa e responsável: justificativa obrigatória, histórico preservado, aviso ao gerente e cobrança recalculada até a execução` | Chefe (`auditar`) troca peça→execução com justificativa: `retorno_classificado` com `causa_anterior`, alerta `retorno_causa_alterada` ao gerente; consultor → 403; sem justificativa → erro; nada mudou → erro; trocar para `uso_cliente` antes da execução tira a garantia das linhas e do total; depois de `execucao_liberada_em` → erro de bloqueio |
| T12 | `retorno sem causa ou sem responsável não sai do Diagnóstico` | OS de retorno montada com `forceFill` sem causa: `StageGate::missing(Diagnóstico→Orçamento)` contém a causa; com causa de execução e sem responsável contém "Responsável pelo retorno"; OS comum não é afetada |
| T13 | `entra nos indicadores: CHE-10, CHE-13, CHE-14, TEC-10 e TEC-02` | 4 OS entregues + 1 retorno abertos pela ação com causa execução → CHE-10 e GER-05 = 25,0; retorno de causa peça não conta; CHE-14 = 100; retorno cancelado sai de CHE-10/CHE-14; TEC-10 (`['mechanic_id' => …]`) conta o retorno por execução em até 30 dias da 1ª operação concluída (a origem precisa de um serviço com `OperationService::start/finish` do técnico, como em `IndicatorsTest.php:182-211`); TEC-02 cai quando o retorno é por diagnóstico em até 30 dias do `diagnostico_fim` (a origem precisa do evento `diagnostico_fim` no mês e de `diagnostico_mechanic_id`); a página `admin.mentoria.indicador` de CHE-10 lista o retorno (`OS que formam o número`) |
| T14 | `garantia não gera comissão e não distorce produtividade, margem de peças nem ticket médio` | `CommissionReportService::build()` do mês: nenhuma linha da OS de garantia, comissão do mecânico e do consultor 0; `DreService::compute`: `receita_mo`/`receita_pecas` 0 e `custo_pecas` > 0; `IndicatorService` CHE-12 sem horas de garantia; PEC-12 sem a peça; ticket médio do painel ignora a OS sem cobrança |
| T15 | `a saída de OS sem cobrança não pede nota nem pagamento e não gera alerta crítico; retorno cobrado continua exigindo` | Garantia em Faturamento com checklist de saída: `StageGate::missing` vazio; `StageAdvanceService::advance` → evento `saida_veiculo` com `sem_nf=false`, sem `bloqueio_forcado`, sem alerta crítico, FIN-02 fora da base; retorno cobrado (fora do prazo) → falta "Nota fiscal emitida" e "Pagamento confirmado ou acordo assinado" |
| T16 | `negociação: linha em garantia não recebe desconto e a alçada de desconto ignora a garantia` | POST `admin.service_orders.negociacao.update` com `item_discounts` para a linha em garantia → `discount_amount` continua 0; desconto na linha cobrada calcula o % só sobre a parte cobrada |
| T17 | `piloto desligado: tudo como antes` | `abrir_retorno` indisponível; `recalculateTotals` de OS comum igual ao de sempre; página da OS abre sem o cartão; Marketing conta as OS como antes |
| T18 | `técnico, impressão e acompanhamento público: sem crédito, e técnico sem valores nem garantia` | Técnico: GET da OS de retorno mostra o chip/origem mas **não** contém "Garantia", "sem cobrança", "Isento", "Causa", "Cor de crédito", valores; `admin.tecnico.tasks` tem `retorno` e passa nas chaves proibidas de `TecnicoValoresTest.php:118`; POST `isentar_retorno` → 403. Impressão: gerente vê "Retorno da OS nº…", "Garantia" e "Coberto pela garantia"; técnico não; nenhuma das duas contém a cor de crédito (`vermelho`, `cor_credito`) — mesmo método de `EntradaELgpdTest.php:36-49`. Acompanhamento (URL assinada): contém "Retorno", não contém crédito, garantia, isenção nem valores |
| T19 | `Painel do Dono: retornos por causa na tela, no PDF e no JSON; Marketing não conta retorno` | `PainelDonoService::build('2026-11')['retornos']` com totais por causa, `em_garantia`, `por_responsavel` e `valor_nao_cobrado_fmt`; a página `admin.mentoria.painel` contém "Retornos e garantias do mês"; `admin.mentoria.painel.pdf` renderiza o bloco; `admin.mentoria.painel.dados?aba=indicadores` tem a chave `retornos` e `blocos` continua com 4 títulos; `MarketingController` não conta a OS de retorno nem na lista nem no CSV detalhado |
| T20 | `banco sem as colunas novas: a tela abre e a ação explica` | Forçar o memo (reflexão em `RetornoGarantia::$memo`) para `disponivel() = false`: GET da OS entregue responde 200 sem o formulário de abrir; POST `abrir_retorno` devolve a mensagem de atualização, sem 500 (não dá para dropar coluna no teste: DDL faz commit implícito no MariaDB) |
| T21 | `modo de teste ÷10 (depende da F03)` | Com o modo de teste da F03 ligado, o retorno aberto em quarta 10:00 vence às 10:12 (120 min ÷ 10). Escrever **depois** de a F03 definir o nome da chave/ajudante; sem `skip`: se a F03 ainda não existir, esta linha entra na rodada de integração |

**Teste antigo a atualizar** — `tests/Feature/Workshop/AllBarriersTest.php:605-630` (`abrir retorno cria a OS vinculada…`): o POST de `abrir_retorno` passa a enviar `['motivo' => …, 'causa' => 'peca', 'responsavel_id' => $colaborador->id]` (criar um `Colaborador` ativo) em vez de `['tipo' => 'retorno', …]`; manter as asserções de redirecionamento, `tipo_os`, `vehicle_id`, 1 evento `retorno_aberto` e instância `diagnostico_retorno`; o consultor continua levando 403 em `classificar_retorno` (`auditar`); o chefe passa a enviar `['causa' => 'execucao', 'justificativa' => 'Laudo mostrou falha na montagem']` e a asserção final fica `retorno_causa === 'execucao'`. Renomear para "…a causa é corrigida pelo chefe com justificativa".

**Rodar**: `php artisan test --filter=RetornoGarantia`, depois `--filter=AllBarriers`, `--filter=Indicators`, `--filter=MentoriaPanel`, `--filter=TecnicoValores`, `--filter=StageGate`, `--filter=Workshop` e `php vendor/bin/pint` nos arquivos tocados. Sempre com `DB_DATABASE=krayin_test`.

## Riscos e interações com outras frentes

**Arquivos compartilhados (maior risco de conflito de merge)**: `StageGate.php` (F06, F07, F09), `StageAdvanceService.php` (F09), `ServiceOrder.php` e `ServiceOrderItem.php` (várias), `ServiceOrderEvent.php` (várias), `WorkflowActions.php` (F13, F10/F11), `IndicatorCalculator.php` (F06, F10/F11, F13), `PainelDonoService.php`, `painel*.blade.php`, `view.blade.php` (F01, F05, F06, F07, F09), `print.blade.php`, `negociacao.blade.php`, `ServiceOrderItemService.php` (F08, F10/F11), `ServiceOrderStageController.php`, `RotinaService.php`, `CommissionReportService.php` (F14), `lang/*/app.php`, `AlcadaCatalog.php`, `TecnicoTasks.php`, `MarketingController.php`.

**Pontos de contato que as outras frentes precisam preservar**
- **F09 (nota e saída)**: o predicado `ServiceOrder::semNadaACobrar()` dispensa NF e pagamento em `faturamentoParaSaida()` e zera `sem_nf` em `StageAdvanceService`. Se a F09 reescrever a saída ("Registrar saída do caminhão"), mantém essa dispensa; sem ela a garantia só sai com avanço forçado, gera alerta crítico ao Proprietário e trava automática para Consultor/Financeiro. A tela de faturamento também deve mostrar "sem cobrança" quando o predicado for verdadeiro.
- **F06 (crédito)**: a OS de retorno **totalmente coberta** herda `cor_credito`/`cor_credito_em` da origem na abertura (satisfaz a exigência atual do Cadastro e o CON-03). Se a F06 trocar a exigência por outros campos (pedido de análise, cadastro do cliente), tratar `ServiceOrder::coberturaTotal()` como "análise de crédito dispensada" e tirar essas OS de CON-03/FIN-01. Retorno **cobrado** passa pela análise normal. Origem sem cor registrada (OS anterior ao piloto): nada é copiado e o gate pede a cor normalmente.
- **F07 (termo de entrada)**: o retorno continua exigindo termo, fotos, km e aceite — o veículo volta a entrar. Não dispensar.
- **F13 (chamado externo e diária)**: linhas "Diária de pátio" nunca entram na garantia (`ServiceOrderItem::FORA_DA_GARANTIA`, prefixo do nome — `RotinaService::lancarDiaria` já usa esse nome; manter ou chamar `addItem` com `'garantia' => false`). `setTipo()` passa a recusar mudar o tipo de uma OS de retorno. `ServiceOrder::TIPOS_OS` já traz os rótulos de chamado externo/preventiva.
- **F10/F11 (peças)**: peça de garantia consome estoque normalmente e passa pela relação, precificação e compra; só não gera alçada de preço mínimo (`price()` pula) e fica fora de PEC-12 e DRE-07 (`em_garantia`). Se reescreverem esses cálculos, manter o `reject(em_garantia)`.
- **F14 (remuneração)**: comissão de garantia é sempre zero e o relatório pula as linhas; qualquer novo "total variável por pessoa" deve ignorar `em_garantia`.
- **F03 (modo de teste)**: nenhuma ação; só o teste T21 depende.
- **F05 (menu e permissões)**: nenhuma chave nova; precisa de `service_orders.edit` no Consultor, `service_orders.auditar` no Chefe/Gerente/Proprietário e `ver_valores_financeiros` em quem isenta.
- **F00 (oficinas)**: a migration nova precisa rodar em cada banco de oficina.
- **F04 (perfis) / A5 (colaboradores)**: o seletor "Responsável" lista `Colaborador::ativos()`; sem colaboradores cadastrados o retorno por execução/peça/diagnóstico não abre (mensagem pede o cadastro).
- **F01 (correções)**: `SO/Resources/views/public/tracking.blade.php:21` tem um erro de CSS (`.card {0`); não é desta frente, mas está na mesma página que esta frente edita.
- **Observer**: `ServiceOrderObserver::created` (`:30-36`) continua como está (sem efeito prático hoje); não é preciso mexer, desde que `RetornoGarantia::abrir()` crie a OS sem `tipo_os` (evento `retorno_aberto` duplicado e barreira aberta duas vezes).

**Riscos técnicos**
- Mudar `line_total`/`discount_total` e a semântica de `sub_total` só para linhas `em_garantia`: sem essas linhas (piloto desligado, OS comum) tudo é idêntico ao de hoje. O teste T17 e a suíte `Workshop` protegem.
- `updateNegociacao` regrava descontos de todas as linhas: sem o `continue` da garantia, um desconto "assado" no banco sobreviveria à retirada da garantia (cobraria zero por engano). Coberto por T16.
- `AlcadaService::authorize()` cria o pedido e **lança** exceção: por isso a chamada fica fora de `DB::transaction` (senão o pedido some no rollback).
- A causa mexe no bônus do Chefe e do Técnico: por isso correção só com justificativa, histórico (evento nunca é alterado: `ServiceOrderEvent` lança exceção em update/delete) e aviso ao Gerente.
- Retorno aberto "por fora" (Novo Cadastro normal, sem vínculo) não entra no retrabalho — risco de "retrabalho escondido". Melhoria futura (não incluída): no Novo Cadastro, avisar quando o veículo saiu há menos de N dias de uma OS.
- TEC-10/TEC-02 continuam atribuindo o retorno ao técnico da OS de origem (`osBase()` escopa por `diagnostico_mechanic_id`/`mechanic_id`). O "responsável" informado serve a painel, auditoria e travas manuais (Bônus > Travas "Retorno por negligência"), não muda esses indicadores.
- O retorno entregue também entra no denominador de CHE-10 ("OS entregues") e de TEC-10 (fórmula literal do catálogo); não alterado.
- OS de garantia aparece em DRE-09 (margem negativa) porque a receita é zero e o custo existe; é o custo da garantia à vista (meta do DRE-09 vem em branco). Não alterado.
- A garantia conta da saída mais N dias. Retorno de retorno usa a saída da OS de retorno imediatamente anterior (cada entrega reinicia a garantia do que foi refeito).
- Concorrência: duplo clique é coberto por `lockForUpdate` na origem + `podeAbrir()` dentro da transação.

**Avisos para a documentação (RETOMAR-TRABALHO.md, a atualizar por quem integrar)**
- Nova seção "OS de retorno e garantia": fluxo, regra de cobertura (serviço/peças separados, uso do cliente não é garantia), isenção com alçada `retorno_garantia`, migration `2026_11_18_121200…`, ações `abrir_retorno`/`classificar_retorno`/`isentar_retorno`, predicados `semNadaACobrar()`/`coberturaTotal()`, coluna `quote_items.em_garantia` e `sub_total` sem garantia.
- Lembrar que o seletor Retorno/Garantia foi removido e que `RETORNO_CAUSAS` agora vive em `RetornoGarantia::CAUSAS`.

## Decisões em aberto

Só o que depende do patrão; cada uma com a opção recomendada (o desenvolvedor pode implementar a recomendada sem esperar).

1. **Que valor a alçada "Retorno e garantia" compara?** A matriz diz "Custo até R$ __". **Recomendado**: o **valor de tabela que deixa de ser cobrado** na isenção (é o número que o dono entende ao preencher o limite). Alternativa: custo interno (peças a custo + horas × custo da hora), mais trabalhosa e sujeita a peça sem custo informado.
2. **A garantia dentro do prazo também deve exigir aprovação quando passar de certo valor?** Hoje só a **isenção** (exceção) passa pela alçada; a garantia dentro do prazo é "sem cobrança por padrão", como o PDF pede. **Recomendado**: manter assim agora e reavaliar depois das duas primeiras semanas do piloto (ligar seria uma chamada em "Negociação → Em Execução").
3. **O responsável informado deve passar a decidir de quem é o retorno em TEC-10 e TEC-02?** Hoje esses indicadores seguem o técnico da OS de origem. **Recomendado**: manter agora (mudança simples e sem risco) e revisar com dados reais, porque trocar a atribuição muda o bônus do técnico.
4. **O "responsável" pode ficar em branco no "uso do cliente"?** O PDF diz "classificação obrigatória da causa e responsável". **Recomendado**: dispensar o responsável só nessa causa (a falha não é da oficina, não há quem atribuir). Se o patrão quiser o responsável sempre obrigatório, basta tirar a exceção em `RetornoGarantia::validarCausaEResponsavel` e a regra `required_unless:causa,uso_cliente` de `abrir_retorno`.

## Passo a passo para o usuário

**Depois de a equipe de desenvolvimento entregar o zip (nada disso é feito pelo desenvolvedor no servidor):**
1. Subir o zip e rodar `public/migrate-safe.php` **no banco principal e no banco de cada oficina** (a F00 explica como). A migration desta frente cria 5 colunas novas e não mexe em dado existente. Sem ela, o botão "Abrir OS de retorno" avisa que falta atualizar e o resto do sistema segue normal.
2. **Configurações > Ficha de Enquadramento**: conferir "Garantia do serviço (dias)" e "Garantia das peças (dias)" (padrão 90 e 90; 0 = sem garantia).
3. **Configurações > Indicadores e alertas > Barreiras**: conferir "Diagnóstico de retorno" (120 min, aviso ao Chefe, e ao Gerente 60 min depois).
4. **Mentoria > Matriz de alçadas** (opcional): preencher a linha "Retorno e garantia" — "Chefe decide sozinho até R$ X", "Gerente até R$ Y". Em branco não bloqueia.
5. **Configurações > Funções**: Consultor técnico com "Ordens de Serviço > Editar"; Chefe de oficina, Gerente e Proprietário com "Auditar diagnóstico (visto do chefe)"; Gerente e Proprietário também com "Ver valores financeiros". O Técnico fica sem essas.
6. **Colaboradores**: ter cada pessoa cadastrada e ativa (é a lista do campo "Responsável").
7. **Teste na Oficina Teste Mentoria (roteiro, linha 53)**, com o piloto ligado:
   1. Abrir uma OS que já foi entregue (Pós-Venda ou Concluída). Aparece o cartão "Retorno e garantia" com as datas da garantia.
   2. Preencher "O que o cliente relatou", escolher a "Causa do retorno" e o "Responsável pela falha" e clicar em "Abrir OS de retorno".
   3. O sistema abre a OS nova, com "Esta OS é um retorno da OS #N" (link) e o prazo "Diagnóstico até hh:mm". Na OS de origem, o cartão passa a listar o retorno.
   4. Lançar um serviço e uma peça na OS de retorno: dentro da garantia, o total fica R$ 0,00 e as linhas mostram "Garantia".
   5. Em Mentoria > Indicadores, abrir "Retrabalho" (CHE-10): a OS de retorno com causa "Falha de execução" aparece na lista e conta no percentual. No Painel do Dono, o bloco "Retornos e garantias do mês" mostra o retorno por causa.
   6. Testar um retorno "Uso do cliente" e um de OS entregue há mais de 90 dias: nos dois a cobrança é normal. Para dar cortesia, o Gerente usa "Isentar cobrança" com justificativa.
   7. Com o modo de teste ÷10 (F03) ligado, o prazo de 2 h vira 12 min.
