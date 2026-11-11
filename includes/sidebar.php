<?php
// includes/sidebar.php — MetaDash v2
if (!isset($currentPage)) $currentPage = '';
if (!isset($sidebarBase)) $sidebarBase = '';

$role        = $_SESSION['role'] ?? 'user';
$isAdmin     = ($role === 'admin');
$isChefe     = ($role === 'chefe');
$isFinanc    = ($role === 'financeiro');
$isMarketing = ($role === 'marketing');
$isAdminOrChefe = in_array($role, ['admin','chefe']);

$themeLabel = (getTheme() === 'dark') ? 'Modo Claro' : 'Modo Escuro';
$themeIcon  = (getTheme() === 'dark') ? '☀️' : '🌙';

// Contagem de notificações não lidas
$notifCount = 0;
try {
    if (!isset($db)) $db = Database::getInstance();
    $uid = $_SESSION['user_id'] ?? 0;
    $n   = $db->fetch("SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND read_at IS NULL", [$uid]);
    $notifCount = intval($n['c'] ?? 0);
} catch(Exception $e) {}

// ── SVGs 15×15 ────────────────────────────────────────────────
$icDash   = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="1.5" y="1.5" width="4.5" height="4.5" rx="1"/><rect x="9" y="1.5" width="4.5" height="4.5" rx="1"/><rect x="1.5" y="9" width="4.5" height="4.5" rx="1"/><rect x="9" y="9" width="4.5" height="4.5" rx="1"/></svg>';
$icResume = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><line x1="2.5" y1="4" x2="12.5" y2="4"/><line x1="2.5" y1="7.5" x2="12.5" y2="7.5"/><line x1="2.5" y1="11" x2="8.5" y2="11"/></svg>';
$icTrans  = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="1.5" y="3" width="12" height="9" rx="1.5"/><line x1="1.5" y1="6" x2="13.5" y2="6"/></svg>';
$icEvent  = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><rect x="1.5" y="2.5" width="12" height="11" rx="1.5"/><line x1="1.5" y1="5.5" x2="13.5" y2="5.5"/><line x1="4.5" y1="1" x2="4.5" y2="3.5"/><line x1="10.5" y1="1" x2="10.5" y2="3.5"/></svg>';
$icConv   = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><rect x="1.5" y="2" width="12" height="11" rx="1.5"/><path d="M3 10l2.5-3 2 2.5 2-2.5 2 2"/></svg>';
$icAccts  = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><circle cx="6" cy="4.5" r="2"/><path d="M2 12c0-2.21 1.79-4 4-4s4 1.79 4 4"/><circle cx="11.5" cy="5" r="1.5"/><path d="M13 12a2 2 0 0 0-2-2"/></svg>';
$icCreat  = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><rect x="1.5" y="1.5" width="12" height="12" rx="1.5"/><path d="M1.5 9.5l3-3 2.5 2.5 2-2 3 3"/><circle cx="10.5" cy="4.5" r="1"/></svg>';
$icReport = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M5 2H3a1.5 1.5 0 0 0-1.5 1.5v9A1.5 1.5 0 0 0 3 14h9a1.5 1.5 0 0 0 1.5-1.5V3.5A1.5 1.5 0 0 0 12 2h-2"/><rect x="5" y="1" width="5" height="2.5" rx=".75"/><line x1="4.5" y1="6.5" x2="10.5" y2="6.5"/><line x1="4.5" y1="9" x2="10.5" y2="9"/><line x1="4.5" y1="11.5" x2="7.5" y2="11.5"/></svg>';
$icCfg    = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><circle cx="7.5" cy="7.5" r="2"/><path d="M7.5 1.5v1M7.5 12.5v1M1.5 7.5h1M12.5 7.5h1M3.05 3.05l.71.71M11.24 11.24l.71.71M3.05 11.95l.71-.71M11.24 3.76l.71-.71"/></svg>';
$icImport = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M7.5 1.5v8M4.5 7l3 3 3-3"/><path d="M2.5 10.5v2a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-2"/></svg>';
$icTask   = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><rect x="2.5" y="2" width="10" height="11" rx="1.5"/><line x1="5" y1="5.5" x2="10" y2="5.5"/><line x1="5" y1="8" x2="10" y2="8"/><line x1="5" y1="10.5" x2="8" y2="10.5"/></svg>';
$icSync   = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M12.5 6.5a5 5 0 1 0 .3 2.5"/><path d="M12.8 3v3.5h-3.5"/></svg>';
$icUsers  = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><circle cx="7.5" cy="5" r="2.5"/><path d="M2.5 13a5 5 0 0 1 10 0"/></svg>';
$icCo     = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><rect x="2.5" y="2.5" width="10" height="10" rx="1"/><line x1="5.5" y1="5.5" x2="5.5" y2="5.5"/><line x1="9.5" y1="5.5" x2="9.5" y2="5.5"/><line x1="5.5" y1="9.5" x2="9.5" y2="9.5"/></svg>';
$icTik    = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M9 2h2.5a2.5 2.5 0 0 0 2.5 2.5v2a4.5 4.5 0 0 1-2.5-.76V10a4.5 4.5 0 1 1-4.5-4.5"/></svg>';
$icOut    = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2.5H3.5A1.5 1.5 0 0 0 2 4v7a1.5 1.5 0 0 0 1.5 1.5H6"/><path d="M9.5 10.5 12.5 7.5 9.5 4.5"/><line x1="12.5" y1="7.5" x2="6" y2="7.5"/></svg>';
$icBell   = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M7.5 1.5a4.5 4.5 0 0 1 4.5 4.5c0 3 1 4 1 4H2s1-1 1-4A4.5 4.5 0 0 1 7.5 1.5z"/><line x1="5.5" y1="12" x2="9.5" y2="12"/></svg>';
$icPush   = '<svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><circle cx="7.5" cy="7.5" r="6"/><line x1="7.5" y1="4.5" x2="7.5" y2="7.5"/><circle cx="7.5" cy="10" r=".5" fill="currentColor"/></svg>';

