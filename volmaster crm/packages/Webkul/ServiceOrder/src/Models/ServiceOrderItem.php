<?php

namespace Webkul\ServiceOrder\Models;

use Webkul\Mechanic\Models\MechanicProxy;
use Webkul\PartInventory\Models\PartProxy;
use Webkul\Quote\Models\QuoteItem;

class ServiceOrderItem extends QuoteItem
{
    const TIPO_PECA = 'Peça';

    const TIPO_SERVICO = 'Serviço';

    const SITUACAO_DISPONIVEL = 'Disponível';

    const SITUACAO_PENDENTE_DE_COMPRA = 'Pendente de Compra';

    /**
     * The two semaphore lights the mechanic can raise on an Orçamento line:
     * orange for "worth keeping an eye on", red for "replace/do it now".
     */
    const ALERTA_ATENCAO = 'Atenção';

    const ALERTA_CRITICO = 'Crítico';

    protected $table = 'quote_items';

    protected $fillable = [
        'sku',
        'name',
        'quantity',
        'price',
        'coupon_code',
        'discount_percent',
        'discount_amount',
        'tax_percent',
        'tax_amount',
        'total',
        'product_id',
        'quote_id',
        'tipo',
        'hours',
        'funcionario_id',
        'part_id',
        'situacao',
        'alerta',
        'alerta_observacao',
    ];

    protected $casts = [
        'precificada_em' => 'datetime',
        'comprada_em' => 'datetime',
        'prazo_entrega_em' => 'datetime',
        'entregue_tecnico_em' => 'datetime',
        'adicional' => 'boolean',
    ];

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class, 'quote_id');
    }

    public function part()
    {
        return $this->belongsTo(PartProxy::modelClass());
    }

    public function funcionario()
    {
        return $this->belongsTo(MechanicProxy::modelClass(), 'funcionario_id');
    }

    /**
     * Quantity relevant for pricing -- hours for a Serviço line, quantidade for a Peça line.
     */
    public function getEffectiveQuantityAttribute(): float
    {
        return $this->tipo === self::TIPO_SERVICO
            ? (float) ($this->hours ?? $this->quantity)
            : (float) $this->quantity;
    }

    /**
     * Code for a catalog service (the catalog has no code column of its own).
     */
    public static function serviceCode(int $serviceId): string
    {
        return 'SRV-'.str_pad((string) $serviceId, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Code shown on the printed OS: stored sku, or the part's current code
     * for lines added before codes were stored.
     */
    public function getCodigoAttribute(): ?string
    {
        return $this->sku ?: $this->part?->codigo;
    }

    /**
     * Price x quantity, before any discount.
     */
    public function getGrossTotalAttribute(): float
    {
        return round($this->price * $this->effective_quantity, 2);
    }

    /**
     * How much this line was discounted (R$) -- the per-item share of the
     * order's discount, which is what commissions must be based on.
     */
    public function getDiscountTotalAttribute(): float
    {
        return round($this->gross_total - $this->line_total, 2);
    }

    public function getLineTotalAttribute(): float
    {
        $subtotal = $this->price * $this->effective_quantity;

        $afterPercent = $subtotal - ($subtotal * ($this->discount_percent ?? 0) / 100);

        return max(0, $afterPercent - ($this->discount_amount ?? 0));
    }
}
