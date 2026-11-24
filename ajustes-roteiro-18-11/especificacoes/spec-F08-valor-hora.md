# Especificação — F08-valor-hora

Frente: **Valor da hora e tempo padrão** (item **B5** do PDF de 18/11/2026). Raiz do Laravel: `d:\volmaster crm\volmaster crm`. Todos os caminhos abaixo são relativos a ela. `SO` = `packages/Webkul/ServiceOrder/src`, `PI` = `packages/Webkul/PartInventory/src`.

## Decisões de projeto (leia primeiro)

1. **Tudo da B5 só vale com o piloto ligado** (regra 2 do contexto). Com o piloto desligado a tela de itens funciona exatamente como hoje (preço digitado ou o `preco_padrao` do catálogo, horas digitadas, sem aviso, sem alçada). A única coisa que existe sempre é o campo **opcional** "Tempo padrão (horas)" no catálogo de serviços.
2. **A regra mora no servidor.** O JavaScript só pré-preenche e mostra o aviso; quem decide (preencher padrão, alçada, evento) é `ValorHoraService`, chamado pelo `ServiceOrderController::storeItem`. Nada de regra só na tela.
3. **O formulário de itens não é Vue.** O PDF/briefing fala em "Vue", mas `SO/Resources/views/view.blade.php:243-434` é Blade puro + JS simples com ouvintes no `document` (mesmo padrão de `partials/negociacao.blade.php:152-219`, porque o layout do admin é um app Vue que re-renderiza o miolo da página ao montar). Converter o formulário para Vue seria reescrever a tela sem ganho; o aviso fica em JS simples, mais o aviso do servidor (`session('warning')`) e uma marca permanente na linha do item.
4. **Piso do "abaixo do mínimo" = o menor entre o preço mínimo da hora (DRE) e o valor da hora da Ficha.** Valor digitado igual ou maior que o da Ficha **nunca** pede alçada (aumentar o preço nunca é risco, e a hora da Ficha é a que o dono aprovou). Isto evita que, numa oficina cuja Ficha está abaixo do mínimo calculado pela DRE (justamente o que a análise de preço mínimo revela), TODO serviço do catálogo caísse na fila de alçadas. Quando a Ficha é maior ou igual ao mínimo, a regra é exatamente a do PDF (abaixo do mínimo da DRE).
5. **Múltiplo "3 vezes" configurável** (`valor_hora_limite_multiplo`, padrão 3, campo na Ficha), porque "tudo o que é regra vem da configuração da oficina". Igual a 3 vezes **não** avisa; só acima.
6. **Alçada nova `hora_abaixo_minimo`** (aprovador = Gerente, que pode encaminhar ao dono; mesmo desenho de `preco_abaixo_minimo`). O pedido guarda em `valor` o **% abaixo do piso** (não o R$/h), porque `AlcadaService::consumeApproval` compara `valor aprovado >= valor da nova tentativa` (`SO/Services/Alcada/AlcadaService.php:302-319`): com o "% de déficit", uma aprovação cobre a mesma tentativa ou uma menos agressiva, nunca uma mais baixa. Com R$/h a comparação ficaria invertida.
7. **O número do preço mínimo da hora (derivado da DRE, dado do dono) só aparece para quem tem a permissão `financeiro_dre`.** Os demais (ex.: Consultor) veem "abaixo do preço mínimo da hora" sem o valor; o Gerente/Dono vê o número no pedido da fila. Ver "Decisões em aberto".
8. **Corrigir a OS #11 não é código** (dado do servidor do patrão). Salvaguardas projetadas: aviso na tela, confirmação ao lançar acima do limite, marca vermelha permanente na linha do item, evento de auditoria e evento de remoção de item. Passo a passo para corrigir/cancelar no fim deste arquivo.

## O que já existe

| # | O que | Onde |
|---|---|---|
| 1 | Valor unitário e horas **livres**: `$item->price = $data['preco_unitario']`; `$item->hours = $data['hours'] ?? null` (Serviço tem `quantity = 1`) | `SO/Services/ServiceOrderItemService.php:33`, `:52-53` |
| 2 | Rota/controlador que lança item: `storeItem` (`ValoresFinanceiros::authorize` em `:324`; validação `:328-337`, com `preco_unitario` = `required\|numeric\|min:0`; `addItem` em `:339`). Rota `admin.service_orders.items.store` **sem chave de ACL** (não consta em `SO/Config/acl.php:20-61`) | `SO/Http/Controllers/ServiceOrderController.php:321-344`; `SO/Routes/web.php:48` |
| 3 | Formulário "Adicionar Item" (Blade + JS simples). Só aparece com `$canSeeValues` e OS não "Concluída" (`:243`). Catálogo de serviços `:270-278` (`data-price="{{ $service->preco_padrao }}"`); horas `:290-293` (`type=number step=0.5`); valor `:305-308` (`data-money`); botão `:316-318`. JS `:351-434`: `toggleItemFields` `:353-371`, `fillServiceFromCatalog` `:378-387` (copia o `preco_padrao` do catálogo para o campo), `prepareItemSubmit` `:389-404`, ouvintes `:406-430` | `SO/Resources/views/view.blade.php` |
| 4 | Tabela de itens (preço na célula `:167`, exibida só com `$canSeeValues` `:151-176`); botão × (`:205-211`, escondido em "Concluída"); `$pilot` já chega à view (`ServiceOrderController.php:219,228`) | `view.blade.php:145-241` |
| 5 | Catálogo de serviços: tabela `services` com `nome, descricao, part_category_id, preco_padrao` — **sem tempo padrão** | `PI/Database/Migrations/2026_09_02_100000_create_services_table.php:20-26`; `PI/Models/Service.php:12-17` |
| 6 | Telas do catálogo: criar/editar (`PI/Resources/views/services/create.blade.php:47-63`, `edit.blade.php:47-63`), validação (`PI/Http/Controllers/ServiceController.php:48-64`, `:90-106`), DataGrid (`PI/DataGrids/ServiceDataGrid.php:16-24`, `:54-62`), rotas `/admin/services-catalog` (`PI/Routes/web.php:26-38`), menu "Serviços" (`PI/Config/menu.php:11-15`), ACL `services`, `services.create`, `services.edit` | `PI/…` |
| 7 | **Tempo padrão da execução = horas do item**: `OperationService::padraoSeconds` e cor do cronômetro (verde ≤100%, amarelo ≤ `tecnico_margem_percentual`=120%, vermelho acima). A tela do técnico lê isso (`padrao_seg`) | `SO/Services/OperationService.php:79-82`, `:98-107` |
| 8 | **Valor da hora da Ficha**: `WorkshopSettings` chave `valor_hora_mao_obra` (padrão `null`); campo na Ficha; gravação via `BrNumber`; DRE lê como `hora_cobrada` | `SO/Services/WorkshopSettings.php:67`; `SO/Resources/views/ficha/edit.blade.php:47`; `SO/Http/Controllers/FichaController.php:45,80-82`; `SO/Services/Financeiro/DreService.php:39-54` |
| 9 | **Preço mínimo da hora (DRE)**: `DreService::precoMinimoHora(string $periodo)` devolve `preco_minimo` (custo a cobrir + lucro desejado ÷ horas vendáveis ÷ (1 − impostos − comissões)), com 3 meses anteriores; mostrado na DRE e no indicador DRE-06. **Nada compara o valor lançado numa OS com ele** | `DreService.php:273-316`; `SO/Resources/views/financeiro/dre.blade.php:69-88`; `SO/Services/Indicators/IndicatorCalculator.php:1219-1226` |
| 10 | **Alçada**: `AlcadaService::authorize($tema, $os, $valor, $descricao, $user)` (bloqueia criando pedido; só com piloto ligado, `:115`), `consumeApproval` (aprovação vale uma vez), `levelOf`, `required`, `forward`/`decide`. Catálogo de temas já tem `preco_abaixo_minimo` (peça, `:49`) e `valor_hora_margem` (mudança da hora/tabela de margem **na configuração**, tipo dono, `:43`). **Não existe tema para o valor da hora lançado numa OS.** Modelo de uso: `WorkflowActions::price()` | `SO/Services/Alcada/AlcadaService.php:35-48,55-76,111-143,302-319`; `AlcadaCatalog.php:26-52`; `SO/Services/WorkflowActions.php:383-415` |
| 11 | `alcada_regras.tema` e `alcada_pedidos.tema` são `string(30)`: a chave nova precisa ter ≤ 30 caracteres. A fila formata `valor` por `unidade` (`percent`→"24,0%", `brl`→"R$ …") | `SO/Database/Migrations/2026_11_17_100200_create_alcada_tables.php:15,27`; `SO/Http/Controllers/AlcadaController.php:161-172`; `SO/Resources/views/alcadas/fila.blade.php:4-6` |
| 12 | Técnico sem valores: `ValoresFinanceiros::can()` (piloto desligado → todos veem) e `authorize()` (403). Teste existente: `items.store` → 403 para o Técnico. O técnico só lança **peças**, e o preço vem do servidor: `TecnicoController::addPart` | `SO/Services/ValoresFinanceiros.php:20-57`; `tests/Feature/Workshop/TecnicoValoresTest.php:137`; `SO/Http/Controllers/TecnicoController.php:94-109` |
| 13 | Outro caminho que cria linha "Serviço" que **não é mão de obra**: `RotinaService::lancarDiaria` (`hours = 1`, preço = diária). Testado em `RotinaTest.php:143-159`. **Não pode sofrer a regra da hora** (por isso a regra fica no controlador, não dentro de `addItem`) | `SO/Services/Rotina/RotinaService.php:394-414` |
| 14 | Auditoria: `ServiceOrderEvent` é append-only e **exige o tipo registrado** em `TIPOS`; a linha do tempo esconde de quem não vê valores os tipos listados em `$eventosRestritos`. Remover item hoje **não deixa rastro** | `SO/Models/ServiceOrderEvent.php:26-96,141-143`; `SO/Resources/views/partials/workflow.blade.php:20-21`; `SO/Services/ServiceOrderItemService.php:84-99` |
| 15 | Piloto: `StageGate::active()`. Padrão de tolerância a coluna ausente: `PrevisaoOs::disponivel()` (+ `ajustar()`) | `SO/Services/StageGate.php:23-26`; `SO/Services/PrevisaoOs.php:19-25,82-97` |
| 16 | Middleware `DonoForaDoFluxo` registra evento quando o dono faz POST em `admin.service_orders.*` sem `error` na sessão (um `warning` não conta como erro — nada a mudar) | `SO/Http/Middleware/DonoForaDoFluxo.php:18-56` |
| 17 | Relatórios que contam OS **faturadas mesmo canceladas** (só olham `faturado_em`, carimbado ao chegar em Faturamento): DRE, ponto de equilíbrio, margem por OS, Painel do Dono (faturado hoje), bônus, relatório diário. Indicadores e comissões já excluem Cancelada | `DreService.php:76,236,361`; `SO/Services/Indicators/PainelDonoService.php:214,351`; `SO/Services/Bonus/BonusService.php:430`; `SO/Services/Financeiro/FinanceiroService.php:327`; `SO/Observers/ServiceOrderObserver.php:119-121`; contra-exemplos `IndicatorCalculator.php:225`, `SO/Services/CommissionReportService.php:32` |

