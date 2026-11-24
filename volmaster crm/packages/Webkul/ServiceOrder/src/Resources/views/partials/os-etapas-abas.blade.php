{{--
    Passos da OS (pedido do patrão, 19/11/2026): Cliente > Ordem de Serviço > Diagnóstico > Orçamento...
    Cada passo mostra só o que é dele, abrindo sempre na etapa atual da OS. Antes, a tela mostrava as
    informações de todas as etapas de uma vez. As partes da tela que pertencem a um passo levam o
    atributo data-painel="Etapa" (ou "Etapa A|Etapa B"); "anexos" e "historico" são abas extras.

    Variáveis (vindas de view.blade): $serviceOrder e $abaInicial.
    Estilo direto no HTML de propósito (o CSS compilado do servidor só tem as classes que já existiam);
    cores com transparência para servir no tema claro e no escuro.

    IMPORTANTE: o painel do sistema (Vue) refaz a página depois de carregar, então o script não liga
    "escutas" direto nos elementos: trata o clique no document e busca os elementos na hora de usar
    (o mesmo padrão do resto da tela da OS).
--}}
@php
    $fluxoEtapas = \Webkul\ServiceOrder\Models\ServiceOrder::stagesFlow();
    $posicaoAtual = array_search($serviceOrder->workshop_status, $fluxoEtapas, true);
    $qtdAnexos = \Webkul\ServiceOrder\Services\ExtrasOs::disponivel() ? $serviceOrder->extras()->count() : 0;

    $abasOs = [];

    foreach ($fluxoEtapas as $indice => $etapa) {
        $abasOs[] = [
            'chave' => $etapa,
            'rotulo' => trans('service_order::app.stages.'.$etapa),
            'estado' => $posicaoAtual === false ? 'futuro' : ($indice < $posicaoAtual ? 'feito' : ($indice === $posicaoAtual ? 'atual' : 'futuro')),
        ];
    }

    $abasOs[] = ['chave' => 'anexos', 'rotulo' => trans('service_order::app.etapas-abas.anexos').($qtdAnexos ? ' ('.$qtdAnexos.')' : ''), 'estado' => 'extra'];
    $abasOs[] = ['chave' => 'historico', 'rotulo' => trans('service_order::app.etapas-abas.historico'), 'estado' => 'extra'];

    $dicaDe = function (string $chave): string {
        $texto = trans('service_order::app.dica.'.$chave);

        return $texto === 'service_order::app.dica.'.$chave ? '' : $texto;
    };

    $estiloAba = 'display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:9999px;font-size:13px;font-weight:600;cursor:pointer';
    $estiloAbaAtiva = $estiloAba.';background:#2563eb;border:1px solid #2563eb;color:#ffffff';
    $estiloAbaComum = $estiloAba.';background:rgba(148,163,184,.16);color:inherit;border:1px solid ';
@endphp

