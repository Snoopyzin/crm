@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $rotulo = 'text-xs text-gray-500 dark:text-gray-400';
    $editando = $colaborador->exists;
    $data = fn ($d) => $d ? $d->format('Y-m-d') : '';
    $venc = function ($d) {
        if (! $d) { return ['—', 'text-gray-400']; }
        $classe = $d->lt(today()) ? 'text-red-600 font-semibold' : ($d->lte(today()->addDays(30)) ? 'text-yellow-700' : 'text-gray-700 dark:text-gray-300');
        return [$d->format('d/m/Y'), $classe];
    };
    $sistemas = ['Motor', 'Transmissão', 'Elétrica', 'Diagnóstico eletrônico', 'Suspensão', 'Freios', 'Hidráulica', 'Arrefecimento'];
@endphp

<x-admin::layouts>
    <x-slot:title>
        Colaboradores
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Colaboradores</div>
            <p class="text-sm text-gray-500">Quem trabalha na oficina, com os cargos (um colaborador pode acumular cargos), vencimentos de férias, exame e EPI. Alimenta os indicadores de RH e o bônus.</p>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-3 text-base font-semibold dark:text-white">{{ $editando ? 'Editar '.$colaborador->nome : 'Novo colaborador' }}</p>

            <form method="POST" action="{{ $editando ? route('admin.colaboradores.update', $colaborador->id) : route('admin.colaboradores.store') }}" class="flex flex-col gap-3">
                @csrf
                @if ($editando) @method('PUT') @endif

                <div class="flex flex-wrap gap-3">
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Nome</label><input type="text" name="nome" required value="{{ old('nome', $colaborador->nome) }}" class="w-64 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">CPF</label><input type="text" name="cpf" value="{{ old('cpf', $colaborador->cpf) }}" class="w-40 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1">
                        <label class="{{ $rotulo }}">Usuário do sistema</label>
                        <select name="user_id" class="{{ $campo }}"><option value="">- sem acesso -</option>
                            @foreach ($usuarios as $usuario)<option value="{{ $usuario->id }}" @selected($colaborador->user_id == $usuario->id)>{{ $usuario->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="{{ $rotulo }}">Técnico vinculado</label>
                        <select name="mechanic_id" class="{{ $campo }}"><option value="">-</option>
                            @foreach ($mecanicos as $mecanico)<option value="{{ $mecanico->id }}" @selected($colaborador->mechanic_id == $mecanico->id)>{{ $mecanico->nome }}</option>@endforeach
                        </select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="{{ $rotulo }}">Classificação</label>
                        <select name="classificacao" class="{{ $campo }}">
                            <option value="oficina" @selected($colaborador->classificacao === 'oficina')>Oficina (técnico, chefe)</option>
                            <option value="administracao" @selected($colaborador->classificacao === 'administracao')>Administração</option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="{{ $rotulo }}">Cargos (marque todos que a pessoa ocupa)</label>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        @foreach ($cargos as $chave => $nome)
                            <label class="flex items-center gap-1 text-sm dark:text-white"><input type="checkbox" name="cargos[]" value="{{ $chave }}" @checked($colaborador->temCargo($chave)) /> {{ $nome }}</label>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Salário base (R$)</label><input type="text" data-money name="salario_base" value="{{ $colaborador->salario_base !== null ? number_format($colaborador->salario_base, 2, ',', '.') : '' }}" class="w-36 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Admissão</label><input type="date" name="admissao" value="{{ $data($colaborador->admissao) }}" class="{{ $campo }}" /></div>
                    <div class="flex flex-col gap-1">
                        <label class="{{ $rotulo }}">Registro feito antes do início?</label>
                        <select name="registro_antes_inicio" class="{{ $campo }}"><option value="">-</option>
                            <option value="1" @selected($colaborador->registro_antes_inicio === true)>Sim</option>
                            <option value="0" @selected($colaborador->registro_antes_inicio === false)>Não</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Vencimento das férias</label><input type="date" name="ferias_vencimento" value="{{ $data($colaborador->ferias_vencimento) }}" class="{{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Vencimento do exame periódico</label><input type="date" name="exame_periodico_vencimento" value="{{ $data($colaborador->exame_periodico_vencimento) }}" class="{{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Vencimento da ficha de EPI</label><input type="date" name="epi_vencimento" value="{{ $data($colaborador->epi_vencimento) }}" class="{{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">CNH (categoria)</label><input type="text" name="cnh_categoria" maxlength="3" value="{{ $colaborador->cnh_categoria }}" class="w-20 {{ $campo }}" /></div>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="{{ $rotulo }}">Matriz de competências do técnico (0 = não faz · 3 = domina)</label>
                    <div class="flex flex-wrap gap-3">
                        @foreach ($sistemas as $sistema)
                            <label class="flex items-center gap-1 text-xs dark:text-white">{{ $sistema }}
                                <select name="competencias[{{ $sistema }}]" class="{{ $campo }}">
                                    <option value=""></option>
                                    @foreach ([0, 1, 2, 3] as $nivel)<option value="{{ $nivel }}" @selected(($colaborador->competencias[$sistema] ?? null) === $nivel)>{{ $nivel }}</option>@endforeach
                                </select>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="primary-button">{{ $editando ? 'Salvar alterações' : 'Cadastrar colaborador' }}</button>
                    @if ($editando)<a href="{{ route('admin.colaboradores.index') }}" class="secondary-button">Cancelar</a>@endif
                </div>
            </form>
        </div>

        <div class="box-shadow overflow-x-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                        <th class="pb-2">Colaborador</th><th class="pb-2">Cargos</th><th class="pb-2">Admissão</th>
                        <th class="pb-2">Férias</th><th class="pb-2">Exame</th><th class="pb-2">EPI</th><th class="pb-2">Situação</th><th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($colaboradores as $item)
                        <tr class="border-b border-gray-100 dark:border-gray-800 {{ $item->ativo ? '' : 'opacity-60' }}">
                            <td class="py-2 dark:text-white">{{ $item->nome }}</td>
                            <td class="py-2 text-xs text-gray-600 dark:text-gray-300">{{ collect($item->cargosLista())->map(fn ($c) => $cargos[$c] ?? $c)->implode(' + ') ?: '—' }}</td>
                            <td class="py-2 text-xs">{{ $item->admissao?->format('d/m/Y') ?? '—' }}</td>
                            @foreach ([$item->ferias_vencimento, $item->exame_periodico_vencimento, $item->epi_vencimento] as $vencimento)
                                @php [$texto, $classe] = $venc($vencimento); @endphp
                                <td class="py-2 text-xs {{ $classe }}">{{ $texto }}</td>
                            @endforeach
                            <td class="py-2 text-xs">{{ $item->ativo ? 'Ativo' : 'Desligado em '.$item->desligamento_em?->format('d/m/Y') }}</td>
                            <td class="py-2 whitespace-nowrap">
                                <a class="text-blue-600 hover:underline" href="{{ route('admin.colaboradores.edit', $item->id) }}">Editar</a>
                                @if ($item->ativo)
                                    <form method="POST" action="{{ route('admin.colaboradores.desligar', $item->id) }}" class="inline" onsubmit="return confirm('Registrar o desligamento de {{ $item->nome }}?')">
                                        @csrf <button type="submit" class="ml-2 text-red-600 hover:underline">Desligar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-3 text-gray-500">Nenhum colaborador cadastrado ainda.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