## Requisitos do PDF → como serão atendidos

| Requisito (PDF B5 / roteiro) | Situação hoje | Como será atendido |
|---|---|---|
| "Ao adicionar um serviço, o **valor unitário da hora vem da Ficha**" | **Falta.** A Ficha já guarda o valor (item 8), mas o formulário usa o `preco_padrao` do catálogo ou texto livre | Pilot ligado: ao escolher o tipo "Serviço" ou um serviço do catálogo o campo "Valor Unitário" é preenchido com o valor da Ficha (JS). No servidor, se o campo vier em branco, `ValorHoraService::completar()` usa a Ficha. Ficha sem valor → comportamento de hoje (catálogo/digitado) e dica "Ficha sem valor da hora" |
| "…e as **horas vêm do catálogo de serviços (tempo padrão)**" | **Falta.** O catálogo não tem tempo (item 5) | Nova coluna `services.tempo_padrao_horas`, campo "Tempo padrão (horas)" nas telas do catálogo e coluna na lista; `data-horas` em cada opção do formulário; o JS preenche "Horas"; o servidor completa se vier em branco. A hora vira o `hours` do item = tempo padrão do cronômetro do técnico (item 7) |
| "Alterar o valor da hora na OS para **baixo do preço mínimo da hora (DRE)** … mostra aviso" | **Parcial.** O mínimo existe (item 9); nada compara, não há aviso | `ValorHoraService::avaliar()` + rota JSON `admin.service_orders.valor_hora` (aviso ao digitar) + `session('warning')` depois de gravar + evento `valor_hora_fora_da_faixa` |
| "…ou para **cima de 3 vezes o valor da Ficha** mostra aviso" | **Falta** | Mesmo mecanismo; limite = Ficha × `valor_hora_limite_multiplo` (padrão 3); banner vermelho, `confirm()` ao enviar, marca vermelha permanente na linha do item |
| "**abaixo do mínimo exige alçada**" | **Parcial.** Motor de alçada pronto (item 10); falta o tema e a chamada | Tema `hora_abaixo_minimo` em `AlcadaCatalog`; `ValorHoraService::conferir()` chama `AlcadaService::authorize()` no `storeItem` (piloto ligado; Gerente/Dono lançam direto; Consultor etc. abrem pedido ao gerente e repetem a ação depois da aprovação) |
| "**Corrigir a OS de teste #11** (6 h × R$ 10.000) **ou cancelá-la**" | **Dado do servidor** — não há como fazer aqui | Salvaguardas acima + evento `item_removido` (a correção deixa rastro) + passo a passo (fim do arquivo), incluindo o caso de OS já faturada/concluída |
| **Teste:** "serviço do catálogo entra com o valor da Ficha; R$ 10.000/h mostra aviso" | — | Testes 1 e 3 abaixo |
| Roteiro **24** (serviço do catálogo entra com o valor da Ficha e as horas do tempo padrão) e **25** (R$ 10.000/h → aviso na tela) | — | Testes 1, 3, 13 |
| Roteiro **36** (serviço de 1 h de tempo padrão: verde até 1 h, amarelo até 1 h 12, vermelho depois) | Lógica existe; faltava a hora vir do catálogo | Teste 17 (serviço lançado do catálogo com 1 h → cores do cronômetro) |
| Roteiro **5/6** (Técnico não vê valores; 403) | Existe | Teste 12: Técnico não vê Ficha, hora, aviso nem o endpoint |
| Regras gerais: "regra vem da configuração da oficina"; "nada se apaga" | — | Múltiplo na Ficha; pedido de alçada e eventos são append-only; remoção de item vira evento |

## Mudanças por arquivo

### Criar

#### 1. `SO/Services/ValorHoraService.php` (regra de negócio da B5)

Resolvido pelo contêiner (depende de `DreService` e `AlcadaService`). Constantes: `TEMA_ALCADA = 'hora_abaixo_minimo'`, `FAIXA_OK = 'ok'`, `FAIXA_ABAIXO_MINIMO = 'abaixo_minimo'`, `FAIXA_ACIMA_LIMITE = 'acima_limite'`.

