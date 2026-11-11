<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/theme.php';
require_once __DIR__ . '/../config/database.php';

Auth::requireAdmin();

$db      = Database::getInstance();
$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'create') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role     = $_POST['role'] ?? 'user';
        if ($username && $password) {
            $exists = $db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
            if ($exists) {
                $message = 'Usuário já existe.'; $msgType = 'error';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $db->insert("INSERT INTO users (username, password, role) VALUES (?, ?, ?)", [$username, $hash, $role]);
                $message = "Usuário '{$username}' criado!"; $msgType = 'success';
            }
        } else {
            $message = 'Preencha todos os campos.'; $msgType = 'error';
        }
    }

    if ($_POST['action'] === 'delete') {
        $uid = intval($_POST['user_id']);
        if ($uid !== Auth::getUserId()) {
            $db->execute("DELETE FROM users WHERE id = ?", [$uid]);
            $message = 'Usuário removido.'; $msgType = 'success';
        } else {
            $message = 'Você não pode remover seu próprio usuário.'; $msgType = 'error';
        }
    }

    if ($_POST['action'] === 'save_permissions') {
        $uid      = intval($_POST['user_id']);
        $accounts = $_POST['accounts'] ?? [];
        $db->execute("DELETE FROM user_account_permissions WHERE user_id = ?", [$uid]);
        foreach ($accounts as $accountId) {
            $db->insert("INSERT IGNORE INTO user_account_permissions (user_id, account_id) VALUES (?, ?)", [$uid, $accountId]);
        }
        $message = 'Permissões salvas!'; $msgType = 'success';
    }

    if ($_POST['action'] === 'change_role') {
        $uid  = intval($_POST['user_id']);
        $role = $_POST['new_role'] ?? 'user';
        $allowed = array('admin','chefe','financeiro','marketing','user');
        if (in_array($role, $allowed) && $uid !== Auth::getUserId()) {
            $db->execute("UPDATE users SET role = ? WHERE id = ?", [$role, $uid]);
            $message = 'Cargo atualizado!'; $msgType = 'success';
        }
    }

    if ($_POST['action'] === 'change_password') {
        $uid      = intval($_POST['user_id']);
        $password = trim($_POST['new_password'] ?? '');
        if ($password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->execute("UPDATE users SET password = ? WHERE id = ?", [$hash, $uid]);
            $message = 'Senha alterada!'; $msgType = 'success';
        }
    }
}

$users             = $db->fetchAll("SELECT * FROM users ORDER BY role DESC, username");
$allMetaAccounts   = $db->fetchAll("SELECT account_id, account_name, card_display FROM ad_accounts ORDER BY account_name");
try {
    $allTiktokAccounts = $db->fetchAll("SELECT account_id, name FROM tiktok_accounts ORDER BY name");
} catch (Exception $e) {
    $allTiktokAccounts = [];
}

$permissions = [];
foreach ($users as $u) {
    $rows = $db->fetchAll("SELECT account_id FROM user_account_permissions WHERE user_id = ?", [$u['id']]);
    $permissions[$u['id']] = array_column($rows, 'account_id');
}

$allAccountsCombined = [];
foreach ($allMetaAccounts as $a) {
    $allAccountsCombined[] = ['id'=>$a['account_id'], 'name'=>$a['account_name'], 'card'=>$a['card_display']??'', 'platform'=>'meta'];
}
foreach ($allTiktokAccounts as $a) {
    $allAccountsCombined[] = ['id'=>$a['account_id'], 'name'=>$a['name'], 'card'=>'', 'platform'=>'tiktok'];
}

