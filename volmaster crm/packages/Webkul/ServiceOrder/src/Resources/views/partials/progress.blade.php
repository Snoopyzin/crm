@php
    $stages = \Webkul\ServiceOrder\Models\ServiceOrder::stagesFlow();
    $nextStage = $serviceOrder->nextStage();
    $isTerminal = in_array($serviceOrder->workshop_status, ['Concluída', 'Cancelada']);

    // Campos obrigatórios da etapa (piloto): o botão Avançar mostra o que falta.
    $missing = $nextStage ? app(\Webkul\ServiceOrder\Services\StageGate::class)->missing($serviceOrder, $nextStage) : [];
    $canForce = ! empty($missing) && bouncer()->hasPermission('service_orders.forcar_avanco');
@endphp

{{-- A lista de etapas agora é a barra de passos no topo da OS (abas); aqui fica só o aviso de OS encerrada. --}}
@if ($isTerminal)
    <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full px-3 py-1 text-xs {{ $serviceOrder->workshop_status === 'Cancelada' ? 'bg-red-600 text-white' : 'bg-green-600 text-white' }} font-semibold">
            {{ trans('service_order::app.stages.'.$serviceOrder->workshop_status) }}
        </span>
    </div>
@endif

@if ($nextStage && ! empty($missing))
    <div class="mt-3 rounded-md border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-900 dark:border-yellow-700 dark:bg-yellow-950 dark:text-yellow-200" data-testid="faltando-para-avancar">
        <p class="mb-1 font-semibold">Para avançar para "{{ trans('service_order::app.stages.'.$nextStage) }}" falta:</p>

        <ul class="list-disc pl-5">
            @foreach ($missing as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="mt-3 flex flex-wrap items-center gap-2">
    @if ($nextStage)
        @if (empty($missing))
            <form method="POST" action="{{ route('admin.service_orders.stage.update', $serviceOrder->id) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="stage" value="{{ $nextStage }}" />
                <button type="submit" class="primary-button">
                    {{ trans('service_order::app.stage-index.advance-btn', ['stage' => trans('service_order::app.stages.'.$nextStage)]) }}
                </button>
            </form>
        @else
            <button type="button" class="primary-button cursor-not-allowed opacity-50" disabled title="Complete os itens acima para avançar">
                {{ trans('service_order::app.stage-index.advance-btn', ['stage' => trans('service_order::app.stages.'.$nextStage)]) }}
            </button>

            @if ($canForce)
                <form method="POST" action="{{ route('admin.service_orders.stage.update', $serviceOrder->id) }}" class="flex flex-wrap items-center gap-2" onsubmit="return confirm('Forçar o avanço registra a justificativa e avisa o gerente. Continuar?')">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="stage" value="{{ $nextStage }}" />
                    <input type="hidden" name="forcar" value="1" />
                    <input type="text" name="justificativa" required maxlength="500" placeholder="Justificativa para forçar o avanço" class="w-72 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                    <button type="submit" class="secondary-button">Forçar avanço</button>
                </form>
            @endif
        @endif
    @endif

    @unless ($isTerminal)
        <form method="POST" action="{{ route('admin.service_orders.stage.update', $serviceOrder->id) }}" onsubmit="return confirm('Tem certeza que deseja cancelar esta ordem de serviço?')">
            @csrf
            @method('PUT')
            <input type="hidden" name="stage" value="Cancelada" />
            <button type="submit" class="secondary-button">
                @lang('service_order::app.stage-index.cancel-btn')
            </button>
        </form>
    @endunless
</div>
