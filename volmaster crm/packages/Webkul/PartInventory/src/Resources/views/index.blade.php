<x-admin::layouts>
    <x-slot:title>
        @lang('partinventory::app.index.title')
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="parts" />

                <div class="text-xl font-bold dark:text-white">
                    @lang('partinventory::app.index.title')
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                @if (bouncer()->hasPermission('parts.create'))
                    <a
                        href="{{ route('admin.parts.create') }}"
                        class="primary-button"
                    >
                        @lang('partinventory::app.index.create-btn')
                    </a>
                @endif
            </div>
        </div>

        <x-admin::datagrid :src="route('admin.parts.index')">
            <x-admin::shimmer.datagrid />
        </x-admin::datagrid>
    </div>
</x-admin::layouts>