function navItem($label, $href, $icon, $active) {
    $cls = $active ? 'nav-item active' : 'nav-item';
    return '<a href="'.htmlspecialchars($href).'" class="'.$cls.'">'.$icon.'<span style="flex:1">'.htmlspecialchars($label).'</span></a>';
}
?>

<!-- MOBILE TOPBAR -->
<div class="mob-topbar">
  <button type="button" onclick="document.querySelector('.sidebar').classList.toggle('open')"
          style="background:none;border:none;color:var(--tx);cursor:pointer;padding:8px;border-radius:8px;">
    <svg width="18" height="18" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">
      <line x1="1.5" y1="4" x2="13.5" y2="4"/><line x1="1.5" y1="7.5" x2="13.5" y2="7.5"/>
      <line x1="1.5" y1="11" x2="13.5" y2="11"/>
    </svg>
  </button>
  <div class="mob-topbar-logo">
    <div class="sidebar-logo-icon">M</div>
    <span>Meta<span style="color:var(--ac)">Dash</span></span>
  </div>
  <!-- SINO MOBILE -->
  <button type="button" id="notif-btn-mobile" onclick="openNotifPanel()"
          style="background:none;border:none;color:var(--tx);cursor:pointer;padding:8px;border-radius:8px;position:relative;margin-left:auto">
    <?= $icBell ?>
    <span id="notif-badge-mobile" style="display:none;position:absolute;top:4px;right:4px;background:var(--rd);color:#fff;font-size:9px;font-weight:700;border-radius:10px;padding:1px 4px;min-width:16px;text-align:center"></span>
  </button>
</div>

