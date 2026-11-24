# Especificação — F09-nota-saida

Frente: **Nota fiscal e saída do caminhão** (item B3 do PDF de 18/11/2026; linhas 45 a 50 do roteiro de 72 linhas).

Convenções deste documento: a raiz é `d:\volmaster crm\volmaster crm`; os caminhos abaixo são relativos a ela. `SO/` = `packages/Webkul/ServiceOrder/src/` e `FIS/` = `packages/Webkul/Fiscal/src/`. Todo texto de tela em português do Brasil, datas `dd/mm/aaaa hh:mm`, fuso `America/Sao_Paulo`.

Resumo: a maior parte do B3 já existe de forma "parcial". O pacote Fiscal já emite NFS-e e NF-e pela Focus NFe, o `StageGate` já bloqueia a saída sem nota e sem pagamento, o `StageAdvanceService` já aceita avanço forçado com justificativa e dispara dois alertas críticos, o Asaas já confirma o pagamento pelo webhook, e FIN-02 e "saídas sem NF" já são calculados. O que falta é: (1) a emissão aparecer no Faturamento (hoje só com a OS "Concluída"); (2) o registro manual exigir arquivo e restringir quem registra; (3) a marcação "faturamento mensal" no cadastro do cliente (hoje a "frota" é um texto livre do veículo e isenta indevidamente a nota); (4) a regra do PDF de que a frota dispensa só o pagamento, nunca a nota; (5) a trava imediata para o consultor da OS e para o financeiro (hoje só nasce no fechamento do mês e só para quem forçou); (6) o botão "Registrar saída do caminhão"; (7) o bloqueio do faturamento enquanto houver divergência de peças; (8) testes, que hoje não existem para Fiscal nem para o webhook do Asaas.

---

## O que já existe

### Emissão fiscal (pacote Fiscal, Focus NFe)
- `FIS/Services/FocusNfeClient.php:48` `emitNfse()`, `:57` `emitNfe()`, `:74` `consultStatus()`, `:90` `cancel()`, `:125-147` `emit()` (cria o `FiscalDocument` como "processando" ANTES do POST), `:154-172` `mapResponseToColumns()`. Falha encontrada: `emit()` não trata resposta HTTP que não seja 2xx; o corpo de erro da Focus (`{codigo, mensagem}`) não tem `status`, então o documento fica "processando" para sempre e `last_error` fica vazio. O docblock `:14-19` diz que a NF-e "não está ligada a nenhuma tela", o que já não é verdade.
- `FIS/Http/Controllers/FiscalController.php:56` `create()` (confirmação da NFS-e), `:101` `emitForServiceOrder()`, `:141` `createNfe()`, `:172` `emitNfeForServiceOrder()`, `:207` `missingRequirementsNfe()`, `:248` `buildNfePayload()`, `:319` `missingRequirements()`, `:345` `buildNfsePayload()`. Faltas: nenhuma chamada a `ValoresFinanceiros`, nenhuma guarda contra emissão duplicada, nenhuma consulta manual da situação, nenhum bloqueio por etapa.
- `FIS/Routes/web.php:13-23` (rotas `admin.fiscal_documents.create`, `.emit-nfe` [é a NFS-e], `.create-nfe-produto`, `.emit-nfe-produto`); `FIS/Config/acl.php:20-28` (chave `fiscal_documents.emit`).
- `FIS/Http/Controllers/FocusNfeWebhookController.php:22-61` atualiza status, número, XML e PDF; só confere o segredo se `focusnfe.webhook_secret` estiver preenchido (`FIS/Config/focusnfe.php:40`).
- `FIS/Models/FiscalDocument.php:11-17` status `processando`, `autorizado`, `erro_autorizacao`, `cancelado`; `FIS/Models/FiscalSettings.php:42-60` `isReadyForEmission()` e `isReadyForNfeEmission()`.
- `SO/Providers/ServiceOrderServiceProvider.php:66-81`: `FiscalDocument::saved` grava o evento `nf_emitida` (origem `focus`) quando o documento passa a `autorizado`.
- `SO/Resources/views/view.blade.php:27-77`: os botões "Emitir Nota Fiscal" e "Emitir NF-e de Produto" só aparecem com `workshop_status === 'Concluída'` (depois da saída). O Faturamento não tem como emitir.

### Gate da saída e avanço forçado
- `SO/Services/StageGate.php:57-60` `isSaida()`; `:65-74` `hasNotaFiscal()` (vale `nf_manual_em` OU `FiscalDocument` autorizado); `:80-87` `hasPagamentoOuAcordo()` (qualquer boleto "Pago" já vale, mesmo parcial); `:288-310` `faturamentoParaSaida()`, que devolve cedo para a frota (`:296-299`) e portanto isenta nota E pagamento. Isso contradiz o PDF (B3 e linha 50: a frota dispensa só o pagamento; "só com a nota").
- `SO/Models/ServiceOrder.php:333-339` `isFrotaFaturamentoMensal()` devolve `(bool) $this->vehicle?->frota`. A coluna `vehicles.frota` é texto livre ("Nº da frota do cliente (opcional)": `packages/Webkul/Vehicle/src/Database/Migrations/2026_10_21_100000_add_frota_to_vehicles_table.php:16` e `packages/Webkul/Vehicle/src/Resources/views/form.blade.php:159-162`). Qualquer veículo com um número de frota digitado escapa da nota e do pagamento. Não existe marcação "faturamento mensal" no cliente.
- `SO/Services/StageAdvanceService.php:34-81` `advance()`; `:52-59` grava `bloqueio_forcado` com `faltava`, `justificativa` e `escala_dono` (verdadeiro na saída); `:62-64` calcula `$semNf` (com a isenção da frota); `:98-102` grava `saida_em` e o evento `saida_veiculo` com `sem_nf`.
- `SO/Http/Controllers/ServiceOrderController.php:75-115` `updateStage()` (forçar exige `service_orders.forcar_avanco`, `:94`); `SO/Resources/views/partials/progress.blade.php:25-64` (botão genérico "Avançar para Pós-Venda", lista do que falta e formulário "Forçar avanço").
- `SO/Models/ServiceOrderEvent.php:51-54` tipos `nf_emitida`, `pagamento_confirmado`, `acordo_assinado`, `saida_veiculo`; `:92` `bloqueio_forcado`; `:95` `correcao`; `:161-172` `correct()`.
- `SO/Services/WorkflowService.php:369-374` `registerNf()` (só número, sem arquivo, sem quem registrou, sobrescreve sem justificativa), `:376-385` `confirmPayment()` (idempotente), `:387-392` `signAgreement()`.
- `SO/Http/Controllers/WorkflowController.php:164-173` `registerNf()` (`ValoresFinanceiros::authorize`; qualquer um com `service_orders.edit` registra), `:175-191` pagamento e acordo. Rotas `SO/Routes/web.php:112-114`.
- `SO/Resources/views/partials/workflow.blade.php:357-408` bloco "7. Nota fiscal e pagamento para a saída" (NF só por número, pagamento, acordo, selo de frota). `:19-21` filtro de eventos restritos da linha do tempo; `:496-498` mostra a justificativa de `bloqueio_forcado` a todos, inclusive ao Técnico.
- `SO/Models/ServiceOrderAttachment.php:14-18` tipos de anexo; a coluna `tipo` é `string(30)` livre (`SO/Database/Migrations/2026_11_16_100100_add_workflow_columns_to_quotes_table.php:72`), então um tipo novo não pede migration.

### Alertas críticos
- `SO/Models/BarrierRule.php:296-307` `critico_bloqueio_forcado` (gerente; o dono entra pela escalada quando `escala_dono`) e `:309-318` `critico_saida_sem_nota` (gerente e proprietário, condição `sem_nf`). `SO/Services/BarrierEngine.php:257-291` `openImmediate()`. `SO/Services/AlertService.php:37-88` `fire()` e `:238-247` `mensagem()` (texto sem valores em R$ e sem crédito).
- Teste existente: `tests/Feature/Workshop/StageGateTest.php:196-224` (forçar a saída gera os dois códigos de alerta crítico para gerente e proprietário e nenhum para o consultor).

### Travas
- `SO/Services/Bonus/BonusCatalog.php:72` trava `liberado_sem_quitacao` (consultor) e `:102` `saida_sem_nf` (financeiro).
- `SO/Services/Bonus/TravaService.php:136-170` `avancosForcados()`: só roda dentro de `detectar()` (fechamento do mês), só cria trava para o colaborador de quem forçou (`:143-147`) e só se ele tiver o cargo (`:163`). Quando o gerente força a saída, nenhuma trava de consultor nem de financeiro nasce. `:233-252` `criarAutomatica()` (idempotente por `chave_unica`, limite de 120 caracteres). `:65-93` `decidir()` (gerente confirma; a do gerente é do dono).
- Teste existente: `tests/Feature/Workshop/BonusTest.php:206-246`.

### Indicadores e relatório diário
- FIN-02 "NF antes da saída": `SO/Services/Indicators/IndicatorCalculator.php:152` e `:874-880` `nfAntesDaSaida()` (conta `saida_veiculo` sem `sem_nf`, EXCLUINDO a frota). Catálogo: `SO/Services/Indicators/IndicatorCatalog.php:112`.
- CON-07 "OS fechada e enviada ao financeiro no dia": `IndicatorCalculator.php:88` lê a barreira `pronto_nao_faturado` (`SO/Models/BarrierRule.php:240-252`, de `checklist_saida` até `os_enviada_financeiro`). Catálogo `IndicatorCatalog.php:48`.
- Relatório diário do financeiro ao dono: `SO/Services/Financeiro/FinanceiroService.php:268-284` `relatorioDoDia()`, `:289-331` `operacao()`; o "selo" é o campo `saidas_sem_nf` (`:316-317`, `:328`), contado a partir dos eventos `saida_veiculo` com `sem_nf`; aparece em `SO/Resources/views/financeiro/relatorios.blade.php:54-60` ("saídas sem NF (deve ser zero)", vermelho quando maior que zero) e entra no `snapshot_json` gravado em `:358` quando o financeiro envia. Painel do Dono: FIN-02 e CON-07 aparecem na etapa "07 Faturamento" (`SO/Services/Indicators/PainelDonoService.php:35`).
- `SO/Services/Rotina/RotinaService.php:213-214` ("OS prontas a faturar" do financeiro) só olha `nf_manual_em`; uma nota autorizada pela Focus continua aparecendo como "emitir nota fiscal".

### Asaas (roteiro linha 49)
- Cobrança gerada pelo sistema: `SO/Http/Controllers/ServiceOrderStageController.php:516-551` `storeBoletoViaAsaas()` -> `SO/Services/AsaasClient.php:46-71` `createCharge()`; grava o evento `boleto_ou_cobranca_gerada` (`:543`). Cobrança manual: `:489-509`.
- Webhook: `SO/Http/Controllers/AsaasWebhookController.php:25-51` atualiza o boleto (`AsaasClient::mapStatus`, `:108-115`) e, se "Pago", chama `WorkflowService::confirmPayment($os, 'asaas')`. Rota pública `SO/Routes/public.php:41-43` (`POST /service-orders/asaas/webhook`). O token (`config('asaas.webhook_token')`, `SO/Config/asaas.php:20`) só é conferido se estiver preenchido. Marcar o boleto como "Pago" à mão também confirma (`ServiceOrderStageController.php:563-565`).
- Conclusão da verificação: o "status muda sozinho" JÁ funciona no código. Faltam teste, endurecimento do token e o caso do pagamento parcial (um boleto de entrada pago já libera a saída).

### Divergência de peças (roteiro linha 46)
- `SO/Services/WorkflowActions.php:163-171` ação `conferir_pecas` (Faturamento) e `:485-495` `checkParts()` gravam `pecas_conferidas_em` e o evento `pecas_conferidas` com `divergencia` verdadeiro ou falso. O indicador PEC-06 lê isso (`IndicatorCalculator.php:774-786`). Nada impede faturar quando há divergência: não existe bloqueio.

