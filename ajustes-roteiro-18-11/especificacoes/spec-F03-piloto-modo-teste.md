# Especificação — F03-piloto-modo-teste

Frente: **Condições para ligar o piloto e modo de teste das barreiras** (itens **A5** e **A6** do PDF "Roteiro de Teste e Ajustes do Software", 18/11/2026).

Convenções deste documento
- Caminhos sem prefixo são relativos a `packages/Webkul/ServiceOrder/src`. Caminhos que começam por `tests/` são relativos à raiz do Laravel (`d:\volmaster crm\volmaster crm`).
- Os números de linha são do código de hoje (antes de qualquer frente desta rodada); outras frentes vão deslocá-los, então use também o nome da função.
- Textos de interface ficam fixos em pt-BR dentro de controllers, services e views, como todo o restante do módulo da mentoria. **Não criar chaves de idioma** para esta frente: nos testes `APP_LOCALE` é `en` e `trans()` sairia em inglês.
- **Nenhuma migration** nesta frente (tudo vai em `workshop_settings`, que já é chave/valor, e em `mentoria_registros`, que já existe).

---

## Resumo da situação

| Bullet do PDF | Situação hoje | O que esta frente faz |
|---|---|---|
| A5 · cadastrar um colaborador por cargo, ligado a um usuário | **Existe** a tela (Colaboradores) | Nada de código. Ação manual do patrão. A sincronização cargo ↔ perfil do usuário é da F04 |
| A5 · Ficha com valor da hora, impostos, comissões, despesas variáveis, margem de peças, lucro, encargos, início da mentoria, meta, gatilho da Fase 2 e % de bônus por cargo | **Existe**, todos os campos | Teste de regressão que garante os campos + lista "recomendados" na tela do piloto |
| A5 · obrigatórios para ligar o piloto: valor da hora, impostos, início da mentoria, 1 colaborador Gerente ou Proprietário | **Falta** | Classe `PilotoRequisitos` + trava em `WorkshopSettingsController::updateGeneral` |
| A5 · a chave mostra o que falta e não liga | **Falta** | Lista na tela (com links) + aviso após a tentativa |
| A5 · piloto já ligado não é desligado por isso | Regra de projeto | Garantida no controller e coberta por teste |
| A5 · Teste "tentar ligar com a Ficha incompleta mostra a lista" | **Falta** teste | `PilotoRequisitosTest` |
| A6 · ligar o piloto só na oficina de teste | **Existe** (a chave é por banco de oficina) | Nada de código |
| A6 · configurar o horário comercial | **Existe** | Nada de código (decisão documentada sobre o modo de teste) |
| A6 · restaurar as barreiras padrão | **Existe e é seguro**, mas sem teste e com mensagem genérica | Mensagem que diz o que foi restaurado + teste |
| A6 · modo de teste ÷ 10 | **Falta** | `ModoTeste` + pontos de cálculo (tabela abaixo) + tela + selo |
| A6 · selo "MODO DE TESTE (prazos ÷ 10)" | **Falta** | Partial Blade incluído onde alerta e prazo aparecem |
| A6 · Teste "OS sem mecânico e sem diagnóstico não avança para Orçamento" | **Parcial** (o bloqueio existe, o teste literal não) | Teste novo + endurecimento do Diagnóstico → Orçamento (exige "Técnico designado") |

---

## O que já existe

### Piloto, Ficha e Colaboradores (A5)
- **Chave do piloto**: `Http/Controllers/WorkshopSettingsController.php:55-114` (`updateGeneral`). A gravação é a linha 87, `WorkshopSettings::set('piloto_ativo', request()->boolean('piloto_ativo'))`, **sem nenhuma validação**. O checkbox está em `Resources/views/settings/workshop.blade.php:28-34`. Leitura central em todo o código: `Services/StageGate.php:23-26` (`StageGate::active()`).
- **Colaboradores NÃO ficam no pacote Mechanic.** Ficam em `Models/Colaborador.php` (tabela `colaboradores`). O "perfil" do colaborador é a coluna `cargos` (string de até 120 caracteres, chaves separadas por vírgula, na ordem fixa de `WorkshopSettingsController::PAPEIS`, `:24-33`: `proprietario, gerente, chefe, consultor, tecnico, pecas, financeiro, zeladoria`). Também tem `ativo` (bool), `user_id` e `mechanic_id` (ambos opcionais). `Colaborador::scopeAtivos` (`:55-58`), `scopeComCargo` (`:60-63`, usa `FIND_IN_SET`), `cargosLista()` (`:45-48`).
- Tela e cadastro: `Http/Controllers/ColaboradorController.php` (`dados()` em `:87-126` grava `cargos` com `implode(',')` filtrando pelos cargos válidos), rotas `admin.colaboradores.*` (`Routes/web.php:236-243`), view `Resources/views/colaboradores/index.blade.php:57-63` (checkboxes de cargo) e `:36-40` (select "Usuário do sistema").
- O **perfil do usuário do sistema** é outra coisa: `roles.perfil_oficina` (lista por vírgula), lido por `Services/RecipientResolver.php:40-45` (`byPerfil`). Hoje não há sincronização com o colaborador (isso é a F04).
- **Ficha de Enquadramento**: `Http/Controllers/FichaController.php:35-106` (`update`) e `Resources/views/ficha/edit.blade.php`. **Todos os campos do A5 já existem** (a chave é a de `WorkshopSettings`):

| Campo do A5 | Chave | Tela (`ficha/edit.blade.php`) | Gravação (`FichaController.php`) |
|---|---|---|---|
| Valor da hora | `valor_hora_mao_obra` | `:47` | `:45`, `:80` |
| Impostos | `pct_impostos` | `:62` | `:57`, `:80` |
| Comissões | `pct_comissoes` | `:63` | `:58`, `:80` |
| Despesas variáveis (de peças) | `pct_desp_var_pecas` | `:64` | `:59`, `:80` |
| Margem desejada de peças | `margem_desejada_pecas` | `:65` | `:60`, `:80` |
| Lucro desejado | `lucro_desejado` | `:66` | `:61`, `:80` |
| Encargos | `encargos_percentual` | `:69` | `:64`, `:80` |
| Data de início da mentoria | `inicio_mentoria` | `:77` | `:56`, `:72` |
| Meta de faturamento | `meta_faturamento_mensal` | `:78` | `:55`, `:80` |
| Gatilho da Fase 2 | `gatilho_resultado` | `:79` | `:54`, `:80` |
| % de bônus por cargo | `bonus_cheio_percentual` (mapa cargo → %) | `:83-86` (todos os cargos, menos o proprietário) | `:66-67`, `:91-101` |

  (Só "modelo de remuneração variável" não existe; é da F14.) O CNPJ também existe (`ficha_cnpj`, `:28`) mas **não** é exigido pelo A5.
- `Services/FichaEnquadramento.php`: `CARGOS_OBRIGATORIOS` (`:24`), `cargosSemResponsavel()` (`:59-64`), `mesDaMentoria()`/`fase()` (`:29-52`). Nada disso bloqueia o piloto.
- **Efeito colateral de teste**: `tests/Feature/Workshop/HttpFlowTest.php:305-342` liga o piloto pela tela com Ficha vazia e sem colaborador. Vai quebrar quando a trava existir (ver "Testes").

### Barreiras, prazos e modo de teste (A6)
- Horário comercial: `settings/workshop.blade.php:36-50`, gravado em `updateGeneral` (`:76-85`, chave `horario_comercial`; padrão seg-sex 8-18 e sáb 8-12 em `Services/WorkshopSettings.php:24-31`). **Sem nenhum dia marcado a lista fica vazia e `Services/BusinessHours.php` conta em relógio corrido** (`:33-36`, `:76-78`, `:111-113`).
- Silêncio: `Services/AlertDispatcher.php:74-89` (`inQuietHours`), configurado em `settings/workshop.blade.php:55-61`.
- **Barreiras padrão**: `Models/BarrierRule.php:53-329` (`DEFAULTS`, 20 barreiras), `ensureDefaults()` (`:347-354`, `firstOrCreate` por `codigo`: só cria o que falta, nunca sobrescreve nem apaga), `ensureDefaultsOnce()` (`:359-364`, chamada pelo motor em `BarrierEngine.php:44` e `:86`). Rota e botão: `Routes/web.php:140`, `settings/workshop.blade.php:107` ("Restaurar as padrão da mentoria que faltam"), `WorkshopSettingsController::restoreRules` (`:151-158`, mensagem genérica). **Não existe teste da rota.**
- **Onde um prazo vira data** (único lugar que gera `vence_em`, `nivel2_em`, `nivel3_em`): `Services/BarrierEngine.php` `open` (`:114-161`), `prazoMinutos` (`:166-177`), `dueAt` (`:179-189`), `dueFromTarget` (`:194-207`), `levelAt` (`:212-238`). As datas são **gravadas na abertura** em `barrier_instances`; o `tick` (`:77-112`) só compara com a data gravada. Por isso o divisor entra na abertura e vale para as barreiras abertas daqui para frente.
- Bloqueio de etapa: `Services/StageAdvanceService.php:34-81`, `Services/StageGate.php:33-52` (`missing`), `osParaDiagnostico` (`:160-163`), `diagnosticoParaOrcamento` (`:165-196`), `Models/ServiceOrder.php:183-190` (`canAdvanceTo`: só a etapa seguinte), `Observers/ServiceOrderObserver.php:105-109` (com o piloto ligado e usuário logado, qualquer troca de etapa fora do `StageAdvanceService` é recusada), `Http/Controllers/ServiceOrderController.php:75-115` (`updateStage`).
- Testes que já cobrem partes do teste do A6: `tests/Feature/Workshop/StageGateTest.php:19` (Diagnóstico → Orçamento lista o que falta), `:110` (OS → Diagnóstico exige técnico), `:238` (troca de etapa por fora do botão é recusada); `tests/Feature/Workshop/HttpFlowTest.php:25` (HTTP: avançar para Orçamento é recusado com "Falta:"), `:46` (forçar exige permissão). **Nenhum** monta a OS "sem mecânico e sem diagnóstico" de ponta a ponta.

### Mapa de TODOS os pontos onde prazo, antecedência ou escalonamento é calculado

