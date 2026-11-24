<x-admin::layouts>
    <x-slot:title>
        Relatório de SMS
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Relatório de SMS</div>
            <a href="{{ route('admin.workshop.settings.edit') }}" class="secondary-button">Voltar</a>
        </div>

        @if ($simulado)
            <div class="rounded-lg border border-yellow-300 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
                <b>Modo simulado.</b> Nenhum SMS é enviado de verdade: esta lista mostra o que <b>teria</b> sido enviado. Para enviar de verdade, escolha o provedor e preencha as credenciais em Configurações &gt; Indicadores e alertas.
            </div>
        @endif

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-3 text-sm dark:text-white">
                Este mês: <b>{{ $mes }}</b> SMS de um limite de <b>{{ $limite }}</b>
                &middot; custo estimado <b>R$ {{ number_format($custoMes, 2, ',', '.') }}</b>
            </p>

            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800">
                        <th class="pb-2">Quando</th>
                        <th class="pb-2">Telefone</th>
                        <th class="pb-2">Mensagem</th>
                        <th class="pb-2">Provedor</th>
                        <th class="pb-2">Situação</th>
                        <th class="pb-2 text-right">Custo</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr class="border-b border-gray-100 dark:border-gray-900 dark:text-gray-200">
                            <td class="py-1.5">{{ $log->created_at->format('d/m/Y H:i') }}</td>
                            <td class="py-1.5">{{ $log->telefone }}</td>
                            <td class="py-1.5">{{ $log->mensagem }}</td>
                            <td class="py-1.5">{{ $log->provedor }}</td>
                            <td class="py-1.5">
                                @if ($log->status === 'simulado')
                                    Simulado <span class="text-xs text-gray-500">(não enviado de verdade)</span>
                                @elseif ($log->status === 'enviado')
                                    Enviado
                                @elseif ($log->status === 'bloqueado')
                                    {{ str_contains((string) $log->detalhe, 'Limite') ? 'Não enviado: limite mensal atingido' : 'Não enviado: sem celular ou sem aceite de SMS' }}
                                @elseif ($log->status === 'falhou')
                                    Falhou @if ($log->detalhe)<span class="text-xs text-gray-500">({{ $log->detalhe }})</span>@endif
                                @else
                                    {{ $log->status }}
                                @endif
                            </td>
                            <td class="py-1.5 text-right">R$ {{ number_format($log->custo, 4, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-2 text-gray-500">Nenhum SMS ainda.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-4">{{ $logs->links() }}</div>
        </div>
    </div>
</x-admin::layouts>
