<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Painel do Dono {{ $painel['periodo'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2a30; }
        h1 { font-size: 16px; margin: 0 0 2px; } h2 { font-size: 12px; margin: 14px 0 4px; border-bottom: 1px solid #90a4ae; padding-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; } th, td { text-align: left; padding: 3px 4px; border-bottom: 1px solid #eceff1; }
        th { font-size: 9px; color: #546e7a; text-transform: uppercase; } .r { text-align: right; }
        .verde { color: #166534; } .amarelo { color: #92400e; } .vermelho { color: #991b1b; font-weight: bold; } .sem_meta, .sem_dado { color: #78909c; }
        .cartoes td { width: 25%; border: 1px solid #cfd8dc; padding: 6px; } .cartoes b { font-size: 13px; display: block; }
    </style>
</head>
<body>
    <h1>Painel do Dono — {{ $painel['periodo_rotulo'] }}</h1>
    <p style="color:#607d8b">Gerado em {{ now()->format('d/m/Y H:i') }} para o encontro de mentoria.</p>

    <table class="cartoes"><tr>
        @foreach ($painel['cartoes'] as $i => $cartao)
            <td><span>{{ $cartao['rotulo'] }}</span><b class="{{ $cartao['situacao'] }}">{{ $cartao['valor'] }}</b></td>
            @if (($i + 1) % 4 === 0 && ! $loop->last) </tr><tr> @endif
        @endforeach
    </tr></table>

    @php
        $blocos = [
            'Indicadores da DRE e da precificação' => $painel['dre'],
            'Peças e estoque' => $painel['pecas']['linhas'],
            'Caixa e financeiro' => $painel['financeiro']['linhas'],
            'Gestão do gerente e RH' => $painel['gestao']['linhas'],
            'O próprio dono' => $painel['dono']['linhas'],
        ];
    @endphp

    <h2>Fluxo do caminhão (8 etapas) — {{ $painel['fluxo']['no_patio'] }} no pátio, {{ $painel['fluxo']['parados'] }} parados há mais de 3 dias</h2>
    @foreach ($painel['fluxo']['etapas'] as $etapa)
        <p style="margin:6px 0 2px"><b>{{ $etapa['etapa'] }}</b> — {{ $etapa['agora'] }} agora{{ $etapa['parados'] ? ', '.$etapa['parados'].' parados' : '' }}</p>
        @include('service_order::mentoria.painel-pdf-linhas', ['linhas' => $etapa['indicadores']])
    @endforeach

    @foreach ($blocos as $titulo => $linhas)
        <h2>{{ $titulo }}</h2>
        @include('service_order::mentoria.painel-pdf-linhas', ['linhas' => $linhas])
    @endforeach

    <h2>Equipe e bônus</h2>
    <table>
        <tr><th>Colaborador</th><th>Cargo</th><th class="r">Nota</th><th class="r">Faixa</th><th>Trava</th><th class="r">Valor</th><th>Situação</th></tr>
        @foreach ($painel['equipe']['apuracoes'] as $a)
            <tr><td>{{ $a['colaborador'] }}</td><td>{{ $a['cargo'] }}</td><td class="r">{{ $a['nota'] !== null ? number_format($a['nota'], 1, ',', '.') : '—' }}</td><td class="r">{{ $a['faixa'] }}%</td><td>{{ $a['trava'] ? 'sim' : 'não' }}</td><td class="r">{{ $a['valor'] !== null ? 'R$ '.number_format($a['valor'], 2, ',', '.') : '—' }}</td><td>{{ $a['status'] }}</td></tr>
        @endforeach
    </table>
</body>
</html>
