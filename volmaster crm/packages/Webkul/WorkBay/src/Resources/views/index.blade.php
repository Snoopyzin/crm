<x-admin::layouts>
    <x-slot:title>
        @lang('workbay::app.index.title')
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="work_bays" />

                <div class="text-xl font-bold dark:text-white">
                    @lang('workbay::app.index.title')
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                @if (bouncer()->hasPermission('work_bays.create'))
                    <a
                        href="{{ route('admin.work_bays.create') }}"
                        class="primary-button"
                    >
                        @lang('workbay::app.index.create-btn')
                    </a>
                @endif
            </div>
        </div>

        <x-admin::datagrid :src="route('admin.work_bays.index')">
            <x-admin::shimmer.datagrid />
        </x-admin::datagrid>
    </div>
</x-admin::layouts>