$permsJson    = json_encode($permissions, JSON_UNESCAPED_UNICODE);
$accountsJson = json_encode($allAccountsCombined, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Usuários — MetaDash</title>
<?php echoThemeAssets('../'); ?>
<style>
.page-title{font-family:'Inter',sans-serif;font-size:22px;font-weight:700;margin-bottom:4px}
.page-sub{color:var(--mt2);font-size:13px;margin-bottom:24px}
.alert{border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px}
.alert-success{background:rgba(62,207,142,.08);border:1px solid rgba(62,207,142,.2);color:var(--gn)}
.alert-error{background:rgba(240,100,90,.08);border:1px solid rgba(240,100,90,.2);color:var(--rd)}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px}
.card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:22px}
.card-title{font-size:14px;font-weight:600;margin-bottom:16px;color:var(--tx)}
label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:var(--mt);margin-bottom:5px}
input[type=text],input[type=password],select{width:100%;padding:10px 13px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-family:'Inter',sans-serif;font-size:13px;outline:none;transition:border-color .2s;margin-bottom:13px}
input:focus,select:focus{border-color:var(--ac)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:8px;font-family:'Inter',sans-serif;font-weight:600;font-size:13px;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-p{background:var(--ac);color:#fff}
.btn-p:hover{opacity:.85}
.btn-d{background:rgba(240,100,90,.1);border:1px solid rgba(240,100,90,.2);color:var(--rd)}
.btn-d:hover{background:rgba(240,100,90,.2)}
.btn-sm{padding:5px 11px;font-size:12px}
.users-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;overflow:hidden;margin-bottom:22px}
.users-header{padding:18px 22px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between}
table{width:100%;border-collapse:collapse}
thead th{padding:10px 18px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--mt);background:var(--s2);border-bottom:1px solid var(--bd)}
tbody tr{border-bottom:1px solid var(--bd);transition:background .15s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:rgba(255,255,255,.02)}
td{padding:13px 18px;font-size:13px;vertical-align:middle}
.role-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:5px;font-size:11px;font-weight:600}
.role-admin{background:rgba(245,166,35,.1);color:var(--yw)}
.role-chefe{background:rgba(251,146,60,.1);color:#fb923c}
.role-financeiro{background:rgba(62,207,142,.1);color:var(--gn)}
.role-marketing{background:rgba(130,110,255,.1);color:#826eff}
.role-user{background:rgba(84,112,255,.1);color:var(--ac)}
/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:26px;width:100%;max-width:560px;max-height:85vh;display:flex;flex-direction:column;position:relative}
.modal-title{font-size:16px;font-weight:700;margin-bottom:3px}
.modal-sub{color:var(--mt2);font-size:12px;margin-bottom:14px}
.modal-close{position:absolute;top:14px;right:14px;background:none;border:none;color:var(--mt);font-size:18px;cursor:pointer;padding:4px 7px;border-radius:6px;line-height:1}
.modal-close:hover{color:var(--tx);background:var(--s2)}
.search-wrap{position:relative;margin-bottom:8px}
.search-wrap input{width:100%;padding:9px 13px 9px 34px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-size:13px;outline:none;margin:0}
.search-wrap input:focus{border-color:var(--ac)}
.search-wrap .ico{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--mt2);font-size:13px;pointer-events:none}
.search-count{font-size:11px;color:var(--mt2);margin-bottom:7px}
.select-all-row{display:flex;align-items:center;gap:8px;margin-bottom:7px;padding:7px 13px;background:rgba(84,112,255,.05);border-radius:7px;cursor:pointer}
.select-all-row input{width:15px;height:15px;accent-color:var(--ac);margin:0;cursor:pointer}
.select-all-row span{font-size:12px;color:var(--mt2)}
.accounts-scroll{overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:5px}
.acc-item{display:flex;align-items:center;gap:11px;padding:9px 13px;background:var(--s2);border:1px solid var(--bd);border-radius:9px;cursor:pointer;transition:border-color .15s}
.acc-item:hover{border-color:var(--ac)}
.acc-item.checked{border-color:rgba(84,112,255,.4);background:rgba(84,112,255,.05)}
.acc-item input{width:15px;height:15px;accent-color:var(--ac);cursor:pointer;flex-shrink:0;margin:0}
.acc-item-name{font-size:12px;font-weight:500;flex:1}
.acc-item-card{font-size:11px;color:var(--ac)}
.acc-item.hidden{display:none}
.modal-footer{display:flex;gap:9px;margin-top:14px;padding-top:14px;border-top:1px solid var(--bd);flex-shrink:0}
.modal-pw{max-width:360px}
@media(max-width:768px){.grid-2{grid-template-columns:1fr}}
</style>
</head>
<body>

<?php
$currentPage = 'users';
$sidebarBase = '../';
require __DIR__ . '/../includes/sidebar.php';
?>