### Permissões e dados sensíveis
- `SO/Services/ValoresFinanceiros.php:20-57` (`can()` verdadeiro com piloto desligado; `authorize()` devolve 403 e registra no log). `SO/Config/acl.php:72-76` `service_orders.forcar_avanco`; `:77-83` `ver_valores_financeiros`.
- `SO/Http/Middleware/DonoForaDoFluxo.php:29` grava `acao_dono_fora_fluxo` para qualquer rota que comece com `admin.service_orders.` e tenha `{id}`. Por isso a rota nova do cadastro do cliente NÃO pode usar esse prefixo (o `{id}` seria o id da pessoa).
- `SO/Http/Middleware/RegistraAcessoSensivel.php:17-19` lista de prefixos de rota cujo acesso fica registrado (LGPD).
- O resultado de busca de pessoas (`packages/Webkul/Admin/src/Http/Resources/PersonResource.php:18-26`) tem campos explícitos: uma coluna nova em `persons` não vaza por JSON.

### Testes existentes que esta frente toca
- `tests/Feature/Workshop/StageGateTest.php:159-183` (critério 5), `:185-194` (frota "sai sem NF e sem pagamento": vai mudar), `:196-224`.
- `tests/Feature/Workshop/HttpFlowTest.php:202-219` (boleto marcado como Pago libera a saída; segue valendo porque a OS do teste tem total zero).
- `tests/Feature/Workshop/TecnicoValoresTest.php:139-140` (técnico leva 403 em `workflow.nf` e `workflow.payment`).
- Não existe nenhum teste de Fiscal/Focus nem do webhook do Asaas.

---

## Requisitos do PDF → como serão atendidos

| # | Requisito (PDF B3 / task) | Situação hoje | Como será atendido |
|---|---|---|---|
| 1 | Emitir NFS-e (serviço) e NF-e (peças) por emissor via API, reaproveitando o pacote Fiscal | PARCIAL: integração e telas de confirmação existem; só aparecem com a OS "Concluída"; sem consulta de situação, sem guarda de duplicidade, sem `ValoresFinanceiros`, erro HTTP vira "processando" | Bloco de nota no Faturamento com "Emitir NFS-e" e "Emitir NF-e de peças" (rotas existentes), "Atualizar situação" (rota nova), guarda de duplicidade, `ValoresFinanceiros` e etapa mínima nas rotas fiscais, tratamento de erro HTTP no `FocusNfeClient` |
| 2 | Enquanto a integração não estiver pronta: "Número da nota" obrigatório + anexo XML/PDF, registrado pelo Financeiro | PARCIAL: só o número; qualquer um com `service_orders.edit` registra; sobrescreve sem rastro | `registerNf()` exige número, tipo da nota e 1 a 3 arquivos XML/PDF; só quem tem `fiscal_documents.emit` registra (piloto ligado); segundo registro só com justificativa e gera evento `correcao`; arquivos nunca são apagados; download autenticado |
| 3 | Botão "Registrar saída do caminhão" no Faturamento; com piloto ligado só libera com nota registrada E (pagamento OU acordo OU cliente frota com faturamento mensal marcado no cadastro) | PARCIAL: o gate existe, mas o botão é o "Avançar" genérico e a frota isenta também a nota | Botão "Registrar saída do caminhão" (no topo da OS e no bloco do Faturamento) usando o mesmo `StageAdvanceService`; `faturamentoParaSaida()` passa a exigir nota SEMPRE (exceto retorno/garantia sem cobrança) e a dispensar só o pagamento para a frota |
| 4 | Marcação "frota com faturamento mensal" no cadastro do cliente, visível só para quem vê financeiro | FALTA (só existe o texto livre `vehicles.frota`) | Coluna `persons.faturamento_mensal`, tabela de histórico, cartão "Faturamento do cliente" na página do cliente (gancho de view, sem editar o pacote Admin), rota própria com permissão nova e motivo obrigatório |
| 5 | Forçar a saída: justificativa obrigatória | JÁ EXISTE (`StageAdvanceService.php:48-50`) | Mantido; texto de confirmação e mensagens específicas da saída |
| 6 | Forçar a saída: avisa Gerente e Proprietário na hora (alerta crítico, sem R$) | JÁ EXISTE (`critico_bloqueio_forcado` + `critico_saida_sem_nota`) | Mantido sem mudar barreiras; teste novo confere destinatários, ausência de "R$" e que o consultor não recebe |
| 7 | Forçar a saída: trava pendente para o Consultor e o Financeiro | PARCIAL: só no fechamento do mês e só para quem forçou | `TravaService::registrarSaidaForcada()` cria as travas na hora (consultor da OS e financeiros ativos), idempotente com a detecção do mês |
| 8 | Alimenta FIN-02, CON-07 e o selo "saídas sem NF" do relatório diário | JÁ EXISTE; ajustes pequenos | FIN-02 passa a incluir a frota (nota obrigatória) e a excluir retorno/garantia sem cobrança; `saida_veiculo` ganha `sem_pagamento`, `excecao_frota`, `sem_cobranca`, `forcada`; relatório lista as OS do selo; CON-07 coberto por teste de ponta a ponta |
| 9 | Roteiro 49: nota + cobrança pelo Asaas; quando o pagamento entra o status muda sozinho | EXISTE no código, sem teste | Teste com `Http::fake`; webhook recusa sem token com o piloto ligado; pagamento parcial não confirma sozinho; origem e hora do pagamento aparecem no bloco |
| 10 | Roteiro 46: divergência entre peças aplicadas e lançadas impede faturar até Peças corrigir | FALTA (só registra o evento) | `StageGate::faturamentoBloqueio()` barra registro de NF, emissão fiscal e geração de cobrança; entra na lista do que falta para a saída; só Peças, gerente ou proprietário limpam; alerta para Peças e gerente |
| 11 | Roteiro 50: frota com faturamento mensal sai só com a nota | CONTRADIZ o código atual (isenta a nota) | Corrigido em `faturamentoParaSaida()`; teste novo e reescrita do teste `StageGateTest.php:185` |
| 12 | Teste do PDF: saída sem nota bloqueada; forçada com justificativa gera alerta crítico e trava pendente | Sem trava na hora | Testes T01 e T03 abaixo |

---

## Mudanças por arquivo

### Criar

1. **`SO/Database/Migrations/2026_11_18_090100_add_faturamento_mensal_to_clientes.php`** (ver seção Banco).
2. **`SO/Models/ClienteFaturamentoHistorico.php`**: histórico só de inserção (`UPDATED_AT = null`; `updating` e `deleting` lançam `LogicException('O histórico do faturamento mensal não pode ser alterado; registre uma nova mudança.')`). Tabela `cliente_faturamento_historico`.
3. **`SO/Services/ClienteFaturamento.php`**
   ```php
   class ClienteFaturamento
   {
       protected static ?bool $coluna = null;
       protected static ?bool $historico = null;

       public static function colunaDisponivel(): bool   // Schema::hasColumn('persons', 'faturamento_mensal'), memoizado
       public static function disponivel(): bool         // coluna E tabela de histórico
       public static function esquecer(): void           // gancho de teste: zera a memória das duas verificações

       /** O cliente da OS está marcado como frota com faturamento mensal? Sem a coluna: sempre falso. */
       public static function mensal(?\Webkul\Contact\Models\Person $pessoa): bool

       /** Marca ou desmarca. Devolve false se já estava assim. Motivo obrigatório; grava o histórico e atualiza a coluna na mesma transação. */
       public static function definir(Person|int $pessoa, bool $mensal, string $motivo, ?User $por = null): bool

       public static function ultimoRegistro(int $personId): ?ClienteFaturamentoHistorico
       public static function veiculosComFrota(int $personId): int   // Vehicle com `frota` preenchido, só para a dica da tela
   }
   ```
   Detalhes: atualizar a coluna por `Person::query()->whereKey($id)->update(['faturamento_mensal' => $mensal])`, nunca por `$person->update()` (o Eloquent tentaria gravar atributos EAV como `cpf_cnpj`; mesmo cuidado do `AsaasClient.php:93-97`). Sem a coluna, `definir()` lança `ValidationException` com "Atualize o banco (migrate-safe) antes de marcar o cliente." e `mensal()` devolve falso (nunca isenta ninguém).
4. **`SO/Http/Controllers/ClienteFaturamentoController.php`** (estende o `Controller` do Admin)
   ```php
   public function update(int $personId): RedirectResponse
   {
       ValoresFinanceiros::authorize('faturamento mensal do cliente');   // Técnico: 403, registrado no log

       $person = Person::findOrFail($personId);
       $this->preventUnauthorizedAccess($person->user_id);

       $this->validate(request(), [
           'faturamento_mensal' => 'required|boolean',
           'justificativa' => 'required|string|min:3|max:300',
       ], ['justificativa.required' => 'Informe o motivo (por exemplo, o contrato de frota).']);

       try {
           $mudou = ClienteFaturamento::definir($person, request()->boolean('faturamento_mensal'), request('justificativa'));
           session()->flash('success', trans($mudou ? 'service_order::app.cliente_faturamento.salvo' : 'service_order::app.cliente_faturamento.sem-mudanca'));
       } catch (ValidationException $e) {
           session()->flash('error', collect($e->errors())->flatten()->first());
       }

       return redirect()->route('admin.contacts.persons.view', $personId);
   }
   ```
5. **`SO/Services/NotaFiscalService.php`**: tira a lógica de leitura das telas.
   ```php
   class NotaFiscalService
   {
       public const TIPOS_MANUAIS = ['servico' => 'Serviços (NFS-e)', 'peca' => 'Peças (NF-e)', 'unica' => 'Serviços e peças (nota única)'];
       public const ETAPAS_DE_NOTA = [ServiceOrder::STAGE_FATURAMENTO, ServiceOrder::STAGE_POS_VENDA, ServiceOrder::STAGE_CONCLUIDA];

       public function integracaoPronta(string $tipo): bool       // 'nfse' | 'nfe': Schema::hasTable('fiscal_settings') && ApiKey::focusNfeToken() && FiscalSettings::current()->isReadyFor...()
       public function integracaoFaltando(string $tipo): array    // frases simples: 'chave da Focus NFe (Configurações > Chaves de API)', 'dados fiscais da oficina (Configurações Fiscais)'
       public function documentos(ServiceOrder $os): array        // ['nfse' => ?FiscalDocument, 'nfe' => ?FiscalDocument] (o mais recente de cada tipo)
       public function registroManual(ServiceOrder $os): ?array   // número, quando, quem, tipo_nota, anexos (último evento nf_emitida de origem manual)
       public function avisoPecasSemNfe(ServiceOrder $os): bool   // há peças lançadas e só há NFS-e/nota de serviço
       public function emissaoPermitidaNaEtapa(ServiceOrder $os): bool
   }
   ```
6. **`SO/Resources/views/partials/faturamento-saida.blade.php`**: o novo conteúdo do bloco 7 (nota, pagamento, acordo, frota, divergência e saída). Estrutura e `data-testid` na seção "Textos da interface".
7. **`SO/Resources/views/partials/cliente-faturamento.blade.php`**: cartão "Faturamento do cliente" da página do cliente.
8. **`tests/Feature/Workshop/SaidaNotaFiscalTest.php`** (ver seção Testes).

### Alterar (por área)

**A. Gate e avanço da saída**