| # | Ponto | Onde | O que calcula | Decisão no modo de teste |
|---|---|---|---|---|
| 1 | Abertura de barreira (modos `minutos`, `dinamico`, `fim_do_dia`, `data_alvo`) | `BarrierEngine.php:114-238` | `vence_em`, `nivel2_em`, `nivel3_em` | **Divide** (é o único gerador de datas de barreira) |
| 2 | Verificação periódica | `BarrierEngine.php:77-112`, `:315-342` | Compara com datas gravadas | Não muda |
| 3 | Barreira de operação (% do tempo padrão) | `BarrierEngine.php:344-378` | Tempo trabalhado × padrão | Não muda; o efeito vem de `OperationService::padraoSeconds` (sub-opção, linha 17 desta tabela) |
| 4 | `BarrierRule` | `Models/BarrierRule.php` | Só dados (`DEFAULTS`, `offsetMinutos`) | Não muda: o prazo **real** continua gravado |
| 5 | `BarrierConditions` | `Services/BarrierConditions.php:16-31` | Condições (peças, NF, etapa < teste) | Não tem prazo; nada a fazer |
| 6 | `BusinessHours` | `Services/BusinessHours.php` | Calendário de expediente | **Continua valendo** (ver "Regra do modo de teste", abaixo); só `addMinutes` passa a aceitar fração |
| 7 | `PrazoOs` e semáforo da lista de OS | `Services/PrazoOs.php:56-93`, `DataGrids/ServiceOrderDataGrid.php:55-61`, `:162-180` | Lêem `vence_em` gravado | Herdam (não mudam) |
| 8 | `PeriodicAlerts` | `Services/PeriodicAlerts.php:26-30`, `:41-53`, `:78` | OS parada 3 d, vermelho sem plano 7 d, RH 30 d, rodada de 1 h, resumo do dono 2 h | **Não divide**: são calendários gerenciais, sem linha no roteiro |
| 9 | Cronômetros da "Minha rotina" | `Services/Rotina/RotinaService.php:497-507` (`item`) e cortes em `:248`, `:252`, `:255` | Limite do cronômetro e listas "sem resposta", "atualizar cliente", "pós-venda" | **Divide** (senão o painel fica verde com a barreira já vencida) |
| 10 | Quadro de pátio | `RotinaService.php:75-101` | Cor pelas datas da barreira | Herda |
| 11 | Diária de pátio | `RotinaService.php:483-492` | Horas da Ficha | **Não nesta frente** (F13 é dona; interface `ModoTeste::minutos`) |
| 12 | `AlertService` | `Services/AlertService.php` | Monta alertas e texto; não calcula prazo | Não muda |
| 13 | Entrega de alertas | `AlertDispatcher.php:74-89` (silêncio), `:184-203` (SMS do nível 2 em 10 min), `:217-225` (agrupar 5 min) | Janelas de entrega | **Não divide**: silêncio é horário do dia; os outros dois são ajustáveis na própria tela |
| 14 | Prazo do dono na fila de alçadas | `Services/Alcada/AlcadaService.php:28`, `:178`, `:208`, `:340` | `prazo_dono_em` = 24 h | **Divide** (roteiro linha 31) |
| 15 | Tempo alvo do diagnóstico | `StageGate.php:104-110`, `WorkflowService.php:176-186`, `TecnicoTasks.php:61`, `:67-69`, `TecnicoController.php:37`, `tecnico/index.blade.php:164`, `:167-168`, `:268`, `partials/workflow.blade.php:174`, `:183` | "Passou do alvo" e causa do atraso | **Divide** (roteiro linha 17 só funciona assim) |
| 16 | Janela de aprovação do bônus | `Services/Bonus/BonusService.php:335-342` | Dia 1 ao 5º dia útil | Decisão 2 (recomendo: aberta no modo de teste) |
| 17 | Tempo padrão do serviço | `Services/OperationService.php:79-82` | Base do cronômetro verde/amarelo/vermelho e da barreira de operação | **Sub-opção** separada (decisão 1) |
| 18 | Indicadores "em até X" | `IndicatorCalculator.php:82-129` (`barrier()`, `:287-312`) | Lêem `cumprida_no_prazo` das instâncias | Herdam |
| 19 | Indicadores com limite fixo sobre carimbos | `IndicatorCalculator.php:510` (CON-12, 7 d), `:1019` e `:1025` (GER-09, 48 h), `:1353` e `:1359` (DON-06, 24 h) | Comparam datas com um limite | **Dividem** (coerência com os alertas) |
| 20 | Indicadores de outras frentes | `IndicatorCalculator.php:115` (CHE-15, 30 min), `:142` (PEC-07, 24 h), `:143` (PEC-08, 5 d) | Limite fixo | **Fora desta frente** (F13 e F11); devem usar `ModoTeste::minutos` |
| 21 | Financeiro | `FinanceiroService.php:30` (régua 1/7/15), `:314` (`subHours(48)`), `DreService` | Relatórios e cobrança | Não divide |
| 22 | Prazo prometido ao cliente | `ServiceOrderObserver.php:76-87` | Data digitada pelo usuário | Não divide (dado, não regra); só a **antecedência** do alerta é dividida (item 1) |
| 23 | Textos de ajuda com prazo real | `WorkflowActions.php:52`, `:78`, `:132`, `:143`, `:562` | Texto fixo ("meta: 10 min") | Não muda; o selo avisa que os prazos estão divididos |

---

## Requisitos do PDF → como serão atendidos

### A5

1. **"Na oficina de teste, cadastrar um colaborador por cargo, cada um ligado a um usuário do sistema."** Já existe a tela. Ação manual (ver "Passo a passo"). Sem código aqui; a sincronização cargo ↔ perfil do usuário é da F04.
2. **"Preencher toda a Ficha... (11 campos)."** Todos os campos já existem (tabela acima). Ação manual. Para o patrão enxergar o que ainda está em branco, a tela do piloto mostra uma lista **"recomendados"** (não bloqueia) com os 8 campos que não são obrigatórios. Teste de regressão garante que o formulário da Ficha continua tendo todos os `name` do A5.
3. **"Tornar obrigatórios para ligar o piloto... Se faltar algo, a chave do piloto mostra o que falta e não liga."** **Falta.** Novo `Services/PilotoRequisitos.php` e trava em `updateGeneral`. Detalhes em "Mudanças por arquivo", itens 12 e 13.
4. **Um piloto que já está ligado não é desligado por isso** (pedido adicional da tarefa). A trava só roda na transição desligado → ligado. Quem está ligado e continua marcado fica ligado mesmo com a Ficha incompleta; a tela só avisa. Desmarcar continua desligando, sem exigência.
5. **"Teste: tentar ligar o piloto com a Ficha incompleta mostra a lista do que falta."** Coberto por `PilotoRequisitosTest`, casos 1 a 3.

### A6

1. **"Ligar a chave 'Piloto de indicadores e alertas' só na oficina de teste."** Já existe: cada oficina tem o próprio banco e o próprio `workshop_settings`. Ação manual.
2. **"Configurar o horário comercial."** Já existe (`settings/workshop.blade.php:36-50`). Dica que passa a constar na tela do modo de teste: sem nenhum dia marcado os prazos contam em relógio corrido.
3. **"Restaurar as barreiras padrão da mentoria."** Já funciona e é seguro (`BarrierRule::ensureDefaults`: cria só as que faltam, não sobrescreve prazos editados, não apaga, é idempotente). Faltam: mensagem dizendo **o que foi restaurado** e um teste da rota (`PilotoOrcamentoGateTest`, casos 4 e 5).
4. **"Criar o 'modo de teste' das barreiras: um campo que divide todos os prazos por 10 (ex.: 2 h vira 12 min), só para a oficina de teste."** **Falta.** Detalhes completos abaixo (classe `ModoTeste`, mapa de pontos, tela, selo, auditoria).
5. **"Teste: com o piloto ligado, uma OS sem mecânico e sem diagnóstico NÃO avança para Orçamento."** **Parcial.** O bloqueio existe em duas etapas (OS → Diagnóstico exige "Técnico designado"; Diagnóstico → Orçamento exige laudo, evidência, finalização, visto e relação de peças), mas havia um furo: uma OS que entra no Diagnóstico à força (com justificativa) sem técnico pode ser diagnosticada por outra pessoa e chegar ao Orçamento sem nenhum técnico responsável (`WorkflowService::startDiagnostico` não exige técnico). Esta frente (a) escreve o teste literal e (b) faz o Diagnóstico → Orçamento exigir também **"Técnico designado"** (decisão 3).

### Regra do modo de teste (decisões de projeto)

- **Só vale com o piloto ligado.** `ModoTeste::ativo()` = `StageGate::active()` **e** a caixa marcada. Com o piloto desligado nada muda (regra inegociável 2), mesmo que a caixa esteja marcada no banco.
- **Por oficina.** A caixa mora em `workshop_settings` do banco da oficina; logo só afeta a oficina em que foi marcada ("só para a oficina de teste").
- **Divide a duração, não o calendário.** O que é dividido: prazos em minutos das barreiras, antecedência do prazo prometido, níveis 2 e 3 (em minutos, em % do prazo e em dias), barreiras de "fim do dia" (o tempo que falta até o fechamento), tempo alvo do diagnóstico, limites dos cronômetros da rotina, prazo de 24 h do dono e os limites fixos de GER-09, DON-06 e CON-12. Exemplos: 2 h vira 12 min; 24 h vira 2 h 24 min; 36 h vira 3 h 36 min; 48 h vira 4 h 48 min; 7 dias vira 16 h 48 min; "1 dia útil" vira 2 h 24 min.
- **Horário comercial: continua valendo exatamente como configurado.** Os 12 minutos são contados dentro do expediente. Motivo: o horário comercial faz parte do que o roteiro testa (A6 manda configurá-lo) e dividir uma janela de expediente não tem sentido. Para testar fora do expediente, o patrão deixa **todos os dias desmarcados** (relógio corrido) ou alarga o horário da oficina de teste. A tela do modo de teste explica isso.
- **Silêncio noturno: não é alterado.** É um horário do dia, e a linha 60 do roteiro ("Alerta durante o horário de silêncio: o comum espera, o crítico toca na hora") precisa dele funcionando. Quem rodar o roteiro à noite deve desligar o silêncio ou ajustar a janela. A janela de agrupamento (5 min) e a espera do SMS do nível 2 (10 min) também não são divididas; são campos editáveis na mesma tela.
- **Regra de ouro (contrato com as outras frentes): tudo que é gravado é o prazo REAL** (regra de barreira, tipo de queixa, eventos como `prazo_precificacao_min`, colunas da OS). O divisor só é aplicado (a) em `BarrierEngine` ao transformar prazo em data, (b) na hora de decidir "passou do alvo/limite" e (c) na exibição de cronômetros. Assim, desligar o modo de teste devolve o comportamento normal sem tocar em dado nenhum.
- **Vale para as barreiras abertas daqui para frente.** As já abertas mantêm o prazo com que nasceram (mesma semântica de "Vale para as próximas OS" da edição de barreira). Ao desligar, as abertas durante o teste mantêm o prazo curto até serem cumpridas.
- **Sub-opção: dividir também o tempo padrão dos serviços** (`modo_teste_tempo_padrao`, desligada por padrão). O tempo padrão é propriedade do serviço, não um "prazo"; as linhas 36 a 38 do roteiro (serviço de 1 h: verde até 1 h, amarelo até 1 h 12, vermelho depois) levariam 1 h 12 min sem ela.
- **Auditoria.** Ligar/desligar o modo de teste e ligar/desligar/recusar o piloto geram um registro em `mentoria_registros` (só inserção), com usuário e hora.

---

## Mudanças por arquivo

### 1. CRIAR `Services/ModoTeste.php`

```php
<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;

/**
 * Modo de teste das barreiras (item A6 do roteiro): divide os prazos para o roteiro
 * de teste não levar dias. Só vale com o piloto ligado e é gravado por oficina
 * (workshop_settings), então afeta apenas a oficina em que foi marcado.
 *
 * Regra de ouro: o que fica gravado (regra, tipo de queixa, evento, coluna da OS) é
 * SEMPRE o prazo real. O divisor entra só onde o prazo vira data (BarrierEngine), onde se
 * decide "passou do alvo" e na exibição dos cronômetros.
 */
class ModoTeste
{
    public const DIVISOR_PADRAO = 10;

    public static function ativo(): bool
    {
        return StageGate::active() && (bool) WorkshopSettings::get('modo_teste_barreiras', false);
    }

    /** Sub-opção: divide também o tempo padrão dos serviços (cronômetro do técnico). */
    public static function tempoPadraoAtivo(): bool
    {
        return static::ativo() && (bool) WorkshopSettings::get('modo_teste_tempo_padrao', false);
    }

    public static function divisor(): int
    {
        $valor = (int) WorkshopSettings::get('modo_teste_divisor', self::DIVISOR_PADRAO);

        return $valor >= 2 && $valor <= 100 ? $valor : self::DIVISOR_PADRAO;
    }

    /** 1 com o modo desligado; o divisor com o modo ligado. */
    public static function fator(): int
    {
        return static::ativo() ? static::divisor() : 1;
    }

    /** Minutos reais -> minutos efetivos. Desligado, devolve o próprio valor (mesmo tipo). */
    public static function minutos(int|float $reais): int|float
    {
        $fator = static::fator();

        return $fator === 1 ? $reais : $reais / $fator;
    }

    /** $de + o prazo efetivo (relógio corrido). */
    public static function depois(Carbon $de, int|float $minutosReais): Carbon
    {
        return $de->copy()->addSeconds((int) round(static::minutos($minutosReais) * 60));
    }

    /** $de - o prazo efetivo (relógio corrido). */
    public static function antes(Carbon $de, int|float $minutosReais): Carbon
    {
        return $de->copy()->subSeconds((int) round(static::minutos($minutosReais) * 60));
    }

    /** "MODO DE TESTE (prazos ÷ 10)" ou null com o modo desligado. */
    public static function selo(): ?string
    {
        return static::ativo() ? 'MODO DE TESTE (prazos ÷ '.static::divisor().')' : null;
    }

    /** 4.5 -> "4,5"; 60.0 -> "60". */
    public static function numero(int|float $valor): string
    {
        return rtrim(rtrim(number_format((float) $valor, 2, ',', ''), '0'), ',');
    }

    /** 1440 -> "24 h"; 144 -> "2 h 24 min"; 12 -> "12 min"; 1.5 -> "1 min 30 s". */
    public static function duracao(int|float $minutos): string
    {
        $segundos = (int) round($minutos * 60);
        $partes = [];

        if ($horas = intdiv($segundos, 3600)) {
            $partes[] = $horas.' h';
        }

        if ($min = intdiv($segundos % 3600, 60)) {
            $partes[] = $min.' min';
        }

        if ($seg = $segundos % 60) {
            $partes[] = $seg.' s';
        }

        return $partes ? implode(' ', $partes) : '0 min';
    }
}
```

