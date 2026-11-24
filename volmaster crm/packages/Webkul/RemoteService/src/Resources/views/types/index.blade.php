<x-admin::layouts>
    <x-slot:title>
        Tipos de Atendimento
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <div class="text-xl font-bold dark:text-white">
                    Tipos de Atendimento
                </div>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Os serviços que aparecem pra escolher ao registrar um Atendimento Remoto (Diagnóstico, Programações, etc.).
                </p>
            </div>

            <div class="flex items-center gap-x-2.5">
                <a
                    href="{{ route('admin.remote_services.index') }}"
                    class="secondary-button"
                >
                    Voltar
                </a>

                <a
                    href="{{ route('admin.remote_services.types.create') }}"
                    class="primary-button"
                >
                    Novo tipo
                </a>
            </div>
        </div>

        <x-admin::datagrid :src="route('admin.remote_services.types.index')">
            <x-admin::shimmer.datagrid />
        </x-admin::datagrid>
    </div>
</x-admin::layouts>
