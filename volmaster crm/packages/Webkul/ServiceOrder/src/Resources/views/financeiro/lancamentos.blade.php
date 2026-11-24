@php
    $campo = 'rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
    $rotulo = 'text-xs text-gray-500 dark:text-gray-400';
    $reais = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
@endphp

<x-admin::layouts>
    <x-slot:title>
        Contas a pagar
    </x-slot>

    <div class="flex flex-col gap-4">
        @include('service_order::financeiro._abas')

        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Contas a pagar</div>
            <p class="text-sm text-gray-500">O único trabalho novo do financeiro: escolher a conta do plano de contas de cada pagamento. A DRE se monta sozinha a partir disso.
                @if ($semConta) <b class="text-red-600">{{ $semConta }} lançamento(s) sem conta</b> — não entram na conciliação nem na DRE. @endif</p>
        </div>

        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <form method="POST" action="{{ route('admin.financeiro.lancamentos.store') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Descrição</label><input type="text" name="descricao" required class="w-64 {{ $campo }}" /></div>
                <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Valor (R$)</label><input type="number" step="0.01" min="0.01" name="valor" required class="w-32 {{ $campo }}" /></div>
                <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Vencimento</label><input type="date" name="vencimento" required class="{{ $campo }}" /></div>
                <div class="flex flex-col gap-1">
                    <label class="{{ $rotulo }}">Conta</label>
                    <select name="conta_id" class="w-64 {{ $campo }}"><option value="">- escolher depois -</option>
                        @foreach ($contas as $conta)<option value="{{ $conta->id }}">{{ $conta->codigo }} {{ $conta->nome }}</option>@endforeach
                    </select>
                </div>
                <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">Fornecedor</label><input type="text" name="parceiro" class="w-44 {{ $campo }}" /></div>
                <div class="flex flex-col gap-1"><label class="{{ $rotulo }}">OS (terceiros, opcional)</label><input type="number" name="service_order_id" class="w-24 {{ $campo }}" /></div>
                <label class="flex items-center gap-1 pb-2 text-xs dark:text-white"><input type="hidden" name="programada" value="0" /><input type="checkbox" name="programada" value="1" checked /> Conta programada</label>
                <label class="flex items-center gap-1 pb-2 text-xs dark:text-white"><input type="hidden" name="pagamento_pecas" value="0" /><input type="checkbox" name="pagamento_pecas" value="1" /> Pagamento de peças</label>
                <button type="submit" class="primary-button">Lançar</button>
            </form>
        </div>

        <div class="flex gap-2 text-sm">
            @foreach (['abertos' => 'Em aberto', 'pagos' => 'Pagos', 'sem_conta' => 'Sem conta', 'todos' => 'Todos'] as $chave => $nome)
                <a href="{{ route('admin.financeiro.lancamentos', ['filtro' => $chave]) }}" class="rounded-full px-3 py-1 {{ $filtro === $chave ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' }}">{{ $nome }}</a>
            @endforeach
        </div>

        <div class="box-shadow overflow-x-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-800">
                        <th class="pb-2">Vencimento</th><th class="pb-2">Descrição</th><th class="pb-2 text-right">Valor</th><th class="pb-2">Conta</th><th class="pb-2">Situação</th><th class="pb-2">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lancamentos as $l)
                        <tr class="border-b border-gray-100 align-top dark:border-gray-800">
                            <td class="py-2 whitespace-nowrap {{ ! $l->pago_em && $l->vencimento->lt(today()) ? 'font-semibold text-red-600' : 'dark:text-white' }}">{{ $l->vencimento->format('d/m/Y') }}</td>
                            <td class="py-2 dark:text-white">{{ $l->descricao }}<span class="block text-xs text-gray-500">{{ $l->parceiro }}{{ $l->service_order_id ? ' · OS #'.$l->service_order_id : '' }}{{ $l->programada ? '' : ' · avulsa' }}</span></td>
                            <td class="py-2 text-right dark:text-white">{{ $reais($l->valor) }}</td>
                            <td class="py-2">
                                @if ($l->conta)
                                    <span class="text-xs dark:text-white">{{ $l->conta->codigo }} {{ $l->conta->nome }}</span>
                                @else
                                    <form method="POST" action="{{ route('admin.financeiro.classificar', $l->id) }}" class="flex gap-1">
                                        @csrf
                                        <select name="conta_id" required class="w-44 {{ $campo }}"><option value="">escolher conta</option>@foreach ($contas as $conta)<option value="{{ $conta->id }}">{{ $conta->codigo }} {{ $conta->nome }}</option>@endforeach</select>
                                        <button type="submit" class="secondary-button">Salvar</button>
                                    </form>
                                @endif
                            </td>
                            <td class="py-2 text-xs">{{ $l->pago_em ? 'Pago em '.$l->pago_em->format('d/m/Y').($l->conciliado_em ? ' · conciliado' : '').($l->juros_multa ? ' · com juros/multa' : '') : 'Em aberto' }}</td>
                            <td class="py-2">
                                @unless ($l->pago_em)
                                    <form method="POST" action="{{ route('admin.financeiro.pagar', $l->id) }}" class="flex flex-wrap items-center gap-1">
                                        @csrf
                                        <input type="date" name="pago_em" value="{{ today()->toDateString() }}" class="{{ $campo }}" />
                                        <select name="forma" class="{{ $campo }}">@foreach ($formas as $chave => $nome)<option value="{{ $chave }}">{{ $nome }}</option>@endforeach</select>
                                        <label class="flex items-center gap-1 text-xs dark:text-white"><input type="hidden" name="juros_multa" value="0" /><input type="checkbox" name="juros_multa" value="1" /> juros/multa</label>
                                        @if ($l->pagamento_pecas)<label class="flex items-center gap-1 text-xs dark:text-white"><input type="hidden" name="nf_entrada_conferida" value="0" /><input type="checkbox" name="nf_entrada_conferida" value="1" /> NF conferida</label>@endif
                                        <button type="submit" class="primary-button">Pagar</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-gray-500">Nenhum lançamento.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
