<?php

namespace Webkul\PartInventory\Repositories;

use Webkul\Core\Eloquent\Repository;

class PartCategoryRepository extends Repository
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
        return 'Webkul\PartInventory\Contracts\PartCategory';
    }
}
