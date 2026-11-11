<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();
$role = Auth::getRole();
if (!in_array($role, ['admin','chefe','financeiro','marketing'])) {
    header('Location: index.php'); exit;
}

$isAdmin     = ($role === 'admin');
$isChefe     = ($role === 'chefe');
$canCreate   = ($role === 'admin' || $role === 'chefe');
$db          = Database::getInstance();
$allowedAccounts = Auth::getAllowedAccounts();

if ($isAdmin) {
    $accounts = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE status IN ('ACTIVE','INACTIVE') ORDER BY account_name");
} elseif (!empty($allowedAccounts)) {
    $ph = implode(',', array_fill(0, count($allowedAccounts), '?'));
    $accounts = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE account_id IN ({$ph}) AND status IN ('ACTIVE','INACTIVE') ORDER BY account_name", $allowedAccounts);
} else {
    $accounts = [];
}

if ($isAdmin) {
    $tokens = $db->fetchAll("SELECT t.*, a.account_name FROM report_tokens t LEFT JOIN ad_accounts a ON a.account_id = t.account_id ORDER BY t.created_at DESC");
} elseif ($allowedAccounts !== null && !empty($allowedAccounts)) {
    $ph = implode(',', array_fill(0, count($allowedAccounts), '?'));
    $tokens = $db->fetchAll("SELECT t.*, a.account_name FROM report_tokens t LEFT JOIN ad_accounts a ON a.account_id = t.account_id WHERE t.account_id IN ({$ph}) ORDER BY t.created_at DESC", $allowedAccounts);
} else {
    $tokens = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Links de Relatório — Meta Ads</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{max-width:100%;overflow-x:hidden}
:root{--ac:#4f6ef7;--ac2:#a78bfa;--gn:#34d399;--rd:#f87171;--yw:#fbbf24;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--tx);min-height:100vh;display:flex}

/* sidebar desktop */
.sidebar{width:240px;min-height:100vh;background:var(--sf);border-right:1px solid var(--bd);display:flex;flex-direction:column;padding:24px 16px;position:fixed;top:0;left:0;bottom:0}
.logo{display:flex;align-items:center;gap:10px;padding:8px 12px 28px;border-bottom:1px solid var(--bd);margin-bottom:20px}
.logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--ac),var(--ac2));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px}
.logo-text{font-family:'Syne',sans-serif;font-weight:800;font-size:16px}.logo-text span{color:var(--ac)}
.nav-label{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--mt);padding:0 12px;margin-bottom:8px;margin-top:16px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:var(--mt2);text-decoration:none;font-size:14px;font-weight:500;transition:all .15s;margin-bottom:2px}
.nav-item:hover,.nav-item.active{background:rgba(79,110,247,.12);color:var(--tx)}.nav-item.active{color:var(--ac)}
.sidebar-footer{margin-top:auto;padding-top:20px;border-top:1px solid var(--bd)}
.logout-link{display:block;text-align:center;color:var(--mt);font-size:12px;text-decoration:none;padding:6px}
.logout-link:hover{color:var(--rd)}