- **`SO/Models/ServiceOrder.php:333-339`**: `isFrotaFaturamentoMensal()` passa a ser `return ClienteFaturamento::mensal($this->person);`. Atualizar o docblock: "Cliente de frota com faturamento mensal (marcação no cadastro do cliente): dispensa só o pagamento/acordo; a nota fiscal continua obrigatória."
- **`SO/Services/StageGate.php`** (arquivo compartilhado; editar por método):
  ```php
  public const PERFIS_CORRIGEM_DIVERGENCIA = ['pecas', 'gerente', 'proprietario'];

  /** Retorno/garantia sem cobrança (total zero): não gera nota nem pagamento. Ponto de extensão da F12. */
  public function saidaSemCobranca(ServiceOrder $os): bool
  {
      return in_array($os->tipo_os, ['retorno', 'garantia'], true) && (float) $os->grand_total <= 0;
  }

  public function exigeNota(ServiceOrder $os): bool { return ! $this->saidaSemCobranca($os); }

  public function exigePagamento(ServiceOrder $os): bool
  {
      return ! $this->saidaSemCobranca($os) && ! $os->isFrotaFaturamentoMensal();
  }

  /** As cobranças pagas cobrem o total da OS (tolerância de 1 centavo)? Pagamento parcial não conta. */
  public function pagamentoCobertoPorCobrancas(ServiceOrder $os): bool
  {
      $pago = round((float) $os->boletos()->where('status', ServiceOrderBoleto::STATUS_PAGO)->sum('valor'), 2);

      return $pago > 0 && $pago + 0.01 >= round((float) $os->grand_total, 2);
  }

  public function hasPagamentoOuAcordo(ServiceOrder $os): bool   // substitui o corpo atual (:80-87)
  {
      return (bool) ($os->pagamento_confirmado_em || $os->acordo_assinado_em) || $this->pagamentoCobertoPorCobrancas($os);
  }

  /** A última conferência de peças (pecas_conferidas) registrou divergência e ninguém registrou a correção? */
  public function divergenciaDePecasPendente(ServiceOrder $os): bool
  {
      $ultima = ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'pecas_conferidas')
          ->orderByDesc('ocorrido_em')->orderByDesc('id')->first();

      return $ultima !== null && ! empty($ultima->dados_json['divergencia']);
  }

  public function podeCorrigirDivergencia(?User $user = null): bool   // perfil_oficina em PERFIS_CORRIGEM_DIVERGENCIA (sem atalho por "permissão total", como AlcadaService::levelOf)

  /** Mensagem que barra o faturamento agora (registrar nota, emitir nota, gerar cobrança), ou null. Só com o piloto ligado e só na etapa Faturamento (depois da saída a regularização não pode travar). */
  public function faturamentoBloqueio(ServiceOrder $os): ?string
  {
      if (! static::active() || $os->workshop_status !== ServiceOrder::STAGE_FATURAMENTO) {
          return null;
      }

      return $this->divergenciaDePecasPendente($os)
          ? 'Há divergência entre as peças aplicadas e as lançadas. O faturamento fica parado até Peças corrigir e registrar a conferência sem divergência.'
          : null;
  }

  protected function faturamentoParaSaida(ServiceOrder $os): array   // substitui :288-310; os textos antigos ficam IGUAIS (os testes dependem deles)
  {
      $missing = [];

      if (! $os->checklist_saida_em) {
          $missing[] = 'Checklist de saída com horas reais e próxima revisão';
      }

      if ($this->divergenciaDePecasPendente($os)) {
          $missing[] = 'Divergência entre peças aplicadas e lançadas: Peças precisa corrigir';
      }

      if ($this->exigeNota($os) && ! $this->hasNotaFiscal($os)) {
          $missing[] = 'Nota fiscal emitida';
      }

      if ($this->exigePagamento($os) && ! $this->hasPagamentoOuAcordo($os)) {
          $missing[] = 'Pagamento confirmado ou acordo assinado';
      }

      return $missing;
  }
  ```
  Ordem das mensagens preservada ("Checklist...", "Nota fiscal emitida", "Pagamento confirmado ou acordo assinado"), como esperam `StageGateTest.php:165-174`. Imports novos: `ServiceOrderBoleto`, `ServiceOrderEvent` (já importado), `Webkul\User\Models\User`.
- **`SO/Services/StageAdvanceService.php:34-103`** (arquivo compartilhado):
  - guardar o retorno do `ServiceOrderEvent::record(... 'bloqueio_forcado' ...)` em `$eventoForcado`;
  - substituir `$semNf` por um array calculado ANTES de mudar a etapa (usa o estado atual):
    ```php
    $saida = $this->gate->isSaida($serviceOrder, $toStage) ? [
        'sem_nf' => $this->gate->exigeNota($serviceOrder) && ! $this->gate->hasNotaFiscal($serviceOrder),
        'sem_pagamento' => $this->gate->exigePagamento($serviceOrder) && ! $this->gate->hasPagamentoOuAcordo($serviceOrder),
        'excecao_frota' => $serviceOrder->isFrotaFaturamentoMensal(),
        'sem_cobranca' => $this->gate->saidaSemCobranca($serviceOrder),
        'forcada' => $forcado,
    ] : [];
    ```
  - `afterArriving(ServiceOrder $os, string $from, string $to, array $saida, ?ServiceOrderEvent $forcado)`: no ramo Faturamento -> Pós-Venda grava `saida_em` e `saida_veiculo` com `$saida` (o campo `sem_nf` continua lá, então FIN-02, a barreira `critico_saida_sem_nota` e o relatório seguem funcionando) e, se `$forcado`, chama `app(TravaService::class)->registrarSaidaForcada($forcado)`. A trava só nasce depois de a OS ter mudado de etapa (sem trava para saída que não aconteceu). O alerta continua disparando na gravação do evento, como hoje.
- **`SO/Services/Bonus/TravaService.php`** (arquivo compartilhado):
  ```php
  protected static ?bool $disponivel = null;   // Schema::hasTable('bonus_travas') && Schema::hasTable('colaboradores'), memoizado

  /**
   * Saída do caminhão forçada (Faturamento -> Pós-Venda): trava pendente, na hora, para o consultor da OS e para
   * o financeiro. O gerente confirma ou rejeita em Bônus > Travas. Idempotente (chave_unica).
   *
   * @return int Quantidade de travas novas
   */
  public function registrarSaidaForcada(ServiceOrderEvent $forcado): int
  {
      if (! $this->disponivel()) {
          return 0;
      }

      $periodo = Carbon::parse($forcado->ocorrido_em)->format('Y-m');
      $os = ServiceOrder::find($forcado->service_order_id);
      $faltava = implode('; ', (array) ($forcado->dados_json['faltava'] ?? []));
      $novas = 0;

      foreach ($this->responsaveisDaSaida($os, $forcado->usuario_id) as [$colaborador, $cargo, $tipo]) {
          $novas += $this->criarAutomatica(
              $periodo, $colaborador, $cargo, $tipo,
              "saida:{$forcado->id}:{$cargo}:{$colaborador->id}",
              "Saída do caminhão forçada na OS #{$forcado->service_order_id}. Faltava: {$faltava}. Justificativa: ".($forcado->dados_json['justificativa'] ?? ''),
              $forcado->service_order_id,
          );
      }

      return $novas;
  }

  /** @return list<array{0: Colaborador, 1: string, 2: string}> */
  protected function responsaveisDaSaida(?ServiceOrder $os, ?int $forcouUserId): array
  {
      // Consultor: quem abriu a OS, se for colaborador ativo com o cargo; senão quem forçou, se tiver o cargo.
      $consultor = ($os?->user_id ? Colaborador::ativos()->where('user_id', $os->user_id)->comCargo('consultor')->first() : null)
          ?? ($forcouUserId ? Colaborador::ativos()->where('user_id', $forcouUserId)->comCargo('consultor')->first() : null);

      $lista = $consultor ? [[$consultor, 'consultor', 'liberado_sem_quitacao']] : [];

      foreach (Colaborador::ativos()->comCargo('financeiro')->get() as $financeiro) {
          $lista[] = [$financeiro, 'financeiro', 'saida_sem_nf'];
      }

      return $lista;
  }
  ```
  Em `avancosForcados()` (`:136-170`), no TOPO do `foreach`, ANTES de buscar o colaborador de quem forçou (que hoje faz `continue` quando o gerente não é colaborador com o cargo):
  ```php
  if ($evento->etapa_origem === 'Faturamento' && $evento->etapa_destino === 'Pós-Venda') {
      $novas += $this->registrarSaidaForcada($evento);

      continue;
  }
  ```
  e remover do `match` o braço `$destino === 'Pós-Venda' => [...]` (`:155`). Assim a detecção mensal e a trava imediata usam a mesma regra e a mesma `chave_unica` (nada duplica).
  Sem consultor identificável (OS aberta por quem não é consultor e quem forçou também não é), só a trava do financeiro nasce; o alerta crítico já avisou gerente e dono. Não se pune "todos os consultores".

**B. Registro de nota, pagamento e divergência**

- **`SO/Models/ServiceOrderAttachment.php`**: `public const TIPO_NOTA_FISCAL = 'nota_fiscal';`.
- **`SO/Services/WorkflowService.php:369-392`**:
  ```php
  public function registerNf(ServiceOrder $os, string $numero, array $arquivos, string $tipoNota = 'unica', ?string $justificativa = null): void
  ```
  Regras, nesta ordem (todas com `ValidationException::withMessages`):
  1. `trim($numero) !== ''` ("Informe o número da nota fiscal.");
  2. `$tipoNota` em `NotaFiscalService::TIPOS_MANUAIS`;
  3. com o piloto ligado, a OS precisa estar em `NotaFiscalService::ETAPAS_DE_NOTA` ("A nota fiscal é registrada a partir da etapa de Faturamento.");
  4. `array_filter($arquivos, fn ($a) => $a instanceof UploadedFile && $a->isValid())` não vazio ("Anexe o XML ou o PDF da nota fiscal.");
  5. `$this->gate->faturamentoBloqueio($os)` (divergência de peças) vira erro;
  6. se `nf_manual_em` já existe, exige `$justificativa` ("A nota já foi registrada. Para registrar de novo (correção), informe a justificativa.").
  Depois: `storeAttachment($os, ServiceOrderAttachment::TIPO_NOTA_FISCAL, $arquivo)` para cada arquivo; `forceFill(['nf_manual_numero' => $numero, 'nf_manual_em' => now()])`; `ServiceOrderEvent::record($os, 'nf_emitida', ['origem' => 'manual', 'numero' => $numero, 'tipo_nota' => $tipoNota, 'arquivos' => count])`; se havia registro anterior, `ServiceOrderEvent::correct($anterior, $justificativa, ['nova_nota' => $numero])` (o evento anterior é o último `nf_emitida` com `dados_json['origem'] === 'manual'`, filtrado em PHP, sem consulta JSON). Os arquivos antigos ficam (nada se apaga).
  Novo método:
  ```php
  /** Cobrança paga: confirma o pagamento da OS só quando as cobranças pagas cobrem o total. Devolve se a OS ficou paga. */
  public function syncPayment(ServiceOrder $os, string $origem): bool
  {
      if ($os->pagamento_confirmado_em) {
          return true;
      }

      if (! $this->gate->pagamentoCobertoPorCobrancas($os)) {
          return false;
      }

      $this->confirmPayment($os, $origem);

      return true;
  }
  ```
  `confirmPayment()` continua sendo o botão explícito "Confirmar pagamento" do Financeiro (vale sempre).
- **`SO/Http/Controllers/WorkflowController.php:164-173`** `registerNf()` reescrito e método novo `nfArquivo()`:
  ```php
  public function registerNf(int $id): RedirectResponse
  {
      ValoresFinanceiros::authorize('nota fiscal');    // continua sendo a 1ª linha (Técnico: 403 antes de validar)

      abort_if(StageGate::active() && ! bouncer()->hasPermission('fiscal_documents.emit'), 403, trans('service_order::app.nota_fiscal.sem-permissao'));

      $os = $this->order($id);

      $this->validate(request(), [
          'numero' => ['required', 'string', 'max:60', 'regex:/^[0-9A-Za-z\/.\-]+$/'],
          'tipo_nota' => ['required', 'in:servico,peca,unica'],
          'arquivos' => ['required', 'array', 'min:1', 'max:3'],
          'arquivos.*' => ['file', 'mimes:xml,pdf', 'max:5120'],
          'justificativa' => ['nullable', 'string', 'max:300'],
      ], [ /* mensagens da seção Textos */ ]);

      return $this->run($id, fn () => $this->workflow->registerNf($os, request('numero'), request()->file('arquivos', []), request('tipo_nota'), request('justificativa')), trans('service_order::app.nota_fiscal.registrada'));
  }

  /** GET service-orders/{id}/workflow/nf/{arquivoId}: baixa o XML/PDF da nota registrada à mão. */
  public function nfArquivo(int $id, int $arquivoId)
  {
      ValoresFinanceiros::authorize('arquivo da nota fiscal');
      $os = $this->order($id);
      $arquivo = ServiceOrderAttachment::where('quote_id', $os->id)->where('tipo', ServiceOrderAttachment::TIPO_NOTA_FISCAL)->findOrFail($arquivoId);
      abort_unless(Storage::disk('public')->exists($arquivo->path), 404);

      return Storage::disk('public')->download($arquivo->path, $arquivo->nome_original ?: basename($arquivo->path));
  }
  ```
  A tela nunca imprime `Storage::url()` do arquivo da nota; só a rota autenticada.
