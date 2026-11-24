@php
    $acoes = app(\Webkul\ServiceOrder\Services\WorkflowActions::class)->availableFor($serviceOrder);
    $pecasOs = $serviceOrder->items()->where('tipo', \Webkul\ServiceOrder\Models\ServiceOrderItem::TIPO_PECA)->get();
    $reclamacoesAbertas = \Illuminate\Support\Facades\DB::table('service_order_reclamacoes')->where('quote_id', $serviceOrder->id)->whereNull('fechada_em')->get();

    $campoCss = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $rotuloCss = 'text-xs text-gray-500 dark:text-gray-400';
@endphp

@if ($acoes)
    <div class="rounded-md border border-gray-200 p-3 lg:col-span-2 dark:border-gray-800" id="acoes-fluxo">
        <p class="mb-3 text-sm font-semibold text-gray-800 dark:text-white">
            Passos desta etapa ({{ $serviceOrder->workshop_status }})
            @if ($serviceOrder->tipo_os && $serviceOrder->tipo_os !== 'normal')
                <span class="ml-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700">{{ str_replace('_', ' ', $serviceOrder->tipo_os) }}</span>
            @endif
        </p>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            @foreach ($acoes as $chave => $acao)
                <form method="POST" action="{{ route('admin.service_orders.workflow.action', [$serviceOrder->id, $chave]) }}" class="flex flex-col gap-2 rounded-md bg-gray-50 p-2 dark:bg-gray-950">
                    @csrf

                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $acao['label'] }}</p>

                    @if (! empty($acao['ajuda']))
                        <p class="{{ $rotuloCss }}">{{ $acao['ajuda'] }}</p>
                    @endif

                    <div class="flex flex-wrap items-end gap-2">
                        @foreach ($acao['campos'] as $nome => $campo)
                            <div class="flex flex-col gap-1">
                                <label class="{{ $rotuloCss }}">{{ $campo['label'] }}</label>

                                @switch($campo['tipo'])
                                    @case('select')
                                        <select name="{{ $nome }}" class="{{ $campoCss }}" @required(empty($campo['opcional']))>
                                            @foreach ($campo['opcoes'] as $valor => $texto)
                                                <option value="{{ $valor }}">{{ $texto }}</option>
                                            @endforeach
                                        </select>
                                        @break

                                    @case('item_peca')
                                        <select name="{{ $nome }}" class="{{ $campoCss }}" required>
                                            <option value="">-</option>
                                            @foreach ($pecasOs as $peca)
                                                <option value="{{ $peca->id }}">{{ $peca->name }}{{ $peca->precificada_em ? ' (precificada)' : '' }}</option>
                                            @endforeach
                                        </select>
                                        @break

                                    @case('reclamacao')
                                        <select name="{{ $nome }}" class="{{ $campoCss }}" required>
                                            @foreach ($reclamacoesAbertas as $reclamacao)
                                                <option value="{{ $reclamacao->id }}">#{{ $reclamacao->id }} - {{ \Illuminate\Support\Str::limit($reclamacao->descricao, 40) }}</option>
                                            @endforeach
                                        </select>
                                        @break

                                    @case('money')
                                        <input type="text" data-money name="{{ $nome }}" class="w-28 {{ $campoCss }}" @required(empty($campo['opcional'])) />
                                        @break

                                    @case('number')
                                        <input type="number" min="0" name="{{ $nome }}" class="w-28 {{ $campoCss }}" @required(empty($campo['opcional'])) />
                                        @break

                                    @case('date')
                                        <input type="date" name="{{ $nome }}" class="{{ $campoCss }}" @required(empty($campo['opcional'])) />
                                        @break

                                    @default
                                        <input type="text" name="{{ $nome }}" class="w-56 {{ $campoCss }}" maxlength="500" @required(empty($campo['opcional'])) />
                                @endswitch
                            </div>
                        @endforeach

                        <button type="submit" class="secondary-button">Registrar</button>
                    </div>
                </form>
            @endforeach
        </div>
    </div>
@endif
