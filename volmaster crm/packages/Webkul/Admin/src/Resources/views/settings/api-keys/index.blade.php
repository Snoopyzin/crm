<x-admin::layouts>
    <x-slot:title>
        Chaves de API
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <div class="text-xl font-bold dark:text-white">
                    Chaves de API
                </div>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Guarde aqui chaves/tokens de serviços externos (Focus NFe, WhatsApp, etc.) -- os valores ficam criptografados e nunca são exibidos de novo depois de salvos.
                </p>
            </div>

            <div class="flex items-center gap-x-2.5">
                @if (bouncer()->hasPermission('settings.other_settings.api_keys.create'))
                    <a
                        href="{{ route('admin.settings.api_keys.create') }}"
                        class="primary-button"
                    >
                        Nova chave
                    </a>
                @endif
            </div>
        </div>

        <x-admin::datagrid :src="route('admin.settings.api_keys.index')">
            <x-admin::shimmer.datagrid />
        </x-admin::datagrid>
    </div>
</x-admin::layouts>
