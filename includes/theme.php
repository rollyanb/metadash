<?php
// includes/theme.php — MetaDash v2 (novo design Inter/minimalista)

function getTheme() {
    if (class_exists('Auth')) { Auth::startSession(); }
    elseif (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['theme']) && in_array($_SESSION['theme'], ['dark','light'])) {
        return $_SESSION['theme'];
    }
    if (!empty($_SESSION['user_id'])) {
        if (!class_exists('Database')) {
            $base = dirname(dirname(__FILE__));
            require_once $base . '/config/database.php';
        }
        $db   = Database::getInstance();
        $user = $db->fetch("SELECT theme FROM users WHERE id = ?", array($_SESSION['user_id']));
        $theme = ($user && in_array($user['theme'], ['dark','light'])) ? $user['theme'] : 'dark';
        $_SESSION['theme'] = $theme;
        return $theme;
    }
    return 'dark';
}

function getThemeClass() {
    return 'theme-' . getTheme();
}

function echoThemeAssets($sidebarBase = '') {
    $theme = getTheme();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* ── TOKENS ──────────────────────────────────────────── */
html.theme-dark {
  --bg:#08080c; --sf:#0f0f15; --s2:#16161e; --s3:#1e1e28;
  --bd:rgba(255,255,255,.07); --bd2:rgba(255,255,255,.12);
  --tx:#ededf2; --mt:#5f5f6d; --mt2:#9494a3;
  --ac:#5470ff; --gn:#3ecf8e; --rd:#f0645a; --yw:#f5a623;
  --surface:#0f0f15; --surface2:#16161e; --border:rgba(255,255,255,.07);
  --text:#ededf2; --muted:#5f5f6d; --muted2:#9494a3;
  --accent:#5470ff; --green:#3ecf8e; --red:#f0645a;
}
html.theme-light {
  --bg:#f6f6f8; --sf:#ffffff; --s2:#f0f0f3; --s3:#e8e8ec;
  --bd:rgba(15,15,25,.08); --bd2:rgba(15,15,25,.14);
  --tx:#16161d; --mt:#9b9ba7; --mt2:#5a5a67;
  --ac:#3a5cf0; --gn:#15a06a; --rd:#d8473c; --yw:#d97706;
  --surface:#ffffff; --surface2:#f0f0f3; --border:rgba(15,15,25,.08);
  --text:#16161d; --muted:#9b9ba7; --muted2:#5a5a67;
  --accent:#3a5cf0; --green:#15a06a; --red:#d8473c;
}

/* ── BASE ────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
  font-family: 'Inter', system-ui, sans-serif;
  font-size: 14px;
  -webkit-font-smoothing: antialiased;
  background: var(--bg);
  color: var(--tx);
  min-height: 100vh;
  display: flex;  /* sidebar is position:fixed, .main needs margin-left */
}
.app-wrap { display: contents; } /* passthrough — actual layout is body flex */

/* ── SCROLLBAR ───────────────────────────────────────── */
::-webkit-scrollbar { width: 9px; height: 9px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--bd2); border-radius: 9px; }
::-webkit-scrollbar-thumb:hover { background: var(--mt); }
::selection { background: rgba(84,112,255,.22); }

/* ── LAYOUT ──────────────────────────────────────────── */

/* ── SIDEBAR ─────────────────────────────────────────── */
.sidebar {
  width: 220px; flex: none;
  background: var(--sf);
  border-right: 1px solid var(--bd);
  display: flex; flex-direction: column;
  padding: 16px 12px;
  position: fixed; top: 0; left: 0; bottom: 0;
  z-index: 100; overflow-y: auto;
}
.sidebar-logo {
  display: flex; align-items: center; gap: 10px;
  padding: 5px 8px 14px;
}
.sidebar-logo-icon {
  width: 26px; height: 26px; border-radius: 7px;
  background: var(--ac); display: flex; align-items: center;
  justify-content: center; color: #fff;
  font-weight: 700; font-size: 14px; letter-spacing: -.02em;
  flex: none;
}
.sidebar-logo-text {
  font-size: 14.5px; font-weight: 600; letter-spacing: -.015em;
}
.sidebar-logo-text span { color: var(--ac); }

