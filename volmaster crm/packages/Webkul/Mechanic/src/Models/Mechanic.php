<?php

namespace Webkul\Mechanic\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Mechanic\Contracts\Mechanic as MechanicContract;

class Mechanic extends Model implements MechanicContract
{
    protected $table = 'mechanics';

    protected $fillable = [
        'nome',
        'especialidade',
        'years_of_experience',
        'avaliacao',
        'foto_path',
        'status',
    ];

    const LEGEND_STATUS = 'Lenda';
}
