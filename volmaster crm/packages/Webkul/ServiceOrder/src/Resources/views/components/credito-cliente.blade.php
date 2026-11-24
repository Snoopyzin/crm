{{--
    Cartão "Cor de crédito do cliente" na página do cliente (B1). Dado restrito: só quem tem a
    permissão (Consultor, Financeiro, Gerente e Proprietário) e vê valores. Nunca vai para o link
    de acompanhamento do cliente nem para a impressão.
--}}
@php
    $credito = app(\Webkul\ServiceOrder\Services\Credito\CreditoCliente::class);
@endphp

@if (isset($person) && $credito::disponivel() && $credito->podeVer())
    @php
        $historico = $credito->historico($person->id, 8);
        $vigente = $historico->first();
        $sugestao = $credito->sugestao($person->id);
        $efetiva = $credito->corEfetiva($person->id);
        $nomes = ['verde' => 'Verde', 'amarelo' => 'Amarelo', 'vermelho' => 'Vermelho'];
        $cores = ['verde' => 'bg-green-100 text-green-800', 'amarelo' => 'bg-yellow-100 text-yellow-800', 'vermelho' => 'bg-red-100 text-red-800'];
    @endphp

    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" id="credito-cliente">
        <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">Cor de crédito do cliente</p>
        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">Visível só para Consultor, Financeiro, Gerente e Proprietário. Nunca aparece no link do cliente nem na OS impressa.</p>

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span>Registrada:</span>
            @if ($vigente)
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $cores[$vigente->cor] }}">{{ $nomes[$vigente->cor] }}</span>
                <span class="text-xs text-gray-500">em {{ $vigente->created_at->format('d/m/Y H:i') }} por {{ $vigente->user?->name ?? '—' }}@if ($vigente->observacao) &middot; {{ $vigente->observacao }}@endif</span>
            @else
                <span class="text-xs text-gray-500">nenhuma análise ainda</span>
            @endif
        </div>

        <div class="mt-1 flex flex-wrap items-center gap-2 text-sm">
            <span>O sistema sugere:</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $cores[$sugestao['cor']] }}">{{ $nomes[$sugestao['cor']] }}</span>
            <span class="text-xs text-gray-500">{{ $sugestao['motivo'] }}</span>
        </div>

        @if ($efetiva)
            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                <span>Vale para liberar prazo:</span>
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $cores[$efetiva] }}">{{ $nomes[$efetiva] }}</span>
            </div>
        @endif

        @if ($credito->podeRegistrar())
            <form method="POST" action="{{ route('admin.credito.registrar', $person->id) }}" class="mt-3 flex flex-wrap items-end gap-2">
                @csrf
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-gray-500">Nova análise</label>
                    <select name="cor_credito" required class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                        <option value="">-</option>
                        @foreach (\Webkul\ServiceOrder\Services\Credito\CreditoCliente::CORES as $valor => $nome)
                            <option value="{{ $valor }}">{{ $nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-gray-500">Observação</label>
                    <input type="text" name="observacao" maxlength="1000" class="w-56 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-gray-500">Justificativa (se for mais branda que a sugestão)</label>
                    <input type="text" name="justificativa" maxlength="1000" class="w-64 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                </div>
                <button type="submit" class="secondary-button">Registrar</button>
            </form>
        @endif

        @if ($historico->count() > 1)
            <details class="mt-3 text-xs text-gray-600 dark:text-gray-300">
                <summary class="cursor-pointer">Histórico ({{ $historico->count() }} últimas análises)</summary>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($historico as $registro)
                        <li>{{ $registro->created_at->format('d/m/Y H:i') }} &mdash; {{ $nomes[$registro->cor] }} por {{ $registro->user?->name ?? '—' }}@if ($registro->observacao) &middot; {{ $registro->observacao }}@endif @if ($registro->justificativa) &middot; justificativa: {{ $registro->justificativa }}@endif</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
@endif
