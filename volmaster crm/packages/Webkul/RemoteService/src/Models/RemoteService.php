<?php

namespace Webkul\RemoteService\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Contact\Models\PersonProxy;
use Webkul\RemoteService\Contracts\RemoteService as RemoteServiceContract;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\User\Models\GroupProxy;
use Webkul\User\Models\UserProxy;
use Webkul\Vehicle\Models\VehicleProxy;

class RemoteService extends Model implements RemoteServiceContract
{
    const STATUS_PENDENTE = 'Pendente';

    const STATUS_ABERTO = 'Aberto';

    const STATUS_FINALIZADO = 'Finalizado';

    protected $table = 'remote_services';

    protected $fillable = [
        'person_id',
        'cliente_nome_livre',
        'nome_oficina',
        'whatsapp',
        'fila_token',
        'vehicle_id',
        'veiculo_descricao_livre',
        'teamviewer_id',
        'teamviewer_password',
        'tipo_atendimento',
        'grupo_id',
        'descricao_problema',
        'descricao_solucao',
        'status',
        'valor',
        'pago',
        'faturado',
        'forma_pagamento',
        'consultor_id',
        'tecnico_id',
        'service_order_id',
        'iniciado_em',
        'finalizado_em',
    ];

    protected $casts = [
        'pago' => 'boolean',
        'faturado' => 'boolean',
        'valor' => 'decimal:2',
        'iniciado_em' => 'datetime',
        'finalizado_em' => 'datetime',
        'tipo_atendimento' => 'array',
    ];

    /**
     * "tipo_atendimento" as a comma-separated string, for display.
     */
    public function getTipoAtendimentoLabelAttribute(): string
    {
        $tipos = $this->tipo_atendimento;

        if (empty($tipos)) {
            return '-';
        }

        return implode(', ', $tipos);
    }

    /**
     * Human-readable elapsed time. Falls back to "created_at" as the start
     * reference when the technician never explicitly clicked "Iniciar", and
     * to "now" as the end reference while the occurrence is still open --
     * so it always reflects the time actually spent, not just recorded time.
     */
    public function getDuracaoFormatadaAttribute(): ?string
    {
        $inicio = $this->iniciado_em ?? $this->created_at;

        $fim = $this->finalizado_em ?? ($this->status === self::STATUS_FINALIZADO ? $this->updated_at : now());

        if (! $inicio || ! $fim) {
            return null;
        }

        $minutos = (int) floor($inicio->diffInMinutes($fim));

        if ($minutos < 60) {
            return $minutos.' min';
        }

        $horas = intdiv($minutos, 60);
        $restoMinutos = $minutos % 60;

        return $restoMinutos > 0 ? "{$horas}h {$restoMinutos}min" : "{$horas}h";
    }

    public function person()
    {
        return $this->belongsTo(PersonProxy::modelClass());
    }

    public function vehicle()
    {
        return $this->belongsTo(VehicleProxy::modelClass());
    }

    public function consultor()
    {
        return $this->belongsTo(UserProxy::modelClass(), 'consultor_id');
    }

    public function tecnico()
    {
        return $this->belongsTo(UserProxy::modelClass(), 'tecnico_id');
    }

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    public function grupo()
    {
        return $this->belongsTo(GroupProxy::modelClass(), 'grupo_id');
    }
}
