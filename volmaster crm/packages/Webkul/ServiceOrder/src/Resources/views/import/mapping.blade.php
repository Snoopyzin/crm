<x-admin::layouts>
    <x-slot:title>
        Importar dados
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-xl font-bold text-gray-800 dark:text-white">3. Ligue as colunas</p>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                Arquivo <b>{{ $upload['name'] }}</b> com {{ number_format($total, 0, ',', '.') }} linhas.
                Já tentamos adivinhar cada coluna -- confira e corrija o que estiver errado. Campos com * são obrigatórios.
            </p>

            @if ($total > $max)
                <p class="mt-1 text-sm font-semibold" style="color:#dc2626">Só as primeiras {{ number_format($max, 0, ',', '.') }} linhas serão importadas. Divida a planilha e importe o resto depois.</p>
            @endif
        </div>

        {{-- Sample of the spreadsheet --}}
        <div class="box-shadow overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <p class="px-4 pt-3 text-sm font-semibold text-gray-800 dark:text-white">Primeiras linhas da sua planilha</p>

            <table class="mt-2 min-w-full text-xs">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-950">
                        @foreach ($headers as $header)
                            <th class="whitespace-nowrap px-3 py-2 text-left font-semibold text-gray-600 dark:text-gray-300">{{ $header ?: '(sem título)' }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @foreach ($sample as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            @foreach ($headers as $index => $header)
                                <td class="whitespace-nowrap px-3 py-1.5 text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($row[$index] ?? '', 40) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('admin.import.preview') }}" class="box-shadow flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            @csrf

            <div class="grid grid-cols-2 gap-x-6 gap-y-3 max-sm:grid-cols-1">
                @foreach ($fields as $field => [$label, $required])
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-800 dark:text-white">{{ $label }}{{ $required ? ' *' : '' }}</label>

                        <select name="mapping[{{ $field }}]" class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">— não importar —</option>

                            @foreach ($headers as $index => $header)
                                <option value="{{ $index }}" {{ $mapping[$field] === $index ? 'selected' : '' }}>
                                    {{ $header ?: 'Coluna '.($index + 1) }}{{ isset($sample[0][$index]) && $sample[0][$index] !== '' ? ' (ex: '.\Illuminate\Support\Str::limit($sample[0][$index], 25).')' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>

            @if ($upload['type'] === 'clientes')
                <div class="max-w-sm">
                    <label class="mb-1 block text-xs font-medium text-gray-800 dark:text-white">Tipo dos veículos quando a planilha não disser</label>

                    <select name="tipo_padrao" class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        @foreach (['caminhoes' => 'Caminhão', 'carros' => 'Carro', 'motos' => 'Moto'] as $value => $label)
                            <option value="{{ $value }}" {{ ($options['tipo_padrao'] ?? 'caminhoes') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <div class="max-w-sm">
                    <label class="mb-1 block text-xs font-medium text-gray-800 dark:text-white">Depósito onde o estoque vai entrar</label>

                    <select name="warehouse_id" class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        <option value="">—</option>

                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" {{ (string) ($options['warehouse_id'] ?? ($warehouses->count() === 1 ? $warehouse->id : '')) === (string) $warehouse->id ? 'selected' : '' }}>{{ $warehouse->name }}</option>
                        @endforeach
                    </select>

                    @if ($warehouses->isEmpty())
                        <p class="mt-1 text-xs" style="color:#dc2626">Nenhum depósito cadastrado -- crie um em Configurações > Depósitos pra importar o estoque. Sem depósito, só os dados das peças entram.</p>
                    @endif
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-2.5">
                <button type="submit" class="primary-button">Conferir antes de importar</button>

                <button type="submit" form="cancel-import" class="secondary-button">Cancelar</button>
            </div>
        </form>

        <form id="cancel-import" method="POST" action="{{ route('admin.import.cancel') }}">
            @csrf
        </form>
    </div>
</x-admin::layouts>
