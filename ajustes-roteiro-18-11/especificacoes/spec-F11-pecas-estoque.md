# Especificação — F11-pecas-estoque

**Frente:** F11 — Módulo de Peças, parte 2: pedido de compra, entrega ao técnico, estoque e inventário.
**Itens do PDF:** B4 (segunda metade) + linhas 34, 35 e 46 do roteiro + PEC-01 a PEC-13, TEC-05, TEC-06, DRE-07.
**Tamanho:** grande. **Gate:** tudo que bloqueia só vale com `WorkshopSettings 'piloto_ativo'` ligado **e** o módulo migrado (`EstoqueSchema::ativo()`); com o piloto desligado as telas de estoque funcionam exatamente como hoje.

> Convenções desta spec: caminhos são relativos a `d:\volmaster crm\volmaster crm`; `SO/` = `packages/Webkul/ServiceOrder/src/`; `PI/` = `packages/Webkul/PartInventory/src/`. "Piloto ligado" = `StageGate::active()`.

---

## 0. Visão geral (leia primeiro)

Fluxo que passa a existir de ponta a ponta (cada seta grava quem, quando e o quê; nada é apagado):

```
OS autorizada ─► PEDIDO DE COMPRA (fornecedor, prazo prometido, carimbo de compra)
                     │  barreira "entrega_peca" (alerta se passar do prazo prometido)
                     ▼
        NF DE ENTRADA recebida ─► lançada em ≤ 24 h (PEC-07) ─► SALDO SOBE (movimento "entrada", custo médio)
                     │
                     ▼
   ENTREGA AO TÉCNICO (carimbo de entrega) ─► SALDO DESCE (movimento "saída" ligado à OS e ao item)  [PEC-05]
                     │
                     ▼
   CONFERÊNCIA (lançadas × entregues) ─► divergência calculada pelo sistema [PEC-06]; F09 bloqueia o faturamento

AJUSTE DE SALDO ─► fila de alçadas (assunto "Ajuste de estoque") ─► só depois de aprovado grava o movimento "ajuste"
INVENTÁRIO ROTATIVO SEMANAL ─► contagem às cegas ─► divergência ─► pedido de ajuste na fila de alçadas [PEC-09/PEC-10]
PEÇAS PARADAS > N dias (config) ─► lista + valor [PEC-11]
```

Regras de ouro do módulo (valem com o piloto ligado):

1. **Entrada** de saldo: só por **NF de entrada** (ou **saldo de abertura**, uma única vez por peça/depósito, ou **devolução ao estoque** ligada à OS).
2. **Saída** de saldo: só por **entrega ao técnico em uma OS** (movimento `saida` com `reference_id` = OS **e** `os_item_id` = linha da OS). Não existe baixa avulsa.
3. **Ajuste** de saldo (±): só depois de **aprovação na fila de alçadas**; o movimento `ajuste` nasce no ato da aprovação.
4. **Movimento nunca é editado nem apagado**. Estorno = novo movimento com justificativa (e, se reduz saldo sem OS, passa pela alçada).
5. Prazos, dias, quantidades e regra do inventário vêm de `WorkshopSettings` (nada fixo no código).
6. Custo só aparece para quem tem `ValoresFinanceiros::can()`. Eventos, alertas e o cartão de peças da OS **nunca** levam R$.

---

## O que já existe

### Pacote `PartInventory` (peças, saldo, reservas, movimentos)

| O quê | Onde | Observação |
|---|---|---|
| Tabelas `parts`, `part_inventories` (`in_stock`, `allocated`, `warehouse_id`, `warehouse_location_id`), `part_stock_movements` (`type` enum entrada/saida/ajuste, `quantity` com sinal, `reason`, `reference_id`, `user_id`) | `PI/Database/Migrations/2026_08_04_111246…111248*.php` | `parts.valor_unitario` é o **preço de venda** (o técnico usa no servidor, `SO/Http/Controllers/TecnicoController.php:105`); **não existe custo** em `parts`. |
| Saldo livre = `in_stock − allocated` | `PI/Models/PartInventory.php:26-31` (`onHand`) | Reserva (`allocated`) é "soft"; sem linha no livro. |
| Movimento com trava de saldo negativo | `PI/Services/PartInventoryService.php:16-48` (`applyMovement`) | Sem lock de linha; aceita `saida` sem OS; `ajuste` com qualquer sinal. |
| Reserva / liberação / consumo | `PartInventoryService.php:54-57` (`allocate`), `:62-65` (`release`), `:71-86` (`consumeAllocation`) | `consumeAllocation` não confere saldo (pode negativar). |
| Reserva ao lançar peça na OS; liberação ao remover | `SO/Services/ServiceOrderItemService.php:68-74`, `:84-99` | Usa `PartInventory::where('part_id')->first()` (1ª linha). |
| Consumo (baixa real) **só na conclusão** da OS; liberação ao cancelar | `SO/Observers/ServiceOrderObserver.php:123-148` | `reference_id` = id da OS; sem `os_item_id`. |
| Tela da peça com formulário "Adicionar / Ajustar Estoque" (entrada ou ajuste livre, qualquer sinal, sem aprovação) | `PI/Resources/views/view.blade.php:90-119` → rota `admin.parts.movements.store` (`PI/Routes/web.php:23`) → `PI/Http/Controllers/PartController.php:182-209` | **Via aberta**: ajuste negativo = baixa sem OS; entrada sem NF. |
| Criação da peça com "Quantidade inicial" cria saldo **sem movimento** | `PartController.php:79-86` | Via aberta (saldo sem rastro). Idem importação: `SO/Services/SpreadsheetImportService.php:357-370`. |
| Exclusão de peça / depósito apaga estoque **e histórico** por cascata | `PartController.php:158-177`; FKs `onDelete('cascade')` em `…111247…:20-27` e `…111248…:17-18`; `Admin\…\WarehouseController.php:139-158` (rota `settings-routes.php:293`) | Via aberta (viola "nada se apaga"). `quote_items.part_id` é `ON DELETE SET NULL`. |
| Alerta/trava de "saída sem OS" | `SO/Services/Bonus/TravaService.php:188-208` (`saidasDeEstoque`: `saida` com `reference_id` nulo) | Rede de segurança; continua valendo. Teste: `tests/Feature/Workshop/BonusTest.php:225`. |
| ACL `parts`, `parts.view/create/edit/delete/movements` | `PI/Config/acl.php:4-39` | Menu "Estoque" = `admin.parts.index`. |

### Pacote `ServiceOrder` — compra, entrega e carimbos **por item** (parcial)

| O quê | Onde |
|---|---|
| Colunas em `quote_items`: `precificada_em`, `origem_preco` (estoque/fornecedor), `custo_unitario`, `fornecedor` (texto), `comprada_em`, `prazo_entrega_em`, `entregue_tecnico_em`, `adicional` | `SO/Database/Migrations/2026_11_17_100000_extend_barriers_and_workflow_for_mentoria.php:67-80`; casts em `SO/Models/ServiceOrderItem.php:51-57` |
| Ação "Registrar compra de peça" (carimbo `comprada_em`, fornecedor texto, prazo = data às 18:00, alçada `compra_pecas` se a OS **não** está autorizada, evento `compra_peca`) | `SO/Services/WorkflowActions.php:108-117` (definição) e `:417-442` (`buy`) |
| Ação "Entregar peça ao técnico" (só carimbo `entregue_tecnico_em` + evento `peca_entregue_tecnico` com `prazo_entrega_em`; **não mexe no estoque**) | `WorkflowActions.php:118-123` e `:444-461` (`deliverPart`) |
| Barreira `compra_peca` (fim do dia da aprovação → Peças; +1 dia → Gerente; fecha com `compra_peca` quando `todas_compradas`) | `SO/Models/BarrierRule.php:179-194`; condições em `SO/Services/BarrierConditions.php:16-46` |
| Motor de barreiras (modo `data_alvo` já existe: vence em `dados_json['prazo_em']`) | `SO/Services/BarrierEngine.php:38-72` (`handleEvent`), `:114-161` (`open`), `:194-207` (`dueFromTarget`), `:243-252` (`reference` usa `dados_json['referencia_id']`) |
| "Conferir peças aplicadas × lançadas" **manual** (sim/não, sem cálculo) | `WorkflowActions.php:163-171` e `:485-495` (`checkParts`, evento `pecas_conferidas`) |
| Alçada **"Ajuste de estoque"** já cadastrada no catálogo (`limites`; só o gerente aprova até R$ X, acima o dono; `automatico = false`, **ninguém dispara**) | `SO/Services/Alcada/AlcadaCatalog.php:36`; "Compra de peças" em `:34` |
| Fila de alçadas (pedido → gerente → dono; aprovação "consumida" quando quem pediu repete a ação) | `SO/Services/Alcada/AlcadaService.php:111-143` (`authorize`), `:149-159` (`request`), `:161-188` (`open`), `:228-276` (`decide`) |
| Indicadores já calculados: PEC-01..13 | `SO/Services/Indicators/IndicatorCalculator.php:135-148` (registro) e `:735-868` (funções) |
| Painel de Peças atual (dentro de "Minha rotina"): precificar, compras do dia, entregas pendentes, inventário da semana (só "feito ou não") | `SO/Services/Rotina/RotinaService.php:170-198` (`dePecas`); teste `tests/Feature/Workshop/RotinaTest.php:82-107` |
| Registros manuais `nf_entrada`, `inventario_rotativo`, `auditoria_estoque`, `garantia_fornecedor`, `peca_errada` | `SO/Models/MentoriaRegistro.php:52-56` |
| Padrão de tolerância a coluna ausente | `SO/Services/PrevisaoOs.php:17-25` (`Schema::hasColumn` memoizado — **memoiza para sempre**; aqui a memória será por banco, ver `EstoqueSchema`) |
| Alerta sem OS (`AlertService::raise`) e rotina horária | `SO/Services/AlertService.php:101-147`; `SO/Services/PeriodicAlerts.php:55-64` |
| Contas a pagar com flag `pagamento_pecas` / `nf_entrada_conferida` (FIN-07) | `SO/Models/Lancamento.php:12-45`; `SO/Services/Financeiro/FinanceiroService.php:38-54`, `:73-102` |

### Pacote `Warehouse` e `Product`

- `Warehouse` (`warehouses`, `warehouse_locations`): só cadastro de depósitos/locais; **sem lógica de saldo**. `part_inventories.warehouse_location_id` existe mas **nenhuma tela o preenche** (a lista de contagem do inventário vai mostrar o local quando houver).
- `Product` (Krayin): tem seu próprio `product_inventories` e a rota `admin.products.inventories.store` (`Admin/Routes/Admin/products-routes.php:26`). **Não é usado pelas peças da OS** (`quote_items.product_id = 0`) e o menu principal não tem "Produtos" (`Admin/Config/menu.php`). Fora do escopo; recomendação para a F05: não conceder `products.*` aos perfis da oficina.

### Mapa completo das vias de entrada, saída e ajuste (e como cada uma será fechada)

| # | Via hoje | Problema | Fechamento (piloto ligado + módulo migrado) |
|---|---|---|---|
| 1 | `PartController::storeMovement` (`admin.parts.movements.store`) tipo `ajuste` negativo | Baixa sem OS e sem aprovação | Política `part.stock.movement.before` recusa `ajuste` sem `estoque_ajuste_id` aprovado; formulário antigo some e entra "Pedir ajuste de saldo" |
| 2 | Mesma rota, tipo `entrada` | Entrada sem NF | Política recusa `entrada` sem origem permitida (`nf_entrada`, `devolucao_os`, `abertura`) |
| 3 | `PartInventoryService::applyMovement(…,'saida',…)` chamado por código | Saída sem OS (hoje possível) | Política exige `reference_id` (OS) **e** `contexto['os_item_id']` válido (item da OS, mesma peça, dentro do que falta entregar) |
| 4 | `PartInventoryService::consumeAllocation` (conclusão da OS) | Baixa em lote sem item e sem conferir saldo; momento errado (semanas depois da entrega) | Com o piloto ligado a baixa passa a ser a **entrega ao técnico**; na conclusão só se **libera** a reserva do que sobrou |
| 5 | `PartController::store` com "Quantidade inicial" | Saldo sem movimento | Cria a linha com `in_stock = 0` e grava **saldo de abertura** como movimento (`PartInventoryService::abrirSaldo`) |
| 6 | `SpreadsheetImportService::importPartRow` | Idem | Mesmo `abrirSaldo` |
| 7 | `PartController::destroy` / `Part::delete()` | Cascata apaga linhas de estoque **e movimentos** | `Part::deleting` recusa se há movimento, saldo, reserva, OS ou NF/pedido ligados |
| 8 | Exclusão de depósito (Configurações > Depósitos) | Cascata apaga estoque e movimentos | `Warehouse::deleting` recusa se há saldo ou movimento no depósito |
| 9 | `ServiceOrderItemService::removeItem` / cancelar OS depois de entregar a peça | Deixa baixa órfã | Bloqueia: "devolva ao estoque antes" |
| 10 | Edição direta do modelo (`PartInventory::update(['in_stock' => …])`, `create` com saldo) | Contorna o livro | Guarda no modelo (`updating`/`creating`) com usuário logado: saldo só muda dentro de `PartInventoryService` (`isMoving()`), mesmo padrão de `StageAdvanceService::isAdvancing()` |
| 11 | `UPDATE`/`DELETE` em `part_stock_movements` | Altera o livro | `PartStockMovement::updating/deleting` lançam `LogicException` (sempre, como `MentoriaRegistro`) |
| 12 | `Product` inventories | Outro estoque | Fora do escopo (ver acima) |
| 13 | Excluir a OS (`ServiceOrderController::destroy`, rota `admin.service_orders.destroy`) com peças já baixadas | Os movimentos ficam apontando para uma OS que não existe mais | `ServiceOrder::deleting` recusa se houver movimento com `reference_id` = a OS (`erros.os_com_movimento`); a OS se cancela, não se exclui |

---

## Requisitos do PDF → como serão atendidos

Legenda: **EXISTE** = pronto; **PARCIAL** = existe uma parte; **FALTA** = não existe.

| Bullet do PDF (B4, 2ª metade) | Situação hoje | Como será atendido |
|---|---|---|
| **Pedido de compra ligado à OS autorizada, com fornecedor, prazo prometido e entrega ao técnico (carimbo)** | **PARCIAL**: existem os carimbos por item (`comprada_em`, `entregue_tecnico_em`), fornecedor só como texto, prazo só como data fixa às 18:00; não há "pedido", nem cadastro de fornecedor, nem barreira do prazo prometido | Cadastro de **fornecedores**; **pedido de compra** (cabeçalho + linhas ligadas aos itens da OS) com fornecedor, **prazo prometido (data + hora)**, carimbo de compra (quem/quando); só emite sozinho se a OS está autorizada (senão alçada `compra_pecas`, como hoje); nova barreira **`entrega_peca`** (modo `data_alvo`): alerta Peças no prazo prometido e o Chefe 4 h úteis depois; **entrega ao técnico** passa a ser movimento de estoque com carimbo (quem entregou, qual técnico recebeu); a ação antiga `comprar_peca` cria o pedido por baixo (compatibilidade) |
| **Estoque: entrada por NF de entrada** | **FALTA** | Tabelas `nfs_entrada`/`nf_entrada_itens`; passo 1 "registrar recebimento" (carimba `recebida_em`), passo 2 "lançar" (itens, custo, fornecedor, depósito) → movimento `entrada`, **custo médio** em `parts.custo_medio`, baixa o pedido de compra; duplicidade recusada; estorno por pedido de ajuste |
| **Saída SÓ por lançamento em OS** | **PARCIAL** (reserva e baixa existem, mas há várias vias abertas — ver o mapa acima) | Tabela de vias acima; política única no `PartInventoryService`; entrega ao técnico = única saída; devolução ao estoque = entrada ligada à OS |
| **Ajuste de saldo só com aprovação (fila de alçadas)** | **PARCIAL**: assunto `ajuste_estoque` existe e nada o dispara | Tabela `estoque_ajustes` + `AlcadaService::request('ajuste_estoque')`; o saldo só muda quando o gerente (ou o dono, acima do limite) **aprova** (gancho `workshop.alcada.decidida`); recusa não altera nada; `automatico = true` no catálogo |
| **Inventário rotativo semanal com contagem e divergência** | **FALTA** (só um registro manual "feito sim/não") | `inventarios`/`inventario_itens`; lista da semana gerada pela **regra configurável** (rodízio/valor/giro) e pela quantidade configurada; **contagem às cegas**, recontagem sem apagar; fechamento calcula divergências, grava `inventario_rotativo` (PEC-09/PEC-10) e abre **pedido de ajuste** por item divergente |
| **Peças paradas há mais de 180 dias: lista e valor** | **PARCIAL**: só o número de PEC-11 (`IndicatorCalculator.php:834-854`), 180 fixo no código, valor pelo preço de venda, "movimento" inclui ajuste | `EstoqueParadoService` (lista + valor + resumo) com `estoque_parado_dias` (180) em `WorkshopSettings`; valor a **custo médio** (cai para preço de venda marcado como "estimado"); ajuste não zera o relógio; tela `admin.estoque.parados`; PEC-11 e o Painel do Dono passam a usar o mesmo serviço |
| **Divergência entre peças aplicadas e lançadas (roteiro 46)** | **PARCIAL**: conferência manual sim/não | `PecasConferencia` calcula por item (lançada × entregue), expõe `divergencias()/temDivergencia()/pendencias()` para a **F09** bloquear o faturamento; `conferir_pecas` com piloto ligado **não aceita** "sem divergência" se o cálculo acusa; caminhos de correção: entregar o que falta, devolver, ou reduzir a quantidade lançada (registra `orcamento_corrigido`) |
| **Alimenta PEC-01 a PEC-13, TEC-05, TEC-06, DRE-07** | Calculadas hoje; várias dependem de registro manual | Tabela "Indicadores" na seção "Mudanças por arquivo" (quais ficam como estão, quais mudam e de onde vem o dado) |
| **Painel de Peças: compras do dia, peças a entregar ao técnico, NF de entrada a lançar, inventário rotativo da semana** | **PARCIAL** (compras do dia, entregas e inventário "sim/não" já existem em `RotinaService::dePecas`) | `EstoquePainel` devolve as seções prontas no formato de `RotinaService::secao()` (ver "Interface com a F10") |
| **Teste: baixar peça sem OS é impossível** | **FALTA** | Testes na seção "Testes Pest" |
| **Regras gerais: nada de movimento apagado; tudo que é regra vem da configuração** | Parcial (cascata apaga) | Imutabilidade do livro + bloqueios 7/8/11 + 8 chaves novas em `WorkshopSettings` |
| **Roteiro 34** (Peças registra a compra e a entrega ao técnico → carimbos de compra e entrega) | PARCIAL | Pedido de compra + entrega com carimbos de quem/quando |
| **Roteiro 35** (tentar baixar peça sem OS → impossível) | FALTA | Política única + guardas |
| **Roteiro 46** (divergência aplicadas × lançadas → Financeiro não fatura até Peças corrigir) | PARCIAL | `PecasConferencia` (a trava é da F09) |