.nav-section {
  font-size: 10px; letter-spacing: .09em;
  text-transform: uppercase; color: var(--mt);
  padding: 14px 8px 5px; font-weight: 600;
}
.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 7.5px 9px; border-radius: 8px;
  font-size: 13px; color: var(--mt2);
  text-decoration: none; font-weight: 500;
  transition: background .1s, color .1s;
  margin-bottom: 1px; cursor: pointer;
  border: none; background: none; width: 100%;
  text-align: left; font-family: inherit;
}
.nav-item:hover { background: var(--s2); color: var(--tx); }
.nav-item.active { background: var(--s2); color: var(--tx); }
.nav-item svg { flex: none; opacity: .7; }
.nav-item.active svg { opacity: 1; }

.sidebar-footer {
  margin-top: auto;
  padding-top: 12px;
  border-top: 1px solid var(--bd);
  display: flex; flex-direction: column; gap: 1px;
}

/* ── MAIN ────────────────────────────────────────────── */
.main {
  margin-left: 220px;
  flex: 1; min-width: 0;
  padding: 28px 30px 48px;
  min-height: 100vh;
}

/* ── PAGE HEADER ─────────────────────────────────────── */
.page-title {
  margin: 0; font-size: 22px; font-weight: 600;
  letter-spacing: -.025em;
}
.page-sub {
  margin: 5px 0 0; font-size: 13px; color: var(--mt2);
}

/* ── STAT BAR ────────────────────────────────────────── */
.stat-bar {
  display: flex; background: var(--sf);
  border: 1px solid var(--bd); border-radius: 14px;
  padding: 16px 4px; margin-bottom: 26px;
}
.stat-item {
  flex: 1; min-width: 0; padding: 2px 16px;
}
.stat-item + .stat-item { border-left: 1px solid var(--bd); }
.stat-label {
  font-size: 10px; text-transform: uppercase;
  letter-spacing: .08em; color: var(--mt); font-weight: 600;
  margin-bottom: 8px;
}
.stat-value {
  font-family: 'JetBrains Mono', monospace;
  font-size: 20px; font-weight: 600;
  letter-spacing: -.02em; line-height: 1.1;
}
.stat-value.blue  { color: var(--ac); }
.stat-value.green { color: var(--gn); }
.stat-value.red   { color: var(--rd); }
.stat-value.muted { color: var(--mt2); }

/* ── CARDS ───────────────────────────────────────────── */
.cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 14px;
}
.card {
  background: var(--sf);
  border: 1px solid var(--bd);
  border-radius: 14px; padding: 18px;
  display: flex; flex-direction: column; gap: 16px;
}
.card:hover { border-color: var(--bd2); }
.card-header {
  display: flex; align-items: center;
  justify-content: space-between; gap: 10px;
}
.card-name {
  font-size: 14px; font-weight: 600;
  letter-spacing: -.01em; line-height: 1.3;
}
.card-acct {
  font-size: 10.5px; color: var(--mt);
  font-family: 'JetBrains Mono', monospace;
  margin-top: 2px;
}
.status-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 9px; border-radius: 20px;
  font-size: 11px; font-weight: 600; flex: none;
}
.status-badge.active  { background: rgba(62,207,142,.12); color: var(--gn); }
.status-badge.paused  { background: rgba(148,148,163,.1); color: var(--mt2); }
.status-badge.inactive{ background: rgba(148,148,163,.08); color: var(--mt); }
.status-dot {
  width: 5px; height: 5px; border-radius: 50%;
  background: currentColor;
}

.card-metrics {
  display: grid; grid-template-columns: 1fr 1fr; gap: 14px 20px;
}
.metric-label {
  font-size: 10px; text-transform: uppercase;
  letter-spacing: .07em; color: var(--mt); font-weight: 600;
  margin-bottom: 5px;
}
.metric-value {
  font-family: 'JetBrains Mono', monospace;
  font-size: 15px; font-weight: 600;
  letter-spacing: -.01em;
}
.metric-value.blue  { color: var(--ac); }
.metric-value.green { color: var(--gn); }
.metric-value.red   { color: var(--rd); }