- **`SO/Services/WorkflowActions.php:485-495`** `checkParts()`:
  - com o piloto ligado: divergência "sim" exige `observacao` ("Descreva a divergência encontrada."); "nao" quando há divergência pendente exige `observacao` ("Descreva o que foi corrigido.") e `StageGate::podeCorrigirDivergencia()` ("Só Peças (ou o gerente) registra a correção da divergência.");
  - ao registrar divergência (piloto ligado): `app(AlertService::class)->raise('divergencia_pecas', 'Divergência de peças', "ALERTA - Divergência entre peças aplicadas e lançadas. OS #{$os->id} - {placa} - {cliente}. O faturamento fica parado até Peças corrigir.", ['pecas', 'gerente'], $os)` (não crítico; o dono nunca entra);
  - retorno: "Divergência registrada. O faturamento fica parado até Peças corrigir." ou o texto atual "Conferência de peças registrada.".
  Nenhuma mudança em `definitions()['conferir_pecas']` (o campo `observacao` segue "opcional" no formulário; a regra é do servidor).
- **`SO/Http/Controllers/ServiceOrderStageController.php`**:
  - `storeBoleto()` (`:489`) e `storeBoletoViaAsaas()` (`:516`): depois de achar a OS, `if ($bloqueio = app(StageGate::class)->faturamentoBloqueio($os)) { session()->flash('error', $bloqueio); return redirect()->...; }` (a primeira não carrega a OS hoje: usar `$this->serviceOrderRepository->findOrFail($id)`).
  - `updateBoletoStatus()` (`:563-565`): trocar `confirmPayment(..., 'cobranca')` por `$pago = app(WorkflowService::class)->syncPayment($os, 'cobranca')`; se `request('status') === 'Pago'` e `! $pago`, `session()->flash('warning', trans('service_order::app.saida.pagamento-parcial'))`.
- **`SO/Http/Controllers/AsaasWebhookController.php:25-51`**:
  ```php
  $configuredToken = config('asaas.webhook_token');

  if (! $configuredToken && StageGate::active()) {
      Log::warning('Webhook do Asaas recusado: ASAAS_WEBHOOK_TOKEN não configurado.');

      return response()->json(['message' => 'Webhook sem token configurado'], 503);
  }

  if ($configuredToken && ! hash_equals((string) $configuredToken, (string) $request->header('asaas-access-token'))) {
      return response()->json(['message' => 'Invalid token'], 401);
  }
  // ... atualiza o boleto como hoje ...
  if ($boleto->status === 'Pago' && ($serviceOrder = ServiceOrder::find($boleto->quote_id))) {
      app(WorkflowService::class)->syncPayment($serviceOrder, 'asaas');
  }
  ```
  Piloto desligado: continua aceitando sem token, como hoje.
- **`SO/Http/Controllers/ServiceOrderController.php:75-115`** `updateStage()`:
  ```php
  $gate = app(StageGate::class);
  $ehSaida = $gate->isSaida($serviceOrder, (string) request('stage'));

  if ($ehSaida) {
      ValoresFinanceiros::authorize('saída do caminhão');   // logo depois de achar a OS, antes do canAdvanceTo
  }
  ...
  session()->flash('success', match (true) {
      $ehSaida && $result['forcado'] => trans('service_order::app.saida.forcada-ok'),
      $ehSaida => trans('service_order::app.saida.ok'),
      $result['forcado'] => 'Ordem de serviço avançada à força para "'.request('stage').'". O gerente foi avisado.',
      default => 'Ordem de serviço avançada para "'.request('stage').'".',
  });
  ```
  Não há rota nova para registrar a saída: o botão usa `admin.service_orders.stage.update` com `stage=Pós-Venda` (e `forcar=1` + `justificativa` no forçado). Isso evita nova chave de ACL e mantém `DonoForaDoFluxo`, `canAdvanceTo` e o observador funcionando.

**C. Pacote Fiscal**

- **`FIS/Http/Controllers/FiscalController.php`**:
  - helper `protected function bloqueioDeFaturamento(ServiceOrder $os): ?RedirectResponse`: `ValoresFinanceiros::authorize('nota fiscal')`; com o piloto ligado e etapa fora de `NotaFiscalService::ETAPAS_DE_NOTA`, flash "A nota fiscal é emitida a partir da etapa de Faturamento."; `StageGate::faturamentoBloqueio($os)`; devolve o redirect para `admin.service_orders.view` com o erro, ou null. Chamar logo depois de carregar a OS nos 4 métodos `create`, `emitForServiceOrder`, `createNfe`, `emitNfeForServiceOrder`.
  - guarda de duplicidade `protected function documentoEmAndamento(int $osId, string $type): bool`: existe `FiscalDocument` da OS e do tipo com status fora de `[STATUS_ERRO, STATUS_CANCELADO]` -> flash "Esta OS já tem uma NFS-e (ou NF-e) em andamento ou autorizada. Atualize a situação em vez de emitir de novo." Vale nas telas de confirmação e nos POSTs. É correção de segurança e vale também com o piloto desligado (só atua no caso de duplicidade).
  - método novo:
    ```php
    public function consult(int $id): RedirectResponse
    {
        ValoresFinanceiros::authorize('nota fiscal');
        $document = FiscalDocument::findOrFail($id);

        try {
            $this->focusNfeClient->consultStatus($document);
            session()->flash('success', trans('fiscal::app.consult-result', ['status' => $document->refresh()->status]));
        } catch (\Exception $exception) {
            session()->flash('error', trans('fiscal::app.consult-failed', ['message' => $exception->getMessage()]));
        }

        return $document->quote_id
            ? redirect()->route('admin.service_orders.view', $document->quote_id)
            : redirect()->route('admin.fiscal_documents.view', $document->id);
    }
    ```
- **`FIS/Services/FocusNfeClient.php`**:
  - `emit()` (`:138-146`): se `$response->failed()`, marcar o documento `STATUS_ERRO`, `last_error = $body['mensagem'] ?? $body['codigo'] ?? 'Resposta HTTP '.$response->status().' da Focus NFe.'`, gravar o log `response_received` com `http_status`, devolver o documento (o usuário pode tentar de novo; a guarda de duplicidade não conta documento em erro).
  - `consultStatus()` (`:74-85`): se a resposta falhar, gravar o log e lançar `\RuntimeException` com a mensagem da Focus (o documento não muda).
  - Atualizar o docblock `:14-19` (NF-e já está ligada à tela).
- **`FIS/Http/Controllers/FocusNfeWebhookController.php:22-28`**: com o piloto ligado e `focusnfe.webhook_secret` vazio, responder 503 e registrar no log (mesmo critério do Asaas); comparar com `hash_equals`. Piloto desligado: como hoje.
- **`FIS/Routes/web.php`** (dentro do grupo `fiscal-documents`): `Route::post('{id}/consultar', 'consult')->name('admin.fiscal_documents.consult');`
- **`FIS/Config/acl.php:20-28`**: incluir `'admin.fiscal_documents.consult'` na lista de rotas de `fiscal_documents.emit`.
- **`FIS/Resources/lang/pt_BR/app.php` e `en/app.php`**: chaves `consult-btn`, `consult-result`, `consult-failed`, `duplicada` (ver Textos).

**D. Indicadores, relatório diário e rotina**

- **`SO/Services/Indicators/IndicatorCalculator.php:874-880`** `nfAntesDaSaida()`:
  ```php
  $todas = $this->osComEvento('saida_veiculo');
  $events = new EventIndex($todas->pluck('id')->all(), ['saida_veiculo']);
  // Retorno/garantia sem cobrança não gera nota: fora da conta. A frota entra (a nota é obrigatória).
  $os = $todas->reject(fn ($item) => ! empty($events->first($item->id, 'saida_veiculo')?->dados_json['sem_cobranca']))->values();

  return $this->taxa($os, fn ($item) => empty($events->first($item->id, 'saida_veiculo')?->dados_json['sem_nf']));
  ```
- **`SO/Services/Indicators/IndicatorCatalog.php:112`**: fórmula "Saídas com NF ÷ saídas faturadas (retorno/garantia sem cobrança fora da conta)". Bancos já semeados mantêm o texto antigo (só cosmético; `IndicatorDefinition::ensureDefaults` não reescreve linhas existentes).
- **`SO/Services/Financeiro/FinanceiroService.php:316-328`**: guardar a coleção filtrada e devolver também `'saidas_sem_nf_os' => $eventos->pluck('service_order_id')->unique()->values()->all()` (o teste existente só confere `toHaveKeys`, então chaves novas não quebram).
- **`SO/Resources/views/financeiro/relatorios.blade.php:58`**: o "selo" vira etiqueta: verde "0 saídas sem NF" ou vermelha "N saída(s) sem NF" seguida dos links `OS #id` (rota `admin.service_orders.view`).
- **`SO/Services/Rotina/RotinaService.php:213-214`** `doFinanceiro()`: o texto de cada OS pronta a faturar passa a vir do gate:
  ```php
  $gate = app(StageGate::class);
  $acao = match (true) {
      $gate->divergenciaDePecasPendente($os) => 'aguardando Peças corrigir a divergência',
      $gate->exigeNota($os) && ! $gate->hasNotaFiscal($os) => 'emitir nota fiscal',
      $gate->exigePagamento($os) && ! $gate->hasPagamentoOuAcordo($os) => 'aguardando pagamento ou acordo',
      default => 'liberada: registrar a saída do caminhão',
  };
  ```
  Não mexer em `dePecas()` (é da F10/F11); a F10 deve listar as OS com divergência chamando `StageGate::divergenciaDePecasPendente()`.

**E. Telas**

- **`SO/Resources/views/partials/workflow.blade.php`**:
  - substituir o bloco 7 inteiro (`:357-408`) por `@if ($canSeeValues) @include('service_order::partials.faturamento-saida', ['serviceOrder' => $serviceOrder]) @endif` (um único ponto de edição para reduzir conflito com as outras frentes que mexem neste arquivo);
  - `:21`: além dos `$eventosRestritos`, rejeitar para quem não vê valores o evento `bloqueio_forcado` com `etapa_origem === 'Faturamento'` (a justificativa pode citar dinheiro). A F05 deve avaliar o mesmo para `Negociação`.
