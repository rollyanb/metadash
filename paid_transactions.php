<?php
// paid_transactions.php — Transações pagas (recibos do Business Manager) vinculadas a eventos.
// Importe o zip exportado em Business Manager › Faturamento › Transações › Baixar recibos.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/receipt_parser.php';

Auth::requireLogin();
if (!Auth::isAdmin()) { header('Location: index.php'); exit; } // página só do admin
$isAdmin = true;
$canImport = true;
$canAssign = true;
$db = Database::getInstance();

$message = ''; $msgType = 'success';

// ── AJAX: salvar várias atribuições de uma vez ────────────────
if (($_GET['ajax'] ?? '') === 'assign') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canAssign) { echo json_encode(['error' => 'Sem permissão']); exit; }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $pairs = $in['pairs'] ?? [];
    $n = 0;
    foreach ($pairs as $pr) {
        $itemId = (int)($pr['item'] ?? 0);
        $eid    = (int)($pr['event'] ?? 0);
        if (!$itemId) continue;
        $db->execute("UPDATE paid_transaction_items SET event_id = ?, match_source = 'manual' WHERE id = ?",
            [$eid ?: null, $itemId]);
        $n++;
    }
    echo json_encode(['success' => true, 'saved' => $n]);
    exit;
}

function matchEvent($db, $campaignName, $campMap, $tagMap) {
    $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($campaignName)));
    if (isset($campMap[$key])) return [$campMap[$key], 'campanha'];
    foreach ($tagMap as $tag => $eid) {
        if ($tag !== '' && mb_stripos($key, $tag) !== false) return [$eid, 'tag'];
    }
    return [null, ''];
}

function loadMaps($db) {
    $campMap = [];
    foreach ($db->fetchAll("SELECT campaign_name, event_id FROM event_campaigns") as $r) {
        $campMap[mb_strtolower(preg_replace('/\s+/', ' ', trim($r['campaign_name'])))] = (int)$r['event_id'];
    }
    $tagMap = [];
    foreach ($db->fetchAll("SELECT tag, event_id FROM event_tags") as $r) {
        $tagMap[mb_strtolower(trim($r['tag']))] = (int)$r['event_id'];
    }
    return [$campMap, $tagMap];
}

