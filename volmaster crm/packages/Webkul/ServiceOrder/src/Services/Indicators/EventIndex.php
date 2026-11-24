<?php

namespace Webkul\ServiceOrder\Services\Indicators;

use Illuminate\Support\Collection;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;

/**
 * Eventos de um conjunto de OS carregados de uma vez, para os indicadores
 * consultarem "primeiro/último evento X da OS Y" sem uma consulta por OS.
 */
class EventIndex
{
    /** @var array<int, array<string, Collection<int, ServiceOrderEvent>>> */
    protected array $byOs = [];

    /**
     * @param  int[]  $osIds
     * @param  string[]|null  $types  null = todos os tipos
     */
    public function __construct(array $osIds, ?array $types = null)
    {
        if (! $osIds) {
            return;
        }

        $query = ServiceOrderEvent::query()->whereIn('service_order_id', $osIds)->orderBy('ocorrido_em')->orderBy('id');

        if ($types) {
            $query->whereIn('tipo_evento', $types);
        }

        foreach ($query->get() as $event) {
            $this->byOs[$event->service_order_id][$event->tipo_evento] ??= collect();
            $this->byOs[$event->service_order_id][$event->tipo_evento]->push($event);
        }
    }

    /**
     * @return Collection<int, ServiceOrderEvent>
     */
    public function all(int $osId, string $type): Collection
    {
        return $this->byOs[$osId][$type] ?? collect();
    }

    public function first(int $osId, string $type): ?ServiceOrderEvent
    {
        return $this->all($osId, $type)->first();
    }

    public function last(int $osId, string $type): ?ServiceOrderEvent
    {
        return $this->all($osId, $type)->last();
    }

    public function count(int $osId, string $type): int
    {
        return $this->all($osId, $type)->count();
    }

    public function has(int $osId, string $type): bool
    {
        return $this->all($osId, $type)->isNotEmpty();
    }
}
