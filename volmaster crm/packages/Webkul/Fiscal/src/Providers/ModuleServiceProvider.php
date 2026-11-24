<?php

namespace Webkul\Fiscal\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;
use Webkul\Fiscal\Models\FiscalDocument;
use Webkul\Fiscal\Models\FiscalDocumentLog;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * The models to be used by this module.
     *
     * @var array
     */
    protected $models = [
        FiscalDocument::class,
        FiscalDocumentLog::class,
    ];
}