{{--
    O cartão do passo (#painel-passo) junta as seções do passo num painel só: os cartões internos viram
    seções simples, separadas por uma linha. Seletores por id para valer sobre as classes do Tailwind.
--}}
<style>
    #painel-passo .box-shadow { box-shadow: none; border: 0; border-radius: 0; background: transparent; padding: 0; }
    #painel-passo [data-painel] { border-style: solid; border-color: rgba(148, 163, 184, .3); border-width: 1px 0 0 0; border-radius: 0; margin-top: 18px; padding: 18px 0 0 0; }
    #painel-passo [data-painel][data-primeiro="1"] { border-width: 0; margin-top: 0; padding-top: 0; }
    #painel-passo [data-painel][data-painel-display="contents"] { border-width: 0; margin: 0; padding: 0; }
</style>

<div id="passos-os">
    <nav id="abas-os" aria-label="@lang('service_order::app.etapas-abas.aria')" style="display:flex;flex-wrap:wrap;gap:6px">
        @foreach ($abasOs as $aba)
            @php $ativa = $aba['chave'] === $abaInicial; @endphp
            <button
                type="button"
                role="tab"
                data-aba="{{ $aba['chave'] }}"
                data-estado="{{ $aba['estado'] }}"
                data-dica="{{ $dicaDe($aba['chave']) }}"
                aria-selected="{{ $ativa ? 'true' : 'false' }}"
                title="{{ $dicaDe($aba['chave']) }}"
                style="{{ $ativa ? $estiloAbaAtiva : $estiloAbaComum.($aba['estado'] === 'atual' ? '#f59e0b' : 'rgba(148,163,184,.55)') }}"
            >@if ($aba['estado'] === 'feito')<span aria-hidden="true" style="color:#16a34a">&#10003;</span>@elseif ($aba['estado'] === 'atual')<span aria-hidden="true" style="color:#f59e0b">&#9679;</span>@endif{{ $aba['rotulo'] }}</button>
        @endforeach
    </nav>

    <p id="etapa-dica" style="margin:8px 2px 0;font-size:13px;opacity:.85">{{ $dicaDe($abaInicial) }}</p>
</div>

@push('scripts')
    <script>
        (function () {
            const inicial = @json($abaInicial);

            const abas = () => Array.from(document.querySelectorAll('#abas-os [data-aba]'));

            const estilizar = (botao, ativa) => {
                botao.setAttribute('aria-selected', ativa ? 'true' : 'false');
                botao.style.background = ativa ? '#2563eb' : 'rgba(148,163,184,.16)';
                botao.style.borderColor = ativa ? '#2563eb' : (botao.dataset.estado === 'atual' ? '#f59e0b' : 'rgba(148,163,184,.55)');
                botao.style.color = ativa ? '#ffffff' : 'inherit';
            };

            // Parte da tela que não renderizou nada nesta OS (ex.: bloco só de quem vê valores) nunca aparece.
            const vazia = (parte) => {
                if (parte.dataset.painelVazio === undefined) {
                    const semConteudo = parte.textContent.trim() === '' && ! parte.querySelector('img, video, input, textarea, select, table, button');

                    parte.dataset.painelVazio = semConteudo ? '1' : '0';
                }

                return parte.dataset.painelVazio === '1';
            };

            const aplicar = (aba) => {
                abas().forEach((botao) => estilizar(botao, botao.dataset.aba === aba));

                document.querySelectorAll('[data-painel]').forEach((parte) => {
                    const mostra = parte.dataset.painel.split('|').includes(aba) && ! vazia(parte);

                    parte.style.display = mostra ? (parte.dataset.painelDisplay || '') : 'none';
                });

                // Cartões que só têm sentido com alguma parte visível (ex.: o cartão do passo).
                // Os de dentro primeiro (o fluxo da OS está dentro do cartão do passo).
                Array.from(document.querySelectorAll('[data-painel-pai]')).reverse().forEach((pai) => {
                    const algum = Array.from(pai.querySelectorAll('[data-painel]')).some((parte) => parte.style.display !== 'none');

                    pai.style.display = algum ? (pai.dataset.paiDisplay || '') : 'none';
                });

                // A primeira seção visível do cartão do passo não leva a linha de separação em cima.
                const secoes = Array.from(document.querySelectorAll('#painel-passo [data-painel]'));

                secoes.forEach((parte) => { delete parte.dataset.primeiro; });

                const primeira = secoes.find((parte) => parte.style.display !== 'none' && parte.dataset.painelDisplay !== 'contents');

                if (primeira) {
                    primeira.dataset.primeiro = '1';
                }

                const dica = document.getElementById('etapa-dica');
                const ativo = abas().find((botao) => botao.dataset.aba === aba);

                if (dica && ativo) {
                    dica.textContent = ativo.dataset.dica || '';
                }
            };

            // Os links que apontam para uma parte da tela (ex.: #anotacoes-anexos) abrem o passo onde ela está.
            const doEndereco = () => {
                const hash = decodeURIComponent(location.hash.slice(1));

                if (hash.startsWith('aba-')) {
                    const pedida = hash.slice(4);

                    return abas().some((botao) => botao.dataset.aba === pedida) ? { aba: pedida } : null;
                }

                const alvo = hash ? document.getElementById(hash) : null;
                const parte = alvo ? alvo.closest('[data-painel]') : null;

                if (! parte) {
                    return null;
                }

                const lista = parte.dataset.painel.split('|');

                return { aba: lista.includes(inicial) ? inicial : lista[0], alvo };
            };

            const iniciar = () => {
                if (! abas().length) {
                    return;
                }

                const pedido = doEndereco();

                aplicar(pedido ? pedido.aba : inicial);

                if (pedido && pedido.alvo) {
                    pedido.alvo.scrollIntoView({ block: 'start' });
                }
            };

            document.addEventListener('click', (evento) => {
                const botao = evento.target && evento.target.closest ? evento.target.closest('#abas-os [data-aba]') : null;

                if (! botao) {
                    return;
                }

                aplicar(botao.dataset.aba);
                history.replaceState(null, '', '#aba-' + encodeURIComponent(botao.dataset.aba));
            });

            iniciar();

            // De novo depois que o painel do sistema monta a página.
            window.addEventListener('load', () => setTimeout(iniciar, 0));
        })();
    </script>
@endpush
