<x-admin::layouts>
    <x-slot:title>
        Emitir NF-e de Produto &middot; OS #{{ $serviceOrder->id }}
    </x-slot>

    <x-admin::form :action="route('admin.fiscal_documents.emit-nfe-produto', $serviceOrder->id)" method="POST">
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <x-admin::breadcrumbs name="service_orders.view" :entity="$serviceOrder" />

                    <div class="text-xl font-bold dark:text-white">
                        Emitir NF-e de Produto &middot; OS #{{ $serviceOrder->id }}
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    <a href="{{ route('admin.service_orders.view', $serviceOrder->id) }}" class="secondary-button">
                        Cancelar
                    </a>

                    <button type="submit" class="primary-button">
                        Confirmar e Emitir
                    </button>
                </div>
            </div>

            <div class="box-shadow rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-200">
                Confira os itens abaixo antes de confirmar -- essa é a NF-e das <strong>peças</strong> dessa OS (os serviços/mão de obra vão na NFS-e separada). Depois de emitida, só pode ser cancelada dentro do prazo permitido pela SEFAZ, não simplesmente editada.
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Cliente</p>
                        <p class="text-base font-medium dark:text-white">{{ $serviceOrder->person->name ?? '-' }}</p>
                    </div>

                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">CPF/CNPJ</p>
                        <p class="text-base font-medium dark:text-white">{{ $serviceOrder->person->cpf_cnpj ?? '-' }}</p>
                    </div>

                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Emitente</p>
                        <p class="text-base font-medium dark:text-white">{{ $fiscalSettings->razao_social }} ({{ $fiscalSettings->cnpj }})</p>
                    </div>

                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">CFOP</p>
                        <p class="text-base font-medium dark:text-white">{{ $fiscalSettings->cfop_padrao }}</p>
                    </div>
                </div>
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                    Peças
                </p>

                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            <th class="pb-2">Peça</th>
                            <th class="pb-2">NCM</th>
                            <th class="pb-2">Qtd.</th>
                            <th class="pb-2">Valor Unitário</th>
                            <th class="pb-2">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pecaItems as $item)
                            <tr class="border-b border-gray-100 text-gray-800 dark:border-gray-900 dark:text-gray-200">
                                <td class="py-2">{{ $item->name }}</td>
                                <td class="py-2">{{ $item->part->ncm ?? '-' }}</td>
                                <td class="py-2">{{ $item->quantity }}</td>
                                <td class="py-2">{{ core()->formatBasePrice($item->price) }}</td>
                                <td class="py-2">{{ core()->formatBasePrice($item->line_total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