function revincular($db) {
    // Reaplica campanha/tag em tudo que não foi atribuído manualmente
    list($campMap, $tagMap) = loadMaps($db);
    $items = $db->fetchAll("SELECT id, campaign_name FROM paid_transaction_items WHERE match_source IN ('', 'campanha', 'tag')");
    $n = 0;
    foreach ($items as $it) {
        list($eid, $src) = matchEvent($db, $it['campaign_name'], $campMap, $tagMap);
        $db->execute("UPDATE paid_transaction_items SET event_id = ?, match_source = ? WHERE id = ?", [$eid, $src, $it['id']]);
        if ($eid) $n++;
    }
    return $n;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Importar zip ──
    if ($canImport && isset($_FILES['receipts_zip']) && $_FILES['receipts_zip']['error'] === UPLOAD_ERR_OK) {
        list($txs, $errs) = ReceiptParser::parseZip($_FILES['receipts_zip']['tmp_name']);
        list($campMap, $tagMap) = loadMaps($db);
        $novos = 0; $repetidos = 0; $linhas = 0; $vinculadas = 0;
        foreach ($txs as $tx) {
            $exists = $db->fetch("SELECT id FROM paid_transactions WHERE transaction_id = ?", [$tx['transaction_id']]);
            if ($exists) { $repetidos++; continue; }
            $db->execute("INSERT INTO paid_transactions
                (transaction_id, account_name, billing_account, paid_at, status, payment_method, reference, total, subtotal, taxes)
                VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$tx['transaction_id'], (string)$tx['account_name'], $tx['billing_account'], $tx['paid_at'],
                 $tx['status'], $tx['payment_method'], $tx['reference'], $tx['total'], $tx['subtotal'], $tx['taxes']]);
            foreach ($tx['campaigns'] as $c) {
                list($eid, $src) = matchEvent($db, $c['name'], $campMap, $tagMap);
                $db->execute("INSERT INTO paid_transaction_items
                    (transaction_id, campaign_name, value, period_start, period_end, event_id, match_source)
                    VALUES (?,?,?,?,?,?,?)",
                    [$tx['transaction_id'], $c['name'], $c['value'], $c['period_start'], $c['period_end'], $eid, $src]);
                $linhas++; if ($eid) $vinculadas++;
            }
            $novos++;
        }
        $message = "✅ Importadas {$novos} transações novas ({$repetidos} já existiam). {$vinculadas}/{$linhas} linhas de campanha vinculadas a eventos automaticamente.";
        if ($errs) { $message .= ' ⚠️ Arquivos não reconhecidos: ' . count($errs) . ' (' . htmlspecialchars(implode(', ', array_slice($errs, 0, 3))) . '...)'; }
    }
    // ── Criar tag ──
    elseif ($canImport && isset($_POST['add_tag'], $_POST['tag'], $_POST['tag_event_id']) && trim($_POST['tag']) !== '') {
        $db->execute("INSERT INTO event_tags (tag, event_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE event_id = VALUES(event_id)",
            [trim($_POST['tag']), (int)$_POST['tag_event_id']]);
        $n = revincular($db);
        $message = "✅ Tag salva. Revinculação aplicada ({$n} linhas com evento).";
    }
    // ── Remover tag ──
    elseif ($canImport && isset($_POST['del_tag'])) {
        $db->execute("DELETE FROM event_tags WHERE id = ?", [(int)$_POST['del_tag']]);
        $n = revincular($db);
        $message = "✅ Tag removida. Revinculação aplicada ({$n} linhas com evento).";
    }
    // ── Atribuição manual de uma linha ──
    elseif ($canAssign && isset($_POST['assign_item'], $_POST['assign_event_id'])) {
        $eid = (int)$_POST['assign_event_id'];
        $db->execute("UPDATE paid_transaction_items SET event_id = ?, match_source = 'manual' WHERE id = ?",
            [$eid ?: null, (int)$_POST['assign_item']]);
        $message = $eid ? "✅ Linha atribuída manualmente." : "✅ Atribuição removida.";
    }
    // ── Revincular tudo ──
    elseif ($canImport && isset($_POST['relink_all'])) {
        $n = revincular($db);
        $message = "✅ Revinculação completa: {$n} linhas com evento.";
    }
}

// ── Filtros ──
$fEvent  = isset($_GET['event'])  ? (int)$_GET['event'] : 0;
$fStatus = isset($_GET['status']) ? $_GET['status'] : 'Pago'; // padrão: só pagas
$fMonth  = isset($_GET['month'])  ? $_GET['month'] : '';

