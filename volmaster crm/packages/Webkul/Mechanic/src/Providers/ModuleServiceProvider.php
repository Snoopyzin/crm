<?php

namespace Webkul\Mechanic\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;
use Webkul\Mechanic\Models\Mechanic;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * The models to be used by this module.
     *
     * @var array
     */
    protected $models = [
        Mechanic::class,
    ];
}