Observações: `ativo()` consulta `WorkshopSettings` (cache estático por requisição; o `workshop:tick` já faz `WorkshopSettings::flush()` ao trocar de oficina, `Console/Commands/WorkshopTickCommand.php:91`). O parâmetro `Carbon` aceita `now()` (`Illuminate\Support\Carbon` estende `Carbon\Carbon`).

### 2. ALTERAR `Services/WorkshopSettings.php` (`DEFAULTS`, logo depois de `'piloto_ativo' => false,`, linha 20)

```php
        // Modo de teste das barreiras (A6): divide os prazos por 'modo_teste_divisor'. Só vale
        // com o piloto ligado e só na oficina em que foi marcado. Ver Services/ModoTeste.php.
        'modo_teste_barreiras' => false,
        // Sub-opção: divide também o tempo padrão dos serviços (cronômetro do técnico).
        'modo_teste_tempo_padrao' => false,
        'modo_teste_divisor' => 10,
```

### 3. ALTERAR `Services/BusinessHours.php` (`addMinutes`, `:25-65`)

Aceitar fração de minuto (o modo de teste gera 1,5 min e 4,5 min). Só a assinatura e as duas primeiras decisões mudam:

```php
public function addMinutes(CarbonInterface $from, int|float $minutes): Carbon
{
    $cursor = Carbon::instance($from)->copy();

    if ($minutes <= 0) {
        return $cursor;
    }

    $remaining = (int) round($minutes * 60);           // segundos

    // Sem horário configurado: conta em relógio corrido.
    if (empty($this->schedule)) {
        return $cursor->addSeconds($remaining);
    }

    for ($guard = 0; $guard < 800; $guard++) {         // laço original, inalterado
        ...
    }
    return $cursor;
}
```
Com `fator = 1` o resultado é idêntico ao de hoje (`minutos × 60` segundos).

### 4. ALTERAR `Services/BarrierEngine.php`

Adicionar `use` de `ModoTeste` não é necessário (mesmo namespace). Substituir `dueAt`, `dueFromTarget` e `levelAt` e acrescentar `somar` e `fimDoDia`. `open`, `prazoMinutos`, `tick`, `tickMinutes` e `tickOperation` **não mudam** (o prazo continua real até o ponto de uso).

```php
protected function dueAt(BarrierRule $rule, ServiceOrderEvent $event, Carbon $openedAt, ?int $prazo): ?Carbon
{
    $hours = new BusinessHours;
    $calendar = $rule->critico || ! $rule->conta_horario_comercial;

    return match ($rule->modo) {
        BarrierRule::MODO_FIM_DO_DIA => $this->fimDoDia($openedAt, $calendar, $hours),
        BarrierRule::MODO_DATA_ALVO => $this->dueFromTarget($event, $prazo),
        default => $prazo === null ? null : $this->somar($openedAt, $prazo, $calendar, $hours),
    };
}

/** Soma $minutos (reais, da regra) a $de, já com o divisor do modo de teste. */
protected function somar(Carbon $de, int|float $minutos, bool $calendar, BusinessHours $hours): Carbon
{
    $efetivos = ModoTeste::minutos($minutos);

    return $calendar ? $de->copy()->addSeconds((int) round($efetivos * 60)) : $hours->addMinutes($de, $efetivos);
}

/**
 * "Fim do dia": o fechamento do expediente (ou do dia corrido). No modo de teste, o tempo que
 * falta até o fechamento é dividido, contado como o resto da regra.
 */
protected function fimDoDia(Carbon $abertaEm, bool $calendar, BusinessHours $hours): Carbon
{
    $fim = $calendar ? $abertaEm->copy()->endOfDay()->startOfSecond() : $hours->endOfBusinessDay($abertaEm);

    if (! ModoTeste::ativo()) {
        return $fim;
    }

    $restante = $calendar
        ? max(0, $fim->getTimestamp() - $abertaEm->getTimestamp()) / 60
        : $hours->diffMinutes($abertaEm, $fim);

    return $this->somar($abertaEm, $restante, $calendar, $hours);
}

protected function dueFromTarget(ServiceOrderEvent $event, ?int $antecedencia): ?Carbon
{
    $target = $event->dados_json['prazo_em'] ?? null;

    if (! $target) {
        return null;
    }

    try {
        // A data prometida é do cliente e não muda; só a antecedência do alerta é dividida.
        return Carbon::parse($target)->subSeconds((int) round(ModoTeste::minutos((int) $antecedencia) * 60));
    } catch (\Throwable) {
        return null;
    }
}

protected function levelAt(BarrierRule $rule, int $nivel, Carbon $dueAt, ?int $prazo): ?Carbon
{
    if (! $rule->destinos($nivel)) {
        return null;
    }

    $hours = new BusinessHours;
    $calendar = $rule->critico || ! $rule->conta_horario_comercial;
    $percentual = (int) $rule->{"nivel{$nivel}_apos_percentual"};
    $offset = $rule->offsetMinutos($nivel);

    // % do prazo real, arredondado em minutos como sempre foi; o divisor entra em somar().
    if ($percentual > 0 && $prazo) {
        return $this->somar($dueAt, (int) round($prazo * $percentual / 100), $calendar, $hours);
    }

    if ($offset === null) {
        return null;
    }

    if ($rule->unidade_offset === 'dias') {
        // Modo de teste: N dias viram N x 24 h ÷ divisor, contados como os demais prazos.
        if (ModoTeste::ativo()) {
            return $this->somar($dueAt, $offset * 1440, $calendar, $hours);
        }

        return $calendar ? $dueAt->copy()->addDays($offset) : $hours->addBusinessDays($dueAt, $offset);
    }

    return $this->somar($dueAt, $offset, $calendar, $hours);
}
```

Com o modo desligado cada ramo produz exatamente a mesma data de hoje (`addSeconds(min × 60)` equivale a `addMinutes(min)`; o ramo `'dias'` fica intacto).

### 5. Tempo alvo do diagnóstico (item 15 do mapa)

- `Models/DiagnosticoQueixaTipo.php`: novo método
  ```php
  /** Tempo alvo em minutos, com o divisor do modo de teste (o cadastro guarda o valor real). */
  public function tempoAlvoEfetivoMin(): int|float
  {
      return \Webkul\ServiceOrder\Services\ModoTeste::minutos((int) $this->tempo_alvo_min);
  }
  ```
- `Services/StageGate.php:104-110` (`diagnosticoPassouDoAlvo`): trocar `$serviceOrder->queixaTipo?->tempo_alvo_min` por `$serviceOrder->queixaTipo?->tempoAlvoEfetivoMin()`.
- `Services/WorkflowService.php:176-186` (`finishDiagnostico`):
  ```php
  $alvo = $os->queixaTipo?->tempo_alvo_min;                 // real: vai para o evento, como hoje
  $alvoEfetivo = $os->queixaTipo?->tempoAlvoEfetivoMin();   // com o divisor do modo de teste
  $passou = $alvoEfetivo && $minutos > $alvoEfetivo;
  ...
  'causa_atraso' => ['O diagnóstico passou do tempo alvo ('.ModoTeste::numero($alvoEfetivo).' min): informe a causa do atraso.'],
  ```
  O evento `diagnostico_fim` continua gravando `'tempo_alvo_min' => $alvo` (real) e `'passou_do_alvo' => (bool) $passou` (já com o critério do modo). O evento `diagnostico_inicio` (`:146-149`) também continua gravando o alvo real; quem escala é o `BarrierEngine`.
- `Services/TecnicoTasks.php:61` e `:67-69`: usar `$os->queixaTipo?->tempoAlvoEfetivoMin()` em `'tempo_alvo_min'` e na comparação de `'passou_do_alvo'` (a condição `&& $os->queixaTipo?->tempo_alvo_min` fica como está, só testa se existe alvo).
- `Http/Controllers/TecnicoController.php:37`: devolver o alvo efetivo para o select da tela do técnico:
  ```php
  'queixaTipos' => DiagnosticoQueixaTipo::where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'tempo_alvo_min'])
      ->map(fn ($tipo) => ['id' => $tipo->id, 'nome' => $tipo->nome, 'tempo_alvo_min' => $tipo->tempoAlvoEfetivoMin()])->values(),
  ```
- `Resources/views/tecnico/index.blade.php:268`: trocar `parseInt(el.dataset.alvo, 10)` por `parseFloat(el.dataset.alvo)` (o alvo pode ser 4,5).
- `Resources/views/partials/workflow.blade.php:174` e `:183`: trocar `{{ $tipo->tempo_alvo_min }}` / `{{ $serviceOrder->queixaTipo->tempo_alvo_min }}` por `{{ \Webkul\ServiceOrder\Services\ModoTeste::numero($tipo->tempoAlvoEfetivoMin()) }}` (idem para `queixaTipo`). Com o modo desligado o texto é idêntico ao atual.
- A tela de edição dos tipos de queixa (`settings/workshop.blade.php:217`) continua mostrando o valor **real**.

### 6. ALTERAR `Services/OperationService.php:79-82` (`padraoSeconds`) — sub-opção do tempo padrão

```php
public function padraoSeconds(ServiceOrderItem $item): int
{
    $segundos = (int) round(((float) $item->hours) * 3600);

    // Modo de teste (sub-opção): 1 h de tempo padrão vira 6 min. Cobre o cronômetro do técnico,
    // a barreira de operação, o motivo de atraso e os eventos operacao_inicio/fim.
    return ModoTeste::tempoPadraoAtivo() ? (int) round($segundos / ModoTeste::divisor()) : $segundos;
}
```
É o único ponto que lê o tempo padrão (`BarrierEngine.php:347`, `TecnicoTasks.php:111`, `IndicatorCalculator.php:431`, `WorkflowActions.php:503`, `partials/workflow.blade.php:439`).

### 7. ALTERAR `Services/Rotina/RotinaService.php`

(O arquivo está no namespace `Services\Rotina`: acrescentar `use Webkul\ServiceOrder\Services\ModoTeste;`. O mesmo vale para `AlcadaService` (`Services\Alcada`), `BonusService` (`Services\Bonus`) e `IndicatorCalculator` (`Services\Indicators`). As classes do namespace `Services` (`BarrierEngine`, `StageGate`, `WorkflowService`, `TecnicoTasks`, `OperationService`, `AlertDispatcher`) não precisam de `use`.)

- `item()` (`:497-507`) passa a escalar o **limite** uma única vez; os 9 chamadores continuam informando o limite real (60, 40/20, 600, 30, 480, 120, 2880, 1440, 10080), e as frentes F06/F10/F11/F12/F13, que adicionam itens, herdam o comportamento sem tocar em nada:
  ```php
  protected function item(int $os, ?string $placa, string $texto, float $decorrido = 0, ?float $limite = null, ?string $cor = null): array
  {
      // Os chamadores informam o limite REAL; no modo de teste ele vem dividido.
      $limite = $limite !== null ? (float) ModoTeste::minutos($limite) : null;

      return [
          'os' => $os ?: null,
          'placa' => $placa,
          'texto' => $texto,
          'decorrido' => $limite ? $this->duracao((int) $decorrido) : null,
          'limite' => $limite ? $this->duracao((int) round($limite)) : null,
          'cor' => $cor ?? self::cor($decorrido, $limite),
      ];
  }
  ```
- Os três cortes por data em `doConsultor`:
  - `:248` `$agora->copy()->subHours(48)` → `ModoTeste::antes($agora, 48 * 60)`
  - `:252` `$agora->copy()->subHours(20)` → `ModoTeste::antes($agora, 20 * 60)`
  - `:255` `$agora->copy()->subDays(5)` → `ModoTeste::antes($agora, 5 * 1440)`
- Não alterar os títulos das seções ("Orçamentos a enviar (2 h)" etc.): testes antigos dependem deles e o selo da página já informa a divisão.

### 8. ALTERAR `Services/Alcada/AlcadaService.php`

- `:178` → `'prazo_dono_em' => $direto ? ModoTeste::depois(now(), self::PRAZO_DONO_HORAS * 60) : null,`
- `:208` → `'prazo_dono_em' => ModoTeste::depois(now(), self::PRAZO_DONO_HORAS * 60),`
- `:340` (texto do alerta ao dono): `'... aguardando sua decisão em até '.ModoTeste::duracao(ModoTeste::minutos(self::PRAZO_DONO_HORAS * 60)).'.'`. Com o modo desligado o texto continua "em até 24 h." (`duracao(1440)` = "24 h").
- O teste antigo `tests/Feature/Workshop/AlcadasTest.php:124` (`diffInHours(...) == 24.0`) continua passando.

