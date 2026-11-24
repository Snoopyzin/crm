# Especificação — F10-pecas-painel

Frente: Módulo de Peças, parte 1 (B4, primeira metade): relação de peças, precificação com cronômetro, preço mínimo e Painel de Peças.
Base: código real em `d:\volmaster crm\volmaster crm` (leitura feita em 18/11/2026). Todos os caminhos de `ServiceOrder` abaixo são relativos a `packages/Webkul/ServiceOrder/src` (abreviado `SO/`). Os caminhos de peças são relativos a `packages/Webkul/PartInventory/src` (abreviado `PI/`).

Resumo em uma frase: quase toda a lógica de precificação já existe (ação `precificar_peca`, preço mínimo, alçada, barreira, indicadores). O que falta é a TELA (Painel de Peças), a relação de peças completa (peça fora do cadastro, remover linha), o relógio POR ITEM e configurável (hoje 20/40 min estão fixos no código e a barreira é por OS), o modo de teste e os pontos de ligação para a F11.

---

## O que já existe

### Peças e estoque (pacotes PartInventory, Product, Warehouse)
- Catálogo `parts` (`codigo`, `nome`, `ncm`, `cest`, `part_category_id`, `valor_unitario`, `fornecedor`, `quantidade_minima`): `PI/Models/Part.php:12-21`, migration `PI/Database/Migrations/2026_08_04_111246_create_parts_table.php:14-28`. NÃO há coluna de custo na peça (o custo só existe em `quote_items.custo_unitario`, informado por Peças ao precificar).
- Saldo `part_inventories` (`in_stock`, `allocated`, `warehouse_id`): `PI/Models/PartInventory.php:15-21`. Razão `part_stock_movements` (`entrada|saida|ajuste`): `PI/Models/PartStockMovement.php:13-20`.
- `PI/Services/PartInventoryService.php`: `applyMovement()` :16-48, `allocate()` :54, `release()` :62, `consumeAllocation()` :71-86 (baixa só na conclusão da OS, pelo `ServiceOrderObserver::updating` em `SO/Observers/ServiceOrderObserver.php:123-137`).
- `PI/Http/Controllers/PartController.php`: CRUD do catálogo e `storeMovement()` :182-209 (entrada/ajuste direto, SEM alçada: é assunto da F11).
- Menu/ACL de Estoque, Serviços e Grupos: `PI/Config/menu.php`, `PI/Config/acl.php` (chaves `estoque`, `parts*`, `servicos_catalogo`, `grupos`, `part_categories*`).
- Pacote `Product` = produtos do CRM Krayin (tabela `products`, sem ligação com as peças da oficina). Pacote `Warehouse` = depósitos/locais (`warehouses`, `warehouse_locations`) usados por `part_inventories`. Nenhum dos dois tem nada de painel, compra ou precificação.
- NÃO existe em lugar nenhum: pedido de compra, NF de entrada, inventário rotativo, painel de Peças, rota/ACL/menu de Peças (a busca por `pedido_compra|nota_entrada|inventario` não acha nada).

### Relação de peças do técnico
- Tela "Minhas tarefas": `SO/Resources/views/tecnico/index.blade.php:186-196` (lista a relação e o formulário "+ Peças da relação" com `<select>` do catálogo + quantidade + "Este serviço não precisa de peças" + "Entregar relação de peças"); JS :246-247.
- `SO/Http/Controllers/TecnicoController.php`: `addPart()` :94-109 (só peça do cadastro: `part_id` obrigatório e `exists:parts,id`; o preço vem do cadastro, no servidor; o técnico nunca o vê), `deliverParts()` :111-116, `ownDiagnostic()` :155-162 (só o técnico designado mexe).
- `SO/Services/WorkflowService.php`:
  - `deliverParts()` :276-307 — exige pelo menos 1 peça ou o "sem peças"; grava `quotes.relacao_pecas_em = now()` (sobrescreve a cada chamada); define `quote_items.origem_preco` (`estoque|fornecedor`) para cada peça ainda não precificada; grava o evento `relacao_pecas_entregue` com `itens`, `sem_pecas` e `prazo_precificacao_min` = 20 (tudo em estoque) ou 40 — FIXO NO CÓDIGO :303-305.
  - `hasStock()` :312-325 — soma `in_stock - allocated >= 0`; peça sem `part_id` conta como fornecedor; peça com cadastro e SEM linha de estoque conta como "em estoque" (defeito: `SUM` vazio vira 0 e `0 >= 0` é verdadeiro).
- JSON do técnico sem valores: `SO/Services/TecnicoTasks.php:76-81` (só `nome`, `codigo`, `quantidade`) e `relacao_pecas_em` :66.
- Na tela da OS (consultor/chefe): `SO/Resources/views/partials/workflow.blade.php:271-284` (checkbox "sem peças" + "Entregar relação de peças"), rota `admin.service_orders.workflow.parts` (`SO/Routes/web.php:107`); lançar item com preço: `SO/Http/Controllers/ServiceOrderController.php:321-344` (`storeItem`) e `SO/Services/ServiceOrderItemService.php:26-79` (`addItem`: reserva estoque com `allocate`).
- Bloqueio: `SO/Services/StageGate.php:187` (Diagnóstico → Orçamento exige `relacao_pecas_em`).

### Precificação, preço mínimo e alçada
- Ação `precificar_peca`: definição `SO/Services/WorkflowActions.php:80-98` (campos `item_id`, `preco_unitario`, `custo_unitario`, `origem_preco`, `fornecedor`; `valores => true`, ou seja, só quem vê valores), disponível só nas etapas Diagnóstico e Orçamento (`isAvailable()` :253-276). Execução em `price()` :383-415: calcula o mínimo, chama a alçada, grava preço/custo/origem/fornecedor/`precificada_em`, recalcula os totais da OS e grava o evento `peca_precificada` SEM valores em R$ (o teste `AllBarriersTest.php:539-564` exige isso).
- Preço mínimo: `SO/Services/Financeiro/DreService.php:323-341` `precoMinimoPeca(float $custo): ?float` = `custo ÷ (1 − % impostos − % despesas variáveis − % margem)`. Percentuais de `parametros()` :39-54 (`pct_impostos`, `pct_desp_var_pecas`, `margem_desejada_pecas`, todos da Ficha). A margem vem da tabela por faixa de custo da Ficha (`margem_pecas_faixas.margem_minima`, `FichaController::margens` :151-175) e, sem faixa, de `margem_desejada_pecas`. Devolve `null` quando impostos, despesas e margem estão todos zerados. Coberto por `FinanceiroTest.php:359-374`.
- Alçada: assunto `preco_abaixo_minimo` já existe — `SO/Services/Alcada/AlcadaCatalog.php:49` (tipo `gerente`, automático). `AlcadaService::authorize()` :111-143 bloqueia, abre o pedido e só deixa repetir depois da aprovação, que é "consumida" uma vez (:302-319, por OS + assunto, não por item). Coberto por `FinanceiroTest.php:376-415`.
- Bloqueio: `StageGate::orcamentoParaNegociacao()` :219-233 (Orçamento → Negociação exige todas as peças precificadas).
- Ações vizinhas já prontas: `comprar_peca` / `buy()` :417-442 (evento `compra_peca`, alçada `compra_pecas` sem autorização do cliente), `entregar_peca` / `deliverPart()` :444-461 (evento `peca_entregue_tecnico`), `corrigir_orcamento` :99-107 (evento `orcamento_corrigido`, quem = `pecas|consultor`).

### Barreira
- `SO/Models/BarrierRule.php:120-136`: `precificacao_peca` ("Precificação de peça"), modo `dinamico`, prazo vem do evento (`prazo_campo = prazo_precificacao_min`), fallback 40, nível 1 = Peças, nível 2 aos +50% do prazo = Chefe, `condicao_inicio = tem_pecas`, `condicao_fim = todas_precificadas`. Abre em `relacao_pecas_entregue`, fecha em `peca_precificada`. É UMA instância POR OS (`referencia_id = 0`), prazo 20 só se TODAS as peças estão em estoque.
- Motor: `SO/Services/BarrierEngine.php` (`handleEvent` :38-72, `open` :114-161, `prazoMinutos` :166-177, `reference` :243-252 — já aceita `referencia_id` no evento —, `close` :293-313, `tick` :77-112). Condições: `SO/Services/BarrierConditions.php:16-32`.
- Teste atual: `tests/Feature/Workshop/AllBarriersTest.php:185-223`.
- A tela de configuração mostra "Dispara na hora, sem prazo." para regras dinâmicas (`SO/Resources/views/settings/workshop.blade.php:126-135`), então hoje NÃO há onde editar os 20/40 minutos.
- Modo de teste ÷10: não existe (nenhuma ocorrência no código). É da F03.

### Rotina e Painel do Dono
- `SO/Services/Rotina/RotinaService.php:170-198` `dePecas()`: 4 seções ("Pedidos a precificar", "Compras do dia", "Entregas ao técnico pendentes", "Inventário rotativo semanal"). O cronômetro da precificação usa 20/40 FIXOS (`:177`), mede em relógio corrido a partir de `quotes.relacao_pecas_em` (por OS, não por item), ignora horário comercial e modo de teste, e não tem formulário. "Compras do dia" e "Entregas" leem colunas de `quote_items` (`comprada_em`, `prazo_entrega_em`, `entregue_tecnico_em`). "Inventário" só avisa se não houve registro `inventario_rotativo` na semana. Teste: `RotinaTest.php:82-107`.
- Tela "Minha rotina" genérica: `SO/Resources/views/rotina/painel.blade.php`, rota `admin.rotina.painel`, ACL `rotina_painel` (`SO/Config/acl.php:181-185`); com o piloto ligado `EntradaPorCargo` leva cada cargo para a própria tela (`SO/Http/Middleware/EntradaPorCargo.php:31-35`); Peças cai na "Minha rotina".
- Painel do Dono: bloco de peças `SO/Services/Indicators/PainelDonoService.php:39` (`BLOCO_PECAS = PEC-09, PEC-11, PEC-12`) e `build()` :65-68 (`linhas` + `valor_estoque`); PEC-01 já aparece no card "03 Orçamento" (:31), PEC-05 em "05 Execução" (:33), PEC-06 em "07 Faturamento" (:35), DRE-07 no bloco DRE (:47).
- `SO/Resources/views/financeiro/orcamento.blade.php` (citada nas pistas) é o "Orçamento anual e Marco Zero" da DRE; não tem relação com precificação de peças.

