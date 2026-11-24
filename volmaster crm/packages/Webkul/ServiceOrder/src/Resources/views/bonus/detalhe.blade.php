@php
    $cores = ['verde' => 'bg-green-100 text-green-700', 'amarelo' => 'bg-yellow-100 text-yellow-800', 'vermelho' => 'bg-red-100 text-red-700', 'sem_dado' => 'bg-gray-100 text-gray-600', 'sem_meta' => 'bg-gray-100 text-gray-600'];
    $rotulos = \Webkul\ServiceOrder\Services\Indicators\IndicatorStatus::ROTULOS;
@endphp

<x-admin::layouts>
    <x-slot:title>
        Detalhe da nota
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <a class="text-xs text-blue-600 hover:underline" href="{{ route('admin.mentoria.bonus', ['periodo' => $apuracao->periodo]) }}">&larr; Bônus de {{ $apuracao->periodo }}</a>
            <div class="text-xl font-bold dark:text-white">{{ $apuracao->colaborador?->nome }} &middot; {{ $cargos[$apuracao->cargo] ?? $apuracao->cargo }}</div>
            <p class="text-sm text-gray-500">
                Nota <b>{{ $apuracao->nota !== null ? number_format($apuracao->nota, 1, ',', '.') : '—' }}</b> &middot; faixa {{ $apuracao->faixa_percentual }}% &middot; fase {{ $apuracao->fase }}
                @if ($apuracao->zerado_por_trava) &middot; <b class="text-red-600">zerado por trava confirmada</b> @endif
            </p>
            @if ($apuracao->motivo_resultado)<p class="mt-1 text-sm text-yellow-700">{{ $apuracao->motivo_resultado }}</p>@endif
        </div>

        <div class="box-shadow overflow-x-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-3 text-base font-semibold dark:text-white">Marcadores</p>
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                        <th class="pb-2">Marcador</th><th class="pb-2 text-right">Peso</th><th class="pb-2">Realizado</th><th class="pb-2">Meta</th><th class="pb-2">Situação</th><th class="pb-2 text-right">Pontos</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($apuracao->detalhe_json ?? [] as $linha)
                        <tr class="border-b border-gray-100 align-top dark:border-gray-800">
                            <td class="py-2 dark:text-white">
                                {{ $linha['marcador'] }}
                                <span class="block font-mono text-xs text-gray-500">{{ implode(' + ', $linha['codigos']) }}{{ $linha['resultado'] ? ' · resultado' : '' }}</span>
                                @if ($linha['obs'])<span class="block text-xs text-yellow-700">{{ $linha['obs'] }}</span>@endif
                            </td>
                            <td class="py-2 text-right">{{ $linha['peso'] }}</td>
                            <td class="py-2 text-xs">{{ collect($linha['realizado'])->map(fn ($v) => $v === null ? '—' : number_format($v, 1, ',', '.'))->implode(' / ') }}</td>
                            <td class="py-2 text-xs">{{ collect($linha['metas'])->map(fn ($v) => $v === null ? '—' : number_format($v, 1, ',', '.'))->implode(' / ') }}</td>
                            <td class="py-2">@foreach ($linha['situacoes'] as $s)<span class="mr-1 rounded px-1.5 py-0.5 text-xs {{ $cores[$s] ?? '' }}">{{ $rotulos[$s] ?? $s }}</span>@endforeach</td>
                            <td class="py-2 text-right font-semibold dark:text-white">{{ $linha['pontos'] === null ? 'fora do cálculo' : number_format($linha['pontos_ajustados'], 1, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-3 text-xs text-gray-500">Peso inteiro no alvo, metade em "atenção", zero abaixo. Marcador sem dado no mês sai do cálculo e os demais pesos são reescalonados para somar 100.</p>
        </div>

        @if ($travas->isNotEmpty())
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-2 text-base font-semibold dark:text-white">Travas do mês</p>
                @foreach ($travas as $trava)
                    <p class="border-b border-gray-100 py-1.5 text-sm dark:border-gray-800 dark:text-white">
                        <span class="rounded px-1.5 py-0.5 text-xs {{ $trava->status === 'confirmada' ? 'bg-red-100 text-red-700' : ($trava->status === 'pendente' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-600') }}">{{ $trava->status }}</span>
                        {{ $trava->tipo_nome }} <span class="text-xs text-gray-500">— {{ $trava->descricao }}</span>
                    </p>
                @endforeach
            </div>
        @endif

        @if ($apuracao->feedback)
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-1 text-base font-semibold dark:text-white">Feedback do gerente</p>
                <p class="text-sm dark:text-gray-200">{{ $apuracao->feedback }}</p>
            </div>
        @endif
    </div>
</x-admin::layouts>
