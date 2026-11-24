<x-admin::layouts>
    <x-slot:title>
        Alertas da oficina
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Alertas da oficina</div>

            <form method="POST" action="{{ route('admin.workshop.alerts.read_all') }}">
                @csrf
                <button type="submit" class="secondary-button">Marcar todos como lidos</button>
            </form>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            @forelse ($alerts as $alert)
                <div class="flex flex-wrap items-start justify-between gap-2 border-b border-gray-100 py-3 dark:border-gray-800" style="border-left: 4px solid {{ $alert->critico ? '#dc2626' : '#0E90D9' }}; padding-left: 10px;">
                    <div class="max-w-3xl">
                        <p class="text-sm font-semibold dark:text-white">
                            @if ($alert->critico)
                                <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-700">CRÍTICO</span>
                            @else
                                <span class="rounded bg-blue-100 px-1.5 py-0.5 text-xs text-blue-700">Nível {{ $alert->nivel }}</span>
                            @endif

                            {{ $alert->titulo }}

                            @if ($alert->status === 'resolvido')
                                <span class="rounded bg-green-100 px-1.5 py-0.5 text-xs text-green-700">Resolvido</span>
                            @else
                                <span class="rounded bg-yellow-100 px-1.5 py-0.5 text-xs text-yellow-800">Aberto</span>
                            @endif
                        </p>

                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $alert->mensagem }}</p>

                        @if ($alert->mensagem_resolvido)
                            <p class="mt-1 text-sm text-green-700">{{ $alert->mensagem_resolvido }}</p>
                        @endif

                        <p class="mt-1 text-xs text-gray-500">
                            Disparado em {{ $alert->disparado_em->format('d/m/Y H:i') }}
                            @if ($alert->service_order_id)
                                &middot; <a class="text-blue-600 hover:underline" href="{{ route('admin.service_orders.view', $alert->service_order_id) }}">Abrir OS #{{ $alert->service_order_id }}</a>
                            @endif
                        </p>
                    </div>

                    @if ($alert->status === 'aberto' && $alert->critico)
                        <form method="POST" action="{{ route('admin.workshop.alerts.ack', $alert->id) }}">
                            @csrf
                            <button type="submit" class="secondary-button">Ciente</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500">Nenhum alerta por enquanto.</p>
            @endforelse

            <div class="mt-4">{{ $alerts->links() }}</div>
        </div>
    </div>
</x-admin::layouts>
