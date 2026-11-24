@php
    $brl = fn ($value) => core()->formatBasePrice((float) $value);
    $query = ['de' => $from->format('Y-m-d'), 'ate' => $to->format('Y-m-d')];
@endphp

<x-admin::layouts>
    <x-slot:title>
        Comissões
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-end justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div>
                <p class="text-xl font-bold text-gray-800 dark:text-white">Comissões</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">OS faturadas no período (canceladas não contam). O % vem da Função de cada pessoa, em Configurações &gt; Funções.</p>
            </div>

            <form method="GET" action="{{ route('admin.commissions.index') }}" class="flex flex-wrap items-end gap-2">
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-gray-500 dark:text-gray-400">De</label>
                    <input type="date" name="de" value="{{ $query['de'] }}" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-xs text-gray-500 dark:text-gray-400">Até</label>
                    <input type="date" name="ate" value="{{ $query['ate'] }}" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                </div>

                <button type="submit" class="secondary-button">Filtrar</button>

                <a href="{{ route('admin.commissions.export', $query) }}" class="primary-button">Exportar (Excel)</a>
            </form>
        </div>

        <div class="grid grid-cols-3 gap-3 max-md:grid-cols-1">
            @foreach ([
                ['OS faturadas', number_format($report['orders'], 0, ',', '.')],
                ['Base das comissões', $brl($report['total_base'])],
                ['Total de comissões', $brl($report['total_commission'])],
            ] as [$label, $value])
                <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        @if ($report['warnings']['sem_funcionario'] || $report['warnings']['sem_usuario'] || collect($report['people'])->contains(fn ($p) => $p['percent'] === null))
            <div class="rounded-lg border px-4 py-3 text-sm" style="background:rgba(234,179,8,.12);border-color:rgba(234,179,8,.5);color:#a16207">
                <p class="font-semibold">Pontos pra conferir:</p>
                <ul class="mt-1 list-disc pl-5 text-xs">
                    @if ($report['warnings']['sem_funcionario'])
                        <li>{{ $report['warnings']['sem_funcionario'] }} {{ $report['warnings']['sem_funcionario'] === 1 ? 'item' : 'itens' }} sem funcionário marcado -- não {{ $report['warnings']['sem_funcionario'] === 1 ? 'entra' : 'entram' }} em comissão de execução.</li>
                    @endif
                    @if ($report['warnings']['sem_usuario'])
                        <li>Funcionário sem usuário vinculado ({{ implode(', ', $report['warnings']['sem_usuario']) }}): vincule em Configurações &gt; Usuários ("Mecânico vinculado") pra puxar o % da Função.</li>
                    @endif
                    @if (collect($report['people'])->contains(fn ($p) => $p['percent'] === null))
                        <li>Há pessoas cuja Função não tem % de comissão definido -- aparecem com comissão R$ 0,00.</li>
                    @endif
                </ul>
            </div>
        @endif

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            @forelse ($report['people'] as $index => $person)
                <details class="border-b border-gray-100 py-2 last:border-0 dark:border-gray-800">
                    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 text-sm text-gray-800 dark:text-white">
                        <span>
                            <b>{{ $person['name'] }}</b>
                            <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $person['role'] ?? 'sem usuário vinculado' }} · {{ $person['kind'] }} · {{ count($person['lines']) }} {{ count($person['lines']) === 1 ? 'lançamento' : 'lançamentos' }}</span>
                        </span>

                        <span class="text-right">
                            <span class="text-xs text-gray-500 dark:text-gray-400">base {{ $brl($person['base']) }} × {{ $person['percent'] !== null ? rtrim(rtrim(number_format($person['percent'], 2, ',', ''), '0'), ',').'%' : '—' }} =</span>
                            <b style="color:#16a34a">{{ $brl($person['commission']) }}</b>
                        </span>
                    </summary>

                    <table class="mt-2 w-full text-left text-xs">
                        <thead>
                            <tr class="text-gray-500 dark:text-gray-400">
                                <th class="py-1">OS</th>
                                <th class="py-1">Faturada</th>
                                <th class="py-1">Cliente</th>
                                <th class="py-1">Item</th>
                                <th class="py-1 text-right">Valor cheio</th>
                                <th class="py-1 text-right">Desconto</th>
                                <th class="py-1 text-right">Base</th>
                                <th class="py-1 text-right">Comissão</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($person['lines'] as $line)
                                <tr class="border-t border-gray-100 text-gray-700 dark:border-gray-800 dark:text-gray-300">
                                    <td class="py-1"><a href="{{ route('admin.service_orders.view', $line['os']) }}" class="text-brandColor hover:underline">#{{ $line['os'] }}</a></td>
                                    <td class="py-1">{{ $line['date']?->format('d/m/Y') }}</td>
                                    <td class="py-1">{{ $line['cliente'] }}</td>
                                    <td class="py-1">{{ $line['descricao'] }}</td>
                                    <td class="py-1 text-right">{{ $brl($line['gross']) }}</td>
                                    <td class="py-1 text-right">{{ $line['discount'] > 0 ? '- '.$brl($line['discount']) : '-' }}</td>
                                    <td class="py-1 text-right">{{ $brl($line['base']) }}</td>
                                    <td class="py-1 text-right">{{ $brl($person['percent'] !== null ? $line['base'] * $person['percent'] / 100 : 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @empty
                <p class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">Nenhuma OS faturada nesse período.</p>
            @endforelse
        </div>
    </div>
</x-admin::layouts>