---

## Mudanças por arquivo

### M.0 Ordem de implementação sugerida

1. Migrations (seção "Banco") e `EstoqueSchema`.
2. Modelos novos + trait `SemExclusao`.
3. `PartInventoryService` / `PartStockMovement` (pacote PartInventory) + `EstoquePolitica` + guardas de modelo.
4. Serviços `Estoque*` (saldo, baixa, pedido de compra, NF, ajuste, inventário, parados, conferência, painel, prazos).
5. Ganchos nos arquivos existentes do ServiceOrder (observador, ações, alçadas, barreira, indicadores, alertas, rotina).
6. Controllers, rotas, ACL, views, textos.
7. Testes (um arquivo por assunto) e `pint`.

Regra transversal de **tolerância**: toda tela e todo serviço que roda para qualquer usuário (tela da OS, observador da OS, `RotinaService`, `IndicatorCalculator`, `PeriodicAlerts`) consulta `EstoqueSchema::ativo()` antes de tocar nas tabelas/colunas novas. Sem as migrations o sistema se comporta como hoje (legado), sem erro 500.

---

### M.1 Arquivos a CRIAR

#### M.1.1 Modelos (`SO/Models/`, Eloquent simples como `AlcadaPedido`; sem Contract/Proxy)

Todos usam a trait `SO/Models/Concerns/SemExclusao.php` (nova): `static::deleting(fn () => throw new LogicException('Registros do estoque não podem ser apagados.'))`. Atualização só nos campos de estado indicados.

| Modelo | Tabela | Notas |
|---|---|---|
| `Fornecedor` | `fornecedores` | `$guarded = []`; `scopeAtivos()`; `casts ativo => boolean` |
| `PedidoCompra` | `pedidos_compra` | Constantes `STATUS_ABERTO='aberto'`, `STATUS_PARCIAL='parcial'`, `STATUS_RECEBIDO='recebido'`, `STATUS_CANCELADO='cancelado'`; `casts`: `comprado_em`, `prazo_prometido_em`, `recebido_em`, `cancelado_em` => datetime; `itens()` hasMany; `ordem()` belongsTo `ServiceOrder`; `fornecedor()`; `scopeAtivos()` = status aberto/parcial; accessor `numero` = `'PC-'.str_pad($id, 5, '0', STR_PAD_LEFT)`; `atrasado(): bool` = ativo e `prazo_prometido_em?->isPast()` |
| `PedidoCompraItem` | `pedido_compra_itens` | `casts recebida_em => datetime`; `pedido()`, `itemOs()` belongsTo `ServiceOrderItem` (`quote_item_id`), `part()` |
| `NfEntrada` | `nfs_entrada` | Constantes `STATUS_RECEBIDA='recebida'`, `STATUS_LANCADA='lancada'`, `STATUS_ESTORNO_PEDIDO='estorno_pedido'`, `STATUS_ESTORNADA='estornada'`; `casts` datetimes; `itens()`, `fornecedor()`, `pedido()`; `venceEm(): Carbon` = `recebida_em + EstoquePrazos::nfLimiteMinutos()` |
| `NfEntradaItem` | `nf_entrada_itens` | `nf()`, `part()`, `movimento()` (belongsTo `PartStockMovement` por `movimento_id`) |
| `EstoqueAjuste` | `estoque_ajustes` | Constantes de status `pendente/aplicado/recusado` e de origem `manual/inventario/estorno_nf` (saldo de abertura **não** é ajuste: é movimento `entrada` com origem `abertura`); `alcadaPedido()` (belongsTo `AlcadaPedido`), `partInventory()`, `part()`; `casts decidido_em => datetime` |
| `Inventario` | `inventarios` | `STATUS_EM_CONTAGEM='em_contagem'`, `STATUS_FECHADO='fechado'`; `itens()`; `vigentes()` (itens sem `corrige_id` apontando para eles) |
| `InventarioItem` | `inventario_itens` | `scopeVigentes()` = `whereNotIn('id', fn => select corrige_id where corrige_id is not null)` (mesmo padrão de `MentoriaRegistro::scopeVigentes`); `partInventory()`, `part()`, `ajuste()`; `updating`: os campos da contagem (`quantidade_contada`, `saldo_sistema`, `divergencia`, `contado_em`, `contado_por`) só podem ser preenchidos **uma vez** (se já tinham valor → `LogicException`); recontagem = nova linha com `corrige_id`; `ajuste_id` pode ser preenchido depois |

Em `SO/Models/ServiceOrderItem.php` (alterar, ver M.2): casts novos e dois helpers.

#### M.1.2 Serviços (`SO/Services/Estoque/`, namespace `Webkul\ServiceOrder\Services\Estoque`)

**`EstoqueSchema`** (tolerância; **memória por banco**, porque `workshop:tick` percorre vários bancos no mesmo processo):

```php
public const TABELAS = ['fornecedores','pedidos_compra','pedido_compra_itens','nfs_entrada','nf_entrada_itens','estoque_ajustes','inventarios','inventario_itens'];
public static function ativo(): bool          // todas as TABELAS + colunas part_stock_movements.{origem,os_item_id,estoque_ajuste_id,saldo_apos}, quote_items.quantidade_entregue, parts.custo_medio
public static function tabela(string $nome): bool
public static function coluna(string $tabela, string $coluna): bool
public static function esquecer(): void       // limpa a memória (testes)
public static function forcar(?bool $valor): void // SÓ para testes: false simula "migration pendente"; null volta ao normal
```
Chave da memória: `DB::connection()->getDatabaseName().'.'.$tabela.'.'.$coluna`.

**`EstoquePolitica`** (ouvinte dos eventos e guardas; registrado no provider, ver M.2):

```php
public static function ligada(): bool   // StageGate::active() && EstoqueSchema::ativo()
public function aoMovimentar(PartInventory $pi, string $type, int $delta, ?int $referenceId, array $contexto): void
public function aoAtualizarSaldo(PartInventory $pi): void   // guarda de modelo (updating)
public function aoCriarSaldo(PartInventory $pi): void        // guarda de modelo (creating)
public function aoExcluirPeca(Part $part): void              // Part::deleting
public function aoExcluirDeposito(Warehouse $deposito): void // Warehouse::deleting
public function aoExcluirOs(ServiceOrder $os): void          // ServiceOrder::deleting
```

`aoMovimentar` (só se `ligada()`; **não** depende de usuário logado, porque o ponto de passagem é o serviço; lança `ValidationException::withMessages(['quantity' => [texto]])`):

- `saida`: exige `$referenceId` (id da OS) e `$contexto['os_item_id']`; confere no banco: o item é `Peça`, `quote_id == $referenceId`, `part_id == $pi->part_id`, `abs($delta) <= quantity − EstoqueSaldo::entregue(item)`, OS não `Concluída/Cancelada`; e `locked.in_stock >= abs($delta)` (a linha já está travada pelo serviço). Senão `erros.baixa_sem_os` (ou `erros.saldo_negativo`).
- `entrada`: exige `$contexto['origem']` em `{nf_entrada, devolucao_os, abertura}`. `nf_entrada` → `nf_entrada_id` de uma NF com `status = recebida` (a que está sendo lançada; o serviço só vira `lancada` no fim); `devolucao_os` → `os_item_id` de item com `entregue > 0`; `abertura` → **nenhum** movimento anterior em `part_stock_movements` para essa linha **e** `in_stock = 0` na linha travada (linha antiga com saldo e sem livro não aceita abertura; ela se acerta pelo inventário) (`erros.abertura_repetida`). Senão `erros.entrada_sem_nf`.
- `ajuste`: exige `$contexto['estoque_ajuste_id']` cujo `EstoqueAjuste` tenha `alcada_pedido_id` com `AlcadaPedido.status = aprovado` (no momento da aprovação o pedido já foi atualizado dentro da mesma transação). Senão `erros.ajuste_sem_aprovacao`.

`aoAtualizarSaldo` / `aoCriarSaldo`: só `ligada()` **e** `auth()->guard('user')->check()` (mesmo critério de `ServiceOrderObserver.php:105`; scripts e testes sem usuário continuam podendo montar dados, e a política acima já cobre qualquer chamada ao serviço). Se `isDirty('in_stock')` (ou `in_stock != 0` na criação) e `! PartInventoryService::isMoving()` → `LogicException('O saldo do estoque só muda por movimento (NF de entrada, OS ou ajuste aprovado).')`.

`aoExcluirPeca`: se `ligada()` e existir qualquer `part_stock_movements` das linhas da peça, ou `SUM(in_stock) <> 0`, ou `quote_items.part_id`, `pedido_compra_itens.part_id` ou `nf_entrada_itens.part_id` apontando → `ValidationException(erros.peca_com_historico)`. `aoExcluirDeposito`: se existir linha de estoque do depósito com saldo ≠ 0 ou movimento → `erros.deposito_com_estoque`. `aoExcluirOs`: se `ligada()` e existir `part_stock_movements.reference_id = $os->id` (ou pedido de compra/NF ligados a ela com recebimento) → `erros.os_com_movimento`.

**`EstoqueSaldo`** (consultas puras; todas estáticas):

```php
public static function fisico(int $partId): int                 // SUM(in_stock) de todas as linhas
public static function reservado(int $partId): int              // SUM(allocated)
public static function entregue(ServiceOrderItem $item): int    // max(quantidade_entregue, entregue_tecnico_em ? quantity : 0)  → trata o carimbo antigo (sem baixa) como "entregue"
public static function reservaDoItem(ServiceOrderItem $item): int // max(0, quantity − entregue)
public static function cobreItem(ServiceOrderItem $item): bool  // fisico − (reservado − reservaDoItem) >= quantity − entregue   (corrige o "sem linha de estoque = tem estoque" de WorkflowService::hasStock)
public static function escolherInventario(int $partId, int $quantidade): ?PartInventory // linha com in_stock >= quantidade (a de maior saldo); senão a de maior saldo
public static function custoMedio(int $partId): ?float          // parts.custo_medio ?? parts.ultimo_custo ?? null
public static function custoBase(int $partId): array            // ['valor' => float, 'estimado' => bool]; estimado=true quando cai para valor_unitario
```
Toda consulta trata coluna ausente como neutra (0/null): sem `quote_items.quantidade_entregue` só vale o carimbo antigo; sem `parts.custo_medio` o custo cai para `valor_unitario` com `estimado = true`.

**`EstoqueBaixaService`** (entrega ao técnico e devolução; os eventos da OS são gravados **depois do commit**):

```php
/** @return array{movimento: ?PartStockMovement, completa: bool, quantidade: int} */
public function entregarAoTecnico(ServiceOrder $os, ServiceOrderItem $item, ?int $quantidade = null, ?int $mechanicId = null, ?int $partInventoryId = null, ?User $user = null): array
public function devolverAoEstoque(ServiceOrder $os, ServiceOrderItem $item, int $quantidade, string $causa, string $motivo, ?User $user = null): PartStockMovement
```

`entregarAoTecnico`:
1. Modo legado (piloto desligado **ou** `! EstoqueSchema::ativo()` **ou** item sem `part_id`): só carimbo + evento `peca_entregue_tecnico` (idêntico a `WorkflowActions::deliverPart` de hoje, `:444-461`), sem estoque.
2. Valida: item é `Peça` desta OS; OS em `Em Execução`, `Veículo em Teste` ou `Faturamento` (a conferência do roteiro 46 acontece até o faturamento); `quantidade` default = `quantity − entregue`; `1 ≤ quantidade ≤ quantity − entregue` (senão `os.entrega_a_mais`).
3. Se a peça está em pedido de compra ativo e o saldo físico não cobre a quantidade → `erros.peca_nao_chegou` ("lance a NF de entrada primeiro").
4. `DB::transaction`: trava a linha do item (`lockForUpdate`); escolhe a linha de estoque (`partInventoryId` informado precisa ser da mesma peça); **libera a reserva** na linha onde ela foi feita (`PartInventory::where('part_id', …)->first()`, como `ServiceOrderItemService.php:68-74`) com `PartInventoryService::release($reserva, $quantidade)`; `PartInventoryService::applyMovement($linha, 'saida', $quantidade, "OS #{$os->id} — entrega ao técnico", $os->id, $user?->id, ['origem' => 'os', 'os_item_id' => $item->id, 'custo_unitario' => EstoqueSaldo::custoMedio(part)])`; atualiza o item: `quantidade_entregue += q`; se completou e `entregue_tecnico_em` é nulo → `entregue_tecnico_em = now()`, `entregue_por = user`, `entregue_a_mechanic_id = técnico` (default: `item.funcionario_id ?: os->tecnicoDesignadoId() ?: os.mechanic_id`); se `item.custo_unitario` é nulo, preenche com o custo médio (alimenta PEC-12 e a DRE).
5. Depois do commit: `ServiceOrderEvent::record($os, 'peca_entregue_tecnico', ['item_id','peca','quantidade','completa','tecnico' => nome, 'prazo_entrega_em' => (do pedido de compra, se houver), 'pedido_compra_id', 'referencia_id' => pedido_compra_id])`. **Sem valores em R$**.

`devolverAoEstoque`: `causa ∈ {nao_usada, peca_errada, defeito, outra}`; `1 ≤ quantidade ≤ saldo líquido de saídas do item no livro` (Σ `saida` com `os_item_id` − Σ `entrada` com `origem = devolucao_os` do mesmo item; item entregue só pelo carimbo antigo, sem movimento, responde "Esta peça foi entregue antes do módulo de estoque; não há baixa para devolver."); motivo ≥ 5 caracteres; devolve para a linha de estoque do último movimento `saida` do item (`os_item_id`); `applyMovement(… 'entrada' …, ['origem' => 'devolucao_os', 'os_item_id' => id])` com `reference_id` = OS; **re-reserva** (`allocate`) a quantidade devolvida (o item volta a "lançado e não entregue"); `quantidade_entregue −= q` e, se deixou de estar completa, `entregue_tecnico_em = null` (o histórico fica nos eventos/movimentos); evento `peca_devolvida`; se `causa = peca_errada` grava `MentoriaRegistro::record('peca_errada', ['service_order_id' => $os->id, 'observacao' => …])` (alimenta PEC-02 e TEC-02).

**`PedidoCompraService`**:

```php
public static function disponivel(): bool                        // EstoqueSchema::tabela('pedidos_compra')
public function osAutorizada(ServiceOrder $os): bool             // aprovacao_em && aprovacao_tipo in (total, parcial) && etapa não terminal
public function emitir(array $dados, ?User $user = null): PedidoCompra
public function emitirParaItens(ServiceOrder $os, array $itemIds, array $extra = [], ?User $user = null): PedidoCompra // usado por WorkflowActions::buy
public function cancelar(PedidoCompra $pedido, string $motivo, ?User $user = null): PedidoCompra
public function registrarRecebimento(PedidoCompraItem $linha, int $quantidade): void // chamado pela NF
public function momentoDoPrazo(string $data, ?string $hora): Carbon  // sem hora = fim do expediente (BusinessHours::endOfBusinessDay); com hora = aquela hora
```

`$dados` de `emitir`: `service_order_id?`, `fornecedor_id?`, `fornecedor_nome?`, `fornecedor_novo?: {nome, telefone?, documento?}`, `prazo_data?` (Y-m-d), `prazo_hora?` (H:i), `observacao?`, `itens: list<{quote_item_id?, part_id?, descricao?, quantidade, custo_unitario?}>`.

`emitir`:
1. Resolve fornecedor (cria o "novo" se vier); guarda `fornecedor_nome` (snapshot). No serviço o fornecedor é **opcional** (a ação antiga `comprar_peca` e o teste `AlcadasTest.php:325` não informam); a **tela** o exige (`required_without`).
2. Se há OS: cada `quote_item_id` precisa ser `Peça` da OS, não estar totalmente entregue e **não** estar em outro pedido ativo (`erros.pedido_existente`); a OS não pode estar `Concluída/Cancelada`.
3. Alçada: se não há OS ou `! osAutorizada($os)` → `AlcadaService::authorize('compra_pecas', $os, Σ quantidade × custo, 'Compra de … sem autorização do cliente na OS' / 'Reposição de estoque')` (mesma regra de hoje, `WorkflowActions.php:423-430`; o pedido só nasce depois da aprovação, "repita a ação").
4. Transação: cria `PedidoCompra` (`comprado_em = now()`, `comprado_por`, `prazo_prometido_em`, status `aberto`) e as linhas; para cada item da OS: `forceFill(['comprada_em' => now(), 'fornecedor' => nome(≤120), 'prazo_entrega_em' => prazo, 'situacao' => Disponível])` (mantém os consumidores antigos: `BarrierConditions`, `RotinaService`, `FinanceiroService.php:296`).
5. Depois do commit, **se há OS**: evento `compra_peca` (um por pedido; `dados_json`: `pedido_compra_id`, `item_id` = 1º item, `item_ids`, `fornecedor`, `prazo_entrega_em`; **sem** `referencia_id`, para fechar a barreira `compra_peca` que abre com `referencia_id = 0`) e evento `pedido_compra_emitido` (`referencia_id = pedido_compra_id`, `prazo_em = prazo`, `fornecedor`) que abre a barreira `entrega_peca`.