$where = []; $params = [];
if ($fStatus !== '' && $fStatus !== 'todos') { $where[] = "t.status = ?"; $params[] = $fStatus; }
if ($fMonth !== '' && preg_match('/^\d{4}-\d{2}$/', $fMonth)) { $where[] = "DATE_FORMAT(t.paid_at, '%Y-%m') = ?"; $params[] = $fMonth; }
if ($fEvent) { $where[] = "t.transaction_id IN (SELECT transaction_id FROM paid_transaction_items WHERE event_id = ?)"; $params[] = $fEvent; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$txs = $db->fetchAll("SELECT t.* FROM paid_transactions t $whereSql ORDER BY t.paid_at DESC LIMIT 300", $params);
$eventsByTx = [];
$itemsByTx = [];
if ($txs) {
    $tids = array_column($txs, 'transaction_id');
    $ph = implode(',', array_fill(0, count($tids), '?'));
    $rows = $db->fetchAll("SELECT i.*, e.name AS event_name FROM paid_transaction_items i
                           LEFT JOIN events e ON e.id = i.event_id
                           WHERE i.transaction_id IN ($ph) ORDER BY i.value DESC", $tids);
    foreach ($rows as $r) {
        $itemsByTx[$r['transaction_id']][] = $r;
        if ($r['event_id']) {
            $eventsByTx[$r['transaction_id']][$r['event_id']]['name'] = $r['event_name'];
            $eventsByTx[$r['transaction_id']][$r['event_id']]['value'] =
                ($eventsByTx[$r['transaction_id']][$r['event_id']]['value'] ?? 0) + $r['value'];
        }
    }
}

// Resumo pago por evento (respeita filtro de mês)
$sumWhere = "t.status = 'Pago'"; $sumParams = [];
if ($fMonth !== '' && preg_match('/^\d{4}-\d{2}$/', $fMonth)) { $sumWhere .= " AND DATE_FORMAT(t.paid_at, '%Y-%m') = ?"; $sumParams[] = $fMonth; }
$porEvento = $db->fetchAll("
    SELECT COALESCE(e.name, '(sem evento)') AS ev, i.event_id, SUM(i.value) AS pago
    FROM paid_transaction_items i
    JOIN paid_transactions t ON t.transaction_id = i.transaction_id
    LEFT JOIN events e ON e.id = i.event_id
    WHERE $sumWhere
    GROUP BY i.event_id, e.name ORDER BY pago DESC", $sumParams);

$eventos = $db->fetchAll("SELECT id, name FROM events ORDER BY name");
$tags    = $db->fetchAll("SELECT et.*, e.name AS event_name FROM event_tags et JOIN events e ON e.id = et.event_id ORDER BY et.tag");
$pendentes = $db->fetch("SELECT COUNT(*) c, COALESCE(SUM(i.value),0) v FROM paid_transaction_items i
                         JOIN paid_transactions t ON t.transaction_id = i.transaction_id
                         WHERE i.event_id IS NULL AND t.status = 'Pago'");
// Sugestão automática pela tag do nome da campanha (usa a tabela event_tags)
$tagMapAll = [];
foreach ($db->fetchAll("SELECT et.tag, et.event_id FROM event_tags et") as $tg) {
    $tagMapAll[mb_strtolower(trim($tg['tag']))] = (int)$tg['event_id'];
}
function sugerirEvento($campaignName, $tagMapAll) {
    $n = mb_strtolower($campaignName);
    foreach ($tagMapAll as $tag => $eid) {
        if ($tag !== '' && mb_strpos($n, $tag) !== false) return $eid;
    }
    return 0;
}

$statusBadge = function($s) {
    $map = ['Pago' => 'success', 'Falha' => 'error', 'Pendente' => 'warning'];
    $cls = $map[$s] ?? 'warning';
    return '<span class="badge badge-' . $cls . '">' . htmlspecialchars($s) . '</span>';
};
$fmt = function($v) { return 'R$' . number_format((float)$v, 2, ',', '.'); };
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= getTheme() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transações pagas — MetaDash</title>
<?php echoThemeAssets(); ?>
<style>
.tx-card{background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:14px 16px;margin-bottom:12px}
.tx-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.tx-items{margin-top:10px;border-top:1px solid var(--bd);padding-top:8px}
.tx-item{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:5px 0;font-size:12px}
.tx-item .ev{color:var(--ac);font-weight:600}
.tx-item .noev{color:var(--mt)}
.filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px}
.filters select,.filters input{width:auto;min-width:150px}
.sum-table td{padding:5px 10px 5px 0;font-size:13px}
.tagchip{display:inline-flex;align-items:center;gap:6px;background:var(--s2);border:1px solid var(--bd);border-radius:20px;padding:3px 10px;font-size:12px;margin:3px 4px 0 0}
</style>
</head>
<body>
<?php $currentPage = 'paid_transactions'; $sidebarBase = ''; require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
  <h1 class="page-title">💳 Transações pagas</h1>
  <p style="color:var(--mt);font-size:13px;margin-bottom:16px">Recibos do Business Manager vinculados aos eventos pelo desmembramento por campanha de cada cobrança.</p>

  <?php if ($message): ?><div class="alert alert-<?= $msgType ?>"><?= $message ?></div><?php endif; ?>

  <?php if ($canImport): ?>
  <div class="card">
    <div class="card-title">📥 Importar recibos</div>
    <p style="font-size:12px;color:var(--mt);margin-bottom:10px">Business Manager › Faturamento › Transações › selecione o período › <b>Baixar recibos</b> (zip). Pode importar o mesmo zip mais de uma vez — transações repetidas são ignoradas. Cobranças com status <b>Falha</b> são guardadas mas nunca somadas.</p>
    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <?= Auth::csrfField() ?>
      <input type="file" name="receipts_zip" accept=".zip" required>
      <button type="submit" class="btn btn-p">Importar</button>
      <button type="submit" name="relink_all" value="1" class="btn btn-o" formnovalidate title="Reaplica o vínculo por campanha/tag em tudo que não foi atribuído manualmente">🔁 Revincular tudo</button>
    </form>
  </div>

  <div class="card">
    <div class="card-title">🏷️ Tags de evento (vínculo automático)</div>
    <p style="font-size:12px;color:var(--mt);margin-bottom:8px">Campanha sem correspondência exata é vinculada se o nome contiver uma tag. Ex.: tag <code>[BL RP]</code> → evento Baile Lotadão RP.</p>
    <div style="margin-bottom:10px">
      <?php foreach ($tags as $tg): ?>
      <span class="tagchip"><b><?= htmlspecialchars($tg['tag']) ?></b> → <?= htmlspecialchars($tg['event_name']) ?>
        <form method="POST" style="display:inline"><?= Auth::csrfField() ?><button type="submit" name="del_tag" value="<?= $tg['id'] ?>" style="background:none;border:none;color:var(--mt);cursor:pointer;padding:0">✕</button></form>
      </span>
      <?php endforeach; if (!$tags): ?><span style="font-size:12px;color:var(--mt)">Nenhuma tag ainda.</span><?php endif; ?>
    </div>
    <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <?= Auth::csrfField() ?>
      <input type="text" name="tag" placeholder="[BL RP]" style="width:140px" required>
      <select name="tag_event_id" required>
        <option value="">Evento…</option>
        <?php foreach ($eventos as $ev): ?><option value="<?= $ev['id'] ?>"><?= htmlspecialchars($ev['name']) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" name="add_tag" value="1" class="btn btn-o">Adicionar tag</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($porEvento): ?>
  <div class="card">
    <div class="card-title">💰 Pago por evento<?= $fMonth ? ' — ' . htmlspecialchars($fMonth) : '' ?></div>
    <table class="sum-table">
      <?php foreach ($porEvento as $pe): ?>
      <tr>
        <td><?= $pe['event_id'] ? '<span style="color:var(--ac);font-weight:600">' . htmlspecialchars($pe['ev']) . '</span>' : '<span style="color:var(--mt)">(sem evento)</span>' ?></td>
        <td style="text-align:right;font-weight:700"><?= $fmt($pe['pago']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($pendentes && $pendentes['c'] > 0): ?>
    <div style="margin-top:8px;font-size:12px;color:var(--mt)">⚠️ <?= $pendentes['c'] ?> linhas pagas sem evento (<?= $fmt($pendentes['v']) ?>) — vincule campanhas nos eventos, crie tags ou atribua manualmente abaixo.</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <form method="GET" class="filters">
    <div><label style="font-size:11px;color:var(--mt);display:block">Evento</label>
      <select name="event" onchange="this.form.submit()">
        <option value="">Todos</option>
        <?php foreach ($eventos as $ev): ?><option value="<?= $ev['id'] ?>" <?= $fEvent == $ev['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ev['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label style="font-size:11px;color:var(--mt);display:block">Status</label>
      <select name="status" onchange="this.form.submit()">
        <?php foreach (['Pago','Falha','Pendente'] as $st): ?><option <?= $fStatus === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?>
        <option value="todos" <?= $fStatus === 'todos' ? 'selected' : '' ?>>Todas</option>
      </select></div>
    <div><label style="font-size:11px;color:var(--mt);display:block">Mês</label>
      <input type="month" name="month" value="<?= htmlspecialchars($fMonth) ?>" onchange="this.form.submit()"></div>
  </form>

  <?php if (!$txs): ?>
    <div style="color:var(--mt);text-align:center;padding:40px 0">Nenhuma transação<?= $fEvent || $fStatus || $fMonth ? ' com esses filtros' : ' importada ainda' ?>.</div>
  <?php endif; ?>

  <?php foreach ($txs as $t): ?>
  <div class="tx-card">
    <div class="tx-head">
      <div>
        <div style="font-weight:700;font-size:14px"><?= $fmt($t['total']) ?> <?= $statusBadge($t['status']) ?>
          <?php foreach ($eventsByTx[$t['transaction_id']] ?? [] as $evx): ?>
            <span style="display:inline-block;background:var(--s2);border:1px solid var(--bd);color:var(--ac);border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600;margin-left:4px">🎪 <?= htmlspecialchars($evx['name']) ?> · <?= $fmt($evx['value']) ?></span>
          <?php endforeach; ?>
          <?php if (empty($eventsByTx[$t['transaction_id']]) && !empty($itemsByTx[$t['transaction_id']])): ?>
            <span style="display:inline-block;background:var(--s2);border:1px solid var(--bd);color:var(--mt);border-radius:20px;padding:2px 10px;font-size:11px;margin-left:4px">sem evento</span>
          <?php endif; ?>
        </div>
        <div style="font-size:11px;color:var(--mt);margin-top:2px">
          <?= $t['paid_at'] ? date('d/m/Y H:i', strtotime($t['paid_at'])) : '—' ?> ·
          <?= htmlspecialchars($t['account_name']) ?> ·
          <?= htmlspecialchars((string)$t['payment_method']) ?> ·
          #<?= htmlspecialchars((string)$t['reference']) ?>
        </div>
      </div>
      <div style="font-size:11px;color:var(--mt);text-align:right">
        Subtotal <?= $fmt($t['subtotal']) ?> · Impostos <?= $fmt($t['taxes']) ?><br>
        <span style="font-size:10px">ID <?= htmlspecialchars(substr($t['transaction_id'], 0, 22)) ?>…</span>
      </div>
    </div>

    <?php
      $itensAbertos = array_filter($itemsByTx[$t['transaction_id']] ?? [], function($i) { return empty($i['event_id']); });
      $temSugestao = false;
      foreach ($itensAbertos as $ia) { if (sugerirEvento($ia['campaign_name'], $tagMapAll)) { $temSugestao = true; break; } }
    ?>
    <?php if ($canAssign && $itensAbertos): ?>
    <div class="tx-bulk" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px;padding:8px 10px;background:var(--s2);border-radius:9px">
      <span style="font-size:11.5px;color:var(--mt)"><?= count($itensAbertos) ?> sem evento:</span>
      <select class="bulk-event" style="width:190px;font-size:11.5px;padding:4px 7px">
        <option value="">atribuir tudo a…</option>
        <?php foreach ($eventos as $ev): ?><option value="<?= $ev['id'] ?>"><?= htmlspecialchars($ev['name']) ?></option><?php endforeach; ?>
      </select>
      <button type="button" class="btn btn-o btn-sm" style="font-size:11.5px;padding:4px 10px"
              onclick="aplicarTudo(this)">Aplicar a todos</button>
      <?php if ($temSugestao): ?>
      <button type="button" class="btn btn-o btn-sm" style="font-size:11.5px;padding:4px 10px"
              onclick="sugerirTags(this)" title="Preenche pelos prefixos [ ] das campanhas">✨ Sugerir pelas tags</button>
      <?php endif; ?>
      <span style="flex:1"></span>
      <span class="bulk-msg" style="font-size:11.5px;color:var(--mt)"></span>
      <button type="button" class="btn btn-p btn-sm" style="font-size:11.5px;padding:4px 12px"
              onclick="salvarBloco(this)">💾 Salvar</button>
    </div>
    <?php endif; ?>
    <div class="tx-items">
      <?php foreach ($itemsByTx[$t['transaction_id']] ?? [] as $it): ?>
      <div class="tx-item">
        <div style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($it['campaign_name']) ?>"><?= htmlspecialchars($it['campaign_name']) ?></div>
        <div style="min-width:90px;text-align:right;font-weight:600"><?= $fmt($it['value']) ?></div>
        <div style="min-width:220px;text-align:right">
          <?php if ($it['event_id']): ?>
            <span class="ev">→ <?= htmlspecialchars($it['event_name']) ?></span>
            <span style="font-size:10px;color:var(--mt)">(<?= $it['match_source'] ?>)</span>
          <?php elseif ($canAssign): $sug = sugerirEvento($it['campaign_name'], $tagMapAll); ?>
            <select class="item-event" data-item="<?= $it['id'] ?>" data-sug="<?= $sug ?>"
                    onchange="marcarAlterado(this)" style="width:190px;font-size:11px;padding:3px 6px">
              <option value="">Sem evento…</option>
              <?php foreach ($eventos as $ev): ?><option value="<?= $ev['id'] ?>"><?= htmlspecialchars($ev['name']) ?></option><?php endforeach; ?>
            </select>
          <?php else: ?><span class="noev">sem evento</span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</main>

<script>
function bloco(el) { return el.closest('.tx-card'); }

function marcarAlterado(sel) {
  sel.style.borderColor = sel.value ? 'var(--ac)' : '';
  var b = bloco(sel);
  var msg = b.querySelector('.bulk-msg');
  if (msg) { msg.textContent = 'alterações não salvas'; msg.style.color = 'var(--yw, #fbbf24)'; }
}

function aplicarTudo(btn) {
  var b = bloco(btn);
  var ev = b.querySelector('.bulk-event').value;
  if (!ev) { alert('Escolha o evento antes de aplicar.'); return; }
  b.querySelectorAll('.item-event').forEach(function(s){ s.value = ev; marcarAlterado(s); });
}

function sugerirTags(btn) {
  var b = bloco(btn), n = 0;
  b.querySelectorAll('.item-event').forEach(function(s){
    var sug = s.dataset.sug;
    if (sug && sug !== '0') { s.value = sug; marcarAlterado(s); n++; }
  });
  var msg = b.querySelector('.bulk-msg');
  msg.textContent = n ? n + ' preenchido(s) pela tag — confira e salve' : 'nenhuma tag correspondeu';
  msg.style.color = n ? 'var(--yw, #fbbf24)' : 'var(--mt)';
}

async function salvarBloco(btn) {
  var b = bloco(btn);
  var msg = b.querySelector('.bulk-msg');
  var pairs = [];
  b.querySelectorAll('.item-event').forEach(function(s){
    if (s.value) pairs.push({item: parseInt(s.dataset.item), event: parseInt(s.value)});
  });
  if (!pairs.length) { msg.textContent = 'nada para salvar'; msg.style.color = 'var(--mt)'; return; }

  btn.disabled = true; msg.textContent = 'salvando…'; msg.style.color = 'var(--mt)';
  try {
    var res = await fetch('paid_transactions.php?ajax=assign', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({pairs: pairs})
    });
    var d = await res.json();
    if (!d.success) throw new Error(d.error || 'erro');
    msg.textContent = '✅ ' + d.saved + ' vinculada(s)';
    msg.style.color = 'var(--gn, #34d399)';
    b.querySelectorAll('.item-event').forEach(function(s){
      if (s.value) {
        var nome = s.options[s.selectedIndex].text;
        var td = s.parentNode;
        td.innerHTML = '<span class="ev">→ ' + nome + '</span> <span style="font-size:10px;color:var(--mt)">(manual)</span>';
      }
    });
    var bulk = b.querySelector('.tx-bulk');
    if (!b.querySelector('.item-event')) setTimeout(function(){ bulk.style.display = 'none'; }, 1200);
  } catch (e) {
    msg.textContent = '❌ erro ao salvar'; msg.style.color = '#f87171';
  }
  btn.disabled = false;
}
</script>
</body>
</html>
