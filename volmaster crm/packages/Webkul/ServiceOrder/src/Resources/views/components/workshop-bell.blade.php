@if (auth()->guard('user')->check() && \Webkul\ServiceOrder\Services\StageGate::active())
    @php
        $alertasUrl = route('admin.workshop.alerts.index', [], false);
        $resumoUrl = route('admin.workshop.alerts.summary', [], false);
        $inscreverUrl = route('admin.workshop.push.subscribe', [], false);
        $lerTodosUrl = route('admin.workshop.alerts.read_all', [], false);
    @endphp

    @if ($seloTeste = \Webkul\ServiceOrder\Services\ModoTeste::selo())
        <div id="vm-modo-teste" style="position:fixed; left:50%; transform:translateX(-50%); top:0; z-index:70; background:#b45309; color:#fff; font-size:12px; font-weight:700; letter-spacing:.04em; padding:3px 14px; border-radius:0 0 8px 8px; box-shadow:0 2px 8px rgba(0,0,0,.3); pointer-events:none;">{{ $seloTeste }}</div>
    @endif

    {{-- Sino de alertas (canto inferior direito, acima do rodapé do sistema). --}}
    <div id="vm-bell" style="position:fixed; right:16px; bottom:84px; z-index:60; font-family:inherit;">
        <button id="vm-bell-btn" type="button" aria-label="Alertas da oficina" style="position:relative; width:48px; height:48px; border-radius:50%; border:0; background:#0E90D9; color:#fff; box-shadow:0 4px 14px rgba(0,0,0,.25); cursor:pointer; display:flex; align-items:center; justify-content:center;">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
            <span id="vm-bell-count" style="display:none; position:absolute; top:-4px; right:-4px; min-width:20px; height:20px; padding:0 5px; border-radius:10px; background:#dc2626; color:#fff; font-size:11px; font-weight:700; line-height:20px; text-align:center;">0</span>
        </button>

        <div id="vm-bell-panel" style="display:none; position:absolute; right:0; bottom:58px; width:340px; max-width:calc(100vw - 32px); max-height:70vh; overflow:auto; background:#fff; color:#1f2a30; border:1px solid #cfd8dc; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.25);">
            <div style="padding:10px 12px; border-bottom:1px solid #eceff1; display:flex; justify-content:space-between; align-items:center;">
                <strong style="font-size:14px;">Alertas da oficina</strong>
                <button id="vm-bell-readall" type="button" style="font-size:12px; color:#0E90D9; background:none; border:0; cursor:pointer;">Marcar como lidos</button>
            </div>

            <div id="vm-bell-push" style="display:none; padding:10px 12px; background:#fff8e1; border-bottom:1px solid #ffe082; font-size:12px;"></div>

            <div id="vm-bell-list" style="font-size:13px;"></div>

            <div style="padding:8px 12px; border-top:1px solid #eceff1; text-align:center;">
                <a href="{{ $alertasUrl }}" style="font-size:12px; color:#0E90D9;">Ver todos os alertas</a>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var urls = {
                resumo: @json($resumoUrl),
                inscrever: @json($inscreverUrl),
                lerTodos: @json($lerTodosUrl),
                chave: '/workshop/push/chave',
                sw: '/workshop-sw.js'
            };
            var csrf = @json(csrf_token());

            function $(id) { return document.getElementById(id); }

            function esc(text) {
                var div = document.createElement('div');
                div.textContent = text == null ? '' : String(text);
                return div.innerHTML;
            }

            function guardar(chave, valor) { try { localStorage.setItem(chave, valor); } catch (e) {} }
            function ler(chave) { try { return localStorage.getItem(chave); } catch (e) { return null; } }

            function atualizar() {
                fetch(urls.resumo, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (dados) {
                        if (!dados || !dados.ativo) { return; }

                        var contador = $('vm-bell-count');
                        contador.textContent = dados.contador > 99 ? '99+' : dados.contador;
                        contador.style.display = dados.contador > 0 ? 'block' : 'none';

                        $('vm-bell-list').innerHTML = dados.itens.length ? dados.itens.map(function (item) {
                            var corte = item.critico ? '#dc2626' : '#0E90D9';
                            var corpo = '<div style="padding:10px 12px; border-bottom:1px solid #f1f4f6; border-left:4px solid ' + corte + '; ' + (item.lido ? '' : 'background:#f3f9fe;') + '">'
                                + '<div style="font-weight:700; font-size:12px; color:' + corte + ';">' + (item.critico ? 'CRÍTICO · ' : '') + esc(item.titulo) + ' <span style="font-weight:400; color:#78909c;">' + esc(item.quando) + '</span></div>'
                                + '<div style="margin-top:2px;">' + esc(item.mensagem) + '</div></div>';

                            return item.url ? '<a href="' + esc(item.url) + '" style="display:block; text-decoration:none; color:inherit;">' + corpo + '</a>' : corpo;
                        }).join('') : '<div style="padding:16px 12px; color:#78909c;">Nenhum alerta aberto.</div>';
                    })
                    .catch(function () {});
            }

            $('vm-bell-btn').addEventListener('click', function () {
                var painel = $('vm-bell-panel');
                painel.style.display = painel.style.display === 'none' ? 'block' : 'none';
            });

            $('vm-bell-readall').addEventListener('click', function () {
                fetch(urls.lerTodos, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, credentials: 'same-origin' }).then(atualizar);
            });

            // ---- Notificação no celular (Web Push) ----
            function b64ParaBytes(texto) {
                var padding = '='.repeat((4 - texto.length % 4) % 4);
                var base64 = (texto + padding).replace(/-/g, '+').replace(/_/g, '/');
                var bruto = atob(base64);
                var bytes = new Uint8Array(bruto.length);
                for (var i = 0; i < bruto.length; i++) { bytes[i] = bruto.charCodeAt(i); }
                return bytes;
            }

            // iPad moderno se identifica como Macintosh: o toque na tela é o que o denuncia.
            function ehIphone() { return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); }

            // Folha de aviso do iPhone na PRIMEIRA vez que a pessoa entra por ele (some com "Entendi" e não volta).
            function folhaIphone() {
                if (ler('vm-ios-aviso') === '1' || $('vm-ios-folha')) { return; }

                var folha = document.createElement('div');
                folha.id = 'vm-ios-folha';
                folha.style.cssText = 'position:fixed; left:12px; right:12px; bottom:12px; z-index:80; background:#fff; color:#1f2a30; border:1px solid #cfd8dc; border-radius:12px; box-shadow:0 8px 24px rgba(0,0,0,.3); padding:14px 16px; font-size:14px; font-family:inherit;';
                folha.innerHTML = '<div style="font-weight:700; margin-bottom:6px;">Receba os alertas no iPhone</div>'
                    + '<div>No iPhone as notificações só funcionam com o sistema adicionado à Tela de Início (iOS 16.4 ou superior). Toque em <b>Compartilhar</b> no Safari, depois em <b>Adicionar à Tela de Início</b> e abra o app por lá.</div>'
                    + '<div style="text-align:right; margin-top:10px;"><button type="button" id="vm-ios-ok" style="padding:6px 16px; border:0; border-radius:6px; background:#0E90D9; color:#fff; font-weight:600; cursor:pointer;">Entendi</button></div>';
                document.body.appendChild(folha);

                $('vm-ios-ok').addEventListener('click', function () {
                    guardar('vm-ios-aviso', '1');
                    folha.remove();
                });
            }
            function instalado() { return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true; }

            function inscrever(registro) {
                return fetch(urls.chave).then(function (r) { return r.json(); }).then(function (dados) {
                    if (!dados.chave) { throw new Error('Chaves de notificação ainda não configuradas.'); }

                    return registro.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ParaBytes(dados.chave) });
                }).then(function (inscricao) {
                    return fetch(urls.inscrever, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                        credentials: 'same-origin',
                        body: JSON.stringify(inscricao.toJSON())
                    });
                });
            }

            function aviso(html) {
                var caixa = $('vm-bell-push');
                caixa.innerHTML = html;
                caixa.style.display = 'block';
            }

            function prepararPush() {
                if (!('serviceWorker' in navigator)) {
                    aviso('Este navegador não recebe notificações. Use o Chrome (Android) ou o Safari (iPhone).');
                    return;
                }

                navigator.serviceWorker.register(urls.sw, { scope: '/' }).then(function (registro) {
                    // iPhone só recebe notificação com o app adicionado à tela inicial (iOS 16.4 ou superior).
                    if (ehIphone() && !instalado()) {
                        folhaIphone();
                        aviso('<b>Receba os alertas no iPhone:</b> toque em <b>Compartilhar</b> no Safari, depois em <b>Adicionar à Tela de Início</b> (iOS 16.4 ou superior) e abra o app por lá.');
                        return;
                    }

                    if (!('PushManager' in window) || !('Notification' in window)) {
                        aviso('Este aparelho não recebe notificações pelo navegador.');
                        return;
                    }

                    if (Notification.permission === 'granted') {
                        inscrever(registro).catch(function () {});
                        return;
                    }

                    if (Notification.permission === 'denied') {
                        aviso('As notificações estão bloqueadas neste aparelho. Libere nas configurações do navegador para receber os alertas.');
                        return;
                    }

                    if (ler('vm-push-dispensado') === '1') { return; }

                    aviso('<b>Ative as notificações</b> para receber os alertas no celular. '
                        + '<button id="vm-push-on" type="button" style="margin-left:6px; padding:3px 10px; border:0; border-radius:4px; background:#0E90D9; color:#fff; cursor:pointer;">Ativar</button> '
                        + '<button id="vm-push-off" type="button" style="margin-left:4px; padding:3px 8px; border:0; background:none; color:#78909c; cursor:pointer;">Agora não</button>'
                        + '<div style="margin-top:6px; color:#607d8b;">Para instalar o app: no Android use o menu do Chrome &gt; <b>Instalar app</b>; no iPhone use <b>Compartilhar</b> &gt; <b>Adicionar à Tela de Início</b>.</div>');

                    $('vm-push-on').addEventListener('click', function () {
                        Notification.requestPermission().then(function (permissao) {
                            if (permissao === 'granted') {
                                $('vm-bell-push').style.display = 'none';
                                inscrever(registro).catch(function (erro) { aviso('Não foi possível ativar: ' + esc(erro.message)); });
                            } else {
                                aviso('Permissão não concedida. Você ainda vê os alertas no sino.');
                            }
                        });
                    });

                    $('vm-push-off').addEventListener('click', function () {
                        guardar('vm-push-dispensado', '1');
                        $('vm-bell-push').style.display = 'none';
                    });
                }).catch(function () {});
            }

            atualizar();
            setInterval(atualizar, 60000);
            prepararPush();
        })();
    </script>
@endif