```php
<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\Schema;
use Webkul\PartInventory\Models\Service;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Services\Alcada\AlcadaCatalog;
use Webkul\ServiceOrder\Services\Alcada\AlcadaService;
use Webkul\ServiceOrder\Services\Financeiro\DreService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorFormatter;
use Webkul\User\Models\User;

class ValorHoraService
{
    public const TEMA_ALCADA = 'hora_abaixo_minimo';
    public const FAIXA_OK = 'ok';
    public const FAIXA_ABAIXO_MINIMO = 'abaixo_minimo';
    public const FAIXA_ACIMA_LIMITE = 'acima_limite';

    protected static ?bool $infraestrutura = null;
    protected bool $minimoCalculado = false;
    protected ?float $minimo = null;

    public function __construct(protected DreService $dre, protected AlcadaService $alcadas) {}

    /** B5 só vale com o piloto ligado. */
    public function ativo(): bool
    {
        return StageGate::active();
    }

    /** Valor da hora de mão de obra da Ficha; null quando não preenchido (leitor canônico: F03 pode reaproveitar). */
    public function valorFicha(): ?float
    {
        $valor = (float) (WorkshopSettings::get('valor_hora_mao_obra') ?? 0);

        return $valor > 0 ? round($valor, 2) : null;
    }

    /** Múltiplo da Ficha acima do qual avisa (padrão 3, configurável na Ficha). */
    public function multiplo(): float
    {
        $multiplo = (float) (WorkshopSettings::get('valor_hora_limite_multiplo') ?? 0);

        return $multiplo > 1 ? $multiplo : (float) WorkshopSettings::DEFAULTS['valor_hora_limite_multiplo'];
    }

    /** "3" ou "2,5" (para textos). */
    public function multiploTexto(): string
    {
        return rtrim(rtrim(number_format($this->multiplo(), 1, ',', ''), '0'), ',');
    }

    public function limite(): ?float
    {
        $ficha = $this->valorFicha();

        return $ficha === null ? null : round($ficha * $this->multiplo(), 2);
    }

    /** Estritamente maior que o limite (igual a 3 vezes não avisa). Barato: só aritmética, serve à marca da tabela de itens. */
    public function acimaDoLimite(float $valor): bool
    {
        $limite = $this->limite();

        return $limite !== null && round($valor, 2) > $limite;
    }

    /** Preço mínimo da hora pela DRE do mês (memoizado por instância); null se não calculável (> 0). */
    public function precoMinimo(): ?float
    {
        if (! $this->minimoCalculado) {
            $this->minimoCalculado = true;

            if (static::infraestruturaDisponivel()) {
                $minimo = $this->dre->precoMinimoHora(now()->format('Y-m'))['preco_minimo'];

                $this->minimo = $minimo !== null && $minimo > 0 ? (float) $minimo : null;
            }
        }

        return $this->minimo;
    }

    /** Menor entre o mínimo da DRE e o valor da Ficha (decisão de projeto 4). */
    public function piso(): ?float
    {
        $minimo = $this->precoMinimo();
        $ficha = $this->valorFicha();

        if ($minimo === null) {
            return null;
        }

        return $ficha === null ? $minimo : min($minimo, $ficha);
    }

    /** OS de retorno/garantia não cobra mão de obra por padrão (B6): o mínimo não se aplica. F12 pode refinar. */
    public function dispensaMinimo(ServiceOrder $os): bool
    {
        return in_array($os->tipo_os, ['retorno', 'garantia'], true);
    }

    /**
     * @return array{faixa: string, valor: float, valor_ficha: ?float, multiplo: float, limite: ?float, minimo: ?float, piso: ?float}
     */
    public function avaliar(float $valor, ?ServiceOrder $os = null): array
    {
        $valor = round($valor, 2);
        $ficha = $this->valorFicha();

        $resultado = [
            'faixa' => self::FAIXA_OK, 'valor' => $valor, 'valor_ficha' => $ficha,
            'multiplo' => $this->multiplo(), 'limite' => $this->limite(), 'minimo' => null, 'piso' => null,
        ];

        if ($this->acimaDoLimite($valor)) {
            $resultado['faixa'] = self::FAIXA_ACIMA_LIMITE;

            return $resultado;
        }

        // O mínimo da DRE custa 3 cálculos mensais: só é consultado quando o valor ficou abaixo da Ficha.
        if (($ficha === null || $valor < $ficha) && ! ($os && $this->dispensaMinimo($os))) {
            $resultado['minimo'] = $this->precoMinimo();
            $resultado['piso'] = $this->piso();

            if ($resultado['piso'] !== null && $valor < round($resultado['piso'], 2)) {
                $resultado['faixa'] = self::FAIXA_ABAIXO_MINIMO;
            }
        }

        return $resultado;
    }

    /** O usuário precisaria de aprovação (tema ativo na matriz e nível abaixo de Gerente)? */
    public function exigeAprovacao(?User $user = null): bool
    {
        if (! static::infraestruturaDisponivel()) {
            return false;
        }

        $user ??= auth()->guard('user')->user();
        $nivel = $this->alcadas->required(self::TEMA_ALCADA, null)['nivel'];

        return $nivel !== AlcadaCatalog::NIVEL_NENHUM && $this->alcadas->levelOf($user) < $nivel;
    }

    /** O número do mínimo (dado da DRE) só vai a quem tem a permissão da DRE. */
    public function podeVerMinimo(): bool
    {
        return bouncer()->hasPermission('financeiro_dre');
    }

    /** $depois = false: aviso antes de gravar (banner/JSON); true: aviso depois de gravar (flash). */
    public function mensagem(array $avaliacao, bool $depois = false): ?string
    {
        $reais = fn (?float $v) => IndicatorFormatter::value($v, 'brl');

        return match ($avaliacao['faixa']) {
            self::FAIXA_ACIMA_LIMITE => trans('service_order::app.valor_hora.'.($depois ? 'lancado_acima' : 'aviso_acima'), [
                'valor' => $reais($avaliacao['valor']), 'multiplo' => $this->multiploTexto(), 'ficha' => $reais($avaliacao['valor_ficha']),
            ]),
            self::FAIXA_ABAIXO_MINIMO => $depois
                ? trans('service_order::app.valor_hora.lancado_abaixo', ['valor' => $reais($avaliacao['valor'])])
                : trim(
                    ($this->podeVerMinimo()
                        ? trans('service_order::app.valor_hora.aviso_abaixo_com_minimo', ['valor' => $reais($avaliacao['valor']), 'minimo' => $reais($avaliacao['minimo'])])
                        : trans('service_order::app.valor_hora.aviso_abaixo_sem_minimo', ['valor' => $reais($avaliacao['valor'])])
                    ).' '.trans('service_order::app.valor_hora.'.($this->exigeAprovacao() ? 'exige_aprovacao' : 'alcada_propria'))
                ),
            default => null,
        };
    }

    /**
     * Padrões que faltam no formulário de item de SERVIÇO (piloto ligado): valor da hora da
     * Ficha, horas do tempo padrão do catálogo e descrição do catálogo. Devolve só o que
     * deve ser acrescentado à requisição ([] para Peça ou piloto desligado).
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function completar(array $dados): array
    {
        if (! $this->ativo() || ($dados['tipo'] ?? null) !== ServiceOrderItem::TIPO_SERVICO) {
            return [];
        }

        $servico = ! empty($dados['service_id']) ? Service::find($dados['service_id']) : null;
        $completar = [];

        if (blank($dados['preco_unitario'] ?? null) && ($ficha = $this->valorFicha()) !== null) {
            $completar['preco_unitario'] = $ficha;
        }

        if (blank($dados['hours'] ?? null) && $servico && (float) $servico->tempo_padrao_horas > 0) {
            $completar['hours'] = (float) $servico->tempo_padrao_horas;
        }

        if (blank($dados['descricao'] ?? null) && $servico) {
            $completar['descricao'] = $servico->nome;
        }

        return $completar;
    }

    /**
     * Confere o valor da hora ANTES de gravar. Abaixo do piso: alçada (Gerente/Dono passam
     * direto; os demais abrem pedido e a ação é interrompida com ValidationException).
     * Devolve a avaliação (ou null para Peça/piloto desligado).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function conferir(ServiceOrder $os, array $dados, ?User $user = null): ?array
    {
        if (! $this->ativo() || ($dados['tipo'] ?? null) !== ServiceOrderItem::TIPO_SERVICO) {
            return null;
        }

        $avaliacao = $this->avaliar((float) $dados['preco_unitario'], $os);

        if ($avaliacao['faixa'] === self::FAIXA_ABAIXO_MINIMO) {
            $deficit = round(($avaliacao['piso'] - $avaliacao['valor']) / $avaliacao['piso'] * 100, 2);

            // "valor" do pedido = % abaixo do piso (decisão de projeto 6).
            $this->alcadas->authorize(self::TEMA_ALCADA, $os, $deficit, $this->descricaoDoPedido($dados, $avaliacao, $deficit), $user);
        }

        return $avaliacao;
    }

    /** Depois de gravar: evento de auditoria (sem R$) e avisos na sessão. */
    public function registrar(ServiceOrder $os, ServiceOrderItem $item, ?array $avaliacao): void
    {
        if ($avaliacao === null) {
            return;
        }

        $avisos = [];

        if ($avaliacao['faixa'] !== self::FAIXA_OK) {
            ServiceOrderEvent::record($os, 'valor_hora_fora_da_faixa', ['item_id' => $item->id, 'item' => $item->name, 'faixa' => $avaliacao['faixa']]);
            $avisos[] = $this->mensagem($avaliacao, true);
        }

        if ((float) $item->hours <= 0) {
            $avisos[] = trans('service_order::app.valor_hora.sem_horas');
        }

        if ($avisos) {
            session()->flash('warning', implode(' ', $avisos));
        }
    }

    protected function descricaoDoPedido(array $dados, array $avaliacao, float $deficit): string
    {
        $reais = fn (?float $v) => IndicatorFormatter::value($v, 'brl');

        return trans('service_order::app.valor_hora.alcada_descricao', [
            'servico' => $dados['descricao'],
            'horas' => rtrim(rtrim(number_format((float) ($dados['hours'] ?? 0), 2, ',', ''), '0'), ','),
            'valor' => $reais($avaliacao['valor']),
            'deficit' => IndicatorFormatter::value($deficit, 'percent'),
            'piso' => $reais($avaliacao['piso']),
            'minimo' => $reais($avaliacao['minimo']),
            'ficha' => $reais($avaliacao['valor_ficha']),
        ]);
    }

    /** Servidor só com parte das migrations (ex.: sem as de 17/11) não pode dar erro 500 (padrão PrevisaoOs::disponivel). */
    protected static function infraestruturaDisponivel(): bool
    {
        return static::$infraestrutura ??= collect(['dre_fechamentos', 'plano_contas', 'financeiro_lancamentos', 'colaboradores', 'alcada_regras', 'alcada_pedidos'])
            ->every(fn (string $tabela) => Schema::hasTable($tabela));
    }
}
```

Observações para quem implementa:
- `precoMinimoHora` só é chamado quando `valor < Ficha` (ou Ficha vazia): lançar a hora da Ficha ou acima nunca paga o custo dos 3 meses de DRE.
- `avaliar()` com `os` de `tipo_os` retorno/garantia pula o mínimo (continua avisando o "acima de 3×").
- Nenhum `try/catch` engolindo erro: a proteção é a checagem de tabelas, no estilo de `PrevisaoOs::disponivel()`.

#### 2. `SO/Http/Controllers/ValorHoraController.php`

Uma ação, usada só pelo aviso do formulário. Rota `GET admin/service-orders/valor-hora?valor=60.00&os=11`.

```php
class ValorHoraController extends Controller
{
    public function __construct(protected ValorHoraService $valorHora) {}

    public function avaliar(Request $request): JsonResponse
    {
        ValoresFinanceiros::authorize('valor da hora');   // Técnico (e quem não vê valores) → 403, também em JSON

        $dados = $this->validate($request, ['valor' => 'required|numeric|min:0|max:99999999', 'os' => 'nullable|integer']);

        if (! $this->valorHora->ativo()) {
            return response()->json(['ativo' => false, 'faixa' => ValorHoraService::FAIXA_OK, 'exige_aprovacao' => false, 'mensagem' => null]);
        }

        $os = ! empty($dados['os']) ? ServiceOrder::find($dados['os']) : null;
        $avaliacao = $this->valorHora->avaliar((float) $dados['valor'], $os);

        return response()->json([
            'ativo' => true,
            'faixa' => $avaliacao['faixa'],
            'exige_aprovacao' => $this->valorHora->exigeAprovacao(),
            'mensagem' => $this->valorHora->mensagem($avaliacao),   // texto pronto; sem o R$ do mínimo para quem não tem financeiro_dre
        ]);
    }
}
```
A resposta **não** devolve `minimo`, `piso` nem a Ficha como campos (só o texto montado no servidor).

#### 3. `SO/Resources/views/partials/valor-hora.blade.php`

Incluído **dentro do `<form id="add-item-form">`** (logo antes de `</form>`, depois do botão), portanto só existe no bloco `@if ($serviceOrder->workshop_status !== 'Concluída' && $canSeeValues)` de `view.blade.php:243`. Não renderiza nada com o piloto desligado ou para o Técnico.

