// sw.js — Service Worker MetaDash
// Versão com re-subscribe automático para iOS

var CACHE_VERSION = 'metadash-v3';

// ── INSTALL / ACTIVATE ────────────────────────────────────────
self.addEventListener('install', function(event) {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(self.clients.claim());
});

// ── PUSH ──────────────────────────────────────────────────────
self.addEventListener('push', function(event) {
    if (!event.data) return;

    var data;
    try { data = event.data.json(); }
    catch(e) { data = { title: 'MetaDash', body: event.data.text() }; }

    var title   = data.title || 'MetaDash';
    var options = {
        body:    data.body  || '',
        icon:    data.icon  || '/assets/icon-192.png',
        badge:   data.badge || '/assets/icon-72.png',
        data:    { url: data.url || '/' },
        // iOS requer requireInteraction:false para não bloquear a tela
        requireInteraction: false,
        // tag agrupa notificações do mesmo tipo (evita acúmulo)
        tag:     data.tag   || 'metadash-push',
        renotify: true,
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// ── CLICK ─────────────────────────────────────────────────────
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url)
        ? event.notification.data.url
        : '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(list) {
            // Se já tem uma aba aberta, foca ela e navega
            for (var i = 0; i < list.length; i++) {
                var client = list[i];
                if ('focus' in client) {
                    client.focus();
                    if ('navigate' in client) client.navigate(url);
                    return;
                }
            }
            // Senão abre nova aba
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});

// ── PUSH SUBSCRIPTION CHANGE (iOS invalida subscription) ──────
// Evento disparado quando iOS/browser invalida a subscription antiga
// e fornece uma nova automaticamente — precisamos salvar no backend
self.addEventListener('pushsubscriptionchange', function(event) {
    event.waitUntil(
        self.registration.pushManager.subscribe({
            userVisibleOnly:      true,
            applicationServerKey: urlBase64ToUint8Array(
                'BOD8saPXZbuLU_Tf1HvD-eNQekZBjmjcyuBeOhyd5Khld_THUGpP4adEgl_RD9q3YPr-oEDPezxS3buQk7Mippo'
            )
        }).then(function(sub) {
            var k = sub.getKey('p256dh');
            var a = sub.getKey('auth');
            return fetch('/api/push_subscribe.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    action:   'subscribe',
                    endpoint: sub.endpoint,
                    keys: {
                        p256dh: arrayBufferToBase64(k),
                        auth:   arrayBufferToBase64(a)
                    }
                })
            });
        }).catch(function(e) {
            // Não conseguiu re-subscribir — aguarda próximo acesso do usuário
        })
    );
});

// ── HELPERS ───────────────────────────────────────────────────
function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw     = self.atob(base64);
    var output  = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);
    return output;
}

function arrayBufferToBase64(buffer) {
    var bytes = new Uint8Array(buffer);
    var bin   = '';
    for (var i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i]);
    return self.btoa(bin);
}