@php
    $card = 'box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900';
    $h = 'mb-1 text-base font-semibold text-gray-800 dark:text-white';
    $hint = 'mb-3 text-xs text-gray-500 dark:text-gray-400';
    $input = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
@endphp

<x-admin::layouts>
    <x-slot:title>
        Perfis da mentoria
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Perfis da mentoria</div>
            <a href="{{ route('admin.workshop.settings.edit') }}" class="secondary-button">Indicadores e alertas</a>
        </div>

        {{-- 1. Funções dos cargos --}}
        <div class="{{ $card }}">
            <p class="{{ $h }}">1. Funções dos cargos</p>
            <p class="{{ $hint }}">
                Cria (ou atualiza) uma função para cada cargo da mentoria, já com as permissões certas. Nenhuma função antiga é apagada
                e nenhum usuário muda de função neste passo. Quem acumula cargos recebe a soma das permissões, mas sem valores em reais
                se nenhum dos cargos dele enxerga valores (Técnico, Chefe, Zeladoria).
            </p>

            <table class="mb-4 w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                        <th class="pb-2">Cargo</th><th class="pb-2">Função criada</th><th class="pb-2">Usuários</th><th class="pb-2">Valores em R$</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($situacao as $linha)
                        <tr class="border-b border-gray-100 dark:border-gray-800 dark:text-gray-200">
                            <td class="py-1.5"><b>{{ $linha['nome'] }}</b><span class="block text-xs text-gray-500">{{ $linha['descricao'] }}</span></td>
                            <td class="py-1.5">{{ $linha['funcao'] ?? '—' }}</td>
                            <td class="py-1.5">{{ $linha['usuarios'] }}</td>
                            <td class="py-1.5">{{ $linha['valores'] ? 'Vê' : 'Não vê' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <form method="POST" action="{{ route('admin.workshop.perfis.aplicar') }}">
                @csrf
                <button type="submit" class="primary-button">{{ $ativo ? 'Atualizar as funções da mentoria' : 'Criar as funções da mentoria' }}</button>
            </form>
        </div>

        {{-- 2. Migração dos usuários --}}
        <div class="{{ $card }}">
            <p class="{{ $h }}">2. Mover os usuários para o cargo certo</p>
            <p class="{{ $hint }}">
                Prévia do que vai mudar. O cargo sugerido vem do nome da função atual (Administrador &rarr; Proprietário, Consultor &rarr; Consultor técnico,
                Diagnóstico &rarr; Chefe de oficina, Financeiro, Mecânico &rarr; Técnico, Programador &rarr; Suporte Volmaster). Confira pessoa por pessoa e
                escolha "Manter como está" onde não tiver certeza. Cada mudança fica registrada e o lote pode ser desfeito.
            </p>

            @if (! $ativo)
                <p class="text-sm text-yellow-800">Crie as funções da mentoria no passo 1 antes de mover usuários.</p>
            @elseif (empty($plano))
                <p class="text-sm text-gray-500">Todos os usuários já estão em funções da mentoria.</p>
            @else
                <form method="POST" action="{{ route('admin.workshop.perfis.migrar') }}">
                    @csrf
                    <table class="mb-4 w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                                <th class="pb-2">Usuário</th><th class="pb-2">Função atual</th><th class="pb-2">Passa a ser</th><th class="pb-2">Valores em R$</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($plano as $linha)
                                <tr class="border-b border-gray-100 dark:border-gray-800 dark:text-gray-200">
                                    <td class="py-1.5">{{ $linha['nome'] }}<span class="block text-xs text-gray-500">{{ $linha['email'] }}@if (! $linha['ativo']) &middot; inativo @endif</span></td>
                                    <td class="py-1.5">{{ $linha['funcao_atual'] }}</td>
                                    <td class="py-1.5">
                                        <select name="usuario[{{ $linha['usuario_id'] }}]" class="{{ $input }}">
                                            <option value="">Manter como está</option>
                                            @foreach ($perfis as $chave => $nome)
                                                <option value="{{ $chave }}" @selected($linha['sugerido'] === $chave)>{{ $nome }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="py-1.5 text-xs">
                                        {{ $linha['valores_antes'] ? 'Vê' : 'Não vê' }}
                                        @if ($linha['valores_depois'] !== null && $linha['valores_depois'] !== $linha['valores_antes'])
                                            &rarr; <b class="{{ $linha['valores_depois'] ? 'text-green-700' : 'text-red-700' }}">{{ $linha['valores_depois'] ? 'Vê' : 'Não vê' }}</b>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <button type="submit" class="primary-button" onclick="return confirm('Mover os usuários como mostrado na tabela?')">Mover os usuários</button>
                </form>
            @endif

            @if ($ultimoLote)
                <form method="POST" action="{{ route('admin.workshop.perfis.desfazer') }}" class="mt-3" onsubmit="return confirm('Devolver os usuários do último lote às funções anteriores?')">
                    @csrf
                    <input type="hidden" name="lote" value="{{ $ultimoLote }}" />
                    <button type="submit" class="secondary-button">Desfazer o último lote</button>
                </form>
            @endif
        </div>
    </div>
</x-admin::layouts>