<main class="main">
  <h1 class="page-title">👥 Usuários</h1>
  <p class="page-sub">Gerencie acessos e permissões por conta de anúncio</p>

  <?php if ($message): ?>
  <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="grid-2">
    <div class="card">
      <div class="card-title">➕ Novo usuário</div>
      <form method="POST" action=""><?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="create">
        <label>Nome de usuário</label>
        <input type="text" name="username" placeholder="ex: joao" required autocomplete="off">
        <label>Senha</label>
        <input type="password" name="password" placeholder="••••••••" required autocomplete="new-password">
        <label>Nível de acesso</label>
        <select name="role">
          <option value="user">👤 Usuário</option>
          <option value="chefe">👔 Chefe</option>
          <option value="financeiro">💰 Financeiro</option>
          <option value="marketing">🎨 Marketing</option>
          <option value="admin">⭐ Administrador</option>
        </select>
        <button type="submit" class="btn btn-p" style="width:100%">✅ Criar usuário</button>
      </form>
    </div>
    <div class="card" style="display:flex;flex-direction:column;gap:13px">
      <div class="card-title">ℹ️ Como funciona</div>
      <div style="font-size:12px;color:var(--mt2);line-height:1.9">
        <p>⭐ <strong style="color:var(--tx)">Admin</strong> — acesso total ao sistema.</p>
        <p>👔 <strong style="color:var(--tx)">Chefe</strong> — tudo exceto Configurações. Contas por permissão.</p>
        <p>💰 <strong style="color:var(--tx)">Financeiro</strong> — apenas Transações das contas permitidas.</p>
        <p>🎨 <strong style="color:var(--tx)">Marketing</strong> — apenas Criativos das contas permitidas.</p>
        <p>👤 <strong style="color:var(--tx)">Usuário</strong> — acesso básico.</p>
        <p style="margin-top:7px">🎛️ Clique em <strong style="color:var(--ac)">Permissões</strong> para definir as contas visíveis.</p>
      </div>
    </div>
  </div>

  <div class="users-card">
    <div class="users-header">
      <div style="font-size:14px;font-weight:600">Usuários cadastrados</div>
      <span style="font-size:11px;color:var(--mt)"><?= count($users) ?> usuário(s)</span>
    </div>
    <div><table>
      <thead><tr><th>Usuário</th><th>Nível</th><th>Contas</th><th>Ações</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <?php $isMe = $u['id'] == Auth::getUserId(); $permCount = count($permissions[$u['id']] ?? []); ?>
        <tr>
          <td>
            <div style="font-weight:600"><?= htmlspecialchars($u['username']) ?></div>
            <?php if ($isMe): ?><div style="font-size:11px;color:var(--mt)">você</div><?php endif; ?>
          </td>
          <td>
            <?php
            $roleBadges = array(
              'admin'      => array('role-admin',      '⭐ Admin'),
              'chefe'      => array('role-chefe',      '👔 Chefe'),
              'financeiro' => array('role-financeiro', '💰 Financeiro'),
              'marketing'  => array('role-marketing',  '🎨 Marketing'),
              'user'       => array('role-user',       '👤 Usuário'),
            );
            $rb = $roleBadges[$u['role']] ?? array('role-user','👤 Usuário');
            ?>
            <span class="role-badge <?= $rb[0] ?>"><?= $rb[1] ?></span>
          </td>
          <td>
            <?php if ($u['role'] === 'admin'): ?>
              <span style="color:var(--gn);font-size:12px">✅ Todas</span>
            <?php elseif ($permCount === 0): ?>
              <span style="color:var(--rd);font-size:12px">⚠️ Nenhuma</span>
            <?php else: ?>
              <span style="color:var(--mt2);font-size:12px"><?= $permCount ?> conta(s)</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:7px;flex-wrap:wrap">
              <?php if ($u['role'] !== 'admin'): ?>
              <button type="button" class="btn btn-sm btn-p" onclick="openPermissions(<?= $u['id'] ?>)">🎛️ Permissões</button>
              <?php endif; ?>
              <button type="button" class="btn btn-sm" style="background:var(--s2);color:var(--mt2);border:1px solid var(--bd)"
                onclick="openPassword(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">🔑 Senha</button>
              <?php if (!$isMe): ?>
              <button type="button" class="btn btn-sm" style="background:rgba(130,110,255,.08);color:#826eff;border:1px solid rgba(130,110,255,.2)"
                onclick="openRole(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>', '<?= $u['role'] ?>')">🏷️ Cargo</button>
              <form method="POST" action="" onsubmit="return confirm('Remover <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?')" style="display:inline">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-d">🗑️</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</main>

