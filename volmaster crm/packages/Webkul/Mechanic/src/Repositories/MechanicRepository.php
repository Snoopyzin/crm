<?php

namespace Webkul\Mechanic\Repositories;

use Illuminate\Validation\ValidationException;
use Webkul\Core\Eloquent\Repository;
use Webkul\Mechanic\Models\Mechanic;

class MechanicRepository extends Repository
{
    /**
     * Specify fields that are searchable.
     *
     * @var array
     */
    protected $fieldSearchable = [
        'nome',
        'especialidade',
    ];

    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return 'Webkul\Mechanic\Contracts\Mechanic';
    }

    /**
     * Update a mechanic, refusing to change a legendary mechanic's status away from "Lenda".
     */
    public function update(array $attributes, $id)
    {
        $mechanic = $this->find($id);

        if (
            $mechanic
            && $mechanic->status === Mechanic::LEGEND_STATUS
            && ($attributes['status'] ?? Mechanic::LEGEND_STATUS) !== Mechanic::LEGEND_STATUS
        ) {
            throw ValidationException::withMessages([
                'status' => [trans('mechanic::app.errors.legend-status-locked')],
            ]);
        }

        return parent::update($attributes, $id);
    }

    /**
     * Delete a mechanic, refusing to delete a legendary mechanic.
     */
    public function delete($id)
    {
        $mechanic = $this->find($id);

        if ($mechanic && $mechanic->status === Mechanic::LEGEND_STATUS) {
            throw ValidationException::withMessages([
                'status' => [trans('mechanic::app.errors.legend-delete-locked')],
            ]);
        }

        return parent::delete($id);
    }
}
