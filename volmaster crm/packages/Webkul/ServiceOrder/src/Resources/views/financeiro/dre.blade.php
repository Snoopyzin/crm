@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $reais = fn ($v) => $v === null ? '—' : 'R$ '.number_format((float) $v, 2, ',', '.');
    $cor = ['verde' => 'bg-green-100 text-green-700', 'amarelo' => 'bg-yellow-100 text-yellow-800', 'vermelho' => 'bg-red-100 text-red-700', 'sem_meta' => 'bg-gray-100 text-gray-500'];
    $card = 'box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900';
@endphp

<x-admin::layouts>
    <x-slot:title>
        DRE gerencial
    </x-slot>

    <div class="flex flex-col gap-4">
        @include('service_order::financeiro._abas')

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div>
                <div class="text-xl font-bold dark:text-white">DRE gerencial de {{ $periodo }}</div>
                <p class="text-sm text-gray-500">Montada com as notas emitidas, as peças lançadas na OS e as contas a pagar classificadas.
                    @if ($congelada) <b class="text-blue-700">Congelada no fechamento do mês.</b> @endif
                    @if ($semConta) <b class="text-red-600">{{ $semConta }} lançamento(s) sem conta ficam de fora.</b> @endif</p>
            </div>
            <form method="GET"><input type="month" name="periodo" value="{{ $periodo }}" onchange="this.form.submit()" class="{{ $campo }}" /></form>
        </div>

        <div class="{{ $card }} overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                        <th class="pb-2">Linha</th><th class="pb-2 text-right">Valor</th><th class="pb-2 text-right">% do fat.</th><th class="pb-2 text-right">Meta</th>
                        <th class="pb-2">Cor</th><th class="pb-2 text-right">Mês anterior</th><th class="pb-2 text-right">Média 3 meses</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($linhas as $linha)
                        <tr class="border-b border-gray-100 dark:border-gray-800 {{ $linha['nivel'] === 0 ? 'font-semibold' : '' }}">
                            <td class="py-1.5 dark:text-white {{ $linha['nivel'] ? 'pl-5' : '' }}">{{ $linha['rotulo'] }} @if ($linha['estimado'])<span class="rounded bg-yellow-100 px-1 text-xs font-normal text-yellow-800" title="Sem lançamentos: estimado pelos parâmetros da Ficha">estimado</span>@endif</td>
                            <td class="py-1.5 text-right dark:text-white">{{ $reais($linha['valor']) }}</td>
                            <td class="py-1.5 text-right text-gray-500">{{ $linha['percentual'] !== null ? number_format($linha['percentual'], 1, ',', '.').'%' : '—' }}</td>
                            <td class="py-1.5 text-right text-gray-500">{{ $reais($linha['meta']) }}</td>
                            <td class="py-1.5"><span class="rounded-full px-2 py-0.5 text-xs {{ $cor[$linha['situacao']] }}">{{ ['verde' => 'No alvo', 'amarelo' => 'Atenção', 'vermelho' => 'Abaixo', 'sem_meta' => 'Sem meta'][$linha['situacao']] }}</span></td>
                            <td class="py-1.5 text-right text-gray-500">{{ $reais($linha['mes_anterior']) }}</td>
                            <td class="py-1.5 text-right text-gray-500">{{ $reais($linha['media_3_meses']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($dre['pecas_sem_custo']) <p class="mt-2 text-xs text-yellow-700">{{ $dre['pecas_sem_custo'] }} peça(s) faturadas sem custo informado: o custo das peças está subestimado (Peças informa o custo ao precificar).</p> @endif
        </div>

        <div class="{{ $card }}">
            <p class="mb-2 text-base font-semibold dark:text-white">Ponto de equilíbrio</p>
            @if ($equilibrio['ponto_equilibrio'])
                <p class="text-sm dark:text-gray-200">
                    (Pessoal + despesas fixas) {{ $reais($equilibrio['custo_fixo']) }} ÷ margem de contribuição {{ number_format($equilibrio['margem_contribuicao_pct'], 1, ',', '.') }}% (média de 3 meses) =
                    <b>{{ $reais($equilibrio['ponto_equilibrio']) }}</b> de faturamento no mês.
                </p>
                <div class="my-2 h-4 w-full rounded bg-gray-200 dark:bg-gray-700"><div class="h-4 rounded {{ $equilibrio['dia_atingido'] ? 'bg-green-500' : 'bg-yellow-500' }}" style="width: {{ $equilibrio['percentual'] }}%"></div></div>
                <p class="text-sm dark:text-gray-200">
                    Faturado {{ $reais($equilibrio['faturado']) }} —
                    @if ($equilibrio['dia_atingido']) <b class="text-green-700">equilíbrio atingido no dia {{ $equilibrio['dia_atingido'] }}</b> @else <b class="text-red-600">faltam {{ $reais($equilibrio['faltam']) }}</b> @endif
                </p>
            @else
                <p class="text-sm text-gray-500">Sem dados suficientes: lance as despesas e fature algumas OS para calcular.</p>
            @endif
        </div>

        <div class="{{ $card }}">
            <p class="mb-2 text-base font-semibold dark:text-white">Preço mínimo da hora (memória de cálculo)</p>
            <table class="text-sm dark:text-gray-200">
                <tr><td class="pr-6">Pessoal da oficina (média 3 meses)</td><td class="text-right">{{ $reais($hora['pessoal_oficina']) }}</td></tr>
                <tr><td class="pr-6">+ Estrutura comum (pessoal administrativo + despesas fixas) × {{ number_format($hora['participacao_mao_de_obra'], 1, ',', '.') }}% de mão de obra</td><td class="text-right">{{ $reais($hora['estrutura_comum'] * $hora['participacao_mao_de_obra'] / 100) }}</td></tr>
                <tr class="font-semibold"><td class="pr-6">= Custo a cobrir pela mão de obra</td><td class="text-right">{{ $reais($hora['custo_a_cobrir']) }}</td></tr>
                <tr><td class="pr-6">+ Lucro desejado por mês</td><td class="text-right">{{ $reais($hora['lucro_desejado']) }}</td></tr>
                <tr><td class="pr-6">÷ Horas vendáveis ({{ $hora['tecnicos'] }} técnico(s) × {{ number_format($hora['horas_mes'], 0, ',', '.') }} h × {{ number_format($hora['produtividade'], 0, ',', '.') }}% de produtividade)</td><td class="text-right">{{ number_format($hora['horas_vendaveis'], 1, ',', '.') }} h</td></tr>
                <tr><td class="pr-6">÷ (1 − {{ number_format($hora['impostos_pct'], 1, ',', '.') }}% impostos − {{ number_format($hora['comissoes_pct'], 1, ',', '.') }}% comissões)</td><td></td></tr>
                <tr class="font-semibold"><td class="pr-6">= Preço mínimo da hora</td><td class="text-right">{{ $reais($hora['preco_minimo']) }}</td></tr>
                <tr><td class="pr-6">Hora cobrada hoje</td><td class="text-right">{{ $reais($hora['hora_cobrada']) }}</td></tr>
            </table>
            @if ($hora['hora_cobrada_sobre_minimo'] !== null)
                <p class="mt-2 text-sm dark:text-gray-200">
                    A hora cobrada é <b class="{{ $hora['hora_cobrada_sobre_minimo'] >= 100 ? 'text-green-700' : 'text-red-600' }}">{{ number_format($hora['hora_cobrada_sobre_minimo'], 1, ',', '.') }}%</b> do preço mínimo.
                    @if ($hora['perda_mensal'] > 0) Perda mensal estimada: <b class="text-red-600">{{ $reais($hora['perda_mensal']) }}</b>. @endif
                </p>
            @else
                <p class="mt-2 text-xs text-gray-500">Preencha o valor da hora, os impostos e o lucro desejado na Ficha de Enquadramento.</p>
            @endif
        </div>

        <div class="{{ $card }}">
            <p class="mb-2 text-base font-semibold dark:text-white">Fechamento da DRE</p>
            @if ($congelada)
                <form method="POST" action="{{ route('admin.financeiro.dre.reabrir') }}" class="flex flex-wrap items-center gap-2">
                    @csrf <input type="hidden" name="periodo" value="{{ $periodo }}" />
                    <input type="text" name="justificativa" required minlength="5" placeholder="Justificativa do financeiro" class="w-96 {{ $campo }}" />
                    <button type="submit" class="secondary-button">Pedir reabertura (precisa do dono)</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.financeiro.dre.congelar') }}">@csrf <input type="hidden" name="periodo" value="{{ $periodo }}" /><button type="submit" class="secondary-button">Congelar a DRE do mês</button></form>
                <p class="mt-1 text-xs text-gray-500">Congelada, a DRE não muda mais, mesmo que se lance algo depois.</p>
            @endif
        </div>
    </div>
</x-admin::layouts>
