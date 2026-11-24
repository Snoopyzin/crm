<?php

namespace Webkul\Tenant\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;
use Webkul\Tenant\Models\Tenant;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * The models to be used by this module.
     *
     * @var array
     */
    protected $models = [
        Tenant::class,
    ];
}