- **`SO/Resources/views/partials/faturamento-saida.blade.php`** (novo; blocos nesta ordem, todos dentro do cartão "7. Nota fiscal, pagamento e saída do caminhão"):
  1. selo azul `data-testid="badge-frota-mensal"` quando `isFrotaFaturamentoMensal()`: "Cliente de frota com faturamento mensal: dispensa pagamento e acordo; a nota fiscal continua obrigatória";
  2. faixa vermelha `data-testid="aviso-divergencia-pecas"` quando `StageGate::faturamentoBloqueio()` devolve texto, com a orientação "Peças: registre a conferência sem divergência em 'Passos desta etapa'". Com a faixa, os botões de emitir, o formulário de nota e (no cartão de cobrança) os botões de gerar cobrança ficam desabilitados;
  3. coluna **Nota fiscal**: etiqueta feita/pendente; por tipo (NFS-e se há serviços; NF-e se há peças): situação do documento (Processando, Autorizada, Erro, Cancelada) com link `admin.fiscal_documents.view`, links de PDF/XML da Focus quando existirem e botão "Atualizar situação" (POST `admin.fiscal_documents.consult`) enquanto não estiver autorizado/cancelado; botão "Emitir NFS-e" (`data-testid="btn-emitir-nfse"`) e "Emitir NF-e de peças" (`btn-emitir-nfe`) quando `fiscal_documents.emit` + integração pronta + etapa em `ETAPAS_DE_NOTA`; se a integração não está pronta, texto com o que falta (`integracaoFaltando`) e link para Configurações Fiscais; formulário "Registrar nota emitida fora do sistema" (`data-testid="nf-manual-form"`, `enctype="multipart/form-data"`: número, tipo da nota, arquivos XML/PDF, e o campo "Motivo da correção" só quando já existe registro), visível para quem tem `fiscal_documents.emit` (recolhido em `<details>` quando a integração está pronta); resumo `data-testid="nf-registrada"` ("Nota nº X registrada em dd/mm/aaaa hh:mm por Fulano" + links dos arquivos pela rota autenticada); aviso amarelo quando `avisoPecasSemNfe()`;
  4. coluna **Pagamento**: etiqueta feita/pendente; "Pago em dd/mm/aaaa hh:mm (Asaas | cobrança marcada como paga | confirmação manual)" lendo o último evento `pagamento_confirmado`; aviso de pagamento parcial quando há boleto pago que não cobre o total; botão "Confirmar pagamento" como hoje; atalho "Gerar cobrança pelo Asaas" para `#cobranca`;
  5. coluna **Acordo** como hoje;
  6. painel **Saída do caminhão** (`data-testid="saida-caminhao"`), só quando a OS está em Faturamento: lista com 4 itens (checklist de saída, nota, pagamento/acordo/frota, peças sem divergência) com marca de feito/pendente vindas de `StageGate::missing($os, 'Pós-Venda')`; botão `data-testid="btn-registrar-saida"` "Registrar saída do caminhão" (formulário `PUT admin.service_orders.stage.update`, `stage=Pós-Venda`), desabilitado com `disabled` enquanto faltar algo, com a frase "Para registrar a saída falta: ..." (`data-testid="saida-faltando"`); para quem tem `service_orders.forcar_avanco` e enquanto faltar algo, formulário `data-testid="form-forcar-saida"` com `forcar=1`, `justificativa` obrigatória (`minlength="10"`), aviso do que acontece e `confirm()`; depois da saída: "Saída registrada em dd/mm/aaaa hh:mm por Fulano" e etiqueta vermelha "sem nota fiscal" quando o evento diz `sem_nf`.
- **`SO/Resources/views/partials/progress.blade.php:25-64`**: calcular `$ehSaida = StageGate::active() && $serviceOrder->workshop_status === Faturamento && $nextStage === Pós-Venda`. Se `$ehSaida`: o rótulo do botão vira "Registrar saída do caminhão", o do forçado "Forçar a saída" e o `confirm()` usa `service_order::app.saida.forcar-confirmar`; quem não vê valores (`ValoresFinanceiros::can()` falso) não recebe o botão nem o forçado. Fora da saída e com o piloto desligado a tela fica idêntica à de hoje.
- **`SO/Resources/views/partials/faturamento.blade.php`** (cartão do boleto): `id="cobranca"` no cartão e, com `StageGate::faturamentoBloqueio()`, os dois botões de gerar cobrança com `disabled` e a mensagem do bloqueio.
- **`SO/Resources/views/partials/cliente-faturamento.blade.php`** (novo): só renderiza se `ClienteFaturamento::disponivel()` e `ValoresFinanceiros::can()`. Mostra: título "Faturamento do cliente"; situação atual; "Marcado/desmarcado em dd/mm/aaaa hh:mm por Fulano: motivo" (último histórico); dica "N veículo(s) deste cliente têm número de frota cadastrado" quando `veiculosComFrota() > 0`; e, para quem tem `service_orders.cliente_faturamento_mensal`, o formulário `PUT admin.clientes.faturamento_mensal` (caixa "Cliente de frota com faturamento mensal" + campo "Motivo / contrato" obrigatório + botão Salvar). Texto de ajuda: "Quando marcado, a saída do caminhão é liberada só com a nota fiscal, sem exigir pagamento nem acordo. Só tem efeito com o piloto ligado."

**F. Registro, rotas, ACL e idiomas**

- **`SO/Providers/ServiceOrderServiceProvider.php`** (boot, junto de `:53-63`):
  ```php
  Event::listen('admin.contacts.persons.view.attributes.form_controls.after', function ($viewRenderEventManager) {
      $viewRenderEventManager->addTemplate('service_order::partials.cliente-faturamento');
  });
  ```
  Atenção: NÃO usar `admin.contacts.persons.view.attributes.before`: `packages/Webkul/Admin/src/Resources/views/contacts/persons/view/attributes.blade.php` dispara esse nome duas vezes (linhas 1 e 41) e o cartão sairia duplicado. O gancho `...form_controls.after` (linha 36) é único, fica dentro do acordeão "Sobre a pessoa" (aberto por padrão) e depois do `</x-admin::form>`, então o `<form>` do cartão não aninha em outro formulário. O gancho passa `['person' => $person]`.
- **`SO/Routes/web.php`**: no grupo do `WorkflowController` (`:97-120`): `Route::get('nf/{arquivoId}', 'nfArquivo')->whereNumber('arquivoId')->name('admin.service_orders.workflow.nf.arquivo');`. Fora dos grupos: `Route::put('clientes/{personId}/faturamento-mensal', [ClienteFaturamentoController::class, 'update'])->name('admin.clientes.faturamento_mensal');` (importar o controller).
- **`SO/Config/acl.php`**: `service_orders.view` passa a listar também `admin.service_orders.workflow.nf.arquivo`; chave nova `service_orders.cliente_faturamento_mensal` (rota `admin.clientes.faturamento_mensal`, `sort` 8). NÃO tirar `workflow.nf`, `payment`, `agreement` nem `stage.update` de `service_orders.edit` (o mapa rota -> chave usa a última chave que cita a rota e isso derrubaria o piloto desligado: perfis personalizados perderiam o acesso).
- **`SO/Http/Middleware/RegistraAcessoSensivel.php:17-19`**: acrescentar os prefixos `'admin.clientes.'` e `'admin.service_orders.workflow.nf.arquivo'`.
- **`SO/Resources/lang/pt_BR/app.php` e `en/app.php`**: grupos novos `saida`, `nota_fiscal`, `cliente_faturamento` e a chave `acl.cliente_faturamento_mensal` (textos na seção própria). Manter os dois idiomas.

### Alterar (testes existentes)
- **`tests/Feature/Workshop/StageGateTest.php:185-194`**: o teste "frota com faturamento mensal é a exceção: sai sem NF e sem pagamento" passa a ser "frota com faturamento mensal sai só com a nota" (T05). Não apagar sem substituir.
- **`tests/Feature/Workshop/BonusTest.php:206-246`**: continua válido (o evento de saída é criado à mão e o consultor Cris é o autor); acrescentar a expectativa de que a trava `saida_sem_nf` do financeiro também nasce e de que rodar `detectar()` de novo devolve 0.

---

## Banco (migrations)

Uma migration, aditiva e idempotente, no pacote ServiceOrder (mesmo padrão de `2026_11_17_120000_add_codigo_to_persons_and_mechanics.php`):

**`packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_090100_add_faturamento_mensal_to_clientes.php`**

```php
public function up(): void
{
    if (Schema::hasTable('persons') && ! Schema::hasColumn('persons', 'faturamento_mensal')) {
        Schema::table('persons', function (Blueprint $table) {
            $table->boolean('faturamento_mensal')->default(false);
        });
    }

    if (! Schema::hasTable('cliente_faturamento_historico')) {
        Schema::create('cliente_faturamento_historico', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('person_id')->index();
            $table->boolean('faturamento_mensal');
            $table->string('justificativa', 300);
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
}

public function down(): void
{
    Schema::dropIfExists('cliente_faturamento_historico');

    if (Schema::hasColumn('persons', 'faturamento_mensal')) {
        Schema::table('persons', fn (Blueprint $table) => $table->dropColumn('faturamento_mensal'));
    }
}
```

- Nenhuma outra tabela muda: o anexo da nota usa `service_order_attachments.tipo = 'nota_fiscal'` (coluna livre), o registro manual reaproveita `quotes.nf_manual_numero`/`nf_manual_em`, e a trava usa `bonus_travas`.
- O nome usa o bloco horário `09` (número da frente) para não colidir com as outras frentes: F00 -> `00xxxx`, F14 -> `14xxxx`.
- Tolerância a coluna ou tabela ausente (servidor só recebe a migration quando o usuário rodar o `migrate-safe.php`): `ClienteFaturamento::colunaDisponivel()` e `disponivel()` (padrão de `PrevisaoOs::disponivel()`), `TravaService::disponivel()`. Sem a coluna: a frota nunca isenta nada, o cartão do cliente não aparece, nada dá erro 500.
- Aplicar no banco de teste: `$env:DB_DATABASE='krayin_test'; php artisan migrate --path=packages/Webkul/ServiceOrder/src/Database/Migrations/2026_11_18_090100_add_faturamento_mensal_to_clientes.php`. Só rodar no `krayin_crm` se a instrução do coordenador mandar.
- Nada é apagado: o histórico é só de inserção; as correções de nota geram evento `correcao`.

---

## ACL, menu, rotas e configurações

**Chaves de ACL**
| Chave | Situação | Quem deve ter (recomendação para F04/F05) |
|---|---|---|
| `service_orders.cliente_faturamento_mensal` | NOVA ("Marcar cliente com faturamento mensal (frota)") | Financeiro, Gerente, Proprietário |
| `fiscal_documents.emit` | existente; passa a valer também para registrar nota à mão e consultar situação (piloto ligado) | Financeiro, Gerente, Proprietário |
| `service_orders.forcar_avanco` | existente; passa a ser a permissão de forçar a saída | Gerente, Proprietário (ver Decisões) |
| `ver_valores_financeiros` | existente; decide quem vê o bloco, o cartão do cliente e quem usa o botão da saída | Consultor, Financeiro, Gerente, Proprietário |
| `service_orders.edit` / `service_orders.view` | existentes | sem mudança |

**Rotas**
| Nome | Método e caminho | Chave de ACL | Observação |
|---|---|---|---|
| `admin.clientes.faturamento_mensal` | PUT `admin/clientes/{personId}/faturamento-mensal` | `service_orders.cliente_faturamento_mensal` | + `ValoresFinanceiros::authorize` (403 ao Técnico). Prefixo `admin.clientes.` de propósito (não `admin.service_orders.`: ver `DonoForaDoFluxo`) |
| `admin.service_orders.workflow.nf.arquivo` | GET `admin/service-orders/{id}/workflow/nf/{arquivoId}` | `service_orders.view` | + `ValoresFinanceiros::authorize`; acesso registrado (LGPD) |
| `admin.fiscal_documents.consult` | POST `admin/fiscal-documents/{id}/consultar` | `fiscal_documents.emit` | + `ValoresFinanceiros::authorize` |
| (sem rota nova) | `admin.service_orders.stage.update` com `stage=Pós-Venda` | `service_orders.edit` (+ `forcar_avanco` se `forcar=1`) | `ValoresFinanceiros::authorize` só quando é a saída |

**Quem vê e faz o quê (piloto ligado)**
| Ação | Técnico | Consultor | Peças | Financeiro | Gerente | Proprietário |
|---|---|---|---|---|---|---|
| Ver bloco de nota/pagamento/saída e cartão do cliente | não (403 nas rotas) | sim | só se tiver ver_valores | sim | sim | sim |
| Emitir ou registrar nota, consultar situação | não | só com `fiscal_documents.emit` | não | sim | sim | sim |
| Confirmar pagamento / acordo | não | como hoje | não | como hoje | como hoje | como hoje |
| Registrar saída | não (403) | sim | não | sim | sim | sim |
| Forçar saída | não | só com `forcar_avanco` | não | só com `forcar_avanco` | sim | sim |
| Corrigir divergência de peças | não | não | sim | não | sim | sim |
| Marcar cliente de faturamento mensal | não | não | não | sim | sim | sim |

