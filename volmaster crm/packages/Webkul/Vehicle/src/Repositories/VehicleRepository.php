<?php

namespace Webkul\Vehicle\Repositories;

use Webkul\Core\Eloquent\Repository;

class VehicleRepository extends Repository
{
    /**
     * Specify fields that are searchable.
     *
     * @var array
     */
    protected $fieldSearchable = [
        'marca',
        'modelo',
        'placa',
    ];

    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return 'Webkul\Vehicle\Contracts\Vehicle';
    }
}
