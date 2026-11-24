<?php

namespace Webkul\WorkBay\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\WorkBay\Contracts\WorkBayAssignment as WorkBayAssignmentContract;

class WorkBayAssignment extends Model implements WorkBayAssignmentContract
{
    const STATUS_AGENDADA = 'Agendada';

    const STATUS_EM_ANDAMENTO = 'Em Andamento';

    const STATUS_CONCLUIDA = 'Concluída';

    const STATUS_CANCELADA = 'Cancelada';

    protected $table = 'work_bay_assignments';

    protected $fillable = [
        'work_bay_id',
        'quote_id',
        'starts_at',
        'ends_at',
        'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function workBay()
    {
        return $this->belongsTo(WorkBayProxy::modelClass());
    }

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class, 'quote_id')->withoutGlobalScopes();
    }
}