**Menu**: nenhum item novo. O Faturamento já está no menu (`SO/Config/menu.php:60-66`).

**Configurações (`WorkshopSettings`)**: nenhuma chave nova. Regras de prazo não existem nesta frente (alertas são imediatos), então o modo de teste ÷10 não se aplica. Variáveis de ambiente que o usuário precisa definir: `ASAAS_WEBHOOK_TOKEN`, `FOCUSNFE_WEBHOOK_SECRET`, `FOCUSNFE_BASE_URL` (homologação ou produção).

**Códigos novos**: alerta `divergencia_pecas` (via `AlertService::raise`, sem barreira nova); `chave_unica` de trava `saida:{eventoId}:{cargo}:{colaboradorId}`; campos novos em `saida_veiculo.dados_json`: `sem_pagamento`, `excecao_frota`, `sem_cobranca`, `forcada`; em `nf_emitida.dados_json` manual: `tipo_nota` e `arquivos`. Nenhum tipo de evento, assunto de alçada ou código de barreira novo.

---

## Textos da interface (pt-BR)

Grupos novos em `SO/Resources/lang/pt_BR/app.php` (e o equivalente em inglês em `en/app.php`), acessados como `service_order::app.<grupo>.<chave>`:

```php
'acl' => [ /* ... */ 'cliente_faturamento_mensal' => 'Marcar cliente com faturamento mensal (frota)' ],

'saida' => [
    'titulo' => 'Saída do caminhão',
    'registrar-btn' => 'Registrar saída do caminhão',
    'forcar-btn' => 'Forçar a saída',
    'forcar-justificativa' => 'Justificativa da saída forçada (obrigatória)',
    'forcar-aviso' => 'Forçar a saída avisa o gerente e o proprietário na hora e abre uma trava pendente para o consultor e o financeiro, que o gerente vai confirmar ou rejeitar.',
    'forcar-confirmar' => 'Forçar a saída registra a justificativa, avisa o gerente e o proprietário agora e abre a trava do consultor e do financeiro. Continuar?',
    'ok' => 'Saída do caminhão registrada.',
    'forcada-ok' => 'Saída registrada à força. O gerente e o proprietário foram avisados e as travas do consultor e do financeiro aguardam a decisão do gerente.',
    'registrada-em' => 'Saída registrada em :data por :usuario.',
    'sem-nf' => 'sem nota fiscal',
    'liberada' => 'Tudo certo: a saída está liberada.',
    'falta' => 'Para registrar a saída falta:',
    'item-checklist' => 'Checklist de saída',
    'item-nota' => 'Nota fiscal emitida ou registrada',
    'item-pagamento' => 'Pagamento confirmado, acordo assinado ou cliente de frota com faturamento mensal',
    'item-pecas' => 'Peças aplicadas conferidas, sem divergência',
    'frota-selo' => 'Cliente de frota com faturamento mensal: dispensa pagamento e acordo; a nota fiscal continua obrigatória',
    'pagamento-parcial' => 'Cobrança marcada como paga, mas o total pago ainda não cobre o valor da OS. Confirme o pagamento à mão, gere outra cobrança ou registre o acordo.',
    'pago-em' => 'Pago em :data (:origem)',
    'origem-asaas' => 'Asaas',
    'origem-cobranca' => 'cobrança marcada como paga',
    'origem-manual' => 'confirmação manual',
],

'nota_fiscal' => [
    'titulo' => 'Nota fiscal',
    'nfse' => 'NFS-e (serviços)',
    'nfe' => 'NF-e (peças)',
    'emitir-nfse' => 'Emitir NFS-e',
    'emitir-nfe' => 'Emitir NF-e de peças',
    'atualizar' => 'Atualizar situação',
    'situacao' => ['processando' => 'Processando', 'autorizado' => 'Autorizada', 'erro_autorizacao' => 'Erro', 'cancelado' => 'Cancelada'],
    'integracao-pendente' => 'Emissão automática indisponível: falta :itens. Enquanto isso, registre a nota emitida fora do sistema.',
    'manual-titulo' => 'Registrar nota emitida fora do sistema',
    'manual-numero' => 'Número da nota',
    'manual-tipo' => 'Nota de',
    'manual-arquivos' => 'XML e/ou PDF da nota',
    'manual-motivo' => 'Motivo da correção',
    'manual-btn' => 'Registrar nota',
    'registrada' => 'Nota fiscal registrada.',
    'registrada-em' => 'Nota nº :numero registrada em :data por :usuario.',
    'aviso-pecas' => 'Esta OS tem peças e só há nota de serviço. Confirme se as peças já estão na nota ou emita/registre a NF-e.',
    'sem-permissao' => 'Só quem emite nota fiscal pode registrar a nota.',
    'so-no-faturamento' => 'A nota fiscal é registrada ou emitida a partir da etapa de Faturamento.',
    'divergencia-faixa' => 'Divergência entre peças aplicadas e lançadas. O faturamento fica parado até Peças corrigir. Peças: registre a conferência sem divergência em "Passos desta etapa".',
],

'cliente_faturamento' => [
    'titulo' => 'Faturamento do cliente',
    'rotulo' => 'Cliente de frota com faturamento mensal',
    'ajuda' => 'Quando marcado, a saída do caminhão é liberada só com a nota fiscal, sem exigir pagamento nem acordo. Só tem efeito com o piloto ligado.',
    'motivo' => 'Motivo / contrato',
    'salvar' => 'Salvar',
    'marcado' => 'Marcado em :data por :usuario: :motivo',
    'desmarcado' => 'Desmarcado em :data por :usuario: :motivo',
    'dica-frota' => ':n veículo(s) deste cliente têm número de frota cadastrado. Confira se é faturamento mensal.',
    'salvo' => 'Faturamento do cliente atualizado.',
    'sem-mudanca' => 'O cliente já estava assim; nada foi alterado.',
],
```

Mensagens de validação do registro de nota: `numero.required` "Informe o número da nota fiscal."; `numero.regex` "O número da nota só pode ter letras, números, barra, ponto e hífen."; `arquivos.required` "Anexe o XML ou o PDF da nota fiscal."; `arquivos.max` "Envie no máximo 3 arquivos."; `arquivos.*.mimes` "Cada arquivo precisa ser XML ou PDF."; `arquivos.*.max` "Cada arquivo pode ter até 5 MB.".

Pacote Fiscal (`FIS/Resources/lang/pt_BR/app.php`): `'consult-btn' => 'Atualizar situação'`, `'consult-result' => 'Situação da nota atualizada: :status.'`, `'consult-failed' => 'Não foi possível consultar a nota: :message'`, `'duplicada' => 'Esta OS já tem uma :tipo em andamento ou autorizada. Atualize a situação em vez de emitir de novo.'`.

Mensagens fixas em português no `StageGate`/serviços: "Divergência entre peças aplicadas e lançadas: Peças precisa corrigir"; "Descreva a divergência encontrada."; "Descreva o que foi corrigido."; "Só Peças (ou o gerente) registra a correção da divergência."; "A nota já foi registrada. Para registrar de novo (correção), informe a justificativa."; "A nota fiscal é registrada a partir da etapa de Faturamento."; "Atualize o banco (migrate-safe) antes de marcar o cliente."

Alerta de divergência (sem valores e sem crédito): título "Divergência de peças"; mensagem "ALERTA - Divergência entre peças aplicadas e lançadas. OS #12 - ABC1D23 - Transportadora X. O faturamento fica parado até Peças corrigir."

Alerta crítico da saída forçada: já existe e não muda ("ALERTA - Avanço forçado. OS #… Ocorrido às HH:MM. Responsável: …." e "ALERTA - Saída sem nota fiscal. ..."); a justificativa digitada NÃO vai no push nem no SMS (pode citar dinheiro); fica na linha do tempo da OS e na descrição da trava.

Descrição automática da trava: "Saída do caminhão forçada na OS #12. Faltava: Nota fiscal emitida; Pagamento confirmado ou acordo assinado. Justificativa: ...".

---

## Testes Pest a escrever

Arquivo novo: `tests/Feature/Workshop/SaidaNotaFiscalTest.php`. Helpers globais com prefixo `saida` (nomes únicos no Pest; não depender de `staff()` do HttpFlowTest nem de `indicador()`/`realizado()` do IndicatorsTest):

```php
function saidaUsuario(string $perfil, array $extra = [], array $usuario = []): User   // test()->makeUser($perfil, array_merge(['service_orders','service_orders.view','service_orders.edit','ver_valores_financeiros','fiscal_documents.emit'], $extra), $usuario)
function saidaOs(array $override = [], bool $pronta = true): ServiceOrder            // makeOs em Faturamento; $pronta liga checklist_saida_em
function saidaColaborador(User $user, string $cargo): Colaborador                    // Colaborador::create(['nome'=>..., 'cargos'=>$cargo, 'user_id'=>$user->id, 'ativo'=>true])
```
Todos com `$this->enablePilot()` (ou `enablePilot([], [...barreiras...])`), `Storage::fake('public')` quando há upload, `Http::fake` para Focus e Asaas, `ApiKey::create(['nome'=>..., 'tipo'=>'focusnfe'|'asaas', 'valor'=>'token-teste'])` e `FiscalSettings::current()->update([...cnpj, inscricao_municipal, codigo_municipio, item_lista_servico...])`.

| ID | Teste (nome do `it`) | Cobre |
|---|---|---|
| T01 | `B3 / roteiro 47: saída sem nota fica bloqueada, a tela lista o que falta e o técnico não vê o botão` | PDF "saída sem nota fica bloqueada"; linha 47 |
| T02 | `B3 / roteiros 45 e 47: com nota registrada (número + arquivos) e pagamento confirmado a saída é liberada, sem alerta nem trava` | cadeia completa |
| T03 | `B3 / roteiro 48: forçar a saída exige justificativa, avisa gerente e proprietário na hora (crítico, sem R$) e abre a trava pendente do consultor e do financeiro` | PDF "forçada gera alerta crítico e trava pendente"; linha 48 |
| T04 | `forçar a saída: só quem tem forcar_avanco; sem consultor identificável só a trava do financeiro; detectar() do mês não duplica` | travas |
| T05 | `B3 / roteiro 50: cliente de frota com faturamento mensal sai só com a nota` | linha 50 |
| T06 | `Nº de frota digitado no veículo não isenta ninguém; só a marcação no cadastro do cliente` | laço fechado |
| T07 | `marcar o cliente: permissão, motivo obrigatório, histórico que nada apaga e invisível a quem não vê valores` | marcação |
| T08 | `NF manual: número e arquivo (XML/PDF) obrigatórios, só quem emite nota registra, correção só com justificativa e nada é apagado` | fallback manual |
| T09 | `NFS-e pela Focus: botão no Faturamento, emissão (Http::fake), sem duplicar, erro HTTP não fica "processando", webhook e consulta autorizam e liberam a nota` | emissão |
| T10 | `webhooks da Focus e do Asaas recusam chamada sem segredo com o piloto ligado e com segredo errado` | endurecimento |
| T11 | `B3 / roteiro 49: cobrança gerada pelo Asaas e pagamento que entra pelo webhook confirma a OS sozinho` | linha 49 |
| T12 | `pagamento parcial não libera a saída; confirmação manual ou acordo libera` | parcial |
| T13 | `roteiro 46: divergência entre peças aplicadas e lançadas impede faturar até Peças corrigir` | linha 46 |
| T14 | `FIN-02, CON-07 e "saídas sem NF" do relatório diário refletem a saída forçada` | alimenta indicadores |
| T15 | `retorno/garantia sem cobrança sai só com o checklist e fica fora do FIN-02` | gancho F12 |
| T16 | `piloto desligado: a saída, a nota, a divergência e o webhook continuam como antes` | regra 2 do contexto |
| T17 | `Minha rotina do financeiro mostra a nota autorizada pela Focus como "aguardando pagamento ou acordo"` | rotina |
| T18 | `sem a coluna da migration o sistema segue: a frota nunca isenta e nada dá erro` | tolerância |

