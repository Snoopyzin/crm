<x-admin::layouts>
    <x-slot:title>
        Plano de contas
    </x-slot>

    <div class="flex flex-col gap-4">
        @include('service_order::financeiro._abas')

        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Plano de contas</div>
            <p class="text-sm text-gray-500">O padrão da mentoria tem cerca de 20 contas. A oficina só cria subcontas; as contas padrão não se alteram.</p>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <form method="POST" action="{{ route('admin.financeiro.subconta') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-gray-500">Subconta de</label>
                    <select name="pai_id" required class="w-72 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                        @foreach ($contas->whereNull('pai_id') as $conta)<option value="{{ $conta->id }}">{{ $conta->codigo }} {{ $conta->nome }}</option>@endforeach
                    </select>
                </div>
                <div class="flex flex-col gap-1"><label class="text-xs text-gray-500">Nome</label><input type="text" name="nome" required class="w-72 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" /></div>
                <button type="submit" class="primary-button">Criar subconta</button>
            </form>
        </div>

        @foreach ($grupos as $chave => $nome)
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-2 text-base font-semibold dark:text-white">{{ $nome }}</p>
                @foreach ($contas->where('grupo', $chave) as $conta)
                    <p class="border-b border-gray-100 py-1 text-sm dark:border-gray-800 dark:text-white {{ $conta->pai_id ? 'pl-6' : '' }}">
                        <span class="inline-block w-16 font-mono text-xs text-gray-500">{{ $conta->codigo }}</span> {{ $conta->nome }}
                        @if (! $conta->padrao)<span class="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-xs text-blue-700">subconta</span>@endif
                    </p>
                @endforeach
            </div>
        @endforeach
    </div>
</x-admin::layouts>
