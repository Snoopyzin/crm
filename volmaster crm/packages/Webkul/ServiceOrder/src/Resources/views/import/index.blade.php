<x-admin::layouts>
    <x-slot:title>
        Importar dados
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Importar dados do sistema antigo</p>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                No sistema que você usava antes, procure a opção <b>Exportar</b> (Excel ou CSV) da lista de clientes ou de peças e envie o arquivo aqui.
                Nada é gravado antes de você conferir a prévia.
            </p>
        </div>

        <form
            method="POST"
            action="{{ route('admin.import.upload') }}"
            enctype="multipart/form-data"
            class="box-shadow flex flex-col gap-5 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900"
        >
            @csrf

            <div>
                <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">1. O que você quer importar?</p>

                <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                    @foreach ([
                        'clientes' => ['Clientes e veículos', 'Uma linha por cliente (ou por veículo). Se a linha tiver placa, o veículo é cadastrado e ligado ao cliente.'],
                        'pecas' => ['Peças e estoque', 'Código, nome, preço e quantidade em estoque de cada peça.'],
                    ] as $value => [$label, $hint])
                        <label class="flex cursor-pointer gap-3 rounded-lg border border-gray-200 p-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950">
                            <input type="radio" name="type" value="{{ $value }}" class="mt-1" {{ old('type', 'clientes') === $value ? 'checked' : '' }}>

                            <span>
                                <span class="block text-sm font-semibold text-gray-800 dark:text-white">{{ $label }}</span>
                                <span class="block text-xs text-gray-600 dark:text-gray-300">{{ $hint }}</span>
                                <a href="{{ route('admin.import.template', $value) }}" class="mt-1 inline-block text-xs text-brandColor hover:underline">Baixar planilha modelo</a>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">2. Envie a planilha</p>

                <input
                    type="file"
                    name="file"
                    accept=".xlsx,.xls,.csv"
                    class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                >

                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Excel (.xlsx, .xls) ou CSV, até 10 MB e {{ number_format(\Webkul\ServiceOrder\Services\SpreadsheetImportService::MAX_ROWS, 0, ',', '.') }} linhas. A primeira linha precisa ter os títulos das colunas.</p>

                @error('file')
                    <p class="mt-1 text-xs italic text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <button type="submit" class="primary-button">Continuar</button>
            </div>
        </form>
    </div>
</x-admin::layouts>
