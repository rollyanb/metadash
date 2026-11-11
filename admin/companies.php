<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/theme.php';
require_once __DIR__ . '/../config/database.php';
Auth::requireLogin();
if (!Auth::isAdmin()) { header('Location: ../index.php'); exit; } // área administrativa
Auth::requireAdmin();
$db = Database::getInstance();

$companies = $db->fetchAll("
    SELECT c.*,
           COUNT(DISTINCT ca.id) as account_count,
           COUNT(DISTINCT cu.id) as user_count
    FROM companies c
    LEFT JOIN company_accounts ca ON ca.company_id = c.id
    LEFT JOIN company_user_access cu ON cu.company_id = c.id
    GROUP BY c.id ORDER BY c.name
");
$metaAccounts   = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts ORDER BY account_name");
$tiktokAccounts = $db->fetchAll("SELECT account_id, name FROM tiktok_accounts ORDER BY name");
$users          = $db->fetchAll("SELECT id, username, role FROM users WHERE role != 'admin' ORDER BY username");
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Empresas — MetaDash</title>
<?php echoThemeAssets('../'); ?>
<style>
.page-title{font-size:22px;font-weight:700;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--mt2);margin-bottom:22px}
.page-hrow{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:22px;flex-wrap:wrap}

/* BOTÕES */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;font-family:'Inter',sans-serif;font-weight:600;font-size:13px;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-p{background:var(--ac);color:#fff}.btn-p:hover{opacity:.85}
.btn-d{background:rgba(240,100,90,.1);border:1px solid rgba(240,100,90,.2);color:var(--rd)}.btn-d:hover{background:rgba(240,100,90,.2)}
.btn-sm{padding:5px 10px;font-size:12px}

/* GRID */
.companies-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;margin-bottom:22px}
.company-card{background:var(--sf);border:1px solid var(--bd);border-radius:13px;overflow:hidden}
.company-header{padding:15px 17px;border-bottom:1px solid var(--bd);display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.company-name{font-size:14px;font-weight:700;color:var(--tx);display:flex;align-items:center;gap:7px}
.company-meta{font-size:11px;color:var(--mt2);display:flex;gap:10px;margin-top:3px}
.company-body{padding:13px 17px}
.sec-label{font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--mt);margin-bottom:7px;margin-top:11px}
.sec-label:first-child{margin-top:0}
.tag-list{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:4px}
.tag{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:5px;font-size:11px;font-weight:600}
.tag-meta{background:rgba(24,119,242,.12);color:#1877f2}
.tag-tiktok{background:rgba(0,196,204,.12);color:#00c4cc}
.tag-user{background:rgba(84,112,255,.1);color:var(--ac)}
.tag-empty{background:var(--s2);color:var(--mt)}

/* EMPTY */
.empty-state{text-align:center;padding:60px;color:var(--mt2)}
.empty-state .ico{font-size:40px;margin-bottom:12px}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1000;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal{background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:26px;width:100%;max-width:540px;max-height:88vh;overflow-y:auto}
.modal-title{font-size:16px;font-weight:700;margin-bottom:18px}
.field{margin-bottom:14px}
.field label{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--mt);margin-bottom:5px}
.field input[type=text]{width:100%;padding:9px 12px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-family:'Inter',sans-serif;font-size:13px;outline:none;transition:border-color .2s}
.field input:focus{border-color:var(--ac)}
.search-input{width:100%;padding:8px 12px;background:var(--s2);border:1px solid var(--bd);border-radius:7px;color:var(--tx);font-family:'Inter',sans-serif;font-size:12px;outline:none;margin-bottom:6px;transition:border-color .2s}
.search-input:focus{border-color:var(--ac)}
.checkbox-list{display:flex;flex-direction:column;gap:5px;max-height:160px;overflow-y:auto;padding:2px 0}
.checkbox-item{display:flex;align-items:center;gap:9px;padding:7px 10px;background:var(--s2);border-radius:7px;cursor:pointer;font-size:13px}
.checkbox-item input[type=checkbox]{width:14px;height:14px;accent-color:var(--ac);cursor:pointer;flex-shrink:0}
.checkbox-item .role-badge{font-size:10px;color:var(--mt2);margin-left:auto}
.icon-row{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px}
.icon-btn{background:var(--s2);border:1px solid var(--bd);border-radius:6px;padding:6px 10px;font-size:18px;cursor:pointer;transition:border-color .15s;line-height:1}
.icon-btn:hover,.icon-btn.selected{border-color:var(--ac)}
.modal-footer{display:flex;gap:8px;margin-top:18px;justify-content:flex-end}
</style>
</head>
<body>
<?php $currentPage='companies'; $sidebarBase='../'; require __DIR__.'/../includes/sidebar.php'; ?>

<main class="main">
  <div class="page-hrow">
    <div>
      <h1 class="page-title">🏢 Empresas</h1>
      <p class="page-sub">Agrupe contas Meta e TikTok por empresa e controle quem acessa cada uma</p>
    </div>
    <button type="button" class="btn btn-p" onclick="openModal()">+ Nova Empresa</button>
  </div>

  <?php if (empty($companies)): ?>
  <div class="empty-state">
    <div class="ico">🏢</div>
    <div style="font-weight:700;margin-bottom:5px">Nenhuma empresa criada</div>
    <div style="font-size:13px">Crie sua primeira empresa para agrupar contas e usuários</div>
  </div>
  <?php else: ?>
  <div class="companies-grid">
    <?php foreach ($companies as $company):
      $accts = $db->fetchAll("
          SELECT ca.account_id, ca.platform,
                 COALESCE(a.account_name, ta.name, ca.account_id) as name
          FROM company_accounts ca
          LEFT JOIN ad_accounts a ON a.account_id = ca.account_id AND ca.platform='meta'
          LEFT JOIN tiktok_accounts ta ON ta.account_id = ca.account_id AND ca.platform='tiktok'
          WHERE ca.company_id = ?
          ORDER BY ca.platform, name
      ", [$company['id']]);
      $compUsers = $db->fetchAll("
          SELECT u.id, u.username, u.role
          FROM company_user_access cu JOIN users u ON u.id=cu.user_id
          WHERE cu.company_id=? ORDER BY u.username
      ", [$company['id']]);
    ?>
    <div class="company-card">
      <div class="company-header">
        <div>
          <div class="company-name"><?= $company['icon'] ?> <?= htmlspecialchars($company['name']) ?></div>
          <div class="company-meta">
            <span>📊 <?= count($accts) ?> conta(s)</span>
            <span>👥 <?= count($compUsers) ?> usuário(s)</span>
          </div>
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0">
          <button type="button" class="btn btn-sm" style="background:rgba(84,112,255,.1);border:1px solid rgba(84,112,255,.2);color:var(--ac)"
            onclick="editCompany(this)"
            data-company="<?= htmlspecialchars(json_encode($company), ENT_QUOTES) ?>"
            data-accounts="<?= htmlspecialchars(json_encode($accts), ENT_QUOTES) ?>"
            data-users="<?= htmlspecialchars(json_encode($compUsers), ENT_QUOTES) ?>">✏️</button>
          <button type="button" class="btn btn-sm btn-d" onclick="deleteCompany(<?= $company['id'] ?>, '<?= htmlspecialchars($company['name'], ENT_QUOTES) ?>')">🗑️</button>
        </div>
      </div>
      <div class="company-body">
        <div class="sec-label">Contas</div>
        <div class="tag-list">
          <?php if (empty($accts)): ?>
          <span class="tag tag-empty">Nenhuma conta</span>
          <?php else: ?>
          <?php foreach ($accts as $acc): ?>
          <span class="tag <?= $acc['platform']==='tiktok'?'tag-tiktok':'tag-meta' ?>">
            <?= $acc['platform']==='tiktok'?'🎵':'📘' ?> <?= htmlspecialchars(mb_substr($acc['name'],0,28)) ?>
          </span>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="sec-label">Usuários com acesso</div>
        <div class="tag-list">
          <?php if (empty($compUsers)): ?>
          <span class="tag tag-empty">Nenhum usuário</span>
          <?php else: ?>
          <?php foreach ($compUsers as $u): ?>
          <span class="tag tag-user">👤 <?= htmlspecialchars($u['username']) ?></span>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>

<!-- MODAL -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal">
    <div class="modal-title" id="modalTitle">Nova Empresa</div>
    <input type="hidden" id="editId">

    <div class="field">
      <label>Ícone</label>
      <div class="icon-row" id="iconRow">
        <?php foreach (['🏢','🎉','🎵','🎤','🍺','🎪','🏆','🌟','💼','🎭'] as $icon): ?>
        <button type="button" class="icon-btn" onclick="selectIcon('<?= $icon ?>')"><?= $icon ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" id="selectedIcon" value="🏢">
    </div>

    <div class="field">
      <label>Nome da empresa</label>
      <input type="text" id="companyName" placeholder="Ex: Next Management">
    </div>

    <div class="field">
      <label>📘 Contas Meta Ads</label>
      <input type="text" class="search-input" oninput="filterList(this,'metaList')" placeholder="🔍 Buscar conta Meta...">
      <div class="checkbox-list" id="metaList">
        <?php foreach ($metaAccounts as $acc): ?>
        <label class="checkbox-item">
          <input type="checkbox" class="acc-meta" value="<?= htmlspecialchars($acc['account_id']) ?>">
          <span>📘 <?= htmlspecialchars($acc['account_name']) ?></span>
          <span class="role-badge"><?= htmlspecialchars($acc['account_id']) ?></span>
        </label>
        <?php endforeach; ?>
        <?php if (empty($metaAccounts)): ?><div style="font-size:12px;color:var(--mt2);padding:8px">Nenhuma conta Meta cadastrada</div><?php endif; ?>
      </div>
    </div>

    <div class="field">
      <label>🎵 Contas TikTok Ads</label>
      <input type="text" class="search-input" oninput="filterList(this,'tiktokList')" placeholder="🔍 Buscar conta TikTok...">
      <div class="checkbox-list" id="tiktokList">
        <?php foreach ($tiktokAccounts as $acc): ?>
        <label class="checkbox-item">
          <input type="checkbox" class="acc-tiktok" value="<?= htmlspecialchars($acc['account_id']) ?>">
          <span>🎵 <?= htmlspecialchars($acc['name']) ?></span>
          <span class="role-badge"><?= htmlspecialchars($acc['account_id']) ?></span>
        </label>
        <?php endforeach; ?>
        <?php if (empty($tiktokAccounts)): ?><div style="font-size:12px;color:var(--mt2);padding:8px">Nenhuma conta TikTok importada ainda</div><?php endif; ?>
      </div>
    </div>

    <div class="field">
      <label>👥 Usuários com acesso</label>
      <input type="text" class="search-input" oninput="filterList(this,'userList')" placeholder="🔍 Buscar usuário...">
      <div class="checkbox-list" id="userList">
        <?php $rb=['chefe'=>'👑','financeiro'=>'💰','marketing'=>'🎨','user'=>'👤'];
        foreach ($users as $u): ?>
        <label class="checkbox-item">
          <input type="checkbox" class="acc-user" value="<?= $u['id'] ?>">
          <span><?= $rb[$u['role']]??'👤' ?> <?= htmlspecialchars($u['username']) ?></span>
          <span class="role-badge"><?= htmlspecialchars($u['role']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn" style="background:var(--s2);border:1px solid var(--bd);color:var(--mt2)" onclick="closeModal()">Cancelar</button>
      <button type="button" class="btn btn-p" onclick="saveCompany()">💾 Salvar</button>
    </div>
  </div>
</div>

<script>
var selectedIconVal='🏢';

function selectIcon(icon){
  selectedIconVal=icon;document.getElementById('selectedIcon').value=icon;
  document.querySelectorAll('.icon-btn').forEach(function(b){b.classList.toggle('selected',b.textContent===icon);});
}

function openModal(id,name,icon,metaIds,tiktokIds,userIds){
  document.getElementById('editId').value=id||'';
  document.getElementById('companyName').value=name||'';
  document.getElementById('modalTitle').textContent=id?'✏️ Editar Empresa':'🏢 Nova Empresa';
  selectIcon(icon||'🏢');
  document.querySelectorAll('.acc-meta,.acc-tiktok,.acc-user').forEach(function(c){c.checked=false;});
  if(metaIds)   metaIds.forEach(function(id){var el=document.querySelector('.acc-meta[value="'+id+'"]');if(el)el.checked=true;});
  if(tiktokIds) tiktokIds.forEach(function(id){var el=document.querySelector('.acc-tiktok[value="'+id+'"]');if(el)el.checked=true;});
  if(userIds)   userIds.forEach(function(id){var el=document.querySelector('.acc-user[value="'+id+'"]');if(el)el.checked=true;});
  document.getElementById('modalOverlay').classList.add('open');
}

function editCompany(btn){
  var company=JSON.parse(btn.getAttribute('data-company'));
  var accounts=JSON.parse(btn.getAttribute('data-accounts'));
  var users=JSON.parse(btn.getAttribute('data-users'));
  var metaIds=accounts.filter(function(a){return a.platform==='meta';}).map(function(a){return a.account_id;});
  var tiktokIds=accounts.filter(function(a){return a.platform==='tiktok';}).map(function(a){return a.account_id;});
  var userIds=users.map(function(u){return String(u.id);});
  openModal(company.id,company.name,company.icon,metaIds,tiktokIds,userIds);
}

function closeModal(){document.getElementById('modalOverlay').classList.remove('open');}

async function saveCompany(){
  var id=document.getElementById('editId').value;
  var name=document.getElementById('companyName').value.trim();
  var icon=selectedIconVal;
  if(!name){alert('Informe o nome da empresa');return;}
  var metaAccounts=Array.from(document.querySelectorAll('.acc-meta:checked')).map(function(c){return c.value;});
  var tiktokAccounts=Array.from(document.querySelectorAll('.acc-tiktok:checked')).map(function(c){return c.value;});
  var userIds=Array.from(document.querySelectorAll('.acc-user:checked')).map(function(c){return parseInt(c.value);});
  var res=await fetch('../api/companies.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:id?'update_company':'create_company',id:id?parseInt(id):null,name:name,icon:icon,meta_accounts:metaAccounts,tiktok_accounts:tiktokAccounts,user_ids:userIds})});
  var d=await res.json();
  if(!d.success){alert(d.error||'Erro ao salvar');return;}
  location.reload();
}

async function deleteCompany(id,name){
  if(!confirm('Excluir a empresa "'+name+'"? As contas e usuários vinculados perdem o acesso.'))return;
  var res=await fetch('../api/companies.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_company',id:id})});
  var d=await res.json();if(!d.success){alert(d.error||'Erro');return;}location.reload();
}

// Fecha ao clicar no overlay (não no modal)
document.getElementById('modalOverlay').addEventListener('click',function(e){if(e.target===this)closeModal();});

// Seleciona ícone padrão
selectIcon('🏢');

function filterList(input,listId){
  var term=input.value.toLowerCase();
  document.querySelectorAll('#'+listId+' .checkbox-item').forEach(function(item){
    item.style.display=item.textContent.toLowerCase().includes(term)?'':'none';
  });
}
</script>
</body>
</html>