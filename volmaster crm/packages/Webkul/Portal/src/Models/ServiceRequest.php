<?php

namespace Webkul\Portal\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Contact\Models\PersonProxy;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\Vehicle\Models\VehicleProxy;

class ServiceRequest extends Model
{
    const STATUS_PENDENTE = 'Pendente';

    const STATUS_EM_ANALISE = 'Em Análise';

    const STATUS_CONVERTIDA = 'Convertida';

    const STATUS_RECUSADA = 'Recusada';

    protected $table = 'service_requests';

    protected $fillable = [
        'person_id',
        'client_user_id',
        'vehicle_id',
        'veiculo_descricao_livre',
        'descricao',
        'data_preferencia',
        'status',
        'service_order_id',
    ];

    protected $casts = [
        'data_preferencia' => 'date',
    ];

    public function person()
    {
        return $this->belongsTo(PersonProxy::modelClass());
    }

    public function clientUser()
    {
        return $this->belongsTo(ClientUser::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(VehicleProxy::modelClass());
    }

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }
}
