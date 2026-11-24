<?php

namespace Webkul\RemoteService\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\User\Models\GroupProxy;

class RemoteServiceType extends Model
{
    protected $table = 'remote_service_types';

    protected $fillable = [
        'nome',
        'group_id',
    ];

    public function group()
    {
        return $this->belongsTo(GroupProxy::modelClass());
    }
}