.main{margin-left:240px;flex:1;padding:32px}
h1{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;margin-bottom:4px}
.sub{color:var(--mt2);font-size:14px;margin-bottom:24px}
.card{background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:24px;margin-bottom:20px}
.ct{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;margin-bottom:18px}
label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt);margin-bottom:6px}
select,input[type=text]{width:100%;padding:11px 14px;background:var(--s2);border:1px solid var(--bd);border-radius:10px;color:var(--tx);font-family:'DM Sans',sans-serif;font-size:14px;outline:none;margin-bottom:14px}
select:focus,input:focus{border-color:var(--ac)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border-radius:8px;font-family:'Syne',sans-serif;font-weight:700;font-size:13px;cursor:pointer;border:none;transition:all .15s}
.btn-p{background:linear-gradient(135deg,var(--ac),var(--ac2));color:#fff}.btn-p:hover{opacity:.85}
.btn-sm{padding:6px 12px;font-size:12px}
.btn-o{background:rgba(255,255,255,.05);border:1px solid var(--bd);color:var(--mt2)}.btn-o:hover{border-color:var(--ac);color:var(--ac)}
.btn-d{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.2);color:var(--rd)}
.table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:560px}
thead th{padding:12px 16px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--mt);background:rgba(0,0,0,.2);border-bottom:1px solid var(--bd)}
tbody tr{border-bottom:1px solid var(--bd);transition:background .15s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:rgba(255,255,255,.02)}
td{padding:14px 16px;font-size:13px;vertical-align:middle}
.status-on{color:var(--gn);font-size:11px;font-weight:600}
.status-off{color:var(--mt2);font-size:11px;font-weight:600}
.url-cell{font-family:monospace;font-size:11px;color:var(--mt2);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.copy-btn{background:rgba(79,110,247,.1);border:1px solid rgba(79,110,247,.2);color:var(--ac);padding:4px 10px;border-radius:6px;font-size:11px;cursor:pointer;font-family:'DM Sans',sans-serif}
.copy-btn:hover{background:rgba(79,110,247,.2)}
.alert{padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;display:none}
.alert.show{display:block}
.alert-ok{background:rgba(52,211,153,.08);border:1px solid rgba(52,211,153,.2);color:var(--gn)}
.action-btns{display:flex;gap:6px;flex-wrap:wrap}

@media(max-width:768px){
  .main{margin-left:0 !important;padding:72px 16px 24px !important}
  h1{font-size:20px}
  .sub{font-size:12px}
  select,input[type=text]{font-size:16px}
  .card{padding:16px}
  td,thead th{padding:8px 10px;font-size:12px}
  .url-cell{max-width:140px}
  .btn-sm{font-size:11px;padding:5px 8px}
}
</style>
<?php require_once __DIR__ . '/includes/theme.php'; echoThemeAssets(''); ?>
</head>
<body>

<?php
$currentPage = 'report_tokens';
require __DIR__ . '/includes/sidebar.php';
?>

<main class="main">
  <h1>🔗 Links de Relatório</h1>
  <p class="sub">Gere links públicos por conta para compartilhar com clientes — sem necessidade de login</p>

  <div class="alert alert-ok" id="alert"></div>

  <?php if ($canCreate): ?>
  <div class="card">
    <div class="ct">➕ Novo link</div>
    <label>Conta de anúncio</label>
    <select id="selAcc">
      <option value="">Selecione uma conta...</option>
      <?php foreach($accounts as $a): ?>
      <option value="<?= htmlspecialchars($a['account_id']) ?>"><?= htmlspecialchars($a['account_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Descrição (opcional)</label>
    <input type="text" id="label" placeholder="Ex: Relatório Victor Lou — Maio 2026">
    <button class="btn btn-p" onclick="createToken()">🔗 Gerar link</button>
  </div>
  <?php endif; ?>

  <div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Conta</th>
            <th>Descrição</th>
            <th>Link público</th>
            <th>Status</th>
            <th>Criado em</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody id="tbody">
          <?php if (empty($tokens)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--mt2);padding:32px">Nenhum link gerado ainda.</td></tr>
          <?php else: ?>
          <?php foreach ($tokens as $t): ?>
          <tr id="row_<?= $t['id'] ?>">
            <td><?= htmlspecialchars($t['account_name'] ?? $t['account_id']) ?></td>
            <td style="color:var(--mt2)"><?= htmlspecialchars($t['label'] ?: '—') ?></td>
            <td>
              <div class="url-cell" id="url_<?= $t['id'] ?>"><?= htmlspecialchars($_SERVER['HTTP_HOST'].'/'.ltrim(dirname($_SERVER['SCRIPT_NAME']),'/').'/report.php?token='.$t['token']) ?></div>
            </td>
            <td>
              <span class="<?= $t['active'] ? 'status-on' : 'status-off' ?>" id="status_<?= $t['id'] ?>">
                <?= $t['active'] ? '● Ativo' : '● Inativo' ?>
              </span>
            </td>
            <td style="color:var(--mt2)"><?= date('d/m/Y', strtotime($t['created_at'])) ?></td>
            <td>
              <div class="action-btns">
                <button class="copy-btn" onclick="copyUrl(<?= $t['id'] ?>)">📋</button>
                <a href="report.php?token=<?= urlencode($t['token']) ?>" target="_blank" class="btn btn-sm btn-o">👁️</a>
                <?php if ($canCreate): ?>
                <button class="btn btn-sm btn-o" onclick="toggleToken(<?= $t['id'] ?>)"><?= $t['active'] ? '⏸' : '▶' ?></button>
                <button class="btn btn-sm btn-d" onclick="deleteToken(<?= $t['id'] ?>)">🗑️</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<script>
var BASE = '<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['SCRIPT_NAME']),'/') ?>';

async function callApi(body) {
  var r = await fetch('api/tokens.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  return r.json();
}

async function createToken() {
  var acc   = document.getElementById('selAcc').value;
  var label = document.getElementById('label').value;
  if (!acc) { alert('Selecione uma conta'); return; }
  var d = await callApi({action:'create', account_id:acc, label:label});
  if (d.error) { alert(d.error); return; }
  var url = BASE + '/report.php?token=' + d.token;
  navigator.clipboard.writeText(url).catch(function(){});
  showAlert('✅ Link gerado e copiado! ' + url);
  setTimeout(function(){ location.reload(); }, 1500);
}

async function toggleToken(id) {
  await callApi({action:'toggle', id:id});
  location.reload();
}

async function deleteToken(id) {
  if (!confirm('Remover este link?')) return;
  await callApi({action:'delete', id:id});
  var row = document.getElementById('row_'+id);
  if (row) row.remove();
}

function copyUrl(id) {
  var el = document.getElementById('url_'+id);
  var url = 'https://' + el.textContent.trim();
  navigator.clipboard.writeText(url).then(function() {
    showAlert('📋 Link copiado: ' + url);
  });
}

function showAlert(msg) {
  var a = document.getElementById('alert');
  a.textContent = msg;
  a.classList.add('show');
  setTimeout(function(){ a.classList.remove('show'); }, 4000);
}
</script>
</body>
</html>