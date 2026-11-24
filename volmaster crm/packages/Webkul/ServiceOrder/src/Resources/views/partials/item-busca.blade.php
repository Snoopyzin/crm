{{--
    Busca de peças e serviços na hora de lançar o item (reunião de 19/11/2026): com muitos itens, a
    pessoa digita parte do nome ou do código e as listas de peças e de serviços mostram só o que bate.
    Também deixa cadastrar uma peça ou um serviço novo sem sair da OS. O campo não é enviado
    (não tem "name"); só filtra as listas que já estão na tela.
--}}
@php
    $podeCadastrarPeca = bouncer()->hasPermission('parts.create');
    $podeCadastrarServico = bouncer()->hasPermission('services.create');
    $rotuloBusca = 'text-xs text-gray-500 dark:text-gray-400';
@endphp

<div style="flex-basis:100%;display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin-bottom:6px" id="item-busca-bloco">
    <div class="flex flex-col gap-1" style="min-width:280px;flex:1;max-width:440px">
        <label for="item-busca" class="{{ $rotuloBusca }}">@lang('service_order::app.itens.buscar')</label>
        <input
            type="search"
            id="item-busca"
            autocomplete="off"
            placeholder="@lang('service_order::app.itens.buscar-placeholder')"
            class="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
        >
        <p id="item-busca-resumo" class="{{ $rotuloBusca }}" hidden></p>
    </div>

    @if ($podeCadastrarPeca)
        <a href="{{ route('admin.parts.create') }}" target="_blank" rel="noopener" style="font-size:13px;font-weight:600;color:#2563eb;text-decoration:underline">+ @lang('service_order::app.itens.cadastrar-peca')</a>
    @endif

    @if ($podeCadastrarServico)
        <a href="{{ route('admin.services.create') }}" target="_blank" rel="noopener" style="font-size:13px;font-weight:600;color:#2563eb;text-decoration:underline">+ @lang('service_order::app.itens.cadastrar-servico')</a>
    @endif
</div>

@push('scripts')
    <script>
        (function () {
            // O painel do sistema (Vue) refaz a página depois de carregar: o campo é tratado no document
            // e as listas são buscadas na hora (o mesmo padrão do resto da tela da OS).

            // Minúsculas e sem acento: "embreagem" acha "Embreagém".
            const limpar = (texto) => (texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

            // As opções originais da lista, guardadas na primeira busca (antes de filtrar).
            const guardar = (select) => select.__originais || (select.__originais = Array.from(select.options).map((opcao, indice) => ({
                valor: opcao.value,
                texto: opcao.textContent,
                busca: limpar(opcao.textContent),
                dados: Object.assign({}, opcao.dataset),
                primeira: indice === 0 && opcao.value === '',
            })));

            const refazer = (select, termos) => {
                if (! select) {
                    return 0;
                }

                const atual = select.value;
                const lista = guardar(select);
                let achados = 0;

                select.innerHTML = '';

                lista.forEach((item) => {
                    if (! item.primeira && ! termos.every((termo) => item.busca.includes(termo))) {
                        return;
                    }

                    const opcao = document.createElement('option');

                    opcao.value = item.valor;
                    opcao.textContent = item.texto;
                    Object.keys(item.dados).forEach((chave) => { opcao.dataset[chave] = item.dados[chave]; });
                    select.appendChild(opcao);

                    achados += item.primeira ? 0 : 1;
                });

                const aindaExiste = Array.from(select.options).some((opcao) => opcao.value === atual);

                if (aindaExiste) {
                    select.value = atual;
                } else if (atual !== '') {
                    select.value = '';
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }

                return achados;
            };

            document.addEventListener('input', (evento) => {
                if (! evento.target || evento.target.id !== 'item-busca') {
                    return;
                }

                const termos = limpar(evento.target.value).split(/\s+/).filter(Boolean);
                const nPecas = refazer(document.getElementById('item-part'), termos);
                const nServicos = refazer(document.getElementById('item-service'), termos);
                const resumo = document.getElementById('item-busca-resumo');

                if (resumo) {
                    resumo.hidden = termos.length === 0;
                    resumo.textContent = nPecas + ' peça(s) e ' + nServicos + ' serviço(s) encontrados.';
                }
            });
        })();
    </script>
@endpush