.card-footer {
  display: flex; align-items: center;
  justify-content: space-between;
  font-size: 11px; color: var(--mt);
  padding-top: 10px; border-top: 1px solid var(--bd);
}
.sync-dot {
  width: 5px; height: 5px; border-radius: 50%;
  background: var(--gn); display: inline-block; margin-right: 4px;
}

/* ── BUTTONS ─────────────────────────────────────────── */
.btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 8px 15px; border-radius: 9px; border: none;
  font-size: 12.5px; font-weight: 600; font-family: 'Inter', inherit;
  cursor: pointer; transition: opacity .15s, transform .1s;
  text-decoration: none; white-space: nowrap;
}
.btn:active { transform: scale(.98); }
.btn-primary { background: var(--ac); color: #fff; }
.btn-primary:hover { opacity: .88; }
.btn-o {
  background: none; border: 1px solid var(--bd);
  color: var(--mt2);
}
.btn-o:hover { background: var(--s2); color: var(--tx); border-color: var(--bd2); }
.btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; }
.btn-danger { background: rgba(240,100,90,.12); color: var(--rd); border: 1px solid rgba(240,100,90,.2); }

/* ── SECTIONS / PANELS ───────────────────────────────── */
.section-title {
  font-size: 10.5px; text-transform: uppercase;
  letter-spacing: .08em; color: var(--mt); font-weight: 600;
  margin-bottom: 14px;
}
.panel {
  background: var(--sf); border: 1px solid var(--bd);
  border-radius: 14px; padding: 20px 22px; margin-bottom: 18px;
}

/* ── TABS ────────────────────────────────────────────── */
.tabs-row {
  display: flex; gap: 2px;
  border-bottom: 1px solid var(--bd);
  margin-bottom: 24px; overflow-x: auto;
}
.tab-btn {
  padding: 9px 15px 10px; font-size: 13px; font-weight: 500;
  color: var(--mt2); background: none; border: none;
  border-bottom: 2px solid transparent; margin-bottom: -1px;
  cursor: pointer; font-family: inherit; white-space: nowrap;
  transition: color .15s;
}
.tab-btn:hover { color: var(--tx); }
.tab-btn.active {
  color: var(--ac);
  border-bottom-color: var(--ac);
  font-weight: 600;
}

/* ── TABLE ───────────────────────────────────────────── */
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th {
  padding: 9px 13px; text-align: left;
  font-size: 10px; text-transform: uppercase; letter-spacing: .07em;
  color: var(--mt); font-weight: 600;
  border-bottom: 1px solid var(--bd);
}
tbody td {
  padding: 11px 13px;
  border-bottom: 1px solid var(--bd);
  vertical-align: middle;
}
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover { background: var(--s2); }

/* ── FORMS ───────────────────────────────────────────── */
input[type="text"], input[type="date"], input[type="number"],
input[type="password"], input[type="email"], select, textarea {
  background: var(--s2); color: var(--tx);
  border: 1px solid var(--bd); border-radius: 8px;
  padding: 8px 12px; font-size: 13px; font-family: inherit;
  outline: none; transition: border-color .15s;
}
input:focus, select:focus, textarea:focus {
  border-color: var(--ac); box-shadow: 0 0 0 3px rgba(84,112,255,.12);
}
select option { background: var(--sf); color: var(--tx); }

/* ── MODAL ───────────────────────────────────────────── */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.55); z-index: 500;
  display: flex; align-items: center; justify-content: center;
  backdrop-filter: blur(4px);
}
.modal-box {
  background: var(--sf); border: 1px solid var(--bd);
  border-radius: 16px; padding: 24px;
  width: 100%; max-width: 440px;
  margin: 0 16px; max-height: 88vh;
  overflow-y: auto;
}
.modal-title {
  font-size: 16px; font-weight: 600;
  letter-spacing: -.02em; margin-bottom: 18px;
}

/* ── PROGRESS BAR ────────────────────────────────────── */
.progress-bar {
  height: 4px; border-radius: 4px;
  background: var(--s2); overflow: hidden;
  margin-top: 8px;
}
.progress-fill {
  height: 100%; border-radius: 4px;
  background: var(--ac); transition: width .4s ease;
}