<!-- SIDEBAR -->
<aside class="sidebar">
  <!-- Logo + sino desktop -->
  <div class="sidebar-logo" style="display:flex;align-items:center;justify-content:space-between">
    <div style="display:flex;align-items:center;gap:10px">
      <div class="sidebar-logo-icon">M</div>
      <span class="sidebar-logo-text">Meta<span>Dash</span></span>
    </div>
    <!-- SINO DESKTOP -->
    <button type="button" id="notif-btn-desk" onclick="openNotifPanel()"
            title="Notificações"
            style="background:none;border:none;color:var(--mt2);cursor:pointer;padding:6px;border-radius:8px;position:relative;transition:color .15s;flex-shrink:0">
      <?= $icBell ?>
      <span id="notif-badge" style="display:<?= $notifCount > 0 ? 'flex' : 'none' ?>;position:absolute;top:2px;right:2px;background:var(--rd);color:#fff;font-size:9px;font-weight:700;border-radius:10px;padding:1px 4px;min-width:16px;align-items:center;justify-content:center">
        <?= $notifCount > 0 ? ($notifCount > 99 ? '99+' : $notifCount) : '' ?>
      </span>
    </button>
  </div>

  <div class="nav-section">Menu</div>

  <?php if (!$isFinanc): ?>
  <?= navItem('Dashboard',    $sidebarBase.'index.php',       $icDash,   $currentPage==='index') ?>
  <?php endif; ?>

  <?php if ($isAdmin || $isChefe || $isFinanc): ?>
  <?= navItem('Transações',   $sidebarBase.'transactions.php',$icTrans,  $currentPage==='transactions') ?>
  <?php if ($isAdmin || $isFinanc || $isChefe): ?>
  <?= navItem('Reembolsos',   $sidebarBase.'refunds.php',    $icTrans,  $currentPage==='refunds') ?>
  <?php endif; ?>
  <?php endif; ?>

  <?= navItem('Eventos',      $sidebarBase.'events.php',      $icEvent,  $currentPage==='events') ?>
  <?php if ($isAdmin || $isChefe || $isMarketing): ?>
  <?= navItem('Estúdio Criativo', $sidebarBase.'estudio.php', $icCreat, $currentPage==='estudio') ?>
  <?php endif; ?>
  <?= navItem('Nova demanda', $sidebarBase.'nova-demanda.php',$icTask,   $currentPage==='nova_demanda') ?>
  <?= navItem('Negócios locais', $sidebarBase.'clientes.php', $icCo,     $currentPage==='clientes') ?>

  <?php if ($isAdmin): ?>
  <div class="nav-section">Admin</div>
  <?= navItem('Trajetória',      $sidebarBase.'trajetoria.php',      $icReport,$currentPage==='trajectory') ?>
  <?= navItem('Sincronizar',     $sidebarBase.'sync_manual.php',    $icSync,  $currentPage==='sync_manual') ?>
  <?= navItem('Importar Fatura', $sidebarBase.'import_invoice.php', $icImport,$currentPage==='import_invoice') ?>
  <?= navItem('Usuários',        $sidebarBase.'admin/users.php',    $icUsers, $currentPage==='users') ?>
  <?= navItem('Empresas',        $sidebarBase.'admin/companies.php',$icCo,    $currentPage==='companies') ?>
  <?= navItem('Importar TikTok', $sidebarBase.'tiktok_import.php',  $icTik,   $currentPage==='tiktok_import') ?>
  <?= navItem('Notif. Push',    $sidebarBase.'push_settings.php',  $icPush,  $currentPage==='push_settings') ?>
  <?= navItem('Teste Push',     $sidebarBase.'push_test.php',      $icPush,  $currentPage==='push_test') ?>
  <?= navItem('Transações pagas',$sidebarBase.'paid_transactions.php',$icTrans,$currentPage==='paid_transactions') ?>
  <?= navItem('Relatórios (contas)',$sidebarBase.'report_tokens.php',$icReport,$currentPage==='report_tokens') ?>
  <?= navItem('Contas',          $sidebarBase.'accounts.php',      $icAccts, $currentPage==='accounts') ?>
  <?= navItem('Log de acessos',  $sidebarBase.'access_log.php',    $icUsers, $currentPage==='access_log') ?>
  <?= navItem('Configurações',   $sidebarBase.'settings.php',      $icCfg,   $currentPage==='settings') ?>
  <?php endif; ?>

  <div class="sidebar-footer">
    <!-- Botão ativar push -->
    <button type="button" id="btnEnablePush" onclick="mdEnablePush()" class="nav-item"
            style="display:none;background:rgba(62,207,142,.08);border:1px solid rgba(62,207,142,.2);color:var(--gn);width:100%;text-align:left;cursor:pointer;margin-bottom:4px">
      <?= $icPush ?> <span>Ativar notificações</span>
    </button>

    <button type="button" class="nav-item theme-toggle-btn" onclick="mdToggleTheme()">
      <span class="toggle-icon"><?= $themeIcon ?></span>
      <span class="toggle-label"><?= $themeLabel ?></span>
    </button>
    <a href="<?= $sidebarBase ?>logout.php" class="nav-item">
      <?= $icOut ?> Sair
    </a>
  </div>
