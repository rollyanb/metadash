<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();

$db       = Database::getInstance();
$accounts = $db->fetchAll("SELECT account_id, account_name, card_display, last_sync FROM ad_accounts ORDER BY account_name");
$total    = count($accounts);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sincronização — Meta Ads</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg:#07070f; --surface:#0f0f1a; --surface2:#161625;
    --border:rgba(255,255,255,0.07); --accent:#4f6ef7; --accent2:#a78bfa;
    --green:#34d399; --red:#f87171; --yellow:#fbbf24;
    --text:#ededf5; --muted:#5c5c78; --muted2:#8888a0;
  }
  body { font-family:'DM Sans',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding:32px; }

  .header { display:flex; align-items:center; gap:14px; margin-bottom:32px; }
  .logo-icon { width:40px;height:40px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px; }
  h1 { font-family:'Syne',sans-serif; font-size:24px; font-weight:800; }
  .subtitle { color:var(--muted2); font-size:14px; margin-top:2px; }

  .summary-bar {
    background:var(--surface); border:1px solid var(--border); border-radius:16px;
    padding:20px 24px; margin-bottom:24px;
    display:flex; align-items:center; gap:32px; flex-wrap:wrap;
  }
  .stat .label { font-size:11px; text-transform:uppercase; letter-spacing:1px; color:var(--muted2); margin-bottom:4px; }
  .stat .value { font-family:'Syne',sans-serif; font-size:22px; font-weight:800; }
  .stat .value.green { color:var(--green); }
  .stat .value.yellow { color:var(--yellow); }

  /* Progress bar global */
  .progress-wrap { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:20px 24px; margin-bottom:24px; }
  .progress-label { display:flex; justify-content:space-between; margin-bottom:10px; font-size:13px; }
  .progress-label span { color:var(--muted2); }
  .progress-label strong { color:var(--text); }
  .bar-track { background:rgba(255,255,255,0.05); border-radius:100px; height:10px; overflow:hidden; }
  .bar-fill { height:100%; background:linear-gradient(90deg,var(--accent),var(--accent2)); border-radius:100px; transition:width 0.4s ease; width:0%; }

  /* Controls */
  .controls { display:flex; gap:12px; margin-bottom:24px; flex-wrap:wrap; align-items:center; }
  .btn { padding:11px 22px; border-radius:10px; font-family:'Syne',sans-serif; font-weight:700; font-size:14px; cursor:pointer; border:none; transition:all 0.15s; }
  .btn-start { background:linear-gradient(135deg,var(--accent),var(--accent2)); color:white; }
  .btn-start:hover { opacity:0.85; }
  .btn-start:disabled { opacity:0.4; cursor:not-allowed; }
  .btn-outline { background:transparent; border:1px solid var(--border); color:var(--muted2); }
  .btn-outline:hover { border-color:var(--accent); color:var(--accent); }
  .period-select { background:var(--surface2); border:1px solid var(--border); border-radius:10px; color:var(--text); padding:10px 14px; font-family:'DM Sans',sans-serif; font-size:14px; outline:none; }

  /* Accounts list */
  .accounts-card { background:var(--surface); border:1px solid var(--border); border-radius:16px; overflow:hidden; }
  .accounts-header { padding:16px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
  .card-title { font-family:'Syne',sans-serif; font-size:15px; font-weight:700; }
  table { width:100%; border-collapse:collapse; }
  thead th { padding:10px 20px; text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:1px; color:var(--muted); background:rgba(0,0,0,0.2); border-bottom:1px solid var(--border); }
  tbody tr { border-bottom:1px solid var(--border); }
  tbody tr:last-child { border-bottom:none; }
  td { padding:12px 20px; font-size:13px; vertical-align:middle; }

  .status-cell { display:flex; align-items:center; gap:8px; }
  .dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
  .dot-waiting  { background:var(--muted); }
  .dot-running  { background:var(--yellow); animation:pulse 1s infinite; }
  .dot-done     { background:var(--green); }
  .dot-error    { background:var(--red); }
  .dot-skipped  { background:var(--muted); }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.3} }

  .status-text { font-size:12px; color:var(--muted2); }
  .status-text.running { color:var(--yellow); }
  .status-text.done    { color:var(--green); }
  .status-text.error   { color:var(--red); }

  .txn-badge { display:inline-flex;align-items:center;gap:4px;background:rgba(79,110,247,0.1);border:1px solid rgba(79,110,247,0.2);border-radius:5px;padding:2px 8px;font-size:11px;color:var(--accent); }
  .card-badge { color:var(--accent2); font-size:12px; }

  .back-link { display:inline-flex;align-items:center;gap:6px;color:var(--muted2);text-decoration:none;font-size:13px;margin-top:20px; }
  .back-link:hover { color:var(--accent); }

  #statusMsg { font-size:13px; color:var(--muted2); }
