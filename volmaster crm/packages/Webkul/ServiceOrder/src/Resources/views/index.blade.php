<x-admin::layouts>
    <x-slot:title>
        @lang('service_order::app.index.title')
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="service_orders" />

                <div class="text-xl font-bold dark:text-white">
                    @lang('service_order::app.index.title')
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                @if (bouncer()->hasPermission('service_orders.create'))
                    <a
                        href="{{ route('admin.service_orders.create') }}"
                        class="primary-button"
                    >
                        @lang('service_order::app.index.create-btn')
                    </a>
                @endif
            </div>
        </div>

        <x-admin::datagrid :src="route('admin.service_orders.index')">
            <x-admin::shimmer.datagrid />
        </x-admin::datagrid>
    </div>
</x-admin::layouts>
