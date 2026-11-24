<x-admin::layouts>
    <x-slot:title>
        @lang('service_order::app.clientes.title')
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="text-xl font-bold dark:text-white">
                @lang('service_order::app.clientes.title')
            </div>

            @if (bouncer()->hasPermission('cadastro'))
                <a href="{{ route('admin.service_orders.cadastro.create', $cadastro) }}" class="primary-button">
                    @lang('service_order::app.clientes.cadastrar-btn')
                </a>
            @endif
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <form method="GET" action="{{ route('admin.clientes.busca') }}" class="flex flex-wrap items-end gap-2">
                <div class="flex min-w-[18rem] flex-1 flex-col gap-1">
                    <label for="q" class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.clientes.campo')</label>
                    <input
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $termo }}"
                        autofocus
                        autocomplete="off"
                        placeholder="@lang('service_order::app.clientes.placeholder')"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                    >
                </div>

                <button type="submit" class="primary-button">@lang('service_order::app.clientes.pesquisar-btn')</button>
            </form>

            @if ($resultados === null)
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    @if ($termo === '')
                        @lang('service_order::app.clientes.dica')
                    @else
                        @lang('service_order::app.clientes.curto')
                    @endif
                </p>
            @elseif ($resultados->isEmpty())
                <div class="mt-4 rounded-md bg-yellow-50 p-3 text-sm text-yellow-900 dark:bg-yellow-900/20 dark:text-yellow-200">
                    <p>@lang('service_order::app.clientes.nenhum', ['termo' => $termo])</p>

                    @if (bouncer()->hasPermission('cadastro'))
                        <a href="{{ route('admin.service_orders.cadastro.create', $cadastro) }}" class="primary-button mt-3 inline-block">
                            @lang('service_order::app.clientes.cadastrar-btn')
                        </a>
                    @endif
                </div>
            @else
                <table class="mt-4 w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            <th class="pb-2">@lang('service_order::app.clientes.cliente')</th>
                            <th class="pb-2">@lang('service_order::app.clientes.documento')</th>
                            <th class="pb-2">@lang('service_order::app.clientes.telefones')</th>
                            <th class="pb-2">@lang('service_order::app.clientes.veiculos')</th>
                            <th class="pb-2"></th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($resultados as $cliente)
                            <tr class="border-b border-gray-100 align-top text-gray-800 dark:border-gray-900 dark:text-gray-200">
                                <td class="py-2 font-medium">
                                    {{ $cliente['nome'] }}
                                    @if ($cliente['codigo'])
                                        <span style="margin-left:4px;padding:1px 6px;border-radius:4px;background:#e2e8f0;color:#475569;font-size:11px;font-weight:600" title="Código do cliente">Nº {{ $cliente['codigo'] }}</span>
                                    @endif
                                    @include('service_order::partials.cliente-etiquetas', ['credito' => $cliente['credito']])
                                </td>
                                <td class="py-2">{{ $cliente['documento'] ?: '-' }}</td>
                                <td class="py-2">{{ $cliente['telefones'] ?: '-' }}</td>
                                <td class="py-2">
                                    @forelse ($cliente['veiculos'] as $veiculo)
                                        <div>{{ $veiculo }}</div>
                                    @empty
                                        -
                                    @endforelse
                                </td>
                                <td class="py-2 text-right">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @if (bouncer()->hasPermission('service_orders.create'))
                                            <a href="{{ $cliente['abrir_os'] }}" class="primary-button">@lang('service_order::app.clientes.nova-os-btn')</a>
                                        @endif

                                        @if (bouncer()->hasPermission('cadastro'))
                                            <a href="{{ $cliente['nova_os'] }}" class="secondary-button">@lang('service_order::app.clientes.cadastro-btn')</a>
                                        @endif

                                        @if (bouncer()->hasPermission('contacts.persons.view'))
                                            <a href="{{ $cliente['ficha'] }}" class="secondary-button">@lang('service_order::app.clientes.ficha-btn')</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-admin::layouts>
