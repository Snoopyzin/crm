{{-- Impact e Recomendações saíram do menu lateral (ninguém da oficina os usa no dia a dia) e ficam aqui, em Ajuda e recursos. --}}
@php
    $extras = collect([
        ['chave' => 'impact', 'rota' => 'admin.impact.open', 'titulo' => 'Impact', 'texto' => 'Abrir o Portal do cliente.'],
        ['chave' => 'recommendations', 'rota' => 'admin.recommendations.index', 'titulo' => 'Recomendações', 'texto' => 'Recomendações para os clientes.'],
    ])->filter(fn ($item) => \Illuminate\Support\Facades\Route::has($item['rota']) && bouncer()->hasPermission($item['chave']));
@endphp

@if ($extras->isNotEmpty())
    <div class="flex flex-col gap-4">
        <p class="text-sm font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-400">Mais recursos</p>

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($extras as $item)
                <a href="{{ route($item['rota']) }}" class="flex flex-col gap-1 rounded-2xl border border-gray-200 bg-white p-5 transition hover:shadow-md dark:border-gray-800 dark:bg-gray-900">
                    <span class="text-base font-semibold text-gray-800 dark:text-white">{{ $item['titulo'] }}</span>
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $item['texto'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
