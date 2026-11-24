<x-admin::layouts>
    <x-slot:title>
        {{ $definicao->codigo }} - {{ $definicao->nome }}
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div>
                <a class="text-xs text-blue-600 hover:underline" href="{{ route('admin.mentoria.indicadores', ['cargo' => $definicao->cargo, 'periodo' => $periodo]) }}">&larr; Indicadores</a>
                <div class="text-xl font-bold dark:text-white">{{ $definicao->codigo }} &middot; {{ $definicao->nome }}</div>
            </div>

            <form method="GET" class="flex items-center gap-2">
                <input type="month" name="periodo" value="{{ $periodo }}" onchange="this.form.submit()" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
            </form>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-xs uppercase text-gray-500">Realizado no mês</p>
                <p class="mt-1 text-3xl font-bold dark:text-white">{{ $linha['realizado_fmt'] }}</p>
                <span class="mt-2 inline-block rounded-full px-2 py-0.5 text-xs font-semibold" style="background: {{ $linha['cores']['fundo'] }}; color: {{ $linha['cores']['texto'] }};">{{ $linha['situacao_rotulo'] }}</span>
                @if ($resultado->denominador !== null)
                    <p class="mt-2 text-xs text-gray-500">{{ rtrim(rtrim(number_format($resultado->numerador, 2, ',', '.'), '0'), ',') }} de {{ rtrim(rtrim(number_format($resultado->denominador, 2, ',', '.'), '0'), ',') }}</p>
                @endif
                @if (! $resultado->hasData())
                    <p class="mt-2 text-xs text-gray-500">{{ $resultado->detalhe }}</p>
                @endif
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-xs uppercase text-gray-500">Meta</p>
                <p class="mt-1 text-3xl font-bold dark:text-white">{{ $linha['meta_fmt'] }}</p>
                <p class="mt-2 text-xs text-gray-500">Quem valida: {{ $definicao->valida }} &middot; Responsável: {{ $linha['cargo'] }}</p>
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-xs uppercase text-gray-500">Fórmula</p>
                <p class="mt-1 text-sm dark:text-white">{{ $definicao->formula }}</p>
                <p class="mt-3 text-xs uppercase text-gray-500">Últimos 6 meses</p>
                <p class="text-sm dark:text-white">
                    @foreach ($historico as $ym => $item)
                        <span class="mr-2 whitespace-nowrap"><span class="text-xs text-gray-500">{{ $ym }}</span> {{ $item['fmt'] }}</span>
                    @endforeach
                </p>
            </div>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-3 text-base font-semibold dark:text-white">OS que formam o número</p>

            @forelse ($ordens as $ordem)
                <a href="{{ route('admin.service_orders.view', $ordem->id) }}" class="mb-1 mr-2 inline-flex items-center gap-2 rounded-md border border-gray-200 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-800 dark:text-white dark:hover:bg-gray-800">
                    <b>#{{ $ordem->id }}</b>
                    {{ $ordem->vehicle?->placa ?? '-' }}
                    <span class="text-xs text-gray-500">{{ $ordem->person?->name }} &middot; {{ $ordem->workshop_status }}</span>
                </a>
            @empty
                <p class="text-sm text-gray-500">Este indicador não vem de OS individuais (vem de registros do dia a dia) ou ainda não tem dados no mês.</p>
            @endforelse
        </div>
    </div>
</x-admin::layouts>