`cancelar`: só se nenhuma linha recebeu (`quantidade_recebida = 0`); motivo ≥ 5; status `cancelado` (+quem/quando/motivo); limpa `comprada_em`, `prazo_entrega_em`, `fornecedor` dos itens da OS (o carimbo antigo continua nos eventos); evento `pedido_compra_cancelado` (`referencia_id` = pedido) que encerra a barreira `entrega_peca`.

`registrarRecebimento`: soma `quantidade_recebida`; ao completar a linha seta `recebida_em` e, se `quote_item_id`, `quote_items.recebida_em`; recalcula o status do pedido (`parcial` / `recebido` + `recebido_em`); evento `peca_recebida` (só com OS).

**`NfEntradaService`**:

```php
public function receber(array $dados, ?User $user = null): NfEntrada       // passo 1: carimba recebida_em (≤ agora), sem tocar no saldo
public function lancar(NfEntrada $nf, array $itens, ?User $user = null, array $opcoes = []): NfEntrada // passo 2: itens + custo → saldo
public function lancarDireto(array $cabecalho, array $itens, ?User $user = null, array $opcoes = []): NfEntrada // receber + lancar na mesma tela
public function solicitarEstorno(NfEntrada $nf, string $motivo, ?User $user = null): Collection // devolve os EstoqueAjuste criados
public function aoDecidirEstorno(EstoqueAjuste $ajuste): void              // chamado por EstoqueAjusteService
```

- Duplicidade: recusa se existir NF com a mesma `chave_acesso` ou o mesmo trio `(fornecedor, numero, serie)` (`nf.duplicada`).
- `lancar`: ver algoritmo em "Fluxo da NF" abaixo. **Custo exige `ValoresFinanceiros::can()`** (o controller chama `ValoresFinanceiros::authorize('NF de entrada')`); registrar recebimento não exige.
- `$opcoes['gerar_conta_a_pagar']` (Etapa 2, ver M.2.4): cria `Lancamento` via `FinanceiroService::criar`.

*Fluxo da NF (`lancar`)*, dentro de `DB::transaction`: para cada item → `PartInventory::firstOrCreate(['part_id', 'warehouse_id' => nf.warehouse_id ?? WorkshopSettings::get('estoque_deposito_padrao') ?? 1º depósito (menor id)], ['in_stock' => 0, 'allocated' => 0])`; guarda `saldoAnterior = SUM(in_stock)` e `custoAnterior`; `applyMovement($linha, 'entrada', q, "NF {numero} — {fornecedor}", null, user, ['origem' => 'nf_entrada', 'nf_entrada_id' => id, 'custo_unitario' => c])`; cria `NfEntradaItem` (com `movimento_id`); atualiza a peça com `forceFill` (**não** colocar em `$fillable`): `custo_medio = saldoAnterior > 0 && custoAnterior !== null ? round((saldoAnterior·custoAnterior + q·c) / (saldoAnterior + q), 4) : c`, `ultimo_custo = c`, `ultima_entrada_em = now()`; se o item aponta `pedido_compra_item_id` → `PedidoCompraService::registrarRecebimento`. No fim: `status = lancada`, `lancada_em = now()`, `lancada_por`, `total_itens = Σ q·c`. `total_nota` informado é só conferência: diferença gera **aviso** na tela, não bloqueia (a nota tem impostos que não entram no custo do item).

`solicitarEstorno`: NF `lancada`; para cada item exige `in_stock da linha ≥ quantidade` (senão lista as peças que já saíram); cria um `EstoqueAjuste` por item (`origem = estorno_nf`, `quantidade = −q`, `nf_entrada_id`, motivo) com pedido na alçada; NF vai a `estorno_pedido`. Em `aoDecidirEstorno`: todos aplicados → NF `estornada`; algum recusado → NF volta a `lancada` (os aplicados ficam registrados). O `custo_medio` **não** é recalculado no estorno (limitação documentada).

**`EstoqueAjusteService`**:

```php
public function solicitar(PartInventory $pi, int $saldoContado, string $motivo, string $origem = 'manual', array $ref = [], ?User $user = null): EstoqueAjuste  // delta = saldoContado − in_stock atual
public function solicitarDelta(PartInventory $pi, int $delta, string $motivo, string $origem, array $ref = [], ?User $user = null): EstoqueAjuste
public function aoDecidirAlcada(AlcadaPedido $pedido, bool $aprovar, ?User $decisor = null): void // ouvinte de 'workshop.alcada.decidida'
```

`solicitarDelta`: `delta ≠ 0` (`ajustes.sem_diferenca`); se `in_stock + delta < 0` → `ajustes.negativo`; cria `EstoqueAjuste` (`saldo_antes`, `quantidade = delta`, motivo ≥ 10 caracteres, `solicitado_por`), calcula `valor = abs(delta) × custoBase` e abre o pedido com `AlcadaService::request('ajuste_estoque', descricao, valor, null, user)` — **`request`, não `authorize`**: `required()` devolve "nenhum nível" quando o dono ainda não preencheu o limite do gerente (`AlcadaService.php:55-76`, `limites()`), e `request` força no mínimo o gerente (`:156`). Guarda `alcada_pedido_id`. Descrição do pedido (sem R$): `Ajuste de {±q} un. de {nome} ({codigo}) no depósito {dep}: saldo {antes} → {depois}. Motivo: {motivo}`.

`aoDecidirAlcada` (só `tema = ajuste_estoque` com `EstoqueAjuste` ligado; pedido "manual" criado em "Pedir uma decisão" é ignorado): recusa → `status = recusado`, `decidido_por/em`; aprova → `aplicar()`: re-lê a linha com `lockForUpdate`, confere `in_stock + delta ≥ 0`, `PartInventoryService::applyMovement($pi, 'ajuste', $delta, "Ajuste #{id}: motivo", null, $decisor->id, ['origem' => origem === 'inventario' ? 'inventario' : (origem === 'estorno_nf' ? 'estorno_nf' : 'ajuste_aprovado'), 'estoque_ajuste_id' => id])`, grava `status = aplicado`, `movimento_id` e marca o pedido `consumido` (`AlcadaPedido` aceita `aprovado → consumido`, `AlcadaPedido.php:45-52`). Se não puder aplicar lança `ValidationException`; como `AlcadaService::decide` roda em transação (M.2), a **decisão inteira volta atrás** e o gerente vê o motivo.

**`InventarioService`**:

```php
public function garantirDaSemana(?User $user = null): Inventario   // idempotente (unique em semana); chamado pelo POST `generate` e pelo PeriodicAlerts — a tela GET só lê (mostra "ainda não gerado" + botão)
public function selecionar(int $limite, string $regra): Collection // de PartInventory
public function contar(InventarioItem $item, int $quantidade, ?User $user = null, ?string $obs = null): InventarioItem
public function fechar(Inventario $inv, ?User $user = null): Inventario
public function resumo(Inventario $inv): array  // ['previstos','contados','corretos','divergentes','faltam','acuracidade','atrasado' => bool,'limite_em' => Carbon]
```

- Semana = `EstoquePrazos::semanaChave()` = `now()->format('o-W')` (**mesmo formato que `inventarioSemanal()` usa**, `IndicatorCalculator.php:826`), segunda a domingo; `inicio`/`fim` gravados.
- **Seleção** (`selecionar`), elegíveis = linhas de estoque com `in_stock > 0` **ou** `allocated > 0` **ou** movimento nos últimos 90 dias. Ordem: (0) primeiro as linhas cuja **última contagem vigente teve divergência não resolvida** (ajuste recusado ou ausente); depois pela regra de `inventario_regra`:
  - `rodizio` (padrão): última contagem mais antiga primeiro (nunca contadas primeiro), desempate por código;
  - `valor`: `in_stock × custo base` decrescente, excluindo quem foi contado nos últimos `inventario_ciclo_dias`;
  - `giro`: nº de movimentos `entrada/saida` nos últimos 90 dias decrescente, excluindo quem foi contado nos últimos `inventario_ciclo_dias`.
  Se a regra `valor`/`giro` não completar a quantidade, completa com `rodizio`. Limite = `inventario_itens_semana`.
- `garantirDaSemana` cria `inventarios` (`regra` = snapshot da regra) e uma linha `inventario_itens` por linha de estoque selecionada (sem contagem). Estoque vazio → inventário com 0 itens (fecha na hora; PEC-10 conta a semana).
- `contar`: **contagem às cegas** — o saldo do sistema só é lido **no ato de gravar**: grava `quantidade_contada`, `saldo_sistema = in_stock atual`, `divergencia = contada − saldo_sistema`, `contado_em/por`. Se o item já tinha contagem **não se altera**: cria **nova linha** com `corrige_id` apontando para a anterior (recontagem); a vigente é a última. Só enquanto o inventário está `em_contagem`.
- `fechar`: exige todos os itens vigentes contados (`inventario.faltam`); para cada vigente com `divergencia ≠ 0` → `EstoqueAjusteService::solicitarDelta($linha, $divergencia, "Inventário da semana {semana}: contagem {c}, sistema {s}", 'inventario', ['inventario_item_id' => id])` e grava `ajuste_id` no item; fecha (`status`, `fechado_em/por`, `contados`, `corretos`); grava `MentoriaRegistro::record('inventario_rotativo', ['dados_json' => ['contados' => N, 'corretos' => M, 'inventario_id' => id, 'semana' => '2026-47'], 'ok' => N === M], $fechado_em)` — é o dado que `acuracidade()` e `inventarioSemanal()` já leem (`IndicatorCalculator.php:814-829`). `corretos` = vigentes com `divergencia = 0`.

**`EstoqueParadoService`**:

```php
public function dias(): int                                   // WorkshopSettings::get('estoque_parado_dias', 180)
public function lista(?int $dias = null): Collection          // linhas (stdClass) ordenadas da mais antiga para a mais nova
public function resumo(?int $dias = null): array              // ['itens','valor_parado','valor_estoque','percentual','custo_estimado']
public function valorDoEstoque(): float                       // Σ in_stock × custo base
```

SQL da lista (MariaDB, `ONLY_FULL_GROUP_BY`): `part_inventories pi JOIN parts p`, `LEFT JOIN part_categories c`, `LEFT JOIN warehouses w`, `LEFT JOIN part_stock_movements m ON m.part_inventory_id = pi.id`; `WHERE pi.in_stock > 0`; `GROUP BY pi.id, p.id, p.codigo, p.nome, c.name, w.name, pi.in_stock, p.valor_unitario, pi.created_at` (+ `p.custo_medio` se a coluna existe); `referencia = COALESCE(MAX(CASE WHEN m.type IN ('entrada','saida') THEN m.created_at END), pi.created_at)` — **ajuste não zera o relógio** (hoje zera: `IndicatorCalculator.php:842`); `HAVING referencia < now() − dias`. `ultima_saida = MAX(CASE WHEN m.type = 'saida' …)`. `custo_base = COALESCE(p.custo_medio, p.valor_unitario)` e `custo_estimado = p.custo_medio IS NULL`. Valores monetários só vão para a tela se `ValoresFinanceiros::can()`; a lista e as contagens não precisam deles.

**`PecasConferencia`** (interface com a **F09**; só tem efeito com `StageGate::active() && EstoqueSchema::ativo()`, senão devolve vazio):

```php
/** @return Collection<int, array{item_id:int, peca:string, lancada:int, entregue:int, diferenca:int, tipo:'falta_entrega'|'entrega_a_mais'}> */
public function divergencias(ServiceOrder $os): Collection   // itens Peça com part_id onde quantity ≠ EstoqueSaldo::entregue(item)
public function temDivergencia(ServiceOrder $os): bool
/** @return string[] */
public function pendencias(ServiceOrder $os): array          // frases pt-BR para a lista "o que falta": 'Peça "Filtro de óleo": lançada 2 e entregue ao técnico 1'
public function reduzirLancada(ServiceOrder $os, ServiceOrderItem $item, int $novaQuantidade, string $motivo, ?User $user = null): void
```

`reduzirLancada`: `entregue ≤ nova < quantity` (para zerar uma linha não entregue use `removeItem`); libera a diferença da reserva; `item.quantity = nova`, recalcula `total` e os totais da OS (`ServiceOrderRepository::recalculateTotals`); evento `orcamento_corrigido` com `{quem: 'pecas', motivo, item_id, de, para}` (entra em PEC-03, CHE-03 e TEC-06 — correção de Peças depois da aprovação é exatamente o que esses indicadores medem).

**`PecasOsStatus`** (cartão da OS; **sem nenhum valor em R$**):

```php
/** @return list<array{item_id:int, peca:string, codigo:?string, lancada:int, entregue:int, situacao:string, situacao_rotulo:string, fornecedor:?string, prazo:?string, pedido_id:?int, atrasada:bool, saldo_livre:int, pode_entregar:bool, pode_devolver:bool}> */
public function linhas(ServiceOrder $os, bool $comFornecedor): array
```
Situação (chave → rótulo em "Textos"): `a_precificar` (sem `precificada_em`), `precificada`, `a_comprar` (origem fornecedor, OS autorizada, sem pedido ativo), `aguardando_fornecedor` / `atrasada` (pedido ativo; vermelho se `prazo_prometido_em` passou), `recebida` (linha do pedido completa, falta entregar), `pronta` (origem estoque e `cobreItem`), `sem_saldo`, `entregue_parcial`, `entregue`. `fornecedor` só quando `$comFornecedor` (usuário com `estoque_operacao`).

**`EstoquePainel`** (interface com a **F10**; cada método devolve **uma seção** no formato de `RotinaService::secao()`: `['titulo','vazio','itens' => [...], 'checklists' => []]`; cada item: `os`, `placa`, `texto`, `decorrido`, `limite`, `cor` e **novo campo opcional `url`**):

```php
public function pedidosAReceber(): array     // 'Pedidos de compra a receber'
public function entregasPendentes(): array   // 'Entregas ao técnico pendentes' (título atual, conteúdo estendido): itens Peça com entregue < lançada em OS Em Execução, Veículo em Teste ou Faturamento (hoje só Em Execução; a correção da divergência acontece até o faturamento)
public function nfsALancar(): array          // 'NF de entrada a lançar'
public function inventarioDaSemana(): array  // 'Inventário rotativo semanal' (título atual, conteúdo estendido)
public function pecasParadas(): array        // 'Peças paradas há mais de N dias'
public function abaixoDoMinimo(): Collection // dado para "Compras do dia": peças com (fisico − reservado) <= quantidade_minima (reposição); a seção 'Compras do dia' atual NÃO muda de texto
```

Cor/cronômetro usa `RotinaService::cor($decorrido, $limite)` (público e estático); cada seção limita a 50 linhas e devolve `vazio` quando não há dado (estoque vazio é normal). Regras: NF a lançar → `decorrido` = minutos desde `recebida_em`, `limite` = `EstoquePrazos::nfLimiteMinutos()`, `url` = `route('admin.estoque.nf.show', id)`; pedido a receber → `decorrido` desde `comprado_em`, `limite` até `prazo_prometido_em` (vermelho se passou); inventário → "Inventário da semana 2026-47: 3 de 15 itens contados" com cor pelo prazo (`inventario_dia_limite`), `url` = `admin.estoque.inventario.index`; peças paradas → uma linha-resumo (`N itens parados há mais de 180 dias`; o valor só se `ValoresFinanceiros::can()`).

**`EstoquePrazos`**:

```php
public static function nfLimiteMinutos(): int      // round(nf_entrada_prazo_horas × 60 ÷ divisorDeTeste())
public static function divisorDeTeste(): int       // PONTO ÚNICO de ligação com o "modo de teste ÷10" da F03; hoje: max(1, (int) WorkshopSettings::get('modo_teste_divisor', 1)) — trocar pela chave/ajudante que a F03 criar
public static function semanaChave(?Carbon $d = null): string   // ($d ?? now())->format('o-W')
public static function limiteDoInventario(?Carbon $d = null): Carbon // fim do expediente do dia ISO 'inventario_dia_limite' da semana de $d (BusinessHours::endOfBusinessDay)
```

#### M.1.3 Middleware, controladores e rotas

- `SO/Http/Middleware/EstoqueMigrado.php`: se `! EstoqueSchema::ativo()` devolve a view `service_order::estoque.sem_migration` (status 503, mensagem `estoque.erros.sem_migration`, sem redirecionar — um redirecionamento perderia o aviso e poderia cair no `EntradaPorCargo`). Evita erro 500 em banco sem migration.
- Controladores em `SO/Http/Controllers/` (todos `extends Webkul\Admin\Http\Controllers\Controller`, mensagens via `session()->flash('success'|'error')`, `ValidationException` capturada como em `WorkflowController::run`):

| Controlador | Métodos → rota |
|---|---|
| `EstoqueFornecedorController` | `index`, `store`, `update` |
| `EstoqueCompraController` | `index`, `create`, `store`, `show`, `cancel` |
| `EstoqueNfController` | `index`, `create`, `receive`, `store`, `show`, `post`, `reverse` |
| `EstoqueInventarioController` | `index`, `show`, `generate`, `count`, `close`, `ajustes`, `storeAjuste` |
| `EstoqueRegrasController` | `parados`, `config`, `updateConfig`, `buscarPecas` (JSON) |
| `OsPecasController` | `entregar`, `devolver`, `reduzir` (rotas por OS; usa a mesma visibilidade de `WorkflowController::order()`, `:266-282`) |

