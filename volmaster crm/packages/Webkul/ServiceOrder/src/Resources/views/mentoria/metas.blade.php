@php
    $campo = 'w-24 rounded-md border border-gray-300 px-2 py-1 text-right text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
@endphp

<x-admin::layouts>
    <x-slot:title>
        Tabela de metas
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Tabela de metas</div>
            <p class="text-sm text-gray-500">
                Uma linha por indicador. Deixe a meta da oficina em branco para usar o padrão da mentoria. Os indicadores de
                "meta da empresa" ficam sem cor no painel até você preencher. Mudar uma meta muda a cor, os alertas e a nota do bônus na hora.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.mentoria.metas.update') }}" class="flex flex-col gap-4">
            @csrf
            @method('PUT')

            @foreach ($definicoes as $cargo => $itens)
                <div class="box-shadow overflow-x-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <p class="mb-3 text-base font-semibold dark:text-white">{{ $cargos[$cargo] ?? $cargo }}</p>

                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                                <th class="pb-2">Código</th>
                                <th class="pb-2">Indicador</th>
                                <th class="pb-2">Tipo</th>
                                <th class="pb-2 text-right">Padrão da mentoria</th>
                                <th class="pb-2 text-right">Meta da oficina</th>
                                <th class="pb-2">Quem valida</th>
                                <th class="pb-2">Ativo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($itens as $item)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-1.5 font-mono text-xs">{{ $item->codigo }}</td>
                                    <td class="py-1.5 dark:text-white" title="{{ $item->formula }}">{{ $item->nome }}</td>
                                    <td class="py-1.5 text-xs text-gray-500">{{ ['min' => 'Mínimo', 'teto' => 'Teto', 'zero' => 'Zero ocorrências', 'nota' => 'Nota média'][$item->tipo_meta] }}</td>
                                    <td class="py-1.5 text-right text-gray-500">{{ $item->meta_empresa && $item->meta_padrao === null ? 'Meta da empresa' : ($item->meta_padrao === null ? '—' : rtrim(rtrim(number_format($item->meta_padrao, 2, ',', ''), '0'), ',')) }}</td>
                                    <td class="py-1.5 text-right">
                                        <input type="text" name="meta[{{ $item->codigo }}]" value="{{ $item->meta_oficina !== null ? rtrim(rtrim(number_format($item->meta_oficina, 2, ',', ''), '0'), ',') : '' }}" class="{{ $campo }}" />
                                    </td>
                                    <td class="py-1.5 text-xs text-gray-500">{{ $item->valida }}</td>
                                    <td class="py-1.5"><input type="checkbox" name="ativo[{{ $item->codigo }}]" value="1" @checked($item->ativo) /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach

            <div><button type="submit" class="primary-button">Salvar metas</button></div>
        </form>
    </div>
</x-admin::layouts>