### 9. ALTERAR `Services/Indicators/IndicatorCalculator.php` (limites fixos que o roteiro cita)

Importar `Webkul\ServiceOrder\Services\ModoTeste`.
- `:510` (CON-12): `$recontato->ocorrido_em->lte(ModoTeste::ativo() ? ModoTeste::depois($recusa, 7 * 1440) : $recusa->copy()->addDays(7)->endOfDay())`.
- `:1019` e `:1025` (GER-09): `Carbon::parse($r->aberta_em)->addHours(48)->lt(now())` → `ModoTeste::depois(Carbon::parse($r->aberta_em), 48 * 60)->lt(now())`; e `Carbon::parse($r->fechada_em)->lte(ModoTeste::depois(Carbon::parse($r->aberta_em), 48 * 60))` no lugar de `diffInHours(...) <= 48` (equivalente com Carbon 3.11, instalado: `diffInHours` devolve float).
- `:1353` e `:1359` (DON-06): trocar `$p->encaminhado_em->copy()->addHours(24)` por `($p->prazo_dono_em ?? $p->encaminhado_em->copy()->addHours(24))`. O prazo gravado já vem dividido no modo de teste; sem ele (linhas antigas) vale +24 h.
- **Não tocar** em CHE-15 (`:115`), PEC-07 (`:142`) e PEC-08 (`:143`): são das frentes F13 e F11, que devem passar o limite por `ModoTeste::minutos()`.

### 10. ALTERAR `Services/Bonus/BonusService.php:335-342` (opcional, decisão 2)

Primeira linha de `janelaDeAprovacao`: `if (ModoTeste::ativo()) { return true; }`. A janela "dia 1 ao 5º dia útil" é de calendário e não pode ser dividida; sem isso as linhas 62 a 65 do roteiro (aprovar o bônus, exportar a folha, estorno) só passam nos 5 primeiros dias úteis do mês. A mensagem de `:289` continua igual. Desligar o modo de teste devolve a janela real.

### 11. ALTERAR `Services/AlertDispatcher.php` (opcional, baixo risco)

Marcar a notificação do celular para ninguém confundir teste com alerta real. Em `sendPushFor` (`:131-132`) e `dispatchResolved` (`:52-53`), prefixar o `title` com `'[TESTE] '` quando `ModoTeste::ativo()`. Só o título do push muda; o alerta gravado e o SMS não mudam. Se a F02 reescrever esses trechos, pode ser cortado sem prejuízo.

### 12. CRIAR `Services/PilotoRequisitos.php`

```php
<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\Schema;
use Webkul\ServiceOrder\Models\Colaborador;

/**
 * Condições para LIGAR o piloto (A5 do roteiro). Só a transição desligado -> ligado é
 * conferida: um piloto já ligado nunca é desligado por Ficha incompleta.
 */
class PilotoRequisitos
{
    /** Cargos com % de bônus esperado na Ficha (zeladoria é opcional; proprietário não tem bônus). */
    protected const CARGOS_BONUS = ['gerente', 'chefe', 'consultor', 'tecnico', 'pecas', 'financeiro'];

    /**
     * Obrigatórios para ligar.
     *
     * @return list<array{chave: string, rotulo: string, curto: string, ok: bool, onde: string, rota: string, permissao: string, obrigatorio: bool}>
     */
    public function itens(): array
    {
        return [
            $this->item('valor_hora', 'Valor da hora de mão de obra', 'valor da hora de mão de obra',
                (float) WorkshopSettings::get('valor_hora_mao_obra') > 0,
                'Ficha de Enquadramento > Valores e prazos', 'admin.ficha.edit', 'settings.ficha'),
            $this->item('impostos', 'Impostos sobre a venda (%)', 'impostos sobre a venda (%)',
                $this->preenchido('pct_impostos'),
                'Ficha de Enquadramento > Parâmetros financeiros', 'admin.ficha.edit', 'settings.ficha'),
            $this->item('inicio_mentoria', 'Data de início da mentoria', 'data de início da mentoria',
                $this->dataValida(WorkshopSettings::get('inicio_mentoria')),
                'Ficha de Enquadramento > Mentoria e bônus', 'admin.ficha.edit', 'settings.ficha'),
            $this->item('gerente_ou_proprietario', 'Colaborador ativo com o cargo de Gerente ou Proprietário',
                'colaborador ativo com o cargo de Gerente ou Proprietário',
                $this->temGerenteOuProprietario(),
                'Colaboradores', 'admin.colaboradores.index', 'colaboradores'),
        ];
    }

    /** Só o que falta (mesmo formato de itens()). */
    public function faltando(): array
    {
        return array_values(array_filter($this->itens(), fn (array $item) => ! $item['ok']));
    }

    /**
     * Campos da Ficha que o A5 manda preencher mas que NÃO impedem ligar o piloto.
     */
    public function recomendados(): array
    {
        $bonus = (array) WorkshopSettings::get('bonus_cheio_percentual', []);
        $bonusCompleto = collect(self::CARGOS_BONUS)->every(fn ($cargo) => (float) ($bonus[$cargo] ?? 0) > 0);

        return [
            $this->item('pct_comissoes', 'Comissões (%)', 'comissões', $this->preenchido('pct_comissoes'), 'Ficha de Enquadramento > Parâmetros financeiros', 'admin.ficha.edit', 'settings.ficha', false),
            $this->item('pct_desp_var_pecas', 'Despesas variáveis de peças (%)', 'despesas variáveis', $this->preenchido('pct_desp_var_pecas'), 'Ficha de Enquadramento > Parâmetros financeiros', 'admin.ficha.edit', 'settings.ficha', false),
            $this->item('margem_desejada_pecas', 'Margem desejada de peças (%)', 'margem desejada de peças', $this->preenchido('margem_desejada_pecas'), 'Ficha de Enquadramento > Parâmetros financeiros', 'admin.ficha.edit', 'settings.ficha', false),
            $this->item('lucro_desejado', 'Lucro desejado por mês (R$)', 'lucro desejado', $this->preenchido('lucro_desejado'), 'Ficha de Enquadramento > Parâmetros financeiros', 'admin.ficha.edit', 'settings.ficha', false),
            $this->item('encargos_percentual', 'Encargos sobre o salário (%)', 'encargos', $this->preenchido('encargos_percentual'), 'Ficha de Enquadramento > Parâmetros financeiros', 'admin.ficha.edit', 'settings.ficha', false),
            $this->item('meta_faturamento_mensal', 'Meta de faturamento mensal (R$)', 'meta de faturamento', (float) WorkshopSettings::get('meta_faturamento_mensal') > 0, 'Ficha de Enquadramento > Mentoria e bônus', 'admin.ficha.edit', 'settings.ficha', false),
            $this->item('gatilho_resultado', 'Gatilho de resultado da Fase 2 (R$)', 'gatilho da Fase 2', (float) WorkshopSettings::get('gatilho_resultado') > 0, 'Ficha de Enquadramento > Mentoria e bônus', 'admin.ficha.edit', 'settings.ficha', false),
            $this->item('bonus_cheio_percentual', 'Valor cheio do bônus por cargo (%)', '% de bônus por cargo', $bonusCompleto, 'Ficha de Enquadramento > Mentoria e bônus', 'admin.ficha.edit', 'settings.ficha', false),
        ];
    }

    /**
     * Avisos que não impedem ligar o piloto.
     *
     * @return list<string>
     */
    public function avisos(): array
    {
        $avisos = [];

        if (Schema::hasColumn('roles', 'perfil_oficina')) {
            $quem = app(RecipientResolver::class);

            if ($quem->byPerfil('gerente')->isEmpty() && $quem->byPerfil('proprietario')->isEmpty()) {
                $avisos[] = 'Nenhum usuário do sistema está com o papel de Gerente ou Proprietário (veja "Papéis e celulares", abaixo): os alertas de nível 2, de nível 3 e os críticos não chegarão a ninguém.';
            }
        }

        return $avisos;
    }

    /** Texto único para o aviso após a tentativa recusada (uma linha só: vai dentro de um script do layout). */
    public static function mensagemRecusa(array $faltando): string
    {
        return 'O piloto não foi ligado. Falta: '.implode('; ', array_column($faltando, 'curto')).'. As outras configurações foram salvas.';
    }

    protected function temGerenteOuProprietario(): bool
    {
        return Schema::hasTable('colaboradores')
            && Colaborador::ativos()
                ->where(fn ($q) => $q->whereRaw('FIND_IN_SET(?, cargos)', ['gerente'])->orWhereRaw('FIND_IN_SET(?, cargos)', ['proprietario']))
                ->exists();
    }

    /** Preenchido = número gravado (0 conta: o dono declarou). Vazio não conta. */
    protected function preenchido(string $chave): bool
    {
        $valor = WorkshopSettings::get($chave);

        return $valor !== null && $valor !== '' && is_numeric($valor);
    }

    protected function dataValida(mixed $valor): bool
    {
        return filled($valor) && strtotime((string) $valor) !== false;
    }

    protected function item(string $chave, string $rotulo, string $curto, bool $ok, string $onde, string $rota, string $permissao, bool $obrigatorio = true): array
    {
        return compact('chave', 'rotulo', 'curto', 'ok', 'onde', 'rota', 'permissao', 'obrigatorio');
    }
}
```

Notas: o valor da hora exige **> 0**; impostos aceita **0** (o dono declarou). O colaborador precisa estar **ativo** (um desligado não conta) e ter `gerente` ou `proprietario` em `cargos`. O vínculo com usuário do sistema **não** é exigido (decisão 4); em vez disso, `avisos()` avisa quando nenhum usuário tem o papel (é o que `RecipientResolver::byPerfil` usa para entregar alertas, e continua valendo qualquer que seja o formato de armazenamento do perfil que a F04 adotar, desde que `byPerfil` seja mantido).

### 13. ALTERAR `Http/Controllers/WorkshopSettingsController.php`

Novos `use`: `Illuminate\Support\Facades\Schema`, `Webkul\ServiceOrder\Models\MentoriaRegistro`, `Webkul\ServiceOrder\Services\ModoTeste`, `Webkul\ServiceOrder\Services\PilotoRequisitos`, `Webkul\ServiceOrder\Services\StageGate`.

**a) `edit()` (`:37-53`)** acrescenta ao array da view:
```php
'requisitos' => app(PilotoRequisitos::class)->itens(),
'recomendados' => app(PilotoRequisitos::class)->recomendados(),
'avisosPiloto' => app(PilotoRequisitos::class)->avisos(),
'divisor' => ModoTeste::divisor(),
'modoTesteAtivo' => ModoTeste::ativo(),
```

**b) `updateGeneral()` (`:55-114`)**: a validação e o resto não mudam. Trocar a linha 87 e o final:
```php
// A5: o piloto só LIGA com a Ficha mínima pronta; quem já está ligado nunca é desligado por isso.
$ligadoAntes = StageGate::active();
$quer = request()->boolean('piloto_ativo');
$faltando = ($quer && ! $ligadoAntes) ? app(PilotoRequisitos::class)->faltando() : [];
$ligar = $quer && $faltando === [];

WorkshopSettings::set('piloto_ativo', $ligar);

... (horário, silêncio, resumo do dono, SMS etc. exatamente como hoje: as outras configurações são salvas mesmo quando o piloto não liga)

$recusado = $quer && ! $ligar;

$this->registrar(
    'piloto_alterado',
    $ligar,
    $recusado ? PilotoRequisitos::mensagemRecusa($faltando) : ($ligar ? 'Piloto ligado' : 'Piloto desligado'),
    ['acao' => $recusado ? 'recusado' : ($ligar ? 'ligou' : 'desligou'), 'faltando' => array_column($faltando, 'chave')],
    $recusado || $ligar !== $ligadoAntes,
);

if ($faltando) {
    session()->flash('error', PilotoRequisitos::mensagemRecusa($faltando));
} elseif ($ligar && ! $ligadoAntes) {
    session()->flash('success', 'Configurações salvas. Piloto ligado: a partir de agora valem os bloqueios por etapa, as barreiras de prazo e os alertas.');
} elseif (! $ligar && $ligadoAntes) {
    session()->flash('success', 'Configurações salvas. Piloto desligado: a oficina volta ao fluxo de sempre.');
} else {
    session()->flash('success', 'Configurações salvas.');
}

return back();
```
O último parâmetro de `registrar` (`$gravar`) evita registro quando nada mudou; implemente `registrar()` assim:
```php
/** Trilha de auditoria (mentoria_registros: só inserção). Tolera banco sem a tabela. */
protected function registrar(string $tipo, bool $ok, string $observacao, array $dados = [], bool $gravar = true): void
{
    if (! $gravar || ! Schema::hasTable('mentoria_registros')) {
        return;
    }

    MentoriaRegistro::record($tipo, ['ok' => $ok, 'observacao' => mb_substr($observacao, 0, 500), 'dados_json' => $dados ?: null]);
}
```
Atenção: `session()->flash('error', ...)` precisa ser **uma linha só e texto puro** (o layout o imprime dentro de uma string JavaScript, `Admin/Resources/views/components/flash-group/index.blade.php:38`); por isso a lista clicável fica na página, não no aviso.

