@php
    $OS = \Webkul\ServiceOrder\Models\ServiceOrder::class;

    $mapa = [
        $OS::STAGE_CADASTRO => ['CON-01', 'CON-13', 'CON-03'],
        $OS::STAGE_ORDEM_SERVICO => ['CON-01', 'CON-13', 'CON-03'],
        $OS::STAGE_DIAGNOSTICO => ['TEC-01', 'CHE-01', 'CHE-02'],
        $OS::STAGE_ORCAMENTO => ['PEC-01', 'CHE-04', 'CON-04'],
        $OS::STAGE_NEGOCIACAO => ['CON-09', 'CON-10'],
        $OS::STAGE_EM_EXECUCAO => ['TEC-07', 'CHE-06', 'PEC-05'],
        $OS::STAGE_VEICULO_TESTE => ['CHE-08'],
        $OS::STAGE_FATURAMENTO => ['CON-07', 'FIN-02'],
        $OS::STAGE_POS_VENDA => ['CON-08', 'CON-15', 'CHE-10'],
    ];

    $periodo = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) request('periodo')) ? request('periodo') : now()->format('Y-m');
    $linhas = app(\Webkul\ServiceOrder\Services\Indicators\PainelDonoService::class)->linhas($mapa[$stage] ?? [], $periodo, false);

    // Faturamento: quantos caminhões estão prontos e ainda não foram enviados ao financeiro.
    $prontos = $stage === $OS::STAGE_FATURAMENTO
        ? $OS::query()->where('quotes.workshop_status', $OS::STAGE_FATURAMENTO)->whereNotNull('quotes.checklist_saida_em')->whereNull('quotes.enviada_financeiro_em')->count()
        : null;
@endphp

@php
    // Negociação: descontos dentro da alçada = OS com desconto que não precisaram de aprovação.
    $descontos = null;

    if ($stage === $OS::STAGE_NEGOCIACAO) {
        [$inicioMes, $fimMes] = app(\Webkul\ServiceOrder\Services\Indicators\IndicatorService::class)->period($periodo);
        $comDesconto = \Illuminate\Support\Facades\DB::table('quote_items')->join('quotes', 'quotes.id', '=', 'quote_items.quote_id')
            ->where('quote_items.discount_amount', '>', 0)->whereBetween('quotes.updated_at', [$inicioMes, $fimMes])->distinct()->pluck('quotes.id');
        $precisaram = \Webkul\ServiceOrder\Models\AlcadaPedido::where('tema', 'desconto')->whereIn('service_order_id', $comDesconto)->distinct()->pluck('service_order_id');
        $descontos = $comDesconto->isEmpty() ? null : round(($comDesconto->count() - $precisaram->count()) / $comDesconto->count() * 100, 1);
    }
@endphp

@if ($linhas)
    <div class="flex flex-wrap items-stretch gap-3">
        @foreach ($linhas as $linha)
            <a href="{{ route('admin.mentoria.indicador', [$linha['codigo'], 'periodo' => $periodo]) }}"
               class="min-w-[180px] flex-1 rounded-lg border border-gray-200 p-3 dark:border-gray-800"
               style="background: {{ $linha['cores']['fundo'] }}; color: {{ $linha['cores']['texto'] }};">
                <p class="text-xs uppercase opacity-80">{{ $linha['nome'] }}</p>
                <p class="mt-1 text-2xl font-bold">{{ $linha['realizado_fmt'] }}</p>
                <p class="text-xs opacity-80">Meta {{ $linha['meta_fmt'] }} &middot; {{ $linha['situacao_rotulo'] }}</p>
            </a>
        @endforeach

        @if ($stage === $OS::STAGE_NEGOCIACAO)
            <div class="min-w-[180px] flex-1 rounded-lg border border-gray-200 p-3 dark:border-gray-800" style="background: {{ $descontos === null ? '#eceff1' : ($descontos >= 90 ? '#dcfce7' : ($descontos >= 72 ? '#fef3c7' : '#fee2e2')) }}; color: #1f2a30;">
                <p class="text-xs uppercase opacity-80">Descontos dentro da alçada</p>
                <p class="mt-1 text-2xl font-bold">{{ $descontos === null ? '—' : number_format($descontos, 1, ',', '.').'%' }}</p>
                <p class="text-xs opacity-80">OS com desconto que não precisaram de aprovação</p>
            </div>
        @endif

        @if ($prontos !== null)
            <div class="min-w-[180px] flex-1 rounded-lg border border-gray-200 p-3 dark:border-gray-800" style="background: {{ $prontos ? '#fef3c7' : '#dcfce7' }}; color: {{ $prontos ? '#92400e' : '#166534' }};">
                <p class="text-xs uppercase opacity-80">Prontos e não faturados</p>
                <p class="mt-1 text-2xl font-bold">{{ $prontos }}</p>
                <p class="text-xs opacity-80">Checklist de saída feito, OS ainda não enviada ao financeiro</p>
            </div>
        @endif
    </div>
@endif
