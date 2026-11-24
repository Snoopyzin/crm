{{--
    Passo "Diagnóstico" da OS, numa seção só e em coluna (pedido do patrão, 19/11/2026: menos painéis,
    mais fácil). Ordem: situação e horários > descrição técnica e decisão > fotos e vídeos > finalizar.
    O que é secundário fica recolhido ("Código de falha ou medição", horários do chefe).

    Usado dentro de partials/workflow.blade.php (herda $serviceOrder, $painel, $gate, $inicio, $fim, $dt,
    $label, $input, $title, $ok, $pending, $canAudit, $queixaTipos e $causas).
--}}
<div {!! $painel('Diagnóstico') !!}>
    <p class="{{ $title }}">
        Diagnóstico
        @if ($serviceOrder->diagnostico_visto_status === 'aprovado')
            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700">aprovado pelo chefe</span>
        @elseif ($serviceOrder->diagnostico_visto_status === 'devolvido')
            <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">devolvido ao técnico</span>
        @elseif ($fim)
            <span class="rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-semibold text-yellow-800">aguardando visto do chefe</span>
        @elseif ($inicio)
            <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700">em andamento</span>
        @else
            {!! $pending !!}
        @endif
    </p>

    {{-- Situação: início, conclusão e, se ainda não começou, o botão de iniciar. --}}
    <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px 32px;margin-bottom:14px">
        <div>
            <p class="{{ $label }}">@lang('service_order::app.diagnostico.inicio')</p>
            <p class="text-sm font-medium dark:text-white">{{ $inicio ? $dt($inicio) : trans('service_order::app.diagnostico.nao-iniciado') }}</p>
        </div>

        <div>
            <p class="{{ $label }}">@lang('service_order::app.diagnostico.conclusao')</p>
            <p class="text-sm font-medium dark:text-white">
                {{ $fim ? $dt($fim) : trans('service_order::app.diagnostico.nao-concluido') }}
                @if ($fim) <span class="text-xs text-gray-500">({{ round($gate->diagnosticoMinutos($serviceOrder)) }} min)</span> @endif
            </p>
        </div>

        @if ($inicio && $serviceOrder->queixaTipo)
            <div>
                <p class="{{ $label }}">Queixa</p>
                <p class="text-sm dark:text-white">{{ $serviceOrder->queixaTipo->nome }} <span class="text-xs text-gray-500">(alvo {{ \Webkul\ServiceOrder\Services\ModoTeste::numero($serviceOrder->queixaTipo->tempoAlvoEfetivoMin()) }} min)</span></p>
            </div>
        @endif

        @if (! $inicio)
            <form method="POST" action="{{ route('admin.service_orders.workflow.diagnostico.start', $serviceOrder->id) }}" style="display:flex;align-items:flex-end;gap:8px">
                @csrf
                <div class="flex flex-col gap-1">
                    <label class="{{ $label }}">Tipo de queixa (define o tempo alvo)</label>
                    <select name="queixa_tipo_id" class="{{ $input }}">
                        <option value="">-</option>
                        @foreach ($queixaTipos as $tipo)
                            <option value="{{ $tipo->id }}" @selected($serviceOrder->queixa_tipo_id == $tipo->id)>{{ $tipo->nome }} ({{ \Webkul\ServiceOrder\Services\ModoTeste::numero($tipo->tempoAlvoEfetivoMin()) }} min)</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="primary-button">Iniciar diagnóstico</button>
            </form>
        @endif
    </div>

    {{-- O chefe de oficina informa ou corrige os horários (reunião de 19/11/2026). --}}
    @if ($canAudit)
        <details style="margin-bottom:14px">
            <summary class="{{ $label }}" style="cursor:pointer;color:#2563eb;font-weight:600">Informar ou corrigir os horários (chefe de oficina)</summary>

            <form method="POST" action="{{ route('admin.service_orders.workflow.diagnostico.horarios', $serviceOrder->id) }}" class="mt-2 flex flex-wrap items-end gap-2">
                @csrf
                <div class="flex flex-col gap-1">
                    <label class="{{ $label }}">@lang('service_order::app.diagnostico.inicio')</label>
                    <input type="datetime-local" name="diagnostico_inicio_em" value="{{ old('diagnostico_inicio_em', $inicio?->format('Y-m-d\TH:i')) }}" class="{{ $input }}" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="{{ $label }}">@lang('service_order::app.diagnostico.conclusao')</label>
                    <input type="datetime-local" name="diagnostico_fim_em" value="{{ old('diagnostico_fim_em', $fim?->format('Y-m-d\TH:i')) }}" class="{{ $input }}" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="{{ $label }}">Motivo (obrigatório se mudar um horário já registrado)</label>
                    <input type="text" name="justificativa" maxlength="500" class="w-64 {{ $input }}" />
                </div>
                <button type="submit" class="secondary-button">Salvar horários</button>
            </form>
        </details>
    @endif

    {{-- Descrição técnica e a decisão do diagnóstico: um formulário só. --}}
    <form method="POST" action="{{ route('admin.service_orders.workflow.laudo', $serviceOrder->id) }}" class="flex flex-col gap-1" style="margin-bottom:16px">
        @csrf
        <label class="{{ $label }}">@lang('service_order::app.diagnostico.diagnostico') (laudo do diagnóstico, preenchida pelo profissional)</label>
        <textarea name="diagnostico" rows="4" class="{{ $input }}" placeholder="O que foi encontrado e a causa provável">{{ $serviceOrder->diagnostico }}</textarea>
        <label class="{{ $label }}" style="margin-top:6px">@lang('service_order::app.diagnostico.o_que_fazer')</label>
        <textarea name="diagnostico_o_que_fazer" rows="3" class="{{ $input }}" placeholder="{{ trans('service_order::app.diagnostico.o_que_fazer-placeholder') }}">{{ $serviceOrder->diagnostico_o_que_fazer }}</textarea>
        <div style="margin-top:6px"><button type="submit" class="secondary-button">Salvar laudo</button></div>
    </form>

    {{-- Fotos e vídeos (valem como evidência do laudo). --}}
    <div style="margin-bottom:16px">
        @include('service_order::partials.diagnostico-midia', ['serviceOrder' => $serviceOrder])
    </div>

    {{-- Código de falha ou medição: recolhido, só abre se a pessoa precisar. --}}
    @php $nEvidencias = $serviceOrder->laudoItems->count(); @endphp

    <details style="margin-bottom:16px" @if ($nEvidencias) open @endif>
        <summary class="{{ $label }}" style="cursor:pointer;color:#2563eb;font-weight:600">Código de falha ou medição ({{ $nEvidencias }})</summary>

        <ul class="mt-2 flex flex-col gap-1 text-sm dark:text-white">
            @forelse ($serviceOrder->laudoItems as $evidencia)
                <li class="flex items-center gap-2">
                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-gray-800">{{ \Webkul\ServiceOrder\Models\ServiceOrderLaudoItem::TIPOS[$evidencia->tipo] ?? $evidencia->tipo }}</span>
                    {{ $evidencia->descricao }}@if ($evidencia->valor): <b>{{ $evidencia->valor }}</b>@endif
                    @if ($evidencia->attachment)
                        <a href="{{ $evidencia->attachment->url() }}" target="_blank"><img src="{{ $evidencia->attachment->url() }}" alt="Foto" class="h-10 w-10 rounded object-cover" /></a>
                    @endif
                </li>
            @empty
                <li class="text-gray-500">Nenhum código de falha ou medição lançado. Uma foto ou um vídeo acima também vale como evidência.</li>
            @endforelse
        </ul>

        @unless ($fim)
            <form method="POST" action="{{ route('admin.service_orders.workflow.evidence', $serviceOrder->id) }}" enctype="multipart/form-data" class="mt-2 flex flex-wrap items-end gap-2">
                @csrf
                <div class="flex flex-col gap-1">
                    <label class="{{ $label }}">Tipo</label>
                    <select name="tipo" class="{{ $input }}">
                        @foreach (\Webkul\ServiceOrder\Models\ServiceOrderLaudoItem::TIPOS as $valor => $nome)
                            <option value="{{ $valor }}">{{ $nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label class="{{ $label }}">Código / descrição</label>
                    <input type="text" name="descricao" maxlength="255" class="w-44 {{ $input }}" placeholder="Ex.: P0203" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="{{ $label }}">Valor medido</label>
                    <input type="text" name="valor" maxlength="120" class="w-28 {{ $input }}" placeholder="Ex.: 2,1 V" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="{{ $label }}">Foto</label>
                    <input type="file" name="foto" accept="image/*" capture="environment" class="w-44 text-sm" />
                </div>
                <button type="submit" class="secondary-button">Adicionar</button>
            </form>
        @endunless
    </details>

    {{-- Finalizar: o passo mais importante, em destaque no fim do formulário. --}}
    @if ($inicio && ! $fim)
        <form method="POST" action="{{ route('admin.service_orders.workflow.diagnostico.finish', $serviceOrder->id) }}" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;margin-bottom:6px">
            @csrf
            <div class="flex flex-col gap-1">
                <label class="{{ $label }}">Causa do atraso (obrigatória se passou do tempo alvo)</label>
                <select name="causa_atraso" class="{{ $input }}">
                    <option value="">-</option>
                    @foreach ($causas as $causa)
                        <option value="{{ $causa }}">{{ $causa }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="primary-button">Finalizar diagnóstico</button>
        </form>

        <p class="text-xs text-gray-500" style="margin-bottom:12px">Para finalizar: a descrição técnica salva e ao menos uma evidência (foto, vídeo, código de falha ou medição).</p>
    @endif

    @if ($serviceOrder->diagnostico_causa_atraso)
        <p class="text-xs text-gray-500" style="margin-bottom:12px">Causa do atraso: <b>{{ $serviceOrder->diagnostico_causa_atraso }}</b></p>
    @endif

    {{-- Visto do chefe --}}
    @if ($fim && $canAudit)
        <form method="POST" action="{{ route('admin.service_orders.workflow.diagnostico.audit', $serviceOrder->id) }}" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;margin-bottom:12px">
            @csrf
            <div class="flex flex-col gap-1" style="flex:1;min-width:260px">
                <label class="{{ $label }}">Visto do chefe (se devolver, diga o que corrigir)</label>
                <input type="text" name="observacao" maxlength="500" class="{{ $input }}" placeholder="Observação" />
            </div>
            <button type="submit" name="status" value="aprovado" class="primary-button">Aprovar</button>
            <button type="submit" name="status" value="devolvido" class="secondary-button">Devolver ao técnico</button>
        </form>
    @endif

    {{-- Relação de peças: uma linha. --}}
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px">
        <p class="{{ $label }}">Relação de peças {!! $serviceOrder->relacao_pecas_em ? $ok.' <span class="text-xs text-gray-500">'.$dt($serviceOrder->relacao_pecas_em).'</span>' : $pending !!}</p>

        @unless ($serviceOrder->relacao_pecas_em)
            <form method="POST" action="{{ route('admin.service_orders.workflow.parts', $serviceOrder->id) }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:12px">
                @csrf
                <label class="flex items-center gap-2 text-sm dark:text-white">
                    <input type="checkbox" name="sem_pecas" value="1" /> Este serviço não precisa de peças
                </label>
                <button type="submit" class="secondary-button">Entregar relação de peças</button>
            </form>
        @endunless
    </div>

    @if ($serviceOrder->diagnostico)
        <form method="POST" action="{{ route('admin.service_orders.diagnostico.whatsapp', $serviceOrder->id) }}" style="margin-top:10px">
            @csrf
            <button type="submit" class="text-xs text-blue-600 hover:underline">Enviar diagnóstico ao cliente por WhatsApp</button>
        </form>
    @endif
</div>