```blade
@php
    $valorHora = app(\Webkul\ServiceOrder\Services\ValorHoraService::class);
@endphp

@if ($valorHora->ativo())
    @php
        $fichaValor = $valorHora->valorFicha();
        $limiteValor = $valorHora->limite();
        $reais = fn ($valor) => 'R$ '.number_format((float) $valor, 2, ',', '.');
    @endphp

    <div id="valor-hora-config" class="hidden"
         data-url="{{ route('admin.service_orders.valor_hora') }}"
         data-os="{{ $serviceOrder->id }}"
         data-ficha="{{ $fichaValor }}"
         data-limite="{{ $limiteValor }}"
         data-multiplo="{{ $valorHora->multiploTexto() }}"></div>

    <div class="flex w-full basis-full flex-col gap-2">
        <p id="valor-hora-dica" class="text-xs text-gray-500 dark:text-gray-400" style="display:none">
            @if ($fichaValor)
                {{ trans('service_order::app.valor_hora.dica', ['ficha' => $reais($fichaValor), 'limite' => $reais($limiteValor)]) }}
            @else
                {{ trans('service_order::app.valor_hora.ficha_vazia') }}
            @endif
        </p>

        <div id="valor-hora-aviso" role="alert" aria-live="polite" class="hidden"></div>
    </div>

    @push('scripts')
        <script>
            // Ouvintes no document e buscas na hora do evento: o layout do admin é um app Vue que
            // re-renderiza este miolo ao montar (mesmo padrão de partials/negociacao.blade.php).
            (function () {
                if (window.vmValorHora) {
                    return;
                }

                var textos = @json(['confirmar_acima' => trans('service_order::app.valor_hora.confirmar_acima')]);
                var byId = function (id) { return document.getElementById(id); };
                var num = function (v) { var n = parseFloat(v); return isNaN(n) ? null : n; };
                var money = function (v) { var n = window.vmMoney ? window.vmMoney.parse(v) : parseFloat(v); return isNaN(n) ? null : n; };
                var brl = function (n) { return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };
                var config = function () { var el = byId('valor-hora-config'); return el ? el.dataset : null; };
                var setMoney = function (input, n) { if (window.vmMoney) { window.vmMoney.set(input, n); } else { input.value = n.toFixed(2); } };
                var temporizador = null;
                var ultima = { chave: null, resposta: null };

                function mostrar(resposta) {
                    var caixa = byId('valor-hora-aviso');

                    if (! caixa) { return; }

                    if (! resposta || ! resposta.mensagem || resposta.faixa === 'ok') {
                        caixa.className = 'hidden';
                        caixa.textContent = '';
                        return;
                    }

                    // Vermelho = provável erro de digitação (acima do limite); âmbar = abaixo do mínimo (alçada).
                    caixa.className = 'rounded-md border px-3 py-2 text-sm ' + (resposta.faixa === 'acima_limite'
                        ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-700 dark:bg-red-950 dark:text-red-200'
                        : 'border-yellow-300 bg-yellow-50 text-yellow-900 dark:border-yellow-700 dark:bg-yellow-950 dark:text-yellow-200');
                    caixa.textContent = resposta.mensagem;   // textContent: nada de HTML vindo do servidor
                }

                function avaliar() {
                    var cfg = config();
                    var preco = byId('item-preco');
                    var tipo = byId('item-tipo');
                    var valor = preco ? money(preco.value) : null;

                    if (! cfg || ! tipo || tipo.value !== 'Serviço' || valor === null) {
                        mostrar(null);
                        return;
                    }

                    var chave = valor.toFixed(2);

                    if (ultima.chave === chave) {
                        mostrar(ultima.resposta);
                        return;
                    }

                    var url = cfg.url + '?valor=' + encodeURIComponent(chave) + (cfg.os ? '&os=' + encodeURIComponent(cfg.os) : '');

                    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .then(function (resposta) {
                            var atual = preco ? money(preco.value) : null;

                            if (atual === null || atual.toFixed(2) !== chave) { return; }   // já digitou outro valor

                            ultima = { chave: chave, resposta: resposta };
                            mostrar(resposta);
                        })
                        .catch(function (erro) { console.warn('Valor da hora: não foi possível avaliar agora.', erro); });
                }

                function agendar() {
                    clearTimeout(temporizador);
                    temporizador = setTimeout(avaliar, 350);
                }

                window.vmValorHora = {
                    // Chamado por toggleItemFields() em view.blade.php.
                    aoMudarTipo: function (tipo) {
                        var cfg = config();
                        var preco = byId('item-preco');
                        var dica = byId('valor-hora-dica');

                        if (dica) { dica.style.display = tipo === 'Serviço' ? '' : 'none'; }

                        if (! cfg || ! preco) { return; }

                        if (tipo === 'Serviço') {
                            var ficha = num(cfg.ficha);

                            if (ficha !== null) { setMoney(preco, ficha); preco.dataset.autoFicha = '1'; }
                        } else if (preco.dataset.autoFicha === '1') {
                            preco.value = '';
                            delete preco.dataset.autoFicha;
                        }

                        mostrar(null);

                        if (tipo === 'Serviço') { agendar(); }
                    },

                    // Chamado ao fim de fillServiceFromCatalog() em view.blade.php (depois do que ela já faz).
                    aoEscolherServico: function (opcao) {
                        var cfg = config();

                        if (! cfg || ! opcao || ! opcao.value) { return; }

                        var preco = byId('item-preco');
                        var horas = byId('item-hours');
                        var ficha = num(cfg.ficha);

                        if (preco && ficha !== null) { setMoney(preco, ficha); preco.dataset.autoFicha = '1'; }
                        if (horas) { horas.value = opcao.dataset.horas || ''; }

                        agendar();
                    },
                };

                document.addEventListener('input', function (event) {
                    if (event.target && event.target.id === 'item-preco') { agendar(); }
                });

                // Valor acima do limite: pede confirmação (não bloqueia; o servidor avisa de novo).
                document.addEventListener('submit', function (event) {
                    if (! event.target || event.target.id !== 'add-item-form') { return; }

                    var cfg = config();
                    var tipo = byId('item-tipo');
                    var preco = byId('item-preco');

                    if (! cfg || ! tipo || tipo.value !== 'Serviço' || ! preco) { return; }

                    var valor = money(preco.value);
                    var limite = num(cfg.limite);
                    var ficha = num(cfg.ficha);

                    if (valor !== null && limite !== null && valor > limite) {
                        var texto = textos.confirmar_acima.replace(':valor', brl(valor)).replace(':multiplo', cfg.multiplo).replace(':ficha', brl(ficha));

                        if (! window.confirm(texto)) { event.preventDefault(); }
                    }
                }, true);
            })();
        </script>
    @endpush
@endif
```

Notas: (a) o `@push('scripts')` desta parcial sai ANTES do `@push('scripts')` de `view.blade.php` (a parcial é avaliada antes do fim do arquivo), então `window.vmValorHora` já existe quando `toggleItemFields('Peça')` roda na última linha do script da tela; os ganchos usam `if (window.vmValorHora)` de qualquer forma. (b) Banner: `role="alert"`, texto por `textContent`. (c) Se a Ficha estiver sem valor (`data-ficha=""`), nada é pré-preenchido e `fillServiceFromCatalog` continua copiando o `preco_padrao` do catálogo, como hoje.

#### 4. Migration `PI/Database/Migrations/2026_11_18_081500_add_tempo_padrao_horas_to_services_table.php`

Ver seção "Banco (migrations)". (Horário `08:15:00` reservado para a F08; as demais frentes devem escolher outro.)

#### 5. `tests/Feature/Workshop/ValorHoraTest.php`

Ver seção "Testes Pest a escrever".

### Alterar

#### `PI/Models/Service.php`

```php
use Illuminate\Support\Facades\Schema;

protected $fillable = ['nome', 'descricao', 'part_category_id', 'preco_padrao', 'tempo_padrao_horas'];

protected $casts = ['tempo_padrao_horas' => 'float'];   // coluna ausente → atributo null, sem erro

protected static ?bool $temTempoPadrao = null;

/** Bancos que ainda não receberam a migration seguem funcionando sem o campo (padrão PrevisaoOs::disponivel). */
public static function temTempoPadrao(): bool
{
    return static::$temTempoPadrao ??= Schema::hasColumn('services', 'tempo_padrao_horas');
}

/** Só para testes: força a coluna como (in)disponível; null volta a detectar. */
public static function definirTempoPadraoDisponivel(?bool $disponivel): void
{
    static::$temTempoPadrao = $disponivel;
}

/** Ajusta os dados do formulário: sem a coluna o campo é descartado; vazio ou 0 vira null. */
public static function ajustarTempoPadrao(array $dados): array
{
    if (! static::temTempoPadrao()) {
        unset($dados['tempo_padrao_horas']);

        return $dados;
    }

    if (array_key_exists('tempo_padrao_horas', $dados)) {
        $horas = (float) $dados['tempo_padrao_horas'];
        $dados['tempo_padrao_horas'] = $horas > 0 ? round($horas, 2) : null;
    }

    return $dados;
}
```

#### `PI/Http/Controllers/ServiceController.php` (`store` `:48-64` e `update` `:90-106`)

