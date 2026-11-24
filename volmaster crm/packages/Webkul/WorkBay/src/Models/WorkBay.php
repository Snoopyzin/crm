<?php

namespace Webkul\WorkBay\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\WorkBay\Contracts\WorkBay as WorkBayContract;

class WorkBay extends Model implements WorkBayContract
{
    protected $table = 'work_bays';

    protected $fillable = [
        'name',
        'capacity',
        'status',
    ];

    public function assignments()
    {
        return $this->hasMany(WorkBayAssignmentProxy::modelClass());
    }

    /**
     * The bay's current active assignment, if any.
     */
    public function currentAssignment()
    {
        return $this->assignments()
            ->whereNotIn('status', ['Concluída', 'Cancelada'])
            ->latest('starts_at')
            ->first();
    }
}