Regras de validação (mensagens em pt-BR no `lang`): ver M.3. Quando o formulário vem do cartão da OS (campo oculto `service_order_id`), `EstoqueCompraController@store` volta para `admin.service_orders.view`; sem OS (reposição), vai para `admin.estoque.compras.show`. As telas usam `session()->flash('success'|'error')` e capturam `ValidationException` como `WorkflowController::run` (`:288-299`).

#### M.1.4 Views (`SO/Resources/views/estoque/…` — Blade + Tailwind + JS simples, como `ficha/edit.blade.php` e `mentoria/registros.blade.php`; nada de Vue)

| Arquivo | Conteúdo |
|---|---|
| `estoque/partials/abas.blade.php` | Barra de abas no padrão de `financeiro/_abas.blade.php`: Peças (`admin.parts.index`, chave `parts`), Pedidos de compra, NF de entrada, Fornecedores, Inventário da semana, Ajustes de saldo, Peças paradas, Regras do estoque (cada uma só se `bouncer()->hasPermission(chave)`); contadores: NF a lançar, ajustes pendentes |
| `estoque/partials/part_view_actions.blade.php` | Usada pela tela da peça (PartInventory). Piloto desligado/módulo inativo → inclui o formulário antigo (`partinventory::partials.legacy-movement-form`). Ligado → (a) "Pedir ajuste de saldo" (linha de estoque, saldo real contado, motivo; só para quem tem `estoque_inventario`), (b) botão "Lançar NF de entrada" (só com `estoque_operacao`), (c) "Saldo de abertura" só se a linha tem saldo 0, nenhum movimento e o usuário tem `parts.create`, (d) texto "Entradas só por NF de entrada; saídas só pela OS" |
| `estoque/compras/index`, `create`, `show` | Lista (filtros: em aberto, atrasados, recebidos, cancelados; coluna "Prazo" vermelha se passou); formulário (OS opcional `?os=`, peças da OS que faltam comprar com quantidade e custo previsto, fornecedor com "cadastrar novo", prazo prometido data+hora com sugestão `compra_prazo_sugerido_dias`); detalhe com linhas, recebimentos e botão "Cancelar pedido" (motivo) |
| `estoque/fornecedores/index` | Lista + formulário de cadastro/edição (sem excluir; "Desativar") |
| `estoque/nf/index`, `create`, `show` | Abas "A lançar / Lançadas / Estornadas"; "Registrar recebimento" (rápido, sem valores); "Lançar NF" com linhas dinâmicas (busca por código/nome via `admin.estoque.pecas.buscar`, quantidade, custo; "puxar do pedido de compra"); detalhe com movimentos gerados, "Pedir estorno" |
| `estoque/inventario/index`, `show` | Semana atual (gera a lista), contagem às cegas (um campo por item, mostra o local `warehouse_location_id` quando houver), depois de salvar mostra saldo do sistema e divergência; "Recontar"; "Fechar o inventário"; histórico de semanas com acuracidade |
| `estoque/ajustes/index` | Pedidos de ajuste: status, quem pediu, quem decidiu, link para o pedido na fila de alçadas |
| `estoque/parados` | Lista + totais (itens e % do estoque; valor só com permissão); filtros de dias e grupo |
| `estoque/config` | Formulário das 8 chaves de `WorkshopSettings` + linha "Módulo de estoque: ATIVO / aguardando atualização do banco" (`EstoqueSchema::ativo()`) |
| `estoque/sem_migration.blade.php` | Página 503 simples com `estoque.erros.sem_migration` (usada por `EstoqueMigrado`) |
| `partials/pecas_estoque.blade.php` | Cartão "Peças e compras desta OS" (ver abaixo) |

*Cartão da OS* (`service_order::partials.pecas_estoque`): incluído em `SO/Resources/views/view.blade.php` logo depois do bloco `@if ($pilot) @include('service_order::partials.workflow' …)` (`:324-326`) com `@if ($pilot) @includeIf('service_order::partials.pecas_estoque', ['serviceOrder' => $serviceOrder]) @endif`. Só renderiza se `EstoqueSchema::ativo()` e a OS tem peças. Tabela com `PecasOsStatus::linhas` (peça, lançada, entregue, situação, fornecedor/prazo para quem tem `estoque_operacao`), faixa vermelha de divergência (`PecasConferencia::pendencias`) e, **somente com `estoque_operacao`**, os formulários: "Emitir pedido de compra" (posta em `admin.estoque.compras.store` com `service_order_id`), "Entregar ao técnico" (`admin.service_orders.pecas.entregar`), "Devolver ao estoque", "Reduzir a quantidade lançada". **Não exibe nenhum valor** (o técnico abre a OS e vê o cartão sem R$; ver `tests/Feature/Workshop/TecnicoValoresTest.php:50-68` — não usar as palavras "Valor unitário", "Descontos", "Boleto", "Cor de crédito").

---

### M.2 Arquivos a ALTERAR

#### M.2.1 Pacote `PartInventory`

| Arquivo | Mudança |
|---|---|
| `PI/Services/PartInventoryService.php` | (1) `protected static bool $movendo = false;` + `public static function isMoving(): bool`. (2) `applyMovement(PartInventory $partInventory, string $type, int $quantity, ?string $reason = null, ?int $referenceId = null, ?int $userId = null, array $contexto = []): PartStockMovement`: dentro da transação, **trava a linha** (`PartInventory::whereKey($id)->lockForUpdate()->firstOrFail()`), calcula o delta, dispara `Event::dispatch('part.stock.movement.before', [$linhaTravada, $type, $delta, $referenceId, $contexto])`, confere saldo negativo **na linha travada**, liga `$movendo` só em volta do `increment`, atualiza o objeto do chamador (`refresh`) e cria o movimento com as colunas extras (`origem`, `os_item_id`, `nf_entrada_id`, `estoque_ajuste_id`, `custo_unitario`, `saldo_apos`) **filtradas por `PartStockMovement::colunasExtras()`**. Chamadas antigas (sem `$contexto`) continuam válidas. (3) `consumeAllocation(…, array $contexto = [])`: mesma trava, o mesmo evento `before` e o mesmo `$movendo` em volta dos dois `decrement` (sem isso a guarda de modelo barraria o caminho antigo); **sem** nova checagem de negativo quando o piloto está desligado (quem confere é a política). (4) Novo `abrirSaldo(PartInventory $pi, int $quantidade, ?float $custoUnitario = null, ?int $userId = null): ?PartStockMovement` (devolve `null` se `quantidade <= 0`; grava `entrada` com `origem = abertura`, `reason = 'Saldo de abertura'`, e atualiza `parts.custo_medio/ultimo_custo` se vier custo). |
| `PI/Models/PartStockMovement.php` | `$fillable` + colunas extras; constantes `ORIGEM_NF_ENTRADA`, `ORIGEM_ABERTURA`, `ORIGEM_OS`, `ORIGEM_DEVOLUCAO_OS`, `ORIGEM_AJUSTE`, `ORIGEM_INVENTARIO`, `ORIGEM_ESTORNO_NF`; `public static function colunasExtras(): array` (interseção com as colunas que existem, memória por banco); `booted()`: `updating` e `deleting` lançam `LogicException('Movimentos de estoque não podem ser alterados nem apagados; registre um novo movimento com justificativa.')` (sempre, igual a `MentoriaRegistro`). |
| `PI/Models/Part.php` | **Não** adicionar `custo_medio`/`ultimo_custo` ao `$fillable` (o `PartController::update` faz mass assignment com todo o request; um usuário com `parts.edit` adulteraria o custo). Atualizar sempre com `forceFill`. |
| `PI/Http/Controllers/PartController.php` | `store` (`:79-86`): criar a linha com `in_stock => 0, allocated => 0` e, se `request('in_stock') > 0`, chamar `$this->partInventoryService->abrirSaldo($linha, (int) request('in_stock'), null, auth()->guard('user')->id())` (grava o saldo **no livro** com o piloto ligado ou desligado). `destroy` (`:158-177`): capturar `ValidationException` antes do `\Exception` genérico e devolver `response()->json(['message' => primeiro erro], 400)`. `storeMovement` (`:182-209`) não muda (a política recusa e o `catch` existente exibe a mensagem). |
| `PI/Resources/views/view.blade.php` | Trocar `:90-119` por: `@if ($part->inventories->isNotEmpty())` → `@if (view()->exists('service_order::estoque.partials.part_view_actions')) @include(...) @else @include('partinventory::partials.legacy-movement-form', ['part' => $part]) @endif`. Traduzir os cabeçalhos em inglês da própria tela ("In Stock/Allocated/On Hand" → "Em estoque/Reservado/Disponível"; "Type/Qty/Reason/Date" → "Tipo/Qtd/Motivo/Data"; `ucfirst($movement->type)` → rótulo: Entrada/Saída/Ajuste) e mostrar "Custo médio" e "Última entrada" só se `ValoresFinanceiros::can()` (usar `class_exists`). |
| `PI/Resources/views/partials/legacy-movement-form.blade.php` (**novo**) | O formulário atual (`view.blade.php:90-119`) extraído **sem alterar o comportamento**. |
| `PI/Resources/views/index.blade.php` | Depois do cabeçalho: `@includeIf('service_order::estoque.partials.abas')`. |
| `PI/Resources/views/create.blade.php` (`:214-228`) | Texto de apoio sob "Quantidade Inicial": com o piloto ligado, "Será registrado como saldo de abertura (movimento no livro)". |

#### M.2.2 Pacote `ServiceOrder` — registro e infraestrutura

| Arquivo | Mudança |
|---|---|
| `SO/Providers/ServiceOrderServiceProvider.php` (`boot`, depois de `ServiceOrder::observe(...)`, `:47`) | `Event::listen('part.stock.movement.before', [EstoquePolitica::class, 'aoMovimentar']);` `Event::listen('workshop.alcada.decidida', [EstoqueAjusteService::class, 'aoDecidirAlcada']);` `PartInventory::updating([EstoquePolitica::class, 'aoAtualizarSaldo']); PartInventory::creating([EstoquePolitica::class, 'aoCriarSaldo']); Part::deleting([EstoquePolitica::class, 'aoExcluirPeca']); Warehouse::deleting([EstoquePolitica::class, 'aoExcluirDeposito']); ServiceOrder::deleting([EstoquePolitica::class, 'aoExcluirOs']);` (usar os modelos concretos de `Webkul\PartInventory\Models`, `Webkul\Warehouse\Models` e `Webkul\ServiceOrder\Models`). |
| `SO/Routes/web.php` | Grupo `Route::middleware(EstoqueMigrado::class)->prefix('estoque')` e o grupo `service-orders/{id}/pecas` (rotas em "ACL, menu, rotas"). Colocar **depois** do grupo `service-orders` para não conflitar com `{id}`. |
| `SO/Config/acl.php` | 3 chaves novas (ver seção ACL). |
| `SO/Services/WorkshopSettings.php` (`DEFAULTS`, junto de `garantia_pecas_dias`, `:71-72`) | 8 chaves novas (ver seção ACL/configurações). |
| `SO/Models/ServiceOrderEvent.php` (`TIPOS`, `:26-96`) | `pedido_compra_emitido` => 'Pedido de compra emitido'; `pedido_compra_cancelado` => 'Pedido de compra cancelado'; `peca_recebida` => 'Peça recebida do fornecedor'; `peca_devolvida` => 'Peça devolvida ao estoque'. Nenhum leva valores; **não** entram em `$eventosRestritos` de `partials/workflow.blade.php:20` (o técnico pode ver a linha do tempo da peça). |
| `SO/Models/ServiceOrderItem.php` (`:51-57`) | Casts: `recebida_em => datetime`, `quantidade_entregue => integer`; helper `entregueEfetiva(): int` que chama `EstoqueSaldo::entregue($this)`. |
| `SO/Models/BarrierRule.php` (`DEFAULTS`, depois de `compra_peca`, `:179-194`) | Nova barreira (ver "Barreira `entrega_peca`"). |
| `SO/Services/BarrierConditions.php` (`passes`, `:16-32`) | Nova condição `'pedido_entregue' => $this->pedidoEntregue($serviceOrder, $event)`: verdadeiro se o evento é `pedido_compra_cancelado`, se não há `pedido_compra_id`/tabela, ou se **todas** as linhas do pedido ligadas a item da OS têm `EstoqueSaldo::entregue(item) >= quantity`. |
| `SO/Services/Alcada/AlcadaCatalog.php` (`:36`) | `ajuste_estoque`: `automatico` passa a `true` (a matriz mostra "bloqueia automaticamente"). |
| `SO/Services/Alcada/AlcadaService.php` (`decide`, `:228-276`) | Envolver o corpo em `DB::transaction` e, logo depois do `$pedido->update([...])` (`:247-253`), `Event::dispatch('workshop.alcada.decidida', [$pedido, $aprovar, $user]);`. Mensagem ao solicitante (`:265-272`): para `ajuste_estoque` usar "aprovado. O saldo do estoque já foi ajustado." em vez de "Repita a ação." **Arquivo compartilhado** — o gancho serve também F06/F08/F12/F13. |
| `SO/Services/WorkflowActions.php` | `buy` (`:417-442`): se `PedidoCompraService::disponivel()` → `return app(PedidoCompraService::class)->emitirParaItens($os, [$item->id], ['fornecedor_nome' => …, 'prazo_data' => …])` e mensagem 'Compra registrada.' (a alçada passa a viver no serviço; o teste `AlcadasTest.php:314-337` continua válido); senão código atual. `deliverPart` (`:444-461`): se piloto ligado e módulo ativo → `EstoqueBaixaService::entregarAoTecnico($os, $item)`; senão código atual. `checkParts` (`:485-495`): com piloto ligado e módulo ativo, `divergencia = nao` + `PecasConferencia::temDivergencia($os)` → `ValidationException(['divergencia' => ['Há divergência entre as peças lançadas e as entregues ao técnico: …']])`; o evento passa a gravar `divergencia => declarada || calculada` e `itens_divergentes`. |
| `SO/Resources/views/partials/workflow_actions.blade.php` (`:20`) | No início do `@foreach`: `@continue(in_array($chave, ['comprar_peca', 'entregar_peca'], true) && \Webkul\ServiceOrder\Services\Estoque\EstoqueSchema::ativo())` — a tela passa a usar o cartão da OS; **as ações continuam existindo** para compatibilidade e testes. |
| `SO/Observers/ServiceOrderObserver.php` (`updating`, `:123-148`) | Ramo "Concluída": se `EstoquePolitica::ligada()` → para cada item `Peça` **libera** `EstoqueSaldo::reservaDoItem($item)` (não consome; a baixa já aconteceu na entrega); senão o consumo atual, **mas só de `quantity − EstoqueSaldo::entregue($item)`** (evita baixa em dobro se o piloto foi desligado depois de entregas). Ramo "Cancelada": se ligada e há item com `entregue > 0` → `ValidationException` (`erros.os_cancelar_com_pecas`); senão libera só `reservaDoItem`. |
| `SO/Services/ServiceOrderItemService.php` (`removeItem`, `:84-99`) | Com `EstoquePolitica::ligada()`: se `entregue > 0` → `ValidationException(erros.peca_entregue_nao_remove)`; se a linha está em pedido de compra ativo → `erros.peca_em_pedido`. Libera só `reservaDoItem`. |
| `SO/Http/Controllers/ServiceOrderController.php` (`destroyItem`, `:349-358`) | Capturar `ValidationException` e `session()->flash('error', primeiro erro)` (padrão de `updateStatus`, `:283-289`). |
| `SO/Services/WorkflowService.php` (`hasStock`, `:312-325`) | **Compartilhado com a F10**: trocar o corpo por `return EstoqueSaldo::cobreItem($item);` (corrige o caso "peça com cadastro mas sem linha de estoque conta como em estoque"). Quem mexer primeiro faz; o outro só confere. |
| `SO/Http/Middleware/RegistraAcessoSensivel.php` (`:17-19`) | **Opcional**: acrescentar `'admin.estoque.nf.'`, `'admin.estoque.compras.'`, `'admin.estoque.ajustes'`, `'admin.estoque.parados'` aos prefixos (custos e valores de estoque são dado financeiro). |

#### M.2.3 `ServiceOrder` — indicadores, painel, alertas, importação

| Arquivo | Mudança |
|---|---|
| `SO/Services/Indicators/IndicatorCalculator.php` | `'PEC-07'` (`:142`) → `$this->nfEntradaNoPrazo()` (novo, abaixo). `pecasParadas()` (`:834-854`) → corpo vira `$r = app(EstoqueParadoService::class)->resumo(); return $r['valor_estoque'] > 0 ? IndicatorResult::ratio($r['valor_parado'], $r['valor_estoque']) : IndicatorResult::none();` (sem a lista fixa de 180). Demais PEC/TEC/DRE: **sem mudança de cálculo** (tabela de indicadores abaixo). |
| `SO/Services/Indicators/PainelDonoService.php` | `valorDoEstoque()` (`:305-311`) → `app(EstoqueParadoService::class)->valorDoEstoque()` (custo médio com queda para preço de venda); `build()['pecas']` ganha `'paradas' => resumo()` (quantidade e valor; o painel é do dono/gerente). `BLOCO_PECAS` (`:39`) não muda. |
| `SO/Services/Rotina/RotinaService.php` (`dePecas`, `:170-198`) | **Compartilhado com a F10.** Com `EstoqueSchema::ativo()`, a lista devolvida passa a ser, nesta ordem: 'Pedidos a precificar' (como hoje/F10), 'Compras do dia' (**mesmo texto de hoje**, `RotinaTest.php:105-106`), `EstoquePainel::pedidosAReceber()`, `EstoquePainel::entregasPendentes()`, `EstoquePainel::nfsALancar()`, `EstoquePainel::inventarioDaSemana()`, `EstoquePainel::pecasParadas()`. Sem o módulo, o código atual. |
| `SO/Resources/views/rotina/painel.blade.php` (`:30-38`) | Se `$item['url']` existir, renderizar o texto como link. (Compartilhado com a F10.) |
| `SO/Services/PeriodicAlerts.php` (`run`, `:55-64`) | Somar `nfsEmAtraso($now)` e `inventarioEmAtraso($now)` (ver "Alertas"); ambos retornam 0 sem `EstoqueSchema::ativo()`. `InventarioService::garantirDaSemana()` também roda aqui (a lista da semana nasce sozinha, uma vez). |
| `SO/Http/Controllers/IndicatorController.php` (`storeRegistro`, `:91-132`) | Com `EstoquePolitica::ligada()`, recusar os tipos `nf_entrada` e `inventario_rotativo` ("Este registro agora é feito pelo módulo de Estoque") — evita contar duas vezes. A importação de planilha histórica (`HistoricoImportService`) **não** passa por aqui e continua aceitando. |
| `SO/Services/SpreadsheetImportService.php` (`importPartRow`, `:357-370`) | Trocar `PartInventory::create([… 'in_stock' => $quantidade …])` por criar com `0` e `PartInventoryService::abrirSaldo(...)` (quando `$quantidade > 0`). |

