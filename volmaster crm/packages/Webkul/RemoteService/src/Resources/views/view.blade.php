<x-admin::layouts>
    <x-slot:title>
        Atendimento Remoto #{{ $remoteService->id }}
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <div class="text-xl font-bold dark:text-white">
                    Atendimento Remoto #{{ $remoteService->id }}
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $remoteService->status === 'Finalizado' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : ($remoteService->status === 'Pendente' ? 'bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300') }}">
                    {{ $remoteService->status }}
                </span>

                @if ($remoteService->status === 'Finalizado')
                    <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        ⏱ {{ $remoteService->duracao_formatada }}
                    </span>
                @elseif ($remoteService->status === 'Aberto')
                    @if ($remoteService->iniciado_em)
                        <span
                            id="remote-service-timer"
                            class="rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-900 dark:text-blue-300"
                            data-started-at="{{ $remoteService->iniciado_em->toIso8601String() }}"
                        >
                            ⏱ 00:00:00
                        </span>
                    @else
                        <x-admin::form :action="route('admin.remote_services.start', $remoteService->id)" method="PUT">
                            <button type="submit" class="secondary-button">
                                Iniciar atendimento
                            </button>
                        </x-admin::form>
                    @endif
                @endif

                @if ($remoteService->teamviewer_id)
                    @if ($remoteService->teamviewer_password)
                        <button
                            type="button"
                            class="secondary-button"
                            onclick="navigator.clipboard.writeText('{{ $remoteService->teamviewer_password }}'); this.textContent='Copiado!'; setTimeout(() => this.textContent='Copiar senha', 1500);"
                        >
                            Copiar senha
                        </button>
                    @endif

                    <a
                        href="teamviewer10://control?device={{ $remoteService->teamviewer_id }}"
                        class="primary-button"
                    >
                        Conectar via TeamViewer
                    </a>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 max-lg:grid-cols-1">
            <!-- Details -->
            <div class="box-shadow flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-base font-semibold text-gray-800 dark:text-white">Dados do atendimento</p>

                <div class="grid grid-cols-2 gap-y-3 text-sm max-sm:grid-cols-1">
                    <span class="text-gray-500">Cliente</span>
                    <span class="text-gray-800 dark:text-white">
                        {{ $remoteService->person->name ?? $remoteService->cliente_nome_livre ?: '-' }}
                        @unless ($remoteService->person)
                            <span class="text-xs text-gray-400">(ainda não cadastrado -- será criado ao finalizar)</span>
                        @endunless
                    </span>

                    @if ($remoteService->nome_oficina)
                        <span class="text-gray-500">Oficina</span>
                        <span class="text-gray-800 dark:text-white">{{ $remoteService->nome_oficina }}</span>
                    @endif

                    @if ($remoteService->whatsapp)
                        <span class="text-gray-500">WhatsApp</span>
                        <span class="text-gray-800 dark:text-white">{{ $remoteService->whatsapp }}</span>
                    @endif

                    <span class="text-gray-500">Veículo</span>
                    <span class="text-gray-800 dark:text-white">
                        @if ($remoteService->vehicle)
                            {{ $remoteService->vehicle->marca }} {{ $remoteService->vehicle->modelo }} - {{ $remoteService->vehicle->placa }}
                        @else
                            {{ $remoteService->veiculo_descricao_livre ?: 'Não informado' }}
                        @endif
                    </span>

                    <span class="text-gray-500">Tipo de atendimento</span>
                    <span class="text-gray-800 dark:text-white">{{ $remoteService->tipo_atendimento_label }}</span>

                    <span class="text-gray-500">Grupo</span>
                    <span class="text-gray-800 dark:text-white">{{ $remoteService->grupo->name ?? 'Sem grupo (não visível pra quem tem Grupo específico)' }}</span>

                    <span class="text-gray-500">Consultor</span>
                    <span class="text-gray-800 dark:text-white">{{ $remoteService->consultor->name ?? '-' }}</span>

                    <span class="text-gray-500">Criado em</span>
                    <span class="text-gray-800 dark:text-white">{{ core()->formatDate($remoteService->created_at) }}</span>

                    <span class="text-gray-500">Tempo gasto</span>
                    <span class="text-gray-800 dark:text-white">{{ $remoteService->duracao_formatada }}</span>
                </div>

                <div class="border-t border-gray-200 pt-3 dark:border-gray-800">
                    <p class="mb-2 text-gray-500">Acesso TeamViewer</p>

                    <x-admin::form :action="route('admin.remote_services.update-teamviewer', $remoteService->id)" method="PUT">
                        @include('remote_service::teamviewer-ocr')

                        <div class="grid grid-cols-2 gap-x-2 gap-y-2 max-sm:grid-cols-1">
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>ID</x-admin::form.control-group.label>
                                <x-admin::form.control-group.control type="text" id="teamviewer_id" name="teamviewer_id" :value="$remoteService->teamviewer_id" label="ID do TeamViewer" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Senha</x-admin::form.control-group.label>
                                <x-admin::form.control-group.control type="text" id="teamviewer_password" name="teamviewer_password" :value="$remoteService->teamviewer_password" label="Senha do TeamViewer" />
                            </x-admin::form.control-group>
                        </div>

                        <button type="submit" class="secondary-button mt-2">Salvar ID/senha</button>
                    </x-admin::form>
                </div>

                <div class="border-t border-gray-200 pt-3 dark:border-gray-800">
                    <p class="mb-1 text-gray-500">Descrição do problema</p>
                    <p class="text-gray-800 dark:text-white">{{ $remoteService->descricao_problema }}</p>
                </div>

                @if ($remoteService->status === 'Finalizado')
                    <div class="border-t border-gray-200 pt-3 dark:border-gray-800">
                        <p class="mb-1 text-gray-500">Descrição da solução</p>
                        <p class="text-gray-800 dark:text-white">{{ $remoteService->descricao_solucao }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-y-3 border-t border-gray-200 pt-3 text-sm dark:border-gray-800 max-sm:grid-cols-1">
                        <span class="text-gray-500">Técnico</span>
                        <span class="text-gray-800 dark:text-white">{{ $remoteService->tecnico->name ?? '-' }}</span>

                        <span class="text-gray-500">Valor</span>
                        <span class="text-gray-800 dark:text-white">{{ $remoteService->valor !== null ? core()->formatBasePrice($remoteService->valor) : '-' }}</span>

                        <span class="text-gray-500">Pago</span>
                        <span class="text-gray-800 dark:text-white">{{ $remoteService->pago ? 'Sim' : 'Não' }}</span>

                        <span class="text-gray-500">Faturado</span>
                        <span class="text-gray-800 dark:text-white">{{ $remoteService->faturado ? 'Sim' : 'Não' }}</span>

                        <span class="text-gray-500">Forma de pagamento</span>
                        <span class="text-gray-800 dark:text-white">{{ $remoteService->forma_pagamento ?: '-' }}</span>

                        @if ($remoteService->serviceOrder)
                            <span class="text-gray-500">Ordem de Serviço</span>
                            <a href="{{ route('admin.service_orders.view', $remoteService->service_order_id) }}" class="text-brandColor hover:underline">
                                Ver O.S. #{{ $remoteService->service_order_id }}
                            </a>
                        @endif
                    </div>
                @endif
            </div>

            <!-- Approve form (pending requests from the public scheduling link) -->
            @if ($remoteService->status === 'Pendente' && bouncer()->hasPermission('remote_services.approve'))
                <x-admin::form :action="route('admin.remote_services.approve', $remoteService->id)" method="PUT">
                    <div class="box-shadow flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">Aprovar agendamento</p>

                        <p class="text-sm text-gray-500">
                            Veio pelo link público de agendamento. Confirme (ou identifique, se o cliente não sabia) o tipo de programação e libere pro grupo certo pegar.
                        </p>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label class="required">Tipo de atendimento (pode marcar mais de um)</x-admin::form.control-group.label>

                            <div class="max-h-64 overflow-y-auto rounded-md border border-gray-300 p-3 dark:border-gray-800">
                                @forelse ($tiposPorGrupo as $grupoNome => $tipos)
                                    <p class="mb-1 mt-3 text-xs font-semibold uppercase text-gray-400 first:mt-0">
                                        {{ $grupoNome }}
                                    </p>

                                    <div class="grid grid-cols-2 gap-1 max-sm:grid-cols-1">
                                        @foreach ($tipos as $tipo)
                                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                                <input
                                                    type="checkbox"
                                                    name="tipo_atendimento[]"
                                                    value="{{ $tipo->nome }}"
                                                    {{ in_array($tipo->nome, $remoteService->tipo_atendimento ?? []) ? 'checked' : '' }}
                                                >
                                                {{ $tipo->nome }}
                                            </label>
                                        @endforeach
                                    </div>
                                @empty
                                    <p class="text-sm text-gray-400">
                                        Nenhum tipo cadastrado ainda.
                                        <a href="{{ route('admin.remote_services.types.create') }}" class="text-brandColor hover:underline">Cadastre o primeiro</a>.
                                    </p>
                                @endforelse
                            </div>

                            <x-admin::form.control-group.error control-name="tipo_atendimento" />
                        </x-admin::form.control-group>

                        <button type="submit" class="primary-button self-start">
                            Aprovar e liberar atendimento
                        </button>
                    </div>
                </x-admin::form>
            @endif

            <!-- Finish form -->
            @if ($remoteService->status === 'Aberto' && bouncer()->hasPermission('remote_services.finish'))
                <x-admin::form :action="route('admin.remote_services.finish', $remoteService->id)" method="POST">
                    <div class="box-shadow flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">Finalizar atendimento</p>

                        <p class="text-sm text-gray-500">
                            Ao finalizar, uma Ordem de Serviço é gerada automaticamente.
                        </p>

                        @unless ($remoteService->vehicle_id)
                            <div class="grid grid-cols-3 gap-x-2 gap-y-3 max-sm:grid-cols-1">
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label>Marca</x-admin::form.control-group.label>
                                    <x-admin::form.control-group.control type="text" id="marca" name="marca" label="Marca" />
                                    <x-admin::form.control-group.error control-name="marca" />
                                </x-admin::form.control-group>

                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label>Modelo</x-admin::form.control-group.label>
                                    <x-admin::form.control-group.control type="text" id="modelo" name="modelo" label="Modelo" />
                                    <x-admin::form.control-group.error control-name="modelo" />
                                </x-admin::form.control-group>

                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label>Placa</x-admin::form.control-group.label>
                                    <x-admin::form.control-group.control type="text" id="placa" name="placa" label="Placa" />
                                    <x-admin::form.control-group.error control-name="placa" />
                                </x-admin::form.control-group>
                            </div>

                            <p class="text-xs text-gray-400">
                                Sem veículo cadastrado ainda para esse cliente — opcional, se não preencher a Ordem de Serviço é gerada mesmo assim.
                            </p>
                        @endunless

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label class="required">Descrição da solução</x-admin::form.control-group.label>
                            <x-admin::form.control-group.control type="textarea" id="descricao_solucao" name="descricao_solucao" rules="required" label="Descrição da solução" />
                            <x-admin::form.control-group.error control-name="descricao_solucao" />
                        </x-admin::form.control-group>

                        <div class="grid grid-cols-2 gap-x-2 gap-y-3 max-sm:grid-cols-1">
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Valor</x-admin::form.control-group.label>
                                <x-admin::form.control-group.control type="text" id="valor" name="valor" label="Valor" data-money />
                                <x-admin::form.control-group.error control-name="valor" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Forma de pagamento</x-admin::form.control-group.label>
                                <x-admin::form.control-group.control type="text" id="forma_pagamento" name="forma_pagamento" label="Forma de pagamento" />
                                <x-admin::form.control-group.error control-name="forma_pagamento" />
                            </x-admin::form.control-group>
                        </div>

                        <div class="flex gap-4">
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                <input type="checkbox" name="pago" value="1"> Pago
                            </label>

                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                <input type="checkbox" name="faturado" value="1"> Faturado
                            </label>
                        </div>

                        <button type="submit" class="primary-button self-start">
                            Finalizar e gerar Ordem de Serviço
                        </button>
                    </div>
                </x-admin::form>
            @endif
        </div>
    </div>

    @if ($remoteService->status !== 'Finalizado' && $remoteService->iniciado_em)
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var el = document.getElementById('remote-service-timer');

                if (! el) {
                    return;
                }

                var startedAt = new Date(el.dataset.startedAt).getTime();

                function pad(n) {
                    return String(n).padStart(2, '0');
                }

                function update() {
                    var diff = Math.max(0, Date.now() - startedAt);

                    var totalSeconds = Math.floor(diff / 1000);
                    var hours = Math.floor(totalSeconds / 3600);
                    var minutes = Math.floor((totalSeconds % 3600) / 60);
                    var seconds = totalSeconds % 60;

                    el.textContent = '⏱ ' + pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
                }

                update();

                setInterval(update, 1000);
            });
        </script>
    @endif
</x-admin::layouts>
