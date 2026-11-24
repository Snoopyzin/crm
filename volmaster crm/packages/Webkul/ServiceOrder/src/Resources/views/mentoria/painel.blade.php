@php
    $caixa = 'box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900';
    $titulo = 'mb-3 text-base font-semibold text-gray-800 dark:text-white';
@endphp

<x-admin::layouts>
    <x-slot:title>
        Painel do Dono
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div>
                <div class="text-xl font-bold dark:text-white">Painel do Dono</div>
                <p class="text-sm text-gray-500">{{ $painel['periodo_rotulo'] }} · fase {{ \Webkul\ServiceOrder\Services\FichaEnquadramento::fase($periodo) }} da mentoria ({{ \Webkul\ServiceOrder\Services\FichaEnquadramento::fase($periodo) === 1 ? 'processo' : 'processo + resultado' }})</p>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-3 text-sm">
                <input type="month" name="periodo" value="{{ $periodo }}" onchange="this.form.submit()" class="rounded-md border border-gray-300 px-2 py-1.5 dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                <label class="flex items-center gap-1 dark:text-gray-300"><input type="checkbox" name="comparar" value="1" @checked($comparar) onchange="this.form.submit()" /> Comparar com o mês anterior</label>
                <a href="{{ route('admin.mentoria.painel.pdf', ['periodo' => $periodo]) }}" class="secondary-button">PDF para o encontro de mentoria</a>
                <a href="{{ route('admin.mentoria.painel.celular') }}" class="secondary-button">Versão do celular</a>
            </form>
        </div>

        {{-- Bloco 1 - Resultado do mês --}}
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ($painel['cartoes'] as $cartao)
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800" style="background: {{ $cartao['cores']['fundo'] }}; color: {{ $cartao['cores']['texto'] }};">
                    <p class="text-xs uppercase opacity-80">{{ $cartao['rotulo'] }}</p>
                    <p class="mt-1 text-2xl font-bold">{{ $cartao['valor'] }}</p>
                </div>
            @endforeach
        </div>

        <div class="{{ $caixa }}">
            <p class="{{ $titulo }}">Indicadores da DRE e da precificação</p>
            @include('service_order::mentoria._linhas', ['linhas' => $painel['dre'], 'periodo' => $periodo])
            <p class="mt-2 text-xs text-gray-500">Detalhes em <a class="text-blue-600 hover:underline" href="{{ route('admin.financeiro.dre', ['periodo' => $periodo]) }}">DRE e preços</a>.</p>
        </div>

        {{-- Bloco 2 - Fluxo do caminhão --}}
        <div class="{{ $caixa }}">
            <p class="{{ $titulo }}">Fluxo do caminhão (8 etapas)</p>

            <p class="mb-3 rounded-md bg-gray-50 px-3 py-2 text-sm dark:bg-gray-800 dark:text-gray-200">
                <b>{{ $painel['fluxo']['no_patio'] }}</b> caminhões no pátio agora &middot;
                <b class="{{ $painel['fluxo']['parados'] ? 'text-red-600' : '' }}">{{ $painel['fluxo']['parados'] }}</b> parados há mais de 3 dias
            </p>

            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                @foreach ($painel['fluxo']['etapas'] as $etapa)
                    <div class="rounded-md border border-gray-200 p-3 dark:border-gray-800">
                        <p class="mb-2 flex items-center justify-between text-sm font-semibold dark:text-white">
                            <span>{{ $etapa['etapa'] }}</span>
                            <span class="text-xs font-normal text-gray-500">{{ $etapa['agora'] }} agora{{ $etapa['parados'] ? ' · '.$etapa['parados'].' parados' : '' }}</span>
                        </p>
                        @include('service_order::mentoria._linhas', ['linhas' => $etapa['indicadores'], 'periodo' => $periodo])
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Bloco 3 - Peças e estoque --}}
        <div class="{{ $caixa }}">
            <p class="{{ $titulo }}">Peças e estoque <span class="text-sm font-normal text-gray-500">&middot; valor total do estoque: R$ {{ number_format($painel['pecas']['valor_estoque'], 2, ',', '.') }}</span></p>
            @include('service_order::mentoria._linhas', ['linhas' => $painel['pecas']['linhas'], 'periodo' => $periodo])
        </div>

        {{-- Bloco 4 - Caixa e financeiro --}}
        <div class="{{ $caixa }}">
            <p class="{{ $titulo }}">Caixa e financeiro</p>
            @include('service_order::mentoria._linhas', ['linhas' => $painel['financeiro']['linhas'], 'periodo' => $periodo])
        </div>

        {{-- Bloco 5 - Gestão do gerente e RH --}}
        <div class="{{ $caixa }}">
            <p class="{{ $titulo }}">Gestão do gerente e RH</p>
            @include('service_order::mentoria._linhas', ['linhas' => $painel['gestao']['linhas'], 'periodo' => $periodo])
        </div>

        {{-- Bloco 6 - Equipe, bônus e o próprio dono --}}
        <div class="{{ $caixa }}">
            <p class="{{ $titulo }}">Equipe e bônus</p>

            <p class="mb-3 text-sm dark:text-gray-200">
                Fila de alçadas do dono:
                <a class="font-semibold text-blue-600 hover:underline" href="{{ route('admin.mentoria.alcadas') }}">{{ $painel['equipe']['alcadas_aguardando_dono'] }} aguardando</a>
                @if ($painel['equipe']['alcadas_atrasadas']) <span class="font-semibold text-red-600">({{ $painel['equipe']['alcadas_atrasadas'] }} fora do prazo de 24 h)</span> @endif
            </p>

            @if ($painel['equipe']['apuracoes'])
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                            <th class="pb-2">Colaborador</th><th class="pb-2">Cargo</th><th class="pb-2 text-right">Nota do mês</th><th class="pb-2">Faixa</th><th class="pb-2">Trava</th>
                            <th class="pb-2 text-right">Valor a pagar</th><th class="pb-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($painel['equipe']['apuracoes'] as $linha)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-1.5 dark:text-white">{{ $linha['colaborador'] }}</td>
                                <td class="py-1.5 text-xs text-gray-500">{{ \Webkul\ServiceOrder\Http\Controllers\WorkshopSettingsController::PAPEIS[$linha['cargo']] ?? $linha['cargo'] }}</td>
                                <td class="py-1.5 text-right font-semibold dark:text-white">{{ $linha['nota'] !== null ? number_format($linha['nota'], 1, ',', '.') : '—' }}</td>
                                <td class="py-1.5">{{ $linha['faixa'] }}%</td>
                                <td class="py-1.5">{!! $linha['trava'] ? '<span class="font-semibold text-red-600">sim</span>' : 'não' !!}</td>
                                <td class="py-1.5 text-right">{{ $linha['valor'] !== null ? 'R$ '.number_format($linha['valor'], 2, ',', '.') : '—' }}</td>
                                <td class="py-1.5 text-right">
                                    @if ($linha['pode_aprovar'])
                                        <form method="POST" action="{{ route('admin.mentoria.bonus.aprovar', $linha['id']) }}">@csrf <button class="primary-button" type="submit" @disabled(! $painel['equipe']['janela_aprovacao'])>Aprovar bônus</button></form>
                                    @else
                                        <span class="text-xs text-gray-500">{{ ['calculado' => 'aguardando o gerente', 'revisado' => '', 'aprovado' => 'aprovado'][$linha['status']] ?? '' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500">O botão de aprovar vale do dia 1 ao 5º dia útil do mês seguinte e só libera depois da revisão do gerente. Detalhes em <a class="text-blue-600 hover:underline" href="{{ route('admin.mentoria.bonus', ['periodo' => $periodo]) }}">Bônus</a>.</p>
            @else
                <p class="text-sm text-gray-500">Nenhuma nota de bônus calculada neste mês ainda (o cálculo é feito no dia 1 do mês seguinte).</p>
            @endif
        </div>

        <div class="{{ $caixa }}">
            <p class="{{ $titulo }}">O próprio dono</p>
            @include('service_order::mentoria._linhas', ['linhas' => $painel['dono']['linhas'], 'periodo' => $periodo])
        </div>

        @if ($anterior)
            <div class="{{ $caixa }}">
                <p class="{{ $titulo }}">Comparação com {{ $anterior['periodo_rotulo'] }}</p>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    @foreach ($anterior['cartoes'] as $cartao)
                        <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-800">
                            <p class="text-xs uppercase text-gray-500">{{ $cartao['rotulo'] }}</p>
                            <p class="text-lg font-semibold dark:text-white">{{ $cartao['valor'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-admin::layouts>
