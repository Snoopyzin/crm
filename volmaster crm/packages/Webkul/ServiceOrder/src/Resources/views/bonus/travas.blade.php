@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $rotulo = 'text-xs text-gray-500 dark:text-gray-400';
    $status = ['pendente' => 'bg-yellow-100 text-yellow-800', 'confirmada' => 'bg-red-100 text-red-700', 'rejeitada' => 'bg-gray-100 text-gray-600'];
    $podeDecidir = bouncer()->hasPermission('bonus_gerente');
@endphp

<x-admin::layouts>
    <x-slot:title>
        Travas do bônus
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <a class="text-xs text-blue-600 hover:underline" href="{{ route('admin.mentoria.bonus', ['periodo' => $periodo]) }}">&larr; Bônus de {{ $periodo }}</a>
            <div class="text-xl font-bold dark:text-white">Travas de {{ $periodo }}</div>
            <p class="text-sm text-gray-500">Qualquer trava confirmada no mês zera o bônus, seja qual for a nota. O gerente confirma (ou rejeita com comentário); a trava do gerente é confirmada pelo dono.</p>
        </div>

        @if ($podeDecidir)
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-3 text-base font-semibold dark:text-white">Registrar uma trava</p>
                <form method="POST" action="{{ route('admin.mentoria.bonus.travas.store') }}" class="flex flex-wrap items-end gap-3" id="form-trava">
                    @csrf
                    <input type="hidden" name="periodo" value="{{ $periodo }}" />
                    <div class="flex flex-col gap-1">
                        <label class="{{ $rotulo }}">Colaborador</label>
                        <select name="colaborador_id" required class="{{ $campo }}">@foreach ($colaboradores as $c)<option value="{{ $c->id }}">{{ $c->nome }}</option>@endforeach</select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="{{ $rotulo }}">Cargo e trava</label>
                        <select name="tipo" required class="w-96 {{ $campo }}" onchange="this.form.cargo.value = this.selectedOptions[0].dataset.cargo">
                            @foreach ($catalogo as $cargo => $itens)
                                <optgroup label="{{ $cargos[$cargo] ?? $cargo }}">
                                    @foreach ($itens as $chave => $item)<option value="{{ $chave }}" data-cargo="{{ $cargo }}">{{ $item['nome'] }}</option>@endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <input type="hidden" name="cargo" value="{{ array_key_first($catalogo) }}" />
                    </div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">O que aconteceu</label><input type="text" name="descricao" required maxlength="500" class="w-72 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Evidência (obrigatória)</label><input type="text" name="evidencia" required maxlength="500" placeholder="O que foi visto, onde e quando" class="w-72 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">OS (opcional)</label><input type="number" name="service_order_id" class="w-24 {{ $campo }}" /></div>
                    <button type="submit" class="primary-button">Registrar trava</button>
                </form>
                <script>
                    (function () { var f = document.getElementById('form-trava'); if (f && f.tipo.selectedOptions[0]) { f.cargo.value = f.tipo.selectedOptions[0].dataset.cargo; } })();
                </script>
            </div>
        @endif

        <div class="box-shadow overflow-x-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                        <th class="pb-2">Colaborador</th><th class="pb-2">Trava</th><th class="pb-2">Evidência</th><th class="pb-2">Origem</th><th class="pb-2">Situação</th><th class="pb-2">Decisão</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($travas as $trava)
                        <tr class="border-b border-gray-100 align-top dark:border-gray-800">
                            <td class="py-2 dark:text-white">{{ $trava->colaborador?->nome }}<span class="block text-xs text-gray-500">{{ $cargos[$trava->cargo] ?? $trava->cargo }}</span></td>
                            <td class="py-2 dark:text-white">{{ $trava->tipo_nome }}<span class="block text-xs text-gray-500">{{ $trava->descricao }}</span>
                                @if ($trava->service_order_id)<a class="text-xs text-blue-600 hover:underline" href="{{ route('admin.service_orders.view', $trava->service_order_id) }}">OS #{{ $trava->service_order_id }}</a>@endif</td>
                            <td class="py-2 text-xs text-gray-600 dark:text-gray-300">{{ $trava->evidencia ?: '—' }}</td>
                            <td class="py-2 text-xs">{{ $trava->origem === 'automatica' ? 'Detectada pelo sistema' : 'Registro manual' }}</td>
                            <td class="py-2"><span class="rounded px-1.5 py-0.5 text-xs {{ $status[$trava->status] }}">{{ $trava->status }}</span>
                                @if ($trava->decisao_comentario)<span class="block text-xs text-gray-500">{{ $trava->decisao_comentario }}</span>@endif</td>
                            <td class="py-2">
                                @if ($podeDecidir && $trava->status === 'pendente')
                                    <form method="POST" action="{{ route('admin.mentoria.bonus.travas.decidir', $trava->id) }}" class="flex flex-wrap gap-1">
                                        @csrf
                                        <input type="text" name="comentario" placeholder="Comentário (obrigatório para rejeitar)" maxlength="500" class="w-56 {{ $campo }}" />
                                        <button type="submit" name="decisao" value="confirmar" class="primary-button">Confirmar</button>
                                        <button type="submit" name="decisao" value="rejeitar" class="secondary-button">Rejeitar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-gray-500">Nenhuma trava neste mês.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