### Indicadores (já calculam)
Catálogo `SO/Services/Indicators/IndicatorCatalog.php:83-84, 96-108, 170`; cálculo `SO/Services/Indicators/IndicatorCalculator.php` (registro :123-124, :136-148, :208). Mapa completo na seção "Requisitos". O cálculo de PEC-02..06, 12, 13, TEC-05, TEC-06 e DRE-07 já sai de eventos e colunas existentes; PEC-07..10 e a lista de PEC-11 dependem de dados da F11 (registros `nf_entrada`, `inventario_rotativo`, `garantia_fornecedor`).

---

## Requisitos do PDF → como serão atendidos

Legenda: JÁ EXISTE / PARCIAL / FALTA.

### 1. "Painel de Peças (perfil Peças)" — FALTA
Não há rota, ACL, menu nem tela. Existe só a "Minha rotina" genérica. Será criado o Painel de Peças (`admin.pecas.painel`) com cinco blocos nesta ordem, que é a do PDF:
1. Fila de itens a precificar (com cronômetro e formulário de preço) — PARCIAL hoje (lista sem formulário, relógio errado). **Entrega completa nesta frente.**
2. Compras do dia — PARCIAL (lista existente, sem pedido de compra). Esta frente move a consulta atual para `PainelPecasService::comprasDoDia()`; a F11 troca o corpo pela consulta dos pedidos de compra.
3. Peças a entregar ao técnico — PARCIAL (idem) → `entregasAoTecnico()`.
4. NF de entrada a lançar — FALTA → `nfEntradaALancar()` nasce vazio; a F11 alimenta.
5. Inventário rotativo da semana — PARCIAL (só lembrete) → `inventarioDaSemana()`; a F11 alimenta.
Cada bloco é um cartão com contador, lista (formato idêntico a `RotinaService::item()`) e link opcional para a tela da F11 (só aparece se a rota existir, via `Route::has`).

### 2. Cronômetro: 20 min se tem em estoque, 40 min se precisa cotar — PARCIAL
Existe a classificação `estoque|fornecedor` (`WorkflowService::deliverParts`) e uma barreira por OS. Falta: (a) prazos vindos da configuração (hoje fixos em `WorkflowService.php:303-305` e `RotinaService.php:177`), (b) relógio por ITEM e não por OS, (c) horário comercial e modo de teste ÷10 coerentes com a barreira, (d) cor verde/amarelo/vermelho no painel, (e) a barreira por item.
Solução:
- Configuração: `WorkshopSettings` `pecas_prazo_estoque_min` (padrão 20) e `pecas_prazo_cotacao_min` (padrão 40), editáveis no bloco da barreira "Precificação de peça" em Configurações > Indicadores e alertas.
- Início do relógio de cada item: nova coluna `quote_items.a_precificar_em` (carimbo de quando a peça entrou na fila: entrega da relação, ou inclusão posterior). Sem a coluna (banco ainda não migrado) vale `quotes.relacao_pecas_em`.
- Fim: `quote_items.precificada_em` (já existe).
- Cor (mesma regra de `RotinaService::cor()` :43-54): verde abaixo de 80% do prazo, amarelo de 80% a 100%, vermelho acima do prazo ou quando a barreira já está no nível 1+.
- Barreira: `precificacao_peca` passa a abrir UMA INSTÂNCIA POR ITEM (`referencia_id = id do item`): novo evento `peca_a_precificar` (um por peça) abre; `peca_precificada` ou `peca_removida` fecham. Nível 1 avisa Peças ao vencer; nível 2 avisa o Chefe aos +50% do prazo (já é a configuração atual). Isso faz o PEC-01 medir ITENS, como diz o catálogo ("Itens precificados em ≤ 20 min (estoque) ou ≤ 40 min (fornecedor) ÷ itens").
- Modo de teste ÷10 (F03): o evento leva o prazo NOMINAL; o motor da F03 divide ao abrir a barreira (ver contrato abaixo); o painel divide o limite exibido com o mesmo divisor.

### 3. Relação de peças enviada pelo técnico (sem valores) — PARCIAL
Existe: técnico escolhe peça do cadastro, informa quantidade, entrega a relação com carimbo; o JSON dele não tem valores; cada peça ganha `origem_preco`.
Falta:
- Peça FORA do cadastro (a Parte A informa "Peças e estoque vazios": com o catálogo vazio o técnico não consegue montar relação nenhuma). Novo: descrição + código/referência opcional + quantidade; a linha nasce com preço 0 e `part_id` nulo (a coluna já aceita nulo) e conta como "precisa cotar" (40 min).
- Tirar uma linha antes de entregar (hoje errou, ficou). Novo: remover enquanto a relação não foi entregue; registra `peca_removida`, libera a reserva de estoque.
- Cada linha vira um item com relógio próprio (coluna `a_precificar_em` + evento `peca_a_precificar` por linha).
- Entrega repetida não pode reiniciar o relógio (hoje `deliverParts` regrava `relacao_pecas_em` toda vez).
- Depois de entregue (piloto ligado) a relação trava para o técnico; destrava só enquanto o Chefe tiver devolvido o diagnóstico (assim o técnico corrige a lista e entrega de novo). Peça esquecida depois vai como "Item adicional" (Chefe/Consultor), que também entra na fila (ver `ServiceOrderItemService::addItem`).
- O técnico vê o andamento sem valores: cada linha mostra "Aguardando preço" ou "Com preço" (sem R$).

### 4. Preço mínimo = custo ÷ (1 − % impostos − % despesas variáveis − % margem desejada), percentuais da Ficha — JÁ EXISTE (parcial na tela)
A fórmula e a Ficha estão prontas e testadas (`DreService::precoMinimoPeca`). Ajustes desta frente:
- Mostrar o preço mínimo AO precificar (endpoint `admin.pecas.preco_minimo`, calculado no servidor) e avisar quando o preço digitado fica abaixo.
- Aviso permanente quando o mínimo está desligado (Ficha sem impostos, despesas e margem): hoje `precoMinimoPeca()` devolve `null` e qualquer preço passa em silêncio.
- Observação: o PDF fala em "margem desejada"; o código usa a margem mínima da faixa de custo quando a Ficha tem a tabela de faixas, e a margem desejada única quando não tem (decisão em aberto nº 1; recomendação: manter).

### 5. "Preço abaixo do mínimo vai para a fila de alçadas (assunto já existe)" — JÁ EXISTE para a precificação; FALTA para "vender"
Confirmado: o assunto `preco_abaixo_minimo` existe (`AlcadaCatalog.php:49`) e a precificação já bloqueia. O teste do PDF diz "vender abaixo do mínimo pede alçada" e hoje o desconto da Negociação pode levar uma peça precificada para baixo do mínimo sem alçada (`ServiceOrderStageController::checkAlcadas` :194-227 só confere `desconto` e `condicao_pagamento`). Será criado `checkPrecoMinimoDasPecas()` na Negociação.
Também: precificar de novo uma peça já precificada passa a exigir motivo (piloto ligado), e o evento `peca_precificada` ganha os campos `repreco`, `motivo` e `abaixo_minimo` (sem valores).

### 6. "Pedido de compra ligado à OS autorizada…" e "Estoque: entrada por NF, saída só por OS, ajuste com aprovação, inventário, parados > 180 dias" — FORA desta frente (F11)
Esta frente só deixa os pontos de ligação (contrato abaixo).

### 7. "Alimenta PEC-01 a PEC-13, TEC-05, TEC-06 e DRE-07"
| Indicador | Fonte hoje | Com a F10 | Depende da F11 |
|---|---|---|---|
| PEC-01 Precificação no prazo | `barrier('precificacao_peca')` (`IndicatorCalculator.php:136`) por OS | passa a medir ITENS (instância por item) | não |
| PEC-02 Peça certa na 1ª vez | eventos `peca_precificada` − registros `peca_errada` (:735-746) | ignora re-preços (`repreco`) | o registro `peca_errada` continua manual (Registros do dia a dia) |
| PEC-03 Preço e prazo mantidos | `orcamento_corrigido` depois da aprovação (:748-754) | sem mudança | não |
| PEC-04 Compra no mesmo dia | barreira `compra_peca` (:139) | sem mudança | F11 deve manter o evento `compra_peca` |
| PEC-05 Entrega no prazo | evento `peca_entregue_tecnico` com `prazo_entrega_em` (:756-772) | sem mudança | F11 deve manter o evento e o campo |
| PEC-06 Conferência antes de faturar | evento `pecas_conferidas` (:774-786) | sem mudança | não |
| PEC-07 NF de entrada em 24 h | registro `nf_entrada` (`recebida_em`, `lancada_em`) (:142) | só mostra o bloco | F11 grava o registro ao lançar a NF |
| PEC-08 Garantia/devolução em 5 dias | registro `garantia_fornecedor` (:143) | — | F11 (ou registro manual) |
| PEC-09 / PEC-10 Acuracidade e inventário semanal | registro `inventario_rotativo` (`contados`, `corretos`) (:814-829) | só mostra o bloco | F11 grava ao fechar a contagem |
| PEC-11 Parados > 180 dias | movimentos e `valor_unitario` (:834-854) | — | F11 (lista e valor; hoje o valor usa `valor_unitario`, não custo) |
| PEC-12 Margem bruta de peças | `custo_unitario` dos itens faturados (:856-868) | o custo passa a vir do formulário do painel | não |
| PEC-13 Auditoria do estoque | registros `auditoria_estoque` (:148) | — | não |
| TEC-05 Relação em 30 min | barreira `relacao_pecas` (:123) | `relacao_pecas_entregue` continua sendo gravado | não |
| TEC-06 Relação correta na 1ª vez | `orcamento_corrigido` com quem = `pecas` (:669-675) | botão "Relação com erro" no painel | não |
| DRE-07 Peças abaixo do mínimo sem alçada | itens + `AlcadaPedido` (:1231-1253) | a Negociação também passa por alçada | não |

### 8. Integração com Painel do Dono e Rotina do perfil Peças — PARCIAL
- Rotina: `RotinaService::dePecas()` passa a delegar para `PainelPecasService::secoes()` (mesmos títulos, mais a seção "NF de entrada a lançar"); a "Minha rotina" e o painel mostram os mesmos dados.
- Painel do Dono: `PainelDonoService::build()['pecas']` ganha `fila` (itens na fila, atrasados, pedidos de preço abaixo do mínimo na fila de alçadas) e a tela do computador mostra uma linha de resumo no bloco "Peças e estoque". O JSON do celular (`PainelDonoController.php:92-97`) NÃO muda (um teste fixa os títulos dos blocos).

### Testes do PDF
- "precificar fora do prazo deixa o item vermelho e dispara a barreira": coberto (barreira por item + cor no painel).
- "vender abaixo do mínimo pede alçada": coberto (precificação já existia; Negociação nova).
- "baixar peça sem OS é impossível": é da F11.