</style>
</head>
<body>

<div class="header">
  <div class="logo-icon">🔄</div>
  <div>
    <h1>Sincronização de Transações</h1>
    <div class="subtitle">Processa conta por conta para evitar timeout — <?= $total ?> contas encontradas</div>
  </div>
</div>

<!-- SUMMARY -->
<div class="summary-bar">
  <div class="stat">
    <div class="label">Total de contas</div>
    <div class="value"><?= $total ?></div>
  </div>
  <div class="stat">
    <div class="label">Processadas</div>
    <div class="value green" id="statDone">0</div>
  </div>
  <div class="stat">
    <div class="label">Cobranças salvas</div>
    <div class="value yellow" id="statTxn">0</div>
  </div>
  <div class="stat">
    <div class="label">Erros</div>
    <div class="value" id="statErr" style="color:var(--red)">0</div>
  </div>
  <div style="margin-left:auto">
    <div id="statusMsg">Aguardando início...</div>
  </div>
</div>

<!-- PROGRESS BAR -->
<div class="progress-wrap">
  <div class="progress-label">
    <strong id="progressText">0 / <?= $total ?> contas</strong>
    <span id="progressPct">0%</span>
  </div>
  <div class="bar-track"><div class="bar-fill" id="barFill"></div></div>
</div>

<!-- CONTROLS -->
<div class="controls">
  <select class="period-select" id="periodSelect">
    <option value="30">Últimos 30 dias</option>
    <option value="60">Últimos 60 dias</option>
    <option value="90" selected>Últimos 90 dias</option>
    <option value="180">Últimos 180 dias</option>
  </select>
  <button class="btn btn-start" id="btnStart" onclick="startSync()">▶ Iniciar sincronização</button>
  <button class="btn btn-outline" id="btnStop" onclick="stopSync()" disabled>⏹ Parar</button>
  <a href="transactions.php" class="btn btn-outline">Ver Transações</a>
</div>

<!-- ACCOUNTS TABLE -->
<div class="accounts-card">
  <div class="accounts-header">
    <div class="card-title">Contas de Anúncio</div>
    <span style="font-size:12px;color:var(--muted)"><?= $total ?> contas</span>
  </div>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Conta</th>
        <th>Cartão</th>
        <th>Cobranças</th>
        <th>Gastos (dias)</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody id="accountsBody">
      <?php foreach ($accounts as $i => $acc): ?>
      <tr id="row_<?= htmlspecialchars($acc['account_id']) ?>">
        <td style="color:var(--muted);font-size:12px"><?= $i + 1 ?></td>
        <td>
          <div style="font-weight:500"><?= htmlspecialchars($acc['account_name']) ?></div>
          <div style="font-size:11px;color:var(--muted);font-family:monospace"><?= $acc['account_id'] ?></div>
        </td>
        <td class="card-badge"><?= $acc['card_display'] ? '💳 ' . htmlspecialchars($acc['card_display']) : '—' ?></td>
        <td id="txn_<?= htmlspecialchars($acc['account_id']) ?>" style="color:var(--muted2)">—</td>
        <td id="spend_<?= htmlspecialchars($acc['account_id']) ?>" style="color:var(--muted2)">—</td>
        <td>
          <div class="status-cell">
            <div class="dot dot-waiting" id="dot_<?= htmlspecialchars($acc['account_id']) ?>"></div>
            <span class="status-text" id="status_<?= htmlspecialchars($acc['account_id']) ?>">Aguardando</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<a href="index.php" class="back-link">← Voltar ao Dashboard</a>

