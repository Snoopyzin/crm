@php
    $gate = app(\Webkul\ServiceOrder\Services\StageGate::class);
    $ops = app(\Webkul\ServiceOrder\Services\OperationService::class);

    $canAudit = bouncer()->hasPermission('service_orders.auditar');
    $inicio = $serviceOrder->diagnostico_inicio_em;
    $fim = $serviceOrder->diagnostico_concluido_em;
    $temVeiculo = (bool) $serviceOrder->vehicle_id;
    $operations = $serviceOrder->operations()->get();
    $margem = (float) \Webkul\ServiceOrder\Services\WorkshopSettings::get('tecnico_margem_percentual', 120);
    $causas = (array) \Webkul\ServiceOrder\Services\WorkshopSettings::get('causas_atraso_diagnostico', []);
    $motivosPausa = (array) \Webkul\ServiceOrder\Services\WorkshopSettings::get('motivos_pausa', []);
    $motivosAtraso = (array) \Webkul\ServiceOrder\Services\WorkshopSettings::get('motivos_atraso_operacao', []);
    $meios = (array) \Webkul\ServiceOrder\Services\WorkshopSettings::get('meios_aprovacao', []);

    $fmtHm = fn (int $seconds) => sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    $dt = fn ($value) => $value ? $value->format('d/m/Y H:i') : null;

    // Eventos que carregam dado financeiro/crédito: ficam fora da linha do tempo do técnico.
    $eventosRestritos = ['cor_credito_registrada', 'forma_pagamento_registrada', 'pagamento_confirmado', 'acordo_assinado', 'nf_emitida', 'cliente_aprovou', 'cliente_aprovou_parcial', 'cliente_recusou', 'orcamento_enviado', 'peca_precificada', 'desconto_aplicado', 'boleto_ou_cobranca_gerada', 'os_enviada_financeiro'];
    $events = $serviceOrder->events()->with('usuario')->get()->reject(fn ($e) => ! $canSeeValues && in_array($e->tipo_evento, $eventosRestritos, true));

    $input = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $label = 'text-xs text-gray-500 dark:text-gray-400';
    $card = 'box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900';
    $title = 'mb-3 text-sm font-semibold text-gray-800 dark:text-white';
    $ok = '<span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700">feito</span>';
    $pending = '<span class="rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-semibold text-yellow-800">pendente</span>';
@endphp