---

## Mudanças por arquivo

### Criar

#### `SO/Services/Pecas/PrazosPecas.php`
Prazos configuráveis da precificação. Única classe que conhece as chaves.
```php
namespace Webkul\ServiceOrder\Services\Pecas;

class PrazosPecas
{
    public const ESTOQUE = 'estoque';
    public const FORNECEDOR = 'fornecedor';

    /** Prazo da Ficha/Indicadores e alertas, em minutos (mínimo 1). */
    public static function nominalMin(string $origem): int;     // 'pecas_prazo_estoque_min' (20) | 'pecas_prazo_cotacao_min' (40)

    /** Divisor do modo de teste (F03). 1 = desligado; 10 = prazos ÷ 10. Único ponto que lê a chave da F03. */
    public static function divisorDeTeste(): float;             // max(1, (float) WorkshopSettings::get('barreiras_divisor_prazos', 1))

    /** Prazo exibido no painel: nominal ÷ divisor. */
    public static function efetivoMin(string $origem): float;
}
```
Nota: a chave `barreiras_divisor_prazos` é a PROPOSTA desta frente; se a F03 escolher outro nome, só `divisorDeTeste()` muda.

#### `SO/Services/Pecas/OrigemDaPeca.php`
Tira o `hasStock()` do `WorkflowService` (que passa a chamá-lo) e corrige o defeito da peça sem linha de estoque.
```php
class OrigemDaPeca
{
    public function definir(ServiceOrderItem $item): string;    // 'estoque' | 'fornecedor' (não grava)
    public function temSaldo(ServiceOrderItem $item): bool;
}
```
`temSaldo`: sem `part_id` → false; senão uma consulta `PartInventory::where('part_id', ...)->selectRaw('COALESCE(SUM(in_stock),0) as fisico, COALESCE(SUM(in_stock - allocated),0) as livre')->first()` e `fisico > 0 && livre >= 0` (a reserva desta OS já está em `allocated`; `fisico > 0` fecha o caso peça cadastrada sem saldo). Ponto de ligação da F11 se ela mudar a regra de saldo.

#### `SO/Services/Pecas/FilaDePrecificacao.php`
Coração da frente.
```php
class FilaDePrecificacao
{
    /** Banco já migrado? Memoizado, no padrão de PrevisaoOs::disponivel(). */
    public static function colunaDisponivel(): bool;            // Schema::hasColumn('quote_items', 'a_precificar_em')
    public static function usarColuna(?bool $valor): void;      // só para teste (simula banco não migrado; null = volta ao normal)

    /** Põe a peça na fila: define origem_preco, grava a_precificar_em (se houver a coluna) e registra 'peca_a_precificar'. false se já estava na fila ou já foi precificada. */
    public function enviar(ServiceOrder $os, ServiceOrderItem $item): bool;

    /** Itens Peça sem precificada_em, de OS abertas com relacao_pecas_em. */
    public function itens(): Collection;

    /** Estado do relógio de um item. */
    public function cronometro(ServiceOrderItem $item, ?Carbon $agora = null, ?BarrierInstance $instancia = null): array;

    /** Fila agrupada por OS e ordenada por urgência (vermelhos primeiro, depois o que vence antes). */
    public function grupos(?Carbon $agora = null): array;

    /** Contadores: a_precificar, atrasados (vermelho), a_vencer (amarelo), aguardando_alcada. */
    public function resumo(?Carbon $agora = null): array;
}
```
Regras:
- `enviar()`: ignora item que não é `Peça` ou já tem `precificada_em`. Origem = `OrigemDaPeca::definir()`. Se `colunaDisponivel()` e o item já tem `a_precificar_em`, retorna false (não repete o evento). Grava `origem_preco` (+ `a_precificar_em = now()`), depois `ServiceOrderEvent::record($os, 'peca_a_precificar', ['referencia_id' => $item->id, 'item_id' => $item->id, 'peca' => $item->name, 'quantidade' => (int) $item->quantity, 'origem' => $origem, 'prazo_precificacao_min' => PrazosPecas::nominalMin($origem)])`. O evento leva o prazo NOMINAL (o motor divide no modo de teste).
- `cronometro()` devolve `['origem', 'inicio' (Carbon), 'vence' (Carbon), 'limite_min' (efetivo), 'limite_nominal_min', 'decorrido_min', 'nivel_alerta' (0,1,2 da instância), 'cor', 'atrasado']`. Início = `a_precificar_em` ou, se nulo, `serviceOrder->relacao_pecas_em` (fallback do banco não migrado e dos itens antigos). `origem` = `origem_preco` ou `OrigemDaPeca::definir()` se nulo. Vencimento = `vence_em` da instância da barreira (`barrier_code = precificacao_peca`, `referencia_id = id do item`, aberta) quando existir; senão cálculo local: `BusinessHours::addMinutes($inicio, max(1, (int) round($limite)))` se a regra conta horário comercial (`BarrierRule::conta_horario_comercial`, padrão verdadeiro quando a regra não existe), ou `$inicio->copy()->addMinutes(...)` em relógio corrido. Decorrido = `BusinessHours::diffMinutes($inicio, $agora)` (ou minutos corridos). Cor = vermelho se `nivel_atual > 0` ou `$agora > $vence`; amarelo se `decorrido >= 0.8 × limite`; senão verde (o mesmo critério de `RotinaService::cor()`).
- `grupos()`: uma consulta de itens com `serviceOrder.vehicle`, uma de instâncias abertas da barreira (`BarrierInstance::abertas()->where('barrier_code', 'precificacao_peca')->whereIn('service_order_id', $ids)`, indexadas por `service_order_id.'-'.referencia_id`) e uma de `AlcadaPedido` (`tema = preco_abaixo_minimo`, status pendente/encaminhado/aprovado, a última por OS). Cada grupo: `os`, `placa`, `veiculo`, `servico` (`subject`), `etapa`, `url` (só se o usuário tem `service_orders.view`), `pode_precificar` (`WorkflowActions::isAvailable($os, definitions()['precificar_peca'])`), `pode_erro` (idem para `corrigir_orcamento`), `alcada` (`null` ou `['status', 'texto']`), `itens[]` (`id`, `nome`, `codigo`, `quantidade`, `origem`, `origem_rotulo`, `inicio_ts`, `vence_ts`, `limite_min`, `cor`, `atrasado`, `nivel_alerta`, `ultimo_custo`). `ultimo_custo` (custo do último item com `part_id` igual e `custo_unitario` preenchido, buscado numa consulta agrupada por `part_id` para a fila inteira) SÓ entra quando `ValoresFinanceiros::can()`.
- Nenhuma consulta SQL referencia `a_precificar_em` (só leitura do atributo, que vira null se a coluna não existir): o código roda em banco não migrado.

#### `SO/Services/Pecas/PainelPecasService.php`
Compõe o painel. É a interface com a F11.
```php
class PainelPecasService
{
    public function __construct(protected FilaDePrecificacao $fila) {}

    /** As 5 seções no formato de RotinaService::secao() (usadas por Minha rotina). */
    public function secoes(): array;

    /** Dados do painel (página e JSON): resumo, fila, blocos, assinatura. */
    public function dados(): array;

    public function resumo(): array;

    // Pontos de ligação da F11. Cada um devolve Collection de arrays no formato de RotinaService::item().
    public function comprasDoDia(): Collection;
    public function entregasAoTecnico(): Collection;
    public function nfEntradaALancar(): Collection;           // F10: vazia
    public function inventarioDaSemana(): Collection;

    /** Rotas opcionais das telas da F11 (só entram se Route::has). */
    public function links(): array;
}
```
- `comprasDoDia()`, `entregasAoTecnico()` e `inventarioDaSemana()` recebem, SEM mudança de comportamento, o corpo hoje em `RotinaService::dePecas()` :179-190 (itens com `origem_preco = fornecedor`, `comprada_em` nulo e OS aprovada; itens de estoque ou comprados, sem `entregue_tecnico_em`, em OS em execução; lembrete se não houve registro `inventario_rotativo` na semana).
- `secoes()` devolve, nesta ordem: "Pedidos a precificar" (do `FilaDePrecificacao::grupos()`, `texto` = nome da peça, `decorrido`/`limite` formatados, cor do relógio), "Compras do dia", "Entregas ao técnico pendentes", "NF de entrada a lançar" (vazio: "Nenhuma NF de entrada a lançar."), "Inventário rotativo semanal".
- `dados()`: `['gerado_em' => 'dd/mm/aaaa hh:mm', 'agora' => epoch, 'pode_precificar' => ValoresFinanceiros::can(), 'preco_minimo_ativo' => DreService::precoMinimoPeca(100.0) !== null, 'resumo' => [...], 'fila' => grupos, 'blocos' => [['chave' => 'compras'|'entregas'|'nf_entrada'|'inventario', 'titulo', 'vazio', 'itens', 'link']...], 'assinatura' => md5 dos ids + cores]`.
- `links()`: `['compras' => 'admin.pecas.compras.index', 'nf_entrada' => 'admin.pecas.nf_entrada.index', 'inventario' => 'admin.pecas.inventario.index', 'estoque' => 'admin.parts.index']`, filtrado por `Route::has()`.

#### `SO/Http/Controllers/PecasPainelController.php`
```php
class PecasPainelController extends Controller
{
    public function __construct(protected PainelPecasService $painel) {}

    public function index(): View;                      // GET  admin.pecas.painel
    public function dados(): JsonResponse;              // GET  admin.pecas.painel.dados (polling de 30 s)
    public function precoMinimo(): JsonResponse;        // GET  admin.pecas.preco_minimo?custo=123.45
    public function precificar(int $id, int $itemId, WorkflowActions $acoes): RedirectResponse|JsonResponse;      // POST admin.service_orders.pecas.precificar
    public function relacaoComErro(int $id, int $itemId, WorkflowActions $acoes): RedirectResponse|JsonResponse;  // POST admin.service_orders.pecas.erro
}
```
- `index()`: `view('service_order::pecas.painel', ['dados' => $this->painel->dados(), 'indicadores' => app(PainelDonoService::class)->linhas(['PEC-01', 'PEC-04', 'PEC-05', 'PEC-06'], now()->format('Y-m'), false), 'links' => $this->painel->links(), 'podeVerOs' => bouncer()->hasPermission('service_orders.view')])`. Os indicadores (que são caros) só na página, nunca no JSON do polling.
- `dados()`: `response()->json($this->painel->dados())`.
- `precoMinimo()`: `ValoresFinanceiros::authorize('preço mínimo de peça')`; valida `custo` (`required|numeric|min:0`); devolve `['disponivel' => bool, 'minimo' => ?float, 'texto' => 'R$ 166,67'|null]` usando `DreService::precoMinimoPeca()`.
- `precificar()`: `ValoresFinanceiros::authorize('precificar peça')`; `$os = ServiceOrder::findOrFail($id)`; valida com `WorkflowActions::definitions()['precificar_peca']['regras']` (que passa a incluir `motivo`) depois de `request()->merge(['item_id' => $itemId])`; chama `$acoes->perform($os, 'precificar_peca', $dados)`; `ValidationException` (inclusive a da alçada) vira `session()->flash('error', primeira mensagem)`. Resposta: redirect para `route('admin.pecas.painel').'#os-'.$id` com flash, ou JSON `{ok, mensagem}` (422 no erro) quando `expectsJson()`.
- `relacaoComErro()`: valida `motivo` (`required|string|max:255`); `perform($os, 'corrigir_orcamento', ['quem' => 'pecas', 'motivo' => $motivo])` (alimenta TEC-06 e CHE-03). Mesma forma de resposta.