- Regra nova nos dois `validate`: `'tempo_padrao_horas' => 'nullable|numeric|min:0.01|max:999.99'`, com mensagens: `tempo_padrao_horas.numeric` = "O tempo padrão precisa ser um número de horas (por exemplo, 1.5)." e `tempo_padrao_horas.min` = "O tempo padrão precisa ser maior que zero.".
- Depois de `$data = request()->except('new_category_name');` acrescentar `$data = Service::ajustarTempoPadrao($data);` (import `Webkul\PartInventory\Models\Service`).

#### `PI/DataGrids/ServiceDataGrid.php`

- `prepareQueryBuilder()` (`:16-24`): montar a lista de colunas e, **só se `Service::temTempoPadrao()`**, incluir `'services.tempo_padrao_horas'` no `select`.
- `prepareColumns()`: depois da coluna `preco_padrao` (`:54-62`), **só se `Service::temTempoPadrao()`**:

```php
$this->addColumn([
    'index' => 'tempo_padrao_horas',
    'label' => trans('partinventory::app.services.index.datagrid.tempo'),
    'type' => 'string',
    'sortable' => true,
    'closure' => fn ($row) => $row->tempo_padrao_horas !== null
        ? rtrim(rtrim(number_format((float) $row->tempo_padrao_horas, 2, ',', ''), '0'), ',').' h'
        : '—',
]);
```

#### `PI/Resources/views/services/create.blade.php` e `edit.blade.php`

Depois do grupo "Preço Padrão" (`:47-63`) acrescentar, dentro de `@if (\Webkul\PartInventory\Models\Service::temTempoPadrao()) … @endif` (banco sem a coluna não mostra o campo):

```blade
<x-admin::form.control-group>
    <x-admin::form.control-group.label>
        @lang('partinventory::app.services.form.tempo_padrao')
    </x-admin::form.control-group.label>

    <x-admin::form.control-group.control
        type="number"
        id="tempo_padrao_horas"
        name="tempo_padrao_horas"
        step="any"
        min="0.01"
        max="999.99"
        :value="old('tempo_padrao_horas')"   {{-- edit: old('tempo_padrao_horas', $service->tempo_padrao_horas) --}}
        :label="trans('partinventory::app.services.form.tempo_padrao')"
    />

    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('partinventory::app.services.form.tempo_padrao_dica')</p>

    <x-admin::form.control-group.error control-name="tempo_padrao_horas" />
</x-admin::form.control-group>
```

E, logo abaixo do campo "Preço Padrão" (dentro do mesmo grupo, antes do `error`), uma linha de ajuda: `<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('partinventory::app.services.form.preco_padrao_dica')</p>`. O campo "Preço Padrão" **continua obrigatório** (retrocompatibilidade): vira o valor de reserva quando a Ficha não tem a hora ou com o piloto desligado.

#### `PI/Resources/lang/pt_BR/app.php` e `en/app.php`

Chaves em "Textos da interface".

#### `SO/Http/Controllers/ServiceOrderController.php` — `storeItem` (`:321-344`)

```php
public function storeItem(int $id): RedirectResponse
{
    // Lançar item exige informar preço: quem não vê valores usa a tela do técnico.
    ValoresFinanceiros::authorize('lançar item com preço');

    $serviceOrder = $this->serviceOrderRepository->findOrFail($id);
    $valorHora = app(ValorHoraService::class);

    // B5 (piloto ligado): serviço sem valor/horas digitados entra com o valor da hora da Ficha e o tempo padrão do catálogo.
    request()->merge($valorHora->completar(request()->all()));

    $this->validate(request(), [ /* regras de hoje, sem alteração */ ]);

    try {
        // Valor da hora abaixo do piso → alçada do gerente (abre o pedido e interrompe; Gerente/Dono passam).
        $avaliacao = $valorHora->conferir($serviceOrder, request()->all());
    } catch (ValidationException $exception) {
        session()->flash('error', collect($exception->errors())->flatten()->first());

        return redirect()->route('admin.service_orders.view', $id);
    }

    $item = $this->serviceOrderItemService->addItem($serviceOrder, request()->all());

    $valorHora->registrar($serviceOrder, $item, $avaliacao);   // evento + flash 'warning' (acima de 3×, abaixo do mínimo, sem horas)

    session()->flash('success', trans('service_order::app.view.item-added'));

    return redirect()->route('admin.service_orders.view', $id);
}
```
- Importar `ValorHoraService`. Peça e piloto desligado: `completar` devolve `[]`, `conferir`/`registrar` não fazem nada → fluxo idêntico ao de hoje.
- **Não** transacionar `conferir` + `addItem` juntos: a exceção da alçada precisa que o pedido criado seja gravado.
- `addItem` **não muda** (a diária de pátio e os testes continuam passando preço livre).
- Pendência da F05 (não fazer aqui): `storeItem`/`destroyItem` não conferem se o usuário pode ver aquela OS (`view()` confere em `:188-197`; `destroyItem` nem `ValoresFinanceiros` chama).

#### `SO/Services/ServiceOrderItemService.php` — `removeItem` (`:84-99`)

Antes de `$item->delete()`: `ServiceOrderEvent::record($serviceOrder, 'item_removido', ['item' => $item->name, 'tipo' => $item->tipo]);` (sem valores em R$; `ServiceOrderEvent` já está importado). Assim a correção da OS #11 e qualquer item removido deixam rastro na linha do tempo.

#### `SO/Models/ServiceOrderEvent.php` — `TIPOS` (`:26-96`)

Acrescentar `'valor_hora_fora_da_faixa' => 'Valor da hora fora da faixa'` e `'item_removido' => 'Item removido da OS'`.

#### `SO/Resources/views/partials/workflow.blade.php:20`

Acrescentar `'valor_hora_fora_da_faixa'` à lista `$eventosRestritos` (o Técnico não vê que houve exceção de preço). `item_removido` não leva valor e pode aparecer para todos.

#### `SO/Services/Alcada/AlcadaCatalog.php` — `temas()` (perto de `:49`)

```php
// B5: valor da hora lançado na OS abaixo do piso (menor entre a Ficha e o preço mínimo da DRE). O "valor" do pedido é o % abaixo do piso.
'hora_abaixo_minimo' => $t('Valor da hora abaixo do mínimo', 'gerente', 'percent', '—', 'Aprova a exceção', '—', 'Valor da hora do serviço na OS', true),
```
(18 caracteres, cabe nos 30 de `alcada_regras.tema`.) A linha de `alcada_regras` nasce sozinha no primeiro uso (`AlcadaRegra::ensureDefaultsOnce()`), inclusive nas oficinas já semeadas — **sem migration**. Tipo `gerente`: não usa limites na matriz.

#### `SO/Services/WorkshopSettings.php` — `DEFAULTS` (após `:67`)

```php
// B5: avisa quando o valor da hora lançado na OS passar de N vezes o valor da hora da Ficha.
'valor_hora_limite_multiplo' => 3,
```

#### `SO/Http/Controllers/FichaController.php` (`:37-68` regras e `:84-89` gravações)

- Regra: `'valor_hora_limite_multiplo' => 'nullable|numeric|min:1.1|max:100'`.
- Gravação (só quando o campo vier, para não zerar o padrão em formulários antigos/testes): `if (isset($dados['valor_hora_limite_multiplo'])) { WorkshopSettings::set('valor_hora_limite_multiplo', (float) $dados['valor_hora_limite_multiplo']); }`.

#### `SO/Resources/views/ficha/edit.blade.php:47` (cartão "Valores e prazos")

Depois do campo "Valor da hora de mão de obra (R$)":

```blade
<div class="flex flex-col gap-1"><label class="{{ $rotulo }}">@lang('service_order::app.valor_hora.ficha_multiplo')</label><input type="number" step="0.1" min="1.1" max="100" name="valor_hora_limite_multiplo" value="{{ $cfg['valor_hora_limite_multiplo'] }}" class="w-24 {{ $campo }}" /></div>
```

#### `SO/Routes/web.php`

`use Webkul\ServiceOrder\Http\Controllers\ValorHoraController;` e, **antes** do grupo `ServiceOrderController` (`:25`) — senão `Route::get('{id}', 'view')` (`:36`) captura "valor-hora":

```php
// B5: avalia o valor da hora digitado no item da OS (aviso). Fica antes de service-orders/{id}.
Route::get('service-orders/valor-hora', [ValorHoraController::class, 'avaliar'])->name('admin.service_orders.valor_hora');
```

#### `SO/Resources/views/view.blade.php` (arquivo muito disputado: edição mínima)

1. Antes da tabela de itens (`:145`): `@php $valorHoraSvc = app(\Webkul\ServiceOrder\Services\ValorHoraService::class); @endphp`.
2. Célula do valor (`:167`), dentro do bloco que já é `@if ($canSeeValues)`:

```blade
<td class="py-2">
    {{ core()->formatBasePrice($item->price) }}
    @if ($pilot && $item->tipo === 'Serviço' && $valorHoraSvc->acimaDoLimite((float) $item->price))
        <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900/30 dark:text-red-400" title="@lang('service_order::app.valor_hora.badge_titulo')">@lang('service_order::app.valor_hora.badge_acima', ['multiplo' => $valorHoraSvc->multiploTexto()])</span>
    @endif
</td>
```
3. Opções do catálogo (`:275`): acrescentar `data-horas="{{ $service->tempo_padrao_horas !== null ? (float) $service->tempo_padrao_horas : '' }}"` (coluna ausente → `null` → vazio; sem erro).
4. Horas (`:292`): `step="0.5"` → `step="any"` (um tempo padrão como 0,75 ou 1,33 não pode ser recusado pelo navegador).
5. Antes de `</form>` (`:319`, depois do botão): `@include('service_order::partials.valor-hora', ['serviceOrder' => $serviceOrder])`.
6. JS: em `toggleItemFields` (`:353-371`), no fim: `if (window.vmValorHora) { window.vmValorHora.aoMudarTipo(tipo); }`; em `fillServiceFromCatalog` (`:378-387`), no fim (depois de preencher descrição e preço): `if (window.vmValorHora) { window.vmValorHora.aoEscolherServico(option); }`.

