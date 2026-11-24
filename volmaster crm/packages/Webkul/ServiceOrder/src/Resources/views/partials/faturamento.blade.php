<div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
        @lang('service_order::app.faturamento.boleto-title')
    </p>

    <div class="mb-3 flex flex-col gap-2">
        @forelse ($serviceOrder->boletos as $boleto)
            <div class="flex items-center gap-3 text-sm">
                <span class="font-medium dark:text-white">{{ core()->formatBasePrice($boleto->valor) }}</span>
                <span class="text-gray-500 dark:text-gray-400">{{ $boleto->vencimento ? core()->formatDate($boleto->vencimento) : '-' }}</span>

                @if ($boleto->gateway === 'asaas')
                    <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-950 dark:text-blue-300">Asaas</span>
                @endif

                <form method="POST" action="{{ route('admin.service_orders.boletos.update', [$serviceOrder->id, $boleto->id]) }}">
                    @csrf
                    @method('PUT')
                    <select name="status" onchange="this.form.submit()" class="rounded-md border border-gray-300 px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                        @foreach (['Pendente', 'Pago', 'Cancelado'] as $status)
                            <option value="{{ $status }}" @selected($boleto->status === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </form>

                @if ($boleto->link_pagamento)
                    <a href="{{ $boleto->link_pagamento }}" target="_blank" class="text-xs text-blue-600 hover:underline">Link</a>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">-</p>
        @endforelse
    </div>

    <form method="POST" action="{{ route('admin.service_orders.boletos.store', $serviceOrder->id) }}" class="flex flex-wrap items-end gap-2">
        @csrf

        <div class="flex flex-col gap-1">
            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.faturamento.valor')</label>
            <input type="text" data-money name="valor" required value="{{ $serviceOrder->grand_total }}" class="w-32 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.faturamento.vencimento')</label>
            <input type="date" name="vencimento" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.faturamento.link_pagamento')</label>
            <input type="text" name="link_pagamento" class="w-64 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
        </div>

        <button type="submit" class="secondary-button">@lang('service_order::app.faturamento.add-boleto-btn')</button>

        <button type="submit" formaction="{{ route('admin.service_orders.boletos.asaas', $serviceOrder->id) }}" class="primary-button">
            @lang('service_order::app.faturamento.gerar-asaas-btn')
        </button>
    </form>

    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        @lang('service_order::app.faturamento.gerar-asaas-hint')
    </p>
</div>