#### `SO/Resources/views/pecas/painel.blade.php`
Página `<x-admin::layouts>` (título "Painel de Peças"), Tailwind como `rotina/painel.blade.php`, script em `@push('scripts')` (como `view.blade.php:351`). Estrutura:
1. Cabeçalho + faixa de resumo (cartões: A precificar, Atrasados, Aguardando alçada, Compras do dia, Entregas pendentes, NF de entrada, Inventário da semana).
2. Aviso amarelo se `preco_minimo_ativo` for falso (texto na seção de textos), com link para a Ficha se o usuário tiver `settings.ficha`.
3. "Meus indicadores do mês": PEC-01, PEC-04, PEC-05, PEC-06 (realizado, meta e cor; sem R$).
4. "Itens a precificar": um cartão por OS (`id="os-{id}"`) com placa, veículo, serviço, etapa, link da OS (se `podeVerOs`), chip da alçada e o botão "Relação com erro". Dentro, uma linha por peça: quantidade x nome (código), chip da origem ("Em estoque · 20 min" / "Cotar com fornecedor · 40 min"), cronômetro colorido e o formulário "Custo (R$)", "Preço de venda (R$)" (ambos `data-money`, que o `NormalizeMoneyInput` converte no POST), "Origem", "Fornecedor" (opcional), "Motivo" só quando for re-precificar, botão "Precificar". Sem a permissão de valores, o formulário não aparece e a linha mostra "Seu perfil não informa valores". Quando `pode_precificar` é falso (OS já passou de Orçamento), em vez do formulário a linha mostra "Esta OS já está em {etapa}: o preço não pode mais ser definido por aqui. Fale com o consultor." O botão "Relação com erro" só aparece se `pode_erro`.
5. Quatro cartões: Compras do dia, Peças a entregar ao técnico, NF de entrada a lançar, Inventário rotativo da semana (lista + link da F11 quando existir).
JS (puro, sem Vue): (a) a cada 1 s recalcula o cronômetro de cada linha a partir de `data-inicio`/`data-vence` (epoch, corrigidos pela diferença entre o relógio do servidor e o do navegador) mostrando "faltam mm:ss" ou "atrasado há mm:ss" e só ESCURECE a cor entre dois recarregamentos (verde → amarelo → vermelho, nunca volta); (b) ao digitar o custo (espera de 400 ms), chama `admin.pecas.preco_minimo` e mostra "Preço mínimo: R$ X" (ou "Preço mínimo indisponível…"); ao digitar o preço, compara e mostra o aviso de alçada e troca o rótulo do botão para "Enviar para a alçada"; (c) a cada 30 s consulta `admin.pecas.painel.dados` e, se a `assinatura` mudou, mostra a faixa "Há itens novos ou mudanças. Atualizar" (NÃO recarrega sozinha, para não perder o que foi digitado).

### Alterar

#### `SO/Database/Migrations/2026_11_18_104500_add_a_precificar_em_to_quote_items_table.php` — ver "Banco".

#### `SO/Models/ServiceOrderItem.php`
Adicionar em `$casts` (:51-57): `'a_precificar_em' => 'datetime'`. Não entra em `$fillable` (todo o resto de precificação usa `forceFill`).

#### `SO/Models/ServiceOrderEvent.php`
Em `TIPOS` (:26-96) acrescentar `'peca_a_precificar' => 'Peça enviada para precificação'` e `'peca_removida' => 'Peça removida da relação'`. Nenhum dos dois leva valor em R$.

#### `SO/Models/BarrierRule.php`
Substituir o item `precificacao_peca` de `DEFAULTS` (:120-136) por:
```php
[
    'codigo' => 'precificacao_peca',
    'nome' => 'Precificação de peça',
    'etapa' => 'Orçamento',
    // Uma instância por peça: abre quando a peça entra na fila de Peças e fecha quando é precificada (ou tirada da relação).
    'evento_inicio' => 'peca_a_precificar',
    'evento_fim' => 'peca_precificada,peca_removida',
    'modo' => self::MODO_DINAMICO,
    // 20 min se a peça está em estoque, 40 se precisa cotar: o prazo vem da configuração (Peças) e entra no evento.
    'prazo_campo' => 'prazo_precificacao_min',
    'prazo_minutos' => 40,
    'nivel1_destino' => 'pecas',
    'nivel2_apos_percentual' => 50,
    'nivel2_destino' => 'chefe',
    'critico' => false,
],
```
(Sem `condicao_inicio`/`condicao_fim`.) Não muda a contagem de 20 barreiras. Esta regra ainda não foi enviada a nenhum servidor (o pacote da mentoria não subiu), então basta mudar o padrão; `BarrierConditions` mantém `tem_pecas`/`todas_precificadas` (sem uso) para linhas antigas.

#### `SO/Services/BarrierEngine.php`
Em `close()` (:293-313) tolerar a linha antiga da regra (instância geral da OS, `referencia_id = 0`), que continua existindo no `krayin_crm` de demonstração e em qualquer banco que já semeou o padrão antigo:
```php
$referencia = $this->reference($rule, $event);

$instances = BarrierInstance::abertas()
    ->where('service_order_id', $event->service_order_id)
    ->where('barrier_code', $rule->codigo)
    // Evento de uma peça (referência = item) também encerra a instância geral antiga da OS (referência 0);
    // ela só chega aqui se a condição de fim da regra passar (todas as peças precificadas).
    ->whereIn('referencia_id', array_values(array_unique([$referencia, 0])))
    ->get();
```
Arquivo compartilhado com a F03 (o divisor do modo de teste mexe em `prazoMinutos()`/`levelAt()`).

#### `SO/Services/WorkflowService.php`
`deliverParts()` (:276-307) passa a:
1. Manter a validação (≥ 1 peça ou "sem peças").
2. Piloto ligado: se `relacao_pecas_em` já existe e o diagnóstico NÃO está `devolvido`, recusar com `ValidationException` ("A relação de peças desta OS já foi entregue."). Piloto desligado: comportamento atual (aceita de novo).
3. Gravar `relacao_pecas_em` SÓ se ainda for nulo (hoje é sobrescrito a cada chamada).
4. Para cada peça sem `precificada_em`: `app(FilaDePrecificacao::class)->enviar($os, $item)` (substitui o laço atual :292-300). O `hasStock()` :312-325 sai daqui e vira `OrigemDaPeca`.
5. Gravar `relacao_pecas_entregue` como hoje, com `prazo_precificacao_min` = `PrazosPecas::nominalMin(...)` do pior caso (40 se alguma peça é `fornecedor`, senão 20) — mantido para linhas antigas da regra e para o TEC-05. Ordem: primeiro `relacao_pecas_entregue`, depois os `peca_a_precificar` (um por peça enfileirada).

#### `SO/Services/ServiceOrderItemService.php`
- `addItem()` :26-79, ramo Peça (:47-51): `part_id` e `sku` toleram peça fora do cadastro: `$item->part_id = $data['part_id'] ?? null;` e `sku` = código do cadastro ou `$data['codigo'] ?? null`.
- Ao final de `addItem()` (depois de reservar e recalcular), se o item é Peça, `$serviceOrder->relacao_pecas_em` existe e a etapa da OS é Diagnóstico ou Orçamento: `app(FilaDePrecificacao::class)->enviar($serviceOrder, $item)`. Assim a peça acrescentada depois (item adicional do Chefe/Consultor) também ganha relógio e barreira.
- `removeItem()` :84-99: antes de apagar uma linha de Peça, gravar `ServiceOrderEvent::record($serviceOrder, 'peca_removida', ['referencia_id' => $item->id, 'item_id' => $item->id, 'peca' => $item->name, 'quantidade' => (int) $item->quantity, 'precificada' => (bool) $item->precificada_em])`. Isso fecha a barreira do item (evento de fim) e mantém o rastro da linha apagada.

#### `SO/Services/WorkflowActions.php`
- `definitions()['precificar_peca']` (:80-98): acrescentar o campo opcional `'motivo' => ['label' => 'Motivo (se a peça já estava precificada)', 'tipo' => 'text', 'opcional' => true]`, a regra `'motivo' => 'nullable|string|max:255'` e `'ajuda' => 'O preço mínimo sai da Ficha: custo ÷ (1 − impostos − despesas variáveis − margem). Abaixo dele, o pedido vai para a fila de alçadas do gerente.'`.
- `price()` (:383-415):
  - `$repreco = (bool) $item->precificada_em;` com o piloto ligado (`StageGate::active()`) e `$repreco` sem `motivo`, `ValidationException` "Esta peça já foi precificada: informe o motivo da alteração do preço." (com o piloto desligado nada muda).
  - `$abaixo = $minimo !== null && (float) $dados['preco_unitario'] < $minimo;` o bloco da alçada fica igual (chama `authorize` só se `$abaixo`).
  - O evento passa a ser `['item_id' => $item->id, 'referencia_id' => $item->id, 'peca' => $item->name, 'origem' => ..., 'abaixo_minimo' => $abaixo, 'repreco' => $repreco, 'motivo' => $repreco ? $dados['motivo'] : null]`. Continua SEM preço nem custo.
- `definitions()['corrigir_orcamento']` (:99-107): `etapas` passa a incluir `STAGE_DIAGNOSTICO` (o painel mostra itens de OS que ainda estão em Diagnóstico).

#### `SO/Services/WorkshopSettings.php`
Em `DEFAULTS` (depois de `meios_aprovacao`, :130): `'pecas_prazo_estoque_min' => 20, 'pecas_prazo_cotacao_min' => 40,` com comentário "Prazo para Peças precificar cada peça (min)".