**c) NOVO `updateTestMode()`** (rota `PUT settings/workshop/modo-teste`):
```php
public function updateTestMode(): RedirectResponse
{
    $this->validate(request(), ['modo_teste_barreiras' => 'nullable|boolean', 'modo_teste_tempo_padrao' => 'nullable|boolean']);

    $antes = (bool) WorkshopSettings::get('modo_teste_barreiras', false);
    $antesTempo = (bool) WorkshopSettings::get('modo_teste_tempo_padrao', false);

    $ligar = request()->boolean('modo_teste_barreiras');
    $tempoPadrao = $ligar && request()->boolean('modo_teste_tempo_padrao');   // a sub-opção só existe com o modo ligado

    WorkshopSettings::set('modo_teste_barreiras', $ligar);
    WorkshopSettings::set('modo_teste_tempo_padrao', $tempoPadrao);

    $this->registrar(
        'modo_teste_alterado',
        $ligar,
        $ligar ? 'Modo de teste ligado'.($tempoPadrao ? ' (com o tempo padrão dos serviços)' : '') : 'Modo de teste desligado',
        ['divisor' => ModoTeste::divisor(), 'tempo_padrao' => $tempoPadrao],
        $ligar !== $antes || $tempoPadrao !== $antesTempo,
    );

    $divisor = ModoTeste::divisor();

    if ($ligar && ! StageGate::active()) {
        session()->flash('warning', 'Modo de teste salvo, mas ele só vale com o piloto ligado.');
    } elseif ($ligar) {
        session()->flash('success', "Modo de teste ligado: as barreiras abertas daqui para frente usam prazos {$divisor} vezes menores (2 h viram 12 min; 24 h viram 2 h 24 min; 7 dias viram 16 h 48 min). Use só na oficina de teste.");
    } else {
        session()->flash('success', 'Modo de teste desligado: os prazos voltam ao normal. As barreiras abertas durante o teste mantêm os prazos curtos até serem cumpridas.');
    }

    return back();
}
```

**d) `restoreRules()` (`:151-158`)**: mesma ação, mensagem que diz o que foi feito:
```php
public function restoreRules(): RedirectResponse
{
    $antes = BarrierRule::pluck('codigo')->all();

    BarrierRule::ensureDefaults();

    $criadas = BarrierRule::whereNotIn('codigo', $antes)->pluck('nome')->all();

    session()->flash('success', match (true) {
        $criadas === [] => 'Nenhuma barreira padrão estava faltando. Nada foi alterado.',
        count($criadas) <= 5 => 'Barreiras padrão restauradas: '.implode(', ', $criadas).'. As que já existiam não foram alteradas.',
        default => count($criadas).' barreiras padrão restauradas. As que já existiam não foram alteradas.',
    });

    return back();
}
```

### 14. ALTERAR `Models/MentoriaRegistro.php:80-82` (grupo "Sistema", não aparecem no formulário, ver `IndicatorController.php:83`, `:94`)
```php
'piloto_alterado' => ['grupo' => 'Sistema', 'label' => 'Chave do piloto alterada'],
'modo_teste_alterado' => ['grupo' => 'Sistema', 'label' => 'Modo de teste das barreiras alterado'],
```
(`mentoria_registros.tipo` é `string(40)`; os dois nomes cabem.) Os registros aparecem em Mentoria > Registros do dia a dia, "Últimos registros".

### 15. ALTERAR `Routes/web.php:133-142` e `Config/acl.php:108-120`
- Rota, dentro do grupo de `WorkshopSettingsController`, depois da linha 141:
  `Route::put('modo-teste', 'updateTestMode')->name('admin.workshop.settings.test_mode');`
- ACL: acrescentar `'admin.workshop.settings.test_mode'` à lista `route` da chave **existente** `settings.workshop`. **Obrigatório**: o Bouncer só protege rotas citadas em alguma chave de ACL (`Admin/Http/Middleware/Bouncer.php:79-86`); uma rota fora da lista ficaria aberta a qualquer usuário logado.

### 16. Views

**a) CRIAR `Resources/views/components/modo-teste-selo.blade.php`** (só estilo inline, para funcionar nas telas independentes do técnico e do celular):
```blade
@if (\Webkul\ServiceOrder\Services\ModoTeste::ativo())
    <span title="Os prazos desta oficina estão divididos por {{ \Webkul\ServiceOrder\Services\ModoTeste::divisor() }} para o roteiro de teste." style="display:inline-block;margin-left:8px;padding:2px 10px;border-radius:999px;background:#b91c1c;color:#fff;font-size:11px;font-weight:800;letter-spacing:.4px;white-space:nowrap;vertical-align:middle;">{{ \Webkul\ServiceOrder\Services\ModoTeste::selo() }}</span>
@endif
```
Incluir com `@include('service_order::components.modo-teste-selo')` (padrão do pacote, como `components.stage-indicators`) em:

| View | Âncora (linha de hoje) |
|---|---|
| `alerts/index.blade.php` | `:8`, dentro do `<div class="text-xl font-bold ...">Alertas da oficina</div>` |
| `rotina/painel.blade.php` | `:13` (título "Minha rotina") |
| `rotina/patio.blade.php` | `:12` (título "Quadro de pátio") |
| `alcadas/fila.blade.php` | `:18` (título "Fila de alçadas") |
| `stage-index.blade.php` | `:8-10` (título da etapa; mostra a coluna "Prazo") |
| `tecnico/index.blade.php` | `:46`, ao lado do `<h1>Minhas tarefas</h1>` (tela independente) |
| `mentoria/painel.blade.php` | `:14` (título "Painel do Dono") |
| `mentoria/painel-celular.blade.php` | `:39`, depois do `<h1>` (tela independente) |
| `mentoria/indicadores.blade.php` | `:8` (título) |
| `partials/workflow.blade.php` | `:32`, ao lado de "Fluxo da OS" (mostra o tempo alvo) |
| `settings/workshop.blade.php` | `:18` (título) e no cartão do modo de teste |

**b) ALTERAR `components/workshop-bell.blade.php`** (sino global, aparece em toda página do admin com o piloto ligado): dentro de `<div id="vm-bell" ...>` (`:10`), antes do botão, um selo flutuante à esquerda do sino:
```blade
@if (\Webkul\ServiceOrder\Services\ModoTeste::ativo())
    <span id="vm-modo-teste" style="position:absolute; right:58px; bottom:12px; white-space:nowrap; background:#b91c1c; color:#fff; font-size:11px; font-weight:800; padding:4px 10px; border-radius:999px; box-shadow:0 2px 8px rgba(0,0,0,.25);">{{ \Webkul\ServiceOrder\Services\ModoTeste::selo() }}</span>
@endif
```
Isso cobre também o card "OS em andamento" do Início (pacote Admin) sem editar o pacote Admin.

**c) ALTERAR `settings/workshop.blade.php`**
1. No bloco "Chave do piloto" (`:27-34`), logo depois do `</label>` do checkbox (`:33`), a checklist:
```blade
@php
    $faltaObrigatorio = collect($requisitos)->contains(fn ($r) => ! $r['ok']);
    $recomendadosFaltando = collect($recomendados)->filter(fn ($r) => ! $r['ok']);
@endphp
<div id="requisitos-piloto" class="mt-3 rounded-md border p-3 text-sm {{ $faltaObrigatorio ? 'border-yellow-300 bg-yellow-50 text-yellow-900' : 'border-green-300 bg-green-50 text-green-900' }}">
    <p class="font-semibold">{{ $settings['piloto_ativo'] ? 'Condições do piloto' : 'Para ligar o piloto, a oficina precisa ter:' }}</p>
    <ul class="mt-1 flex flex-col gap-0.5">
        @foreach ($requisitos as $item)
            <li>
                <span class="rounded px-1.5 py-0.5 text-xs font-semibold {{ $item['ok'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $item['ok'] ? 'Ok' : 'Falta' }}</span>
                {{ $item['rotulo'] }}
                @unless ($item['ok'])
                    @if (bouncer()->hasPermission($item['permissao']))
                        &middot; <a class="text-blue-600 underline" href="{{ route($item['rota']) }}">{{ $item['onde'] }}</a>
                    @else
                        &middot; {{ $item['onde'] }}
                    @endif
                @endunless
            </li>
        @endforeach
    </ul>
    @if ($faltaObrigatorio && ! $settings['piloto_ativo'])
        <p class="mt-1 text-xs">O piloto não liga enquanto faltar algum item acima. As outras configurações desta tela são salvas do mesmo jeito.</p>
    @elseif ($faltaObrigatorio)
        <p class="mt-1 text-xs">O piloto continua ligado, mas a Ficha ou os Colaboradores ficaram incompletos.</p>
    @endif
    @if ($recomendadosFaltando->isNotEmpty())
        <details class="mt-2 text-xs"><summary class="cursor-pointer">Ficha completa (recomendado): faltam {{ $recomendadosFaltando->count() }} campo(s)</summary>
            <ul class="mt-1 list-disc pl-5">@foreach ($recomendadosFaltando as $r)<li>{{ $r['rotulo'] }}</li>@endforeach</ul>
        </details>
    @endif
    @foreach ($avisosPiloto as $aviso)
        <p class="mt-2 text-xs text-yellow-800">{{ $aviso }}</p>
    @endforeach
</div>
```
2. Entre o `</form>` do formulário geral (`:101`) e o comentário `{{-- Barreiras --}}` (`:103`), o cartão do modo de teste (formulário próprio, para não mexer no formulário geral que a F02 também altera):
```blade
{{-- Modo de teste das barreiras (A6) --}}
<form method="POST" action="{{ route('admin.workshop.settings.test_mode') }}" class="{{ $card }} flex flex-col gap-3" data-ja-ligado="{{ ($settings['modo_teste_barreiras'] ?? false) ? '1' : '0' }}" onsubmit="return confirmarModoTeste(this)">
    @csrf
    @method('PUT')
    <p class="{{ $h }}">Modo de teste das barreiras @include('service_order::components.modo-teste-selo')</p>
    <p class="{{ $hint }}">Só para a oficina de teste. Divide por {{ $divisor }} os prazos das barreiras e dos níveis de alerta, o tempo alvo do diagnóstico, os cronômetros da "Minha rotina" e o prazo de 24 h do dono: 2 h viram 12 min, 24 h viram 2 h 24 min, 7 dias viram 16 h 48 min. Vale para as barreiras abertas daqui para frente (ligue antes de abrir as OS do roteiro). O horário comercial e o horário de silêncio continuam como estão configurados; para testar fora do expediente, deixe todos os dias do horário comercial desmarcados (os prazos passam a contar em relógio corrido) e ajuste ou desligue o silêncio. Só vale com o piloto ligado.</p>
    <label class="flex items-center gap-2 text-sm font-semibold dark:text-white"><input type="checkbox" name="modo_teste_barreiras" value="1" @checked($settings['modo_teste_barreiras'] ?? false) /> Ligar o modo de teste (prazos ÷ {{ $divisor }})</label>
    <label class="flex items-center gap-2 text-sm dark:text-white"><input type="checkbox" name="modo_teste_tempo_padrao" value="1" @checked($settings['modo_teste_tempo_padrao'] ?? false) /> Dividir também o tempo padrão dos serviços (cronômetro do técnico)</label>
    @if (! $settings['piloto_ativo'])<p class="text-xs text-yellow-700">O piloto está desligado: o modo de teste só passa a valer quando o piloto for ligado.</p>@endif
    <p class="text-xs text-red-700">Nunca ligue o modo de teste em uma oficina real: os alertas e os indicadores dela ficariam com prazos 10 vezes menores.</p>
    <div><button type="submit" class="secondary-button">Salvar modo de teste</button></div>
</form>
<script>
    function confirmarModoTeste(form) {
        if (! form.modo_teste_barreiras.checked || form.dataset.jaLigado === '1') { return true; }
        return confirm('Ligar o modo de teste? Use somente na oficina de teste: os prazos e os alertas desta oficina passam a ser {{ $divisor }} vezes mais curtos.');
    }
</script>
```
3. (Opcional, polimento) Em cada cartão de barreira, quando `$modoTesteAtivo`, mostrar ao lado de "Prazo (min)": `modo de teste: {{ \Webkul\ServiceOrder\Services\ModoTeste::numero(\Webkul\ServiceOrder\Services\ModoTeste::minutos($rule->prazo_minutos)) }} min`.