/* ── NOTIFICATION DOT ────────────────────────────────── */
.notif-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--rd); display: inline-block;
}

/* ── RESPONSIVE MOBILE ───────────────────────────────── */
@media (max-width: 768px) {
  .sidebar { transform: translateX(-100%); transition: transform .25s; }
  .sidebar.open { transform: translateX(0); }
  .main { margin-left: 0; padding: 72px 16px 40px; }
  .stat-bar { flex-wrap: wrap; }
  .stat-item { min-width: 45%; }
  .cards-grid { grid-template-columns: 1fr; }
  .mob-topbar {
    position: fixed; top: 0; left: 0; right: 0; height: 56px;
    background: var(--sf); border-bottom: 1px solid var(--bd);
    z-index: 99; display: flex; align-items: center;
    padding: 0 16px; gap: 12px;
  }
  .mob-topbar-logo {
    display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 14px;
  }
}
@media (min-width: 769px) { .mob-topbar { display: none; } }

/* ── LEGACY COMPAT ───────────────────────────────────── */
/* Mantém variáveis antigas para componentes não migrados */
html.theme-dark  { --ac:#5470ff;--accent:#5470ff;--accent2:#7c91ff }
html.theme-light { --ac:#3a5cf0;--accent:#3a5cf0;--accent2:#6076e8 }
.theme-dark  { color-scheme: dark; }
.theme-light { color-scheme: light; }
</style>
<meta name="csrf-token" content="<?= class_exists('Auth') ? htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') : '' ?>">
<script>
// ── CSRF (automático) ─────────────────────────────────────────
// Injeta o token em todo fetch() POST/PUT/DELETE same-origin e em todo <form method="post">.
(function(){
  var meta = document.querySelector('meta[name="csrf-token"]');
  var token = meta ? meta.getAttribute('content') : '';
  window.MD_CSRF = token;
  if (!token) return;

  function sameOrigin(url){
    try { var u = new URL(url, location.href); return u.origin === location.origin; } catch(e){ return true; }
  }
  var _fetch = window.fetch;
  window.fetch = function(input, init){
    init = init || {};
    var method = (init.method || (input && input.method) || 'GET').toUpperCase();
    var url = (typeof input === 'string') ? input : (input && input.url) || '';
    if (method !== 'GET' && method !== 'HEAD' && sameOrigin(url)) {
      var h = init.headers;
      if (h instanceof Headers) { if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', token); }
      else { h = Object.assign({}, h || {}); if (!h['X-CSRF-Token']) h['X-CSRF-Token'] = token; init.headers = h; }
    }
    return _fetch.call(this, input, init);
  };
  var _open = XMLHttpRequest.prototype.open, _send = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function(m,u){ this._mdMethod=(m||'GET').toUpperCase(); this._mdUrl=u; return _open.apply(this, arguments); };
  XMLHttpRequest.prototype.send = function(){
    if (this._mdMethod !== 'GET' && this._mdMethod !== 'HEAD' && sameOrigin(this._mdUrl)) {
      try { this.setRequestHeader('X-CSRF-Token', token); } catch(e){}
    }
    return _send.apply(this, arguments);
  };
  function inject(form){
    if (!form || (form.method||'get').toLowerCase() !== 'post') return;
    if (form.querySelector('input[name="_csrf"]')) return;
    var i = document.createElement('input'); i.type='hidden'; i.name='_csrf'; i.value=token; form.appendChild(i);
  }
  document.addEventListener('submit', function(e){ inject(e.target); }, true);
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('form[method="post" i]').forEach(inject);
  });
})();

(function(){
  var saved = localStorage.getItem('md_theme') || 'dark';
  if(saved === 'light' || saved === 'dark'){
    var html = document.documentElement;
    html.className = html.className.replace(/theme-\w+/g,'').trim() + ' theme-' + saved;
  }
})();

var _mdApiBase = <?= json_encode($sidebarBase) ?>;

function mdToggleTheme(){
  var html    = document.documentElement;
  var current = html.classList.contains('theme-light') ? 'light' : 'dark';
  var next    = current === 'dark' ? 'light' : 'dark';
  html.classList.remove('theme-' + current);
  html.classList.add('theme-' + next);
  localStorage.setItem('md_theme', next);
  document.querySelectorAll('.theme-toggle-btn').forEach(function(btn){
    btn.querySelector('.toggle-label').textContent = next === 'dark' ? 'Modo Claro' : 'Modo Escuro';
    btn.querySelector('.toggle-icon').textContent  = next === 'dark' ? '☀️' : '🌙';
  });
  fetch(_mdApiBase + 'api/set_theme.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({theme: next})
  }).catch(function(){});
}

