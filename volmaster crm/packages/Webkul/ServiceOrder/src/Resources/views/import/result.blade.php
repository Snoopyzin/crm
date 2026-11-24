@php
    $isClientes = $upload['type'] === 'clientes';

    $verb = $dryRun ? 'serão' : 'foram';

    $cards = $isClientes
        ? [
            ['Clientes novos', $result['created'], "$verb cadastrados"],
            ['Clientes já existentes', $result['reused'], 'mesmo CPF/CNPJ, e-mail ou telefone -- não duplicados'],
            ['Veículos novos', $result['vehicles_created'], "$verb cadastrados"],
            ['Veículos já existentes', $result['vehicles_skipped'], 'placa já cadastrada -- ignorados'],
        ]
        : [
            ['Peças novas', $result['created'], "$verb cadastradas"],
            ['Peças já existentes', $result['updated'], ($dryRun ? 'terão' : 'tiveram').' nome/preço atualizados (estoque não muda)'],
        ];
@endphp

<x-admin::layouts>
    <x-slot:title>
        Importar dados
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <p class="text-xl font-bold text-gray-800 dark:text-white">
                {{ $dryRun ? '4. Confira antes de importar' : 'Importação concluída' }}
            </p>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                {{ $upload['name'] }} -- {{ number_format($result['total'], 0, ',', '.') }} linhas lidas.
                @if ($dryRun)
                    Nada foi gravado ainda.
                @endif
            </p>
        </div>

        <div class="grid grid-cols-4 gap-3 max-lg:grid-cols-2 max-sm:grid-cols-1">
            @foreach ($cards as [$label, $count, $hint])
                <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white">{{ number_format($count, 0, ',', '.') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        @if ($result['errors'])
            <div class="box-shadow rounded-lg border bg-white p-4 dark:bg-gray-900" style="border-color:rgba(239,68,68,.5)">
                <p class="text-sm font-semibold" style="color:#dc2626">
                    {{ count($result['errors']) }} {{ count($result['errors']) === 1 ? 'linha com problema' : 'linhas com problema' }}
                    {{ $dryRun ? '(serão puladas -- o resto importa normalmente)' : '(foram puladas)' }}
                </p>

                <ul class="mt-2 max-h-72 overflow-y-auto text-xs text-gray-700 dark:text-gray-300">
                    @foreach (array_slice($result['errors'], 0, 200) as $error)
                        <li class="border-t border-gray-100 py-1 dark:border-gray-800"><b>Linha {{ $error['line'] }}:</b> {{ $error['message'] }}</li>
                    @endforeach

                    @if (count($result['errors']) > 200)
                        <li class="py-1">... e mais {{ count($result['errors']) - 200 }}.</li>
                    @endif
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2.5">
            @if ($dryRun)
                <form method="POST" action="{{ route('admin.import.run') }}" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').innerText = 'Importando...';">
                    @csrf

                    <button type="submit" class="primary-button">Importar agora</button>
                </form>

                <a href="{{ route('admin.import.mapping') }}" class="secondary-button">Voltar e ajustar colunas</a>

                <form method="POST" action="{{ route('admin.import.cancel') }}">
                    @csrf

                    <button type="submit" class="secondary-button">Cancelar</button>
                </form>
            @else
                <a href="{{ $isClientes ? route('admin.contacts.persons.index') : route('admin.import.index') }}" class="primary-button">
                    {{ $isClientes ? 'Ver clientes' : 'Importar outra planilha' }}
                </a>

                @if ($isClientes)
                    <a href="{{ route('admin.import.index') }}" class="secondary-button">Importar outra planilha</a>
                @endif
            @endif
        </div>
    </div>
</x-admin::layouts>