<script>
const accounts = <?= json_encode(array_column($accounts, 'account_id')) ?>;
let running  = false;
let stopped  = false;
let doneCount = 0;
let txnTotal  = 0;
let errCount  = 0;

function setStatus(id, type, text) {
  const dot    = document.getElementById('dot_' + id);
  const status = document.getElementById('status_' + id);
  dot.className    = 'dot dot-' + type;
  status.className = 'status-text ' + type;
  status.textContent = text;
}

function updateProgress() {
  const pct = Math.round((doneCount / accounts.length) * 100);
  document.getElementById('barFill').style.width      = pct + '%';
  document.getElementById('progressText').textContent = doneCount + ' / ' + accounts.length + ' contas';
  document.getElementById('progressPct').textContent  = pct + '%';
  document.getElementById('statDone').textContent = doneCount;
  document.getElementById('statTxn').textContent  = txnTotal;
  document.getElementById('statErr').textContent  = errCount;
}

async function processAccount(accountId, days) {
  setStatus(accountId, 'running', 'Processando...');

  const since = Math.floor(Date.now() / 1000) - (days * 86400);
  const until = Math.floor(Date.now() / 1000);

  const body = new URLSearchParams({ account_id: accountId, since, until });

  try {
    const res  = await fetch('api/sync_account.php', { method: 'POST', body });
    const data = await res.json();

    if (data.error) {
      setStatus(accountId, 'error', 'Erro: ' + data.error.substring(0, 50));
      document.getElementById('txn_'   + accountId).textContent = '—';
      document.getElementById('spend_' + accountId).textContent = '—';
      errCount++;
      return;
    }

    const txnSaved   = data.txn_saved   ?? 0;
    const spendDays  = data.spend_days  ?? 0;
    const activities = data.activities  ?? 0;

    document.getElementById('txn_'   + accountId).innerHTML =
      txnSaved > 0
        ? '<span class="txn-badge">💰 ' + txnSaved + '</span>'
        : '<span style="color:var(--muted)">0</span>';

    document.getElementById('spend_' + accountId).textContent =
      spendDays > 0 ? spendDays + ' dias' : '—';

    if (data.api_error) {
      setStatus(accountId, 'error', 'API: ' + data.api_error.substring(0, 50));
      errCount++;
    } else {
      setStatus(accountId, 'done', txnSaved > 0 ? '✅ ' + txnSaved + ' cobranças' : '✅ Sem cobranças');
    }

    txnTotal += txnSaved;

  } catch (e) {
    setStatus(accountId, 'error', 'Falha de rede');
    errCount++;
  }
}

async function startSync() {
  if (running) return;
  running = true;
  stopped = false;
  doneCount = 0;
  txnTotal  = 0;
  errCount  = 0;

  document.getElementById('btnStart').disabled = true;
  document.getElementById('btnStop').disabled  = false;
  document.getElementById('periodSelect').disabled = true;

  const days = parseInt(document.getElementById('periodSelect').value);

  for (let i = 0; i < accounts.length; i++) {
    if (stopped) {
      document.getElementById('statusMsg').textContent = '⏹ Parado pelo usuário.';
      break;
    }

    const id = accounts[i];
    document.getElementById('statusMsg').textContent = 'Processando ' + (i + 1) + ' de ' + accounts.length + '...';

    // Scroll para linha atual
    const row = document.getElementById('row_' + id);
    if (row) row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    await processAccount(id, days);
    doneCount++;
    updateProgress();

    // Pausa entre contas para não sobrecarregar
    await new Promise(r => setTimeout(r, 400));
  }

  running = false;
  document.getElementById('btnStart').disabled = false;
  document.getElementById('btnStop').disabled  = true;
  document.getElementById('periodSelect').disabled = false;

  if (!stopped) {
    document.getElementById('statusMsg').textContent =
      '✅ Concluído! ' + txnTotal + ' cobranças salvas em ' + doneCount + ' contas.';
  }
}

function stopSync() {
  stopped = true;
  running = false;
  document.getElementById('btnStop').disabled  = true;
  document.getElementById('btnStart').disabled = false;
}
</script>
</body>
</html>