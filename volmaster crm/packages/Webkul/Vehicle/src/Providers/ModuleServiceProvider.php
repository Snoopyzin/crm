<?php

namespace Webkul\Vehicle\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;
use Webkul\Vehicle\Models\Vehicle;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * The models to be used by this module.
     *
     * @var array
     */
    protected $models = [
        Vehicle::class,
    ];
}
