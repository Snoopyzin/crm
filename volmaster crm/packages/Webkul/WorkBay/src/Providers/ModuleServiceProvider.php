<?php

namespace Webkul\WorkBay\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;
use Webkul\WorkBay\Models\WorkBay;
use Webkul\WorkBay\Models\WorkBayAssignment;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * The models to be used by this module.
     *
     * @var array
     */
    protected $models = [
        WorkBay::class,
        WorkBayAssignment::class,
    ];
}
