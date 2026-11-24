{{-- Linhas de indicadores do Painel do Dono: realizado, meta, situação, tendência e responsável. --}}
<table class="w-full text-left text-sm">
    <thead>
        <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
            <th class="pb-2">Indicador</th>
            <th class="pb-2 text-right">Realizado</th>
            @isset($comparacao) <th class="pb-2 text-right">Mês anterior</th> @endisset
            <th class="pb-2 text-right">Meta</th>
            <th class="pb-2">Situação</th>
            <th class="pb-2">Tendência (3 meses)</th>
            <th class="pb-2">Responsável</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($linhas as $linha)
            <tr class="border-b border-gray-100 dark:border-gray-800">
                <td class="py-1.5 dark:text-white">
                    <a class="hover:underline" href="{{ route('admin.mentoria.indicador', [$linha['codigo'], 'periodo' => $periodo]) }}"><span class="font-mono text-xs text-gray-500">{{ $linha['codigo'] }}</span> {{ $linha['nome'] }}</a>
                </td>
                <td class="py-1.5 text-right font-semibold dark:text-white">{{ $linha['realizado_fmt'] }}</td>
                @isset($comparacao)
                    <td class="py-1.5 text-right text-gray-500">{{ collect($comparacao)->firstWhere('codigo', $linha['codigo'])['realizado_fmt'] ?? '—' }}</td>
                @endisset
                <td class="py-1.5 text-right text-gray-500">{{ $linha['meta_fmt'] }}</td>
                <td class="py-1.5"><span class="rounded-full px-2 py-0.5 text-xs font-semibold" style="background: {{ $linha['cores']['fundo'] }}; color: {{ $linha['cores']['texto'] }};">{{ $linha['situacao_rotulo'] }}</span></td>
                <td class="py-1.5 text-xs text-gray-500">{{ collect($linha['tendencia'])->pluck('fmt')->implode(' › ') }}</td>
                <td class="py-1.5 text-xs text-gray-500">{{ $linha['cargo'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