#### `SO/Resources/lang/pt_BR/app.php` e `en/app.php`

Nova seção `valor_hora` (textos abaixo).

## Banco (migrations)

Uma migration, aditiva e idempotente, no pacote dono da tabela `services` (o `Artisan::call('migrate')` do provisionamento de oficinas e o `migrate-safe.php` carregam o caminho do `PartInventoryServiceProvider`, `loadMigrationsFrom`):

`PI/Database/Migrations/2026_11_18_081500_add_tempo_padrao_horas_to_services_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tempo padrão do serviço do catálogo, em horas. Opcional: as linhas antigas ficam
     * sem tempo e o fluxo de hoje (horas digitadas na OS) continua valendo.
     */
    public function up(): void
    {
        if (Schema::hasTable('services') && ! Schema::hasColumn('services', 'tempo_padrao_horas')) {
            Schema::table('services', function (Blueprint $table) {
                $table->decimal('tempo_padrao_horas', 6, 2)->nullable()->after('preco_padrao');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('services', 'tempo_padrao_horas')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropColumn('tempo_padrao_horas');
            });
        }
    }
};
```

- Nada em `quote_items`: o item já guarda `price` (valor da hora) e `hours` (tempo padrão da execução) — a B5 só muda de onde esses dois valores vêm. Não há `service_id` no item (o `sku` `SRV-0001` já carrega o código) e não há snapshot da Ficha (a auditoria fica no evento e no pedido de alçada).
- Nada novo em `alcada_regras` (a linha do tema nasce no primeiro uso) nem em `workshop_settings` (chave nova com padrão em `DEFAULTS`).
- Aplicar nos testes: `$env:DB_DATABASE='krayin_test'; php artisan migrate --path=packages/Webkul/PartInventory/src/Database/Migrations/2026_11_18_081500_add_tempo_padrao_horas_to_services_table.php` (DDL não é desfeito por `DatabaseTransactions`; rodar uma vez). **Não** rodar no `krayin_crm` sem ordem.
- **Tolerância à coluna ausente** (servidor que ainda não rodou o `migrate-safe.php`): `Service::temTempoPadrao()` (memoizado) governa: campo escondido nas telas do catálogo, coluna fora da lista/consulta, dado descartado em `store`/`update`; a view da OS lê `$service->tempo_padrao_horas` (atributo ausente → `null`). O resto da B5 só roda com o piloto ligado e checa as tabelas da mentoria (`ValorHoraService::infraestruturaDisponivel()`).

## ACL, menu, rotas e configurações

- **ACL: nenhuma chave nova.** A rota nova `admin.service_orders.valor_hora` fica **sem chave**, como a irmã `admin.service_orders.items.store` (`SO/Config/acl.php` não lista nenhuma das duas). A proteção real é `ValoresFinanceiros::authorize()` (403 para o Técnico, inclusive em JSON); com o piloto desligado o endpoint responde só `{"ativo": false}`. **Pedido à F05:** ao mapear `items.store`/`items.destroy` numa chave (hoje qualquer usuário autenticado passa pelo ACL), mapear junto `admin.service_orders.valor_hora`; lembrar que o mapa rota→chave usa a ÚLTIMA chave que cita a rota. Não mapeei aqui para não mudar o acesso de quem hoje lança item sem `service_orders.edit`.
- **Menu:** nenhuma mudança. O catálogo continua em "Serviços" (`servicos_catalogo`, `/admin/services-catalog`); o formulário de itens fica na tela da OS.
- **Rota nova:** `GET /admin/service-orders/valor-hora` (`admin.service_orders.valor_hora`, `ValorHoraController@avaliar`), parâmetros `valor` (obrigatório, número com ponto) e `os` (opcional).
- **Configurações novas:** `WorkshopSettings` `valor_hora_limite_multiplo` (padrão 3; Ficha de Enquadramento > Valores e prazos). Reaproveita `valor_hora_mao_obra` (Ficha) e `tecnico_margem_percentual` (cores do cronômetro). A matriz de alçadas ganha o tema `hora_abaixo_minimo` (pode ser desativado na tela "Matriz de alçadas": desativado, o valor abaixo do piso passa com aviso e evento, sem pedido).
- **Tipos de evento novos:** `valor_hora_fora_da_faixa` (restrito a quem vê valores) e `item_removido`.
- **Barreiras / indicadores:** nenhum código de barreira nem indicador novo. A B5 **não cria prazos**: o modo de teste ÷10 (F03) não se aplica; o prazo de 24 h do dono na fila (`AlcadaService::PRAZO_DONO_HORAS`) é existente e é decisão da F03 se entra no modo de teste.
- **Perfis (acúmulo incluso; A4):**

| Perfil | Vê o formulário/Ficha/avisos | Lança serviço | Valor abaixo do piso |
|---|---|---|---|
| Técnico | **Não** (sem `ver_valores_financeiros`): sem formulário, sem Ficha, sem aviso, sem marca na linha, sem o evento na linha do tempo; endpoint → 403 | Não (só peças, `addPart`, preço do servidor) | — |
| Consultor, Financeiro, Peças, Chefe (se tiver `ver_valores_financeiros`) | Sim | Sim | Pedido ao Gerente (nível 0 na alçada); o número do mínimo só aparece se tiver `financeiro_dre` |
| Gerente / Proprietário | Sim | Sim | Passam direto; fica o aviso e o evento |
| Quem acumula Técnico + outro perfil com valores | Segue `ValoresFinanceiros::can()` (regra da A4: soma, mas Técnico sem outro perfil com valores continua cego) | idem | idem |
| Mentor | Só leitura (F05) | Não | — |

## Textos da interface (pt-BR)

**`SO/Resources/lang/pt_BR/app.php` — seção `valor_hora`** (e o equivalente em inglês em `en/app.php`):

```php
'valor_hora' => [
    'dica' => 'Hora da Ficha: :ficha. Aviso acima de :limite.',
    'ficha_vazia' => 'A Ficha de Enquadramento está sem o valor da hora de mão de obra. Preencha em Configurações > Ficha de Enquadramento.',
    'aviso_acima' => 'Atenção: o valor da hora (:valor) é mais de :multiplo vezes o valor da hora da Ficha (:ficha). Confira se não foi um zero a mais.',
    'aviso_abaixo_com_minimo' => 'Atenção: o valor da hora (:valor) está abaixo do preço mínimo da hora (:minimo).',
    'aviso_abaixo_sem_minimo' => 'Atenção: o valor da hora (:valor) está abaixo do preço mínimo da hora.',
    'exige_aprovacao' => 'Esse lançamento precisa da aprovação do gerente.',
    'alcada_propria' => 'Você tem alçada para lançar; o valor fica registrado.',
    'lancado_acima' => 'Item lançado, mas confira: o valor da hora (:valor) é mais de :multiplo vezes o valor da hora da Ficha (:ficha).',
    'lancado_abaixo' => 'Item lançado com o valor da hora (:valor) abaixo do preço mínimo da hora, com alçada ou aprovação do gerente. Fica registrado.',
    'confirmar_acima' => 'O valor da hora (:valor) é mais de :multiplo vezes o valor da Ficha (:ficha). Lançar mesmo assim?',
    'sem_horas' => 'O serviço foi lançado sem horas. Informe as horas (tempo padrão): sem elas o cronômetro do técnico não tem referência.',
    'badge_acima' => 'acima de :multiplo× a Ficha',
    'badge_titulo' => 'O valor da hora passa do limite de aviso da Ficha de Enquadramento.',
    'alcada_descricao' => 'Serviço ":servico" (:horas h) a :valor por hora, :deficit abaixo de :piso (mínimo para lançar sem aprovação). Preço mínimo da DRE: :minimo. Valor da hora da Ficha: :ficha.',
    'ficha_multiplo' => 'Avisar quando a hora da OS passar de (vezes o valor da Ficha)',
],
```

Inglês (`en/app.php`): mesmas chaves, por exemplo `'aviso_acima' => 'Warning: the hourly rate (:valor) is more than :multiplo times the Ficha hourly rate (:ficha). Check for an extra zero.'`, `'exige_aprovacao' => 'This entry needs the manager\'s approval.'`, `'badge_acima' => 'above :multiplo× the Ficha'`, `'ficha_multiplo' => 'Warn when the order hourly rate exceeds (times the Ficha rate)'` etc. (manter as duas línguas).

**Mensagens já existentes que aparecem neste fluxo:** `service_order::app.view.item-added` ("Item adicionado com sucesso."); da alçada (`AlcadaService::authorize`): "Esta ação passa da sua alçada (Valor da hora abaixo do mínimo). O pedido foi enviado para aprovação do gerente; repita a ação depois da aprovação." e "Já existe um pedido de "Valor da hora abaixo do mínimo" aguardando o gerente."

**Catálogo (`PI/Resources/lang/pt_BR/app.php`):**
- `services.form.tempo_padrao` = "Tempo padrão (horas)"
- `services.form.tempo_padrao_dica` = "Quantas horas este serviço leva em média. Ao lançar o serviço na OS as horas entram sozinhas e viram o tempo padrão do cronômetro do técnico."
- `services.form.preco_padrao_dica` = "Com o piloto ligado, o valor da hora vem da Ficha de Enquadramento. Este preço só é usado quando a Ficha está sem o valor da hora ou com o piloto desligado."
- `services.index.datagrid.tempo` = "Tempo padrão"
- Em `en`: "Standard time (hours)", "How many hours this service takes on average…", "With the pilot on…", "Standard time".

