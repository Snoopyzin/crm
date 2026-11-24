<x-admin::layouts>
    <x-slot:title>
        Registro de acesso
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Registro de acesso aos dados financeiros e de pessoal</div>
            <p class="text-sm text-gray-500">Quem abriu ou mexeu no painel do dono, no financeiro, nos colaboradores, no bônus e na Ficha. Só inserção: nada é apagado. (LGPD)</p>
        </div>

        <div class="box-shadow overflow-x-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800"><th class="pb-2">Quando</th><th class="pb-2">Quem</th><th class="pb-2">Tela / ação</th><th class="pb-2">Método</th><th class="pb-2">IP</th></tr></thead>
                <tbody>
                    @forelse ($acessos as $acesso)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1.5 whitespace-nowrap dark:text-white">{{ \Carbon\Carbon::parse($acesso->acessado_em)->format('d/m/Y H:i:s') }}</td>
                            <td class="py-1.5 dark:text-white">{{ $acesso->usuario ?? '—' }}</td>
                            <td class="py-1.5 font-mono text-xs">{{ $acesso->rota }}</td>
                            <td class="py-1.5 text-xs">{{ $acesso->metodo }}</td>
                            <td class="py-1.5 text-xs text-gray-500">{{ $acesso->ip }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-3 text-gray-500">Nenhum acesso registrado ainda.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
