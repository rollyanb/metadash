<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
Auth::requireLogin();
if (!Auth::isAdmin()) { header('Location: index.php'); exit; } // área administrativa
$role = Auth::getRole();
if (!in_array($role, ['admin','chefe'])) { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notificações Push — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
.page-title{font-size:22px;font-weight:700;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--mt2);margin-bottom:22px}

.card{background:var(--sf);border:1px solid var(--bd);border-radius:13px;overflow:hidden;margin-bottom:16px}
.card-hd{padding:14px 20px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:13px;font-weight:700;color:var(--tx)}

.user-row{padding:13px 20px;border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:12px;transition:background .12s}
.user-row:last-child{border-bottom:none}
.user-row:hover{background:rgba(255,255,255,.02)}
.user-avatar{width:34px;height:34px;border-radius:9px;background:rgba(84,112,255,.15);color:var(--ac);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0}
.user-name{font-size:13px;font-weight:600;color:var(--tx)}
.user-role{font-size:11px;color:var(--mt2)}
.push-badge{font-size:11px;padding:2px 8px;border-radius:5px;font-weight:600}
.badge-on{background:rgba(62,207,142,.1);color:var(--gn)}
.badge-off{background:rgba(148,148,163,.1);color:var(--mt2)}

.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:7px;font-family:'Inter',sans-serif;font-weight:600;font-size:12px;cursor:pointer;border:none;transition:all .15s}
.btn-p{background:var(--ac);color:#fff}.btn-p:hover{opacity:.85}
.btn-o{background:var(--s2);border:1px solid var(--bd);color:var(--mt2)}.btn-o:hover{border-color:var(--ac);color:var(--ac)}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1000;align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal{background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:26px;width:100%;max-width:500px;max-height:86vh;overflow-y:auto;display:flex;flex-direction:column}
.modal-title{font-size:15px;font-weight:700;color:var(--tx);margin-bottom:3px}
.modal-sub{font-size:12px;color:var(--mt2);margin-bottom:14px}
.acc-list{display:flex;flex-direction:column;gap:5px;max-height:320px;overflow-y:auto;margin-bottom:14px}
.acc-item{display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--s2);border-radius:8px;border:1px solid var(--bd);cursor:pointer;transition:border-color .15s}
.acc-item.selected{border-color:rgba(84,112,255,.4);background:rgba(84,112,255,.06)}
.acc-item input{width:15px;height:15px;accent-color:var(--ac);cursor:pointer;flex-shrink:0}
.acc-name{font-size:13px;font-weight:500;color:var(--tx)}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;padding-top:12px;border-top:1px solid var(--bd)}

.toast{position:fixed;bottom:22px;right:22px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:11px 16px;font-size:13px;opacity:0;transition:opacity .3s;z-index:2000;pointer-events:none}
.toast.show{opacity:1}
</style>
</head>
<body>
<?php $currentPage='push_settings'; $sidebarBase=''; require __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
  <h1 class="page-title">🔔 Notificações Push</h1>
  <p class="page-sub">Defina quais contas cada usuário recebe notificação</p>

  <div class="card">
    <div class="card-hd">
      <div class="card-title">Usuários</div>
      <span style="font-size:11px;color:var(--mt2)">Clique em ✏️ para configurar</span>
    </div>
    <div id="usersBody" style="padding:20px;text-align:center;color:var(--mt2);font-size:13px">Carregando...</div>
  </div>
</main>

<!-- MODAL -->
<div class="modal-overlay" id="modal">
  <div class="modal">
    <div class="modal-title" id="modalTitle">Configurar notificações</div>
    <div class="modal-sub" id="modalSub"></div>
    <div class="acc-list" id="accGrid"></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-o" onclick="closeModal()">Cancelar</button>
      <button type="button" class="btn btn-p" onclick="savePrefs()">💾 Salvar</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
var ALL_ACCOUNTS = [], currentUserId = null;

async function api(action, body) {
  var r = await fetch('api/push_user_prefs.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({action:action},body||{}))});
  return r.json();
}

async function loadUsers() {
  var d = await api('list_all');
  var users = d.users || [];
  var ra = await api('get_accounts');
  ALL_ACCOUNTS = ra.accounts || [];
  var body = document.getElementById('usersBody');
  if (!users.length) { body.innerHTML='<div style="padding:20px;color:var(--mt2)">Nenhum usuário encontrado</div>'; return; }
  var rb={'admin':'⭐','chefe':'👔','financeiro':'💰','marketing':'🎨','user':'👤'};
  body.innerHTML = users.map(function(u) {
    var hasPush=u.has_push>0, count=parseInt(u.notify_count)||0;
    return '<div class="user-row">'+
      '<div class="user-avatar">'+esc(u.username[0].toUpperCase())+'</div>'+
      '<div style="flex:1">'+
        '<div class="user-name">'+(rb[u.role]||'👤')+' '+esc(u.username)+'</div>'+
        '<div class="user-role">'+esc(u.role)+(hasPush?' · Push ativo':' · Sem push')+'</div>'+
      '</div>'+
      '<span class="push-badge '+(count>0?'badge-on':'badge-off')+'">'+(count>0?count+' contas':'Nenhuma')+'</span>'+
      '<button type="button" class="btn btn-o" onclick="openModal('+u.id+',\''+esc(u.username)+'\')" style="margin-left:8px">✏️</button>'+
    '</div>';
  }).join('');
}

async function openModal(userId, username) {
  currentUserId = userId;
  document.getElementById('modalTitle').textContent = '🔔 Notificações de '+username;
  document.getElementById('modalSub').textContent = 'Selecione as contas que '+username+' vai receber notificação.';
  var d = await api('get', {user_id:userId});
  var selected = d.account_ids || [];
  var grid = document.getElementById('accGrid');
  if (!ALL_ACCOUNTS.length) {
    grid.innerHTML='<div style="color:var(--mt2);font-size:13px;padding:8px">Nenhuma conta disponível</div>';
  } else {
    grid.innerHTML = ALL_ACCOUNTS.map(function(a) {
      var isSel = selected.indexOf(a.account_id)>=0;
      return '<label class="acc-item'+(isSel?' selected':'')+'">'+
        '<input type="checkbox" value="'+esc(a.account_id)+'"'+(isSel?' checked':'')+' onchange="this.closest(\'.acc-item\').classList.toggle(\'selected\',this.checked)">'+
        '<span class="acc-name">'+esc(a.account_name)+'</span>'+
      '</label>';
    }).join('');
  }
  document.getElementById('modal').classList.add('open');
}

async function savePrefs() {
  var ids = Array.from(document.querySelectorAll('#accGrid input:checked')).map(function(c){return c.value;});
  await api('save', {user_id:currentUserId, account_ids:ids});
  closeModal();
  showToast('✅ Salvo!');
  loadUsers();
}

function closeModal(){ document.getElementById('modal').classList.remove('open'); }
document.getElementById('modal').addEventListener('click',function(e){if(e.target===this)closeModal();});

function showToast(msg){ var t=document.getElementById('toast');t.textContent=msg;t.classList.add('show');setTimeout(function(){t.classList.remove('show');},2500); }
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

loadUsers();
</script>
</body>
</html>