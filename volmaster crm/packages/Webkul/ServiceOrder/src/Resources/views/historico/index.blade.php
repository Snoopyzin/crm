<x-admin::layouts>
    <x-slot:title>
        Importar histórico das planilhas
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Importar o histórico das planilhas de medição</div>
            <p class="text-sm text-gray-500">Na virada para o sistema, traga colaboradores e cargos, metas, registros de medição por dia, os valores mensais dos indicadores e as notas de bônus já fechadas.
                O que entra por aqui aparece no painel com a marca "origem: planilha". Importar a mesma planilha duas vezes não duplica.</p>
        </div>

        @if ($resultado)
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-base font-semibold dark:text-white">{{ $resultado['simulado'] ? 'Simulação' : 'Importação concluída' }}: {{ $resultado['tipo'] }}</p>
                <p class="text-sm dark:text-gray-200">{{ $resultado['total'] }} linha(s) lida(s) · <b class="text-green-700">{{ $resultado['criados'] }} {{ $resultado['simulado'] ? 'seriam criadas ou atualizadas' : 'criadas ou atualizadas' }}</b> · {{ $resultado['ignorados'] }} ignorada(s)</p>
                @foreach ($resultado['erros'] as $erro)<p class="text-xs text-red-600">{{ $erro }}</p>@endforeach
                @if ($resultado['simulado'])<p class="mt-2 text-xs text-gray-500">Nada foi gravado. Se estiver certo, envie de novo sem marcar "Só simular".</p>@endif
            </div>
        @endif

        @foreach ($tipos as $chave => $tipo)
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-base font-semibold dark:text-white">{{ $tipo['nome'] }}</p>
                <p class="mb-2 text-xs text-gray-500">Colunas: <span class="font-mono">{{ implode(' · ', $tipo['colunas']) }}</span> · <a class="text-blue-600 hover:underline" href="{{ route('admin.historico.modelo', $chave) }}">baixar o modelo</a></p>

                <form method="POST" action="{{ route('admin.historico.importar') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
                    @csrf
                    <input type="hidden" name="tipo" value="{{ $chave }}" />
                    <input type="file" name="arquivo" accept=".csv,.xlsx,.xls,.txt" required class="text-sm" />
                    <label class="flex items-center gap-1 text-sm dark:text-white"><input type="hidden" name="simular" value="0" /><input type="checkbox" name="simular" value="1" checked /> Só simular</label>
                    <button type="submit" class="primary-button">Enviar</button>
                </form>
            </div>
        @endforeach
    </div>
</x-admin::layouts>
