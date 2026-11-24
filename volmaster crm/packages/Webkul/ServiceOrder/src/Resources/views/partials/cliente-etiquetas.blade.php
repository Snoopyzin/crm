{{--
    Etiquetas discretas do cliente: a cor de crédito (verde = bom, amarelo = duvidoso, vermelho = ruim;
    só para quem pode ver crédito) e o aviso de cadastro vencido (a última análise de crédito passou da
    validade da Ficha). Estilo direto no HTML de propósito.

    Variável: $credito (array de ClienteBusca ou null).
--}}
@php
    $coresEtiqueta = [
        'verde' => ['#dcfce7', '#166534', 'Crédito: bom'],
        'amarelo' => ['#fef3c7', '#92400e', 'Crédito: duvidoso'],
        'vermelho' => ['#fee2e2', '#991b1b', 'Crédito: ruim'],
    ];
@endphp

@if (! empty($credito))
    @if (! empty($credito['cor']) && isset($coresEtiqueta[$credito['cor']]))
        <span class="etiqueta-credito" data-cor="{{ $credito['cor'] }}" style="display:inline-block;margin-left:4px;padding:1px 8px;border-radius:9999px;background:{{ $coresEtiqueta[$credito['cor']][0] }};color:{{ $coresEtiqueta[$credito['cor']][1] }};font-size:11px;font-weight:700">{{ $coresEtiqueta[$credito['cor']][2] }}</span>
    @endif

    @if (! empty($credito['vencida']))
        <span class="etiqueta-cadastro-vencido" style="display:inline-block;margin-left:4px;padding:1px 8px;border-radius:9999px;background:#ffedd5;color:#9a3412;font-size:11px;font-weight:700" title="A última análise de crédito passou da validade ({{ $credito['validade_dias'] }} dias). Peça nova consulta ao Financeiro.">
            {{ ! empty($credito['nunca']) ? 'Sem análise de crédito' : 'Cadastro vencido há '.$credito['dias'].' dias' }}
        </span>
    @endif
@endif
