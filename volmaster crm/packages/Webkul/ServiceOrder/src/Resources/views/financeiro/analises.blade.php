@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $reais = fn ($v) => $v === null ? '—' : 'R$ '.number_format((float) $v, 2, ',', '.');
    $card = 'box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900';
    $base = $simulador['base'];
@endphp

<x-admin::layouts>
    <x-slot:title>
        Margem, simulador e caixa projetado
    </x-slot>

    <div class="flex flex-col gap-4">
        @include('service_order::financeiro._abas')

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Análises de {{ $periodo }}</div>
            <form method="GET"><input type="month" name="periodo" value="{{ $periodo }}" onchange="this.form.submit()" class="{{ $campo }}" /></form>
        </div>

        <div class="{{ $card }} overflow-x-auto">
            <p class="mb-2 text-base font-semibold dark:text-white">Margem por OS (da pior para a melhor)</p>
            <p class="mb-2 text-xs text-gray-500">Receita − impostos − custo das peças − terceiros − horas reais × custo da hora.</p>
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800"><th class="pb-2">OS</th><th class="pb-2">Cliente</th><th class="pb-2 text-right">Receita</th><th class="pb-2 text-right">Peças</th><th class="pb-2 text-right">Terceiros</th><th class="pb-2 text-right">Horas</th><th class="pb-2 text-right">Margem</th><th class="pb-2 text-right">%</th></tr></thead>
                <tbody>
                    @forelse ($margens as $m)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1.5"><a class="text-blue-600 hover:underline" href="{{ route('admin.service_orders.view', $m['os']) }}">#{{ $m['os'] }}</a> <span class="text-xs text-gray-500">{{ $m['placa'] }}</span></td>
                            <td class="py-1.5 dark:text-white">{{ $m['cliente'] }}</td>
                            <td class="py-1.5 text-right">{{ $reais($m['receita']) }}</td>
                            <td class="py-1.5 text-right">{{ $reais($m['custo_pecas']) }}</td>
                            <td class="py-1.5 text-right">{{ $reais($m['terceiros']) }}</td>
                            <td class="py-1.5 text-right">{{ number_format($m['horas_reais'], 1, ',', '.') }} h</td>
                            <td class="py-1.5 text-right font-semibold {{ $m['margem'] < 0 ? 'text-red-600' : 'text-green-700' }}">{{ $reais($m['margem']) }}</td>
                            <td class="py-1.5 text-right">{{ $m['margem_pct'] !== null ? number_format($m['margem_pct'], 1, ',', '.').'%' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-3 text-gray-500">Nenhuma OS faturada neste mês.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="{{ $card }} overflow-x-auto">
            <p class="mb-2 text-base font-semibold dark:text-white">Clientes e frotas por margem</p>
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800"><th class="pb-2">Cliente</th><th class="pb-2 text-right">OS</th><th class="pb-2 text-right">Receita</th><th class="pb-2 text-right">Margem</th><th class="pb-2 text-right">%</th><th class="pb-2 text-right">Prazo médio</th><th class="pb-2 text-right">Inadimplência</th></tr></thead>
                <tbody>
                    @foreach ($clientes as $c)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1.5 dark:text-white">{{ $c['cliente'] }}</td><td class="py-1.5 text-right">{{ $c['os'] }}</td><td class="py-1.5 text-right">{{ $reais($c['receita']) }}</td>
                            <td class="py-1.5 text-right font-semibold">{{ $reais($c['margem']) }}</td><td class="py-1.5 text-right">{{ $c['margem_pct'] !== null ? number_format($c['margem_pct'], 1, ',', '.').'%' : '—' }}</td>
                            <td class="py-1.5 text-right">{{ $c['prazo_medio_dias'] !== null ? number_format($c['prazo_medio_dias'], 1, ',', '.').' dias' : '—' }}</td>
                            <td class="py-1.5 text-right {{ $c['inadimplencia_pct'] > 0 ? 'text-red-600' : '' }}">{{ number_format($c['inadimplencia_pct'], 1, ',', '.') }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="{{ $card }}" id="simulador">
            <p class="mb-2 text-base font-semibold dark:text-white">Simulador "e se" (nada é gravado)</p>
            <div class="flex flex-wrap items-end gap-3 text-sm">
                @foreach (['preco_hora' => 'Preço da hora (R$)', 'produtividade' => 'Produtividade (%)', 'tecnicos' => 'Nº de técnicos', 'margem_pecas' => 'Margem de peças (%)', 'despesa_nova' => 'Despesa nova (R$/mês)'] as $chave => $nome)
                    <div class="flex flex-col gap-1"><label class="text-xs text-gray-500">{{ $nome }}</label><input type="number" step="0.01" id="sim-{{ $chave }}" value="{{ $chave === 'despesa_nova' ? 0 : $base[$chave] }}" class="w-32 {{ $campo }}" oninput="simular()" /></div>
                @endforeach
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2 text-sm md:grid-cols-4 dark:text-gray-200">
                <p>Receita de mão de obra<br><b id="sim-r-mo">—</b></p><p>Receita de peças<br><b id="sim-r-pecas">—</b></p>
                <p>Margem de contribuição<br><b id="sim-mc">—</b></p><p>Resultado operacional<br><b id="sim-res">—</b></p>
            </div>
        </div>

        <div class="{{ $card }}">
            <p class="mb-2 text-base font-semibold dark:text-white">Fluxo de caixa projetado (90 dias)</p>
            <p class="text-sm dark:text-gray-200">Saldo conciliado {{ $reais($caixa['saldo_inicial']) }} · em 30 dias <b>{{ $reais($caixa['em_30_dias']) }}</b> · 60 dias <b>{{ $reais($caixa['em_60_dias']) }}</b> · 90 dias <b>{{ $reais($caixa['em_90_dias']) }}</b></p>
            @if ($caixa['fica_negativo']) <p class="mt-1 text-sm font-semibold text-red-600">Atenção: o caixa fica negativo em algum momento (menor saldo previsto {{ $reais($caixa['menor_saldo']) }}).</p> @endif
            <div class="mt-3 flex items-end gap-1" style="height: 120px;">
                @php $maior = max(1, collect($caixa['semanas'])->max(fn ($s) => abs($s['saldo']))); @endphp
                @foreach ($caixa['semanas'] as $semana)
                    <div class="flex flex-1 flex-col items-center justify-end" title="até {{ $semana['ate'] }}: {{ $reais($semana['saldo']) }}">
                        <div class="w-full rounded-t {{ $semana['saldo'] < 0 ? 'bg-red-500' : 'bg-blue-500' }}" style="height: {{ max(2, abs($semana['saldo']) / $maior * 100) }}px;"></div>
                        <span class="text-[10px] text-gray-500">{{ $semana['ate'] }}</span>
                    </div>
                @endforeach
            </div>
            <p class="mt-1 text-xs text-gray-500">Saldo + a receber (cobranças pendentes) − a pagar (contas em aberto) − folha (salários dos colaboradores) por semana.</p>
        </div>
    </div>

    <script>
        var BASE = @json($base);

        function num(id) { return parseFloat(document.getElementById('sim-' + id).value) || 0; }
        function brl(v) { return 'R$ ' + v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

        function simular() {
            var horasVendaveis = num('tecnicos') * BASE.horas_mes * (num('produtividade') / 100);
            var receitaMO = horasVendaveis * num('preco_hora');
            var margem = num('margem_pecas') / 100;
            var receitaPecas = margem < 1 ? BASE.custo_pecas / (1 - margem) : BASE.receita_pecas;
            var bruta = receitaMO + receitaPecas;
            var impostos = bruta * BASE.impostos_pct / 100;
            var comissoes = bruta * BASE.comissoes_pct / 100;
            var despVar = receitaPecas * BASE.desp_var_pecas_pct / 100;
            var mc = bruta - impostos - BASE.custo_pecas - BASE.terceiros - comissoes - despVar;
            var resultado = mc - BASE.pessoal - BASE.despesas_fixas - num('despesa_nova');

            document.getElementById('sim-r-mo').textContent = brl(receitaMO);
            document.getElementById('sim-r-pecas').textContent = brl(receitaPecas);
            document.getElementById('sim-mc').textContent = brl(mc);
            var el = document.getElementById('sim-res');
            el.textContent = brl(resultado);
            el.style.color = resultado < 0 ? '#dc2626' : '#15803d';
        }

        simular();
    </script>
</x-admin::layouts>
