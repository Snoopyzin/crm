<?php

namespace Webkul\PartInventory\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;
use Webkul\PartInventory\Models\Part;
use Webkul\PartInventory\Models\PartCategory;
use Webkul\PartInventory\Models\PartInventory;
use Webkul\PartInventory\Models\PartStockMovement;
use Webkul\PartInventory\Models\Service;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * The models to be used by this module.
     *
     * @var array
     */
    protected $models = [
        PartCategory::class,
        Part::class,
        PartInventory::class,
        PartStockMovement::class,
        Service::class,
    ];
}
