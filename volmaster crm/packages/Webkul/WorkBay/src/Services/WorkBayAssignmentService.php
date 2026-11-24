<?php

namespace Webkul\WorkBay\Services;

use Illuminate\Validation\ValidationException;
use Webkul\WorkBay\Models\WorkBayAssignment;

class WorkBayAssignmentService
{
    /**
     * Create a new assignment for a bay, rejecting it if it overlaps with
     * another active assignment already scheduled for that bay.
     */
    public function assign(array $data): WorkBayAssignment
    {
        if ($this->hasConflict($data['work_bay_id'], $data['starts_at'], $data['ends_at'] ?? null)) {
            throw ValidationException::withMessages([
                'starts_at' => [trans('workbay::app.errors.conflict')],
            ]);
        }

        return WorkBayAssignment::create([
            'work_bay_id' => $data['work_bay_id'],
            'quote_id' => $data['quote_id'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'status' => WorkBayAssignment::STATUS_AGENDADA,
        ]);
    }

    /**
     * Whether an active assignment already occupies this bay during the given window.
     */
    public function hasConflict(int $workBayId, string $startsAt, ?string $endsAt, ?int $excludeAssignmentId = null): bool
    {
        $query = WorkBayAssignment::where('work_bay_id', $workBayId)
            ->whereNotIn('status', [WorkBayAssignment::STATUS_CONCLUIDA, WorkBayAssignment::STATUS_CANCELADA])
            ->where('starts_at', '<', $endsAt ?? '9999-12-31')
            ->where(function ($query) use ($startsAt) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $startsAt);
            });

        if ($excludeAssignmentId) {
            $query->where('id', '!=', $excludeAssignmentId);
        }

        return $query->exists();
    }
}
