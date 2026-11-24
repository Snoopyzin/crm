{{--
    Campo de pesquisa de cliente na abertura da OS (reunião de 19/11/2026): por nome, código, CPF/CNPJ,
    telefone ou placa. Mostra a etiqueta de crédito (só para quem pode ver crédito) e abre um pop-up
    quando o cadastro está vencido (última análise de crédito além da validade da Ficha).
    O cliente escolhido vai no campo "person_id" (escondido), como o seletor antigo.

    Variáveis: $clienteInicial (array de ClienteBusca ou null), vindo do controller; $avisarAoAbrir
    (opcional, padrão true): na edição de uma OS existente vai false, para o pop-up não sair a cada abertura.
    Usado na abertura (create) e na edição (edit) da OS.
    Estilo direto no HTML de propósito (o CSS compilado do servidor só tem as classes que já existiam).

    IMPORTANTE: o painel do sistema (Vue) refaz a página depois de carregar, então o script não liga
    "escutas" direto nos elementos: trata os eventos no document e busca os elementos na hora de usar.
--}}
@php
    $podeCadastrarCliente = bouncer()->hasPermission('cadastro');
    $campoCliente = 'w-full rounded border border-gray-300 px-2.5 py-2 text-sm font-normal text-gray-800 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
@endphp

<div id="cliente-busca-bloco" style="position:relative">
    <input type="hidden" id="person_id" name="person_id" value="{{ $clienteInicial['id'] ?? '' }}">

    <div id="cliente-escolhido" style="display:{{ $clienteInicial ? 'flex' : 'none' }};justify-content:space-between;align-items:flex-start;gap:8px;border:1px solid #94a3b8;border-radius:6px;padding:8px 10px">
        {{-- Já sai com o nome do servidor; o script completa com código, documento e crédito. --}}
        <div id="cliente-escolhido-dados" style="font-size:14px;line-height:1.35"><strong>{{ $clienteInicial['nome'] ?? '' }}</strong></div>
        <button type="button" id="cliente-trocar" style="flex:none;display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#2563eb;background:none;border:0;cursor:pointer;text-decoration:underline">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="21" y2="21"/></svg>
            @lang('service_order::app.clientes.trocar')
        </button>
    </div>

    <div id="cliente-campo" style="display:{{ $clienteInicial ? 'none' : 'block' }}">
        {{-- A lupa fica dentro do campo: clicar nela pesquisa o que foi digitado (ou leva o cursor para o campo). --}}
        <div style="position:relative">
            <input
                type="search"
                id="cliente-busca"
                autocomplete="off"
                placeholder="@lang('service_order::app.clientes.placeholder-os')"
                aria-label="@lang('service_order::app.clientes.campo')"
                class="{{ $campoCliente }}"
                style="padding-right:46px"
            >

            <button
                type="button"
                id="cliente-lupa"
                title="@lang('service_order::app.clientes.lupa')"
                aria-label="@lang('service_order::app.clientes.lupa')"
                style="position:absolute;top:0;right:0;bottom:0;width:42px;display:flex;align-items:center;justify-content:center;border:0;border-radius:0 4px 4px 0;background:#2563eb;color:#ffffff;cursor:pointer"
            >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="21" y2="21"/></svg>
            </button>
        </div>

        <div id="cliente-resultados" style="display:none;position:absolute;z-index:50;left:0;right:0;max-height:320px;overflow:auto;background:#ffffff;color:#111827;border:1px solid #cbd5e1;border-radius:6px;box-shadow:0 8px 20px rgba(0,0,0,.25);margin-top:2px"></div>
    </div>
</div>

