<?php

namespace Webkul\Admin\Helpers\Reporting;

use Webkul\ServiceOrder\Models\ServiceOrder as ServiceOrderModel;

class ServiceOrder extends AbstractReporting
{
    /**
     * Retrieves total concluded Service Order value and their progress.
     */
    public function getTotalConcludedServiceOrderValueProgress(): array
    {
        return [
            'previous' => $previous = $this->getTotalConcludedServiceOrderValue($this->lastStartDate, $this->lastEndDate),
            'current' => $current = $this->getTotalConcludedServiceOrderValue($this->startDate, $this->endDate),
            'formatted_total' => core()->formatBasePrice($current),
            'progress' => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves total concluded Service Order value for a date range.
     */
    public function getTotalConcludedServiceOrderValue($startDate, $endDate): float
    {
        return (float) ServiceOrderModel::where('workshop_status', ServiceOrderModel::STATUS_CONCLUIDA)
            ->whereBetween('data_conclusao', [$startDate, $endDate])
            ->sum('grand_total');
    }

    /**
     * Retrieves total cancelled Service Order value and their progress.
     */
    public function getTotalCancelledServiceOrderValueProgress(): array
    {
        return [
            'previous' => $previous = $this->getTotalCancelledServiceOrderValue($this->lastStartDate, $this->lastEndDate),
            'current' => $current = $this->getTotalCancelledServiceOrderValue($this->startDate, $this->endDate),
            'formatted_total' => core()->formatBasePrice($current),
            'progress' => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves total cancelled Service Order value for a date range.
     *
     * There is no dedicated "cancelled at" column, so `updated_at` is used
     * as the best available approximation of when the cancellation happened.
     */
    public function getTotalCancelledServiceOrderValue($startDate, $endDate): float
    {
        return (float) ServiceOrderModel::where('workshop_status', ServiceOrderModel::STATUS_CANCELADA)
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->sum('grand_total');
    }
}