// ── PUSH NOTIFICATIONS ───────────────────────────────────────
(function() {
    var VAPID_PUBLIC = 'BOD8saPXZbuLU_Tf1HvD-eNQekZBjmjcyuBeOhyd5Khld_THUGpP4adEgl_RD9q3YPr-oEDPezxS3buQk7Mippo';
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

    function urlB64ToUint8(b) {
        var pad = '='.repeat((4 - b.length % 4) % 4);
        var raw = window.atob((b + pad).replace(/-/g,'+').replace(/_/g,'/'));
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }
    function keyToBase64(key) {
        return btoa(String.fromCharCode.apply(null, new Uint8Array(key)));
    }

    async function saveSub(sub) {
        var k = sub.getKey('p256dh'), a = sub.getKey('auth');
        var r = await fetch(_mdApiBase + 'api/push_subscribe.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                action: 'subscribe',
                endpoint: sub.endpoint,
                keys: { p256dh: keyToBase64(k), auth: keyToBase64(a) }
            })
        });
        var d = await r.json().catch(function(){ return {}; });
        // Sessão expirou no iOS — esconde botão de push (não temos como subscrever sem sessão)
        if (d.error === 'not_logged_in') return false;
        return d.success;
    }

    async function registerPush() {
        try {
            var reg = await navigator.serviceWorker.register('/sw.js');
            await navigator.serviceWorker.ready;
            var existing = await reg.pushManager.getSubscription();

            if (existing) {
                // Verifica se ainda está válida no banco (iOS pode ter invalidado silenciosamente)
                var check = await fetch(_mdApiBase + 'api/push_subscribe.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ action: 'check', endpoint: existing.endpoint })
                });
                var cd = await check.json().catch(function(){ return {}; });

                if (cd.valid) {
                    // Tudo ok — esconde botão de ativar
                    var btn = document.getElementById('btnEnablePush');
                    if (btn) btn.style.display = 'none';
                    return;
                }
                // Banco não tem mais — re-envia a subscription existente
                await saveSub(existing);
                return;
            }

            // Sem subscription local — não pede permissão automaticamente
            // (o usuário clica em "Ativar notificações" na sidebar)
            // Mas se já tem permissão concedida, re-subscreve silenciosamente
            if (Notification.permission === 'granted') {
                var sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlB64ToUint8(VAPID_PUBLIC)
                });
                await saveSub(sub);
            }

        } catch(e) {}
    }

    // Roda na carga da página
    if (document.readyState === 'complete') registerPush();
    else window.addEventListener('load', registerPush);

    // Expõe globalmente para o botão "Ativar notificações" da sidebar
    window._mdRegisterPush = async function() {
        try {
            var reg  = await navigator.serviceWorker.register('/sw.js');
            await navigator.serviceWorker.ready;
            var perm = await Notification.requestPermission();
            if (perm !== 'granted') return false;
            var existing = await reg.pushManager.getSubscription();
            if (existing) await existing.unsubscribe();
            var sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlB64ToUint8(VAPID_PUBLIC)
            });
            return await saveSub(sub);
        } catch(e) { return false; }
    };
})();

// ── NOTIFICATION BADGE POLLING ────────────────────────────────
function mdUpdateNotifBadge() {
    fetch(_mdApiBase + 'api/events.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'get_notifications'})
    }).then(function(r){return r.json();}).then(function(d){
        var count = parseInt(d.unread || 0);
        var badge = document.getElementById('notif-badge');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }).catch(function(){});
}
// Polling a cada 60s (só se elemento existir)
document.addEventListener('DOMContentLoaded', function(){
    mdUpdateNotifBadge();
    setInterval(mdUpdateNotifBadge, 60000);
});

</script>
<?php
}