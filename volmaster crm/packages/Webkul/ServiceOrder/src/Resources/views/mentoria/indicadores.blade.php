<x-admin::layouts>
    <x-slot:title>
        Indicadores
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Indicadores da mentoria</div>

            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="cargo" value="{{ $cargo }}" />
                <input type="month" name="periodo" value="{{ $periodo }}" onchange="this.form.submit()" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
            </form>
        </div>

        <div class="box-shadow flex flex-wrap gap-2 rounded-lg border border-gray-200 bg-white p-3 text-sm dark:border-gray-800 dark:bg-gray-900">
            @foreach ($cargos as $chave => $nome)
                <a href="{{ route('admin.mentoria.indicadores', ['cargo' => $chave, 'periodo' => $periodo]) }}"
                   class="rounded-full px-3 py-1 {{ $chave === $cargo ? 'bg-blue-600 font-semibold text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' }}">
                    {{ $nome }}
                </a>
            @endforeach
        </div>

        <div class="box-shadow overflow-x-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                        <th class="pb-2">Código</th>
                        <th class="pb-2">Indicador</th>
                        <th class="pb-2 text-right">Realizado</th>
                        <th class="pb-2 text-right">Meta</th>
                        <th class="pb-2">Situação</th>
                        <th class="pb-2">Últimos 3 meses</th>
                        <th class="pb-2">Quem valida</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($linhas as $linha)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 font-mono text-xs"><a class="text-blue-600 hover:underline" href="{{ route('admin.mentoria.indicador', [$linha['codigo'], 'periodo' => $periodo]) }}">{{ $linha['codigo'] }}</a></td>
                            <td class="py-2 dark:text-white">{{ $linha['nome'] }}</td>
                            <td class="py-2 text-right font-semibold dark:text-white">{{ $linha['realizado_fmt'] }}</td>
                            <td class="py-2 text-right text-gray-500">{{ $linha['meta_fmt'] }}</td>
                            <td class="py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" style="background: {{ $linha['cores']['fundo'] }}; color: {{ $linha['cores']['texto'] }};">{{ $linha['situacao_rotulo'] }}</span>
                            </td>
                            <td class="py-2 text-xs text-gray-500">
                                {{ collect($linha['tendencia'])->pluck('fmt')->implode('  ›  ') }}
                            </td>
                            <td class="py-2 text-xs text-gray-500">{{ $linha['valida'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <p class="mt-3 text-xs text-gray-500">
                Verde: atingiu a meta. Amarelo: pelo menos 80% da meta (nos tetos, até 1,5 vezes a meta; nas ocorrências, uma). Vermelho: abaixo disso.
                "Meta em branco" é meta da empresa: preencha em Configurações &gt; Tabela de metas.
            </p>
        </div>
    </div>
</x-admin::layouts>
