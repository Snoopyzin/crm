@php
    $verValores = \Webkul\ServiceOrder\Services\ValoresFinanceiros::can();
@endphp

<x-admin::layouts>
    <x-slot:title>
        Meu bônus
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Meu bônus</div>
            <p class="text-sm text-gray-500">Sua nota do mês, marcador por marcador. Depois da conversa com o gerente, registre a sua ciência.</p>
        </div>

        @if (! $colaborador)
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900">Seu usuário ainda não está ligado a um colaborador. Peça ao gerente para cadastrar em Colaboradores.</div>
        @endif

        @forelse ($apuracoes as $a)
            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-base font-semibold dark:text-white">{{ $a->periodo }} &middot; {{ $cargos[$a->cargo] ?? $a->cargo }}</p>
                <p class="text-sm dark:text-gray-200">
                    Nota <b>{{ $a->nota !== null ? number_format($a->nota, 1, ',', '.') : '—' }}</b> &middot; faixa {{ $a->faixa_percentual }}%
                    @if ($a->zerado_por_trava) &middot; <b class="text-red-600">bônus zerado por trava</b> @endif
                    @if ($verValores && $a->status === 'aprovado' && $a->valor !== null) &middot; valor R$ {{ number_format($a->valor, 2, ',', '.') }} @endif
                </p>
                @if ($a->feedback)<p class="mt-2 rounded-md bg-gray-50 p-2 text-sm dark:bg-gray-800 dark:text-gray-200"><b>Feedback do gerente:</b> {{ $a->feedback }}</p>@endif

                <div class="mt-2">
                    @foreach ($a->detalhe_json ?? [] as $linha)
                        <p class="border-b border-gray-100 py-1 text-xs text-gray-700 dark:border-gray-800 dark:text-gray-300">
                            {{ $linha['marcador'] }}: {{ $linha['pontos'] === null ? 'fora do cálculo' : number_format($linha['pontos_ajustados'], 1, ',', '.').' pontos' }}
                        </p>
                    @endforeach
                </div>

                @if ($a->ciencia_colaborador_em)
                    <p class="mt-2 text-xs text-green-700">Ciência registrada em {{ $a->ciencia_colaborador_em->format('d/m/Y H:i') }}.</p>
                @else
                    <form method="POST" action="{{ route('admin.mentoria.bonus.ciencia', $a->id) }}" class="mt-2">@csrf <button type="submit" class="primary-button">Estou ciente da minha nota</button></form>
                @endif
            </div>
        @empty
            @if ($colaborador)<div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900">Nenhuma nota revisada ainda.</div>@endif
        @endforelse
    </div>
</x-admin::layouts>
