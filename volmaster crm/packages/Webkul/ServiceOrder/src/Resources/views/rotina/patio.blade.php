@php
    $cor = ['verde' => 'bg-green-100 text-green-800 border-green-300', 'amarelo' => 'bg-yellow-100 text-yellow-800 border-yellow-300', 'vermelho' => 'bg-red-100 text-red-800 border-red-300'];

    // O nome da etapa na tela vem do idioma ("Cadastro" aparece como "Cadastro do cliente").
    $rotuloEtapa = function (string $etapa): string {
        $chave = 'service_order::app.stages.'.$etapa;
        $texto = trans($chave);

        return $texto === $chave ? $etapa : $texto;
    };

    // Texto da busca: placa sem hífen + cliente + veículo + número da OS, em minúsculas e sem acento.
    $textoBusca = fn (array $caminhao): string => \Illuminate\Support\Str::of(implode(' ', [
        preg_replace('/[^A-Za-z0-9]/', '', (string) $caminhao['placa']),
        $caminhao['placa'],
        $caminhao['cliente'],
        $caminhao['veiculo'],
        'os'.$caminhao['os'],
    ]))->ascii()->lower()->toString();
@endphp

<x-admin::layouts>
    <x-slot:title>
        Quadro de pátio
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-xl font-bold dark:text-white">Quadro de pátio</div>
            <p class="text-sm text-gray-500">Todos os caminhões na oficina, por etapa, com o tempo na etapa. Verde: no prazo. Amarelo: 80% do prazo. Vermelho: atrasado.</p>

            <div style="margin-top:12px;max-width:420px">
                <input
                    type="search"
                    id="patio-busca"
                    autocomplete="off"
                    placeholder="Buscar pela placa ou pelo nome do cliente..."
                    aria-label="Buscar caminhão pela placa ou pelo nome do cliente"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                >
                <p id="patio-busca-resumo" class="text-xs text-gray-500" style="margin-top:4px" hidden></p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3 xl:grid-cols-5">
            @foreach ($etapas as $etapa)
                <div class="box-shadow rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900" data-patio-etapa>
                    <p class="mb-2 flex items-center justify-between text-sm font-semibold dark:text-white">{{ $rotuloEtapa($etapa['etapa']) }}<span class="rounded-full bg-gray-100 px-2 text-xs dark:bg-gray-800" data-patio-contador data-total="{{ count($etapa['caminhoes']) }}">{{ count($etapa['caminhoes']) }}</span></p>

                    @forelse ($etapa['caminhoes'] as $caminhao)
                        <a
                            href="{{ route('admin.service_orders.view', $caminhao['os']) }}"
                            class="mb-2 block rounded-md border p-2 text-xs {{ $cor[$caminhao['cor']] }}"
                            data-patio-card
                            data-busca="{{ $textoBusca($caminhao) }}"
                            title="Abrir a OS #{{ $caminhao['os'] }}"
                        >
                            <span style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                                @include('service_order::components.placa', ['placa' => $caminhao['placa'], 'escala' => 0.95])
                                <span class="opacity-75">OS #{{ $caminhao['os'] }}</span>
                            </span>
                            <b class="text-sm" style="display:block;margin-top:6px;line-height:1.25">{{ $caminhao['cliente'] ?: 'Cliente não informado' }}</b>
                            <span style="display:block">{{ $caminhao['veiculo'] }}</span>
                            <span class="font-semibold" style="display:block;margin-top:2px">{{ $caminhao['tempo_na_etapa'] }} na etapa</span>
                        </a>
                    @empty
                        <p class="text-xs text-gray-400">Nenhum caminhão.</p>
                    @endforelse
                </div>
            @endforeach
        </div>
    </div>

    @push('scripts')
        <script>
            (function () {
                // O painel do sistema (Vue) refaz a página depois de carregar: o campo é tratado no document
                // e os elementos são buscados na hora (o mesmo padrão do resto do sistema).
                // Minúsculas, sem acento; "abc-1d23" e "ABC1D23" são a mesma busca.
                const limpar = (texto) => texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
                const soLetrasENumeros = (texto) => texto.replace(/[^a-z0-9]/g, '');

                const filtrar = (campo) => {
                    const resumo = document.getElementById('patio-busca-resumo');
                    const termo = limpar(campo.value);
                    const termoCompacto = soLetrasENumeros(termo);
                    let visiveis = 0;
                    let total = 0;

                    document.querySelectorAll('[data-patio-card]').forEach((card) => {
                        const texto = card.dataset.busca || '';
                        const bate = termo === '' || texto.includes(termo) || (termoCompacto !== '' && soLetrasENumeros(texto).includes(termoCompacto));

                        card.hidden = ! bate;
                        card.style.display = bate ? '' : 'none';
                        total++;
                        visiveis += bate ? 1 : 0;
                    });

                    document.querySelectorAll('[data-patio-etapa]').forEach((coluna) => {
                        const contador = coluna.querySelector('[data-patio-contador]');

                        if (contador) {
                            contador.textContent = termo === '' ? contador.dataset.total : coluna.querySelectorAll('[data-patio-card]:not([hidden])').length;
                        }
                    });

                    resumo.hidden = termo === '';
                    resumo.textContent = visiveis === 0 ? 'Nenhum caminhão encontrado.' : visiveis + ' de ' + total + ' caminhão(ões).';
                };

                document.addEventListener('input', (evento) => {
                    if (evento.target && evento.target.id === 'patio-busca') {
                        filtrar(evento.target);
                    }
                });
            })();
        </script>
    @endpush
</x-admin::layouts>