{{-- Pop-up de cadastro vencido --}}
<div id="cliente-alerta" role="dialog" aria-modal="true" aria-labelledby="cliente-alerta-titulo" style="display:none;position:fixed;top:0;right:0;bottom:0;left:0;z-index:10050;background:rgba(0,0,0,.55);align-items:center;justify-content:center">
    <div style="background:#ffffff;color:#111827;border-radius:10px;max-width:480px;width:92%;padding:22px;box-shadow:0 20px 50px rgba(0,0,0,.4)">
        <p id="cliente-alerta-titulo" style="font-size:18px;font-weight:700;margin:0 0 8px;color:#9a3412">@lang('service_order::app.clientes.alerta-titulo')</p>
        <p id="cliente-alerta-texto" style="font-size:14px;line-height:1.5;margin:0"></p>
        <div style="text-align:right;margin-top:18px">
            <button type="button" id="cliente-alerta-ok" style="padding:8px 18px;border-radius:6px;border:0;background:#2563eb;color:#ffffff;font-size:14px;font-weight:600;cursor:pointer">@lang('service_order::app.clientes.alerta-ok')</button>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        (function () {
            const g = (id) => document.getElementById(id);
            const urlPesquisa = @json(route('admin.clientes.pesquisar'));
            const podeCadastrar = @json($podeCadastrarCliente);
            const inicial = @json($clienteInicial);
            // Na edição de uma OS que já existe o aviso de cadastro vencido só sai ao trocar de cliente.
            const avisarAoAbrir = @json($avisarAoAbrir ?? true);

            const CORES = {
                verde: ['#dcfce7', '#166534', 'Crédito: bom'],
                amarelo: ['#fef3c7', '#92400e', 'Crédito: duvidoso'],
                vermelho: ['#fee2e2', '#991b1b', 'Crédito: ruim'],
            };

            const el = (tag, texto, estilo) => {
                const no = document.createElement(tag);

                if (texto) {
                    no.textContent = texto;
                }

                if (estilo) {
                    no.style.cssText = estilo;
                }

                return no;
            };

            const etiqueta = (texto, fundo, cor) => el('span', texto, 'display:inline-block;margin-left:6px;padding:1px 8px;border-radius:9999px;font-size:11px;font-weight:700;background:' + fundo + ';color:' + cor);

            const etiquetas = (cliente) => {
                const grupo = document.createDocumentFragment();
                const credito = cliente.credito;

                if (! credito) {
                    return grupo;
                }

                if (credito.cor && CORES[credito.cor]) {
                    grupo.appendChild(etiqueta(CORES[credito.cor][2], CORES[credito.cor][0], CORES[credito.cor][1]));
                }

                if (credito.vencida) {
                    grupo.appendChild(etiqueta(credito.nunca ? 'Sem análise de crédito' : 'Cadastro vencido há ' + credito.dias + ' dias', '#ffedd5', '#9a3412'));
                }

                return grupo;
            };

            const mostrarAlerta = (cliente) => {
                const credito = cliente.credito;

                if (! credito || ! credito.vencida || ! g('cliente-alerta')) {
                    return;
                }

                g('cliente-alerta-texto').textContent = credito.nunca
                    ? 'Este cliente ainda não tem análise de crédito. Antes de seguir, peça ao Financeiro uma consulta cadastral.'
                    : 'A última análise de crédito deste cliente foi há ' + credito.dias + ' dias, e a validade do cadastro é de ' + credito.validade_dias + ' dias. Peça ao Financeiro uma nova consulta cadastral antes de liberar prazo.';
                g('cliente-alerta').style.display = 'flex';
                g('cliente-alerta-ok').focus();
            };

            const resumo = (cliente) => {
                const dados = g('cliente-escolhido-dados');

                if (! dados) {
                    return;
                }

                dados.innerHTML = '';

                const linha1 = el('div', null, 'font-weight:600');
                linha1.appendChild(document.createTextNode(cliente.nome));

                if (cliente.codigo) {
                    linha1.appendChild(el('span', 'Nº ' + cliente.codigo, 'margin-left:6px;padding:1px 6px;border-radius:4px;background:#e2e8f0;color:#475569;font-size:11px;font-weight:600'));
                }

                linha1.appendChild(etiquetas(cliente));
                dados.appendChild(linha1);

                const detalhe = [cliente.documento, cliente.telefones].filter(Boolean).join(' · ');

                if (detalhe) {
                    dados.appendChild(el('div', detalhe, 'font-size:12px;opacity:.8'));
                }
            };

            const escolher = (cliente, avisar) => {
                const oculto = g('person_id');

                oculto.value = cliente.id;
                resumo(cliente);
                g('cliente-escolhido').style.display = 'flex';
                g('cliente-campo').style.display = 'none';
                g('cliente-resultados').style.display = 'none';

                // O formulário carrega os veículos do cliente quando o campo muda.
                oculto.dispatchEvent(new Event('change', { bubbles: true }));

                if (avisar) {
                    mostrarAlerta(cliente);
                }
            };

            const desenhar = (resposta) => {
                const lista = g('cliente-resultados');

                if (! lista) {
                    return;
                }

                lista.innerHTML = '';

                if (! resposta.resultados.length) {
                    const vazio = el('div', 'Nenhum cliente encontrado.', 'padding:10px 12px;font-size:13px');

                    if (podeCadastrar) {
                        const link = el('a', 'Cadastrar cliente', 'margin-left:8px;color:#2563eb;font-weight:600;text-decoration:underline');
                        link.href = resposta.cadastro;
                        vazio.appendChild(link);
                    }

                    lista.appendChild(vazio);
                    lista.style.display = 'block';

                    return;
                }

                resposta.resultados.forEach((cliente) => {
                    const item = el('button', null, 'display:block;width:100%;text-align:left;padding:8px 12px;border:0;border-bottom:1px solid #e2e8f0;background:#ffffff;color:#111827;font-size:13px;cursor:pointer');
                    item.type = 'button';

                    const topo = el('div', null, 'font-weight:600');
                    topo.appendChild(document.createTextNode(cliente.nome));

                    if (cliente.codigo) {
                        topo.appendChild(el('span', 'Nº ' + cliente.codigo, 'margin-left:6px;padding:1px 6px;border-radius:4px;background:#e2e8f0;color:#475569;font-size:11px;font-weight:600'));
                    }

                    topo.appendChild(etiquetas(cliente));
                    item.appendChild(topo);

                    const linha = [cliente.documento, cliente.telefones, cliente.veiculos.slice(0, 2).join(' | ')].filter(Boolean).join(' · ');

                    if (linha) {
                        item.appendChild(el('div', linha, 'font-size:12px;color:#64748b'));
                    }

                    item.addEventListener('mouseover', () => { item.style.background = '#f1f5f9'; });
                    item.addEventListener('mouseout', () => { item.style.background = '#ffffff'; });
                    item.addEventListener('click', () => escolher(cliente, true));

                    lista.appendChild(item);
                });

                lista.style.display = 'block';
            };

            let espera = null;
            let ultima = 0;

            const pesquisar = (termo, demora) => {
                clearTimeout(espera);

                // Duas letras ou mais, ou um número (código do cliente).
                if (termo.length < 2 && ! /^\d+$/.test(termo)) {
                    if (g('cliente-resultados')) {
                        g('cliente-resultados').style.display = 'none';
                    }

                    return;
                }

                espera = setTimeout(() => {
                    const numero = ++ultima;

                    fetch(urlPesquisa + '?q=' + encodeURIComponent(termo), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                        .then((resposta) => resposta.json())
                        .then((resposta) => { if (numero === ultima) { desenhar(resposta); } })
                        .catch(() => { if (g('cliente-resultados')) { g('cliente-resultados').style.display = 'none'; } });
                }, demora);
            };

            document.addEventListener('input', (evento) => {
                if (! evento.target || evento.target.id !== 'cliente-busca') {
                    return;
                }

                pesquisar(evento.target.value.trim(), 250);
            });

            // Enter escolhe o primeiro resultado (e não envia o formulário); Esc fecha a lista.
            document.addEventListener('keydown', (evento) => {
                if (! evento.target || evento.target.id !== 'cliente-busca') {
                    return;
                }

                if (evento.key === 'Enter') {
                    evento.preventDefault();

                    const primeiro = g('cliente-resultados').querySelector('button');

                    if (primeiro) {
                        primeiro.click();
                    }
                } else if (evento.key === 'Escape') {
                    g('cliente-resultados').style.display = 'none';
                }
            });

            document.addEventListener('click', (evento) => {
                const alvo = evento.target;

                if (! alvo || ! alvo.closest) {
                    return;
                }

                // Lupa do campo: pesquisa na hora o que foi digitado; com o campo vazio, só leva o cursor para ele.
                if (alvo.closest('#cliente-lupa')) {
                    const campo = g('cliente-busca');
                    const termo = campo.value.trim();

                    campo.focus();

                    if (termo !== '') {
                        pesquisar(termo, 0);
                    }

                    return;
                }

                if (alvo.closest('#cliente-trocar')) {
                    const oculto = g('person_id');

                    oculto.value = '';
                    oculto.dispatchEvent(new Event('change', { bubbles: true }));
                    g('cliente-escolhido').style.display = 'none';
                    g('cliente-campo').style.display = 'block';
                    g('cliente-busca').value = '';
                    g('cliente-busca').focus();

                    return;
                }

                if (alvo.closest('#cliente-alerta-ok')) {
                    g('cliente-alerta').style.display = 'none';

                    return;
                }

                const bloco = g('cliente-busca-bloco');

                if (bloco && g('cliente-resultados') && ! bloco.contains(alvo)) {
                    g('cliente-resultados').style.display = 'none';
                }
            });

            // Cliente que já veio escolhido (voltou de um erro do formulário, ou veio da aba "Buscar cliente").
            // Roda depois que o painel do sistema monta a página.
            if (inicial) {
                resumo(inicial);

                window.addEventListener('load', () => setTimeout(() => {
                    resumo(inicial);
                    g('person_id').dispatchEvent(new Event('change', { bubbles: true }));

                    if (avisarAoAbrir) {
                        mostrarAlerta(inicial);
                    }
                }, 0));
            }
        })();
    </script>
@endpush
