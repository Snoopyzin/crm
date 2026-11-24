<div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
        @lang('service_order::app.orcamento.title')
    </p>

    {{-- Horários do orçamento: o início é a hora em que o caminhão passou para esta etapa; a conclusão, a do envio ao cliente. --}}
    @php
        $orcamentoInicio = $serviceOrder->etapaIniciadaEm(\Webkul\ServiceOrder\Models\ServiceOrder::STAGE_ORCAMENTO);
    @endphp

    <div class="mb-4 grid grid-cols-2 gap-4 max-sm:grid-cols-1">
        <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.orcamento.inicio')</p>
            <p class="text-sm font-medium dark:text-white">{{ $orcamentoInicio ? core()->formatDate($orcamentoInicio) : trans('service_order::app.orcamento.nao-iniciado') }}</p>
        </div>

        <div>
            <p class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.orcamento.conclusao')</p>
            <p class="text-sm font-medium dark:text-white">{{ $serviceOrder->orcamento_enviado_em ? core()->formatDate($serviceOrder->orcamento_enviado_em) : trans('service_order::app.orcamento.nao-concluido') }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.service_orders.update', $serviceOrder->id) }}" class="mb-4 flex items-end gap-2">
        @csrf
        @method('PUT')
        <input type="hidden" name="person_id" value="{{ $serviceOrder->person_id }}" />
        <input type="hidden" name="vehicle_id" value="{{ $serviceOrder->vehicle_id }}" />
        <input type="hidden" name="servico" value="{{ $serviceOrder->subject }}" />

        <div class="flex flex-col gap-1">
            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.orcamento.tempo_servico')</label>
            <input
                type="text"
                name="tempo_servico"
                value="{{ $serviceOrder->tempo_servico }}"
                placeholder="{{ trans('service_order::app.orcamento.tempo_servico-placeholder') }}"
                class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
            />
        </div>

        <button type="submit" class="secondary-button">
            @lang('service_order::app.checklist.add-btn')
        </button>
    </form>

    @php
        $numbers = collect($serviceOrder->person?->contact_numbers ?? [])->filter(fn ($number) => ! empty($number['value']));
        $whatsapp = ($numbers->first(fn ($number) => stripos($number['label'] ?? '', 'whats') !== false) ?? $numbers->first())['value'] ?? null;
        $whatsappValid = (bool) \Webkul\WhatsApp\Support\BrazilianPhone::e164($whatsapp);
    @endphp

    <p class="mb-2 text-xs {{ $whatsappValid ? 'text-gray-500 dark:text-gray-400' : '' }}" @unless ($whatsappValid) style="color:#dc2626" @endunless>
        @if (! $whatsapp)
            O cliente não tem WhatsApp cadastrado -- adicione na ficha do cliente no formato +55 (DDD) NÚMERO.
        @elseif (! $whatsappValid)
            WhatsApp do cliente inválido: "{{ $whatsapp }}" -- corrija na ficha do cliente no formato +55 (DDD) NÚMERO.
        @else
            WhatsApp será enviado para <b>{{ \Webkul\WhatsApp\Support\BrazilianPhone::format($whatsapp) }}</b>
        @endif
    </p>

    <div class="flex items-center gap-2">
        <form method="POST" action="{{ route('admin.service_orders.orcamento.mail', $serviceOrder->id) }}">
            @csrf
            <button type="submit" class="secondary-button">
                @lang('service_order::app.orcamento.mail-btn')
            </button>
        </form>

        <form method="POST" action="{{ route('admin.service_orders.orcamento.whatsapp', $serviceOrder->id) }}">
            @csrf
            <button type="submit" class="secondary-button">
                @lang('service_order::app.orcamento.whatsapp-btn')
            </button>
        </form>
    </div>
</div>