### 17. ALTERAR `Services/StageGate.php:165-196` (`diagnosticoParaOrcamento`) — endurecimento (decisão 3)
Primeiro item da função, antes de "Laudo do diagnóstico":
```php
if (! $os->tecnicoDesignadoId()) {
    $missing[] = 'Técnico designado';
}
```
`tecnicoDesignadoId()` aceita `diagnostico_mechanic_id` ou `mechanic_id` (`Models/ServiceOrder.php:345-348`), então a OS criada com mecânico já conta. A lista passa a ter "Técnico designado" também nesta transição.

### 18. Testes: helper e testes antigos afetados
- `tests/Feature/Workshop/Concerns/BuildsWorkshop.php`: novo helper (importar `Webkul\ServiceOrder\Models\Colaborador`):
  ```php
  /** Deixa a Ficha mínima pronta para ligar o piloto pela tela (A5). */
  protected function completeFichaForPilot(array $override = []): void
  {
      foreach (array_merge(['valor_hora_mao_obra' => 180.0, 'pct_impostos' => 8.0, 'inicio_mentoria' => '2026-11-01'], $override) as $chave => $valor) {
          WorkshopSettings::set($chave, $valor);
      }

      Colaborador::create(['nome' => 'Dono Teste', 'cargos' => 'proprietario', 'classificacao' => 'administracao', 'ativo' => true]);
  }
  ```
- `tests/Feature/Workshop/HttpFlowTest.php:305-342` ("a tela Minhas tarefas abre e a configuração do piloto grava a chave e os horários"): chamar `$this->completeFichaForPilot();` antes do `PUT ... general` (nada mais muda; as asserções continuam).
- `tests/Feature/Workshop/StageGateTest.php:19`, `:47`, `:60`: criar a OS com `'diagnostico_mechanic_id' => $this->makeMechanic()->id` (por causa do endurecimento do item 17: sem técnico, as listas exatas `toBe([...])` das linhas 37, 57 e 75 ganhariam "Técnico designado" e o `avancou = true` da linha 43 e da 79 deixaria de acontecer). O caso "sem técnico" fica coberto pelo `PilotoOrcamentoGateTest`.

---

## Banco (migrations)

**Nenhuma.** Justificativa:
- As três chaves novas (`modo_teste_barreiras`, `modo_teste_tempo_padrao`, `modo_teste_divisor`) vivem na tabela `workshop_settings` (chave/valor JSON), criada em `2026_11_16_100200_create_workshop_settings_and_barrier_tables.php`.
- A auditoria usa `mentoria_registros` (`2026_11_17_100100_create_indicator_tables.php`), cuja coluna `tipo` é `string(40)`.
- A checklist lê `colaboradores` (`2026_11_17_100400_create_colaboradores_and_ficha_tables.php`) e `roles.perfil_oficina` (`2026_11_16_100400_...`).

Tolerância a tabela/coluna ausente (servidor só recebe migrations quando o usuário roda o `migrate-safe.php`): `PilotoRequisitos` testa `Schema::hasTable('colaboradores')` e `Schema::hasColumn('roles', 'perfil_oficina')`; `registrar()` testa `Schema::hasTable('mentoria_registros')`; `WorkshopSettings::all()` já tolera a falta de `workshop_settings`. O modo de teste, desligado, não consulta nada novo.

---

## ACL, menu, rotas e configurações

- **ACL**: nenhuma chave nova. A rota `admin.workshop.settings.test_mode` entra na lista da chave existente `settings.workshop` (`Config/acl.php:108-120`). Quem já configura o piloto passa a configurar o modo de teste; ninguém mais.
- **Menu**: nenhuma mudança.
- **Rotas**: `PUT /admin/settings/workshop/modo-teste` → `WorkshopSettingsController@updateTestMode`, nome `admin.workshop.settings.test_mode`.
- **WorkshopSettings (novas chaves)**: `modo_teste_barreiras` (bool, padrão `false`), `modo_teste_tempo_padrao` (bool, padrão `false`), `modo_teste_divisor` (int, padrão `10`, sem campo na tela; limitado a 2..100 por `ModoTeste::divisor()`).
- **Tipos de `MentoriaRegistro`**: `piloto_alterado`, `modo_teste_alterado` (grupo "Sistema").
- **Códigos de requisito do piloto**: `valor_hora`, `impostos`, `inicio_mentoria`, `gerente_ou_proprietario` (e os 8 recomendados).
- **Classes novas**: `Services/ModoTeste`, `Services/PilotoRequisitos`. **Partial novo**: `service_order::components.modo-teste-selo`.
- Permissões e "Técnico nunca vê dinheiro": nada nesta frente expõe valor em reais. O selo e o alvo efetivo são tempos. A checklist só aparece em `settings.workshop`.

---

## Textos da interface (pt-BR)

Todos fixos no código (ver convenções no topo).

**Chave do piloto (`settings/workshop.blade.php`)**
- Título da checklist: "Para ligar o piloto, a oficina precisa ter:" (piloto desligado) / "Condições do piloto" (ligado).
- Itens: "Valor da hora de mão de obra" · "Impostos sobre a venda (%)" · "Data de início da mentoria" · "Colaborador ativo com o cargo de Gerente ou Proprietário". Etiquetas "Ok" / "Falta". Links: "Ficha de Enquadramento > Valores e prazos", "Ficha de Enquadramento > Parâmetros financeiros", "Ficha de Enquadramento > Mentoria e bônus", "Colaboradores".
- Rodapé (desligado e incompleto): "O piloto não liga enquanto faltar algum item acima. As outras configurações desta tela são salvas do mesmo jeito." (ligado e incompleto): "O piloto continua ligado, mas a Ficha ou os Colaboradores ficaram incompletos."
- Recomendados: "Ficha completa (recomendado): faltam N campo(s)".
- Aviso de papéis: "Nenhum usuário do sistema está com o papel de Gerente ou Proprietário (veja "Papéis e celulares", abaixo): os alertas de nível 2, de nível 3 e os críticos não chegarão a ninguém."
- Recusa (toast, uma linha): "O piloto não foi ligado. Falta: valor da hora de mão de obra; impostos sobre a venda (%); data de início da mentoria; colaborador ativo com o cargo de Gerente ou Proprietário. As outras configurações foram salvas."
- Ligou: "Configurações salvas. Piloto ligado: a partir de agora valem os bloqueios por etapa, as barreiras de prazo e os alertas." · Desligou: "Configurações salvas. Piloto desligado: a oficina volta ao fluxo de sempre."

**Modo de teste**
- Título: "Modo de teste das barreiras". Caixa: "Ligar o modo de teste (prazos ÷ 10)". Sub-opção: "Dividir também o tempo padrão dos serviços (cronômetro do técnico)". Botão: "Salvar modo de teste". Texto de ajuda e avisos: ver bloco Blade do item 16c.
- Selo: **"MODO DE TESTE (prazos ÷ 10)"** (o número vem de `ModoTeste::divisor()`).
- Confirmação: "Ligar o modo de teste? Use somente na oficina de teste: os prazos e os alertas desta oficina passam a ser 10 vezes mais curtos."
- Mensagens: "Modo de teste ligado: as barreiras abertas daqui para frente usam prazos 10 vezes menores (2 h viram 12 min; 24 h viram 2 h 24 min; 7 dias viram 16 h 48 min). Use só na oficina de teste." · "Modo de teste desligado: os prazos voltam ao normal. As barreiras abertas durante o teste mantêm os prazos curtos até serem cumpridas." · "Modo de teste salvo, mas ele só vale com o piloto ligado."
- Alerta do dono na fila de alçadas: "... aguardando sua decisão em até 24 h." (normal) / "... em até 2 h 24 min." (teste).
- Push (opcional): prefixo "[TESTE] " no título.

**Restaurar barreiras**
- "Nenhuma barreira padrão estava faltando. Nada foi alterado." · "Barreiras padrão restauradas: A, B. As que já existiam não foram alteradas." · "N barreiras padrão restauradas. As que já existiam não foram alteradas."

**Bloqueio Diagnóstico → Orçamento**: acrescenta o item "Técnico designado" (mesmo texto que a transição OS → Diagnóstico já usa).

---

## Testes Pest a escrever

Rodar nesta ordem: `php artisan test --filter=PilotoRequisitos`, `--filter=ModoTeste`, `--filter=PilotoOrcamentoGate`, depois `--filter=Workshop` e, no fim, `php vendor/bin/pint` nos arquivos tocados. Funções auxiliares globais do Pest precisam de nome **único** entre arquivos: use os prefixos `piloto`, `modoteste` e `gate` (já existem `advancer`, `staff`, `team`, `emit`, `dueOf`, `ids`, `channels`, `subscribe`, `papel`, `rh`, `colaborador`, `alcadasUsers`, `osComItem`, `configurarDesconto`, `budgetScenario`, `recipientsOf`). Não use `skip`/`todo`.

### `tests/Feature/Workshop/PilotoRequisitosTest.php` (A5)
Auxiliares: `pilotoAdmin()` (usuário `makeUser('proprietario')` trocado para um role `permission_type = all`, como em `HttpFlowTest.php:306-309`, e `actingAs`), `pilotoFormulario(array $extra = [])` (corpo mínimo válido do `PUT ... general`: `silencio_inicio`, `silencio_fim`, `agrupar_alertas_minutos`, `sms_provedor`, `sms_limite_mensal`, `sms_custo_unitario`, `sms_nao_aberto_minutos`, `tecnico_margem_percentual`, `tecnico_meta_eficiencia`).
1. **"A5: com a Ficha vazia o piloto não liga e a tela lista o que falta, com links"** (PDF A5 "Teste:"; roteiro linha 1). `PUT general` com `piloto_ativo=1` e `agrupar_alertas_minutos=7`. Esperado: redireciona; `piloto_ativo` continua falso; `session('error')` contém "O piloto não foi ligado" e os quatro textos curtos; `agrupar_alertas_minutos` ficou 7 (as outras configurações foram salvas); `GET admin.workshop.settings.edit` mostra os quatro rótulos, "Falta", e os links `route('admin.ficha.edit')` e `route('admin.colaboradores.index')`; existe 1 `MentoriaRegistro` `piloto_alterado` com `ok = false` e `dados_json.acao = 'recusado'`.
2. **"A5: cada item é apontado sozinho"** (dataset com 4 casos). Parte de `completeFichaForPilot()` e quebra um item: valor da hora `0`; `pct_impostos` `null`; `inicio_mentoria` `null`; colaborador só com `tecnico`, e outro caso com o Gerente **desligado** (`ativo = false`). Esperado: `PilotoRequisitos::faltando()` devolve só a chave daquele item e o `PUT` recusa.
3. **"A5: com a Ficha pronta e um Gerente ou Proprietário ativo o piloto liga"** (dataset `gerente`/`proprietario`; roteiro linha 1). `piloto_ativo` verdadeiro; `session('success')` contém "Piloto ligado"; registro `piloto_alterado` com `ok = true`.
4. **"A5: impostos 0 conta como preenchido; valor da hora 0 não"**.
5. **"A5: piloto já ligado não é desligado quando a Ficha está incompleta"**. `enablePilot()` com Ficha vazia; `PUT general` com `piloto_ativo=1` mantém ligado e a página mostra "continua ligado"; um `PUT` **sem** `piloto_ativo` desliga (registro `desligou`); religar volta a ser recusado.
6. **"A5: editar a Ficha com o piloto ligado não o desliga"**. `enablePilot()`, Ficha completa, `PUT admin.ficha.update` apagando `valor_hora_mao_obra` → `StageGate::active()` continua verdadeiro.
7. **"A5: com o piloto desligado, salvar sem marcar a chave não exige nada nem grava registro"** (comportamento atual preservado).
8. **"A5: avisa quando nenhum usuário tem o papel de Gerente ou Proprietário"**: `PilotoRequisitos::avisos()` com um aviso; some depois de `makeUser('gerente')`.
9. **"A5: a Ficha continua tendo todos os campos do roteiro"** (regressão): `GET admin.ficha.edit` como administrador e `assertSee(..., false)` de `name="valor_hora_mao_obra"`, `pct_impostos`, `pct_comissoes`, `pct_desp_var_pecas`, `margem_desejada_pecas`, `lucro_desejado`, `encargos_percentual`, `inicio_mentoria`, `meta_faturamento_mensal`, `gatilho_resultado` e `bonus_cheio[gerente]`.
10. **"só quem tem a permissão liga o piloto"**: consultor recebe 401 no `PUT general` e no `PUT modo-teste` (mesmo padrão de `HttpFlowTest.php:344-350`).

