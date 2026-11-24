@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $reais = fn ($v) => $v === null ? '—' : 'R$ '.number_format((float) $v, 0, ',', '.');
    $card = 'box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900';
    $meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
@endphp

<x-admin::layouts>
    <x-slot:title>
        Orçamento anual e Marco Zero
    </x-slot>

    <div class="flex flex-col gap-4">
        @include('service_order::financeiro._abas')

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Orçamento anual {{ $ano }}</div>
            <form method="GET"><input type="number" name="ano" value="{{ $ano }}" min="2020" max="2100" onchange="this.form.submit()" class="w-24 {{ $campo }}" /></form>
        </div>

        <form method="POST" action="{{ route('admin.financeiro.orcamento.salvar') }}" class="{{ $card }} overflow-x-auto">
            @csrf
            <input type="hidden" name="ano" value="{{ $ano }}" />
            <p class="mb-2 text-xs text-gray-500">Meta mensal por linha da DRE (campo de cima) e o realizado (abaixo, em cinza). Deixe em branco para não ter meta.</p>

            <table class="w-full text-left text-xs">
                <thead><tr class="border-b border-gray-200 uppercase text-gray-500 dark:border-gray-800"><th class="pb-2">Linha</th>@foreach ($meses as $nome)<th class="pb-2 text-center">{{ $nome }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($linhas as $chave => $nome)
                        <tr class="border-b border-gray-100 align-top dark:border-gray-800">
                            <td class="py-1.5 font-semibold dark:text-white">{{ $nome }}</td>
                            @foreach (range(1, 12) as $mes)
                                <td class="px-0.5 py-1.5">
                                    <input type="text" name="meta[{{ $chave }}][{{ $mes }}]" value="{{ isset($metas[$chave][$mes]) ? number_format($metas[$chave][$mes], 2, ',', '.') : '' }}" class="w-20 text-right {{ $campo }}" />
                                    @if ($real[$mes])
                                        @php $valor = $real[$mes][$chave] ?? null; $meta = $metas[$chave][$mes] ?? null; @endphp
                                        <span class="block text-right {{ $meta !== null && $valor !== null ? ($valor >= $meta ? 'text-green-700' : 'text-red-600') : 'text-gray-500' }}">{{ $reais($valor) }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-3"><button type="submit" class="primary-button">Salvar o orçamento</button></div>
        </form>

        <div class="{{ $card }}">
            <p class="mb-1 text-base font-semibold dark:text-white">Marco Zero</p>
            <p class="mb-3 text-xs text-gray-500">Os números da oficina antes da mentoria, registrados no encontro de abertura. O Painel do Dono e a visão do mentor mostram a evolução contra eles.</p>

            @if ($marcoZero)
                @php $mz = $marcoZero; $a = $atual; @endphp
                <table class="mb-4 text-sm dark:text-gray-200">
                    <tr class="text-xs uppercase text-gray-500"><th class="pr-6 text-left">Indicador</th><th class="pr-6 text-right">Marco Zero ({{ \Carbon\Carbon::parse($mz->data)->format('d/m/Y') }})</th><th class="text-right">Hoje</th></tr>
                    <tr><td class="pr-6">Faturamento</td><td class="pr-6 text-right">{{ $reais($mz->faturamento) }}</td><td class="text-right">{{ $reais($a['dre']['faturamento']) }}</td></tr>
                    <tr><td class="pr-6">Resultado</td><td class="pr-6 text-right">{{ $reais($mz->resultado) }}</td><td class="text-right">{{ $reais($a['dre']['resultado_final']) }}</td></tr>
                    <tr><td class="pr-6">Hora cobrada</td><td class="pr-6 text-right">{{ $reais($mz->hora_cobrada) }}</td><td class="text-right">{{ $reais($a['hora']['hora_cobrada']) }}</td></tr>
                    <tr><td class="pr-6">Preço mínimo da hora</td><td class="pr-6 text-right">{{ $reais($mz->preco_minimo_hora) }}</td><td class="text-right">{{ $reais($a['hora']['preco_minimo']) }}</td></tr>
                    <tr><td class="pr-6">Produtividade</td><td class="pr-6 text-right">{{ $mz->produtividade !== null ? number_format($mz->produtividade, 1, ',', '.').'%' : '—' }}</td><td class="text-right">{{ $a['indicadores']['produtividade'] !== null ? number_format($a['indicadores']['produtividade'], 1, ',', '.').'%' : '—' }}</td></tr>
                    <tr><td class="pr-6">Retrabalho</td><td class="pr-6 text-right">{{ $mz->retrabalho !== null ? number_format($mz->retrabalho, 1, ',', '.').'%' : '—' }}</td><td class="text-right">{{ $a['indicadores']['retrabalho'] !== null ? number_format($a['indicadores']['retrabalho'], 1, ',', '.').'%' : '—' }}</td></tr>
                </table>
            @endif

            <form method="POST" action="{{ route('admin.financeiro.marco_zero') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex flex-col gap-1"><label class="text-xs text-gray-500">Data</label><input type="date" name="data" required value="{{ today()->toDateString() }}" class="{{ $campo }}" /></div>
                @foreach (['faturamento' => 'Faturamento (R$)', 'resultado' => 'Resultado (R$)', 'hora_cobrada' => 'Hora cobrada (R$)', 'preco_minimo_hora' => 'Preço mínimo da hora (R$)', 'margem_pecas' => 'Margem de peças (%)', 'produtividade' => 'Produtividade (%)', 'retrabalho' => 'Retrabalho (%)'] as $chave => $nome)
                    <div class="flex flex-col gap-1"><label class="text-xs text-gray-500">{{ $nome }}</label><input type="number" step="0.01" name="{{ $chave }}" class="w-32 {{ $campo }}" /></div>
                @endforeach
                <button type="submit" class="primary-button">Registrar Marco Zero</button>
            </form>
        </div>
    </div>
</x-admin::layouts>