**Alçada / evento (texto fixo em português, como o resto do catálogo):** tema "Valor da hora abaixo do mínimo" (gatilho "Valor da hora do serviço na OS", gerente: "Aprova a exceção"); evento "Valor da hora fora da faixa"; evento "Item removido da OS".

## Testes Pest a escrever

Arquivo novo: `tests/Feature/Workshop/ValorHoraTest.php` (usa `DatabaseTransactions` + `BuildsWorkshop`, como os demais). Nomes de funções/constantes globais com prefixo único (`valorHora…`, `VALOR_HORA_…`). Pré-requisito: migration aplicada no `krayin_test`.

Cenário base `valorHoraCenario()`: `enablePilot()`; Ficha `valor_hora_mao_obra=180`, `pct_impostos=10`, `lucro_desejado=10000`, `produtividade_alvo=80`, `horas_mes_tecnico=176`; um mecânico ativo (`makeMechanic`); serviço de catálogo `Service::create(['nome' => 'Troca de embreagem', 'preco_padrao' => 50, 'tempo_padrao_horas' => 2.5])`; OS em Orçamento; usuários `consultor` e `gerente` com `['service_orders','service_orders.view','service_orders.edit','ver_valores_financeiros','alcadas']`. **Preço mínimo da hora esperado com esses números = R$ 78,91** (10.000 ÷ 140,8 h ÷ 0,9; sem histórico de DRE o custo a cobrir é 0, igual ao teste `FinanceiroTest.php:337-357`). Para o Técnico, copiar `valuesScenario()` de `TecnicoValoresTest.php:15-41`.

| # | Teste | Linha "Teste:" do PDF / roteiro |
|---|---|---|
| 1 | **Serviço do catálogo entra com o valor da hora da Ficha e as horas do tempo padrão** (piloto ligado): `POST items.store` do consultor com só `tipo=Serviço` e `service_id` (sem preço, horas nem descrição) → redirect com `success`; sem `warning`; item com `price=180`, `hours=2.5`, `name='Troca de embreagem'`, `sku='SRV-000X'`, `line_total=450`; `grand_total` da OS = 450; sem pedido de alçada | PDF "serviço do catálogo entra com o valor da Ficha"; roteiro 24 |
| 2 | Valor e horas **digitados** prevalecem sobre os padrões (200 e 3 h → gravados como digitados; sem aviso) | roteiro 24 |
| 3 | **R$ 10.000 por hora grava, avisa e deixa registro**: `preco_unitario=10000`, `hours=6` → item gravado; `session('warning')` contém "mais de 3 vezes"; evento `valor_hora_fora_da_faixa` com `faixa='acima_limite'` e **sem R$ no `dados_json`**; nenhum `AlcadaPedido`; a tela da OS mostra a marca "acima de 3× a Ficha" na linha | PDF "R$ 10.000/h mostra aviso"; roteiro 25 |
| 4 | O múltiplo vem da Ficha: com `valor_hora_limite_multiplo=2` o limite é 360; 360 não avisa (igual não conta), 360,01 avisa | regra "vem da configuração" |
| 5 | **Abaixo do mínimo, o consultor não lança**: `preco_unitario=60` → `session('error')` com "alçada"; nenhum item; `AlcadaPedido` único com `tema='hora_abaixo_minimo'`, `nivel_necessario='gerente'`, `status='pendente'`, `valor≈23.96` (% abaixo do piso 78,91), `descricao` com o nome do serviço; evento `alcada_pedida` | "abaixo do mínimo exige alçada" |
| 6 | **Aprovado pelo gerente, o consultor repete e lança uma vez só**: `AlcadaService::decide(..., true)` → repete com 60 → item gravado, pedido `consumido`, `warning` "alçada ou aprovação"; nova repetição abre novo pedido; com a aprovação de 60 em aberto, tentar 50 (déficit maior) **não** passa (novo pedido) e voltar a 60 consome a aprovação | idem |
| 7 | O **gerente** lança abaixo do mínimo sem pedido, com `warning` e evento `faixa='abaixo_minimo'` | idem |
| 8 | **Ficha abaixo do mínimo da DRE** (Ficha=60 < 78,91): serviço do catálogo entra com 60 sem pedido nem aviso; valor 70 (acima da Ficha, abaixo do mínimo) passa; valor 59 (abaixo da Ficha e do mínimo) pede alçada | decisão de projeto 4 |
| 9 | OS de **garantia/retorno** (`forceFill(['tipo_os' => 'garantia'])`): valor 0 entra sem pedido; "acima de 3×" continua avisando | interação F12 |
| 10 | **Ficha sem valor da hora**: serviço do catálogo sem preço → erro de validação em `preco_unitario` (nada preenchido); com preço 10000 entra sem aviso de 3× | tolerância |
| 11 | **Piloto desligado = como hoje**: sem `enablePilot()`: serviço do catálogo sem preço → erro de validação; com preço 10000 grava sem `warning`, sem evento, sem pedido; a tela da OS não contém `valor-hora-config` nem a marca; horas ficam em branco | regra 2 |
| 12 | **Técnico**: a OS abre (`assertOk`) sem `valor-hora-config`, sem "Hora da Ficha", sem "180,00", sem a marca; `GET route('admin.service_orders.valor_hora', ['valor' => 100])` → 403; evento `valor_hora_fora_da_faixa` não aparece na linha do tempo dele | roteiro 5, 6 |
| 13 | **Endpoint**: consultor sem `financeiro_dre`: `valor=10000` → `faixa='acima_limite'`; `valor=100` → `ok`; `valor=60` → `abaixo_minimo`, `exige_aprovacao=true` e `mensagem` **sem** "78,91"; usuário com `financeiro_dre` → mensagem **com** "R$ 78,91"; valor inválido → 422; piloto desligado → `{ativo:false}` | roteiro 25 |
| 14 | Peça não passa pela regra: `tipo=Peça` com `part_id` grava como hoje, sem evento nem pedido | não quebrar |
| 15 | A diária de pátio (`RotinaService::lancarDiaria`) continua entrando com o valor da diária — rodar `RotinaTest.php:143-159` e acrescentar a asserção de que o `price` não virou o da Ficha | não quebrar |
| 16 | **Catálogo**: `POST admin.services.store` com `tempo_padrao_horas=1.5` grava e a lista (XHR) mostra "1,5 h"; `0` vira `null`; com `Service::definirTempoPadraoDisponivel(false)` o `POST` não dá erro, o dado é descartado e a tela de criar não tem o campo (restaurar com `afterEach(fn () => Service::definirTempoPadraoDisponivel(null))`) | tempo padrão |
| 17 | **Cronômetro** (roteiro 36): serviço do catálogo com 1 h lançado pela rota → `OperationService::cor` = verde aos 59 min, amarelo aos 72 min (limite incluso), vermelho aos 73 min; `padraoSeconds = 3600` | roteiro 36 |
| 18 | `DELETE items.destroy` registra o evento `item_removido` (nome e tipo, sem valores) | correção da OS #11 |
| 19 | Fila de alçadas (gerente) mostra "Valor da hora abaixo do mínimo" e "24,0%" | alçada |
| 20 | Ficha: `PUT admin.ficha.update` com `valor_hora_limite_multiplo=2.5` grava 2.5; valor `1` → erro; formulário antigo sem o campo mantém 3 | configuração |
| 21 | Tema desativado na matriz (`AlcadaRegra::whereKey('hora_abaixo_minimo')->update(['ativo' => false])`): consultor com 60 entra com aviso e evento, sem pedido | matriz |

Depois: `php artisan test --filter=ValorHora`, `--filter=Workshop`, e `php vendor/bin/pint` nos arquivos tocados (com `DB_DATABASE=krayin_test`).

## Riscos e interações com outras frentes