</aside>

<!-- PAINEL DE NOTIFICAÇÕES -->
<div id="notifPanel" style="display:none;position:fixed;top:0;right:0;width:340px;max-width:100vw;height:100vh;background:var(--sf);border-left:1px solid var(--bd);z-index:900;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .25s">
  <div style="padding:16px 18px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
    <div style="font-size:14px;font-weight:700;color:var(--tx)">🔔 Notificações</div>
    <div style="display:flex;gap:8px;align-items:center">
      <button type="button" onclick="markAllRead()" style="background:none;border:none;font-size:11px;color:var(--mt2);cursor:pointer;padding:4px 8px;border-radius:6px;transition:color .15s" title="Marcar tudo como lido">✓ Tudo lido</button>
      <button type="button" onclick="closeNotifPanel()" style="background:none;border:none;color:var(--mt);cursor:pointer;font-size:16px;padding:4px 8px;border-radius:6px;line-height:1">✕</button>
    </div>
  </div>
  <div id="notifList" style="overflow-y:auto;flex:1;padding:8px"></div>
</div>
<div id="notifOverlay" onclick="closeNotifPanel()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:899"></div>

<script>
// ── FECHAR SIDEBAR MOBILE ─────────────────────────────────────
document.addEventListener('click', function(e) {
  var sb = document.querySelector('.sidebar');
  if (!sb) return;
  if (sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('.mob-topbar')) {
    sb.classList.remove('open');
  }
});

// ── PUSH — mostrar botão se não inscrito ──────────────────────
(function checkPushStatus() {
  if (!('serviceWorker' in navigator)) return;

  function showPushBtn(label) {
    var btn = document.getElementById('btnEnablePush');
    if (!btn) return;
    btn.querySelector('span').textContent = label;
    btn.style.display = 'flex';
  }

  // Safari iOS sem PWA: PushManager não existe
  if (!('PushManager' in window)) {
    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    if (isIOS) showPushBtn('Adicionar à tela inicial para notificações');
    return;
  }

  // Se acabou de ativar com sucesso nesta sessão, não mostra o botão
  if (sessionStorage.getItem('pushActivated') === '1') return;

  navigator.serviceWorker.getRegistrations().then(function(regs) {
    if (!regs.length) { showPushBtn('Ativar notificações'); return; }
    regs[0].pushManager.getSubscription().then(function(sub) {
      if (!sub) { showPushBtn('Ativar notificações'); return; }
      // Tem subscription no browser — verifica servidor
      fetch('/api/push_subscribe.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'include',
        body: JSON.stringify({action: 'check', endpoint: sub.endpoint})
      }).then(function(r){ return r.json(); }).then(function(d){
        // Válido no servidor: mostra como "reconfigurar" caso esteja com problemas
        // Inválido: mostra "reativar"
        showPushBtn(d.valid ? 'Reconfigurar notificações' : 'Reativar notificações');
      }).catch(function(){
        // Erro no check: mostra botão por precaução
        showPushBtn('Ativar notificações');
      });
    }).catch(function(){ showPushBtn('Ativar notificações'); });
  }).catch(function(){ showPushBtn('Ativar notificações'); });
})();

async function mdEnablePush() {
  var btn = document.getElementById('btnEnablePush');
  btn.querySelector('span').textContent = 'Ativando...';
  btn.disabled = true;
  try {
    if ('serviceWorker' in navigator && 'PushManager' in window) {
      try {
        var regs = await navigator.serviceWorker.getRegistrations();
        if (regs.length) {
          var old = await regs[0].pushManager.getSubscription();
          if (old) await old.unsubscribe();
        }
      } catch(e2) {}
    }
    var ok = await window._mdRegisterPush();
    if (ok) {
      sessionStorage.setItem('pushActivated', '1');
      btn.style.display = 'none';
    } else if (Notification.permission === 'denied') {
      btn.querySelector('span').textContent = '🚫 Bloqueado — libere nas configurações';
      btn.disabled = false;
    } else {
      btn.querySelector('span').textContent = 'Ativar notificações';
      btn.disabled = false;
    }
  } catch(e) {
    btn.querySelector('span').textContent = 'Erro: ' + e.message;
    btn.disabled = false;
  }
}

