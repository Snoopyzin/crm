@php
    $items = $serviceOrder->items;

    $grossTotal = round($items->sum(fn ($item) => $item->gross_total), 2);

    // Older orders may still carry a whole-order discount: pre-spread it
    // across the items so saving keeps the same final total.
    $legacyDiscount = round(max(0, $items->sum(fn ($item) => $item->line_total) - (float) $serviceOrder->grand_total), 2);

    $initial = [];

    foreach ($items as $item) {
        $initial[$item->id] = $item->discount_total;
    }

    if ($legacyDiscount > 0 && $grossTotal > 0) {
        foreach ($items as $item) {
            $initial[$item->id] = round($initial[$item->id] + $legacyDiscount * $item->gross_total / $grossTotal, 2);
        }
    }
@endphp

<div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">
        @lang('service_order::app.negociacao.title')
    </p>

    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
        Informe o desconto geral e clique em <b>Distribuir</b>: ele é dividido entre os itens proporcionalmente ao valor de cada um.
        Depois você pode ajustar o desconto de qualquer item -- isso reflete no valor que conta pra comissão de cada um.
    </p>

    <form method="POST" action="{{ route('admin.service_orders.negociacao.update', $serviceOrder->id) }}" class="flex flex-col gap-4" id="negociacao-form">
        @csrf
        @method('PUT')

        @if ($items->isNotEmpty())
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-gray-500 dark:text-gray-400">Desconto geral</label>

                    <div class="flex">
                        <input type="number" step="0.01" min="0" id="desconto-geral" class="w-28 rounded-l-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" placeholder="0,00" />

                        <select id="desconto-tipo" class="rounded-r-md border border-l-0 border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                            <option value="valor">R$</option>
                            <option value="percent">%</option>
                        </select>
                    </div>
                </div>

                <button type="button" id="distribuir-desconto" class="secondary-button">Distribuir entre os itens</button>

                <button type="button" id="zerar-desconto" class="text-xs text-gray-500 hover:underline dark:text-gray-400">Zerar descontos</button>
            </div>

            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="pb-2">Item</th>
                        <th class="pb-2">Executado por</th>
                        <th class="pb-2 text-right">Valor</th>
                        <th class="pb-2 text-right">Desconto (R$)</th>
                        <th class="pb-2 text-right">%</th>
                        <th class="pb-2 text-right">Valor final</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($items as $item)
                        <tr class="border-b border-gray-100 text-gray-800 dark:border-gray-900 dark:text-gray-200" data-gross="{{ $item->gross_total }}">
                            <td class="py-1.5">
                                {{ $item->name }}
                                <span class="text-xs text-gray-400">({{ trans('service_order::app.form.tipo-options.'.$item->tipo) }})</span>
                            </td>
                            <td class="py-1.5">
                                <select name="item_funcionarios[{{ $item->id }}]" class="rounded-md border border-gray-300 px-1.5 py-1 text-xs dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                    <option value="">-</option>
                                    @foreach ($mechanics as $mechanic)
                                        <option value="{{ $mechanic->id }}" @selected(($item->funcionario_id ?: $serviceOrder->mechanic_id) == $mechanic->id)>{{ $mechanic->nome }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="py-1.5 text-right">{{ core()->formatBasePrice($item->gross_total) }}</td>
                            <td class="py-1.5 text-right">
                                <input
                                    type="text"
                                    data-money
                                    name="item_discounts[{{ $item->id }}]"
                                    value="{{ number_format($initial[$item->id], 2, '.', '') }}"
                                    class="item-discount w-28 rounded-md border border-gray-300 px-2 py-1 text-right text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                />
                            </td>
                            <td class="item-percent py-1.5 text-right text-xs text-gray-500 dark:text-gray-400"></td>
                            <td class="item-net py-1.5 text-right font-medium"></td>
                        </tr>
                    @endforeach
                </tbody>

                <tfoot>
                    <tr class="text-gray-800 dark:text-white">
                        <td class="pt-2 font-semibold" colspan="2">Total</td>
                        <td class="pt-2 text-right">{{ core()->formatBasePrice($grossTotal) }}</td>
                        <td class="pt-2 text-right font-semibold" id="desconto-total" style="color:#dc2626"></td>
                        <td class="pt-2 text-right text-xs text-gray-500 dark:text-gray-400" id="desconto-total-percent"></td>
                        <td class="pt-2 text-right font-bold" id="valor-final"></td>
                    </tr>
                </tfoot>
            </table>
        @endif

        <div class="flex flex-col gap-1">
            <label class="text-xs text-gray-500 dark:text-gray-400">Condição de pagamento (sai na OS impressa)</label>
            <input
                type="text"
                name="condicao_pagamento"
                list="condicoes-pagamento"
                value="{{ $serviceOrder->condicao_pagamento }}"
                placeholder="Ex: PIX à vista, Boleto 30/60 dias, Cartão 3x"
                class="max-w-md rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
            />
            <datalist id="condicoes-pagamento">
                <option value="À vista (PIX)">
                <option value="À vista (dinheiro)">
                <option value="Cartão de débito">
                <option value="Cartão de crédito à vista">
                <option value="Cartão de crédito 3x">
                <option value="Boleto 30 dias">
                <option value="Boleto 30/60 dias">
                <option value="Boleto 30/60/90 dias">
                <option value="Faturado (conforme contrato)">
            </datalist>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.negociacao.observacoes')</label>
            <textarea
                name="negociacao_observacoes"
                rows="2"
                class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
            >{{ $serviceOrder->negociacao_observacoes }}</textarea>
        </div>

        <div>
            <button type="submit" class="secondary-button">
                @lang('service_order::app.negociacao.save-btn')
            </button>
        </div>
    </form>
</div>

@if ($items->isNotEmpty())
    @push('scripts')
        <script>
            // Document-level listeners + lookups at event time: the admin
            // layout is a Vue app that re-renders this markup on mount, so
            // elements grabbed (or listeners attached) earlier would be stale.
            (function () {
                var brl = function (value) { return value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };
                var cents = function (value) { return Math.round(value * 100); };
                var money = function (value) { var n = window.vmMoney ? window.vmMoney.parse(value) : parseFloat(value); return isNaN(n) ? 0 : n; };
                var setMoney = function (input, value) { if (window.vmMoney) { window.vmMoney.set(input, value); } else { input.value = value.toFixed(2); } };
                var byId = function (id) { return document.getElementById(id); };

                function rows() {
                    var form = byId('negociacao-form');

                    return form ? Array.prototype.slice.call(form.querySelectorAll('tbody tr[data-gross]')) : [];
                }

                function refresh() {
                    if (! byId('valor-final')) {
                        return;
                    }

                    var gross = 0;
                    var discount = 0;

                    rows().forEach(function (row) {
                        var g = parseFloat(row.dataset.gross) || 0;
                        var input = row.querySelector('.item-discount');
                        var d = Math.min(Math.max(money(input.value), 0), g);

                        gross += g;
                        discount += d;

                        row.querySelector('.item-percent').textContent = g > 0 ? (d / g * 100).toFixed(1).replace('.', ',') + '%' : '';
                        row.querySelector('.item-net').textContent = brl(g - d);
                        input.style.borderColor = money(input.value) > g ? '#dc2626' : '';
                    });

                    byId('desconto-total').textContent = discount > 0 ? '- ' + brl(discount) : brl(0);
                    byId('desconto-total-percent').textContent = gross > 0 ? (discount / gross * 100).toFixed(1).replace('.', ',') + '%' : '';
                    byId('valor-final').textContent = brl(gross - discount);
                }

                // Proportional split in cents; the rounding remainder goes to
                // the biggest item so the parts always add up exactly.
                function distribute() {
                    var list = rows();
                    var value = money(byId('desconto-geral').value);
                    var grosses = list.map(function (row) { return cents(parseFloat(row.dataset.gross) || 0); });
                    var totalGross = grosses.reduce(function (a, b) { return a + b; }, 0);

                    if (totalGross <= 0) {
                        return;
                    }

                    var totalDiscount = byId('desconto-tipo').value === 'percent'
                        ? Math.round(totalGross * Math.min(value, 100) / 100)
                        : Math.min(cents(value), totalGross);

                    var shares = grosses.map(function (g) { return Math.floor(totalDiscount * g / totalGross); });
                    var remainder = totalDiscount - shares.reduce(function (a, b) { return a + b; }, 0);

                    shares[grosses.indexOf(Math.max.apply(null, grosses))] += remainder;

                    list.forEach(function (row, i) {
                        setMoney(row.querySelector('.item-discount'), Math.min(shares[i], grosses[i]) / 100);
                    });

                    refresh();
                }

                document.addEventListener('click', function (event) {
                    if (event.target.closest('#distribuir-desconto')) {
                        distribute();
                    } else if (event.target.closest('#zerar-desconto')) {
                        rows().forEach(function (row) { setMoney(row.querySelector('.item-discount'), 0); });
                        byId('desconto-geral').value = '';
                        refresh();
                    }
                });

                document.addEventListener('keydown', function (event) {
                    if (event.target.id === 'desconto-geral' && event.key === 'Enter') {
                        event.preventDefault();
                        distribute();
                    }
                });

                document.addEventListener('input', function (event) {
                    if (event.target.classList && event.target.classList.contains('item-discount')) {
                        refresh();
                    }
                });

                window.addEventListener('load', function () { setTimeout(refresh, 0); });
                setTimeout(refresh, 300);
            })();
        </script>
    @endpush
@endif