#### `SO/Http/Controllers/WorkshopSettingsController.php` e `SO/Resources/views/settings/workshop.blade.php`
- `updateRule()` (:116-149): quando `$rule->codigo === 'precificacao_peca'`, validar `pecas_prazo_estoque_min` e `pecas_prazo_cotacao_min` (`nullable|integer|min:1|max:1440`), gravar com `WorkshopSettings::set()` e copiar o da cotação para `prazo_minutos` da regra (fallback coerente).
- Na view, bloco da regra (:126-135): para `$rule->codigo === 'precificacao_peca'`, em vez de "Dispara na hora, sem prazo.", mostrar os dois campos (rótulos na seção de textos) e a nota "Cada peça tem o seu relógio. No modo de teste os prazos são divididos." Para as outras regras `dinamico` (ex.: `diagnostico_tempo`) trocar a frase enganosa por "O prazo vem do tempo alvo da queixa." (correção de texto).
Arquivos compartilhados com a F03 (mesmo formulário/tela).

#### `SO/Services/Rotina/RotinaService.php`
- `dePecas()` (:170-198): corpo vira `return app(PainelPecasService::class)->secoes();`.
- `item()` :497-507, `secao()` :513-516 e `duracao()` :518-521 passam de `protected function` para `public static function` (as chamadas `$this->item(...)` continuam válidas); o painel de peças reutiliza o formato.

#### `SO/Services/Indicators/PainelDonoService.php` e `SO/Resources/views/mentoria/painel.blade.php`
- `build()` (:65-68): `'pecas' => ['linhas' => ..., 'valor_estoque' => ..., 'fila' => app(FilaDePrecificacao::class)->resumo()]`.
- View (:64-68): abaixo do título do bloco, uma linha "Fila de precificação agora: N item(ns), V atrasado(s). Pedidos de preço abaixo do mínimo na fila de alçadas: A." com link para `admin.pecas.painel` se o usuário tiver `pecas_painel`. Sem valores em R$. O PDF e o JSON do celular não mudam.

#### `SO/Services/Indicators/IndicatorCalculator.php`
- `pecaCerta()` :735-746: contar só eventos `peca_precificada` sem `repreco` (re-preço não é item novo): trocar o `count()` por `->get()->filter(fn ($e) => empty($e->dados_json['repreco']))->count()`. As demais fórmulas ficam como estão.

#### `SO/Http/Controllers/ServiceOrderStageController.php`
Novo método e uma chamada no fim de `checkAlcadas()` (:194-227), para minimizar conflito com a F06:
```php
protected function checkPrecoMinimoDasPecas(ServiceOrder $serviceOrder): void
{
    $dre = app(DreService::class);

    foreach ($serviceOrder->items as $item) {
        if ($item->tipo !== ServiceOrderItem::TIPO_PECA || (float) $item->custo_unitario <= 0) {
            continue;
        }

        $novo = round(min(max(0, (float) (request("item_discounts.{$item->id}") ?? 0)), $item->gross_total), 2);

        // Só confere quando o desconto da linha aumenta (salvar de novo o mesmo desconto já aprovado não pede nada).
        if ($novo <= (float) $item->discount_amount + 0.004) {
            continue;
        }

        $minimo = $dre->precoMinimoPeca((float) $item->custo_unitario);
        $liquido = ($item->gross_total - $novo) / max(1, (float) $item->quantity);

        if ($minimo !== null && $liquido + 0.004 < $minimo) {
            app(AlcadaService::class)->authorize('preco_abaixo_minimo', $serviceOrder, null, 'Peça '.$item->name.' vendida abaixo do preço mínimo com o desconto da negociação');
        }
    }
}
```
`authorize()` já é silencioso com o piloto desligado, para quem tem alçada de gerente e quando há aprovação a consumir.

#### `SO/Http/Controllers/TecnicoController.php`
- `addPart()` :94-109: com o piloto ligado, recusar (422) se a relação não está aberta (ver regra abaixo). A conferência fica DENTRO do closure passado a `run()` (:164-173), para sair como 422 JSON `{ok: false, mensagem}` e não como exceção.
- Novo `addFreePart(int $id, ServiceOrderItemService $items): JsonResponse` — `ownDiagnostic($id)`; valida `descricao` (`required|string|max:120`), `codigo` (`nullable|string|max:60`), `quantidade` (`required|integer|min:1|max:999`); `addItem($os, ['tipo' => 'Peça', 'descricao' => ..., 'codigo' => ..., 'preco_unitario' => 0, 'quantidade' => ..., 'part_id' => null])`. Resposta só `{ok: true}`.
- Novo `removePart(int $id, int $itemId, ServiceOrderItemService $items): JsonResponse` — `ownDiagnostic($id)`; a linha precisa ser Peça desta OS e sem `precificada_em`; chama `removeItem()` (que grava `peca_removida`).
- Regra "relação aberta" (só com o piloto ligado), num método `assertRelacaoAberta(ServiceOrder $os)`: aberta quando `relacao_pecas_em` é nulo OU `diagnostico_visto_status === 'devolvido'`. Senão `ValidationException`: "A relação de peças já foi entregue. Para incluir ou tirar uma peça, peça ao chefe de oficina para devolver o diagnóstico."

#### `SO/Services/TecnicoTasks.php`
Em `diagnosticos()` (:76-81) cada peça passa a levar `'id' => $item->id` e `'situacao' => $item->precificada_em ? 'com_preco' : 'aguardando_preco'`. Nenhum campo com nome de valor (o teste `TecnicoValoresTest.php:118-120` proíbe chaves que começam com `preco`, `price`, `total`, `desconto`, `credito`, `comissao`...).

#### `SO/Resources/views/tecnico/index.blade.php`
Na relação (:186-196): botão "remover" por linha enquanto a relação estiver aberta; sub-formulário "Peça que não está no cadastro" (descrição, código/referência, quantidade, botão "Incluir peça"); chip "Aguardando preço" / "Com preço" por linha depois da entrega. `URLS.diag(id, 'peca-livre')` e `URLS.diag(id, 'peca/' + itemId + '/remover')` no JS (:57-61, :246-247).

#### `SO/Http/Middleware/EntradaPorCargo.php`
No `match` (:31-35), antes do `default`: `in_array('pecas', $perfis, true) && ! array_intersect(['proprietario', 'gerente', 'chefe', 'consultor', 'financeiro', 'tecnico'], $perfis) => ['pecas_painel', 'admin.pecas.painel']`. Quem acumula Peças com outro cargo continua na "Minha rotina" (que já mostra as seções de Peças). Arquivo compartilhado com F04/F05.

#### `SO/Routes/web.php`
Dentro do grupo existente (middleware `web, admin_locale, user, DonoForaDoFluxo, RegistraAcessoSensivel`):
```php
// Painel de Peças: fila de precificação com cronômetro, compras, entregas, NF de entrada e inventário.
Route::controller(PecasPainelController::class)->group(function () {
    Route::get('pecas', 'index')->name('admin.pecas.painel');
    Route::get('pecas/painel.json', 'dados')->name('admin.pecas.painel.dados');
    Route::get('pecas/preco-minimo', 'precoMinimo')->name('admin.pecas.preco_minimo');
    // O prefixo admin.service_orders. faz o DonoForaDoFluxo registrar a ação do dono (DON-05), como na tela da OS.
    Route::post('service-orders/{id}/pecas/{itemId}/precificar', 'precificar')->name('admin.service_orders.pecas.precificar');
    Route::post('service-orders/{id}/pecas/{itemId}/relacao-com-erro', 'relacaoComErro')->name('admin.service_orders.pecas.erro');
});
```
E no grupo `tecnico` (:255-265):
```php
Route::post('diagnostico/{id}/peca-livre', 'addFreePart')->name('admin.tecnico.diagnostico.part_free');
Route::post('diagnostico/{id}/peca/{itemId}/remover', 'removePart')->name('admin.tecnico.diagnostico.part_remove');
```

#### `SO/Config/acl.php` e `SO/Config/menu.php`
Ver "ACL, menu, rotas e configurações". IMPORTANTE: uma rota que não está em nenhuma chave de ACL fica LIVRE para qualquer usuário logado (`Webkul/Admin/src/Http/Middleware/Bouncer.php:79-86`), então as 7 rotas novas precisam estar listadas.

#### `SO/Resources/lang/pt_BR/app.php` e `SO/Resources/lang/en/app.php`
Chaves `menu.pecas_painel` e `acl.pecas_painel` (as duas línguas). O corpo da página usa português direto na Blade, como as demais telas da mentoria.

---

## Banco (migrations)

Uma migration, aditiva e idempotente: `SO/Database/Migrations/2026_11_18_104500_add_a_precificar_em_to_quote_items_table.php` (a F10 usa a faixa 10:45; as outras frentes devem escolher outros HHMMSS).
```php
public function up(): void
{
    if (! Schema::hasColumn('quote_items', 'a_precificar_em')) {
        Schema::table('quote_items', function (Blueprint $table) {
            // Quando a peça entrou na fila de Peças (relação entregue ou item acrescentado depois): início do cronômetro de precificação.
            $table->dateTime('a_precificar_em')->nullable();
        });
    }
}

public function down(): void
{
    if (Schema::hasColumn('quote_items', 'a_precificar_em')) {
        Schema::table('quote_items', function (Blueprint $table) {
            $table->dropColumn('a_precificar_em');
        });
    }
}
```
- Sem backfill: itens antigos ficam com `a_precificar_em` nulo e o cronômetro usa `quotes.relacao_pecas_em`.
- Tolerância a coluna ausente: toda escrita da coluna passa por `FilaDePrecificacao::colunaDisponivel()`; nenhuma consulta SQL a cita. Sem a migration o painel, a barreira e a relação funcionam; só o relógio de peças acrescentadas depois da entrega usa o carimbo da OS.
- Nenhuma tabela nova. Nenhuma linha de `barrier_rules`, `alcada_regras` nem de eventos é alterada ou apagada.
- Aplicar nos testes: `$env:DB_DATABASE='krayin_test'; php artisan migrate --path=packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_104500_add_a_precificar_em_to_quote_items_table.php`. NÃO rodar no `krayin_crm` (demonstração) sem ordem.

---

## ACL, menu, rotas e configurações

