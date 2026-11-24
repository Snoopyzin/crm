@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
@endphp

<div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
        @lang('service_order::app.teste.title')
    </p>

    @if (! $serviceOrder->teste_iniciado_em)
        <form method="POST" action="{{ route('admin.service_orders.teste.start', $serviceOrder->id) }}" class="flex flex-wrap items-end gap-2">
            @csrf
            <select name="teste_mechanic_id" class="w-64 {{ $campo }}">
                <option value="">-</option>
                @foreach ($mechanics ?? [] as $mechanic)
                    <option value="{{ $mechanic->id }}">{{ $mechanic->nome }}{{ $mechanic->cnh_categoria ? ' (CNH '.$mechanic->cnh_categoria.')' : '' }}</option>
                @endforeach
            </select>
            <input type="number" min="0" name="teste_km_saida" placeholder="Km de saída" value="{{ $serviceOrder->km_entrada }}" class="w-32 {{ $campo }}" />
            <button type="submit" class="primary-button">@lang('service_order::app.teste.start-btn')</button>
        </form>
    @elseif (! $serviceOrder->teste_finalizado_em)
        <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
            {{ $serviceOrder->testeMechanic->nome ?? '-' }} &middot; {{ core()->formatDate($serviceOrder->teste_iniciado_em) }}
            @if ($serviceOrder->teste_km_saida !== null) &middot; saiu com {{ number_format($serviceOrder->teste_km_saida, 0, ',', '.') }} km @endif
        </p>

        <form method="POST" action="{{ route('admin.service_orders.teste.finish', $serviceOrder->id) }}" class="flex flex-wrap items-end gap-2">
            @csrf
            <input type="number" min="0" name="teste_km_volta" placeholder="Km de volta" class="w-32 {{ $campo }}" />
            <select name="teste_resultado" class="{{ $campo }}">
                <option value="aprovado">Aprovado</option>
                <option value="reprovado">Reprovado</option>
            </select>
            <textarea name="teste_observacoes" rows="2" placeholder="{{ trans('service_order::app.teste.observacoes') }}" class="w-96 {{ $campo }}"></textarea>
            <button type="submit" class="primary-button">@lang('service_order::app.teste.finish-btn')</button>
        </form>
    @else
        <p class="text-sm text-gray-800 dark:text-white">
            {{ $serviceOrder->testeMechanic->nome ?? '-' }} &middot; {{ core()->formatDate($serviceOrder->teste_iniciado_em) }} - {{ core()->formatDate($serviceOrder->teste_finalizado_em) }}
        </p>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            @if ($serviceOrder->teste_km_saida !== null && $serviceOrder->teste_km_volta !== null)
                Rodou {{ number_format(max(0, $serviceOrder->teste_km_volta - $serviceOrder->teste_km_saida), 0, ',', '.') }} km
                ({{ number_format($serviceOrder->teste_km_saida, 0, ',', '.') }} → {{ number_format($serviceOrder->teste_km_volta, 0, ',', '.') }})
            @endif
            @if ($serviceOrder->teste_resultado) &middot; {{ ucfirst($serviceOrder->teste_resultado) }} @endif
        </p>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $serviceOrder->teste_observacoes }}</p>
    @endif
</div>