<!-- MODAL PERMISSÕES -->
<div class="modal-overlay" id="permModal">
  <div class="modal">
    <button type="button" class="modal-close" onclick="closeModal('permModal')">✕</button>
    <div class="modal-title" id="permTitle">Permissões</div>
    <div class="modal-sub">Selecione as contas que este usuário pode visualizar</div>

    <form method="POST" action="" id="permForm" style="display:flex;flex-direction:column;flex:1;min-height:0"><?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="save_permissions">
      <input type="hidden" name="user_id" id="permUserId">

      <div class="search-wrap">
        <span class="ico">🔍</span>
        <input type="text" id="accountSearch" placeholder="Buscar conta por nome ou ID..." oninput="filterAccounts(this.value)">
      </div>
      <div class="search-count" id="searchCount"></div>

      <div class="select-all-row" onclick="toggleAll()">
        <input type="checkbox" id="selectAll" onclick="event.stopPropagation()">
        <span>Selecionar todas as contas visíveis</span>
      </div>

      <div class="accounts-scroll" id="accountsList"></div>

      <div class="modal-footer">
        <button type="submit" class="btn btn-p">💾 Salvar permissões</button>
        <button type="button" class="btn" style="background:var(--s2);color:var(--mt2);border:1px solid var(--bd)" onclick="closeModal('permModal')">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL CARGO -->
<div class="modal-overlay" id="roleModal">
  <div class="modal modal-pw">
    <button type="button" class="modal-close" onclick="closeModal('roleModal')">✕</button>
    <div class="modal-title" id="roleTitle">Alterar cargo</div>
    <div class="modal-sub">Selecione o nível de acesso do usuário</div>
    <form method="POST" action=""><?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="change_role">
      <input type="hidden" name="user_id" id="roleUserId">
      <label>Cargo</label>
      <select name="new_role" id="roleSelect">
        <option value="user">👤 Usuário</option>
        <option value="financeiro">💰 Financeiro</option>
        <option value="marketing">🎨 Marketing</option>
        <option value="chefe">👔 Chefe</option>
        <option value="admin">⭐ Administrador</option>
      </select>
      <div class="modal-footer">
        <button type="submit" class="btn btn-p">💾 Salvar</button>
        <button type="button" class="btn" style="background:var(--s2);color:var(--mt2);border:1px solid var(--bd)" onclick="closeModal('roleModal')">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL SENHA -->
<div class="modal-overlay" id="pwModal">
  <div class="modal modal-pw">
    <button type="button" class="modal-close" onclick="closeModal('pwModal')">✕</button>
    <div class="modal-title" id="pwTitle">Alterar senha</div>
    <div class="modal-sub">Nova senha para o usuário</div>
    <form method="POST" action=""><?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="user_id" id="pwUserId">
      <label>Nova senha</label>
      <input type="password" name="new_password" placeholder="••••••••" required autocomplete="new-password">
      <div class="modal-footer">
        <button type="submit" class="btn btn-p">💾 Salvar</button>
        <button type="button" class="btn" style="background:var(--s2);color:var(--mt2);border:1px solid var(--bd)" onclick="closeModal('pwModal')">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<script>
var ALL_ACCOUNTS  = <?= $accountsJson ?>;
var ALL_PERMS     = <?= $permsJson ?>;
var currentUserId = null;

function openPermissions(userId) {
  currentUserId = userId;
  var row = document.querySelector('[onclick="openPermissions(' + userId + ')"]');
  var username = row ? row.closest('tr').querySelector('td div').textContent.trim() : userId;
  document.getElementById('permTitle').textContent = '🎛️ Permissões — ' + username;
  document.getElementById('permUserId').value = userId;
  document.getElementById('accountSearch').value = '';
  renderAccounts('');
  document.getElementById('permModal').classList.add('open');
  setTimeout(function(){ document.getElementById('accountSearch').focus(); }, 100);
}