#### M.2.4 Etapa 2 (opcional, recomendada depois do piloto): conta a pagar da NF

`financeiro_lancamentos.nf_entrada_id` (migration própria); na tela de lançar a NF, caixa "Gerar conta a pagar" (valor total, vencimento) que chama `FinanceiroService::criar([... 'pagamento_pecas' => true, 'parceiro' => fornecedor …])`; em `FinanceiroService::pagar` (`:73-102`), se o lançamento tem `nf_entrada_id` de NF `lancada`, `nf_entrada_conferida` passa a `true` automaticamente (FIN-07). Padrão **desligado** (chave `nf_gera_conta_a_pagar`, não listada acima para não inflar a primeira entrega).

---

### M.3 Detalhes de regras, validações e barreira

**Barreira `entrega_peca`** (21ª regra padrão; auto-semeada pelo `ensureDefaults()`; "Restaurar barreiras" também a recria):

```php
[
    'codigo' => 'entrega_peca',
    'nome' => 'Entrega da peça no prazo do fornecedor',
    'etapa' => 'Em Execução',
    'evento_inicio' => 'pedido_compra_emitido',
    'evento_fim' => 'peca_entregue_tecnico,pedido_compra_cancelado',
    'modo' => self::MODO_DATA_ALVO,          // vence em dados_json['prazo_em'] (BarrierEngine::dueFromTarget); prazo_minutos fica nulo = sem antecedência
    'nivel1_destino' => 'pecas',             // no prazo prometido
    'nivel2_apos_minutos' => 240,            // +4 h úteis (conta_horario_comercial = true, padrão)
    'nivel2_destino' => 'chefe',
    'condicao_fim' => 'pedido_entregue',
    'critico' => false,
],
```
- Instância por pedido: `referencia_id` = id do pedido (o evento `pedido_compra_emitido` e os de fim levam `referencia_id`). Os eventos `compra_peca` **não** levam `referencia_id`, para continuar fechando a barreira `compra_peca` (que abre com `referencia_id = 0`).
- Entrega de peça de estoque (sem pedido) não tem `referencia_id` e não mexe nessa barreira.
- Alerta sem valores: o texto é montado por `AlertService::mensagem` (nome da barreira, OS, placa).
- Modo de teste ÷10 (F03): `nivel2_apos_minutos` é escalado pelo motor; o prazo prometido é data absoluta (no teste, o testador informa um horário próximo).
- **Teste existente que muda**: `tests/Feature/Workshop/AllBarriersTest.php:59-62` (`BarrierRule::count()` = 20) → passa a contar as 20 anteriores + as novas de todas as frentes; reescrever como "contém as 20 da mentoria + `entrega_peca`".

**Validações (todas com mensagens em pt-BR no `lang`)**

| Rota | Regras |
|---|---|
| `fornecedores.store/update` | `nome` required\|string\|max:160; `documento` nullable\|string\|max:18 (11 ou 14 dígitos; usar o validador de CPF/CNPJ da F07 se existir); `telefone` nullable\|string\|max:30; `email` nullable\|email\|max:160; `contato` nullable\|string\|max:120; `prazo_padrao_dias` nullable\|integer\|min:0\|max:365; `observacao` nullable\|string\|max:500; `ativo` nullable\|boolean |
| `compras.store` | `service_order_id` nullable\|integer\|exists:quotes,id; `fornecedor_id` nullable\|integer\|exists:fornecedores,id; `fornecedor_nome` required_without:fornecedor_id\|nullable\|string\|max:160; `prazo_data` required\|date\|after_or_equal:today; `prazo_hora` nullable\|date_format:H:i; `itens` required\|array\|min:1; `itens.*.quote_item_id` nullable\|integer; `itens.*.part_id` nullable\|integer\|exists:parts,id; `itens.*.descricao` nullable\|string\|max:255; `itens.*.quantidade` required\|integer\|min:1\|max:9999; `itens.*.custo_unitario` nullable\|string (valor lido com `BrNumber::parse`); `observacao` nullable\|string\|max:500. Custo previsto só é aceito de quem tem `ValoresFinanceiros::can()`; para os demais usa-se `quote_items.custo_unitario`. |
| `compras.cancel` | `motivo` required\|string\|min:5\|max:255 |
| `nf.receive` | `fornecedor_id`/`fornecedor_nome` como acima; `numero` required\|string\|max:20; `serie` nullable\|string\|max:5; `chave_acesso` nullable\|digits:44; `emitida_em` nullable\|date\|before_or_equal:today; `recebida_em` nullable\|date\|before_or_equal:now (padrão: agora); `pedido_compra_id` nullable\|exists:pedidos_compra,id; `warehouse_id` nullable\|exists:warehouses,id; `observacao` nullable\|string\|max:500 |
| `nf.store` / `nf.post` | (`store` = regras de `receive` +) `itens` required\|array\|min:1; `itens.*.part_id` required\|exists:parts,id; `itens.*.quantidade` required\|integer\|min:1\|max:99999; `itens.*.custo_unitario` required (maior que zero); `itens.*.pedido_compra_item_id` nullable\|exists:pedido_compra_itens,id; `total_nota` nullable\|string. O controller chama `ValoresFinanceiros::authorize('NF de entrada')` (403 sem a permissão). |
| `nf.reverse` | `motivo` required\|string\|min:5\|max:255 |
| `inventario.count` | `quantidade` required\|integer\|min:0\|max:999999; `observacao` nullable\|string\|max:255 |
| `ajustes.store` | `part_inventory_id` required\|exists:part_inventories,id; `saldo_contado` required\|integer\|min:0; `motivo` required\|string\|min:10\|max:255 |
| `config.update` | `estoque_parado_dias` required\|integer\|min:30\|max:1825; `nf_entrada_prazo_horas` required\|integer\|min:1\|max:720; `inventario_regra` required\|in:rodizio,valor,giro; `inventario_itens_semana` required\|integer\|min:1\|max:200; `inventario_dia_limite` required\|integer\|between:1,7; `inventario_ciclo_dias` required\|integer\|min:1\|max:365; `compra_prazo_sugerido_dias` required\|integer\|min:0\|max:60; `estoque_deposito_padrao` nullable\|exists:warehouses,id |
| `pecas.entregar` | `item_id` required\|integer; `quantidade` nullable\|integer\|min:1; `mechanic_id` nullable\|exists:mechanics,id; `part_inventory_id` nullable\|exists:part_inventories,id |
| `pecas.devolver` | `item_id` required\|integer; `quantidade` required\|integer\|min:1; `causa` required\|in:nao_usada,peca_errada,defeito,outra; `motivo` required\|string\|min:5\|max:255 |
| `pecas.reduzir` | `item_id` required\|integer; `nova_quantidade` required\|integer\|min:1; `motivo` required\|string\|min:5\|max:255 |

**Alertas novos (sem valores em R$; sem aviso operacional ao dono)** — em `PeriodicAlerts`, via `AlertService::raise`, `unicoPorDia: true`:

| Código | Quando | Destino | Texto |
|---|---|---|---|
| `nf_entrada_atrasada` | NF `recebida` há mais de `nfLimiteMinutos()` | `pecas` | "ALERTA - NF de entrada nº {numero} ({fornecedor}) recebida em {dd/mm HH:mm} ainda não foi lançada." |
| `nf_entrada_atrasada_gerente` | passou de 2× o prazo | `gerente` | mesmo texto, título "NF de entrada sem lançar" |
| `inventario_atrasado` | depois do fim do expediente do `inventario_dia_limite` com itens por contar | `pecas` | "ALERTA - O inventário rotativo da semana {semana} não foi concluído: faltam {n} item(ns) para contar." |
| `inventario_atrasado_gerente` | no dia útil seguinte | `gerente` | idem, título "Inventário da semana atrasado" |

**Tabela de indicadores**

| Indicador | Fonte hoje | O que a F11 faz |
|---|---|---|
| PEC-01 | barreira `precificacao_peca` | nada (F10) |
| PEC-02 | registros `peca_errada` | `devolverAoEstoque(causa = peca_errada)` grava o registro com `service_order_id` |
| PEC-03 | eventos `orcamento_corrigido` | nada; `reduzirLancada` grava `orcamento_corrigido` (quem = pecas) |
| PEC-04 | barreira `compra_peca` | conta não muda; o pedido de compra mantém o evento `compra_peca` e a condição `todas_compradas` |
| PEC-05 | `entregaNoPrazo()` por eventos (`IndicatorCalculator.php:756-772`) | conta não muda; o evento `peca_entregue_tecnico` continua com `prazo_entrega_em` (agora vindo do pedido) |
| PEC-06 | `conferenciaDePecas()` por evento `pecas_conferidas` | conta não muda; a flag `divergencia` passa a ser calculada pelo sistema |
| PEC-07 | registros manuais `nf_entrada` | `nfEntradaNoPrazo()`: das `nfs_entrada` com `recebida_em` no período — lançada em ≤ `nfLimiteMinutos()` conta como certa; **pendente e já vencida conta como errada**; pendente ainda no prazo fica fora; soma os registros manuais antigos/planilha. Limite deixa de ser `24 * 60` fixo (`:142`). |
| PEC-08 | registros `garantia_fornecedor` | nada (continua manual) |
| PEC-09 / PEC-10 | registros `inventario_rotativo` | gravados no fechamento do inventário (`contados`, `corretos`) |
| PEC-11 | `pecasParadas()` | passa a usar `EstoqueParadoService` (dias da configuração, custo médio, ajuste não zera o relógio) |
| PEC-12 | `margemDePecas()` | conta não muda; a entrega preenche `custo_unitario` vazio com o custo médio |
| PEC-13 | registros `auditoria_estoque` | nada |
| TEC-05 | barreira `relacao_pecas` | nada (F10) |
| TEC-06 | `relacaoCorreta()` | nada; `reduzirLancada` conta como correção de Peças |
| DRE-07 | `pecasAbaixoDoMinimo()` | nada (F10); ajustes de estoque **não** entram aqui |

Esboço de `nfEntradaNoPrazo()` (PEC-07) em `IndicatorCalculator`:

```php
protected function nfEntradaNoPrazo(): IndicatorResult
{
    $limite = EstoquePrazos::nfLimiteMinutos();
    $certas = 0;
    $total = 0;

    if (EstoqueSchema::tabela('nfs_entrada')) {
        foreach (NfEntrada::whereBetween('recebida_em', [$this->from, $this->to])->get() as $nf) {
            $vence = $nf->recebida_em->copy()->addMinutes($limite);

            if ($nf->lancada_em) {          // lançada: conta certa se foi dentro do prazo
                $total++;
                $certas += $nf->lancada_em->lte($vence) ? 1 : 0;
            } elseif ($vence->lt(now())) {  // pendente e já vencida: conta como errada
                $total++;
            }                               // pendente ainda no prazo: fora da conta
        }
    }

    // Registros manuais antigos / importados de planilha continuam somando.
    $legado = $this->prazoEntreDatas('nf_entrada', 'recebida_em', 'lancada_em', $limite);
    $certas += (int) ($legado->numerador ?? 0);
    $total += (int) ($legado->denominador ?? 0);

    return $total > 0 ? IndicatorResult::ratio($certas, $total) : IndicatorResult::none();
}
```

---

### M.4 Interface com a F10 (nomes combinados) e com a F09

**F10 (Painel e precificação) — o que a F11 oferece:**

| Contrato | Nome |
|---|---|
| Seções do painel | `Webkul\ServiceOrder\Services\Estoque\EstoquePainel::{pedidosAReceber, entregasPendentes, nfsALancar, inventarioDaSemana, pecasParadas}()` — formato de `RotinaService::secao()` + `url` opcional |
| Cobertura de estoque do item (define 20 min × 40 min) | `EstoqueSaldo::cobreItem(ServiceOrderItem): bool` (substitui `WorkflowService::hasStock`) |
| Tabelas/colunas | `quote_items.quantidade_entregue`, `entregue_por`, `entregue_a_mechanic_id`, `recebida_em`; `pedidos_compra`, `pedido_compra_itens`, `fornecedores` |
| Eventos | `pedido_compra_emitido`, `pedido_compra_cancelado`, `peca_recebida`, `peca_devolvida`; já existentes `compra_peca`, `peca_entregue_tecnico` |
| Rotas | `admin.estoque.compras.create` (aceita `?os={id}`), `admin.estoque.nf.create`, `admin.estoque.inventario.index` |

**F10 — o que a F11 espera:** `precificada_em`, `origem_preco`, `custo_unitario`, `price` do item como já existem; a F10 **não** deve reescrever as seções 'Compras do dia' (texto `'{peça} (comprar hoje)'`), 'Entregas ao técnico pendentes' e 'Inventário rotativo semanal' sem manter a chamada a `EstoquePainel`. Se a F10 usar nomes diferentes para a tela do Painel, quem adapta é o **consumidor** (F10); os nomes acima são da F11.

**F09 (nota/saída) — o que a F11 oferece:** `PecasConferencia::pendencias(ServiceOrder): string[]` — a F09 acrescenta essas frases à lista de `StageGate::faturamentoParaSaida` (e ao botão "Registrar saída") quando `pecas_conferidas_em` está vazio **ou** a última conferência declarou divergência **ou** `temDivergencia()` é verdadeiro. Texto: `Peça "{nome}": lançada {n} e entregue ao técnico {m}`.

---

## Banco (migrations)

Regras: só aditivas, idempotentes (`Schema::hasTable` / `Schema::hasColumn` antes de criar), nada de `UPDATE`/`DELETE` em dados existentes, `down()` só remove o que o próprio `up()` criou (e só se existir). **Nenhum backfill:** o carimbo antigo `entregue_tecnico_em` sem baixa é tratado em código (`EstoqueSaldo::entregue` considera "entregue" a linha cujo carimbo existe e `quantidade_entregue = 0`). Horários escolhidos num bloco próprio (15:01 a 15:05); conferir a pasta antes de criar (ainda não há `2026_11_18_*` no repositório).

| # | Arquivo | Pacote | O que cria |
|---|---|---|---|
| 1 | `packages/Webkul/PartInventory/src/Database/Migrations/2026_11_18_150100_add_custo_e_origem_to_part_tables.php` | PartInventory | `parts`: `custo_medio` decimal(12,4) null, `ultimo_custo` decimal(12,4) null, `ultima_entrada_em` datetime null. `part_stock_movements`: `origem` string(20) null (index), `os_item_id` unsignedInteger null (index), `nf_entrada_id` unsignedInteger null, `estoque_ajuste_id` unsignedInteger null, `custo_unitario` decimal(12,4) null, `saldo_apos` integer null; índice composto (`part_inventory_id`, `type`, `created_at`) nomeado `psm_inv_type_date` para a lista de peças paradas |
| 2 | `packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_150200_create_estoque_compras_tables.php` | ServiceOrder | `fornecedores`, `pedidos_compra`, `pedido_compra_itens` (colunas abaixo) |
| 3 | `packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_150300_create_estoque_nf_ajustes_inventario_tables.php` | ServiceOrder | `nfs_entrada`, `nf_entrada_itens`, `estoque_ajustes`, `inventarios`, `inventario_itens` |
| 4 | `packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_150400_add_entrega_columns_to_quote_items.php` | ServiceOrder | `quote_items`: `quantidade_entregue` unsignedInteger default 0, `entregue_por` unsignedInteger null, `entregue_a_mechanic_id` unsignedInteger null, `recebida_em` datetime null |
| 5 (Etapa 2, opcional) | `…/2026_11_18_150500_add_nf_entrada_id_to_financeiro_lancamentos.php` | ServiceOrder | `financeiro_lancamentos.nf_entrada_id` unsignedInteger null (index) |

Sem chaves estrangeiras nas tabelas novas (padrão das tabelas da mentoria, ex.: `alcada_pedidos`), só índices — assim a ordem de migração entre pacotes e bancos de oficina não importa.

### Colunas

**`fornecedores`**: `id`; `nome` string(160); `documento` string(18) null **unique** (só dígitos); `telefone` string(30) null; `email` string(160) null; `contato` string(120) null; `prazo_padrao_dias` unsignedSmallInteger null; `observacao` string(500) null; `ativo` boolean default true; timestamps; index em `nome`.

**`pedidos_compra`**: `id`; `service_order_id` unsignedInteger null (index; nulo = reposição de estoque); `fornecedor_id` unsignedInteger null (index); `fornecedor_nome` string(160) null (retrato do nome na compra); `status` string(12) default `aberto` (index); `comprado_em` dateTime (carimbo de compra); `comprado_por` unsignedInteger null; `prazo_prometido_em` dateTime null; `observacao` string(500) null; `recebido_em` dateTime null; `cancelado_em` dateTime null; `cancelado_por` unsignedInteger null; `cancelamento_motivo` string(255) null; timestamps; index composto (`status`, `prazo_prometido_em`).

