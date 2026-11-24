@php
    $campo = 'w-24 rounded-md border border-gray-300 px-2 py-1 text-right text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $unidades = ['percent' => '%', 'brl' => 'R$', 'cor' => 'cor de crédito', 'nenhuma' => ''];
    $num = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
@endphp

<x-admin::layouts>
    <x-slot:title>
        Matriz de alçadas
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Matriz de alçadas</div>
            <p class="text-sm text-gray-500">
                Preencha os limites de cada tema. Enquanto um limite estiver em branco, o sistema não consegue julgar "acima da alçada" e a ação passa sem bloqueio.
                Os temas de decisão sempre do gerente ou sempre do dono não usam limite.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.mentoria.alcadas.matriz.update') }}" class="flex flex-col gap-4">
            @csrf
            @method('PUT')

            <div class="box-shadow overflow-x-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                            <th class="pb-2">Tema</th>
                            <th class="pb-2">Decide sozinho</th>
                            <th class="pb-2">Gerente aprova</th>
                            <th class="pb-2">Dono aprova</th>
                            <th class="pb-2">Gatilho no sistema</th>
                            <th class="pb-2 text-right">Limite sozinho</th>
                            <th class="pb-2 text-right">Limite do gerente</th>
                            <th class="pb-2">Ativo</th>
                            <th class="pb-2">Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($temas as $chave => $tema)
                            @php
                                $regra = $regras[$chave] ?? null;
                                $usaLimite = $tema['tipo'] === 'limites';
                                $configurada = ! $usaLimite || ($tema['sozinho_aplica'] ? $regra?->limite_sozinho !== null : $regra?->limite_gerente !== null);
                            @endphp
                            <tr class="border-b border-gray-100 align-top dark:border-gray-800">
                                <td class="py-2 font-semibold dark:text-white">{{ $tema['nome'] }} <span class="block text-xs font-normal text-gray-500">{{ $tema['automatico'] ? 'bloqueia automaticamente' : 'pedido manual' }}</span></td>
                                <td class="py-2 text-xs text-gray-600 dark:text-gray-300">{{ $tema['sozinho'] }}</td>
                                <td class="py-2 text-xs text-gray-600 dark:text-gray-300">{{ $tema['gerente'] }}</td>
                                <td class="py-2 text-xs text-gray-600 dark:text-gray-300">{{ $tema['dono'] }}</td>
                                <td class="py-2 text-xs text-gray-500">{{ $tema['gatilho'] }}</td>
                                <td class="py-2 text-right">
                                    @if ($usaLimite && $tema['sozinho_aplica'])
                                        <input type="text" name="sozinho[{{ $chave }}]" value="{{ $num($regra?->limite_sozinho) }}" class="{{ $campo }}" /> <span class="text-xs text-gray-500">{{ $unidades[$tema['unidade']] }}</span>
                                    @endif
                                </td>
                                <td class="py-2 text-right">
                                    @if ($usaLimite && $tema['gerente_aprova'])
                                        <input type="text" name="gerente[{{ $chave }}]" value="{{ $num($regra?->limite_gerente) }}" class="{{ $campo }}" /> <span class="text-xs text-gray-500">{{ $unidades[$tema['unidade']] }}</span>
                                    @endif
                                </td>
                                <td class="py-2"><input type="checkbox" name="ativo[{{ $chave }}]" value="1" @checked($regra?->ativo ?? true) /></td>
                                <td class="py-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs {{ $configurada ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-800' }}">{{ $configurada ? 'Configurada' : 'Falta preencher' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div><button type="submit" class="primary-button">Salvar matriz</button></div>
        </form>
    </div>
</x-admin::layouts>