function renderAccounts(filter) {
  var perms  = ALL_PERMS[currentUserId] || [];
  var list   = document.getElementById('accountsList');
  var search = filter.toLowerCase().trim();
  var visible = 0;

  list.innerHTML = '';

  ALL_ACCOUNTS.forEach(function(acc) {
    var matchName = acc.name.toLowerCase().includes(search);
    var matchId   = acc.id.toLowerCase().includes(search);
    var hidden    = search && !matchName && !matchId;
    var checked   = perms.includes(acc.id);

    var lbl = document.createElement('label');
    lbl.className = 'acc-item' + (checked ? ' checked' : '') + (hidden ? ' hidden' : '');

    var platIcon  = acc.platform === 'tiktok' ? '🎵' : '📘';
    var platColor = acc.platform === 'tiktok' ? '#00c4cc' : '#1877f2';
    var platBg    = acc.platform === 'tiktok' ? 'rgba(0,196,204,.12)' : 'rgba(24,119,242,.12)';
    var platLabel = acc.platform === 'tiktok' ? 'TikTok' : 'Meta';

    lbl.innerHTML =
      '<input type="checkbox" name="accounts[]" value="' + esc(acc.id) + '"' + (checked ? ' checked' : '') + '>' +
      '<div style="flex:1">' +
        '<div class="acc-item-name">' +
          '<span style="font-size:10px;background:' + platBg + ';color:' + platColor + ';padding:1px 5px;border-radius:4px;margin-right:5px;font-weight:700">' + platIcon + ' ' + platLabel + '</span>' +
          esc(acc.name) +
        '</div>' +
        (acc.card ? '<div class="acc-item-card">💳 ' + esc(acc.card) + '</div>' : '') +
        '<div style="font-size:10px;color:var(--mt);font-family:\'JetBrains Mono\',monospace">' + esc(acc.id) + '</div>' +
      '</div>';

    lbl.querySelector('input').addEventListener('change', function() {
      lbl.classList.toggle('checked', this.checked);
      var perms2 = ALL_PERMS[currentUserId] || [];
      if (this.checked) {
        if (!perms2.includes(acc.id)) perms2.push(acc.id);
      } else {
        perms2 = perms2.filter(function(x){ return x !== acc.id; });
      }
      ALL_PERMS[currentUserId] = perms2;
      updateSelectAll();
    });

    list.appendChild(lbl);
    if (!hidden) visible++;
  });

  var total = ALL_ACCOUNTS.length;
  document.getElementById('searchCount').textContent =
    search ? (visible + ' de ' + total + ' contas') : (total + ' contas no total');
  updateSelectAll();
}

function filterAccounts(val) {
  var perms = ALL_PERMS[currentUserId] || [];
  document.querySelectorAll('#accountsList input[type=checkbox]').forEach(function(cb) {
    var id = cb.value;
    if (cb.checked && !perms.includes(id)) perms.push(id);
    else if (!cb.checked) perms = perms.filter(function(x){ return x !== id; });
  });
  ALL_PERMS[currentUserId] = perms;
  renderAccounts(val);
}

function toggleAll() {
  var cbs = document.querySelectorAll('#accountsList .acc-item:not(.hidden) input');
  var allChecked = Array.from(cbs).every(function(cb){ return cb.checked; });
  cbs.forEach(function(cb) {
    cb.checked = !allChecked;
    cb.closest('.acc-item').classList.toggle('checked', !allChecked);
    var perms = ALL_PERMS[currentUserId] || [];
    if (!allChecked) {
      if (!perms.includes(cb.value)) perms.push(cb.value);
    } else {
      perms = perms.filter(function(x){ return x !== cb.value; });
    }
    ALL_PERMS[currentUserId] = perms;
  });
  updateSelectAll();
}

function updateSelectAll() {
  var cbs = document.querySelectorAll('#accountsList .acc-item:not(.hidden) input');
  if (cbs.length === 0) { document.getElementById('selectAll').checked = false; return; }
  document.getElementById('selectAll').checked = Array.from(cbs).every(function(cb){ return cb.checked; });
}

function openRole(userId, username, currentRole) {
  document.getElementById('roleUserId').value = userId;
  document.getElementById('roleTitle').textContent = '🏷️ Cargo — ' + username;
  document.getElementById('roleSelect').value = currentRole;
  document.getElementById('roleModal').classList.add('open');
}

function openPassword(userId, username) {
  document.getElementById('pwUserId').value = userId;
  document.getElementById('pwTitle').textContent = '🔑 Senha — ' + username;
  document.getElementById('pwModal').classList.add('open');
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.querySelectorAll('.modal-overlay').forEach(function(o) {
  o.addEventListener('click', function(e){ if (e.target === o) closeModal(o.id); });
});

document.getElementById('accountSearch').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') e.preventDefault();
});
</script>
</body>
</html>