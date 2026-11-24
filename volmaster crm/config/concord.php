<?php

use Webkul\Admin\Providers\ModuleServiceProvider as AdminModuleServiceProvider;
use Webkul\Attribute\Providers\ModuleServiceProvider as AttributeModuleServiceProvider;
use Webkul\Automation\Providers\ModuleServiceProvider as AutomationModuleServiceProvider;
use Webkul\Contact\Providers\ModuleServiceProvider as ContactModuleServiceProvider;
use Webkul\Core\Providers\ModuleServiceProvider as CoreModuleServiceProvider;
use Webkul\DataGrid\Providers\ModuleServiceProvider as DataGridModuleServiceProvider;
use Webkul\DataTransfer\Providers\ModuleServiceProvider as DataTransferModuleServiceProvider;
use Webkul\Email\Providers\ModuleServiceProvider as EmailModuleServiceProvider;
use Webkul\EmailTemplate\Providers\ModuleServiceProvider as EmailTemplateModuleServiceProvider;
use Webkul\Lead\Providers\ModuleServiceProvider as LeadModuleServiceProvider;
use Webkul\Product\Providers\ModuleServiceProvider as ProductModuleServiceProvider;
use Webkul\Quote\Providers\ModuleServiceProvider as QuoteModuleServiceProvider;
use Webkul\Tag\Providers\ModuleServiceProvider as TagModuleServiceProvider;
use Webkul\Mechanic\Providers\ModuleServiceProvider as MechanicModuleServiceProvider;
use Webkul\PartInventory\Providers\ModuleServiceProvider as PartInventoryModuleServiceProvider;
use Webkul\Fiscal\Providers\ModuleServiceProvider as FiscalModuleServiceProvider;
use Webkul\User\Providers\ModuleServiceProvider as UserModuleServiceProvider;
use Webkul\Vehicle\Providers\ModuleServiceProvider as VehicleModuleServiceProvider;
use Webkul\Warehouse\Providers\ModuleServiceProvider as WarehouseModuleServiceProvider;
use Webkul\WebForm\Providers\ModuleServiceProvider as WebFormModuleServiceProvider;
use Webkul\WorkBay\Providers\ModuleServiceProvider as WorkBayModuleServiceProvider;
use Webkul\Tenant\Providers\ModuleServiceProvider as TenantModuleServiceProvider;
use Webkul\RemoteService\Providers\ModuleServiceProvider as RemoteServiceModuleServiceProvider;

return [
    'modules' => [
        DataTransferModuleServiceProvider::class,
        AdminModuleServiceProvider::class,
        AttributeModuleServiceProvider::class,
        AutomationModuleServiceProvider::class,
        ContactModuleServiceProvider::class,
        CoreModuleServiceProvider::class,
        DataGridModuleServiceProvider::class,
        EmailTemplateModuleServiceProvider::class,
        EmailModuleServiceProvider::class,
        LeadModuleServiceProvider::class,
        ProductModuleServiceProvider::class,
        QuoteModuleServiceProvider::class,
        TagModuleServiceProvider::class,
        UserModuleServiceProvider::class,
        WarehouseModuleServiceProvider::class,
        WebFormModuleServiceProvider::class,
        DataTransferModuleServiceProvider::class,
        VehicleModuleServiceProvider::class,
        MechanicModuleServiceProvider::class,
        PartInventoryModuleServiceProvider::class,
        WorkBayModuleServiceProvider::class,
        FiscalModuleServiceProvider::class,
        TenantModuleServiceProvider::class,
        RemoteServiceModuleServiceProvider::class,
    ],

    'register_route_models' => true,
];
