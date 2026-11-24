<x-admin::layouts>
    <x-slot:title>
        Serviços Remotos
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <div class="text-xl font-bold dark:text-white">
                    Serviços Remotos
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                <button
                    type="button"
                    class="secondary-button"
                    onclick="navigator.clipboard.writeText('{{ url('/agendar') }}'); this.textContent='Link copiado!'; setTimeout(() => this.textContent='Copiar link de agendamento', 1500);"
                >
                    Copiar link de agendamento
                </button>

                @if (bouncer()->hasPermission('remote_services.types'))
                    <a
                        href="{{ route('admin.remote_services.types.index') }}"
                        class="secondary-button"
                    >
                        Tipos de atendimento
                    </a>
                @endif

                @if (bouncer()->hasPermission('remote_services.create'))
                    <a
                        href="{{ route('admin.remote_services.create') }}"
                        class="primary-button"
                    >
                        Novo atendimento
                    </a>
                @endif
            </div>
        </div>

        <x-admin::datagrid :src="route('admin.remote_services.index')">
            <x-admin::shimmer.datagrid />
        </x-admin::datagrid>
    </div>
</x-admin::layouts>
