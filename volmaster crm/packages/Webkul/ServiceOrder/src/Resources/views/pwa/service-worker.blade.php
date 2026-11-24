@verbatim
/*
 * Service worker do app Volmaster Oficina: recebe as notificações (Web Push),
 * mostra na tela do celular e confirma "entregue" e "aberto" para o sistema.
 * Tocar na notificação abre direto a OS.
 */
self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

function confirmar(tokens, status) {
    return Promise.all((tokens || []).map(function (token) {
        return fetch('/workshop/push/confirmar/' + encodeURIComponent(token) + '/' + status, { method: 'POST' }).catch(function () {});
    }));
}

self.addEventListener('push', function (event) {
    var data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'Volmaster', body: event.data ? event.data.text() : '' };
    }

    var critico = !!data.critical;

    // O som é o padrão do aparelho (a web não permite escolher). O alerta
    // crítico se distingue pela vibração longa e por ficar na tela até ser tocado.
    var options = {
        body: data.body || '',
        tag: data.tag || undefined,
        renotify: !!data.tag,
        icon: '/workshop-icone-192.png',
        badge: '/workshop-icone-192.png',
        vibrate: critico ? [500, 200, 500, 200, 500, 200, 900] : [150, 80, 150],
        requireInteraction: critico,
        data: { url: data.url || '/', tokens: data.tokens || [] }
    };

    event.waitUntil(Promise.all([
        self.registration.showNotification(data.title || 'Volmaster', options),
        confirmar(data.tokens, 'entregue')
    ]));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var info = event.notification.data || {};
    var destino = new URL(info.url || '/', self.location.origin).href;

    event.waitUntil(Promise.all([
        confirmar(info.tokens, 'aberto'),
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (janelas) {
            for (var i = 0; i < janelas.length; i++) {
                if ('navigate' in janelas[i]) {
                    return janelas[i].navigate(destino).then(function (c) { return c && c.focus(); });
                }
            }

            return self.clients.openWindow(destino);
        })
    ]));
});
@endverbatim