**`pedido_compra_itens`**: `id`; `pedido_compra_id` unsignedInteger (index); `quote_item_id` unsignedInteger null (index); `part_id` unsignedInteger null (index); `descricao` string(255); `quantidade` unsignedInteger; `custo_unitario` decimal(12,4) null (previsto); `quantidade_recebida` unsignedInteger default 0; `recebida_em` dateTime null; timestamps.

**`nfs_entrada`**: `id`; `fornecedor_id` unsignedInteger null (index); `fornecedor_nome` string(160) null; `pedido_compra_id` unsignedInteger null (index); `numero` string(20) (index); `serie` string(5) null; `chave_acesso` string(44) null **unique**; `emitida_em` date null; `recebida_em` dateTime; `lancada_em` dateTime null; `status` string(16) default `recebida` (index); `total_nota` decimal(14,2) null; `total_itens` decimal(14,2) null; `warehouse_id` unsignedInteger null; `recebida_por` unsignedInteger null; `lancada_por` unsignedInteger null; `observacao` string(500) null; `estorno_motivo` string(255) null; `estornada_em` dateTime null; `estornada_por` unsignedInteger null; timestamps.

**`nf_entrada_itens`**: `id`; `nf_entrada_id` unsignedInteger (index); `part_id` unsignedInteger (index); `part_inventory_id` unsignedInteger null; `pedido_compra_item_id` unsignedInteger null; `quantidade` unsignedInteger; `custo_unitario` decimal(12,4); `movimento_id` unsignedInteger null; timestamps.

**`estoque_ajustes`**: `id`; `part_inventory_id` unsignedInteger (index); `part_id` unsignedInteger (index); `origem` string(16) (`manual` \| `inventario` \| `estorno_nf`); `saldo_antes` integer; `quantidade` integer (com sinal, ≠ 0); `motivo` string(255); `inventario_item_id` unsignedInteger null; `nf_entrada_id` unsignedInteger null; `alcada_pedido_id` unsignedBigInteger null (index; `alcada_pedidos.id` é `bigIncrements`); `status` string(10) default `pendente` (index); `solicitado_por` unsignedInteger null; `decidido_por` unsignedInteger null; `decidido_em` dateTime null; `movimento_id` unsignedInteger null; timestamps.

**`inventarios`**: `id`; `semana` string(8) **unique** (`2026-47`, formato `o-W`); `inicio` date; `fim` date; `regra` string(12); `status` string(12) default `em_contagem` (index); `itens_previstos` unsignedSmallInteger default 0; `contados` unsignedSmallInteger null; `corretos` unsignedSmallInteger null; `fechado_em` dateTime null; `fechado_por` unsignedInteger null; `criado_por` unsignedInteger null; timestamps.

**`inventario_itens`**: `id`; `inventario_id` unsignedInteger (index); `part_inventory_id` unsignedInteger (index); `part_id` unsignedInteger (index); `corrige_id` unsignedInteger null (index; recontagem); `quantidade_contada` integer null; `saldo_sistema` integer null; `divergencia` integer null; `contado_em` dateTime null; `contado_por` unsignedInteger null; `observacao` string(255) null; `ajuste_id` unsignedInteger null; timestamps.

### Como aplicar nos testes (sem tocar em `krayin_crm`)

```powershell
$env:DB_DATABASE='krayin_test'
php artisan migrate --path=packages/Webkul/PartInventory/src/Database/Migrations/2026_11_18_150100_add_custo_e_origem_to_part_tables.php
php artisan migrate --path=packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_150200_create_estoque_compras_tables.php
php artisan migrate --path=packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_150300_create_estoque_nf_ajustes_inventario_tables.php
php artisan migrate --path=packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_150400_add_entrega_columns_to_quote_items.php
```

### Bancos das oficinas (tenants)

`migrate-safe.php` só migra o banco principal. As 8 tabelas e as colunas acima precisam existir em **cada banco de oficina**; o procedimento é o definido pela **F00**. Até lá, `EstoqueSchema::ativo()` mantém cada oficina no comportamento antigo (sem erro 500) e `EstoqueMigrado` mostra o aviso nas telas novas.

---

## ACL, menu, rotas e configurações

### Chaves de ACL novas (`SO/Config/acl.php`, planas e **sem ponto**)

Importante: rota **sem** chave de ACL fica liberada a qualquer usuário logado (`Admin/Http/Middleware/Bouncer.php:79-86` só confere rotas mapeadas). Por isso **toda** rota nova abaixo aparece em exatamente uma chave.

| Chave | Nome (pt-BR) | Rotas |
|---|---|---|
| `estoque_operacao` | Estoque: pedidos de compra, NF de entrada, entrega de peças e fornecedores | `admin.estoque.fornecedores.index/store/update`, `admin.estoque.compras.index/create/store/show/cancel`, `admin.estoque.nf.index/create/receive/store/show/post/reverse`, `admin.estoque.pecas.buscar`, `admin.estoque.parados`, `admin.service_orders.pecas.entregar/devolver/reduzir` |
| `estoque_inventario` | Estoque: inventário rotativo e pedidos de ajuste de saldo | `admin.estoque.inventario.index/show/generate/count/close`, `admin.estoque.ajustes.index/store` |
| `estoque_config` | Estoque: regras (peças paradas, inventário e prazo da NF) | `admin.estoque.config`, `admin.estoque.config.update` |

Recomendação para a **F05/F04** ligar aos perfis (a F11 só cria as chaves):

| Perfil | `estoque_operacao` | `estoque_inventario` | `estoque_config` | Também precisa |
|---|---|---|---|---|
| Peças | sim | sim | não | `ver_valores_financeiros` (custos), `parts` + `parts.view/create`, `service_orders.view/edit`, `rotina_painel`, `part_categories`, `view_permission = global` |
| Gerente | sim | sim | sim | `alcadas` (aprovar ajuste), `ver_valores_financeiros` |
| Proprietário | sim | sim | sim | `alcadas`, `ver_valores_financeiros` |
| Financeiro | não (confere pela conta a pagar, Etapa 2) | não | não | — |
| Chefe, Consultor, Técnico, Zeladoria, Mentor | **não** | não | não | — (o Técnico vê só o cartão da OS, sem valores) |

Quem recebe "Pedir ajuste de saldo" na tela da peça: `estoque_inventario`. Quem recebe o formulário antigo (piloto desligado): `parts.movements` (como hoje).

### Menu

**Nenhum item novo de menu** (B8 limita o menu do perfil Peças a Painel de Peças, Estoque, Grupos, Minha rotina e Meu bônus). As telas novas se alcançam pela **barra de abas dentro de "Estoque"** (`estoque/partials/abas.blade.php`, incluída em `PI/Resources/views/index.blade.php`) e pelos links do Painel de Peças (`url` das linhas).

### Rotas (`SO/Routes/web.php`, prefixo `/admin`)

| Nome | Método e URI | Ação | Chave |
|---|---|---|---|
| `admin.estoque.fornecedores.index` | GET `estoque/fornecedores` | `EstoqueFornecedorController@index` | estoque_operacao |
| `admin.estoque.fornecedores.store` | POST `estoque/fornecedores` | `@store` | estoque_operacao |
| `admin.estoque.fornecedores.update` | PUT `estoque/fornecedores/{id}` | `@update` | estoque_operacao |
| `admin.estoque.compras.index` | GET `estoque/compras` | `EstoqueCompraController@index` | estoque_operacao |
| `admin.estoque.compras.create` | GET `estoque/compras/novo` (aceita `?os={id}`) | `@create` | estoque_operacao |
| `admin.estoque.compras.store` | POST `estoque/compras` | `@store` | estoque_operacao |
| `admin.estoque.compras.show` | GET `estoque/compras/{id}` | `@show` | estoque_operacao |
| `admin.estoque.compras.cancel` | POST `estoque/compras/{id}/cancelar` | `@cancel` | estoque_operacao |
| `admin.estoque.nf.index` | GET `estoque/nf` | `EstoqueNfController@index` | estoque_operacao |
| `admin.estoque.nf.create` | GET `estoque/nf/nova` | `@create` | estoque_operacao |
| `admin.estoque.nf.receive` | POST `estoque/nf/receber` | `@receive` | estoque_operacao |
| `admin.estoque.nf.store` | POST `estoque/nf` | `@store` (receber + lançar) | estoque_operacao |
| `admin.estoque.nf.show` | GET `estoque/nf/{id}` | `@show` | estoque_operacao |
| `admin.estoque.nf.post` | POST `estoque/nf/{id}/lancar` | `@post` | estoque_operacao |
| `admin.estoque.nf.reverse` | POST `estoque/nf/{id}/estornar` | `@reverse` | estoque_operacao |
| `admin.estoque.pecas.buscar` | GET `estoque/busca-pecas?q=` (JSON; custo só se `ValoresFinanceiros::can()`) | `EstoqueRegrasController@buscarPecas` | estoque_operacao |
| `admin.estoque.parados` | GET `estoque/parados` | `EstoqueRegrasController@parados` | estoque_operacao |
| `admin.estoque.inventario.index` | GET `estoque/inventario` | `EstoqueInventarioController@index` | estoque_inventario |
| `admin.estoque.inventario.show` | GET `estoque/inventario/{id}` | `@show` | estoque_inventario |
| `admin.estoque.inventario.generate` | POST `estoque/inventario/gerar` | `@generate` | estoque_inventario |
| `admin.estoque.inventario.count` | POST `estoque/inventario/itens/{itemId}/contar` | `@count` | estoque_inventario |
| `admin.estoque.inventario.close` | POST `estoque/inventario/{id}/fechar` | `@close` | estoque_inventario |
| `admin.estoque.ajustes.index` | GET `estoque/ajustes` | `EstoqueInventarioController@ajustes` | estoque_inventario |
| `admin.estoque.ajustes.store` | POST `estoque/ajustes` | `@storeAjuste` | estoque_inventario |
| `admin.estoque.config` | GET `estoque/regras` | `EstoqueRegrasController@config` | estoque_config |
| `admin.estoque.config.update` | PUT `estoque/regras` | `@updateConfig` | estoque_config |
| `admin.service_orders.pecas.entregar` | POST `service-orders/{id}/pecas/entregar` | `OsPecasController@entregar` | estoque_operacao |
| `admin.service_orders.pecas.devolver` | POST `service-orders/{id}/pecas/devolver` | `@devolver` | estoque_operacao |
| `admin.service_orders.pecas.reduzir` | POST `service-orders/{id}/pecas/reduzir` | `@reduzir` | estoque_operacao |

Ordem no arquivo: `compras/novo` antes de `compras/{id}`; `nf/nova` antes de `nf/{id}`. As rotas `admin.service_orders.pecas.*` ficam no grupo `service-orders/{id}/pecas` (mesmos middlewares do arquivo: `DonoForaDoFluxo` registra o dono operando, `RegistraAcessoSensivel` conforme o prefixo).

Rotas **existentes** que mudam de comportamento (sem mudar de nome/chave): `admin.parts.movements.store` (a política recusa entrada/ajuste/saída com o piloto ligado), `admin.parts.store`/`destroy`, `admin.service_orders.workflow.action` (`comprar_peca`, `entregar_peca`, `conferir_pecas`), `admin.service_orders.items.destroy`, `admin.mentoria.registros.store`.

### Configurações novas (`SO/Services/WorkshopSettings.php`, `DEFAULTS`)

| Chave | Padrão | Uso |
|---|---|---|
| `estoque_parado_dias` | `180` | dias sem entrada/saída para a peça contar como parada (lista, valor e PEC-11) |
| `nf_entrada_prazo_horas` | `24` | prazo para lançar a NF depois de recebida (PEC-07, painel, alerta); entra no divisor do modo de teste |
| `inventario_regra` | `'rodizio'` | `rodizio` \| `valor` \| `giro` |
| `inventario_itens_semana` | `15` | quantidade de itens a contar por semana |
| `inventario_dia_limite` | `5` | dia ISO (1 = segunda … 7 = domingo) em que o inventário deve estar fechado (usa `WorkshopSettingsController::DIAS` para os nomes) |
| `inventario_ciclo_dias` | `30` | carência para contar o mesmo item de novo nas regras `valor` e `giro` |
| `compra_prazo_sugerido_dias` | `2` | sugestão de prazo prometido no pedido de compra (se o fornecedor tem `prazo_padrao_dias`, vale o dele) |
| `estoque_deposito_padrao` | `null` | id do depósito usado na NF de entrada quando a tela não escolhe |

Tela de edição: `estoque/regras` (chave `estoque_config`), com `WorkshopSettings::set(...)`. **Não** mexer em `ficha/edit.blade.php` nem em `FichaController` (várias frentes editam) — no máximo um link "Regras do estoque" na Ficha.

### Outras chaves/códigos novos

- Eventos da OS: `pedido_compra_emitido`, `pedido_compra_cancelado`, `peca_recebida`, `peca_devolvida`.
- Barreira: `entrega_peca`; condição de barreira: `pedido_entregue`.
- Alertas: `nf_entrada_atrasada`, `nf_entrada_atrasada_gerente`, `inventario_atrasado`, `inventario_atrasado_gerente`.
- Eventos do Laravel: `part.stock.movement.before` (PartInventory) e `workshop.alcada.decidida` (alçadas).
- Assunto de alçada: **nenhum novo** — reaproveita `ajuste_estoque` (passa a `automatico = true`) e `compra_pecas`.
- Origens de movimento (`part_stock_movements.origem`): `nf_entrada`, `abertura`, `os`, `devolucao_os`, `ajuste_aprovado`, `inventario`, `estorno_nf`.
- Tipo de registro da mentoria: reaproveita `inventario_rotativo` e `peca_errada` (sem tipos novos).

---

## Textos da interface (pt-BR)

Colocar em `SO/Resources/lang/pt_BR/app.php` (chave `estoque`, abaixo) **e** em `…/en/app.php` (mesma estrutura, traduzida). Datas `dd/mm/aaaa hh:mm`, fuso `America/Sao_Paulo`. Nenhum texto de alerta, evento ou cartão da OS leva R$.

