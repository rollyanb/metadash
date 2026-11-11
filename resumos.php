<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();
$role = Auth::getRole();
if (!in_array($role, ['admin','chefe','marketing'])) {
    header('Location: index.php'); exit;
}

$db              = Database::getInstance();
$allowedAccounts = Auth::getAllowedAccounts();

if ($allowedAccounts === null) {
    $accounts = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE status IN ('ACTIVE','INACTIVE') ORDER BY account_name");
} elseif (!empty($allowedAccounts)) {
    $ph = implode(',', array_fill(0, count($allowedAccounts), '?'));
    $accounts = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE account_id IN ({$ph}) AND status IN ('ACTIVE','INACTIVE') ORDER BY account_name", $allowedAccounts);
} else {
    $accounts = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resumos de Vendas — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
.page-title{font-size:22px;font-weight:700;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--mt2);margin-bottom:24px}

/* FILTER CARD */
.filter-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:20px 22px;margin-bottom:22px;display:flex;flex-direction:column;gap:18px}
.filter-row{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.filter-label{font-size:12px;font-weight:600;color:var(--mt2);text-transform:uppercase;letter-spacing:.6px;min-width:60px;flex-shrink:0}
.filter-card select{flex:1;min-width:200px;max-width:360px;padding:9px 13px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-family:'Inter',sans-serif;font-size:13px;outline:none;transition:border-color .2s;cursor:pointer}
.filter-card select:focus{border-color:var(--ac)}

/* PERÍODO */
.period-btns{display:flex;gap:6px;flex-wrap:wrap}
.pbtn{padding:7px 14px;border-radius:7px;border:1px solid var(--bd);background:var(--s2);color:var(--mt2);font-family:'Inter',sans-serif;font-size:12px;font-weight:500;cursor:pointer;transition:all .15s}
.pbtn:hover{border-color:var(--ac);color:var(--tx)}
.pbtn.active{background:var(--ac);border-color:var(--ac);color:#fff;font-weight:600}

/* BOTÃO GERAR */
.btn-gerar{padding:10px 22px;background:var(--ac);color:#fff;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:opacity .15s;align-self:flex-start}
.btn-gerar:hover{opacity:.85}
.btn-gerar:disabled{opacity:.5;cursor:not-allowed}

/* RESULT CARD */
.result-card{display:none;background:var(--sf);border:1px solid var(--bd);border-radius:14px;overflow:hidden}
.result-card.show{display:block}
.result-header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--bd)}
.result-title{font-size:14px;font-weight:600;color:var(--tx)}
.btn-copy-all{padding:7px 14px;background:rgba(84,112,255,.1);border:1px solid rgba(84,112,255,.25);border-radius:7px;color:var(--ac);font-family:'Inter',sans-serif;font-size:12px;font-weight:600;cursor:pointer;transition:all .15s}
.btn-copy-all:hover{background:rgba(84,112,255,.18)}
.btn-copy-all.copied{background:rgba(62,207,142,.1);border-color:rgba(62,207,142,.25);color:var(--gn)}

/* BLOCKS */
.blocks{display:flex;flex-direction:column}
.block{border-bottom:1px solid var(--bd)}
.block:last-child{border-bottom:none}
.block-header{display:flex;align-items:center;justify-content:space-between;padding:14px 22px 10px}
.block-title{font-size:13px;font-weight:600;color:var(--tx)}
.btn-copy-block{padding:5px 11px;background:var(--s2);border:1px solid var(--bd);border-radius:6px;color:var(--mt2);font-family:'Inter',sans-serif;font-size:11px;font-weight:500;cursor:pointer;transition:all .15s}
.btn-copy-block:hover{border-color:var(--ac);color:var(--ac)}
.btn-copy-block.copied{border-color:var(--gn);color:var(--gn)}
.block-text{padding:0 22px 18px;font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.75;color:var(--mt2);white-space:pre-wrap}

/* ESTADOS */
.empty-state{padding:40px 22px;text-align:center;color:var(--mt);font-size:13px}
.loading-state{padding:32px 22px;text-align:center;color:var(--mt2);font-size:13px;display:flex;align-items:center;justify-content:center;gap:10px}
@keyframes spin{to{transform:rotate(360deg)}}
.spinner{width:16px;height:16px;border:2px solid var(--bd);border-top-color:var(--ac);border-radius:50%;animation:spin .7s linear infinite;flex-shrink:0}

@media(max-width:600px){
  .filter-row{flex-direction:column;align-items:flex-start}
  .filter-card select{max-width:100%}
}
</style>
</head>
<body>
<?php $currentPage='resumos'; $sidebarBase=''; require __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
  <h1 class="page-title">📋 Resumos de Vendas</h1>
  <p class="page-sub">Gere resumos formatados para WhatsApp</p>

  <div class="filter-card">
    <div class="filter-row">
      <span class="filter-label">Conta</span>
      <select id="selAccount">
        <option value="">Selecione uma conta...</option>
        <?php foreach ($accounts as $a): ?>
        <option value="<?= htmlspecialchars($a['account_id']) ?>"><?= htmlspecialchars($a['account_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filter-row">
      <span class="filter-label">Período</span>
      <div class="period-btns">
        <button type="button" class="pbtn active" onclick="setPeriod('today',this)">Hoje</button>
        <button type="button" class="pbtn" onclick="setPeriod('yesterday',this)">Ontem</button>
        <button type="button" class="pbtn" onclick="setPeriod('3',this)">3 dias</button>
        <button type="button" class="pbtn" onclick="setPeriod('7',this)">7 dias</button>
        <button type="button" class="pbtn" onclick="setPeriod('15',this)">15 dias</button>
        <button type="button" class="pbtn" onclick="setPeriod('30',this)">30 dias</button>
      </div>
    </div>

    <button type="button" class="btn-gerar" id="btnGerar" onclick="gerar()">📊 Gerar resumo</button>
  </div>

  <div class="result-card" id="resultCard">
    <div class="result-header">
      <div class="result-title" id="resultTitle">Resumo</div>
      <button type="button" class="btn-copy-all" id="btnCopyAll" onclick="copyAll()">📋 Copiar tudo</button>
    </div>
    <div class="blocks" id="blocksContainer"></div>
  </div>
</main>

<script>
var currentPeriod = 'today';

function setPeriod(p, btn) {
  currentPeriod = p;
  document.querySelectorAll('.pbtn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
}

function getDateRange(period) {
  var today = new Date();
  today.setHours(0,0,0,0);
  var fmt = function(d){ return d.toISOString().split('T')[0]; };
  if (period === 'today') {
    return { start: fmt(today), end: fmt(today), label: 'Hoje (' + today.toLocaleDateString('pt-BR') + ')' };
  }
  if (period === 'yesterday') {
    var y = new Date(today); y.setDate(y.getDate()-1);
    return { start: fmt(y), end: fmt(y), label: 'Ontem (' + y.toLocaleDateString('pt-BR') + ')' };
  }
  var days = parseInt(period);
  var end   = new Date(today); end.setDate(end.getDate()-1);
  var start = new Date(today); start.setDate(start.getDate()-days);
  return { start: fmt(start), end: fmt(end),
    label: 'Últimos '+days+' dias ('+start.toLocaleDateString('pt-BR')+' a '+end.toLocaleDateString('pt-BR')+')' };
}

function fmtN(n, dec) {
  if (dec === undefined) dec = 2;
  return parseFloat(n||0).toLocaleString('pt-BR', {minimumFractionDigits:dec, maximumFractionDigits:dec});
}

async function gerar() {
  var sel    = document.getElementById('selAccount');
  var accId  = sel.value;
  var accName = sel.options[sel.selectedIndex].text;
  if (!accId) { alert('Selecione uma conta'); return; }

  var btn = document.getElementById('btnGerar');
  btn.disabled = true;
  btn.textContent = '⏳ Gerando...';

  var card = document.getElementById('resultCard');
  card.classList.add('show');
  document.getElementById('blocksContainer').innerHTML =
    '<div class="loading-state"><div class="spinner"></div> Buscando dados...</div>';

  var range = getDateRange(currentPeriod);

  try {
    var r = await fetch('api/resumos.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ account_id: accId, date_start: range.start, date_end: range.end })
    });
    var d = await r.json();

    if (d.error) {
      document.getElementById('blocksContainer').innerHTML = '<div class="empty-state">❌ ' + esc(d.error) + '</div>';
      document.getElementById('resultTitle').textContent = 'Erro';
      return;
    }

    var data = d.data || {};
    var purchases = parseInt(data.purchases) || 0;
    var revenue   = parseFloat(data.revenue)  || 0;
    var spend     = parseFloat(data.spend)    || 0;
    var leads     = parseInt(data.leads)      || 0;
    var cpa  = purchases > 0 ? spend / purchases : 0;
    var cpl  = leads > 0     ? spend / leads     : 0;
    var roas = spend > 0     ? revenue / spend   : 0;
    var roi  = spend > 0     ? ((revenue - spend) / spend) * 100 : 0;

    var blocks = [];

    if (purchases > 0 || spend > 0) {
      var t = accName + '\n';
      t += 'Período: ' + range.label + '\n\n';
      t += 'Compras: ' + purchases + '\n';
      if (cpa > 0) t += 'Custo por compra: R$' + fmtN(cpa) + '\n';
      if (revenue > 0) t += 'Valor faturado: R$' + fmtN(revenue) + '\n';
      t += 'Valor gasto: R$' + fmtN(spend) + '\n';
      if (roas > 0) t += 'ROAS: ' + fmtN(roas) + 'x\n';
      if (roi !== 0) t += 'ROI: ' + fmtN(roi, 1) + '%';
      blocks.push({ title: '🛒 Compras', text: t });
    }

    if (leads > 0) {
      var t2 = accName + '\n';
      t2 += 'Período: ' + range.label + '\n\n';
      t2 += 'Leads: ' + leads + '\n';
      if (cpl > 0) t2 += 'Custo por lead: R$' + fmtN(cpl);
      blocks.push({ title: '📋 Leads', text: t2 });
    }

    document.getElementById('resultTitle').textContent = accName + ' — ' + range.label;

    if (!blocks.length) {
      document.getElementById('blocksContainer').innerHTML = '<div class="empty-state">Nenhum dado encontrado para este período.</div>';
      return;
    }

    document.getElementById('blocksContainer').innerHTML = blocks.map(function(b, idx) {
      return '<div class="block">' +
        '<div class="block-header">' +
          '<span class="block-title">' + b.title + '</span>' +
          '<button type="button" class="btn-copy-block" onclick="copyBlock(' + idx + ')">📋 Copiar</button>' +
        '</div>' +
        '<div class="block-text">' + esc(b.text) + '</div>' +
      '</div>';
    }).join('');

    window._blocks = blocks;

  } catch(e) {
    document.getElementById('blocksContainer').innerHTML = '<div class="empty-state">❌ Erro: ' + esc(e.message) + '</div>';
  }

  btn.disabled = false;
  btn.textContent = '📊 Gerar resumo';
}

function copyBlock(idx) {
  var text = window._blocks[idx].text;
  navigator.clipboard.writeText(text).then(function() {
    var btns = document.querySelectorAll('.btn-copy-block');
    var btn  = btns[idx];
    btn.textContent = '✅ Copiado!';
    btn.classList.add('copied');
    setTimeout(function(){ btn.textContent = '📋 Copiar'; btn.classList.remove('copied'); }, 2000);
  });
}

function copyAll() {
  var all = (window._blocks || []).map(function(b){ return b.text; }).join('\n\n---\n\n');
  navigator.clipboard.writeText(all).then(function() {
    var btn = document.getElementById('btnCopyAll');
    btn.textContent = '✅ Copiado!';
    btn.classList.add('copied');
    setTimeout(function(){ btn.textContent = '📋 Copiar tudo'; btn.classList.remove('copied'); }, 2000);
  });
}

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
</body>
</html>