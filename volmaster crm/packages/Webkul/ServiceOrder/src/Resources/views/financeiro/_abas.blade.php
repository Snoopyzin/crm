@php
    $abas = [
        ['admin.financeiro.lancamentos', 'Contas a pagar', 'financeiro_rotina'],
        ['admin.financeiro.caixa', 'Caixa, conciliação e cobrança', 'financeiro_rotina'],
        ['admin.financeiro.relatorios', 'Relatórios diários', 'financeiro_rotina'],
        ['admin.financeiro.contas', 'Plano de contas', 'financeiro_rotina'],
        ['admin.financeiro.dre', 'DRE e preço da hora', 'financeiro_dre'],
        ['admin.financeiro.analises', 'Margem, simulador e caixa projetado', 'financeiro_dre'],
        ['admin.financeiro.orcamento', 'Orçamento e Marco Zero', 'financeiro_dre'],
    ];
@endphp

<div class="box-shadow flex flex-wrap gap-2 rounded-lg border border-gray-200 bg-white p-3 text-sm dark:border-gray-800 dark:bg-gray-900">
    @foreach ($abas as [$rota, $nome, $permissao])
        @if (bouncer()->hasPermission($permissao))
            <a href="{{ route($rota) }}" class="rounded-full px-3 py-1 {{ request()->routeIs($rota) ? 'bg-blue-600 font-semibold text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' }}">{{ $nome }}</a>
        @endif
    @endforeach
</div>
