@php
    $feedback = $serviceOrder->feedback;
    $history = $serviceOrder->maintenanceHistory()->limit(10)->get();
@endphp

<div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
        @lang('service_order::app.pos_venda.title')
    </p>

    @if (! $feedback || ! $feedback->enviado_em)
        <form method="POST" action="{{ route('admin.service_orders.pos_venda.send', $serviceOrder->id) }}">
            @csrf
            <button type="submit" class="secondary-button">
                @lang('service_order::app.pos_venda.send-btn')
            </button>
        </form>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ trans('service_order::app.pos_venda.sent-at') }}: {{ core()->formatDate($feedback->enviado_em) }}
        </p>

        @if ($feedback->respondido_em)
            <p class="mt-1 text-sm dark:text-white">
                {{ trans('service_order::app.pos_venda.avaliacao') }}:
                <span class="{{ $feedback->avaliacao === 'Bom' ? 'text-green-600' : 'text-red-600' }} font-semibold">{{ $feedback->avaliacao }}</span>
            </p>

            @if ($feedback->comentario)
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">"{{ $feedback->comentario }}"</p>
            @endif
        @else
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">@lang('service_order::app.pos_venda.not-answered')</p>
        @endif
    @endif

    <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
        <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">
            @lang('service_order::app.pos_venda.maintenance-history')
        </p>

        @forelse ($history as $pastOrder)
            <a href="{{ route('admin.service_orders.view', $pastOrder->id) }}" class="block border-b border-gray-100 py-1.5 text-sm text-gray-600 hover:text-blue-600 dark:border-gray-900 dark:text-gray-400">
                #{{ $pastOrder->id }} &middot; {{ $pastOrder->identificacao() }} &middot; {{ core()->formatDate($pastOrder->created_at) }} &middot; {{ trans('service_order::app.stages.'.$pastOrder->workshop_status) }}
            </a>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">@lang('service_order::app.pos_venda.no-history')</p>
        @endforelse
    </div>
</div>
