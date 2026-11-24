<?php

namespace Webkul\ServiceOrder\Services;

use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;

/**
 * Condições que as regras de barreira usam para decidir se abrem (condicao_inicio)
 * ou se fecham (condicao_fim) com um determinado evento. Uma condição vazia ou
 * desconhecida sempre passa.
 */
class BarrierConditions
{
    public function passes(?string $condition, ServiceOrder $serviceOrder, ServiceOrderEvent $event): bool
    {
        return match ($condition) {
            null, '' => true,
            // A saída só é crítica quando o veículo saiu sem nota fiscal.
            'sem_nf' => ! empty($event->dados_json['sem_nf']),
            // A OS tem peças lançadas.
            'tem_pecas' => $this->parts($serviceOrder)->isNotEmpty(),
            // Alguma peça precisa ser comprada (fornecedor) e ainda não foi.
            'precisa_compra' => $this->partsToBuy($serviceOrder)->isNotEmpty(),
            'todas_compradas' => $this->partsToBuy($serviceOrder)->whereNull('comprada_em')->isEmpty(),
            'todas_precificadas' => $this->parts($serviceOrder)->whereNull('precificada_em')->isEmpty(),
            // Etapa anterior ao Veículo em Teste (prazo prometido ainda em risco).
            'antes_do_teste' => $this->beforeTest($serviceOrder),
            default => true,
        };
    }

    protected function parts(ServiceOrder $serviceOrder)
    {
        return ServiceOrderItem::where('quote_id', $serviceOrder->id)
            ->where('tipo', ServiceOrderItem::TIPO_PECA)
            ->get();
    }

    protected function partsToBuy(ServiceOrder $serviceOrder)
    {
        return $this->parts($serviceOrder)->filter(
            fn ($item) => $item->origem_preco === 'fornecedor' || $item->situacao === ServiceOrderItem::SITUACAO_PENDENTE_DE_COMPRA
        );
    }

    protected function beforeTest(ServiceOrder $serviceOrder): bool
    {
        $flow = ServiceOrder::stagesFlow();
        $current = array_search($serviceOrder->workshop_status, $flow, true);
        $test = array_search(ServiceOrder::STAGE_VEICULO_TESTE, $flow, true);

        return $current !== false && $current < $test;
    }
}