- **Arquivos disputados (conflito de merge):** `SO/Resources/views/view.blade.php`, `SO/Http/Controllers/ServiceOrderController.php`, `SO/Services/Alcada/AlcadaCatalog.php`, `SO/Models/ServiceOrderEvent.php` (`TIPOS`), `SO/Resources/views/partials/workflow.blade.php` (`$eventosRestritos`), `SO/Services/WorkshopSettings.php`, `SO/Http/Controllers/FichaController.php` + `ficha/edit.blade.php` (F03, F14), `SO/Routes/web.php`, `SO/Resources/lang/{pt_BR,en}/app.php`, `SO/Services/ServiceOrderItemService.php` (F10/F11). Mantive cada edição pequena e a UI nova numa parcial própria.
- **F05 (menu/permissões):** mapear no ACL `admin.service_orders.valor_hora` junto de `items.store`/`items.destroy`; `storeItem` e `destroyItem` não conferem a visibilidade da OS (`view()` confere); o catálogo "Serviços" mostra `preco_padrao` (dinheiro) e não pode estar visível ao Técnico. O Técnico/Mentor não podem ver `data-ficha` etc.: a parcial só sai dentro do bloco `$canSeeValues`.
- **F14 (remuneração):** `DreService::parametros()['comissoes']` entra no divisor do preço mínimo da hora (`DreService.php:289`). Se a F14 zerar comissões no modelo "Bônus", o mínimo muda sozinho (consistente); não reimplementar a conta.
- **F12 (retorno/garantia):** `ValorHoraService::dispensaMinimo()` usa `tipo_os` `retorno`/`garantia`. Se a F12 criar outro marcador de "sem cobrança" (coluna), ajustar só esse método.
- **F13 (diária de pátio):** a linha "Diária de pátio" é `tipo=Serviço` com `hours=1` e entra em `operations()`; ela **não** deve passar por `storeItem` nem pela regra da hora (hoje entra por `addItem` direto). Observação para a F13: essa linha também aparece como "operação" pendente do técnico.
- **F10/F11:** `removeItem` ganha o evento `item_removido` (vale para peças também); `AlcadaCatalog` é compartilhado (só acrescentei um tema, não mexi em `preco_abaixo_minimo` nem em `ajuste_estoque`).
- **F03:** `ValorHoraService::valorFicha()` é o leitor canônico do valor da hora (a trava "valor da hora obrigatório para ligar o piloto" pode usá-lo). Sem a trava, piloto ligado com Ficha vazia continua funcionando: não preenche nada, sem comparação com a Ficha.
- **F00 (oficinas/migrations por oficina):** a migration `…081500…` precisa rodar no banco de **cada** oficina; é idempotente. A F08 não depende de tabelas novas além das de 17/11.
- **Alçada com `valor` em % (decisão 6):** quem for ler `alcada_pedidos` para outros relatórios deve saber que, neste tema, `valor` é o % abaixo do piso (a fila já formata como %, `unidade='percent'`). A aprovação cobre a mesma tentativa ou uma menos agressiva; se a DRE mudar entre o pedido e a repetição, o déficit pode mudar de centésimos e pedir nova aprovação (aceito).
- **Custo do preço mínimo:** `precoMinimoHora` = 3 cálculos de DRE. Só roda quando o valor digitado é menor que a Ficha (rota JSON, uma vez por valor, com espera de 350 ms) e no servidor ao gravar. Lançar a hora da Ficha ou acima nunca o calcula. Sem cache entre requisições (o cache do Laravel não é separado por oficina).
- **Segurança de dados:** o número do mínimo (derivado de custos e lucro desejado) não sai do servidor para quem não tem `financeiro_dre`; o JSON devolve só o texto. O evento `valor_hora_fora_da_faixa` não leva R$ e fica fora da linha do tempo do Técnico. Alertas (sino/push/SMS) não são criados por esta frente; o único alerta é o `alcada_pedido` ao gerente, que já não leva valores.
- **UX conhecida:** depois de um pedido de alçada o consultor precisa lançar o item de novo (o formulário não guarda o que foi digitado), igual à precificação de peça hoje; o `session('error')` explica.
- **Observação fora do escopo (ver Decisões em aberto, item 4):** cancelar uma OS **já faturada** não a tira da DRE/Painel/relatório diário.
- **Documentação (RETOMAR-TRABALHO.md — não alterei):** registrar a chave `valor_hora_limite_multiplo`, o tema `hora_abaixo_minimo`, a rota `admin.service_orders.valor_hora`, os eventos `valor_hora_fora_da_faixa` e `item_removido`, a coluna `services.tempo_padrao_horas` e que o zip precisa levar também os arquivos de `packages/Webkul/PartInventory/src` (a mudança não fica só em `ServiceOrder`).
- **JS:** o aviso depende de `fetch` e de `window.vmMoney` (já carregado pela máscara de dinheiro); se o `fetch` falhar só o aviso antecipado some — o servidor continua decidindo e avisando depois de gravar. Sem `try/catch` engolindo erro de regra no PHP.

## Decisões em aberto

1. **Quem aprova o valor da hora abaixo do mínimo?** Recomendação: **Gerente** (com "Encaminhar ao dono"), como já é a peça abaixo do mínimo. Alternativa: sempre o Proprietário (como `valor_hora_margem`, que é mudança da própria Ficha).
2. **Se a hora da Ficha já é menor que o preço mínimo da DRE**, o serviço lançado com a hora da Ficha (ou mais) deve passar sem alçada? Recomendação: **sim** (decisão de projeto 4); a análise "hora cobrada é 74% do mínimo" já aparece na DRE/DRE-06 e deve ser tratada alterando a Ficha. Alternativa literal do PDF: pedir alçada em todo valor abaixo do mínimo, inclusive o da Ficha.
3. **Mostrar o número do preço mínimo da hora ao Consultor?** Recomendação: **não** (só quem tem a permissão da DRE vê o número; o Consultor vê "abaixo do preço mínimo da hora" e o Gerente vê o valor no pedido). Alternativa: mostrar a todos que veem valores.
4. **Cancelar uma OS já faturada deve tirá-la da DRE, do Painel do Dono e do relatório diário?** Hoje não tira (as consultas só olham `faturado_em`). Recomendação: **sim**, em tarefa pequena e separada (excluir `Cancelada` em `DreService.php:76,236,361`, `PainelDonoService.php:214,351`, `BonusService.php:430`, `FinanceiroService.php:327`; indicadores e comissões já excluem). Isto é o que faz "cancelar a OS #11" realmente limpar o relatório diário quando ela já passou do Faturamento.
5. **Exigir horas (> 0) em todo serviço com o piloto ligado?** O PDF só diz que as horas vêm do catálogo. Recomendação: **só aviso por enquanto** (já previsto: "O serviço foi lançado sem horas…"); transformar em bloqueio depois de 2 semanas se o aviso aparecer muito, porque sem horas o cronômetro e as barreiras de operação não têm referência.

## Passo a passo para o usuário

**Depois do deploy (nesta frente):**
1. Rodar o `public/migrate-safe.php` (cria o campo "Tempo padrão" no catálogo; em oficinas com banco próprio, rodar também no banco de cada oficina — ver F00). Se não rodar, tudo continua funcionando; só não aparece o campo de tempo padrão.
2. **Menu Serviços:** abrir cada serviço (ex.: "Troca de embreagem") > preencher **Tempo padrão (horas)** (ex.: 2,5) > Salvar.
3. **Configurações > Ficha de Enquadramento > Valores e prazos:** conferir o **Valor da hora de mão de obra** e, se quiser, o **limite de aviso** (padrão 3 vezes). Para o aviso de "abaixo do mínimo" funcionar, preencher também impostos, comissões e lucro desejado (parâmetros da DRE); sem eles o preço mínimo da hora não é calculado e só vale o aviso de 3 vezes.
4. **Configurações > Matriz de alçadas:** conferir que "Valor da hora abaixo do mínimo" está ativo.

**Para testar na Oficina Teste (roteiro 24 e 25), com o piloto ligado:**
- Abrir uma OS em Orçamento > Adicionar Item > Tipo **Serviço** > escolher o serviço do catálogo: **Valor Unitário = valor da Ficha** e **Horas = tempo padrão** entram sozinhos; abaixo do botão aparece "Hora da Ficha: …". Adicionar: a linha entra com horas × valor da Ficha.
- Digitar **R$ 10.000,00** no valor: aparece o aviso vermelho; ao clicar em Adicionar, o sistema pergunta "Lançar mesmo assim?"; depois de lançar aparece o aviso amarelo no topo e a linha fica marcada "acima de 3× a Ficha".
- Entrar como **Consultor** e digitar um valor abaixo do mínimo: aviso âmbar; ao Adicionar vem "Esta ação passa da sua alçada… O pedido foi enviado para aprovação do gerente". O **Gerente** aprova em *Fila de alçadas*; o Consultor repete o lançamento e o item entra (a aprovação vale uma vez).
- Entrar como **Técnico**: não aparece formulário, Ficha nem aviso.

**Corrigir ou cancelar a OS #11 (6 h × R$ 10.000) — é dado do servidor, feito pela tela:**
1. Abrir `/admin/service-orders/11` e olhar a **etapa atual** na barra de etapas no alto.
2. **Se NÃO passou do Faturamento** (Cadastro … Veículo em Teste): clicar em **Cancelar OS** (botão ao lado das etapas) e confirmar. Cancelada, ela some da DRE, do Painel do Dono e do relatório diário (nunca foi faturada).
3. **Se está em Faturamento ou Pós-Venda** (já tem data de faturamento): **não cancele** — a DRE e o Painel continuam contando OS faturada cancelada. Corrija: na tabela de Itens clique no **×** da linha de 6 h × R$ 10.000 (fica registrado o evento "Item removido da OS") e lance de novo com **Adicionar Item**, escolhendo o serviço do catálogo (valor da Ficha e tempo padrão entram sozinhos) ou digitando horas e valor corretos. O total da OS é recalculado.
4. **Se está Concluída:** a tela trava itens e status de propósito. Peça ao suporte da Volmaster para ajustar o preço e as horas do item da OS #11 direto no banco do servidor e recalcular o total da OS, registrando o motivo. Não há (nem deve haver) botão para isso.
5. Conferir depois: *Painel do Dono* e *DRE* do mês (a receita de mão de obra não deve mais incluir os R$ 60.000) e o relatório diário do dia. Lembrar que a receita de mão de obra do mês também alimenta o cálculo do preço mínimo da hora (participação da mão de obra), então a OS #11 distorcia esse número.

**Ordem de implementação sugerida (para o desenvolvedor):** (1) migration + `Service` + telas/lista/lang do catálogo; (2) `WorkshopSettings` + Ficha; (3) `AlcadaCatalog` + `TIPOS` + `$eventosRestritos`; (4) `ValorHoraService` + `ValorHoraController` + rota; (5) `storeItem` + `removeItem`; (6) parcial + edições mínimas de `view.blade.php`; (7) lang pt_BR/en; (8) testes; (9) `pint` e `--filter=Workshop`.