### ACL (`SO/Config/acl.php`)
Nova chave de topo (sem ponto, como as demais telas):
```php
[
    // Painel de Peças: fila de precificação com cronômetro, compras do dia, entregas, NF de entrada e inventário.
    'key' => 'pecas_painel',
    'name' => 'service_order::app.acl.pecas_painel',
    'route' => [
        'admin.pecas.painel',
        'admin.pecas.painel.dados',
        'admin.pecas.preco_minimo',
        'admin.service_orders.pecas.precificar',
        'admin.service_orders.pecas.erro',
    ],
    'sort' => 20,
],
```
Na chave `tecnico` (:92-106) acrescentar `admin.tecnico.diagnostico.part_free` e `admin.tecnico.diagnostico.part_remove`. Nenhuma dessas rotas pode aparecer em outra chave (o mapa rota→chave usa a última que cita a rota).
Quem pode o quê:
- Ver o Painel de Peças: perfis com `pecas_painel` (Peças, Gerente, Proprietário; Mentor só leitura se a F05 quiser). O Técnico NUNCA recebe (esperado 401 do controle de ACL nas rotas, como nos testes atuais de Comissões).
- Informar custo/preço: além de `pecas_painel`, `ver_valores_financeiros` (`ValoresFinanceiros::authorize` devolve 403 e registra no log). O JSON do painel e do preço mínimo não trazem R$ para quem não vê valores.
- Abrir a OS a partir do painel: só com `service_orders.view` (o painel traz placa, veículo, serviço e peça, então Peças não precisa abrir a OS).
- O que a F05 deve dar ao perfil Peças (B8): `pecas_painel`, `ver_valores_financeiros`, `rotina_painel`, `estoque`, `grupos`, `bonus_meu` (e `parts.*` conforme o trabalho do estoque).

### Menu (`SO/Config/menu.php`)
```php
[
    'key' => 'pecas_painel',
    'name' => 'service_order::app.menu.pecas_painel',
    'route' => 'admin.pecas.painel',
    'sort' => 10,
    'icon-class' => 'icon-product',
],
```
A ordem final dos itens é da F05.

### Rotas novas (7)
| Nome | Método e URL | Controlador | Chave de ACL |
|---|---|---|---|
| `admin.pecas.painel` | GET `/admin/pecas` | `PecasPainelController@index` | `pecas_painel` |
| `admin.pecas.painel.dados` | GET `/admin/pecas/painel.json` | `@dados` | `pecas_painel` |
| `admin.pecas.preco_minimo` | GET `/admin/pecas/preco-minimo` | `@precoMinimo` | `pecas_painel` |
| `admin.service_orders.pecas.precificar` | POST `/admin/service-orders/{id}/pecas/{itemId}/precificar` | `@precificar` | `pecas_painel` |
| `admin.service_orders.pecas.erro` | POST `/admin/service-orders/{id}/pecas/{itemId}/relacao-com-erro` | `@relacaoComErro` | `pecas_painel` |
| `admin.tecnico.diagnostico.part_free` | POST `/admin/tecnico/diagnostico/{id}/peca-livre` | `TecnicoController@addFreePart` | `tecnico` |
| `admin.tecnico.diagnostico.part_remove` | POST `/admin/tecnico/diagnostico/{id}/peca/{itemId}/remover` | `TecnicoController@removePart` | `tecnico` |

### Configurações (`WorkshopSettings`)
- `pecas_prazo_estoque_min` (padrão 20) e `pecas_prazo_cotacao_min` (padrão 40): editáveis na barreira "Precificação de peça".
- Lê (definida pela F03): `barreiras_divisor_prazos` (padrão 1; 10 no modo de teste).
- Reaproveita: `pct_impostos`, `pct_desp_var_pecas`, `margem_desejada_pecas` e a tabela `margem_pecas_faixas` (Ficha); `horario_comercial`; `piloto_ativo`.

### Eventos, barreiras, alçadas
- Eventos novos: `peca_a_precificar`, `peca_removida`. `peca_precificada` ganha `referencia_id`, `abaixo_minimo`, `repreco`, `motivo` (sem valores).
- Barreira: nenhuma nova; `precificacao_peca` muda de OS para item (ver `BarrierRule`).
- Alçada: nenhum assunto novo; `preco_abaixo_minimo` passa a valer também na Negociação.

### Comportamento com o piloto desligado
O painel abre para quem tem a permissão e precifica normalmente; não há barreira, alçada nem motivo obrigatório; a relação do técnico não trava e pode ser entregue de novo (como hoje); a tela de configuração e a Ficha funcionam igual. Os novos eventos e o carimbo `a_precificar_em` são gravados mesmo assim (o motor de barreiras já ignora eventos com o piloto desligado: `BarrierEngine.php:40`).

### Valores e Técnico
Nenhuma resposta nova ao Técnico leva R$: `addFreePart`/`removePart` devolvem `{ok}`; as peças do JSON dele só ganham `id` e `situacao`. O evento `peca_precificada` e o novo `peca_a_precificar` não levam valores; a linha do tempo da OS já esconde `peca_precificada` de quem não vê valores (`partials/workflow.blade.php:20`).

---

## Textos da interface (pt-BR)

Menu e ACL:
- Menu: "Painel de Peças".
- ACL: "Painel de Peças (fila de precificação, compras e entregas)".
- En: "Parts panel" / "Parts panel (pricing queue, purchases and deliveries)".

Painel de Peças:
- Título: "Painel de Peças". Subtítulo: "O que o perfil Peças precisa fazer agora, com o cronômetro de cada prazo."
- Cartões do resumo: "A precificar", "Atrasados", "Aguardando alçada", "Compras do dia", "Entregas pendentes", "NF de entrada a lançar", "Inventário da semana".
- Blocos: "Itens a precificar" (vazio: "Nenhuma peça esperando preço."), "Compras do dia" (vazio: "Nenhuma compra pendente."), "Peças a entregar ao técnico" (vazio: "Nenhuma entrega pendente."), "NF de entrada a lançar" (vazio: "Nenhuma NF de entrada a lançar."), "Inventário rotativo da semana" (vazio: "Inventário da semana registrado."; pendente: "ainda não feito nesta semana"). Na Minha rotina os títulos antigos continuam ("Pedidos a precificar", "Entregas ao técnico pendentes").
- Cartão da OS: "OS #{id} · {placa} · {veículo}", "Serviço: {serviço}", "Etapa: {etapa}".
- Linha da peça: "{qtd}x {nome} ({código})", origem "Em estoque · 20 min" ou "Cotar com fornecedor · 40 min" (os números vêm da configuração e do modo de teste), cronômetro "faltam 12:30" / "atrasado há 03:10".
- Formulário: "Custo (R$)", "Preço de venda (R$)", "Origem" (Estoque, Fornecedor), "Fornecedor (opcional)", "Motivo da alteração" (só ao precificar de novo), botão "Precificar".
- Preço mínimo: "Preço mínimo: R$ {valor}"; abaixo dele: "Abaixo do preço mínimo: o pedido vai para a fila de alçadas do gerente."; botão trocado para "Enviar para a alçada"; sem Ficha: "Preço mínimo indisponível: preencha impostos, despesas variáveis e margem de peças na Ficha de Enquadramento."
- Aviso permanente (mínimo desligado): "O preço mínimo está desligado: preencha 'Impostos sobre a venda (%)', 'Despesas variáveis de peças (%)' e 'Margem desejada de peças (%)' na Ficha de Enquadramento (ou a tabela de margem por faixa)."
- Alçada na OS: "Aguardando o gerente decidir o preço" (pendente), "Com o dono" (encaminhado), "Aprovado pelo gerente: precifique de novo" (aprovado).
- Sem permissão de valores: "Seu perfil não informa valores. Peça ao gerente a permissão 'Ver valores financeiros'."
- OS fora das etapas de preço: "Esta OS já está em {etapa}: o preço não pode mais ser definido por aqui. Fale com o consultor."
- Botão "Relação com erro"; prompt/campo: "O que está errado ou faltando na relação?"; retorno: "Correção da relação registrada."
- Faixa de atualização: "Há itens novos ou mudanças. Atualizar".
- Retornos existentes mantidos: "Peça precificada." e a mensagem da alçada ("Esta ação passa da sua alçada (Preço de peça abaixo do mínimo). O pedido foi enviado para aprovação do gerente; repita a ação depois da aprovação.").
- Re-precificar: "Esta peça já foi precificada: informe o motivo da alteração do preço."
- Meus indicadores: "Meus indicadores do mês".
- Painel do Dono: "Fila de precificação agora: {n} item(ns), {v} atrasado(s). Pedidos de preço abaixo do mínimo na fila de alçadas: {a}."

Técnico (Minhas tarefas):
- "Peça que não está no cadastro", "Descrição da peça", "Código ou referência (opcional)", "Quantidade", botão "Incluir peça", ação "remover", chips "Aguardando preço" e "Com preço".
- Erros: "A relação de peças já foi entregue. Para incluir ou tirar uma peça, peça ao chefe de oficina para devolver o diagnóstico."; "Esta peça já tem preço definido por Peças e não pode ser tirada da relação."; "A relação de peças desta OS já foi entregue."
- Sucesso: "Peça removida da relação."

Configurações (barreira "Precificação de peça"):
- "Prazo para precificar peça que está em estoque (min)", "Prazo para precificar peça que precisa de cotação (min)", nota "Cada peça tem o seu relógio e conta em horário comercial. No modo de teste os prazos são divididos (ex.: 20 min viram 2 min)."
- Regras dinâmicas sem os campos: "O prazo vem do tempo alvo da queixa." (em vez de "Dispara na hora, sem prazo.").

Linha do tempo da OS: "Peça enviada para precificação", "Peça removida da relação".

Datas sempre `dd/mm/aaaa hh:mm`.

---

## Testes Pest a escrever

Padrão: `DatabaseTransactions` + `BuildsWorkshop`, em `tests/Feature/Workshop/`; funções auxiliares globais com prefixo único (`pp...`, `rp...`); `forgetSeeded()` já é feito no `setUpWorkshop`. Peça de estoque nos testes: `Warehouse::create(['name' => 'Depósito', 'contact_name' => 'x', 'contact_emails' => [], 'contact_numbers' => [], 'contact_address' => []])`, `Part::create(['codigo' => ..., 'nome' => ..., 'valor_unitario' => 50])`, `PartInventory::create(['part_id' => ..., 'warehouse_id' => ..., 'in_stock' => 3, 'allocated' => 0])`.

