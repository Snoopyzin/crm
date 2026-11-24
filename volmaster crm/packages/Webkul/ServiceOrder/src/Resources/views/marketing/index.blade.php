@php
    $brl = fn ($value) => core()->formatBasePrice((float) $value);
    $maxOs = max(1, $channels->max('os'));
    $best = $channels->except(\Webkul\ServiceOrder\Http\Controllers\MarketingController::NAO_INFORMADO)->sortByDesc('faturamento')->keys()->first();
    $naoInformado = $channels[\Webkul\ServiceOrder\Http\Controllers\MarketingController::NAO_INFORMADO]['os'];
@endphp

<x-admin::layouts>
    <x-slot:title>
        Marketing
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-end justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div>
                <p class="text-xl font-bold text-gray-800 dark:text-white">Marketing — de onde vêm os clientes</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">OS abertas no período, pela resposta "Por qual meio você chegou à nossa empresa?". Faturamento = OS dessas que já foram faturadas.</p>
            </div>

            <form method="GET" action="{{ route('admin.marketing.index') }}" class="flex flex-wrap items-end gap-2">
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-gray-500 dark:text-gray-400">De</label>
                    <input type="date" name="de" value="{{ $from->format('Y-m-d') }}" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-xs text-gray-500 dark:text-gray-400">Até</label>
                    <input type="date" name="ate" value="{{ $to->format('Y-m-d') }}" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                </div>

                <button type="submit" class="secondary-button">Filtrar</button>

                <a href="{{ route('admin.marketing.export', ['de' => $from->format('Y-m-d'), 'ate' => $to->format('Y-m-d')]) }}" class="primary-button" title="Uma linha por canal, com o total">Exportar resumo (Excel)</a>

                <a href="{{ route('admin.marketing.export', ['de' => $from->format('Y-m-d'), 'ate' => $to->format('Y-m-d'), 'tipo' => 'detalhe']) }}" class="secondary-button" title="Uma linha por OS, com o canal de origem">Exportar OS por canal (Excel)</a>
            </form>
        </div>

        <div class="grid grid-cols-3 gap-3 max-md:grid-cols-1">
            @foreach ([
                ['OS abertas', number_format($totalOs, 0, ',', '.')],
                ['Faturamento dessas OS', $brl($totalRevenue)],
                ['Canal que mais faturou', $best && $channels[$best]['faturamento'] > 0 ? $best : '-'],
            ] as [$label, $value])
                <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        @if ($naoInformado > 0)
            <div class="rounded-lg border px-4 py-2 text-xs" style="background:rgba(234,179,8,.12);border-color:rgba(234,179,8,.5);color:#a16207">
                {{ $naoInformado }} {{ $naoInformado === 1 ? 'OS sem' : 'OS sem' }} a origem preenchida (clientes cadastrados antes dessa pergunta). A origem é perguntada só no Novo Cadastro; a OS nova de um cliente herda a origem que ele já tem.
            </div>
        @endif

        <div class="box-shadow overflow-x-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="pb-2">Canal</th>
                        <th class="pb-2">OS abertas</th>
                        <th class="pb-2 text-right">Faturadas</th>
                        <th class="pb-2 text-right">Conversão</th>
                        <th class="pb-2 text-right">Faturamento</th>
                        <th class="pb-2 text-right">Ticket médio</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($channels as $channel => $row)
                        <tr class="border-b border-gray-100 text-gray-800 dark:border-gray-900 dark:text-gray-200">
                            <td class="py-2 font-medium">{{ $channel }}</td>
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    <div style="height:8px;border-radius:4px;background:#0E90D9;width:{{ max(2, round($row['os'] / $maxOs * 160)) }}px;{{ $row['os'] ? '' : 'opacity:.2' }}"></div>
                                    <span>{{ $row['os'] }}</span>
                                </div>
                            </td>
                            <td class="py-2 text-right">{{ $row['faturadas'] }}</td>
                            <td class="py-2 text-right">{{ $row['os'] ? number_format($row['conversao'], 1, ',', '').'%' : '-' }}</td>
                            <td class="py-2 text-right font-semibold">{{ $brl($row['faturamento']) }}</td>
                            <td class="py-2 text-right">{{ $row['faturadas'] ? $brl($row['ticket']) : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
