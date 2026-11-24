<div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
        @lang('service_order::app.diagnostico.title')
    </p>

    {{-- Horários do diagnóstico: o início é carimbado ao iniciar, a conclusão ao salvar/finalizar. --}}
    <div class="mb-3 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
        <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.diagnostico.inicio')</p>
            <p class="text-sm font-medium dark:text-white">{{ $serviceOrder->diagnostico_inicio_em ? core()->formatDate($serviceOrder->diagnostico_inicio_em) : trans('service_order::app.diagnostico.nao-iniciado') }}</p>
        </div>

        <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.diagnostico.conclusao')</p>
            <p class="text-sm font-medium dark:text-white">{{ $serviceOrder->diagnostico_concluido_em ? core()->formatDate($serviceOrder->diagnostico_concluido_em) : trans('service_order::app.diagnostico.nao-concluido') }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.service_orders.diagnostico.update', $serviceOrder->id) }}" class="flex flex-col gap-3">
        @csrf
        @method('PUT')

        <div class="flex flex-col gap-1">
            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.diagnostico.diagnostico')</label>
            <textarea
                name="diagnostico"
                rows="3"
                placeholder="{{ trans('service_order::app.diagnostico.diagnostico-placeholder') }}"
                class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
            >{{ $serviceOrder->diagnostico }}</textarea>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.diagnostico.o_que_fazer')</label>
            <textarea
                name="diagnostico_o_que_fazer"
                rows="3"
                placeholder="{{ trans('service_order::app.diagnostico.o_que_fazer-placeholder') }}"
                class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
            >{{ old('diagnostico_o_que_fazer', $serviceOrder->diagnostico_o_que_fazer) }}</textarea>
        </div>

        {{-- Com o piloto ligado, o técnico é designado no bloco "Fluxo da OS" (registra o evento). --}}
        @unless ($pilot ?? false)
            <div class="flex flex-col gap-1">
                <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.diagnostico.mechanic')</label>
                <select name="diagnostico_mechanic_id" class="w-64 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                    <option value="">-</option>
                    @foreach ($mechanics ?? [] as $mechanic)
                        <option value="{{ $mechanic->id }}" @selected($serviceOrder->diagnostico_mechanic_id == $mechanic->id)>{{ $mechanic->nome }}</option>
                    @endforeach
                </select>
            </div>
        @endunless

        <div>
            <button type="submit" class="secondary-button">
                @lang('service_order::app.diagnostico.save-btn')
            </button>

            @if ($serviceOrder->diagnostico_concluido_em)
                <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">{{ core()->formatDate($serviceOrder->diagnostico_concluido_em) }}</span>
            @endif
        </div>
    </form>

    <div class="mt-3">
        @include('service_order::partials.diagnostico-midia', ['serviceOrder' => $serviceOrder])
    </div>

    @if ($serviceOrder->diagnostico)
        <form method="POST" action="{{ route('admin.service_orders.diagnostico.whatsapp', $serviceOrder->id) }}" class="mt-3">
            @csrf
            <button type="submit" class="secondary-button">
                @lang('service_order::app.diagnostico.whatsapp-btn')
            </button>
        </form>
    @endif
</div>