### `RelacaoDePecasTest.php` (linha 19 do roteiro; "Na OS, o técnico envia a relação de peças")
1. Técnico inclui peça do cadastro e peça fora do cadastro (`peca-livre`), as respostas são só `{ok: true}`, o JSON de `tasks` mostra as duas com `id` e `situacao = aguardando_preco` e nenhuma chave de valor.
2. Entregar a relação: grava `relacao_pecas_em`, `origem_preco` por item (estoque/fornecedor), `a_precificar_em`, UM evento `peca_a_precificar` por linha (com `referencia_id` = id do item e `prazo_precificacao_min` = 20/40 da configuração) e o `relacao_pecas_entregue` (prazo 40 quando alguma peça é fornecedor).
3. Piloto ligado: entregar de novo e incluir/remover depois da entrega dão 422 com a mensagem; com o diagnóstico `devolvido` o técnico inclui, remove e entrega de novo SEM reiniciar o relógio dos itens que já estavam na fila (`a_precificar_em` e `relacao_pecas_em` iguais aos primeiros).
4. Remover linha antes da entrega: some a linha, libera `allocated`, grava `peca_removida`; linha já precificada não sai.
5. Peça acrescentada pelo Consultor depois da entrega (Orçamento) entra na fila com relógio próprio; em Negociação ou depois, não.
6. Piloto desligado: entrega repetida e inclusão depois da entrega continuam aceitas (comportamento atual).
7. Banco não migrado (`FilaDePrecificacao::usarColuna(false)`): entregar, enfileirar e montar o painel funcionam, usando `relacao_pecas_em`.
8. `OrigemDaPeca`: peça cadastrada sem saldo físico conta como fornecedor; com saldo livre, estoque; sem `part_id`, fornecedor.
9. Técnico sem valores: nenhuma resposta nova contém `preco`, `price`, `total`, `custo`, `R$`.

### `PainelPecasTest.php` (linhas 19, 22 e 23 do roteiro; testes do PDF)
1. Prazos configuráveis: padrão 20/40; `WorkshopSettings::set('pecas_prazo_estoque_min', 15)` muda o evento e o limite do painel; o formulário da barreira (`admin.workshop.settings.rule`) grava os dois valores e copia o da cotação para `prazo_minutos`.
2. Cronômetro e cor: peça de estoque verde até 15 min, amarela de 16 a 20, vermelha depois; cotada com 40; peça entregue às 17:50 vence às 08:10 do dia seguinte (horário comercial) e o decorrido não anda de noite; com `barreiras_divisor_prazos = 10` (quando a F03 entregar a chave) os limites viram 2 e 4 min.
3. Linha 22 do roteiro ("fora do prazo deixa o item vermelho e dispara a barreira"): OS com um item de estoque e um cotado; o primeiro é precificado aos 15 min e fecha a barreira dele no prazo; o segundo passa dos 40 min: o painel o mostra vermelho, o `tick` dispara o nível 1 para Peças e, aos +20 min, o nível 2 para o Chefe; precificar fecha a barreira e resolve os alertas; `PEC-01` do mês = 50% (1 de 2 itens).
4. Preço mínimo: com impostos 10, despesas 5, margem 25, o endpoint `admin.pecas.preco_minimo?custo=100` devolve 166,67; a faixa de custo da Ficha tem prioridade; Ficha vazia devolve `disponivel = false` e o painel mostra o aviso; quem não vê valores recebe 403.
5. Linha 23 ("peça abaixo do preço mínimo → pedido na fila de alçadas"): precificar pelo painel abaixo do mínimo com Peças: redireciona com a mensagem da alçada, o item continua na fila, nada é gravado, existe `AlcadaPedido` `preco_abaixo_minimo`; o gerente aprova; repetir passa e `DRE-07` do mês = 0; preço igual ao mínimo passa direto; gerente que precifica abaixo do mínimo passa sem pedido e conta em `DRE-07`.
6. "Vender abaixo do mínimo pede alçada": desconto da Negociação que leva a peça (com custo) abaixo do mínimo cria o pedido e não grava o desconto; desconto que mantém o preço acima passa; peça sem custo é ignorada; piloto desligado passa.
7. Re-precificar exige motivo com o piloto ligado; o evento sai com `repreco = true`; `PEC-02` não conta o re-preço; sem piloto, não exige.
8. ACL: Peças com `pecas_painel` e `ver_valores_financeiros` abre painel e JSON; Técnico recebe 401 (ou 403) em `/admin/pecas`, no JSON, no preço mínimo e no `precificar`; quem tem o painel mas não vê valores recebe 403 no `precificar` e o JSON não traz `ultimo_custo`; consultor sem a chave não abre.
9. `DonoForaDoFluxo`: o proprietário que precifica pelo painel gera `acao_dono_fora_fluxo` (rota `admin.service_orders.pecas.precificar`).
10. Rotina: `RotinaService::dePecas()` devolve as 5 seções, na ordem; o teste `RotinaTest.php:82-107` continua verde sem mudança; "Minha rotina" da Peças mostra a seção nova.
11. `EntradaPorCargo`: com o piloto ligado, perfil só Peças com a permissão cai em `admin.pecas.painel`; Peças acumulado com outro cargo segue na Minha rotina.
12. "Relação com erro": grava `orcamento_corrigido` com quem = `pecas` e derruba o `TEC-06` do técnico da OS.
13. `PEC-12`: item faturado com o custo informado no painel entra na margem; `TEC-05` continua fechando com `relacao_pecas_entregue`.
14. Painel do Dono: `PainelDonoService::build()['pecas']['fila']` traz os contadores e a tela mostra a linha; o JSON do celular não muda (o teste `MentoriaPanelTest.php:72` continua verde).
15. Evento `peca_precificada` não contém preço nem custo (checar por chave, não por número: ver riscos).

### Ajustes em testes existentes
- `AllBarriersTest.php:185-223` ("precificação: 20 min com tudo em estoque ou 40 min com fornecedor…"): reescrever para o modelo por item (uma instância por peça com `referencia_id`; item de estoque vence 09:20 e nível 2 às 09:30; item cotado vence 09:40 e nível 2 às 10:00; cada um fecha sozinho; o alerta do primeiro não depende do segundo). Criar um auxiliar `dueOfItem($os, $itemId)`.
- Novo teste de motor: linha antiga da regra (`evento_inicio = relacao_pecas_entregue`, instância com `referencia_id = 0`) continua fechando quando a última peça é precificada (cobre a mudança de `BarrierEngine::close()`).
- `RotinaTest.php:82-107`, `FinanceiroTest.php:359-415` e `AllBarriersTest.php:539-564` devem passar sem alteração.

### Execução
`php artisan test --filter=RelacaoDePecas`, `--filter=PainelPecas`, `--filter=Workshop`, e `php vendor/bin/pint` nos arquivos tocados.

---

## Riscos e interações com outras frentes

### Contrato F10 ↔ F11 (nomes combinados nesta especificação)
| Ponto | Dono | Nome | O que a F11 faz |
|---|---|---|---|
| Bloco "Compras do dia" | F10 cria, F11 troca o corpo | `PainelPecasService::comprasDoDia(): Collection` | devolver os pedidos de compra abertos/atrasados no formato de `RotinaService::item()` (`os`, `placa`, `texto`, `decorrido`, `limite`, `cor`) |
| Bloco "Peças a entregar ao técnico" | idem | `entregasAoTecnico()` | pedidos recebidos e ainda sem carimbo de entrega ao técnico |
| Bloco "NF de entrada a lançar" | F10 deixa vazio, F11 preenche | `nfEntradaALancar()` | NFs recebidas e não lançadas; ao lançar, gravar `MentoriaRegistro::record('nf_entrada', ['dados_json' => ['recebida_em' => ..., 'lancada_em' => ...]])` (PEC-07 já lê isso) |
| Bloco "Inventário rotativo da semana" | idem | `inventarioDaSemana()` | itens a contar na semana; ao fechar a contagem gravar `MentoriaRegistro::record('inventario_rotativo', ['dados_json' => ['contados' => N, 'corretos' => M]])` (PEC-09/10 leem) |
| Links dos cartões | F10 consulta, F11 nomeia | `Route::has('admin.pecas.compras.index')`, `admin.pecas.nf_entrada.index`, `admin.pecas.inventario.index` | usar exatamente esses nomes (ou avisar a F10 para mudar `PainelPecasService::links()`) |
| Rotas e ACL da F11 | F11 | prefixo de nome `admin.pecas.<assunto>.*`, URL `/admin/pecas/<assunto>`; chaves de ACL próprias (`pecas_compras`, `pecas_nf_entrada`, `pecas_inventario`) | NÃO reutilizar `admin.pecas.painel*`, `admin.pecas.preco_minimo` nem a chave `pecas_painel` |
| Tabelas da F11 | F11 | prefixo `pecas_` (ex.: `pecas_pedidos_compra`, `pecas_notas_entrada`, `pecas_inventarios`) | a F10 não cria tabela nova, só a coluna `quote_items.a_precificar_em` |
| Saldo de estoque | F11 | `OrigemDaPeca::temSaldo()` | se mudar a regra de saldo (reservas, ajustes), alterar só esse método |
| Compra e entrega | F11 | eventos `compra_peca` e `peca_entregue_tecnico` (`item_id`, `prazo_entrega_em`) | manter nome e campos: PEC-04 e PEC-05 e a barreira `compra_peca` dependem deles |
| Vínculo com o cadastro | F11 | `quote_items.part_id` | a peça digitada pelo técnico (sem `part_id`) é ligada ao cadastro quando a NF de entrada ou o pedido de compra criar a peça |
| Custo da peça | F11 | — | `parts` não tem custo; se a F11 criar `custo_medio`/`ultimo_custo`, `FilaDePrecificacao` passa a sugeri-lo no lugar do "último custo praticado" |

### F03 (piloto e modo de teste)
- O motor deve aplicar o divisor ao prazo DINÂMICO lido do evento (`prazo_precificacao_min`, `tempo_alvo_min`) em `BarrierEngine::prazoMinutos()` e, por consequência, aos níveis em % (`levelAt()` parte do prazo já dividido). O evento da F10 leva o prazo nominal, então se a F03 dividir só `prazo_minutos`, a precificação ficaria fora do modo de teste.
- A chave `barreiras_divisor_prazos` e o arredondamento (`max(1, round())`) são propostas da F10 (`PrazosPecas::divisorDeTeste()`); alinhar nomes na integração.
- `restoreRules()` hoje só cria as que faltam (`BarrierRule::ensureDefaults()`); se a F03 passar a reaplicar os padrões, a regra nova de precificação entra junto.
- Arquivos em comum: `BarrierEngine.php`, `WorkshopSettingsController.php` (`updateRule`/`updateGeneral`), `settings/workshop.blade.php`.

### F04 e F05 (perfis, menu e permissões)
- A chave `pecas_painel` precisa entrar no conjunto do perfil Peças (e Gerente/Proprietário/Mentor) e NÃO no do Técnico. Hoje rota sem permissão devolve 401 pelo controle de ACL; se a F05 padronizar 403, ajustar os testes.
- `EntradaPorCargo.php`, `Config/menu.php`, `Config/acl.php` e os arquivos de idioma são compartilhados; a ordem final do menu é da F05.

