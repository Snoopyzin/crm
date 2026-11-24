{{--
    Cartão "Notificações no celular" na tela Minha Conta (A2 do roteiro): ativa o aparelho
    e manda uma notificação de teste para quem está logado. A página é montada pelo Vue
    dentro de #app, então nada guarda referência de elemento: os cliques são delegados no
    document e os textos são pintados procurando o elemento (data-vm-push) na hora.
--}}
@if (auth()->guard('user')->check() && \Webkul\ServiceOrder\Services\StageGate::active())
    <div class="mx-4 mb-8 mt-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900 sm:mx-8">
        <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">Notificações no celular</p>
        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
            Ative as notificações neste aparelho e toque em <b>Testar notificação</b> para conferir se o aviso chega e se, ao tocar nele, o sistema abre.
            No iPhone, adicione o sistema à Tela de Início antes (Safari &gt; Compartilhar &gt; Adicionar à Tela de Início; iOS 16.4 ou superior) e abra o app por lá.
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="secondary-button" data-vm-push-ativar>Ativar neste aparelho</button>
            <button type="button" class="primary-button" data-vm-push-testar>Testar notificação</button>
        </div>

        <p class="mt-3 text-sm text-gray-700 dark:text-gray-200" data-vm-push="status" role="status"></p>
    </div>

    <script>
        (function () {
            if (window.__vmPushConta) { return; }
            window.__vmPushConta = true;

            var urls = {
                testar: @json(route('admin.workshop.push.test', [], false)),
                inscrever: @json(route('admin.workshop.push.subscribe', [], false)),
                chave: '/workshop/push/chave',
                sw: '/workshop-sw.js'
            };
            var csrf = @json(csrf_token());

            function pintar(texto, erro) {
                var alvo = document.querySelector('[data-vm-push="status"]');
                if (!alvo) { return; }
                alvo.textContent = texto;
                alvo.style.color = erro ? '#b91c1c' : '';
            }

            function ehIphone() {
                return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            }

            function instalado() {
                return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            }

            function b64ParaBytes(texto) {
                var padding = '='.repeat((4 - texto.length % 4) % 4);
                var bruto = atob((texto + padding).replace(/-/g, '+').replace(/_/g, '/'));
                var bytes = new Uint8Array(bruto.length);
                for (var i = 0; i < bruto.length; i++) { bytes[i] = bruto.charCodeAt(i); }
                return bytes;
            }

            function ativar() {
                if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                    pintar('Este navegador não recebe notificações. Use o Chrome (Android) ou o Safari (iPhone).', true);
                    return;
                }

                if (ehIphone() && !instalado()) {
                    pintar('No iPhone, primeiro adicione o sistema à Tela de Início (Safari > Compartilhar > Adicionar à Tela de Início) e abra o app por lá.', true);
                    return;
                }

                pintar('Ativando...');

                navigator.serviceWorker.register(urls.sw, { scope: '/' })
                    .then(function (registro) {
                        return Notification.requestPermission().then(function (permissao) {
                            if (permissao !== 'granted') { throw new Error('Permissão não concedida. Libere as notificações nas configurações do navegador.'); }

                            return fetch(urls.chave, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (dados) {
                                if (!dados.chave) { throw new Error('As chaves das notificações ainda não foram geradas. Peça ao responsável da oficina (Configurações > Indicadores e alertas).'); }

                                return registro.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ParaBytes(dados.chave) });
                            });
                        });
                    })
                    .then(function (inscricao) {
                        return fetch(urls.inscrever, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                            credentials: 'same-origin',
                            body: JSON.stringify(inscricao.toJSON())
                        });
                    })
                    .then(function (resposta) {
                        if (!resposta.ok) { throw new Error('Não foi possível guardar este aparelho. Tente de novo.'); }
                        pintar('Pronto: este aparelho está ativado. Toque em "Testar notificação".');
                    })
                    .catch(function (erro) { pintar(erro.message || 'Não foi possível ativar as notificações.', true); });
            }

            function testar() {
                pintar('Enviando...');

                fetch(urls.testar, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json().then(function (corpo) { return { ok: r.ok, corpo: corpo }; }); })
                    .then(function (r) { pintar(r.corpo.mensagem || 'Não foi possível enviar o teste.', r.corpo.status !== 'enviado'); })
                    .catch(function () { pintar('Não foi possível enviar o teste. Confira a conexão e tente de novo.', true); });
            }

            document.addEventListener('click', function (evento) {
                if (evento.target.closest('[data-vm-push-ativar]')) { evento.preventDefault(); ativar(); }
                if (evento.target.closest('[data-vm-push-testar]')) { evento.preventDefault(); testar(); }
            });
        })();
    </script>
@endif
