<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceOrderBoleto extends Model
{
    const STATUS_PENDENTE = 'Pendente';

    const STATUS_PAGO = 'Pago';

    const STATUS_CANCELADO = 'Cancelado';

    protected $table = 'service_order_boletos';

    protected $fillable = [
        'quote_id',
        'valor',
        'vencimento',
        'status',
        'link_pagamento',
        'gateway',
        'asaas_payment_id',
        'billing_type',
    ];

    protected $casts = [
        'vencimento' => 'date',
        'valor' => 'decimal:2',
    ];

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class, 'quote_id')->withoutGlobalScopes();
    }
}
