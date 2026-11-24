<?php

namespace Webkul\WorkBay\Repositories;

use Webkul\Core\Eloquent\Repository;

class WorkBayRepository extends Repository
{
    /**
     * Specify fields that are searchable.
     *
     * @var array
     */
    protected $fieldSearchable = [
        'name',
    ];

    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return 'Webkul\WorkBay\Contracts\WorkBay';
    }
}
