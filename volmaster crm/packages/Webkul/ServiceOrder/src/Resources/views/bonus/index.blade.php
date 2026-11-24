@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $verValores = \Webkul\ServiceOrder\Services\ValoresFinanceiros::can();
    $faixaCor = fn ($f) => $f >= 100 ? 'bg-green-100 text-green-700' : ($f > 0 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-700');
    $status = ['calculado' => ['Calculado', 'bg-gray-100 text-gray-700'], 'revisado' => ['Revisado', 'bg-blue-100 text-blue-700'], 'aprovado' => ['Aprovado', 'bg-green-100 text-green-700']];
    $podeRever = bouncer()->hasPermission('bonus_gerente');
    $podeAprovar = bouncer()->hasPermission('bonus_dono');
@endphp

<x-admin::layouts>
    <x-slot:title>
        Bônus
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div>
                <div class="text-xl font-bold dark:text-white">Bônus de {{ $periodo }}</div>
                <p class="text-sm text-gray-500">
                    Fase {{ $fase }} ({{ $fase === 1 ? 'processo' : 'processo + resultado' }}) &middot;
                    {{ $fechado ? 'mês fechado (indicadores congelados)' : 'mês ainda não fechado' }} &middot;
                    aprovação do dono {{ $janela ? 'aberta agora' : 'fora do prazo (dia 1 ao 5º dia útil)' }}
                </p>
            </div>

            <form method="GET" class="flex items-center gap-2">
                <input type="month" name="periodo" value="{{ $periodo }}" onchange="this.form.submit()" class="{{ $campo }}" />
                <a class="secondary-button" href="{{ route('admin.mentoria.bonus.travas', ['periodo' => $periodo]) }}">Travas @if ($travasPendentes) ({{ $travasPendentes }} pendentes) @endif</a>
            </form>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <b>Como funciona:</b> o sistema calcula no dia 1 &rarr; o gerente confere, confirma as travas e dá o feedback individual (até o 3º dia útil) &rarr;
            o dono aprova (até o 5º dia útil) &rarr; exporta a folha. Depois de aprovado, só muda com estorno justificado.
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($podeRever)
                <form method="POST" action="{{ route('admin.mentoria.bonus.calcular') }}">@csrf <input type="hidden" name="periodo" value="{{ $periodo }}" /><button class="secondary-button" type="submit">Calcular / recalcular as notas</button></form>
                <form method="POST" action="{{ route('admin.mentoria.bonus.painel') }}">@csrf <input type="hidden" name="periodo" value="{{ $periodo }}" /><button class="secondary-button" type="submit">Consolidar o Painel do Dono do mês</button></form>
            @endif
            @if ($podeAprovar)
                <form method="POST" action="{{ route('admin.mentoria.bonus.aprovar_todos') }}">@csrf <input type="hidden" name="periodo" value="{{ $periodo }}" /><button class="primary-button" type="submit" @disabled(! $janela)>Aprovar todos os revisados</button></form>
                <a class="secondary-button" href="{{ route('admin.mentoria.bonus.exportar', ['periodo' => $periodo]) }}">Exportar folha (CSV)</a>
            @endif
        </div>

        <div class="box-shadow overflow-x-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                        <th class="pb-2">Colaborador</th><th class="pb-2">Cargo</th><th class="pb-2 text-right">Nota</th><th class="pb-2">Faixa</th><th class="pb-2">Trava</th>
                        @if ($verValores)<th class="pb-2 text-right">Valor a pagar</th>@endif
                        <th class="pb-2">Situação</th><th class="pb-2">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($apuracoes as $a)
                        <tr class="border-b border-gray-100 align-top dark:border-gray-800">
                            <td class="py-2 dark:text-white">{{ $a->colaborador?->nome }}</td>
                            <td class="py-2 text-xs">{{ $cargos[$a->cargo] ?? $a->cargo }}</td>
                            <td class="py-2 text-right font-semibold dark:text-white">{{ $a->nota !== null ? number_format($a->nota, 1, ',', '.') : '—' }}</td>
                            <td class="py-2"><span class="rounded px-1.5 py-0.5 text-xs {{ $faixaCor($a->faixa_percentual) }}">{{ $a->faixa_percentual }}%</span></td>
                            <td class="py-2 text-xs">{!! $a->zerado_por_trava ? '<span class="font-semibold text-red-600">sim</span>' : 'não' !!}</td>
                            @if ($verValores)<td class="py-2 text-right dark:text-white">{{ $a->valor !== null ? 'R$ '.number_format($a->valor, 2, ',', '.') : '—' }}</td>@endif
                            <td class="py-2"><span class="rounded px-1.5 py-0.5 text-xs {{ $status[$a->status][1] ?? '' }}">{{ $status[$a->status][0] ?? $a->status }}</span>
                                @if ($a->ciencia_colaborador_em)<span class="block text-xs text-gray-500">ciente em {{ $a->ciencia_colaborador_em->format('d/m') }}</span>@endif
                            </td>
                            <td class="py-2">
                                <a class="text-blue-600 hover:underline" href="{{ route('admin.mentoria.bonus.detalhe', $a->id) }}">Detalhe</a>

                                @if ($podeRever && $a->status !== 'aprovado')
                                    <form method="POST" action="{{ route('admin.mentoria.bonus.revisar', $a->id) }}" class="mt-1 flex gap-1">
                                        @csrf
                                        <input type="text" name="feedback" required placeholder="Feedback individual" value="{{ $a->feedback }}" class="w-56 {{ $campo }}" />
                                        <button type="submit" class="secondary-button">Revisar</button>
                                    </form>
                                @endif

                                @if ($podeAprovar && $a->status === 'revisado')
                                    <form method="POST" action="{{ route('admin.mentoria.bonus.aprovar', $a->id) }}" class="mt-1">@csrf <button type="submit" class="primary-button" @disabled(! $janela)>Aprovar bônus</button></form>
                                @endif

                                @if ($podeAprovar && $a->status === 'aprovado')
                                    <form method="POST" action="{{ route('admin.mentoria.bonus.estornar', $a->id) }}" class="mt-1 flex gap-1">
                                        @csrf
                                        <input type="text" name="justificativa" required minlength="5" placeholder="Justificativa do estorno" class="w-48 {{ $campo }}" />
                                        <button type="submit" class="secondary-button">Estornar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-3 text-gray-500">Nenhuma nota calculada ainda neste mês. Cadastre os colaboradores e use "Calcular as notas".</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