```php
'estoque' => [
    'abas' => [
        'pecas' => 'Peças', 'compras' => 'Pedidos de compra', 'nf' => 'NF de entrada', 'fornecedores' => 'Fornecedores',
        'inventario' => 'Inventário da semana', 'ajustes' => 'Ajustes de saldo', 'parados' => 'Peças paradas', 'regras' => 'Regras do estoque',
    ],
    'status' => [
        'pedido' => ['aberto' => 'Aguardando o fornecedor', 'parcial' => 'Recebido em parte', 'recebido' => 'Recebido', 'cancelado' => 'Cancelado'],
        'nf' => ['recebida' => 'A lançar', 'lancada' => 'Lançada', 'estorno_pedido' => 'Estorno aguardando aprovação', 'estornada' => 'Estornada'],
        'ajuste' => ['pendente' => 'Aguardando aprovação', 'aplicado' => 'Aprovado e aplicado', 'recusado' => 'Recusado'],
        'inventario' => ['em_contagem' => 'Em contagem', 'fechado' => 'Fechado'],
        'peca' => [
            'a_precificar' => 'Aguardando preço', 'precificada' => 'Precificada', 'a_comprar' => 'A comprar',
            'aguardando_fornecedor' => 'Aguardando o fornecedor', 'atrasada' => 'Fornecedor atrasado', 'recebida' => 'Recebida, falta entregar',
            'pronta' => 'Em estoque, pronta para entregar', 'sem_saldo' => 'Sem saldo no estoque',
            'entregue_parcial' => 'Entregue em parte', 'entregue' => 'Entregue ao técnico',
        ],
    ],
    'erros' => [
        'baixa_sem_os' => 'A baixa de peça só pode ser feita por lançamento em uma OS (entrega ao técnico). Não existe baixa avulsa.',
        'entrada_sem_nf' => 'A entrada de peças no estoque só pode ser feita pela NF de entrada.',
        'ajuste_sem_aprovacao' => 'O ajuste de saldo precisa da aprovação do gerente. Use "Pedir ajuste de saldo".',
        'saldo_negativo' => 'Esta operação deixaria o saldo negativo (saldo atual: :saldo).',
        'saldo_so_por_movimento' => 'O saldo do estoque só muda por movimento (NF de entrada, OS ou ajuste aprovado).',
        'abertura_repetida' => 'O saldo de abertura só pode ser lançado uma vez por peça e depósito, antes de qualquer outro movimento.',
        'movimento_imutavel' => 'Movimentos de estoque não podem ser alterados nem apagados; registre um novo movimento com justificativa.',
        'peca_com_historico' => 'Esta peça tem movimentos de estoque, saldo ou OS ligadas e não pode ser excluída. Os registros do estoque nunca são apagados.',
        'deposito_com_estoque' => 'Este depósito tem peças em estoque ou histórico de movimentos e não pode ser excluído.',
        'os_com_movimento' => 'Esta OS tem peças baixadas do estoque e não pode ser excluída. Cancele a OS em vez de excluí-la.',
        'peca_entregue_nao_remove' => 'Esta peça já foi entregue ao técnico. Registre a devolução ao estoque antes de removê-la da OS.',
        'peca_em_pedido' => 'Esta peça está no pedido de compra :numero. Cancele o pedido antes de removê-la da OS.',
        'os_cancelar_com_pecas' => 'Há peças entregues ao técnico nesta OS. Registre a devolução ao estoque antes de cancelar.',
        'pedido_existente' => 'Esta peça já tem o pedido de compra :numero em aberto.',
        'peca_nao_chegou' => 'A peça ainda não chegou: o saldo físico é :saldo e a entrega pede :quantidade. Lance a NF de entrada primeiro.',
        'registro_automatico' => 'Este registro agora é feito automaticamente pelo módulo de Estoque.',
        'sem_migration' => 'O módulo de compras, NF e inventário ainda não foi atualizado no banco desta oficina. Peça ao suporte para rodar as atualizações.',
    ],
    'compras' => [
        'titulo' => 'Pedidos de compra', 'novo' => 'Novo pedido de compra', 'fornecedor' => 'Fornecedor', 'novo_fornecedor' => 'Cadastrar novo fornecedor',
        'prazo' => 'Prazo prometido pelo fornecedor', 'hora' => 'Hora (opcional)', 'itens' => 'Peças do pedido', 'quantidade' => 'Quantidade',
        'custo' => 'Custo previsto (R$)', 'emitir' => 'Registrar pedido de compra', 'cancelar' => 'Cancelar pedido', 'motivo_cancelamento' => 'Motivo do cancelamento',
        'registrado' => 'Pedido de compra :numero registrado. Carimbo de compra: :data.', 'cancelado' => 'Pedido :numero cancelado.',
        'sem_itens' => 'Escolha pelo menos uma peça.', 'prazo_passado' => 'O prazo prometido não pode estar no passado.',
        'ja_recebido' => 'Este pedido já recebeu peças. Para desfazer, estorne a NF de entrada.', 'reposicao' => 'Reposição de estoque (sem OS)',
    ],
    'nf' => [
        'titulo' => 'NF de entrada', 'receber' => 'Registrar recebimento', 'lancar' => 'Lançar NF', 'numero' => 'Número da NF', 'serie' => 'Série',
        'chave' => 'Chave de acesso (44 dígitos)', 'emissao' => 'Data de emissão', 'recebida_em' => 'Recebida em', 'deposito' => 'Depósito',
        'total_nota' => 'Total da nota (R$)', 'a_lancar' => 'A lançar', 'lancadas' => 'Lançadas', 'estornadas' => 'Estornadas',
        'recebimento_ok' => 'Recebimento da NF :numero registrado. Lance os itens em até :horas h.',
        'lancada_ok' => 'NF :numero lançada: :itens item(ns) entraram no estoque.',
        'duplicada' => 'Esta NF já foi registrada (#:id).',
        'diferenca_total' => 'Atenção: o total dos itens (R$ :itens) é diferente do total da nota (R$ :nota). Confira antes de lançar.',
        'estorno_pedir' => 'Pedir estorno', 'estorno_motivo' => 'Motivo do estorno',
        'estorno_enviado' => 'Pedido de estorno enviado ao gerente. O saldo só diminui depois da aprovação.',
        'estorno_sem_saldo' => 'Não dá para estornar: a peça :peca já saiu do estoque (saldo atual :saldo).',
    ],
    'ajustes' => [
        'titulo' => 'Ajustes de saldo', 'pedir' => 'Pedir ajuste de saldo', 'saldo_contado' => 'Saldo real (contado)', 'motivo' => 'Motivo (obrigatório)',
        'enviado' => 'Pedido de ajuste enviado ao gerente. O saldo só muda depois da aprovação.',
        'sem_diferenca' => 'O saldo informado é igual ao saldo do sistema.', 'negativo' => 'O ajuste deixaria o saldo negativo.',
        'descricao' => 'Ajuste de :quantidade un. de :peca (:codigo) no depósito :deposito: saldo :antes → :depois. Motivo: :motivo',
        'aprovado_msg' => 'aprovado. O saldo do estoque já foi ajustado.',
    ],
    'inventario' => [
        'titulo' => 'Inventário rotativo da semana', 'gerar' => 'Gerar a lista da semana', 'semana' => 'Semana :semana (:inicio a :fim)',
        'regra' => 'Regra de escolha: :regra', 'contar' => 'Salvar contagem', 'qtd_contada' => 'Quantidade contada', 'saldo_sistema' => 'Saldo do sistema',
        'divergencia' => 'Divergência', 'recontar' => 'Recontar', 'fechar' => 'Fechar o inventário da semana', 'local' => 'Local',
        'faltam' => 'Faltam :n item(ns) para contar.', 'acuracidade' => 'Acuracidade', 'ja_fechado' => 'Este inventário já foi fechado.',
        'cega' => 'O saldo do sistema só aparece depois que você salva a contagem.',
        'fechado' => 'Inventário fechado: :contados item(ns) contado(s), :corretos com saldo correto, :divergentes com divergência. Os pedidos de ajuste foram enviados ao gerente.',
        'sem_itens' => 'Nenhuma peça com saldo para contar nesta semana.',
    ],
    'parados' => [
        'titulo' => 'Peças paradas há mais de :dias dias', 'ultima_saida' => 'Última saída', 'nunca' => 'nunca saiu', 'dias_parada' => 'Dias parada',
        'saldo' => 'Saldo', 'custo_medio' => 'Custo médio', 'valor_parado' => 'Valor parado',
        'total' => 'Total parado: :itens item(ns), :percentual do valor do estoque',
        'custo_estimado' => 'Valor estimado pelo preço de venda: informe o custo na NF de entrada ou no saldo de abertura.',
        'vazio' => 'Nenhuma peça parada. Bom sinal.',
    ],
    'config' => [
        'titulo' => 'Regras do estoque', 'salvo' => 'Regras do estoque salvas.',
        'estoque_parado_dias' => 'Dias sem entrada ou saída para considerar a peça parada',
        'nf_entrada_prazo_horas' => 'Prazo para lançar a NF depois de recebida (horas)',
        'inventario_regra' => 'Como escolher os itens do inventário da semana', 'inventario_itens_semana' => 'Itens contados por semana',
        'inventario_dia_limite' => 'Dia limite para fechar o inventário', 'inventario_ciclo_dias' => 'Dias antes de contar o mesmo item de novo (regras valor e giro)',
        'compra_prazo_sugerido_dias' => 'Prazo sugerido ao fornecedor (dias)', 'estoque_deposito_padrao' => 'Depósito padrão da NF de entrada',
        'regras' => [
            'rodizio' => 'Rodízio: o item contado há mais tempo primeiro',
            'valor' => 'Valor: o item com mais dinheiro parado no estoque primeiro',
            'giro' => 'Giro: o item que mais movimentou nos últimos 90 dias primeiro',
        ],
    ],
    'os' => [
        'titulo' => 'Peças e compras desta OS', 'peca' => 'Peça', 'lancada' => 'Lançada', 'entregue' => 'Entregue', 'situacao' => 'Situação',
        'fornecedor_prazo' => 'Fornecedor e prazo', 'emitir_pedido' => 'Emitir pedido de compra', 'entregar' => 'Entregar ao técnico',
        'devolver' => 'Devolver ao estoque', 'reduzir' => 'Reduzir a quantidade lançada', 'tecnico_recebeu' => 'Técnico que recebeu',
        'divergencia_titulo' => 'Divergência entre peças lançadas e entregues',
        'entrega_ok' => 'Entrega registrada: :quantidade un. de :peca. Baixa feita no estoque.',
        'entrega_ok_legado' => 'Entrega da peça ao técnico registrada.',
        'entrega_a_mais' => 'Só falta entregar :falta un. desta peça.',
        'devolucao_ok' => 'Devolução registrada: :quantidade un. de :peca voltaram ao estoque.',
        'reducao_ok' => 'Quantidade lançada de :peca reduzida de :de para :para.',
        'pendencia' => 'Peça ":peca": lançada :lancada e entregue ao técnico :entregue',
        'causas' => ['nao_usada' => 'Sobrou (não usada)', 'peca_errada' => 'Peça errada (código, cotação ou envio)', 'defeito' => 'Veio com defeito', 'outra' => 'Outra'],
    ],
    'alertas' => [
        'nf_titulo' => 'NF de entrada sem lançar', 'inventario_titulo' => 'Inventário da semana atrasado',
        'nf' => 'ALERTA - NF de entrada nº :numero (:fornecedor) recebida em :data ainda não foi lançada.',
        'inventario' => 'ALERTA - O inventário rotativo da semana :semana não foi concluído: faltam :n item(ns) para contar.',
    ],
],
```

Tradução das linhas do Painel de Peças (títulos de seção): `Pedidos de compra a receber`, `Entregas ao técnico pendentes` (mantido), `NF de entrada a lançar`, `Inventário rotativo semanal` (mantido), `Peças paradas há mais de :dias dias`. Texto das linhas: pedido — `PC-00012 · {Fornecedor} · {n} peça(s) · prazo {dd/mm HH:mm}`; entrega — `{Peça} (pronta para entregar)` / `(aguardando o fornecedor, prazo {dd/mm})` / `(sem saldo no estoque)`; NF — `NF {numero} · {Fornecedor} · recebida {dd/mm HH:mm}`; inventário — `Inventário da semana {2026-47}: {c} de {n} itens contados` / `ainda não gerado: abra o inventário`; parados — `{N} peça(s) paradas há mais de {dias} dias` (+ ` · {valor}` só com permissão).

---

## Testes Pest a escrever

Arquivos novos em `tests/Feature/Workshop/` (Pest + `DatabaseTransactions` já aplicados pelo `uses` de `tests/Pest.php:21`). Não editar `BuildsWorkshop.php` nem `Pest.php`: criar a trait `tests/Feature/Workshop/Concerns/BuildsEstoque.php` e usá-la com `uses(Tests\Feature\Workshop\Concerns\BuildsEstoque::class);` no topo de cada arquivo novo. Funções auxiliares globais com prefixo `estoque…` (nomes únicos entre arquivos); chame `EstoqueSchema::esquecer()` no `beforeEach`.

**Trait `BuildsEstoque`** (assinaturas):

```php
protected function makeDeposito(string $nome = 'Depósito Teste'): int                     // DB::table('warehouses')->insertGetId com contact_* = '[]' (evita os traits do modelo)
protected function makePeca(array $override = []): Part                                   // codigo único, nome, valor_unitario 100, quantidade_minima 0
protected function makeLinhaEstoque(Part $peca, int $saldo = 0, ?int $depositoId = null): PartInventory // cria a linha com 0 e usa PartInventoryService::abrirSaldo (respeita as guardas)
protected function makeItemPeca(ServiceOrder $os, Part $peca, int $qtd = 1, array $extra = []): ServiceOrderItem // via ServiceOrderItemService::addItem (faz a reserva) + forceFill dos extras
protected function makeEquipeEstoque(): array                                             // pecas (estoque_operacao + estoque_inventario + ver_valores_financeiros + service_orders.*), gerente (alcadas), dono, tecnico (sem nada de estoque)
```

| Arquivo | `it(...)` | Cobre |
|---|---|---|
| `EstoqueMovimentosTest.php` | **baixar peça sem OS é impossível** (piloto ligado): `applyMovement('saida')` sem OS lança `ValidationException`; `saida` com OS mas sem `os_item_id`, ou item de outra peça, idem; `POST admin.parts.movements.store` com `ajuste −2` e com `entrada +5` → `session('error')`, saldo e livro intactos; `PartInventory::update(['in_stock' => …])` com usuário logado → `LogicException`; **piloto desligado**: o mesmo `ajuste −2` passa e o saldo cai (comportamento antigo) | **PDF B4 "baixar peça sem OS é impossível" · roteiro 35** |
| | entrega ao técnico baixa o saldo: movimento `saida` com `reference_id` = OS, `os_item_id`, `origem = os`, `saldo_apos`; `quantidade_entregue`, `entregue_tecnico_em`, `entregue_por`, `entregue_a_mechanic_id`; reserva liberada; `custo_unitario` do item preenchido quando vazio; evento `peca_entregue_tecnico` **sem R$** | roteiro 34 (carimbo de entrega) |
| | entrega parcial soma e só carimba na última; não entrega mais que o lançado (`entrega_a_mais`) nem sem saldo; peça de pedido que ainda não chegou → `peca_nao_chegou` | bordas |
| | devolução ao estoque: `entrada` com `origem = devolucao_os`, volta a reserva, zera o carimbo se deixou de estar completa; `causa = peca_errada` grava `peca_errada` com a OS (PEC-02/TEC-02) | PEC-02 |
| | **NF de entrada soma o saldo**: `receber` (carimba `recebida_em`) → `lancar` (2 itens): `in_stock` sobe, `origem = nf_entrada`, `custo_medio` ponderado (10 un. a 10,00 + 10 un. a 20,00 → 15,0000), `ultimo_custo`, `status = lancada`, linha do pedido de compra recebida | **PDF/pedido "NF de entrada soma saldo"** |
| | NF duplicada (chave ou número+série+fornecedor) recusada; lançar custo sem `ver_valores_financeiros` → 403 e nada muda; `receber` sem valores funciona | bordas / valores |
| | **ajuste sem aprovação não altera saldo**: `POST admin.estoque.ajustes.store` cria `EstoqueAjuste` pendente + `AlcadaPedido` tema `ajuste_estoque` (mesmo sem limite configurado) e **não grava movimento**; gerente aprova → movimento `ajuste` com `estoque_ajuste_id`, saldo muda, ajuste `aplicado`, pedido `consumido`; outro pedido recusado → saldo igual, ajuste `recusado` | **PDF "ajuste sem aprovação não altera saldo"** |
| | ajuste acima do limite do gerente nasce no gerente e só o dono decide depois de encaminhar; ajuste aprovado que deixaria saldo negativo: `decide()` lança e **a decisão não é gravada** (transação) | alçada |
| | livro imutável: `update`/`delete` de `PartStockMovement` → `LogicException`; excluir peça com movimento, com saldo ou com OS → bloqueado (e liberado sem histórico); excluir depósito com estoque → bloqueado; excluir OS com peça baixada → bloqueado; **piloto desligado** mantém exclusão como hoje | "nada se apaga" |
| | saldo de abertura vira movimento (`origem = abertura`) no `PartController::store` e na importação; segunda abertura da mesma linha → `abertura_repetida` | via 5/6 |
| | estorno de NF cria pedidos de ajuste negativos com justificativa e só reduz o saldo depois de aprovados; peça que já saiu bloqueia o estorno | via de estorno |
| | OS: remover item entregue e cancelar OS com peça entregue bloqueiam; concluir OS com piloto ligado só libera a reserva do que não foi entregue (não baixa em dobro); **piloto desligado** consome na conclusão como antes; com `EstoqueSchema::forcar(false)` (migration pendente) tudo segue o caminho antigo | observador / tolerância |
| `EstoqueComprasTest.php` | pedido de compra liga OS autorizada + fornecedor + prazo prometido: cria `pedidos_compra`/linhas, carimba `comprada_em`/`comprado_por`, grava `fornecedor` e `prazo_entrega_em` nos itens, emite `compra_peca` (sem `referencia_id`) e `pedido_compra_emitido` (com `referencia_id` e `prazo_em`); fornecedor novo criado na hora | **roteiro 34 (carimbo de compra)** |
| | compra para OS **sem** autorização do cliente e reposição sem OS passam pela alçada `compra_pecas` ("repita a ação") — e o legado `comprar_peca` (rota `workflow.action`) continua passando em `AlcadasTest.php:314-337` | alçada / compatibilidade |
| | barreira `entrega_peca`: Peças alertado no prazo prometido, Chefe 4 h úteis depois; entrega completa fecha e resolve com "RESOLVIDO"; entrega de peça de estoque (sem pedido) não mexe na barreira; cancelar o pedido encerra a barreira; mensagem sem R$ | barreira com prazo |
| | cancelar: só sem recebimento, exige motivo, limpa os carimbos dos itens e emite `pedido_compra_cancelado`; item já em pedido ativo não entra em outro (`pedido_existente`) | bordas |
| | PEC-04 e PEC-05 leem o pedido: compra no dia da aprovação e entrega até o prazo prometido | PEC-04/05 |
| | `EstoquePainel`/`RotinaService::dePecas`: 'Compras do dia' mantém `'Turbina (comprar hoje)'` (`RotinaTest.php:105-106`), 'Pedidos de compra a receber' fica vermelho após o prazo, 'Entregas ao técnico pendentes' distingue pronta / aguardando fornecedor / sem saldo | Painel de Peças |
| `EstoqueInventarioTest.php` | gera a lista da semana uma única vez (idempotente), com a quantidade e a regra da configuração (`rodizio` pelo mais antigo; `valor`; `giro`); itens com divergência não resolvida vêm primeiro; estoque vazio → inventário de 0 itens que fecha sem erro | regra configurável |
| | contagem às cegas grava `saldo_sistema` e `divergencia` no ato; recontagem cria nova linha com `corrige_id` (a anterior continua); só com inventário `em_contagem` | contagem |
| | **inventário gera divergência**: fechar exige todos contados; cria um `EstoqueAjuste` + pedido de alçada por divergência (delta = contada − saldo na contagem); grava `inventario_rotativo` (`contados`, `corretos`) → `acuracidade()` (PEC-09) e `inventarioSemanal()` (PEC-10) corretos; aprovação aplica o delta mesmo que o saldo tenha mudado depois | **PDF "inventário gera divergência"** |
| | alertas: `inventario_atrasado` a Peças depois do dia limite e ao Gerente no dia útil seguinte; sem R$; dono não recebe | alertas |
| `EstoqueParadosTest.php` | peças paradas: usa `estoque_parado_dias` (mudar 180 → 90 muda a lista); entrada/saída zera o relógio, **ajuste não**; peça sem movimento usa `part_inventories.created_at`; valor a custo médio com `custo_estimado` quando cai para o preço de venda; valor só para quem vê valores | **PDF "peças paradas > 180 dias: lista e valor"** |
| | PEC-11 e `valor_estoque` do Painel do Dono usam o mesmo serviço; estoque vazio → `IndicatorResult::none`, tela com mensagem vazia | PEC-11 |
| | PEC-07: NF lançada em 23 h conta certa, em 25 h errada, **pendente vencida conta errada**, pendente no prazo fica fora; mudar `nf_entrada_prazo_horas` muda o resultado; registro manual antigo continua somando; com o piloto ligado `storeRegistro` recusa `nf_entrada` e `inventario_rotativo` | PEC-07 |
| | alerta `nf_entrada_atrasada` a Peças e `…_gerente` com o dobro do prazo, `unicoPorDia`, sem R$ | alertas |
| `PecasConferenciaTest.php` | **divergência**: lançada 2 / entregue 1 aparece em `divergencias()` e em `pendencias()` com o texto combinado; some ao entregar o resto, ou ao devolver + `reduzirLancada`; carimbo antigo sem baixa conta como entregue | **roteiro 46** |
| | `conferir_pecas` com divergência calculada **não aceita** "sem divergência"; sem divergência grava `divergencia = false`; PEC-06 conta só OS conferidas sem divergência; `reduzirLancada` grava `orcamento_corrigido` (quem = pecas) e recalcula totais; não aceita nova quantidade menor que a entregue | PEC-06 / PEC-03 |
| `EstoqueHttpTest.php` | rotas do estoque: Técnico, Consultor, Chefe → 401/403 (qualquer um dos dois, ver F05); Peças, Gerente e Proprietário passam; sem a chave de config, Peças não abre `estoque/regras` | ACL |
| | custos só aparecem para quem vê valores: `NF`, `pedido`, `parados`, JSON de `busca-pecas` e `parts.view` (custo médio) — sem a permissão não há `custo`, `R$` nem `valor` nas respostas | valores / API |
| | a **OS do Técnico** mostra o cartão "Peças e compras desta OS" (nome, lançada, entregue, situação) sem nenhum dos segredos de `TecnicoValoresTest` (`noMoney`) e sem os formulários | técnico sem valores |
| | `EstoqueSchema::forcar(false)`: telas novas devolvem a página 503 com `sem_migration`; a OS, o observador e o painel funcionam como antes | migration pendente |
| | `DonoForaDoFluxo`: o dono entregando peça pela rota da OS gera `acao_dono_fora_fluxo` | regra do dono blindado |