// ── PAINEL DE NOTIFICAÇÕES ────────────────────────────────────
var notifPanelOpen = false;

function openNotifPanel() {
  var panel   = document.getElementById('notifPanel');
  var overlay = document.getElementById('notifOverlay');
  panel.style.display   = 'flex';
  overlay.style.display = 'block';
  setTimeout(function(){ panel.style.transform = 'translateX(0)'; }, 10);
  notifPanelOpen = true;
  loadNotifs();
}

function closeNotifPanel() {
  var panel   = document.getElementById('notifPanel');
  var overlay = document.getElementById('notifOverlay');
  panel.style.transform = 'translateX(100%)';
  setTimeout(function(){
    if (!notifPanelOpen) { panel.style.display='none'; overlay.style.display='none'; }
  }, 260);
  notifPanelOpen = false;
}

async function loadNotifs() {
  var list = document.getElementById('notifList');
  list.innerHTML = '<div style="padding:24px;text-align:center;color:var(--mt2);font-size:13px">Carregando...</div>';
  try {
    var r = await fetch(_mdApiBase + 'api/events.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_notifications'})
    });
    var d = await r.json();
    var notifs = d.notifications || [];
    updateBadge(parseInt(d.unread||0));
    if (!notifs.length) {
      list.innerHTML = '<div style="padding:32px;text-align:center;color:var(--mt);font-size:13px">Nenhuma notificação</div>';
      return;
    }
    list.innerHTML = notifs.map(function(n) {
      var isUnread = !n.read_at;
      var date = n.created_at ? new Date(n.created_at.replace(' ','T')).toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}) : '';
      return '<div onclick="readNotif('+n.id+',this,\''+escAttr(n.link||'')+'\')" style="padding:12px 14px;border-bottom:1px solid var(--bd);cursor:pointer;transition:background .12s;background:'+(isUnread?'rgba(84,112,255,.06)':'transparent')+'" onmouseover="this.style.background=\'rgba(255,255,255,.03)\'" onmouseout="this.style.background=\''+(isUnread?'rgba(84,112,255,.06)':'transparent')+'\'">' +
        (isUnread?'<div style="width:6px;height:6px;background:var(--ac);border-radius:50%;float:right;margin-top:4px"></div>':'')+
        '<div style="font-size:13px;font-weight:'+(isUnread?'600':'400')+';color:var(--tx);margin-bottom:3px">'+esc(n.title)+'</div>'+
        '<div style="font-size:12px;color:var(--mt2);line-height:1.5">'+esc(n.message)+'</div>'+
        '<div style="font-size:10px;color:var(--mt);margin-top:5px">'+date+'</div>'+
      '</div>';
    }).join('');
  } catch(e) {
    list.innerHTML = '<div style="padding:24px;color:var(--rd);font-size:13px">Erro ao carregar</div>';
  }
}

async function readNotif(id, el, link) {
  await fetch(_mdApiBase + 'api/events.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'mark_notification_read', id:id})
  }).catch(function(){});
  el.style.background = 'transparent';
  var dot = el.querySelector('[style*="border-radius:50%"]');
  if (dot) dot.remove();
  mdUpdateNotifBadge();
  if (link) { closeNotifPanel(); window.location.href = _mdApiBase + link; }
}

async function markAllRead() {
  await fetch(_mdApiBase + 'api/events.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'mark_notification_read', all:true})
  }).catch(function(){});
  updateBadge(0);
  loadNotifs();
}

function updateBadge(count) {
  var badge = document.getElementById('notif-badge');
  var badgeM = document.getElementById('notif-badge-mobile');
  [badge, badgeM].forEach(function(b) {
    if (!b) return;
    if (count > 0) {
      b.textContent = count > 99 ? '99+' : count;
      b.style.display = 'flex';
    } else {
      b.style.display = 'none';
    }
  });
}

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escAttr(s){ return String(s||'').replace(/'/g,"\\'"); }
</script>