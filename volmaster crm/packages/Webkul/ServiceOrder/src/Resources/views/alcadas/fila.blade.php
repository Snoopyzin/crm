@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $rotulo = 'text-xs text-gray-500 dark:text-gray-400';
    $fmt = fn ($pedido) => $pedido->valor === null ? null : (($temas[$pedido->tema]['unidade'] ?? '') === 'percent'
        ? \Webkul\ServiceOrder\Services\Indicators\IndicatorFormatter::value($pedido->valor, 'percent')
        : (($temas[$pedido->tema]['unidade'] ?? '') === 'brl' ? \Webkul\ServiceOrder\Services\Indicators\IndicatorFormatter::value($pedido->valor, 'brl') : null));
    $ehGerente = $nivel === \Webkul\ServiceOrder\Services\Alcada\AlcadaCatalog::NIVEL_GERENTE;
    $ehDono = $nivel === \Webkul\ServiceOrder\Services\Alcada\AlcadaCatalog::NIVEL_DONO;
@endphp

<x-admin::layouts>
    <x-slot:title>
        Fila de alçadas
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Fila de alçadas</div>
            <p class="text-sm text-gray-500">
                Toda ação acima da alçada de quem pede fica bloqueada até a aprovação. O gerente decide o que está na alçada dele ou encaminha ao dono, que tem 24 horas para responder.
            </p>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-3 text-base font-semibold dark:text-white">
                {{ $ehDono ? 'Aguardando a decisão do dono' : 'Aguardando decisão' }} ({{ $pedidos->count() }})
            </p>

            @forelse ($pedidos as $pedido)
                <div class="mb-3 rounded-md border border-gray-200 p-3 dark:border-gray-800" style="border-left: 4px solid {{ $pedido->status === 'encaminhado' ? '#dc2626' : '#f59e0b' }};">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold dark:text-white">
                                {{ $pedido->tema_nome }}
                                @if ($fmt($pedido)) <span class="ml-1 text-gray-500">&middot; {{ $fmt($pedido) }}</span> @endif
                                <span class="ml-1 rounded px-1.5 py-0.5 text-xs {{ $pedido->status === 'encaminhado' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-800' }}">
                                    {{ $pedido->status === 'encaminhado' ? 'com o dono' : 'com o gerente' }}
                                </span>
                            </p>
                            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $pedido->descricao }}</p>
                            <p class="text-xs text-gray-500">
                                Pedido por {{ $pedido->solicitante?->name ?? 'sistema' }} em {{ $pedido->created_at->format('d/m H:i') }}
                                @if ($pedido->service_order_id)
                                    &middot; <a class="text-blue-600 hover:underline" href="{{ route('admin.service_orders.view', $pedido->service_order_id) }}">OS #{{ $pedido->service_order_id }}</a>
                                @endif
                                @if ($pedido->prazo_dono_em)
                                    &middot; <span class="{{ $pedido->prazo_dono_em->isPast() ? 'font-semibold text-red-600' : '' }}">prazo do dono: {{ $pedido->prazo_dono_em->format('d/m H:i') }}</span>
                                @endif
                            </p>
                        </div>

                        <form method="POST" action="{{ route('admin.mentoria.alcadas.decidir', $pedido->id) }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <input type="text" name="comentario" placeholder="Comentário (obrigatório para recusar)" maxlength="500" class="w-64 {{ $campo }}" />
                            @if ($ehDono || ($ehGerente && $pedido->nivel_necessario === 'gerente'))
                                <button type="submit" name="decisao" value="aprovar" class="primary-button">Aprovar</button>
                            @endif
                            <button type="submit" name="decisao" value="recusar" class="secondary-button">Recusar</button>
                            @if ($ehGerente && $pedido->status === 'pendente')
                                <button type="submit" name="decisao" value="encaminhar" class="secondary-button">Encaminhar ao dono</button>
                            @endif
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nenhum pedido aguardando decisão.</p>
            @endforelse
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-3 text-base font-semibold dark:text-white">Pedir uma decisão</p>
            <p class="mb-3 text-xs text-gray-500">Para assuntos que não são bloqueados automaticamente: contratação, investimento, mudança de processo, protesto, horas extras...</p>

            <form method="POST" action="{{ route('admin.mentoria.alcadas.solicitar') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex flex-col gap-1">
                    <label class="{{ $rotulo }}">Assunto</label>
                    <select name="tema" required class="w-72 {{ $campo }}">
                        @foreach ($temas as $chave => $tema)
                            <option value="{{ $chave }}">{{ $tema['nome'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="{{ $rotulo }}">O que precisa ser decidido</label>
                    <input type="text" name="descricao" required maxlength="500" class="w-96 {{ $campo }}" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="{{ $rotulo }}">Valor (R$ ou %, se houver)</label>
                    <input type="number" step="0.01" min="0" name="valor" class="w-32 {{ $campo }}" />
                </div>
                <button type="submit" class="primary-button">Enviar pedido</button>
            </form>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-3 text-base font-semibold dark:text-white">Últimas decisões</p>
            @forelse ($recentes as $pedido)
                <p class="border-b border-gray-100 py-1.5 text-sm dark:border-gray-800 dark:text-gray-200">
                    <span class="rounded px-1.5 py-0.5 text-xs {{ $pedido->status === 'recusado' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' }}">{{ $pedido->status === 'recusado' ? 'Recusado' : 'Aprovado' }}</span>
                    {{ $pedido->tema_nome }}{{ $pedido->service_order_id ? ' · OS #'.$pedido->service_order_id : '' }}
                    <span class="text-xs text-gray-500">por {{ $pedido->decisor?->name }} em {{ $pedido->decidido_em?->format('d/m H:i') }}{{ $pedido->comentario ? ' — '.$pedido->comentario : '' }}</span>
                </p>
            @empty
                <p class="text-sm text-gray-500">Nenhuma decisão ainda.</p>
            @endforelse
        </div>
    </div>
</x-admin::layouts>