### `tests/Feature/Workshop/ModoTesteTest.php` (A6)
Auxiliares: `modotestePiloto(array $barreiras)` (`enablePilot(['modo_teste_barreiras' => true], $barreiras)`), `modotesteEvento(...)`, `modotesteInstancia(...)` (nomes únicos).
1. **"o modo de teste vem desligado, não faz nada e só vale com o piloto ligado"**: padrão `ModoTeste::ativo()` falso, `fator()` 1, `minutos(120)` 120, `selo()` nulo; caixa marcada com piloto desligado continua inativa; com o piloto ligado `minutos(120)` é 12 e `selo()` é "MODO DE TESTE (prazos ÷ 10)".
2. **"Orçamento em 2 horas: vence em 12 min, o gerente em +6 e o proprietário em +12"** (roteiro linhas 26 e 27). Diagnóstico finalizado às 09:00 (quarta, horário comercial padrão): `vence_em` 09:12, `nivel2_em` 09:18, `nivel3_em` 09:24. `tick` às 09:11 dispara 0; às 09:12 alerta consultor e chefe; 09:18 gerente; 09:24 proprietário (o dono não recebe antes). `orcamento_enviado` encerra e resolve. Com o modo **desligado** o mesmo cenário dá 11:00 / 12:00 / 13:00.
3. **"prazos do roteiro divididos por 10 em cada regra"** (dataset; usa `onlyBarriers([...])` e eventos como `AllBarriersTest`). Valores esperados (quarta 18/11/2026, expediente 8-18):

| Regra e evento inicial | vence | nível 2 | nível 3 | Linha do roteiro |
|---|---|---|---|---|
| `recepcao`, `chegada_veiculo` 08:00 | 08:01 | 08:02 | — | 10 |
| `cor_credito`, `chegada_veiculo` 08:00 | 08:03 | 08:04:30 | — | 13 |
| `tecnico_designado`, `ficha_enviada_chefe` 09:00 | 09:03 | 09:04:30 | — | 15 |
| `diagnostico_tempo`, `diagnostico_inicio` 08:00 com `tempo_alvo_min` 60 | 08:06 | 08:07:12 | — | 17 |
| `relacao_pecas`, `diagnostico_fim` 09:00 | 09:03 | 09:06 | — | 19 |
| `os_completa_consultor`, `diagnostico_fim` 09:00 | 09:06 | 09:09 | — | 26 |
| `precificacao_peca`, `relacao_pecas_entregue` 09:00 com `prazo_precificacao_min` 40 e 1 peça | 09:04 | 09:06 | — | 22 |
| `resposta_cliente`, `orcamento_enviado` 10:00 (relógio corrido) | 14:48 | 17:12 | — | — |
| `atualizacao_cliente`, `cliente_atualizado` 15:00 (relógio corrido) | 17:24 | 19:48 | — | 40 |
| `posvenda_7_dias`, `saida_veiculo` 10:00 (relógio corrido) | 19/11 02:48 | 19/11 07:36 | — | 51 |
| `reclamacao`, `reclamacao_aberta` 10:00 (relógio corrido) | 13:36 | — | 14:48 | 54 |
| `diagnostico_retorno`, `retorno_aberto` 09:00 | 09:12 | 09:18 | — | — |
| `prazo_prometido`, `prazo_informado` com `prazo_em` 20/11 12:00 | 20/11 09:36 | 12:00 | 14:24 | — |
| `compra_peca` (fim do dia), `cliente_aprovou` 10:00 com peça de fornecedor | 10:48 | 13:12 | — | — |
| `pronto_nao_faturado` (fim do dia), `checklist_saida` 14:00 | 14:24 | 16:48 | — | — |

   (`prazo_prometido` com modo desligado seria 19/11 12:00; o teste confere os dois lados.)
4. **"o horário comercial continua valendo no modo de teste"**: `orcamento_2h` com diagnóstico finalizado às 17:55 → `vence_em` 19/11 08:07; com `horario_comercial` vazio (relógio corrido) → 18:07 do mesmo dia.
5. **"o silêncio e as janelas de entrega não são divididos"**: `AlertDispatcher::inQuietHours` verdadeiro às 21:00 e falso às 10:00 com o modo ligado; `WorkshopSettings::get('agrupar_alertas_minutos')` e `sms_nao_aberto_minutos` inalterados (roteiro linha 60).
6. **"tempo alvo do diagnóstico ÷ 10: passa do alvo e pede a causa do atraso"** (roteiro linha 17): tipo de queixa de 60 min. Com o modo ligado, diagnóstico de 7 min: `StageGate::diagnosticoPassouDoAlvo` verdadeiro; `WorkflowService::finishDiagnostico` sem causa lança `ValidationException` (chave `causa_atraso`, texto com "6 min"); com 5 min finaliza; `TecnicoTasks::diagnosticos()` devolve `tempo_alvo_min` 6 e `passou_do_alvo` verdadeiro. Com o modo desligado os mesmos 7 min não pedem causa. O evento `diagnostico_fim` grava `tempo_alvo_min` **60** (real) e `passou_do_alvo` verdadeiro.
7. **"Minha rotina: os limites dos cronômetros são divididos"**: OS em Orçamento com `diagnostico_concluido_em` 15 min atrás: item "enviar o orçamento" com `limite` "12 min" e cor `vermelho` (modo ligado) contra "2 h 0 min" e `verde` (desligado). Orçamento enviado há 5 h sem resposta aparece em "Orçamentos sem resposta há 48 h" só com o modo ligado. Painel de peças: limite "2 min" para peça em estoque e "4 min" para fornecedor (roteiro linha 22).
8. **"prazo do dono na fila de alçadas: 24 h vira 2 h 24"** (roteiro linha 31): configurar desconto (5/10), consultor pede 15% (vai ao dono), gerente encaminha: `prazo_dono_em - encaminhado_em` = 144 min com o modo ligado (1440 desligado); o alerta crítico ao dono contém "em até 2 h 24 min". DON-06 (`IndicatorService::evaluate('DON-06', '2026-11')`) conta como "no prazo" a decisão tomada 2 h depois e como "fora do prazo" a tomada 3 h depois.
9. **"GER-09 e CON-12 usam o limite dividido"**: reclamação fechada 3 h depois conta no prazo, 5 h depois não (modo ligado); recontato 17 h depois da recusa não conta no CON-12, 10 h depois conta.
10. **"sub-opção do tempo padrão"** (roteiro linhas 36 a 38): serviço de 1 h; sem a sub-opção `padraoSeconds` é 3600; com ela 360; cronômetro `verde` até 360 s, `amarelo` até 432 s (margem 120%), `vermelho` depois; a barreira de operação dispara o nível 1 do chefe aos 120% e o nível 2 do gerente aos 150% do padrão efetivo.
11. **"o selo aparece onde há alerta e prazo"**: com o modo ligado, as páginas `admin.workshop.settings.edit`, `admin.workshop.alerts.index`, `admin.rotina.painel`, `admin.rotina.patio`, `admin.mentoria.alcadas` (fila de alçadas, `Routes/web.php:158`), `admin.tecnico.index` e uma etapa (`admin.service_orders.stage.index`) contêm "MODO DE TESTE (prazos ÷ 10)"; com o modo desligado, nenhuma contém. Usar o administrador com role `all` de `pilotoAdmin()` para não depender das chaves de ACL de cada tela.
12. **"salvar o modo de teste pela tela"**: `PUT admin.workshop.settings.test_mode` liga, grava `modo_teste_barreiras`; a sub-opção sem o modo marcado é descartada; cria `MentoriaRegistro` `modo_teste_alterado` só quando algo muda; com o piloto desligado devolve o aviso "só vale com o piloto ligado".
13. **"janela de aprovação do bônus aberta no modo de teste"** (só se a decisão 2 for aceita): `BonusService::janelaDeAprovacao('2026-10', 18/11/2026)` falso com o modo desligado, verdadeiro ligado.
14. **`BusinessHours::addMinutes` aceita fração**: 4,5 min a partir de 09:00 = 09:04:30 com e sem horário configurado; zero e negativo devolvem o próprio horário.

### `tests/Feature/Workshop/PilotoOrcamentoGateTest.php` (A6: teste do PDF e restaurar barreiras)
1. **"A6: com o piloto ligado, OS sem mecânico e sem diagnóstico NÃO avança para Orçamento"** (PDF A6 "Teste:"; roteiro linhas 15, 16 e 20). `enablePilot()`; OS em "Ordem de Serviço" sem técnico e sem laudo.
   - `StageAdvanceService::advance($os, ORCAMENTO)` lança `ValidationException` (não pula etapa).
   - `advance($os, DIAGNOSTICO)['faltando']` é `['Técnico designado']`.
   - Entrada forçada no Diagnóstico (justificativa): depois, `advance($os->refresh(), ORCAMENTO)` devolve `avancou = false` e `faltando` contém "Técnico designado", "Laudo do diagnóstico", "Laudo com pelo menos 1 evidência (código de falha, medição ou foto)", "Diagnóstico finalizado pelo técnico", "Visto do chefe de oficina no diagnóstico" e "Relação de peças entregue".
   - HTTP: com um usuário que tenha `service_orders`, `service_orders.view` e `service_orders.edit` (mesmo padrão do `staff()` de `HttpFlowTest.php:18-23`, mas com helper de nome próprio, por exemplo `gateUsuario()`), `PUT admin.service_orders.stage.update` com `stage = Orçamento` redireciona para a OS com `session('error')` contendo "Falta:", e a etapa não muda.
   - Por fora do botão: com `getDefaultAdmin()` logado (`actingAs($admin, 'user')`, como em `StageGateTest.php:238-246`), `$os->update(['workshop_status' => ORCAMENTO])` lança `ValidationException` (`ServiceOrderObserver.php:105-109`).
   - Com técnico designado mas **sem diagnóstico** continua bloqueado (lista sem "Técnico designado").
2. **"A6: com o piloto desligado a mesma OS avança (fluxo antigo)"**: sem `enablePilot()`, `advance()` devolve `avancou = true` e o Observer não bloqueia.
3. **"Diagnóstico → Orçamento exige técnico designado"** (endurecimento): OS no Diagnóstico com laudo, evidência, finalização, visto e relação de peças, mas sem técnico, não avança; ao designar, avança.
4. **"restaurar barreiras padrão recria só as que faltam e nunca sobrescreve"**: apagar a barreira `reclamacao` (preparação do teste), alterar `orcamento_2h` para 99 min e desativar `recepcao`; `POST admin.workshop.settings.restore_rules`; `reclamacao` voltou, `orcamento_2h` continua 99, `recepcao` continua inativa, total de regras = `count(BarrierRule::DEFAULTS)`; `session('success')` cita "Reclamação do cliente"; segunda chamada diz "Nenhuma barreira padrão estava faltando" e não duplica.
5. **"restaurar barreiras exige a permissão da configuração"**: consultor recebe 401.

### Mapeamento rápido
- PDF A5 "Teste:" → PilotoRequisitosTest 1 a 3. PDF A6 "Teste:" → PilotoOrcamentoGateTest 1 e 2.
- Roteiro (72 linhas): linha 1 → PilotoRequisitosTest 3; linhas 10, 13, 15, 17, 19, 22, 26, 27, 31, 40, 51 e 54 → ModoTesteTest 2, 3, 6, 7 e 8; linhas 36 a 38 → ModoTesteTest 10; linha 60 → ModoTesteTest 5; linhas 62 a 65 → ModoTesteTest 13.