Cobertura das linhas "Teste:" do PDF e do roteiro: B4 "baixar peça sem OS é impossível" (movimentos), "vender abaixo do mínimo" e "precificar fora do prazo" são da **F10**; ajuste/NF/inventário/peças paradas (pedido da frente) nos arquivos acima; roteiro **34** (compras + movimentos), **35** (movimentos), **46** (conferência); roteiro 19/22/23 (painel e precificação) são da F10, mas o teste do Painel acima garante que a F11 não os quebra.

Comandos: `php artisan test --filter=Estoque`, `--filter=PecasConferencia`, depois `--filter=Workshop` e `php vendor/bin/pint` nos arquivos tocados. Ajustar os testes antigos afetados: `AllBarriersTest.php:59-62` (contagem de barreiras) e, se a F10 mudar `hasStock`, o teste de precificação (`AllBarriersTest.php:195-223`).

---

## Riscos e interações com outras frentes

### Arquivos compartilhados (onde dois implementadores podem se pisar)

| Arquivo | Quem mais edita | O que a F11 toca | Como evitar conflito |
|---|---|---|---|
| `SO/Config/acl.php`, `SO/Routes/web.php` | todas | 3 chaves e 2 grupos de rotas | acrescentar no **fim** do arquivo, bloco com comentário `// F11 estoque` |
| `SO/Services/WorkshopSettings.php` (`DEFAULTS`) | F03, F05, F08, F10, F14… | 8 chaves | bloco único comentado `// Estoque e compras (F11)` |
| `SO/Models/ServiceOrderEvent.php` (`TIPOS`) | F06, F09, F10, F12, F13 | 4 tipos | linhas novas juntas, depois de `peca_entregue_tecnico` |
| `SO/Models/BarrierRule.php` (`DEFAULTS`) e `AllBarriersTest.php:59-62` | F03, F06, F12, F13 | +1 regra (`entrega_peca`) | o teste de contagem vira "contém"; quem rodar a suíte por último reconcilia o número |
| `SO/Services/BarrierConditions.php` | F06, F12, F13 | +1 condição `pedido_entregue` | braço novo no `match` |
| `SO/Services/Alcada/AlcadaService.php` / `AlcadaCatalog.php` | F06, F08, F12, F13 | transação + evento `workshop.alcada.decidida`; `ajuste_estoque.automatico = true` | o gancho é genérico; basta um único `Event::dispatch` (se outra frente já o criou, reutilizar o mesmo nome) |
| `SO/Services/WorkflowActions.php` | F06, F09, F10, F12, F13 | `buy`, `deliverPart`, `checkParts` | métodos já isolados; não reordenar `definitions()` |
| `SO/Services/WorkflowService.php` (`hasStock`) | **F10** | troca do corpo por `EstoqueSaldo::cobreItem` | quem chegar primeiro faz; o outro confere |
| `SO/Services/Rotina/RotinaService.php` (`dePecas`) e `rotina/painel.blade.php` | **F10**, F05 | seções do estoque + campo `url` | manter 'Compras do dia' e os títulos atuais (`RotinaTest.php:82-107`) |
| `SO/Services/Indicators/IndicatorCalculator.php` | F06, F08, F12, F13 | PEC-07, `pecasParadas` | só 2 funções; registro do PEC-07 é 1 linha |
| `SO/Services/PeriodicAlerts.php` | F03, F13 | +2 métodos em `run()` | somar na expressão de `run()` |
| `SO/Providers/ServiceOrderServiceProvider.php` | F02, F05… | 6 ouvintes/guardas | bloco comentado depois de `ServiceOrder::observe` |
| `SO/Resources/views/view.blade.php`, `partials/workflow_actions.blade.php` | F06, F07, F09, F12, F13 | 1 `@includeIf`, 1 `@continue` | linhas isoladas |
| `SO/Resources/lang/{pt_BR,en}/app.php` | todas | chave `estoque` | bloco novo no fim do array |
| `SO/Observers/ServiceOrderObserver.php` | F09, F12, F13 | só o bloco de peças (`:123-148`) | métodos `partItems`/novo método `liberarOuConsumirPecas` |
| `PI/…` (PartInventory) | ninguém | serviço, modelos, controlador, views | pacote próprio da F11 |

### Por frente

- **F00 (oficinas):** as 8 tabelas e as colunas novas precisam chegar a cada banco de oficina. `EstoqueSchema` guarda a resposta **por banco** (o `workshop:tick` troca de banco no mesmo processo, ao contrário de `PrevisaoOs::disponivel()`, que memoiza para sempre). Os alertas novos rodam por oficina porque entram em `PeriodicAlerts`.
- **F01 (correções):** a F11 traduz os cabeçalhos em inglês da tela da peça ao editá-la; a formatação de datas do sistema inteiro continua com a F01 (a F11 usa `format('d/m/Y H:i')`).
- **F02 (notificações):** nenhum trabalho; os alertas novos usam `AlertService::raise`, que já entrega por sino, push e SMS.
- **F03 (piloto/modo de teste):** o ponto único é `EstoquePrazos::divisorDeTeste()`; a F03 deve apontá-lo para a chave/ajudante que criar. A barreira `entrega_peca` é escalada pelo motor (só o `nivel2_apos_minutos`; o prazo prometido é data absoluta). O inventário semanal e as peças paradas **não** são divididos por 10. Estoque **não** deve ser requisito para ligar o piloto (mas a tela de regras mostra "módulo ativo / aguardando atualização do banco").
- **F04/F05 (perfis, menu, 403):** só criar as 3 chaves; **ligá-las** aos perfis é da F04/F05 (tabela na seção ACL). O `Bouncer` hoje responde **401** (não 403) a rota mapeada negada (`Bouncer.php:79-86`); os testes novos aceitam 401 ou 403. Peças precisa de `view_permission = global` e de `service_orders.view/edit` para abrir a OS e precificar. Não conceder `products.*` (estoque paralelo do Krayin).
- **F06/F08/F12/F13/F14:** o gancho `workshop.alcada.decidida` e a transação em `AlcadaService::decide` são genéricos e podem ser usados por elas; nenhum dado compartilhado.
- **F09 (nota/saída):** consome `PecasConferencia::pendencias()` e `temDivergencia()` (contrato em M.4). A F11 **não** bloqueia o faturamento.
- **F10 (peças — painel e precificação):** contrato em M.4. A F11 não toca a fila de precificação, o preço mínimo nem o cronômetro de 20/40 min; só corrige a pergunta "tem em estoque?" (`cobreItem`).

### Riscos técnicos

1. **A baixa muda de momento** (hoje na conclusão da OS, depois da entrega ao técnico): com o piloto ligado e o módulo ativo passa a ser a **entrega**. OS que já estavam em andamento quando o piloto foi ligado e cujas peças não foram entregues pelo módulo terminam sem baixa (só libera a reserva). Mitigação: configurar o estoque **antes** de ligar o piloto numa oficina real; na Oficina Teste (estoque vazio) não há impacto. O observador nunca baixa em dobro (consome só `quantity − entregue`).
2. **Guardas de modelo só com usuário logado** (`aoAtualizarSaldo`/`aoCriarSaldo`): scripts, `tinker` e testes sem `actingAs` ainda conseguem gravar `in_stock`. A política do serviço (`aoMovimentar`) vale sempre. Efeito colateral para as **outras frentes**: um teste que faça `actingAs(...)` e depois `PartInventory::create(['in_stock' => 5])` com o piloto ligado passa a falhar; usar `BuildsEstoque::makeLinhaEstoque()`.
3. **Sem as migrations, os controles ficam inativos em silêncio** com o piloto ligado (para não derrubar a oficina). Por isso `estoque/regras` mostra "Módulo de estoque: ATIVO / aguardando atualização do banco" e `EstoqueMigrado` avisa nas telas novas.
4. **Transação em `AlcadaService::decide`** com ouvinte que pode lançar: se o ajuste aprovado não puder ser aplicado (saldo mudou), a decisão volta atrás e o gerente vê o motivo — comportamento desejado, mas vale para qualquer ouvinte futuro desse evento.
5. **Reserva por `first()`:** a reserva continua indo para a primeira linha de estoque da peça (`ServiceOrderItemService.php:68`). Com vários depósitos, a entrega libera na primeira linha e baixa na linha escolhida; o saldo livre total fica certo, o "reservado por depósito" não. Aceito na v1.
6. **Item sem `part_id`** (peça avulsa) e **peça sem linha de estoque**: não reservam nem baixam (só carimbo); não entram na divergência. O formulário de peça já exige um depósito, então o segundo caso só ocorre com cadastro antigo.
7. **Aprovação parcial do cliente** não marca item a item (`WorkflowService::registerApproval` só guarda `parcial`); o pedido de compra aceita qualquer item da OS autorizada. Se outra frente criar a marca de item recusado, filtrar aqui.
8. **Entrega de peça reservada para outra OS:** só se confere o saldo **físico**; a outra OS fica com saldo livre negativo e aparece como "sem saldo" (reflete a realidade). Não há trava de prioridade.
9. **Estorno de NF** não recalcula `custo_medio` nem desfaz o recebimento do pedido de compra (documentado; correção fina por inventário).
10. **`AlcadaController::solicitar`** ainda deixa criar manualmente um pedido do assunto "Ajuste de estoque": ele é só um registro de decisão e **não** altera saldo (o ouvinte ignora pedido sem `EstoqueAjuste`).
11. **Exclusão de depósito:** o `WarehouseController::destroy` do Admin devolve a mensagem de **sucesso** com status 400 quando algo falha (`:150-156`). O bloqueio funciona; a mensagem é enganosa. Correção opcional de uma linha no core (usar `delete-failed`).
12. **Desempenho:** a lista de peças paradas agrupa movimentos por linha; o índice `psm_inv_type_date` cobre. Painel e lista consultam só o necessário (`EstoquePainel` limita a 50 linhas por seção).
13. **Segurança do custo:** `custo_medio`/`ultimo_custo` ficam **fora** de `$fillable` (o `PartController::update` faz mass assignment com o request inteiro).
14. **Registro imutável:** `inventario_rotativo` gravado no fechamento só se corrige por `MentoriaRegistro::correct` (com justificativa); PEC-10 credita a semana em que o inventário foi **fechado** (fechar atrasado não credita a semana anterior).
15. **Eventos fora da transação:** os `ServiceOrderEvent::record` (que disparam o motor de barreiras e o envio de alertas) são gravados **depois do commit**, para não enviar push/SMS de algo que sofreu rollback.

---

## Decisões em aberto

Só o que depende do patrão/usuário (cada uma com a recomendação adotada na spec):

1. **Como carregar o estoque inicial (saldo de abertura).** Recomendo: permitir uma única vez por peça/depósito, registrado como movimento de abertura (quem, quando), sem aprovação, a quem tem `parts.create` — pela tela "Adicionar Peça" ou pela importação —, com relatório dos saldos de abertura. Alternativa mais rígida: exigir aprovação do gerente em cada abertura (gera dezenas de pedidos na fila).
2. **Momento da baixa de estoque.** Recomendo: na **entrega ao técnico** (é quando a peça sai da prateleira; mantém o inventário certo). Alternativa: continuar baixando só na conclusão da OS (como hoje), o que deixa o saldo do sistema acima do físico por dias.
3. **Regra do inventário rotativo e tamanho da lista.** Recomendo: rodízio (o que foi contado há mais tempo primeiro), 15 itens por semana, fechar até sexta; trocar para "valor" (dinheiro parado) se o patrão quiser focar nas peças caras.
4. **Proteção contra apagar peça ou depósito com histórico também com o piloto desligado?** Recomendo **sim** (hoje a exclusão apaga o livro por cascata e viola "nada se apaga"); a spec só protege com o piloto ligado para respeitar "piloto desligado = comportamento atual".
5. **Limite do gerente para ajuste de saldo (R$) na Matriz de alçadas.** O dono precisa preencher "Ajuste de estoque — gerente aprova até R$ __". Recomendo começar com R$ 500,00 (acima disso vai ao dono em até 24 h). Sem limite, **todo** ajuste vai ao gerente.
6. **Definição de "peça parada".** Recomendo: saldo > 0 e nenhuma **entrada nem saída** há mais de N dias (ajuste de saldo não conta como movimento). Alternativa: só contar a última **saída** (venda), o que marca como parada peça recém-comprada que ainda não vendeu.
7. **Venda de balcão (peça vendida sem veículo).** Como a saída só existe por OS, a venda de balcão exige abrir uma OS sem veículo (tipo normal). Confirmar que a rotina aceita isso; senão será preciso um tipo "venda de balcão" (fora desta spec).
8. **Gerar conta a pagar ao lançar a NF de entrada** (Etapa 2, FIN-07). Recomendo ligar só depois do piloto, com o Financeiro já usando o plano de contas.

---

## Passo a passo para o usuário

**Antes de ligar (no servidor, depois que a implementação estiver testada):**

1. Subir o zip com o pacote e abrir `public/migrate-safe.php?key=…` uma vez (cria as 8 tabelas e as colunas novas no banco **principal**); apagar o arquivo em seguida. Para as **oficinas** (inclusive a "Oficina Teste Mentoria"), seguir o procedimento da F00 — até lá o módulo de estoque fica inativo nelas (sem erro).
2. Conferir que o cron de 1 minuto (`php artisan schedule:run`) está ativo (alertas de NF e de inventário).

**Na Oficina Teste Mentoria (tela):**

3. Configurações > Estoque > **Depósitos**: criar pelo menos um (ex.: "Balcão de peças").
4. Estoque > **Adicionar Peça** (ou Configurações > Importar dados > Peças): cadastrar as peças do roteiro e, **antes de ligar o piloto**, informar a "Quantidade inicial" (vira saldo de abertura).
5. Estoque > **Fornecedores**: cadastrar os fornecedores usados no teste.
6. Configurações > **Matriz de alçadas**: preencher "Ajuste de estoque" (limite do gerente) e "Compra de peças" (limites).
7. Configurações > **Funções**: dar ao perfil Peças as permissões `Estoque: pedidos de compra, NF de entrada, entrega de peças e fornecedores`, `Estoque: inventário rotativo e pedidos de ajuste de saldo`, `Ver valores financeiros`, Estoque (peças), Ordens de Serviço (ver/editar) e Minha rotina; ao Gerente e ao Proprietário, as três chaves de estoque e a Fila de alçadas.
8. Estoque > aba **Regras do estoque**: conferir 180 dias, 24 h, rodízio, 15 itens, sexta-feira.
9. Ligar o piloto (Configurações > Indicadores e alertas) **só depois** dos passos 3 a 8.

**Roteiro de aceite na tela (com o modo de teste ÷10 da F03 ligado):**

10. Linha 34: na OS aprovada, usuário Peças abre "Emitir pedido de compra" (fornecedor + prazo) → conferir o carimbo de compra no cartão; lançar a NF de entrada (saldo sobe); "Entregar ao técnico" → conferir o carimbo de entrega e o saldo descendo.
11. Linha 35: na peça, tentar "Adicionar / Ajustar Estoque" para baixar → o sistema recusa; "Pedir ajuste de saldo" cria o pedido e o saldo **não** muda até o gerente aprovar.
12. Linha 46: lançar 2 peças na OS, entregar 1 → o cartão mostra a divergência e a conferência não aceita "sem divergência"; entregar a outra (ou reduzir a quantidade lançada) e conferir de novo.
13. Estoque > **Inventário da semana**: gerar a lista, contar com um número diferente do saldo, fechar → conferir o pedido de ajuste na Fila de alçadas e PEC-09/PEC-10 no Painel.
14. Estoque > **Peças paradas**: com a regra em 180 dias a lista deve vir vazia na oficina nova; baixar para 1 dia na tela de regras, conferir a lista e voltar para 180.

**Avisos para a documentação (`RETOMAR-TRABALHO.md`):** com o piloto ligado o saldo só muda por NF de entrada, OS (entrega ao técnico) e ajuste aprovado; a baixa acontece na entrega, não mais na conclusão; movimentos nunca são editados nem apagados; peça ou depósito com histórico não se exclui; oito chaves novas em `WorkshopSettings`, três de ACL, quatro tipos de evento, uma barreira (`entrega_peca`, 21ª) e quatro alertas; `migrate-safe.php` não migra os bancos das oficinas.

<!-- FIM-DA-SPEC -->