**Roteiro dos testes (o que montar e o que afirmar)**

- **T01**: OS em Faturamento com `checklist_saida_em`, sem nota. `PUT admin.service_orders.stage.update` com `stage=Pós-Venda` como Financeiro: redireciona; `session('error')` contém "Falta:" e "Nota fiscal emitida" e "Pagamento confirmado ou acordo assinado"; a OS segue em Faturamento; não existe evento `saida_veiculo`. `GET admin.service_orders.view`: HTML contém `data-testid="btn-registrar-saida"`, `disabled`, "Registrar saída do caminhão" e `saida-faltando`. Como Técnico (permissões sem `ver_valores_financeiros`, `view_permission individual`, mecânico vinculado): o mesmo PUT devolve 403 e o HTML não contém "Registrar saída do caminhão".
- **T02**: Financeiro com `Storage::fake('public')`: `POST admin.service_orders.workflow.nf` com `numero=2026/123`, `tipo_nota=unica`, `arquivos[]` = `UploadedFile::fake()->createWithContent('nfse.xml', '<?xml version="1.0"?><nfse/>')` e `UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf')`; `POST ...workflow.payment`; `PUT stage` -> sucesso `service_order::app.saida.ok`; evento `saida_veiculo` com `sem_nf=false`, `sem_pagamento=false`, `forcada=false`; `saida_em` preenchido; `WorkshopAlert::count()` zero; `BonusTrava::count()` zero. Roteiro 45 como pré-condição: usar a ação `checklist_saida` pela rota `admin.service_orders.workflow.action` em vez de `forceFill` em pelo menos um cenário.
- **T03**: usuários gerente, dono, consultor (com `Colaborador` de cargo consultor e OS aberta por ele) e financeiro (com `Colaborador`). Forçador = gerente com `service_orders.forcar_avanco`. (a) `forcar=1` sem justificativa: erro, a OS não muda, nenhum evento `bloqueio_forcado`. (b) com justificativa: avança; `bloqueio_forcado` com `justificativa`, `faltava` e `escala_dono=true`; `saida_veiculo` com `sem_nf=true`, `forcada=true`; alertas críticos para gerente e dono com os códigos `critico_bloqueio_forcado` e `critico_saida_sem_nota` e nenhum para consultor e financeiro; `WorkshopAlert::all()->every(fn ($a) => ! str_contains($a->mensagem, 'R$'))`; `BonusTrava` com exatamente 2 linhas pendentes, origem `automatica`, `service_order_id` da OS, uma do consultor (`liberado_sem_quitacao`, `colaborador_id` do consultor) e uma do financeiro (`saida_sem_nf`). A justificativa não aparece em nenhum `WorkshopAlert->mensagem`. Mensagem de sucesso `saida.forcada-ok`.
- **T04**: sem a permissão: `PUT` com `forcar=1` -> 403. OS aberta por usuário sem cargo de consultor e forçada pelo gerente -> só a trava do financeiro. Depois `app(TravaService::class)->detectar(now()->format('Y-m'))` devolve 0 (a chave `saida:{evento}:{cargo}:{colaborador}` impede duplicar). Com dois financeiros ativos nascem duas travas de financeiro. Gerente chama `decidir()` e confirma a do financeiro: `zerado_por_trava` na apuração (ver padrão de `BonusTest.php:143-172`).
- **T05**: `ClienteFaturamento::definir($person, true, 'Contrato de frota')`; sem nota: `faltando` = `['Nota fiscal emitida']` e NÃO contém "Pagamento..."; com `nf_manual_em`: avança; evento `saida_veiculo` com `excecao_frota=true`, `sem_nf=false`, `sem_pagamento=false`, sem alerta.
- **T06**: veículo com `frota` preenchida, cliente sem marcação: `faltando` ainda pede nota E pagamento.
- **T07**: Financeiro com `service_orders.cliente_faturamento_mensal`: `PUT admin.clientes.faturamento_mensal` com `faturamento_mensal=1` e `justificativa` -> coluna verdadeira e uma linha em `cliente_faturamento_historico`; sem justificativa -> erro; desmarcar exige justificativa e cria nova linha (a anterior continua); repetir o mesmo estado não cria linha. Usuário sem a chave -> 401; Técnico com a chave por engano -> 403 (e o log "Acesso negado a valores financeiros"). `GET admin.contacts.persons.view` como Financeiro contém "Faturamento do cliente" e aparece uma única vez; como usuário sem `ver_valores_financeiros` (piloto ligado) não contém. `ClienteFaturamentoHistorico::first()->delete()` lança `LogicException`. O JSON da busca de pessoas (`admin.contacts.persons.search`) não contém `faturamento_mensal`.
- **T08**: sem arquivo -> erro "Anexe o XML ou o PDF"; sem número -> erro; arquivo `.exe` -> erro de tipo; usuário sem `fiscal_documents.emit` (piloto ligado) -> 403; Técnico -> 403; OS fora de Faturamento/Pós-Venda/Concluída -> erro "a partir da etapa de Faturamento". Registro válido: `nf_manual_numero`, `nf_manual_em`, 2 anexos `tipo=nota_fiscal` no disco fake, evento `nf_emitida` (`origem=manual`, `tipo_nota`, `arquivos=2`); `StageGate::hasNotaFiscal` verdadeiro. Segundo registro sem justificativa -> erro; com justificativa -> novo `nf_emitida` + evento `correcao` apontando o anterior, anexos antigos preservados, `nf_manual_numero` novo. `GET ...workflow.nf.arquivo` baixa o arquivo (Financeiro) e dá 403 ao Técnico; a página da OS não contém a URL do storage do arquivo.
- **T09**: `Http::fake(['homologacao.focusnfe.com.br/*' => Http::response(['status' => 'processando_autorizacao'], 202)])`. GET da OS em Faturamento mostra `btn-emitir-nfse`; em Diagnóstico não. `POST admin.fiscal_documents.emit-nfe` (NFS-e) com `valor_servicos` e `discriminacao` -> `FiscalDocument` tipo `nfse`; segundo POST -> bloqueado pela guarda de duplicidade (sem nova linha); `Http::fake` com 401 `{codigo,mensagem}` -> documento `erro_autorizacao` com `last_error` e nova tentativa permitida. Webhook `POST /fiscal/focusnfe/webhook?secret=...` com `ref`, `status=autorizado`, `numero` -> documento autorizado, evento `nf_emitida` (`origem=focus`), `StageGate::hasNotaFiscal` verdadeiro. `POST admin.fiscal_documents.consult` com `Http::fake` de GET devolvendo `autorizado` também autoriza; falha HTTP -> `session('error')` e o documento não muda. Sem chave da Focus -> mensagem lista o que falta. Técnico -> 403 em `create`, `emit-nfe`, `create-nfe-produto`, `emit-nfe-produto` e `consult`. NF-e: peça sem NCM -> mensagem do `missingRequirementsNfe`.
- **T10**: `config(['asaas.webhook_token' => null])` com o piloto ligado -> 503; token errado -> 401; token certo -> 200; mesma coisa para `focusnfe.webhook_secret` (`?secret=`). Com o piloto desligado e sem token -> aceita (comportamento de hoje).
- **T11**: `config(['asaas.webhook_token' => 'segredo'])`, `ApiKey` asaas, `Http::fake(['*/customers' => ['id' => 'cus_1'], '*/payments' => ['id' => 'pay_1', 'dueDate' => ..., 'status' => 'PENDING', 'invoiceUrl' => ..., 'billingType' => 'UNDEFINED']])`. `POST admin.service_orders.boletos.asaas` com `valor` = total da OS -> `ServiceOrderBoleto` `gateway=asaas`, `asaas_payment_id=pay_1`, `Pendente`, evento `boleto_ou_cobranca_gerada`. `postJson('/service-orders/asaas/webhook', ['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_1', 'status' => 'RECEIVED']], ['asaas-access-token' => 'segredo'])` -> boleto `Pago`, `pagamento_confirmado_em` preenchido, evento `pagamento_confirmado` com `origem=asaas`, e `faltando` deixa de pedir pagamento. Status `PENDING` não confirma; pagamento desconhecido -> 404. O bloco da OS mostra "Pago em dd/mm/aaaa hh:mm (Asaas)".
- **T12**: OS com total 1000 e boleto pago de 400 -> `pagamento_confirmado_em` nulo, `faltando` ainda pede pagamento, `warning` na tela; boleto adicional de 600 pago -> confirma; ou `workflow.payment` (confirmação manual) / `workflow.agreement` libera.
- **T13**: Financeiro e Peças (perfil `pecas`). `POST workflow.action` `conferir_pecas` com `divergencia=sim` sem observação -> erro; com observação -> evento `pecas_conferidas` com `divergencia=true` e alerta `divergencia_pecas` para Peças e gerente (sem "R$"; dono não recebe). Com divergência pendente: registrar nota manual -> erro com o texto do bloqueio; `GET admin.fiscal_documents.create` e `create-nfe-produto` -> redireciona com erro; `boletos.store` e `boletos.asaas` -> erro; `faltando` contém "Divergência entre peças aplicadas e lançadas: Peças precisa corrigir"; o HTML tem `aviso-divergencia-pecas`. Financeiro tentando registrar "sem divergência" -> erro "Só Peças..."; Peças sem observação -> erro; Peças com observação -> evento com `divergencia=false` e tudo liberado. Com o piloto desligado nada disso bloqueia. Divergência registrada e OS que já saiu (Pós-Venda): registrar nota continua permitido (regularização).
- **T14**: três OS com `saida_veiculo` no mês (uma com nota, uma forçada sem nota, uma de frota com nota) -> `app(IndicatorService::class)->evaluate('FIN-02', '2026-11')['resultado']->realizado` é 66,7 (2 de 3; a frota entra). `FinanceiroService::relatorioDoDia($dia)['operacao']['saidas_sem_nf']` = 1 e `saidas_sem_nf_os` = [id da OS forçada]; a tela `admin.financeiro.relatorios` mostra "1 saída(s) sem NF" com o link da OS. CON-07: `enablePilot([], ['pronto_nao_faturado', ...])`, `checklist_saida` e `enviar_financeiro` no mesmo dia -> barreira cumprida no prazo e `evaluate('CON-07', ...)` = 100.
- **T15**: OS `tipo_os=garantia` com total zero em Faturamento: `faltando` só pede o checklist; saída registra `sem_cobranca=true`, `sem_nf=false`; FIN-02 ignora essa OS. OS `retorno` com total maior que zero continua exigindo nota e pagamento.
- **T16**: piloto desligado: `advancer()->advance($os, 'Pós-Venda')` avança sem nota; a OS abre sem o bloco novo; webhook do Asaas aceita sem token; `ValoresFinanceiros::can()` verdadeiro; `faturamentoBloqueio()` nulo; o cartão do cliente aparece mas sem efeito no gate.
- **T17**: `app(RotinaService::class)->doFinanceiro()` com OS cuja nota foi autorizada pela Focus (`FiscalDocument` `autorizado`) mostra "aguardando pagamento ou acordo" e não "emitir nota fiscal".
- **T18**: `ClienteFaturamento::esquecer()` e simular coluna ausente (`Schema` real não muda; usar um gancho estático de teste ou um `Person` sem o atributo): `ClienteFaturamento::mensal($person)` falso e `isFrotaFaturamentoMensal()` falso; `TravaService::registrarSaidaForcada()` devolve 0 quando a tabela não existe (gancho de teste análogo).

**Testes existentes a ajustar**: `StageGateTest.php:185-194` (substituir por T05 e T06 equivalentes, mantendo o `it` com o novo nome) e `BonusTest.php:206-246` (expectativa extra descrita acima). Rodar nesta ordem: `--filter=SaidaNotaFiscal`, `--filter=StageGate`, `--filter=Bonus`, `--filter=Workshop`; depois `php vendor/bin/pint` nos arquivos tocados.

---

## Riscos e interações com outras frentes