---

## Riscos e interações com outras frentes

1. **Quebra conhecida e intencional**: `HttpFlowTest.php:305` liga o piloto sem Ficha. Corrigir com `completeFichaForPilot()` (item 18). Não apagar o teste.
2. **Endurecimento do Diagnóstico → Orçamento** altera três testes antigos de `StageGateTest.php` (`:19`, `:47`, `:60`) por usarem listas exatas sem técnico. Se o patrão não aceitar a decisão 3, retirar o item 17 e o teste 3 de `PilotoOrcamentoGateTest`; o resto da frente não depende dele.
3. **Contrato do prazo real**: eventos e colunas guardam o prazo **real**. F10/F11 (`prazo_precificacao_min` 20/40, painel de peças, NF de entrada 24 h, garantia de fornecedor 5 d), F12 (`diagnostico_retorno` 2 h), F13 (chamado externo 30 min, diária de pátio por horas da Ficha, CHE-15) e F06 (FIN-01 de 30 min) devem: cadastrar o prazo real em `BarrierRule::DEFAULTS` (o modo de teste vale sozinho para os modos `minutos`, `dinamico`, `fim_do_dia` e `data_alvo`), passar o limite real a `RotinaService::item()` (ele divide) e usar `ModoTeste::minutos()/depois()/antes()` em qualquer comparação ou exibição de prazo que fizerem fora do motor. Um **modo novo** de barreira precisa chamar `ModoTeste`.
4. **Arquivos compartilhados com edição pequena e aditiva** (conflito de merge provável, resolver mantendo as duas mudanças): `Http/Controllers/WorkshopSettingsController.php` (F02 mexe em `updateGeneral`, F04 em papéis), `Resources/views/settings/workshop.blade.php` (F02 mexe muito; por isso o modo de teste ficou em formulário próprio e a checklist em um bloco isolado), `Routes/web.php`, `Config/acl.php`, `Services/WorkshopSettings.php`, `Models/MentoriaRegistro.php`, `Services/StageGate.php` (F06, F07, F09, F10), `Services/Rotina/RotinaService.php` (F06, F10, F11, F12, F13), `Services/Indicators/IndicatorCalculator.php`, `Services/AlertDispatcher.php` (F02), `Resources/views/tecnico/index.blade.php`, `Resources/views/partials/workflow.blade.php`, `tests/Feature/Workshop/Concerns/BuildsWorkshop.php`, `HttpFlowTest.php`, `StageGateTest.php`.
5. **F04 (perfis)**: `PilotoRequisitos` depende só de `Colaborador.cargos` com as chaves `gerente`/`proprietario` e de `RecipientResolver::byPerfil()`. Se a F04 renomear cargos ou mudar onde o perfil é guardado, ajustar `temGerenteOuProprietario()` e manter `byPerfil` funcionando.
6. **F00 (oficinas)**: o modo de teste é por banco de oficina, então só afeta a oficina em que for marcado. Se a F00 criar uma marca "oficina de teste" no cadastro de oficinas, `ModoTeste::ativo()` é o ponto único para consultá-la (decisão 5). A F00 também precisa garantir que cada banco de oficina tenha as migrations (a tela de barreiras falha em banco sem as colunas de `2026_11_17_100000`).
7. **Efeitos de ligar o modo numa oficina com dados reais**: barreiras abertas passam a ter prazos curtos e geram alertas; indicadores guardam eventos com o critério do modo. Por isso: confirmação ao ligar, aviso vermelho na tela, selo em todas as telas, registro de auditoria e a instrução de nunca usar em oficina real.
8. **Ao desligar o modo**, as barreiras abertas durante o teste mantêm o prazo curto (decisão consciente: não reescrever datas). Para limpar, cumprir ou cancelar as OS de teste.
9. **Precisão do `tick`**: o agendador roda a cada minuto; com prazos de 1 min o alerta pode chegar até 60 s depois. Aceitável para teste.
10. **Aviso na tela de Registros**: os registros `piloto_alterado`/`modo_teste_alterado` aparecem em "Últimos registros", que oferece "Corrigir" para qualquer linha (comportamento já existente para `bonus_estorno`). Sem impacto.
11. **Flash em uma linha**: o layout imprime `session('error'|'success'|'warning')` dentro de uma string JavaScript; nunca colocar quebra de linha nem HTML nesses avisos.
12. **Testes**: nomes de funções auxiliares globais do Pest precisam ser únicos; toda data usa `$this->at()`; `toEqual` para números vindos de JSON.

---

## Decisões em aberto

1. **O modo de teste deve encurtar também o tempo padrão dos serviços (linhas 36 a 38 do roteiro)?** Hoje o PDF não diz. **Recomendação**: sim, mas como segunda caixa separada, desligada por padrão ("Dividir também o tempo padrão dos serviços"); o patrão liga só nessas linhas. Já está especificada assim; para cortar, remover o item 6 e o teste 10.
2. **Janela de aprovação do bônus (dia 1 ao 5º dia útil) aberta no modo de teste?** É uma janela de calendário e não pode ser dividida; sem isso, a Etapa 10 do roteiro (linhas 62 a 65) só passa nos 5 primeiros dias úteis do mês. **Recomendação**: sim (item 10). Quem quiser testar a própria janela desliga o modo de teste.
3. **Exigir "Técnico designado" também no Diagnóstico → Orçamento?** Fecha o furo de uma OS que entra à força no Diagnóstico e é "diagnosticada" por quem não é técnico, sem ninguém responsável nos indicadores TEC. **Recomendação**: sim (item 17).
4. **O colaborador Gerente/Proprietário precisa estar ligado a um usuário do sistema para contar?** O PDF só pede o colaborador. **Recomendação**: não exigir (literal ao PDF); a tela mostra um aviso quando nenhum usuário tem o papel (`avisos()`), e a F04 sincroniza cargo e perfil. Se preferir exigir, acrescentar `whereNotNull('user_id')` e a checagem de usuário ativo em `temGerenteOuProprietario()`.
5. **Travar o modo de teste para só poder ser ligado na Oficina Teste Mentoria?** Hoje a caixa é livre, com confirmação, selo e auditoria. **Recomendação**: sim, quando a F00 criar a marca de "oficina de teste" no cadastro de oficinas; até lá, manter como está.
6. **Botão "Voltar todas as barreiras ao padrão" (sobrescreve prazos editados)?** O botão atual só recria as que faltam, o que basta para a oficina nova do A6. **Recomendação**: não agora; se for pedido, gravar o "antes" em `mentoria_registros` antes de sobrescrever.
7. **Impostos igual a 0 conta como preenchido?** **Recomendação**: sim (o dono declarou; um MEI paga valor fixo). Valor da hora igual a 0 não conta.

---

## Passo a passo para o usuário

Esta frente **não tem migration**: depois de subir o zip não é preciso rodar o `migrate-safe.php` por causa dela (as outras frentes terão as próprias). Tudo abaixo é feito pelas telas, na **Oficina Teste Mentoria**, como Proprietário.

1. **Configurações > Ficha de Enquadramento**: preencher pelo menos Valor da hora de mão de obra, Impostos sobre a venda e Data de início da mentoria. Para a Ficha completa do A5, preencher também comissões, despesas variáveis de peças, margem desejada de peças, lucro desejado, encargos, meta de faturamento, gatilho da Fase 2 e o % de bônus de cada cargo. Salvar.
2. **Colaboradores**: cadastrar um colaborador por cargo, cada um ligado a um usuário do sistema, e garantir pelo menos um ativo com o cargo **Gerente** ou **Proprietário**.
3. **Configurações > Indicadores e alertas**: conferir a caixa "Para ligar o piloto, a oficina precisa ter:" (tudo "Ok") e o aviso de papéis (em "Papéis e celulares", dizer qual papel cada função representa).
4. Ainda nessa tela, **horário comercial**: informar o expediente real da oficina de teste. Para rodar o roteiro fora do expediente, deixar **todos os dias desmarcados** (os prazos passam a contar em relógio corrido). Ajustar ou desligar o **horário de silêncio** se for rodar à noite (senão os alertas comuns só chegam de manhã).
5. Clicar em **"Restaurar as padrão da mentoria que faltam"** (a mensagem diz o que foi criado), marcar **"Piloto de indicadores e alertas ligado nesta oficina"** e **Salvar configurações**. Se faltar algo, o piloto não liga, o aviso lista o que falta e os links levam à Ficha e aos Colaboradores; as demais configurações ficam salvas.
6. No cartão **"Modo de teste das barreiras"**: marcar "Ligar o modo de teste", e, para as linhas 36 a 38 do roteiro, "Dividir também o tempo padrão dos serviços"; **Salvar modo de teste**. Confirmar o selo vermelho **"MODO DE TESTE (prazos ÷ 10)"** (canto inferior direito, ao lado do sino, e nos títulos das telas). **Ligue antes de abrir as OS do roteiro**: barreiras já abertas mantêm o prazo antigo.
7. Rodar o roteiro lembrando a tradução: onde diz 2 h o sistema usa 12 min; 24 h → 2 h 24; 36 h → 3 h 36; 48 h → 4 h 48; 7 dias → 16 h 48; 30 min → 3 min; 10 min → 1 min.
8. Para a Etapa 10 (bônus), manter o modo de teste ligado se a decisão 2 for aceita; senão fazer essas linhas entre o dia 1 e o 5º dia útil do mês.
9. **Ao terminar o roteiro**, desmarcar o modo de teste e salvar. **Nunca ligar o modo de teste numa oficina real.**
10. Para conferir o teste do A5 à mão: desligar o piloto, apagar (ou esvaziar) o valor da hora na Ficha e tentar ligar; a tela deve listar "valor da hora de mão de obra" e não ligar.

---

## Ordem sugerida de implementação

1. `ModoTeste`, chaves em `WorkshopSettings::DEFAULTS` e `BusinessHours::addMinutes` com fração (itens 1 a 3). Rodar `--filter=BarrierEngine` e `--filter=AllBarriers` para provar que, com o modo desligado, nada mudou.
2. `BarrierEngine` (item 4) e `ModoTesteTest` casos 1 a 5.
3. Tempo alvo do diagnóstico, `OperationService`, `RotinaService`, `AlcadaService`, `IndicatorCalculator` (itens 5 a 9) e os casos 6 a 10 e 14 do `ModoTesteTest`; rodar `--filter=Rotina`, `--filter=Alcadas`, `--filter=StageGate`, `--filter=Indicators`.
4. Rota, ACL, `updateTestMode`, `restoreRules`, partial do selo e includes (itens 13c, 13d, 15, 16) e casos 11 e 12 do `ModoTesteTest`; `PilotoOrcamentoGateTest` casos 4 e 5.
5. `PilotoRequisitos`, trava em `updateGeneral`, checklist na tela (itens 12, 13a, 13b, 14, 16c) e `PilotoRequisitosTest`; ajustar `HttpFlowTest.php:305` com o helper de `BuildsWorkshop`.
6. Endurecimento do `StageGate` (item 17), ajuste dos três testes antigos e `PilotoOrcamentoGateTest` casos 1 a 3.
7. Itens opcionais (10 e 11), conforme as decisões 2 e do push.
8. `--filter=Workshop`, depois `php vendor/bin/pint` nos arquivos tocados.

## Avisos para a documentação (RETOMAR-TRABALHO.md, a ser atualizado fora desta frente)

- O piloto passou a exigir, para LIGAR: valor da hora, impostos, data de início da mentoria e um colaborador ativo Gerente ou Proprietário. Quem já está ligado não é desligado.
- Modo de teste das barreiras (Configurações > Indicadores e alertas): prazos ÷ 10, só com o piloto ligado, por oficina, com selo vermelho "MODO DE TESTE (prazos ÷ 10)" e registro em Registros do dia a dia. Horário comercial e silêncio continuam valendo; sem nenhum dia marcado o horário comercial conta em relógio corrido.
- O que é gravado é sempre o prazo real; o divisor vive em `Services/ModoTeste.php` e é aplicado na abertura da barreira, no tempo alvo do diagnóstico, nos cronômetros da rotina, no prazo de 24 h do dono e em GER-09, DON-06 e CON-12.
- Sem migration nesta frente. Novas chaves em `workshop_settings`: `modo_teste_barreiras`, `modo_teste_tempo_padrao`, `modo_teste_divisor`.
- Diagnóstico → Orçamento passou a exigir "Técnico designado" (se a decisão 3 for aceita).
