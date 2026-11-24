@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $rotulo = 'text-xs text-gray-500 dark:text-gray-400';
@endphp

<x-admin::layouts>
    <x-slot:title>
        Registros do dia a dia
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Registros do dia a dia</div>
            <p class="text-sm text-gray-500">Auditorias com nota, ocorrências, reuniões, checklists e conferências que não nascem na OS. Nada é apagado: para corrigir, registre a correção com justificativa.</p>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <form method="POST" action="{{ route('admin.mentoria.registros.store') }}" class="flex flex-wrap items-end gap-3" id="form-registro">
                @csrf

                <div class="flex flex-col gap-1">
                    <label class="{{ $rotulo }}">O que registrar</label>
                    <select name="tipo" id="reg-tipo" required class="w-80 {{ $campo }}">
                        <option value="">-</option>
                        @foreach ($tipos as $grupo => $itens)
                            <optgroup label="{{ $grupo }}">
                                @foreach ($itens as $chave => $item)
                                    <option value="{{ $chave }}">{{ $item['label'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="{{ $rotulo }}">Data e hora</label>
                    <input type="datetime-local" name="ocorrido_em" value="{{ now()->format('Y-m-d\TH:i') }}" class="{{ $campo }}" />
                </div>

                <div class="flex flex-col gap-1" data-campo="valor" style="display:none">
                    <label class="{{ $rotulo }}" id="reg-valor-rotulo">Valor</label>
                    <input type="number" step="0.01" name="valor" class="w-32 {{ $campo }}" />
                </div>

                <div class="flex flex-col gap-1" data-campo="ok" style="display:none">
                    <label class="{{ $rotulo }}" id="reg-ok-rotulo">Resultado</label>
                    <select name="ok" class="{{ $campo }}">
                        <option value="">-</option>
                        <option value="1">Sim</option>
                        <option value="0">Não</option>
                    </select>
                </div>

                <div class="flex flex-col gap-1" data-campo="user" style="display:none">
                    <label class="{{ $rotulo }}">Colaborador</label>
                    <select name="user_id" class="{{ $campo }}">
                        <option value="">Todos do cargo</option>
                        @foreach ($usuarios as $usuario)
                            <option value="{{ $usuario->id }}">{{ $usuario->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex flex-col gap-1" data-campo="mechanic" style="display:none">
                    <label class="{{ $rotulo }}">Técnico</label>
                    <select name="mechanic_id" class="{{ $campo }}">
                        <option value="">Todos os técnicos</option>
                        @foreach ($mecanicos as $mecanico)
                            <option value="{{ $mecanico->id }}">{{ $mecanico->nome }}</option>
                        @endforeach
                    </select>
                </div>

                <div id="reg-dados" class="flex flex-wrap gap-3"></div>

                <div class="flex flex-col gap-1">
                    <label class="{{ $rotulo }}">Observação</label>
                    <input type="text" name="observacao" maxlength="500" class="w-64 {{ $campo }}" />
                </div>

                <button type="submit" class="primary-button">Registrar</button>
            </form>
        </div>

        <div class="box-shadow overflow-x-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-3 text-base font-semibold dark:text-white">Últimos registros</p>

            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                        <th class="pb-2">Quando</th>
                        <th class="pb-2">Registro</th>
                        <th class="pb-2">Valor</th>
                        <th class="pb-2">Resultado</th>
                        <th class="pb-2">Observação</th>
                        <th class="pb-2">Corrigir</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($registros as $registro)
                        <tr class="border-b border-gray-100 align-top dark:border-gray-800">
                            <td class="py-2 whitespace-nowrap dark:text-white">{{ $registro->ocorrido_em->format('d/m/Y H:i') }}</td>
                            <td class="py-2 dark:text-white">
                                {{ $rotulos[$registro->tipo] ?? $registro->tipo }}
                                @if ($registro->corrige_id)
                                    <span class="rounded bg-yellow-100 px-1.5 py-0.5 text-xs text-yellow-800" title="{{ $registro->justificativa }}">correção do #{{ $registro->corrige_id }}</span>
                                @endif
                            </td>
                            <td class="py-2 dark:text-white">{{ $registro->valor !== null ? number_format($registro->valor, 2, ',', '.') : '' }}</td>
                            <td class="py-2 dark:text-white">{{ $registro->ok === null ? '' : ($registro->ok ? 'Sim' : 'Não') }}</td>
                            <td class="py-2 text-xs text-gray-500">{{ $registro->observacao }}</td>
                            <td class="py-2">
                                <form method="POST" action="{{ route('admin.mentoria.registros.correct', $registro->id) }}" class="flex flex-wrap items-center gap-1">
                                    @csrf
                                    <input type="number" step="0.01" name="valor" placeholder="novo valor" class="w-24 {{ $campo }}" />
                                    <input type="text" name="justificativa" required minlength="5" placeholder="justificativa" class="w-44 {{ $campo }}" />
                                    <button type="submit" class="secondary-button">Corrigir</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-gray-500">Nenhum registro ainda.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        (function () {
            var TIPOS = @json(\Webkul\ServiceOrder\Models\MentoriaRegistro::TIPOS);
            var select = document.getElementById('reg-tipo');

            function mostrar(campo, visivel) {
                var el = document.querySelector('[data-campo="' + campo + '"]');
                if (el) { el.style.display = visivel ? 'flex' : 'none'; }
            }

            select.addEventListener('change', function () {
                var tipo = TIPOS[select.value] || {};
                mostrar('valor', !!tipo.valor);
                mostrar('ok', !!tipo.ok);
                mostrar('user', tipo.colaborador === 'user');
                mostrar('mechanic', tipo.colaborador === 'mechanic');
                document.getElementById('reg-valor-rotulo').textContent = tipo.valor || 'Valor';
                document.getElementById('reg-ok-rotulo').textContent = tipo.ok || 'Resultado';

                var dados = document.getElementById('reg-dados');
                dados.innerHTML = '';

                Object.keys(tipo.dados || {}).forEach(function (nome) {
                    var wrap = document.createElement('div');
                    wrap.className = 'flex flex-col gap-1';
                    wrap.innerHTML = '<label class="{{ $rotulo }}"></label><input type="text" class="w-56 {{ $campo }}" />';
                    wrap.querySelector('label').textContent = tipo.dados[nome];
                    wrap.querySelector('input').name = 'dados[' + nome + ']';
                    dados.appendChild(wrap);
                });
            });
        })();
    </script>
</x-admin::layouts>
