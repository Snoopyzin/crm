@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $reais = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $caixa = $relatorio['caixa'];
    $op = $relatorio['operacao'];
    $rotulos = [
        'aguardando_diagnostico_ou_orcamento' => 'Aguardando diagnóstico ou orçamento', 'aguardando_aprovacao' => 'Aguardando aprovação',
        'aguardando_peca' => 'Aguardando peça', 'em_execucao' => 'Em execução', 'prontas_nao_faturadas' => 'Prontas e não faturadas',
    ];
    $alertas = $enviado ? json_decode($enviado->alertas_json, true) : [];
@endphp

<x-admin::layouts>
    <x-slot:title>
        Relatórios diários
    </x-slot>

    <div class="flex flex-col gap-4">
        @include('service_order::financeiro._abas')

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div>
                <div class="text-xl font-bold dark:text-white">Relatório diário do financeiro ao dono — {{ $dia->format('d/m/Y') }}</div>
                <p class="text-sm text-gray-500">Gerado sozinho; o financeiro revisa, escreve até 3 alertas e envia até as {{ $horario }}.</p>
            </div>
            <form method="GET"><input type="date" name="dia" value="{{ $dia->toDateString() }}" onchange="this.form.submit()" class="{{ $campo }}" /></form>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-2 text-base font-semibold dark:text-white">1. Caixa e banco</p>
            <div class="grid grid-cols-2 gap-2 text-sm md:grid-cols-4 dark:text-gray-200">
                <p>Saldo inicial<br><b>{{ $reais($caixa['saldo_inicial']) }}</b></p>
                <p>Saídas do dia<br><b>{{ $reais($caixa['saidas']) }}</b></p>
                <p>Saldo final conciliado<br><b>{{ $reais($caixa['saldo_conciliado']) }}</b></p>
                <p>Diferença<br><b class="{{ ($caixa['diferenca'] ?? 0) != 0 ? 'text-red-600' : '' }}">{{ $caixa['diferenca'] === null ? 'caixa não fechado' : $reais($caixa['diferenca']) }}</b></p>
                <p>A receber em 7 dias<br><b>{{ $reais($caixa['a_receber_7_dias']) }}</b></p>
                <p>A pagar em 7 dias<br><b>{{ $reais($caixa['a_pagar_7_dias']) }}</b></p>
                <p>Vencido a receber<br><b>{{ $reais($caixa['total_vencido_receber']) }}</b></p>
                <p>Vencido a pagar<br><b>{{ $reais($caixa['total_vencido_pagar']) }}</b></p>
            </div>
            @if (! empty($caixa['entradas']))
                <p class="mt-2 text-xs text-gray-500">Entradas: {{ collect($caixa['entradas'])->map(fn ($v, $k) => ucfirst($k).' '.$reais($v))->implode(' · ') }}</p>
            @endif
            @if ($caixa['explicacao'])<p class="mt-1 text-xs text-gray-500">Explicação da diferença: {{ $caixa['explicacao'] }}</p>@endif
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-2 text-base font-semibold dark:text-white">2. Operação (o segundo olhar)</p>
            <table class="mb-3 text-sm dark:text-gray-200">
                @foreach ($rotulos as $chave => $nome)
                    <tr><td class="pr-6 py-0.5">{{ $nome }}</td><td class="pr-6 text-right"><b>{{ $op['situacoes'][$chave]['quantidade'] }}</b> OS</td><td class="text-right">{{ $reais($op['situacoes'][$chave]['valor']) }}</td></tr>
                @endforeach
            </table>
            <p class="text-sm dark:text-gray-200">
                OS paradas há mais de 3 dias: <b>{{ $op['os_paradas_3_dias'] }}</b> ·
                orçamentos sem resposta há 48 h: <b>{{ $op['orcamentos_sem_resposta_48h'] }}</b> ·
                OS fechadas no dia: <b>{{ $op['os_fechadas_no_dia'] }}</b> ·
                saídas sem NF (deve ser zero): <b class="{{ $op['saidas_sem_nf'] ? 'text-red-600' : '' }}">{{ $op['saidas_sem_nf'] }}</b> ·
                exceções de crédito do dia: <b>{{ $op['excecoes_de_credito'] }}</b>
            </p>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-2 text-base font-semibold dark:text-white">3. Alertas e decisões para o dono (até 3)</p>

            @if ($enviado)
                <p class="mb-2 text-xs {{ $enviado->no_horario ? 'text-green-700' : 'text-red-600' }}">Enviado em {{ \Carbon\Carbon::parse($enviado->enviado_em)->format('d/m/Y H:i') }} — {{ $enviado->no_horario ? 'no horário' : 'fora do horário combinado' }}.</p>
                @forelse ($alertas as $alerta)<p class="border-b border-gray-100 py-1 text-sm dark:border-gray-800 dark:text-white">• {{ $alerta }}</p>@empty<p class="text-sm text-gray-500">Sem alertas neste dia.</p>@endforelse
            @else
                <form method="POST" action="{{ route('admin.financeiro.relatorios.enviar') }}" class="flex flex-col gap-2">
                    @csrf
                    <input type="hidden" name="data" value="{{ $dia->toDateString() }}" />
                    @foreach ([0, 1, 2] as $i)<input type="text" name="alertas[]" maxlength="300" placeholder="Alerta ou decisão {{ $i + 1 }} (opcional)" class="{{ $campo }}" />@endforeach
                    <div><button type="submit" class="primary-button">Enviar relatório ao dono</button></div>
                </form>
            @endif
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-2 text-base font-semibold dark:text-white">Relatório diário de 5 linhas do gerente</p>
            <form method="POST" action="{{ route('admin.financeiro.relatorios.gerente') }}" class="flex flex-col gap-2">
                @csrf
                <input type="hidden" name="data" value="{{ $dia->toDateString() }}" />
                @foreach (['destaques' => 'Destaques', 'problemas' => 'Problemas', 'decisoes_tomadas' => 'Decisões tomadas', 'decisoes_pendentes' => 'Decisões pendentes', 'equipe' => 'Equipe'] as $chave => $nome)
                    <div class="flex flex-col gap-1"><label class="text-xs text-gray-500">{{ $nome }}</label><input type="text" name="{{ $chave }}" maxlength="1000" required value="{{ $gerente->{$chave} ?? '' }}" class="{{ $campo }}" /></div>
                @endforeach
                <div><button type="submit" class="secondary-button">Salvar as 5 linhas</button></div>
            </form>
        </div>
    </div>
</x-admin::layouts>
