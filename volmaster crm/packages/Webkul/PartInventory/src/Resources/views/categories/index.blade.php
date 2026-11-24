<x-admin::layouts>
    <x-slot:title>
        @lang('partinventory::app.categories.index.title')
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="part-categories" />

                <div class="text-xl font-bold dark:text-white">
                    @lang('partinventory::app.categories.index.title')
                </div>
            </div>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                @lang('partinventory::app.categories.index.create-title')
            </p>

            <form method="POST" action="{{ route('admin.part-categories.store') }}" class="flex items-end gap-2">
                @csrf

                <div class="flex flex-col gap-1">
                    <label class="text-xs text-gray-500 dark:text-gray-400">@lang('partinventory::app.categories.form.name')</label>
                    <input
                        type="text"
                        name="name"
                        placeholder="{{ trans('partinventory::app.categories.form.name-placeholder') }}"
                        class="w-64 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                        required
                    />
                </div>

                <button type="submit" class="primary-button">
                    @lang('partinventory::app.categories.index.create-btn')
                </button>
            </form>

            @error('name')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <x-admin::datagrid :src="route('admin.part-categories.index')">
            <x-admin::shimmer.datagrid />
        </x-admin::datagrid>
    </div>
</x-admin::layouts>
