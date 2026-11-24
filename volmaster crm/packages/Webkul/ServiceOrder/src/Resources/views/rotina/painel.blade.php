@php
    $cor = ['verde' => 'bg-green-100 text-green-800', 'amarelo' => 'bg-yellow-100 text-yellow-800', 'vermelho' => 'bg-red-100 text-red-800'];
    $diarias = collect($secoes)->firstWhere('titulo', 'Diária de pátio a cobrar');
@endphp

<x-admin::layouts>
    <x-slot:title>
        Minha rotina
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Minha rotina</div>
            <p class="text-sm text-gray-500">O que o seu cargo precisa fazer agora, com o cronômetro de cada prazo.</p>
        </div>

        @forelse ($secoes as $secao)
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="mb-2 text-base font-semibold dark:text-white">{{ $secao['titulo'] }} @if (count($secao['itens']))<span class="ml-1 rounded-full bg-gray-100 px-2 text-xs dark:bg-gray-800">{{ count($secao['itens']) }}</span>@endif</p>

                @foreach ($secao['checklists'] as $lista)
                    <form method="POST" action="{{ route('admin.rotina.checklist') }}" class="mb-2 flex items-center justify-between gap-3 rounded-md border border-gray-200 p-3 dark:border-gray-800">
                        @csrf
                        <input type="hidden" name="tipo" value="{{ $lista['tipo'] }}" />
                        <span class="text-sm dark:text-white">{{ $lista['rotulo'] }} <span class="text-xs text-gray-500">({{ $lista['feitos'] }} de {{ $lista['previstos'] }} hoje)</span></span>
                        <button type="submit" class="primary-button">Feito</button>
                    </form>
                @endforeach

                @forelse ($secao['itens'] as $item)
                    <div class="mb-1 flex flex-wrap items-center justify-between gap-2 rounded-md px-3 py-1.5 text-sm {{ $cor[$item['cor']] }}">
                        <span>
                            @if ($item['os'])<a class="font-semibold underline" href="{{ route('admin.service_orders.view', $item['os']) }}">OS #{{ $item['os'] }}</a>@endif
                            @if ($item['placa'])<b>{{ $item['placa'] }}</b>@endif
                            {{ $item['texto'] }}
                        </span>
                        @if ($item['decorrido'])<span class="text-xs font-semibold">{{ $item['decorrido'] }} de {{ $item['limite'] }}</span>@endif
                    </div>
                @empty
                    @if (! count($secao['checklists']))<p class="text-sm text-gray-500">{{ $secao['vazio'] }}</p>@endif
                @endforelse

                @if ($secao['titulo'] === 'Diária de pátio a cobrar' && bouncer()->hasPermission('ver_valores_financeiros'))
                    @foreach (app(\Webkul\ServiceOrder\Services\Rotina\RotinaService::class)->diariasDePatio() as $d)
                        <form method="POST" action="{{ route('admin.rotina.diaria', $d['os']) }}" class="mt-1 text-sm dark:text-white">
                            @csrf OS #{{ $d['os'] }}: {{ $d['dias'] }} dia(s) = R$ {{ number_format($d['valor'], 2, ',', '.') }} <button type="submit" class="ml-2 text-blue-600 hover:underline">Lançar na OS</button>
                        </form>
                    @endforeach
                @endif
            </div>
        @empty
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900">
                Seu usuário ainda não tem um papel da oficina. O gerente define em Configurações &gt; Indicadores e alertas &gt; Papéis.
            </div>
        @endforelse
    </div>
</x-admin::layouts>