{{-- Fluxo da OS: as seções de cada passo, uma embaixo da outra. Cada passo grava data, hora e usuário. --}}
<div class="{{ $card }}" id="fluxo-os" data-painel-pai>
    <div style="display:flex;flex-direction:column">

        {{-- Passos simples da etapa atual (recepção, precificação, conferências...): aparecem no passo da etapa atual. --}}
        <div {!! $painel($serviceOrder->workshop_status ?: 'Cadastro', 'contents') !!}>
            @include('service_order::partials.workflow_actions')
        </div>

        {{-- Termo de entrada --}}
        @if ($temVeiculo)
            <div {!! $painel('Cadastro') !!} class="rounded-md border border-gray-200 p-3 dark:border-gray-800">
                <p class="{{ $title }}">Termo de entrada {!! $serviceOrder->termo_assinado_em ? $ok : $pending !!}</p>

                <form method="POST" action="{{ route('admin.service_orders.workflow.termo', $serviceOrder->id) }}" enctype="multipart/form-data" class="flex flex-col gap-2">
                    @csrf

                    <div class="flex flex-wrap gap-2">
                        <div class="flex flex-col gap-1">
                            <label class="{{ $label }}">Km de entrada</label>
                            <input type="number" min="0" name="km_entrada" value="{{ old('km_entrada', $serviceOrder->km_entrada) }}" required class="w-32 {{ $input }}" />
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="{{ $label }}">Combustível</label>
                            <select name="combustivel_entrada" required class="{{ $input }}">
                                <option value="">-</option>
                                @foreach (\Webkul\ServiceOrder\Models\ServiceOrder::COMBUSTIVEIS as $nivel)
                                    <option value="{{ $nivel }}" @selected($serviceOrder->combustivel_entrada === $nivel)>{{ $nivel }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="{{ $label }}">Itens deixados no veículo / avarias</label>
                        <textarea name="itens_entrada" rows="2" class="{{ $input }}">{{ $serviceOrder->itens_entrada }}</textarea>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="{{ $label }}">Fotos do veículo (várias)</label>
                        <input type="file" name="fotos[]" accept="image/*" multiple capture="environment" class="text-sm" />

                        @if ($serviceOrder->attachments->where('tipo', 'termo_foto')->isNotEmpty())
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach ($serviceOrder->attachments->where('tipo', 'termo_foto') as $foto)
                                    <a href="{{ $foto->url() }}" target="_blank"><img src="{{ $foto->url() }}" alt="Foto do termo" class="h-14 w-14 rounded object-cover" /></a>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-3 text-sm dark:text-white">
                        <span>Cliente autoriza o teste de rodagem?</span>
                        <label class="flex items-center gap-1"><input type="radio" name="autorizacao_rodagem" value="1" @checked($serviceOrder->autorizacao_rodagem) /> Sim</label>
                        <label class="flex items-center gap-1"><input type="radio" name="autorizacao_rodagem" value="0" @checked(! $serviceOrder->autorizacao_rodagem) /> Não</label>
                    </div>

                    <label class="flex items-center gap-2 text-sm dark:text-white">
                        <input type="checkbox" name="assinado" value="1" @checked($serviceOrder->termo_assinado_em) @disabled($serviceOrder->termo_assinado_em) />
                        Termo assinado pelo cliente
                        @if ($serviceOrder->termo_assinado_em)
                            <span class="text-xs text-gray-500">({{ $dt($serviceOrder->termo_assinado_em) }})</span>
                        @endif
                    </label>

                    <div><button type="submit" class="secondary-button">Salvar termo</button></div>
                </form>

                @if (\Webkul\ServiceOrder\Services\TermoEntrada::disponivel())
                    @php
                        $aceiteTermo = app(\Webkul\ServiceOrder\Services\TermoEntrada::class)->ultimoAceite($serviceOrder);
                        $linkTermo = session('termo_link');
                    @endphp

                    <div class="mt-3 border-t border-gray-200 pt-3 dark:border-gray-800">
                        <p class="mb-1 text-xs text-gray-500">Aceite pelo cliente (assinatura na tela ou link do WhatsApp, com data, hora e IP):</p>

                        <form method="POST" action="{{ route('admin.service_orders.workflow.termo_link', $serviceOrder->id) }}">
                            @csrf
                            <button type="submit" class="secondary-button">Gerar link do termo (WhatsApp ou tablet)</button>
                        </form>

                        @if ($linkTermo)
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <input type="text" readonly value="{{ $linkTermo['url'] }}" onfocus="this.select()" class="w-80 {{ $input }}" />
                                <a href="{{ $linkTermo['whatsapp'] }}" target="_blank" rel="noopener" class="font-semibold text-blue-600 hover:underline">Abrir no WhatsApp</a>
                                <span class="text-gray-500">válido até {{ $linkTermo['expira_em'] }}</span>
                            </div>
                        @endif

                        @if ($aceiteTermo)
                            <p class="mt-2 text-xs text-green-700">
                                Aceito por <b>{{ $aceiteTermo->aceite_nome }}</b> em {{ $aceiteTermo->aceito_em->format('d/m/Y H:i') }}
                                &middot; IP {{ $aceiteTermo->aceite_ip }}
                                &middot; teste de rodagem: {{ $aceiteTermo->autoriza_rodagem ? 'autorizado' : 'não autorizado' }}
                                @if ($aceiteTermo->assinatura) &middot; com assinatura na tela @endif
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- Cor de crédito (dado restrito) --}}
        @if ($canSeeValues && app(\Webkul\ServiceOrder\Services\Credito\CreditoCliente::class)->podeVer())
            @php
                $credito = app(\Webkul\ServiceOrder\Services\Credito\CreditoCliente::class);
                $sugestaoCredito = $serviceOrder->person_id && $credito::disponivel() ? $credito->sugestao($serviceOrder->person_id) : null;
            @endphp
            <div {!! $painel('Cadastro|Ordem de Serviço') !!} class="rounded-md border border-gray-200 p-3 dark:border-gray-800">
                <p class="{{ $title }}">Cor de crédito {!! $serviceOrder->cor_credito ? $ok : $pending !!}</p>

                @if ($sugestaoCredito)
                    <p class="mb-2 text-xs text-gray-500">O sistema sugere <b>{{ ucfirst($sugestaoCredito['cor']) }}</b>: {{ $sugestaoCredito['motivo'] }}.</p>
                @endif

                @if ($credito->podeRegistrar())
                    <form method="POST" action="{{ route('admin.service_orders.workflow.cor_credito', $serviceOrder->id) }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <div class="flex flex-col gap-1">
                            <label class="{{ $label }}">Resultado da análise do financeiro</label>
                            <select name="cor_credito" required class="{{ $input }}">
                                <option value="">-</option>
                                @foreach (\Webkul\ServiceOrder\Models\ServiceOrder::CORES_CREDITO as $valor => $nome)
                                    <option value="{{ $valor }}" @selected($serviceOrder->cor_credito === $valor)>{{ $nome }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="{{ $label }}">Observação</label>
                            <input type="text" name="observacao" maxlength="1000" class="{{ $input }}" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="{{ $label }}">Justificativa (se for mais branda que a sugestão)</label>
                            <input type="text" name="justificativa" maxlength="1000" class="{{ $input }}" />
                        </div>
                        <button type="submit" class="secondary-button">Registrar</button>
                    </form>
                @else
                    <p class="text-xs text-gray-500">A análise é registrada pelo Financeiro.</p>
                @endif

                @if ($serviceOrder->cor_credito_em)
                    <p class="mt-1 text-xs text-gray-500">Registrada em {{ $dt($serviceOrder->cor_credito_em) }}</p>
                @endif
            </div>
        @endif

        {{-- Designação do técnico --}}
        <div {!! $painel('Ordem de Serviço') !!} class="rounded-md border border-gray-200 p-3 dark:border-gray-800">
            <p class="{{ $title }}">Técnico designado {!! $serviceOrder->tecnicoDesignadoId() ? $ok : $pending !!}</p>

            <form method="POST" action="{{ route('admin.service_orders.workflow.designate', $serviceOrder->id) }}" class="flex items-end gap-2">
                @csrf
                <div class="flex flex-col gap-1">
                    <label class="{{ $label }}">Técnico do diagnóstico</label>
                    <select name="mechanic_id" required class="{{ $input }}">
                        <option value="">-</option>
                        @foreach ($mechanics as $mechanic)
                            <option value="{{ $mechanic->id }}" @selected($serviceOrder->tecnicoDesignadoId() == $mechanic->id)>{{ $mechanic->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="secondary-button">Designar</button>
            </form>
        </div>

        {{-- Diagnóstico: uma seção só, em coluna (pedido do patrão, 19/11/2026: menos painéis). --}}
        @include('service_order::partials.workflow-diagnostico')

        {{-- Orçamento enviado --}}
        <div {!! $painel('Orçamento') !!} class="rounded-md border border-gray-200 p-3 dark:border-gray-800">
            <p class="{{ $title }}">Orçamento enviado ao cliente {!! $serviceOrder->orcamento_enviado_em ? $ok : $pending !!}</p>
            <p class="mb-2 text-xs text-gray-500">O envio por WhatsApp ou e-mail pela própria OS já registra sozinho. Use abaixo para outro canal.</p>

            <form method="POST" action="{{ route('admin.service_orders.workflow.budget_sent', $serviceOrder->id) }}" class="flex items-end gap-2">
                @csrf
                <select name="canal" class="{{ $input }}">
                    @foreach (['WhatsApp', 'E-mail', 'Presencial', 'Outro'] as $canal)
                        <option value="{{ $canal }}">{{ $canal }}</option>
                    @endforeach
                </select>
                <button type="submit" class="secondary-button">Registrar envio</button>
            </form>

            @if ($serviceOrder->orcamento_enviado_em)
                <p class="mt-1 text-xs text-gray-500">Enviado em {{ $dt($serviceOrder->orcamento_enviado_em) }}</p>
            @endif
        </div>

        {{-- Aprovação do cliente --}}
        <div {!! $painel('Negociação') !!} class="rounded-md border border-gray-200 p-3 dark:border-gray-800">
            <p class="{{ $title }}">
                Resposta do cliente
                @if ($serviceOrder->aprovacao_em)
                    <span class="rounded-full {{ $serviceOrder->aprovacao_tipo === 'recusa' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' }} px-2 py-0.5 text-xs font-semibold">{{ $serviceOrder->aprovacao_tipo }}</span>
                @else
                    {!! $pending !!}
                @endif
            </p>

            <form method="POST" action="{{ route('admin.service_orders.workflow.approval', $serviceOrder->id) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <div class="flex flex-col gap-1">
                    <label class="{{ $label }}">Resposta</label>
                    <select name="tipo" class="{{ $input }}">
                        <option value="total">Aprovou tudo</option>
                        <option value="parcial">Aprovou em parte</option>
                        <option value="recusa">Recusou</option>
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="{{ $label }}">Meio</label>
                    <select name="meio" class="{{ $input }}">
                        @foreach ($meios as $meio)
                            <option value="{{ $meio }}">{{ $meio }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="{{ $label }}">Motivo (se recusou)</label>
                    <input type="text" name="motivo_recusa" maxlength="255" class="w-44 {{ $input }}" />
                </div>
                <button type="submit" class="secondary-button">Registrar</button>
            </form>

            @if ($serviceOrder->aprovacao_em)
                <p class="mt-1 text-xs text-gray-500">{{ $serviceOrder->aprovacao_meio }} &middot; {{ $dt($serviceOrder->aprovacao_em) }}@if ($serviceOrder->motivo_recusa) &middot; {{ $serviceOrder->motivo_recusa }}@endif</p>
            @endif
        </div>

        {{-- Faturamento: NF, pagamento e acordo --}}
        @if ($canSeeValues)
            <div {!! $painel('Faturamento|Pós-Venda') !!} class="rounded-md border border-gray-200 p-3 lg:col-span-2 dark:border-gray-800">
                @php
                    $temNf = $gate->hasNotaFiscal($serviceOrder);
                    $temPagto = $gate->hasPagamentoOuAcordo($serviceOrder);
                @endphp

                <p class="{{ $title }}">
                    Nota fiscal e pagamento para a saída
                    @if ($serviceOrder->isFrotaFaturamentoMensal())
                        <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700">frota com faturamento mensal: dispensa NF e pagamento na saída</span>
                    @endif
                </p>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                        <p class="{{ $label }}">Nota fiscal {!! $temNf ? $ok : $pending !!}</p>
                        <p class="mb-1 text-xs text-gray-500">Emitida pelo sistema (Focus) ou registrada aqui.</p>
                        @unless ($temNf)
                            <form method="POST" action="{{ route('admin.service_orders.workflow.nf', $serviceOrder->id) }}" class="flex items-end gap-2">
                                @csrf
                                <input type="text" name="numero" required maxlength="60" placeholder="Número da NF" class="w-36 {{ $input }}" />
                                <button type="submit" class="secondary-button">Registrar NF</button>
                            </form>
                        @endunless
                    </div>

                    <div>
                        <p class="{{ $label }}">Pagamento {!! $temPagto ? $ok : $pending !!}</p>
                        <p class="mb-1 text-xs text-gray-500">Cobrança paga (Asaas) ou confirmação manual.</p>
                        @unless ($serviceOrder->pagamento_confirmado_em || $serviceOrder->boletos->where('status', 'Pago')->isNotEmpty())
                            <form method="POST" action="{{ route('admin.service_orders.workflow.payment', $serviceOrder->id) }}">
                                @csrf
                                <button type="submit" class="secondary-button">Confirmar pagamento</button>
                            </form>
                        @endunless
                    </div>

                    <div>
                        <p class="{{ $label }}">Acordo de pagamento assinado {!! $serviceOrder->acordo_assinado_em ? $ok : $pending !!}</p>
                        <p class="mb-1 text-xs text-gray-500">Vale no lugar do pagamento.</p>
                        @unless ($serviceOrder->acordo_assinado_em)
                            <form method="POST" action="{{ route('admin.service_orders.workflow.agreement', $serviceOrder->id) }}">
                                @csrf
                                <button type="submit" class="secondary-button">Registrar acordo assinado</button>
                            </form>
                        @endunless
                    </div>
                </div>
            </div>
        @endif

        {{-- Operações (apontamento por serviço) --}}
        <div {!! $painel('Em Execução|Veículo em Teste') !!} class="rounded-md border border-gray-200 p-3 lg:col-span-2 dark:border-gray-800">
            <p class="{{ $title }}">Operações e tempos</p>

            @if ($operations->isEmpty())
                <p class="text-sm text-gray-500">Nenhum serviço lançado na OS.</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            <th class="pb-2">Operação</th>
                            <th class="pb-2">Padrão</th>
                            <th class="pb-2">Real</th>
                            <th class="pb-2">%</th>
                            <th class="pb-2">Situação</th>
                            <th class="pb-2">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($operations as $op)
                            @php
                                $situacao = $ops->status($op);
                                $cor = $ops->cor($op);
                                $pct = $ops->percentual($op);
                                $corClasse = ['verde' => 'bg-green-100 text-green-700', 'amarelo' => 'bg-yellow-100 text-yellow-800', 'vermelho' => 'bg-red-100 text-red-700'][$cor];
                            @endphp

                            <tr class="border-b border-gray-100 dark:border-gray-900 dark:text-gray-200">
                                <td class="py-2">{{ $op->name }}</td>
                                <td class="py-2">{{ $fmtHm($ops->padraoSeconds($op)) }}</td>
                                <td class="py-2">{{ $fmtHm($ops->workedSeconds($op)) }}</td>
                                <td class="py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $corClasse }}">{{ $pct !== null ? number_format($pct, 1, ',', '.') . '%' : '-' }}</span></td>
                                <td class="py-2">{{ ['nao_iniciada' => 'Não iniciada', 'em_andamento' => 'Em andamento', 'pausada' => 'Pausada', 'concluida' => 'Concluída'][$situacao] }}</td>
                                <td class="py-2">
                                    <div class="flex flex-wrap items-center gap-1">
                                        @if ($situacao === 'nao_iniciada')
                                            <form method="POST" action="{{ route('admin.service_orders.workflow.operation', [$serviceOrder->id, $op->id, 'iniciar']) }}">@csrf<button class="primary-button" type="submit">Iniciar</button></form>
                                        @elseif ($situacao === 'em_andamento')
                                            <form method="POST" action="{{ route('admin.service_orders.workflow.operation', [$serviceOrder->id, $op->id, 'pausar']) }}" class="flex items-center gap-1">
                                                @csrf
                                                <select name="motivo" required class="{{ $input }}">
                                                    @foreach ($motivosPausa as $motivo)<option value="{{ $motivo }}">{{ $motivo }}</option>@endforeach
                                                </select>
                                                <button class="secondary-button" type="submit">Pausar</button>
                                            </form>
                                        @elseif ($situacao === 'pausada')
                                            <form method="POST" action="{{ route('admin.service_orders.workflow.operation', [$serviceOrder->id, $op->id, 'retomar']) }}">@csrf<button class="primary-button" type="submit">Retomar</button></form>
                                        @endif

                                        @if (in_array($situacao, ['em_andamento', 'pausada'], true))
                                            <form method="POST" action="{{ route('admin.service_orders.workflow.operation', [$serviceOrder->id, $op->id, 'concluir']) }}" class="flex items-center gap-1">
                                                @csrf
                                                <select name="motivo_atraso" class="{{ $input }}" title="Obrigatório se passou de {{ $margem }}% do tempo padrão">
                                                    <option value="">Motivo (se atrasou)</option>
                                                    @foreach ($motivosAtraso as $motivo)<option value="{{ $motivo }}">{{ $motivo }}</option>@endforeach
                                                </select>
                                                <button class="primary-button" type="submit">Concluir</button>
                                            </form>
                                        @endif

                                        @if ($situacao === 'concluida' && $canAudit && $pct !== null && $pct > $margem)
                                            <form method="POST" action="{{ route('admin.service_orders.workflow.operation.confirm_delay', [$serviceOrder->id, $op->id]) }}">@csrf<button class="secondary-button" type="submit">Confirmar atraso</button></form>
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

    {{-- Linha do tempo --}}
    <div {!! $painel('historico') !!} class="mt-4 border-t border-gray-200 pt-3 dark:border-gray-800">
        <p class="{{ $title }}">Linha do tempo (data, hora e usuário de cada passo)</p>

        <ol class="max-h-72 overflow-auto text-sm dark:text-gray-200">
            @foreach ($events->reverse() as $evento)
                <li class="flex gap-3 border-b border-gray-100 py-1 dark:border-gray-900">
                    <span class="w-36 shrink-0 text-xs text-gray-500">{{ $evento->ocorrido_em->format('d/m/Y H:i:s') }}</span>
                    <span class="flex-1">
                        {{ $evento->rotulo }}
                        @if ($evento->tipo_evento === 'mudanca_etapa')
                            <span class="text-gray-500">({{ $evento->etapa_origem ?? 'início' }} &rarr; {{ $evento->etapa_destino }})</span>
                        @endif
                        @if ($evento->tipo_evento === 'bloqueio_forcado')
                            <span class="text-red-600">&mdash; {{ $evento->dados_json['justificativa'] ?? '' }}</span>
                        @endif
                    </span>
                    <span class="w-32 shrink-0 text-right text-xs text-gray-500">{{ $evento->usuario->name ?? 'sistema' }}</span>
                </li>
            @endforeach
        </ol>
    </div>
</div>