**Mudança de regra que o patrão precisa saber**
1. A frota deixa de isentar a NOTA (só dispensa o pagamento). É o que dizem o PDF (B3 e linha 50), mas contradiz o código e o teste atuais. O "Nº de frota" do veículo deixa de ter qualquer efeito no gate.
2. Pagamento: um boleto pago só confirma a OS quando as cobranças pagas cobrem o total (ver Decisões). Hoje qualquer boleto pago libera.
3. Webhooks do Asaas e da Focus passam a exigir token/segredo com o piloto ligado. Sem a variável de ambiente, a baixa automática para de funcionar (a tela mostra "Atualizar situação" e "Confirmar pagamento" como saída manual).

**Interações (arquivos e interfaces compartilhados)**
- **F04/F05 (perfis, menu e permissões):** a matriz de quem tem `fiscal_documents.emit`, `service_orders.forcar_avanco`, `service_orders.cliente_faturamento_mensal` e `ver_valores_financeiros` é deles. Com o piloto ligado e perfis personalizados sem `fiscal_documents.emit`, o Financeiro perde o registro manual da nota até receber a chave. Não removi nenhuma rota de `service_orders.edit` (derrubaria o piloto desligado). A F05 deve avaliar `bloqueio_forcado` de `Negociação` no filtro da linha do tempo do Técnico.
- **F06 (cor de crédito) e esta frente:** ambas aparecem na página do cliente. Cada uma registra o próprio template no mesmo gancho (`admin.contacts.persons.view.attributes.form_controls.after`) no `ServiceOrderServiceProvider.php`; ou compartilham um único cartão "Dados financeiros do cliente". Evitar o gancho `...attributes.before` (disparado duas vezes pelo Admin). Crédito nunca vai ao link de acompanhamento nem à OS impressa; nada desta frente toca esses dois.
- **F07 (termo de entrada):** edita `StageGate::cadastroParaOs`; eu edito `faturamentoParaSaida`, `hasPagamentoOuAcordo` e métodos novos. O arquivo é o mesmo: mesclar por método.
- **F10/F11 (peças):** F10 deve listar "Divergências a corrigir" no Painel de Peças usando `StageGate::divergenciaDePecasPendente($os)` (a correção é a ação `conferir_pecas` da OS). Se a F11 automatizar a conferência "aplicadas × lançadas", deve continuar gravando o evento `pecas_conferidas` com `divergencia` (é o contrato que o bloqueio e o PEC-06 leem). Se Peças não alcançar a OS em Faturamento pelo menu, F05/F10 precisam dar o caminho.
- **F12 (retorno/garantia):** a saída de uma OS de garantia sem cobrança travaria no gate. Deixei o ponto de extensão `StageGate::saidaSemCobranca()` (regra mínima: `tipo_os` retorno/garantia com total zero dispensa nota e pagamento) e o campo `sem_cobranca` no evento. A F12 pode refinar a regra (janela de 90 dias da Ficha, causa classificada) editando só esse método.
- **F13 (diária de pátio):** a diária entra como item de serviço na OS antes do faturamento; sem impacto no gate. Se for lançada depois da cobrança paga, o total passa a não ser coberto: o Financeiro gera cobrança complementar ou confirma à mão.
- **F14 (remuneração):** travas confirmadas zeram o bônus (`BonusService`); nada novo aqui. O relatório de comissão usa `faturado_em` (data em que a OS chega ao Faturamento), não a nota.
- **F00 (oficinas):** a migration precisa rodar no banco de cada oficina (o `migrate-safe.php` só migra o banco principal). Sem ela o sistema não quebra, mas a marcação de frota não existe.
- **F03 (piloto/modo de teste):** nenhum prazo novo; `critico_*` continuam imediatos; nada a dividir por 10. "Restaurar barreiras padrão" não muda (não criei barreira).
- **F02 (notificação/SMS):** o aviso na hora ao gerente e ao proprietário depende da entrega dos alertas críticos (push e SMS) que a F02 trata.

**Riscos técnicos**
- Arquivos da nota no disco `public` (por tenant, fora da pasta do projeto: `Tenant/ResolveTenant.php:107-123`), nome aleatório. Têm CPF/CNPJ do cliente. Mitigação: só link autenticado na tela, acesso registrado em `acessos_sensiveis`. Uma pasta privada por oficina exigiria editar o pacote Tenant (F00): fica como melhoria.
- NF-e (peças) pela Focus nunca foi exercitada de ponta a ponta (certificado digital, bloco de ICMS assumindo Simples Nacional/CSOSN 102, NCM das peças). O caminho manual (número + arquivo) é a saída enquanto isso.
- Marcação "faturamento mensal" é por pessoa (cliente da OS). Transportadora com vários contatos cadastrados como clientes separados precisa marcar cada um. Não olha a organização.
- Estorno ou chargeback depois do pagamento confirmado não desfaz `pagamento_confirmado_em` (o boleto vira "Cancelado", a OS segue "paga"). Fica como limitação conhecida.
- A barreira `pronto_nao_faturado` só fecha com `os_enviada_financeiro`; numa saída forçada sem o consultor ter enviado a OS ao financeiro, ela fica aberta (CON-07 vermelho para aquela OS, o que é coerente com a trava). Não alterei `BarrierRule::DEFAULTS` para não tocar as regras já semeadas.
- `StageGate::divergenciaDePecasPendente` faz uma consulta por OS; usado em listas (rotina do financeiro) é aceitável (poucas OS em Faturamento).
- Memória estática de `Schema::hasColumn` (`ClienteFaturamento`, `TravaService`): em produção é por requisição; nos testes use `ClienteFaturamento::esquecer()`.
- Tipos de arquivo: `mimes:xml,pdf` confere o tipo pelo conteúdo; o XML não é validado como nota fiscal (só anexado).

**Avisos para a documentação (`RETOMAR-TRABALHO.md`, a registrar no relatório do implementador)**
- Frota com faturamento mensal agora é uma marcação no cadastro do cliente e dispensa só o pagamento; a nota é sempre obrigatória.
- Trava da saída forçada nasce na hora para o consultor da OS e para os financeiros; o gerente decide em Bônus > Travas.
- Variáveis `ASAAS_WEBHOOK_TOKEN` e `FOCUSNFE_WEBHOOK_SECRET` passam a ser obrigatórias com o piloto ligado.
- Nova migration `2026_11_18_090100`: coluna `persons.faturamento_mensal` e tabela `cliente_faturamento_historico`.

---

## Decisões em aberto

1. **Pagamento parcial libera a saída?** Hoje qualquer boleto "Pago" libera. Recomendação (já na especificação): só confirma sozinho quando a soma das cobranças pagas cobre o total da OS; entrada parcial exige confirmação manual do Financeiro ou o acordo assinado. Para voltar ao comportamento atual, basta `pagamentoCobertoPorCobrancas()` devolver "existe boleto pago".
2. **Uma nota basta ou exige NFS-e e NF-e?** O PDF fala em "nota registrada" (singular). Recomendação: basta uma nota (de qualquer tipo) para liberar a saída, com aviso amarelo (sem bloquear) quando a OS tem peças e só há nota de serviço. Exigir uma nota por tipo é possível depois, sem refazer a tela (o tipo já é gravado).
3. **Quem pode forçar a saída?** Recomendação: Gerente e Proprietário (permissão `service_orders.forcar_avanco`); Consultor e Financeiro pedem ao gerente. A trava para consultor e financeiro continua fazendo sentido, porque são eles que "liberaram" o caminhão. Se o patrão quiser que o próprio Consultor/Financeiro possam forçar, basta dar a permissão a esses perfis.
4. **Marcar "faturamento mensal" precisa de aprovação do dono?** Recomendação: não nesta fase; permissão própria (Financeiro, Gerente, Proprietário), motivo obrigatório e histórico que nada apaga. O tema `contratos_frota` da matriz de alçadas já existe (`SO/Services/Alcada/AlcadaCatalog.php:44`) mas não está ligado a nenhuma ação; se o patrão quiser o "Dono aprova" por contrato de frota, liga-se nesse tema depois.
5. **OS de retorno/garantia sem cobrança dispensa nota e pagamento na saída?** Recomendação: sim (regra mínima já prevista em `saidaSemCobranca()`); a F12 e o patrão confirmam o critério fino (janela de garantia da Ficha).

---

## Passo a passo para o usuário

**Depois que a equipe subir o código**
1. Rodar `public/migrate-safe.php` uma vez (cria a coluna e a tabela novas). Ele migra só o banco principal: o banco de cada oficina (inclusive a "Oficina Teste Mentoria") também precisa da migration; combinar com a frente das oficinas como isso será feito na HostGator. Sem a migration o sistema funciona, mas a marcação de frota não aparece.
2. No `.env` do servidor, definir `ASAAS_WEBHOOK_TOKEN` (um segredo longo), `FOCUSNFE_WEBHOOK_SECRET` (outro segredo longo) e, para testar, manter `FOCUSNFE_BASE_URL` em homologação (só mudar para `https://api.focusnfe.com.br` quando for emitir de verdade). Eu não mexo no `.env`.
3. **Focus NFe (cada oficina):** Configurações > Chaves de API: cadastrar a chave com o tipo "Focus NFe"; Configurações Fiscais: CNPJ, inscrição municipal, código do município, item da lista de serviço (NFS-e) e, para NF-e, inscrição estadual, CFOP, regime tributário; no painel da Focus subir o certificado digital (NF-e) e cadastrar o gatilho (webhook) apontando para `https://<oficina>.volmastercrm.com/fiscal/focusnfe/webhook?secret=<o mesmo segredo do .env>` (confirmar na Focus o formato exato do gatilho); preencher o NCM das peças em Estoque.
4. **Asaas (cada oficina):** Configurações > Chaves de API: cadastrar a chave com o tipo "Asaas"; no painel do Asaas criar o webhook em `https://<oficina>.volmastercrm.com/service-orders/asaas/webhook`, com o "Token de autenticação" igual ao `ASAAS_WEBHOOK_TOKEN`, e marcar os eventos de pagamento recebido e confirmado (opcionalmente os de estorno). Ficar atento: a URL tem de ser a do subdomínio da oficina.
5. **Permissões das funções (Configurações > Funções):** Financeiro, Gerente e Proprietário recebem "Emitir" em Documentos Fiscais (`fiscal_documents.emit`) e "Marcar cliente com faturamento mensal (frota)"; Gerente e Proprietário recebem "Forçar avanço de etapa"; todos os que lidam com dinheiro precisam de "Ver valores financeiros". Técnico e Peças sem valores ficam sem essas.
6. **Clientes de frota:** abrir cada cliente (Contatos > Pessoas > cliente), na seção "Sobre a pessoa", marcar "Cliente de frota com faturamento mensal" e escrever o motivo (contrato). O número de frota do veículo não vale mais para nada no faturamento.

**Roteiro de teste na Oficina Teste (linhas 45 a 50), com o piloto ligado**
1. Levar uma OS até o Faturamento. Fazer o checklist de saída (linha 45).
2. Linha 46: no passo "Conferir peças aplicadas × lançadas" marcar "Com divergência" e descrever; conferir que o Financeiro não consegue registrar nota nem gerar cobrança e que o aviso vermelho aparece; entrar como Peças, registrar "Sem divergência" descrevendo a correção; conferir que liberou.
3. Linha 47: clicar em "Registrar saída do caminhão" sem nota: o botão fica desabilitado e a lista mostra o que falta.
4. Linha 48: como Gerente, preencher a justificativa e clicar em "Forçar a saída". Conferir: alerta crítico para Gerente e Proprietário (no celular, se a notificação estiver instalada), as duas travas pendentes em Bônus > Travas (consultor e financeiro) e o selo "saídas sem NF" no relatório diário do financeiro.
5. Linha 49: em outra OS, emitir a NFS-e (ou registrar a nota à mão com número e arquivo), gerar a cobrança pelo Asaas, pagar pelo link de teste e conferir que a OS mostra "Pago" sozinha e libera o botão.
6. Linha 50: em uma OS de cliente marcado como frota com faturamento mensal, registrar só a nota e conferir que a saída libera sem pagamento.
7. Anotar o resultado de cada linha (aprovada ou reprovada) para o critério de liberação.
