<?php

namespace Webkul\RemoteService\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;
use Webkul\RemoteService\Models\RemoteService;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * The models to be used by this module.
     *
     * @var array
     */
    protected $models = [
        RemoteService::class,
    ];
}
