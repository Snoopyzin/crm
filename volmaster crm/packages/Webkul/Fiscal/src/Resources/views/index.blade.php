<x-admin::layouts>
    <x-slot:title>
        @lang('fiscal::app.index.title')
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="fiscal_documents" />

                <div class="text-xl font-bold dark:text-white">
                    @lang('fiscal::app.index.title')
                </div>
            </div>
        </div>

        @unless (\Webkul\Admin\Models\ApiKey::focusNfeToken())
            <div class="rounded-lg border border-yellow-300 bg-yellow-50 px-4 py-3 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-200">
                @lang('fiscal::app.not-configured')
            </div>
        @endunless

        <x-admin::datagrid :src="route('admin.fiscal_documents.index')">
            <x-admin::shimmer.datagrid />
        </x-admin::datagrid>
    </div>
</x-admin::layouts>
