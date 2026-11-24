@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $card = 'box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900';
    $pct = fn ($v) => $v === null ? '—' : number_format($v, 1, ',', '.').'%';
@endphp

<x-admin::layouts>
    <x-slot:title>
        Visão do mentor
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div>
                <div class="text-xl font-bold dark:text-white">Visão do mentor — {{ $periodo }}</div>
                <p class="text-sm text-gray-500">Comparativo entre as oficinas, só com indicadores em porcentagem. Os dados operacionais são somente leitura.</p>
            </div>
            <form method="GET" class="flex items-center gap-2">
                <select name="cargo" onchange="this.form.submit()" class="{{ $campo }}">
                    <option value="">Todos os cargos</option>
                    @foreach ($cargos as $chave => $nome)<option value="{{ $chave }}" @selected($cargo === $chave)>{{ $nome }}</option>@endforeach
                </select>
                <input type="month" name="periodo" value="{{ $periodo }}" onchange="this.form.submit()" class="{{ $campo }}" />
            </form>
        </div>

        <div class="{{ $card }} overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                        <th class="pb-2">Indicador</th><th class="pb-2">Meta</th>
                        @foreach ($comparativo['oficinas'] as $oficina)<th class="pb-2 text-center">{{ $oficina['nome'] }}@if ($oficina['sem_dados'])<span class="block text-xs font-normal normal-case text-gray-400">sem dados</span>@endif</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($comparativo['linhas'] as $linha)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1.5 dark:text-white"><span class="font-mono text-xs text-gray-500">{{ $linha['codigo'] }}</span> {{ $linha['nome'] }}</td>
                            <td class="py-1.5 text-xs text-gray-500">{{ $linha['meta'] }}</td>
                            @foreach ($comparativo['oficinas'] as $oficina)
                                @php $celula = $linha['oficinas'][$oficina['nome']] ?? null; @endphp
                                <td class="py-1.5 text-center">
                                    @if ($celula)<span class="rounded-full px-2 py-0.5 text-xs font-semibold" style="background: {{ $celula['cores']['fundo'] }}; color: {{ $celula['cores']['texto'] }};">{{ $celula['realizado_fmt'] }}</span>@else<span class="text-gray-400">—</span>@endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ 2 + count($comparativo['oficinas']) }}" class="py-3 text-gray-500">Sem indicadores para mostrar.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p class="mt-3 text-xs text-gray-400">— indica oficina ou indicador sem dados no período.</p>
        </div>

        <div class="{{ $card }}">
            <p class="mb-2 text-base font-semibold dark:text-white">Evolução contra o Marco Zero</p>
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800"><th class="pb-2">Oficina</th><th class="pb-2">Hora ÷ preço mínimo</th><th class="pb-2">Produtividade</th><th class="pb-2">Retrabalho</th></tr></thead>
                <tbody>
                    @foreach ($evolucao as $linha)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1.5 dark:text-white">{{ $linha['oficina'] }}@if (empty($linha['marco_zero']) && empty($linha['sem_dados']))<span class="block text-xs text-gray-400">Marco Zero ainda não registrado</span>@endif</td>
                            @if (empty($linha['marco_zero']))
                                @foreach (['hora_sobre_minimo', 'produtividade', 'retrabalho'] as $chave)
                                    <td class="py-1.5 text-gray-400">—</td>
                                @endforeach
                            @else
                                @foreach (['hora_sobre_minimo', 'produtividade', 'retrabalho'] as $chave)
                                    <td class="py-1.5">{{ $pct($linha['marco_zero'][$chave]) }} <span class="text-gray-400">→</span> <b>{{ $pct($linha['hoje'][$chave]) }}</b></td>
                                @endforeach
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="{{ $card }}">
            <p class="mb-2 text-base font-semibold dark:text-white">Padrão da mentoria</p>
            <p class="mb-3 text-xs text-gray-500">Ajuste as metas padrão e os pesos desta instalação e replique para todas as oficinas. A meta que cada oficina preencheu para si não é alterada.</p>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.mentor.replicar_metas') }}" onsubmit="return confirm('Replicar as metas padrão para todas as oficinas?')">@csrf <button type="submit" class="secondary-button">Replicar metas padrão</button></form>
                <form method="POST" action="{{ route('admin.mentor.replicar_pesos') }}" onsubmit="return confirm('Replicar os pesos do bônus para todas as oficinas? Isso substitui os pesos de cada uma.')">@csrf <button type="submit" class="secondary-button">Replicar pesos do bônus</button></form>
            </div>
        </div>
    </div>
</x-admin::layouts>