### F06 (crédito) e F08 (valor-hora)
- `ServiceOrderStageController::checkAlcadas()` é compartilhado com a F06 (condição "a prazo" para cliente vermelho): a F10 só ACRESCENTA um método e uma chamada.
- `ServiceOrderItemService::addItem()` e `WorkflowActions::definitions()` são compartilhados com a F08 (ramo Serviço, valor da hora): a F10 mexe só no ramo Peça e na definição `precificar_peca`/`corrigir_orcamento`.

### F12 e F13
- `BarrierRule::DEFAULTS` e `ServiceOrderEvent::TIPOS` recebem acréscimos de várias frentes: conflito apenas de posição; manter o teste "traz as 20 barreiras" coerente com a soma final (a F10 NÃO muda a contagem).
- `RotinaService` (F13 mexe em `diariasDePatio`): a F10 só troca o corpo de `dePecas()` e torna `item/secao/duracao` estáticos.

### F00
- A coluna nova precisa chegar ao banco de cada oficina (a F00 define como). Sem ela o sistema funciona (fallback), mas o relógio das peças acrescentadas depois da entrega usa o carimbo da OS.

### Riscos técnicos
1. Volume de alertas: barreira por item pode gerar vários alertas iguais por OS (o texto não cita a peça) e, no nível 2, vários SMS se o Chefe não abrir em 10 min (`AlertDispatcher::escalateLevelTwoToSms`). Mitigações já existentes: o push é agrupado em janela de 5 min e o SMS tem limite mensal. Melhoria opcional futura: citar a peça na mensagem e agrupar o nível 2 por OS.
2. Aprovação de alçada não é por item: `AlcadaService::consumeApproval()` casa por OS + assunto (:302-319). Com duas peças abaixo do mínimo na mesma OS, o pedido aprovado para uma pode ser consumido pela outra. Não alterei `AlcadaService` (arquivo compartilhado); o texto do pedido cita a peça.
3. Mudança de semântica: `relacao_pecas_em` deixa de ser sobrescrito em entregas repetidas; a tela mostra a primeira entrega.
4. Teste existente frágil: `AllBarriersTest.php:563` confere que o JSON do evento não contém "500" nem "320"; como o `AUTO_INCREMENT` do banco de teste cresce, um `item_id` que contenha esses dígitos reprova por acaso (já pode acontecer hoje; a F10 acrescenta `referencia_id`). Ao reescrever, conferir por chave (`preco`, `custo`) e não por número.
5. Linhas antigas da barreira (demonstração): continuam por OS e fecham pela tolerância em `close()`; o botão "Restaurar" não atualiza linha existente.
6. A rota `admin.service_orders.pecas.*` fica sob o prefixo que o `DonoForaDoFluxo` observa (intencional, DON-05) e NÃO está nos prefixos do `RegistraAcessoSensivel` (o painel é operacional); se o patrão quiser registro de acesso (LGPD) das telas de preço, acrescentar `admin.pecas.` em `RegistraAcessoSensivel::PREFIXOS`.
7. Fuso: tudo usa `now()`; a produção depende de `APP_TIMEZONE=America/Sao_Paulo` no `.env` (o padrão do `config/app.php` é outro).
8. Sem histórico de valores por precificação: re-precificar sobrescreve preço e custo (o rastro fica no evento, que não leva valores, com motivo obrigatório). Se o patrão quiser auditoria de valores, criar tabela só-inserção em outra rodada.
9. `NormalizeMoneyInput` só trata POST/PUT/PATCH de formulário; o endpoint GET `preco-minimo` recebe o número já limpo pelo JS (`vmMoney.parse`).
10. Nenhum arquivo do `krayin_crm` é tocado; testes só em `krayin_test`.
11. Ordem no diagnóstico devolvido: `finishDiagnostico()` zera `diagnostico_visto_status` (`WorkflowService.php:193`), o que trava a relação de novo. O técnico precisa corrigir e entregar a relação ANTES de finalizar o diagnóstico outra vez (a tela já apresenta a relação acima do botão "FINALIZAR DIAGNÓSTICO").
12. Preço zero: `precificar_peca` aceita `preco_unitario = 0` (`min:0`) e, sem preço mínimo configurado, nada barra. Não foi alterado para não mudar o comportamento atual; recomenda-se `min:0.01` quando o piloto for liberado às oficinas.

### Casos de borda tratados
- OS concluída ou cancelada: fora da fila e do resumo; o `tick` fecha as barreiras de OS cancelada (`BarrierEngine.php:100-104`).
- Peça tirada da relação com a barreira aberta: o evento `peca_removida` é evento de fim; fecha a instância (no prazo se nenhum nível tinha disparado) e resolve os alertas.
- Mesma peça em duas linhas: cada linha é um item, com relógio e barreira próprios.
- Piloto ligado no meio do dia: itens que já estavam na fila (sem evento `peca_a_precificar` depois da ligação) não abrem barreira; o painel calcula o relógio localmente e mostra a cor normalmente.
- Entrega fora do expediente: o prazo só corre em horário comercial (`BusinessHours`), tanto na barreira quanto no painel; sem horário configurado, relógio corrido. O sistema não tem calendário de feriados.
- Ficha sem impostos, despesas e margem: `precoMinimoPeca()` devolve `null`, o painel avisa e nenhum preço vai para a alçada; com só a margem preenchida o mínimo é `custo ÷ (1 − margem)`.
- Preço exatamente igual ao mínimo passa (comparação estrita `<`, 2 casas decimais); gerente ou proprietário que precifica abaixo do mínimo passa sem pedido (a alçada dele cobre) e conta em DRE-07.
- Dois usuários precificando o mesmo item: o segundo cai no caso de re-precificação (motivo obrigatório com o piloto ligado).
- Modo de teste: o evento leva o prazo nominal; o motor (F03) e o painel dividem pelo mesmo divisor; o limite exibido nunca fica abaixo de 1 min.
- Banco não migrado: toda escrita de `a_precificar_em` passa por `colunaDisponivel()`; nenhuma consulta cita a coluna.
- Técnico: nunca recebe preço, custo, mínimo, alçada nem o JSON do painel; só `id` e `situacao` da peça; a linha do tempo da OS já esconde `peca_precificada` de quem não vê valores.
- Nada se apaga: linha tirada da relação deixa o evento `peca_removida` (nome, quantidade, se já estava precificada); re-preço deixa o evento com `repreco` e o motivo; pedidos de alçada continuam só-inserção.

### Fora do escopo desta frente (e por quê)
Pedido de compra, NF de entrada, inventário, ajuste com alçada, saída só por OS, lista de parados > 180 dias, vínculo da peça digitada ao cadastro e custo na peça são da F11. Aviso por notificação a Peças quando chega relação nova não foi pedido no PDF (decisão nº 4).

### Avisos para a documentação (RETOMAR-TRABALHO)
Nova tela "Painel de Peças" e nova coluna `quote_items.a_precificar_em`; barreira "Precificação de peça" agora é por peça; prazos 20/40 configuráveis; relação do técnico aceita peça fora do cadastro; a Negociação passa a exigir alçada quando o desconto leva a peça abaixo do mínimo; o cron de 1 minuto continua obrigatório.

---

## Decisões em aberto

1. Preço mínimo com a tabela de margem por faixa. O PDF diz "% margem desejada" da Ficha. O código usa a margem MÍNIMA da faixa de custo quando a Ficha tem a tabela de faixas, e a margem desejada única só quando não há faixa. Recomendação: manter como está (já funciona e está testado); a Ficha deixa claro qual vale.
2. Peça fora do cadastro na relação do técnico (texto livre). Sem isso o técnico não monta relação enquanto o estoque estiver vazio. Recomendação: SIM, a peça digitada conta como "precisa cotar" (40 min) e Peças a liga ao cadastro depois (F11).
3. Reabrir a relação. Recomendação: depois de entregue a relação só reabre se o Chefe devolver o diagnóstico; peça esquecida depois entra como "Item adicional" feito pelo Chefe ou Consultor e ganha relógio próprio.
4. Avisar o perfil Peças por notificação quando chega uma relação nova. Recomendação: NÃO nesta fase (o painel atualiza a cada 30 s e o alerta já chega se a peça atrasar); fácil de acrescentar depois.
5. Alerta por peça atrasada (recomendado: dá o PEC-01 por item e mostra exatamente o que atrasou) ou um alerta por OS (menos barulho). Se o barulho incomodar na oficina piloto, ajustar a configuração da barreira (destinos e prazos) sem desligar o piloto.

---

## Passo a passo para o usuário

Depois que o código desta frente subir (nada disso foi feito agora; é para a hora do deploy):
1. Rodar o `migrate-safe.php` uma vez (a mudança desta frente é uma coluna nova em `quote_items`). Para as oficinas que têm banco próprio, seguir o procedimento definido na frente de oficinas (F00). Se a coluna faltar, o painel funciona do mesmo jeito.
2. Em Configurações > Funções, editar a função do perfil Peças e marcar: "Painel de Peças", "Ver valores financeiros", "Minha rotina", Estoque, Grupos e "Meu bônus" (e "Ordens de Serviço > Visualizar" só se quiser que Peças abra a OS). NÃO marcar nada disso para a função do Técnico.
3. Em Configurações > Ficha de Enquadramento, preencher "Impostos sobre a venda (%)", "Despesas variáveis de peças (%)" e "Margem desejada de peças (%)" (ou a "Tabela de margem de peças" por faixa). Sem isso o painel avisa que o preço mínimo está desligado.
4. Em Configurações > Indicadores e alertas > Barreiras de prazo > "Precificação de peça": conferir 20 minutos (peça em estoque) e 40 minutos (peça que precisa cotar), os destinos (nível 1 Peças, nível 2 Chefe) e o horário comercial da oficina. No modo de teste esses prazos são divididos por 10.
5. Garantir o cron de 1 minuto (`php artisan schedule:run`), que dispara os alertas de atraso.
6. Conferir na Oficina Teste Mentoria: linha 19 (técnico envia a relação e os itens aparecem no Painel de Peças), linha 22 (um item de estoque dentro de 20 min e um cotado depois de 40 min: o segundo fica vermelho e dispara a barreira) e linha 23 (preço abaixo do mínimo cai na fila de alçadas do gerente).
7. Ensinar a equipe: o técnico pode digitar a peça que não está no cadastro; Peças trabalha pelo Painel de Peças e pede "Relação com erro" quando a lista do técnico vier errada ou incompleta.
