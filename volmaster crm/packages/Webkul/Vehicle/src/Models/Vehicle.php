<?php

namespace Webkul\Vehicle\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Vehicle\Contracts\Vehicle as VehicleContract;

class Vehicle extends Model implements VehicleContract
{
    protected $table = 'vehicles';

    protected $fillable = [
        'person_id',
        'marca',
        'modelo',
        'placa',
        'ano',
        'chassi',
        'km',
        'cor',
        'frota',
        'tipo',
        'combustivel',
        'status',
        'photo_path',
    ];

    /**
     * Get the owner (client) of the vehicle.
     */
    public function person()
    {
        return $this->belongsTo(PersonProxy::modelClass());
    }
}
