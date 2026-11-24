@php
    // Passos da OS: a tela abre no passo da etapa atual e mostra só o que é dele (pedido do patrão, 19/11/2026).
    $fluxoDaOs = \Webkul\ServiceOrder\Models\ServiceOrder::stagesFlow();
    $abaInicial = match (true) {
        in_array($serviceOrder->workshop_status, $fluxoDaOs, true) => $serviceOrder->workshop_status,
        $serviceOrder->workshop_status === 'Concluída' => 'Pós-Venda',
        $serviceOrder->workshop_status === 'Cancelada' => 'historico',
        default => 'Cadastro',
    };

    // Atributos de uma parte da tela que pertence a um ou mais passos ("Diagnóstico" ou "Cadastro|Ordem de Serviço").
    $painel = function (string $passos, string $exibicao = '') use ($abaInicial): string {
        $visivel = in_array($abaInicial, explode('|', $passos), true);
        $estilo = $visivel ? ($exibicao !== '' ? 'display:'.$exibicao : '') : 'display:none';

        return 'data-painel="'.e($passos).'"'
            .($exibicao !== '' ? ' data-painel-display="'.e($exibicao).'"' : '')
            .($estilo !== '' ? ' style="'.$estilo.'"' : '');
    };
@endphp

<x-admin::layouts>
    <x-slot:title>
        {{ $serviceOrder->tituloAtual() }}
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="service_orders.view" :entity="$serviceOrder" />

                <div class="text-xl font-bold dark:text-white">
                    {{ $serviceOrder->tituloAtual() }}
                </div>
            </div>

            <div class="flex items-center gap-x-2.5">
                @if (bouncer()->hasPermission('service_orders.edit'))
                    <a href="{{ route('admin.service_orders.edit', $serviceOrder->id) }}" class="secondary-button">
                        @lang('service_order::app.index.datagrid.edit')
                    </a>
                @endif

                <a href="{{ route('admin.service_orders.print', $serviceOrder->id) }}" class="primary-button">
                    @lang('service_order::app.view.print-btn')
                </a>

                @if ($canSeeValues && $serviceOrder->workshop_status === 'Concluída' && bouncer()->hasPermission('fiscal_documents.emit'))
                    @if (! $fiscalDocument)
                        <a href="{{ route('admin.fiscal_documents.create', $serviceOrder->id) }}" class="secondary-button">
                            @lang('fiscal::app.emit-btn')
                        </a>
                    @elseif ($fiscalDocument->status === \Webkul\Fiscal\Models\FiscalDocument::STATUS_CANCELADO)
                        <a href="{{ route('admin.fiscal_documents.create', $serviceOrder->id) }}" class="secondary-button">
                            Emitir Nova NFS-e
                        </a>
                    @else
                        <a href="{{ route('admin.fiscal_documents.view', $fiscalDocument->id) }}" class="secondary-button">
                            NFS-e:
                            @switch($fiscalDocument->status)
                                @case(\Webkul\Fiscal\Models\FiscalDocument::STATUS_AUTORIZADO)
                                    Autorizada
                                    @break
                                @case(\Webkul\Fiscal\Models\FiscalDocument::STATUS_ERRO)
                                    Erro
                                    @break
                                @default
                                    Processando
                            @endswitch
                        </a>
                    @endif

                    @if ($serviceOrder->items->where('tipo', 'Peça')->isNotEmpty())
                        @if (! $fiscalDocumentNfe)
                            <a href="{{ route('admin.fiscal_documents.create-nfe-produto', $serviceOrder->id) }}" class="secondary-button">
                                @lang('fiscal::app.emit-btn-nfe')
                            </a>
                        @elseif ($fiscalDocumentNfe->status === \Webkul\Fiscal\Models\FiscalDocument::STATUS_CANCELADO)
                            <a href="{{ route('admin.fiscal_documents.create-nfe-produto', $serviceOrder->id) }}" class="secondary-button">
                                Emitir Nova NF-e
                            </a>
                        @else
                            <a href="{{ route('admin.fiscal_documents.view', $fiscalDocumentNfe->id) }}" class="secondary-button">
                                NF-e:
                                @switch($fiscalDocumentNfe->status)
                                    @case(\Webkul\Fiscal\Models\FiscalDocument::STATUS_AUTORIZADO)
                                        Autorizada
                                        @break
                                    @case(\Webkul\Fiscal\Models\FiscalDocument::STATUS_ERRO)
                                        Erro
                                        @break
                                    @default
                                        Processando
                                @endswitch
                            </a>
                        @endif
                    @endif
                @endif
            </div>
        </div>

        {{-- Passos da OS: Cliente > Ordem de Serviço > Diagnóstico > Orçamento... cada um mostra só o que é dele. --}}
        @include('service_order::partials.os-etapas-abas')

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" id="dados-os">
            <div class="grid grid-cols-4 gap-4 max-sm:grid-cols-1">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('service_order::app.form.person')</p>
                    <p class="text-base font-medium dark:text-white">
                        {{ $serviceOrder->person->name ?? '-' }}
                        @if ($serviceOrder->person?->codigo)
                            <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300" title="Código do cliente">Nº {{ $serviceOrder->person->codigo }}</span>
                        @endif

                        {{-- Etiquetas discretas: cor de crédito e cadastro vencido (só para quem pode ver crédito). --}}
                        @if ($serviceOrder->person_id)
                            @include('service_order::partials.cliente-etiquetas', ['credito' => app(\Webkul\ServiceOrder\Services\ClienteBusca::class)->porId((int) $serviceOrder->person_id)['credito'] ?? null])
                        @endif
                    </p>

                    @php
                        $contatosCliente = \Webkul\ServiceOrder\Services\ClienteContatos::linhaTelefones($serviceOrder->person?->contact_numbers);
                        $enderecoCliente = \Webkul\ServiceOrder\Services\ClienteContatos::enderecoDe($serviceOrder->person?->id) ?? [];
                        $localCliente = collect([\Webkul\ServiceOrder\Services\ClienteContatos::linhaEndereco($enderecoCliente), collect([$enderecoCliente['city'] ?? null, $enderecoCliente['state'] ?? null])->filter()->implode('/'), $enderecoCliente['postcode'] ?? null])->filter()->implode(' · ');
                    @endphp

                    @if ($contatosCliente)
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $contatosCliente }}</p>
                    @endif

                    @if ($localCliente)
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $localCliente }}</p>
                    @endif
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('service_order::app.form.vehicle')</p>
                    <p class="text-base font-medium dark:text-white">{{ $serviceOrder->vehicle->placa ?? '-' }} &middot; {{ $serviceOrder->vehicle->marca ?? '' }} {{ $serviceOrder->vehicle->modelo ?? '' }}</p>
                </div>

                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">@lang('service_order::app.form.mechanic')</p>
                    <p class="text-base font-medium dark:text-white">{{ $serviceOrder->mechanic->nome ?? '-' }}</p>
                </div>

                {{-- Na etapa de Diagnóstico não há previsão de conclusão (pedido do patrão, 19/11/2026). --}}
                @if ($serviceOrder->workshop_status !== \Webkul\ServiceOrder\Models\ServiceOrder::STAGE_DIAGNOSTICO)
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">@lang('service_order::app.form.data_previsao')</p>
                        <p class="text-base font-medium dark:text-white">{{ $serviceOrder->previsaoTexto() ?? '-' }}</p>
                    </div>
                @endif

            </div>

            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                <p class="text-sm text-gray-500 dark:text-gray-400">@lang('service_order::app.form.description')</p>
                <p class="whitespace-pre-line text-base dark:text-white">{{ $serviceOrder->description ?? '-' }}</p>
            </div>

            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                @include('service_order::partials.progress', ['serviceOrder' => $serviceOrder])
            </div>

            <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                @include('service_order::partials.tracking-link', ['serviceOrder' => $serviceOrder])
            </div>
        </div>

        {{-- O passo escolhido: UM cartão só, com as seções do passo uma embaixo da outra (menos painéis, pedido do patrão). --}}
        <div id="painel-passo" class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" data-painel-pai data-pai-display="flex" style="display:flex;flex-direction:column">

        {{-- Defeito relatado pelo motorista: do Cadastro até o Diagnóstico. --}}
        <div {!! $painel('Cadastro|Ordem de Serviço|Diagnóstico') !!}>
            @include('service_order::partials.defeito-relatado', ['serviceOrder' => $serviceOrder])
        </div>

        <div {!! $painel('Cadastro|Ordem de Serviço') !!}>
            @include('service_order::partials.checklist-entrada', ['serviceOrder' => $serviceOrder])
        </div>

        {{-- O "+" da OS: anotação, fotos, vídeos e outros arquivos. --}}
        <div {!! $painel('anexos') !!}>
            @include('service_order::partials.extras', ['serviceOrder' => $serviceOrder])
        </div>

        <div {!! $painel('Orçamento|Negociação|Em Execução|Faturamento') !!} class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900" id="pecas-servicos">
            <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">
                @lang('service_order::app.view.items-title')
            </p>

            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                Aqui se decide o que será feito: os serviços vêm do catálogo de Serviços e as peças, do estoque.
            </p>

            @if ($serviceOrder->diagnostico_o_que_fazer)
                <div class="mb-3 rounded-md bg-gray-50 p-3 dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.diagnostico.o_que_fazer') (decisão do diagnóstico)</p>
                    <p class="whitespace-pre-line text-sm dark:text-white">{{ $serviceOrder->diagnostico_o_que_fazer }}</p>
                </div>
            @endif

            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="pb-2">@lang('service_order::app.view.tipo')</th>
                        <th class="pb-2">@lang('service_order::app.view.descricao')</th>
                        <th class="pb-2">@lang('service_order::app.view.quantidade')</th>
                        @if ($canSeeValues)
                            <th class="pb-2">@lang('service_order::app.view.preco_unitario')</th>
                            <th class="pb-2">Desconto</th>
                            <th class="pb-2">@lang('service_order::app.view.total')</th>
                        @endif
                        <th class="pb-2">@lang('service_order::app.alerta.title')</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($serviceOrder->items as $item)
                        <tr class="border-b border-gray-100 text-gray-800 dark:border-gray-900 dark:text-gray-200">
                            <td class="py-2">{{ trans('service_order::app.form.tipo-options.'.$item->tipo) }}</td>
                            <td class="py-2">{{ $item->name }}</td>
                            <td class="py-2">{{ $item->tipo === 'Serviço' ? ($item->hours ?? $item->quantity).'h' : $item->quantity }}</td>
                            @if ($canSeeValues)
                                <td class="py-2">{{ core()->formatBasePrice($item->price) }}</td>
                                <td class="py-2">
                                    @if ($item->discount_total > 0)
                                        <span style="color:#dc2626">- {{ core()->formatBasePrice($item->discount_total) }}</span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="py-2">{{ core()->formatBasePrice($item->line_total) }}</td>
                            @endif
                            <td class="py-2">
                                @if ($serviceOrder->workshop_status === 'Concluída')
                                    @if ($item->alerta === 'Crítico')
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900/30 dark:text-red-400" title="{{ $item->alerta_observacao }}">
                                            {{ trans('service_order::app.alerta.Crítico') }}
                                        </span>
                                    @elseif ($item->alerta === 'Atenção')
                                        <span class="rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-semibold text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400" title="{{ $item->alerta_observacao }}">
                                            {{ trans('service_order::app.alerta.Atenção') }}
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-400">{{ trans('service_order::app.alerta.none') }}</span>
                                    @endif
                                @else
                                    <form method="POST" action="{{ route('admin.service_orders.items.alerta.update', [$serviceOrder->id, $item->id]) }}" class="flex items-center gap-1">
                                        @csrf
                                        @method('PUT')
                                        <select name="alerta" class="rounded-md border border-gray-300 px-1.5 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                            <option value="" @selected(! $item->alerta)>{{ trans('service_order::app.alerta.none') }}</option>
                                            <option value="Atenção" @selected($item->alerta === 'Atenção')>{{ trans('service_order::app.alerta.Atenção') }}</option>
                                            <option value="Crítico" @selected($item->alerta === 'Crítico')>{{ trans('service_order::app.alerta.Crítico') }}</option>
                                        </select>
                                        <input type="text" name="alerta_observacao" value="{{ $item->alerta_observacao }}" placeholder="Motivo" class="w-24 rounded-md border border-gray-300 px-1.5 py-1 text-xs dark:border-gray-800 dark:bg-gray-900" />
                                        <button type="submit" class="text-xs text-blue-600 hover:underline">OK</button>
                                    </form>
                                @endif
                            </td>
                            <td class="py-2">
                                @if ($serviceOrder->workshop_status !== 'Concluída')
                                    <form method="POST" action="{{ route('admin.service_orders.items.destroy', [$serviceOrder->id, $item->id]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:underline">&times;</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-2 text-gray-500 dark:text-gray-400">@lang('service_order::app.view.no-items')</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($canSeeValues)
                    <tfoot>
                        <tr class="border-t border-gray-200 dark:border-gray-800">
                            <td colspan="5" class="py-2 text-right font-semibold text-gray-800 dark:text-white">@lang('service_order::app.view.sub-total')</td>
                            <td class="py-2 font-semibold text-gray-800 dark:text-white">{{ core()->formatBasePrice($serviceOrder->sub_total) }}</td>
                            <td colspan="2"></td>
                        </tr>
                        @if (($orderDiscount = round((float) $serviceOrder->sub_total - (float) $serviceOrder->grand_total, 2)) > 0)
                            <tr>
                                <td colspan="5" class="py-2 text-right font-semibold text-gray-800 dark:text-white">Descontos</td>
                                <td class="py-2 font-semibold" style="color:#dc2626">- {{ core()->formatBasePrice($orderDiscount) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        @endif
                        <tr>
                            <td colspan="5" class="py-2 text-right font-bold text-gray-800 dark:text-white">@lang('service_order::app.view.grand-total')</td>
                            <td class="py-2 font-bold text-gray-800 dark:text-white">{{ core()->formatBasePrice($serviceOrder->grand_total) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>

            @if ($serviceOrder->workshop_status !== 'Concluída' && $canSeeValues)
                <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                    <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">
                        @lang('service_order::app.view.add-item')
                    </p>

                    @php
                        // B5: com o piloto ligado o serviço entra com o valor da hora da Ficha e avisa valor fora da faixa.
                        $valorHora = $pilot ? app(\Webkul\ServiceOrder\Services\ValorHoraService::class) : null;
                    @endphp

                    <form method="POST" action="{{ route('admin.service_orders.items.store', $serviceOrder->id) }}" class="flex flex-wrap items-end gap-2" id="add-item-form"
                        data-ficha="{{ $valorHora?->valorDaFicha() }}" data-limite="{{ $valorHora?->limiteSuperior() }}" data-multiplo="{{ $valorHora?->multiplo() }}">
                        @csrf

                        {{-- Busca de peças e serviços por nome ou código, e atalhos para cadastrar. --}}
                        @include('service_order::partials.item-busca')

                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.view.tipo')</label>
                            <select name="tipo" id="item-tipo" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                <option value="Peça">@lang('service_order::app.form.tipo-options.Peça')</option>
                                <option value="Serviço">@lang('service_order::app.form.tipo-options.Serviço')</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-1" id="part-field">
                            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.view.part')</label>
                            <select name="part_id" id="item-part" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                <option value="">-</option>
                                @foreach ($parts as $part)
                                    <option value="{{ $part->id }}" data-price="{{ $part->valor_unitario }}" data-nome="{{ $part->nome }}">{{ $part->codigo }} - {{ $part->nome }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex flex-col gap-1" id="service-field" style="display:none">
                            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.view.service-catalog')</label>
                            <select id="item-service" name="service_id" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                <option value="">@lang('service_order::app.view.service-catalog-custom')</option>
                                @foreach ($services as $service)
                                    <option value="{{ $service->id }}" data-price="{{ $service->preco_padrao }}" data-horas="{{ $service->tempo_padrao_horas }}" data-nome="{{ $service->nome }}">{{ $service->nome }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex flex-col gap-1" id="descricao-field" style="display:none">
                            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.view.descricao')</label>
                            <input type="text" name="descricao" id="item-descricao" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                        </div>

                        <div class="flex flex-col gap-1" id="quantidade-field">
                            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.view.quantidade')</label>
                            <input type="number" name="quantidade" id="item-quantidade" value="1" min="1" class="w-20 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                        </div>

                        <div class="flex flex-col gap-1" id="hours-field" style="display:none">
                            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.view.hours')</label>
                            <input type="number" step="0.5" name="hours" id="item-hours" class="w-20 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-gray-500 dark:text-gray-400">Executado por</label>
                            <select name="funcionario_id" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                <option value="">-</option>
                                @foreach ($mechanics as $mechanic)
                                    <option value="{{ $mechanic->id }}" @selected($serviceOrder->mechanic_id == $mechanic->id)>{{ $mechanic->nome }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-gray-500 dark:text-gray-400">@lang('service_order::app.view.preco_unitario')</label>
                            <input type="text" data-money name="preco_unitario" id="item-preco" class="w-32 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                            <p id="item-aviso-hora" class="max-w-xs text-xs font-semibold text-red-600" style="display:none"></p>
                        </div>

                        <label class="flex items-center gap-1 pb-2 text-xs text-gray-600 dark:text-gray-300" title="Marque quando o item foi identificado depois do orçamento original">
                            <input type="checkbox" name="adicional" value="1" /> Item adicional
                        </label>

                        <input type="hidden" name="descricao" id="item-descricao-hidden" />

                        <button type="submit" class="primary-button" id="add-item-submit">
                            @lang('service_order::app.view.add-item')
                        </button>
                    </form>
                </div>
            @endif
        </div>

        @if ($pilot)
            @include('service_order::partials.workflow', ['serviceOrder' => $serviceOrder, 'mechanics' => $mechanics, 'queixaTipos' => $queixaTipos, 'canSeeValues' => $canSeeValues])
        @endif

        {{-- Com o piloto ligado o diagnóstico é feito no bloco "Fluxo da OS" (com início, fim e evidências). --}}
        @unless ($pilot)
            <div {!! $painel('Diagnóstico') !!}>
                @include('service_order::partials.diagnostico', ['serviceOrder' => $serviceOrder])
            </div>
        @endunless

        <div {!! $painel('Em Execução') !!}>
            @include('service_order::partials.checklist', ['serviceOrder' => $serviceOrder])
        </div>

        {{-- Orçamento, negociação, cobrança: só para quem pode ver valores (perfil Técnico não vê). --}}
        @if ($canSeeValues)
            <div {!! $painel('Orçamento') !!}>
                @include('service_order::partials.orcamento-send', ['serviceOrder' => $serviceOrder])
            </div>

            <div {!! $painel('Negociação') !!}>
                @include('service_order::partials.negociacao', ['serviceOrder' => $serviceOrder, 'mechanics' => $mechanics])
            </div>
        @endif

        <div {!! $painel('Em Execução') !!}>
            @include('service_order::partials.execucao', ['serviceOrder' => $serviceOrder])
        </div>

        <div {!! $painel('Veículo em Teste') !!}>
            @include('service_order::partials.teste', ['serviceOrder' => $serviceOrder])
        </div>

        @if ($canSeeValues)
            <div {!! $painel('Faturamento') !!}>
                @include('service_order::partials.faturamento', ['serviceOrder' => $serviceOrder])
            </div>
        @endif

        <div {!! $painel('Pós-Venda') !!}>
            @include('service_order::partials.pos-venda', ['serviceOrder' => $serviceOrder])
        </div>

        </div>{{-- fim do cartão do passo --}}
    </div>

    @push('scripts')
        <script>
            function toggleItemFields(tipo) {
                const isPeca = tipo === 'Peça';

                const partField = document.getElementById('part-field');
                const serviceField = document.getElementById('service-field');
                const descricaoField = document.getElementById('descricao-field');
                const quantidadeField = document.getElementById('quantidade-field');
                const hoursField = document.getElementById('hours-field');

                if (! partField) {
                    return;
                }

                partField.style.display = isPeca ? '' : 'none';
                serviceField.style.display = isPeca ? 'none' : '';
                descricaoField.style.display = isPeca ? 'none' : '';
                quantidadeField.style.display = isPeca ? '' : 'none';
                hoursField.style.display = isPeca ? 'none' : '';
            }

            function fillPartPrice(select) {
                const option = select.options[select.selectedIndex];
                window.vmMoney.set(document.getElementById('item-preco'), option.dataset.price || '');
            }

            function fillServiceFromCatalog(select) {
                const option = select.options[select.selectedIndex];

                if (! option.value) {
                    return;
                }

                const form = document.getElementById('add-item-form');
                const ficha = form && form.dataset.ficha ? form.dataset.ficha : '';

                document.getElementById('item-descricao').value = option.dataset.nome || '';

                // Piloto ligado: o valor da hora vem da Ficha e as horas, do tempo padrão do catálogo.
                window.vmMoney.set(document.getElementById('item-preco'), ficha || option.dataset.price || '');

                if (option.dataset.horas) {
                    document.getElementById('item-hours').value = option.dataset.horas;
                }

                avisarValorDaHora();
            }

            // Aviso na tela: valor da hora acima de N vezes o valor da Ficha (provável erro de digitação).
            function avisarValorDaHora() {
                const form = document.getElementById('add-item-form');
                const aviso = document.getElementById('item-aviso-hora');
                const campo = document.getElementById('item-preco');

                if (! form || ! aviso || ! campo) {
                    return;
                }

                const limite = parseFloat(form.dataset.limite || '');
                const valor = parseFloat(String(campo.value).replace(/[^\d,]/g, '').replace(',', '.'));
                const servico = document.getElementById('item-tipo').value === 'Serviço';

                if (servico && limite && valor > limite) {
                    aviso.textContent = 'Valor da hora acima de ' + String(form.dataset.multiplo || '3').replace('.', ',') + ' vezes o valor da Ficha. Confira se não foi erro de digitação.';
                    aviso.style.display = '';
                } else {
                    aviso.style.display = 'none';
                }
            }

            function prepareItemSubmit() {
                const tipo = document.getElementById('item-tipo').value;

                if (tipo === 'Peça') {
                    const partSelect = document.getElementById('item-part');
                    const option = partSelect.options[partSelect.selectedIndex];
                    document.getElementById('item-descricao-hidden').value = option ? option.dataset.nome : '';
                    document.getElementById('item-descricao-hidden').name = 'descricao';
                    document.getElementById('item-descricao').removeAttribute('name');
                } else {
                    document.getElementById('item-descricao-hidden').removeAttribute('name');
                    document.getElementById('item-descricao').name = 'descricao';
                }

                return true;
            }

            document.addEventListener('change', function (event) {
                if (! event.target) {
                    return;
                }

                if (event.target.id === 'item-tipo') {
                    toggleItemFields(event.target.value);
                }

                if (event.target.id === 'item-part') {
                    fillPartPrice(event.target);
                }

                if (event.target.id === 'item-service') {
                    fillServiceFromCatalog(event.target);
                }
            });

            document.addEventListener('input', function (event) {
                if (event.target && event.target.id === 'item-preco') {
                    avisarValorDaHora();
                }
            });

            document.addEventListener('submit', function (event) {
                if (event.target && event.target.id === 'add-item-form') {
                    if (! prepareItemSubmit()) {
                        event.preventDefault();
                    }
                }
            });

            toggleItemFields('Peça');
        </script>
    @endpush
</x-admin::layouts>
