<x-admin::layouts>
    <x-slot:title>
        {{ $fiscalDocument->focus_ref }}
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="fiscal_documents" />

                <div class="text-xl font-bold dark:text-white">
                    {{ strtoupper($fiscalDocument->type) }} &middot; {{ $fiscalDocument->status }}
                </div>
            </div>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="grid grid-cols-3 gap-4 max-sm:grid-cols-1">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('fiscal::app.view.ref')</p>
                    <p class="text-base font-medium dark:text-white">{{ $fiscalDocument->focus_ref }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $fiscalDocument->type === 'nfse' ? 'Código de Verificação' : 'Chave de Acesso' }}
                    </p>
                    <p class="text-base font-medium dark:text-white">
                        {{ $fiscalDocument->type === 'nfse' ? ($fiscalDocument->codigo_verificacao ?? '-') : ($fiscalDocument->chave_nfe ?? '-') }}
                    </p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('fiscal::app.view.last-error')</p>
                    <p class="text-base font-medium text-red-600">{{ $fiscalDocument->last_error ?? '-' }}</p>
                </div>

                @if ($fiscalDocument->pdf_url)
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">PDF</p>
                        <a href="{{ $fiscalDocument->pdf_url }}" target="_blank" class="text-base font-medium text-blue-600 underline">Abrir DANFSE</a>
                    </div>
                @endif

                @if ($fiscalDocument->xml_url)
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">XML</p>
                        <a href="{{ $fiscalDocument->xml_url }}" target="_blank" class="text-base font-medium text-blue-600 underline">Baixar XML</a>
                    </div>
                @endif
            </div>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                @lang('fiscal::app.view.logs')
            </p>

            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <th class="pb-2">Event</th>
                        <th class="pb-2">Data</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fiscalDocument->logs as $log)
                        <tr class="border-b border-gray-100 dark:border-gray-900">
                            <td class="py-2">{{ $log->event }}</td>
                            <td class="py-2">{{ core()->formatDate($log->created_at) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
