@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $rotulo = 'text-xs text-gray-500 dark:text-gray-400';
    $reais = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $entradas = $fechamento ? json_decode($fechamento->entradas_json, true) : [];
@endphp

<x-admin::layouts>
    <x-slot:title>
        Caixa, conciliação e cobrança
    </x-slot>

    <div class="flex flex-col gap-4">
        @include('service_order::financeiro._abas')

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Caixa do dia {{ $dia->format('d/m/Y') }}</div>
            <form method="GET"><input type="date" name="dia" value="{{ $dia->toDateString() }}" onchange="this.form.submit()" class="{{ $campo }}" /></form>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-3 text-base font-semibold dark:text-white">Fechamento do caixa</p>

            @if ($fechamento)
                <p class="text-sm dark:text-gray-200">
                    Saldo do sistema {{ $reais($fechamento->saldo_sistema) }} · contado {{ $reais($fechamento->saldo_contado) }} ·
                    <b class="{{ abs($fechamento->diferenca) >= 0.005 ? 'text-red-600' : 'text-green-700' }}">diferença {{ $reais($fechamento->diferenca) }}</b>
                    @if ($fechamento->justificativa) · justificativa: {{ $fechamento->justificativa }} @endif
                </p>
            @else
                <form method="POST" action="{{ route('admin.financeiro.caixa.fechar') }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <input type="hidden" name="data" value="{{ $dia->toDateString() }}" />
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Saldo inicial</label><input type="number" step="0.01" name="saldo_inicial" value="{{ $saldoInicial }}" class="w-32 {{ $campo }}" /></div>
                    @foreach (['dinheiro' => 'Dinheiro', 'pix' => 'PIX', 'cartoes' => 'Cartões', 'boletos' => 'Boletos'] as $chave => $nome)
                        <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Entrada: {{ $nome }}</label><input type="number" step="0.01" min="0" name="entradas[{{ $chave }}]" class="w-28 {{ $campo }}" /></div>
                    @endforeach
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Saídas pagas no dia</label><span class="py-1.5 text-sm dark:text-white">{{ $reais($saidas) }}</span></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Saldo contado</label><input type="number" step="0.01" name="saldo_contado" required class="w-32 {{ $campo }}" /></div>
                    <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Justificativa da diferença</label><input type="text" name="justificativa" maxlength="500" class="w-64 {{ $campo }}" /></div>
                    <button type="submit" class="primary-button">Fechar o caixa</button>
                </form>
                <p class="mt-2 text-xs text-gray-500">Qualquer diferença avisa o gerente e o dono na hora; sem justificativa ainda vira trava do financeiro.</p>
            @endif
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-2 text-base font-semibold dark:text-white">Conciliação do dia</p>
            <p class="mb-2 text-sm text-gray-500">Pagamento sem conta no plano de contas não entra na conciliação. {{ $semConta ? $semConta.' pagamento(s) de hoje estão sem conta.' : 'Todos os pagamentos do dia têm conta.' }}</p>
            <form method="POST" action="{{ route('admin.financeiro.conciliar') }}">@csrf <input type="hidden" name="dia" value="{{ $dia->toDateString() }}" /><button type="submit" class="secondary-button">Conciliar o dia</button></form>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-2 text-base font-semibold dark:text-white">Régua de cobrança (dias 1, 7 e 15 após o vencimento)</p>
            @forelse ($regua as $item)
                <form method="POST" action="{{ route('admin.financeiro.cobranca') }}" class="flex flex-wrap items-center gap-2 border-b border-gray-100 py-1.5 text-sm dark:border-gray-800 dark:text-white">
                    @csrf
                    <input type="hidden" name="boleto_id" value="{{ $item['boleto_id'] }}" /><input type="hidden" name="dia" value="{{ $item['dia_regua'] }}" />
                    <span>Cobrança #{{ $item['boleto_id'] }} · <a class="text-blue-600 hover:underline" href="{{ route('admin.service_orders.view', $item['os']) }}">OS #{{ $item['os'] }}</a> · vencida há {{ $item['atraso_dias'] }} dia(s) · contato do dia {{ $item['dia_regua'] }}</span>
                    <button type="submit" class="secondary-button">Registrar contato feito</button>
                </form>
            @empty
                <p class="text-sm text-gray-500">Nenhum contato de cobrança pendente.</p>
            @endforelse
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-2 text-base font-semibold dark:text-white">Últimos fechamentos</p>
            @foreach ($historico as $h)
                <p class="border-b border-gray-100 py-1 text-sm dark:border-gray-800 dark:text-white">{{ \Carbon\Carbon::parse($h->data)->format('d/m/Y') }} · contado {{ $reais($h->saldo_contado) }} · diferença <span class="{{ abs($h->diferenca) >= 0.005 ? 'font-semibold text-red-600' : '' }}">{{ $reais($h->diferenca) }}</span></p>
            @endforeach
        </div>
    </div>
</x-admin::layouts>
