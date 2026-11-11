<?php
// refunds.php — Controle de reembolso das cobranças da Meta, por evento.
// Acesso: admin, financeiro e chefe (dono).
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();
$role = Auth::getRole();
if (!in_array($role, ['admin', 'financeiro', 'chefe'])) { header('Location: index.php'); exit; }
$isAdmin = Auth::isAdmin();
$uid = Auth::getUserId();
$db  = Database::getInstance();

$UPLOAD_DIR = dirname(__DIR__) . '/refund_receipts'; // fora do public_html

// Eventos que este usuário pode ver (mesma regra do resto do sistema).
// Admin vê todos; demais só os liberados em event_user_access.
$allowedEventIds = null; // null = todos
if (!$isAdmin) {
    $allowedEventIds = array_map('intval', array_column(
        $db->fetchAll("SELECT event_id FROM event_user_access WHERE user_id = ?", [$uid]), 'event_id'));
}
function canSeeEvent($eventId, $allowedEventIds) {
    return $allowedEventIds === null || in_array((int)$eventId, $allowedEventIds, true);
}
$message = ''; $msgType = 'success';

/**
 * Valor bruto de uma linha de cobrança: a parte da mídia + o imposto proporcional.
 * É o que efetivamente saiu do cartão por conta daquele evento.
 */
function itemGross($item) {
    $sub = (float)$item['subtotal'];
    $tx  = (float)$item['taxes'];
    if ($sub <= 0) return (float)$item['value'];
    return round((float)$item['value'] * (1 + $tx / $sub), 2);
}

// ── AÇÕES ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Registrar reembolso recebido
    if (isset($_POST['add_refund'])) {
        $eventId = (int)($_POST['event_id'] ?? 0) ?: null;
        $amount  = (float)str_replace(',', '.', str_replace('.', '', $_POST['amount'] ?? '0'));
        $date    = $_POST['refund_date'] ?? date('Y-m-d');

        if ($amount <= 0) {
            $message = '❌ Informe o valor reembolsado.'; $msgType = 'error';
        } elseif ($eventId && !canSeeEvent($eventId, $allowedEventIds)) {
            $message = '❌ Você não tem acesso a este evento.'; $msgType = 'error';
        } else {
            // Comprovante (opcional): arquivo em pasta protegida ou link
            $file = null;
            if (!empty($_FILES['receipt']['name']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf','jpg','jpeg','png','webp'])) {
                    $message = '❌ Comprovante deve ser PDF, JPG, PNG ou WEBP.'; $msgType = 'error';
                } elseif ($_FILES['receipt']['size'] > 8 * 1024 * 1024) {
                    $message = '❌ Comprovante acima de 8MB.'; $msgType = 'error';
                } else {
                    if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0750, true);
                    $file = 'rcp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $UPLOAD_DIR . '/' . $file)) {
                        $file = null;
                        $message = '⚠️ Reembolso salvo, mas o comprovante não pôde ser gravado.'; $msgType = 'error';
                    }
                }
            }

            if ($msgType !== 'error' || $file !== null || empty($_FILES['receipt']['name'])) {
                $refundId = $db->insert(
                    "INSERT INTO refunds (event_id, amount, refund_date, method, receipt_url, receipt_file, notes, created_by)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [$eventId, $amount, $date, $_POST['method'] ?? null,
                     trim($_POST['receipt_url'] ?? '') ?: null, $file,
                     trim($_POST['notes'] ?? '') ?: null, $uid]);

                // Quita automaticamente as cobranças em aberto do evento, da mais antiga
                // para a mais recente (FIFO), até o valor recebido acabar.
                $restante = $amount; $quitadas = 0;
                if ($eventId) {
                    $rows = $db->fetchAll("
                        SELECT i.id, i.value, t.subtotal, t.taxes, t.paid_at,
                               COALESCE((SELECT SUM(a.amount) FROM refund_allocations a WHERE a.item_id = i.id), 0) AS pago
                        FROM paid_transaction_items i
                        JOIN paid_transactions t ON t.transaction_id = i.transaction_id AND t.status = 'Pago'
                        WHERE i.event_id = ? AND i.refund_exempt = 0
                        ORDER BY t.paid_at ASC, i.id ASC", [$eventId]);
                    foreach ($rows as $r) {
                        if ($restante <= 0.001) break;
                        $aberto = round(itemGross($r) - (float)$r['pago'], 2);
                        if ($aberto <= 0.001) continue;
                        $usar = min($aberto, $restante);
                        $db->execute("INSERT INTO refund_allocations (refund_id, item_id, amount) VALUES (?,?,?)",
                            [$refundId, $r['id'], $usar]);
                        $restante = round($restante - $usar, 2);
                        if ($usar >= $aberto - 0.001) $quitadas++;
                    }
                }
                $det = $quitadas ? " {$quitadas} cobrança" . ($quitadas > 1 ? 's' : '') . ' quitada' . ($quitadas > 1 ? 's' : '') . '.' : '';
                if (!$eventId) $det = ' Sem evento selecionado — lançado como crédito avulso.';
                $sobra = $restante > 0.009 ? ' Sobrou R$ ' . number_format($restante, 2, ',', '.') . ' como crédito para as próximas cobranças.' : '';
                $message = '✅ Reembolso de R$ ' . number_format($amount, 2, ',', '.') . ' registrado.' . $det . $sobra;
                $msgType = 'success';
            }
        }
    }

    // Remover reembolso
    elseif (isset($_POST['del_refund'])) {
        $rid = (int)$_POST['del_refund'];
        $r = $db->fetch("SELECT * FROM refunds WHERE id=?", [$rid]);
        if ($r && ($isAdmin || ((int)$r['created_by'] === $uid && canSeeEvent($r['event_id'], $allowedEventIds)))) {
            if ($r['receipt_file']) @unlink($UPLOAD_DIR . '/' . $r['receipt_file']);
            $db->execute("DELETE FROM refunds WHERE id=?", [$rid]);
            $message = '✅ Reembolso removido.';
        } else {
            $message = '❌ Sem permissão para remover este reembolso.'; $msgType = 'error';
        }
    }

    // Marcar/desmarcar cobrança como "não reembolsável"
    elseif (isset($_POST['toggle_exempt'])) {
        $db->execute("UPDATE paid_transaction_items SET refund_exempt = IF(refund_exempt=1,0,1) WHERE id=?",
            [(int)$_POST['toggle_exempt']]);
        $message = '✅ Cobrança atualizada.';
    }
}

// ── DADOS ────────────────────────────────────────────────────
$tab     = $_GET['tab'] ?? 'aberto';            // aberto | quitado
if (!in_array($tab, ['aberto', 'quitado'])) $tab = 'aberto';
$fEvent  = (int)($_GET['event'] ?? 0);
$fStatus = $_GET['s'] ?? ($tab === 'quitado' ? 'quitado' : 'pendente');
$janela  = $_GET['j'] ?? '90';                  // 90 | 180 | 365 | tudo (só na aba quitados)
if (!in_array($janela, ['90','180','365','tudo'])) $janela = '90';
$busca   = trim($_GET['q'] ?? '');
$pag     = max(1, (int)($_GET['pg'] ?? 1));
$porPag  = 60;

$escopo = [];
if ($allowedEventIds !== null) {
    $escopo[] = $allowedEventIds ? 'i.event_id IN (' . implode(',', $allowedEventIds) . ')' : '0';
}
$escopoSql = $escopo ? ' AND ' . implode(' AND ', $escopo) : '';

// ── Agregado por evento (uma query, sem laço no PHP) ──────────
$agg = $db->fetchAll("
    SELECT i.event_id,
           e.name AS event_name,
           SUM(i.value * (1 + IFNULL(t.taxes,0) / NULLIF(t.subtotal,0))) AS bruto,
           COALESCE(SUM((SELECT SUM(a.amount) FROM refund_allocations a WHERE a.item_id = i.id)), 0) AS pago,
           COUNT(*) AS cobrancas,
           MAX(t.paid_at) AS ultima_cobranca,
           (SELECT MAX(r2.refund_date) FROM refunds r2 WHERE r2.event_id = i.event_id) AS ultimo_reembolso
    FROM paid_transaction_items i
    JOIN paid_transactions t ON t.transaction_id = i.transaction_id AND t.status = 'Pago'
    LEFT JOIN events e ON e.id = i.event_id
    WHERE i.event_id IS NOT NULL AND i.refund_exempt = 0 {$escopoSql}
    GROUP BY i.event_id, e.name");

$totCobrado = 0; $totPago = 0;
$evAberto = []; $evQuitado = [];
$limiteDias = $janela === 'tudo' ? null : (int)$janela;
$cortadosPorTempo = 0;

foreach ($agg as $r) {
    $bruto = round((float)$r['bruto'], 2);
    $pago  = round((float)$r['pago'], 2);
    $ab    = round($bruto - $pago, 2);
    $totCobrado += $bruto; $totPago += $pago;

    $row = [
        'id' => (int)$r['event_id'], 'name' => $r['event_name'] ?: 'Evento #' . $r['event_id'],
        'bruto' => $bruto, 'pago' => $pago, 'aberto' => $ab, 'cobrancas' => (int)$r['cobrancas'],
        'pct' => $bruto > 0 ? min(100, round($pago / $bruto * 100)) : 0,
        'quitado_em' => $r['ultimo_reembolso'],
    ];
    if ($ab > 0.009) { $evAberto[] = $row; continue; }

    // Quitado: aplica janela de tempo e busca
    $ref = $r['ultimo_reembolso'] ?: $r['ultima_cobranca'];
    $dias = $ref ? (int)floor((time() - strtotime($ref)) / 86400) : 9999;
    if ($busca !== '' && mb_stripos($row['name'], $busca) === false) continue;
    if ($limiteDias !== null && $dias > $limiteDias && $busca === '') { $cortadosPorTempo++; continue; }
    $row['dias'] = $dias;
    $evQuitado[] = $row;
}
usort($evAberto,  function($a, $b) { return $b['aberto'] <=> $a['aberto']; });
usort($evQuitado, function($a, $b) { return strcmp((string)$b['quitado_em'], (string)$a['quitado_em']); });

// ── Cobranças (paginadas) ────────────────────────────────────
$where  = ["t.status = 'Pago'", "i.event_id IS NOT NULL", "i.refund_exempt = 0"];
$params = [];
if ($fEvent) { $where[] = 'i.event_id = ?'; $params[] = $fEvent; }
if ($allowedEventIds !== null) {
    $where[] = $allowedEventIds ? 'i.event_id IN (' . implode(',', $allowedEventIds) . ')' : '0';
}
$havingSql = '';
if ($fStatus === 'pendente') $havingSql = 'HAVING aberto > 0.009';
elseif ($fStatus === 'quitado') $havingSql = 'HAVING aberto <= 0.009';

$sqlBase = "
    FROM paid_transaction_items i
    JOIN paid_transactions t ON t.transaction_id = i.transaction_id
    LEFT JOIN events e ON e.id = i.event_id
    WHERE " . implode(' AND ', $where);

$totLinhas = (int)($db->fetch("
    SELECT COUNT(*) c FROM (
      SELECT i.id,
             ROUND(i.value * (1 + IFNULL(t.taxes,0)/NULLIF(t.subtotal,0))
                   - COALESCE((SELECT SUM(a.amount) FROM refund_allocations a WHERE a.item_id = i.id),0), 2) AS aberto
      {$sqlBase} GROUP BY i.id {$havingSql}
    ) x", $params)['c'] ?? 0);

$offset = ($pag - 1) * $porPag;
$linhas = $db->fetchAll("
    SELECT i.id, i.event_id, i.campaign_name, i.value,
           t.paid_at, t.subtotal, t.taxes, e.name AS event_name,
           COALESCE((SELECT SUM(a.amount) FROM refund_allocations a WHERE a.item_id = i.id), 0) AS pago,
           ROUND(i.value * (1 + IFNULL(t.taxes,0)/NULLIF(t.subtotal,0)), 2) AS bruto,
           ROUND(i.value * (1 + IFNULL(t.taxes,0)/NULLIF(t.subtotal,0))
                 - COALESCE((SELECT SUM(a.amount) FROM refund_allocations a WHERE a.item_id = i.id),0), 2) AS aberto
    {$sqlBase}
    GROUP BY i.id {$havingSql}
    ORDER BY t.paid_at DESC, i.value DESC
    LIMIT {$porPag} OFFSET {$offset}", $params);
foreach ($linhas as &$l) {
    $l['_bruto'] = (float)$l['bruto']; $l['_pago'] = (float)$l['pago'];
    $l['_aberto'] = (float)$l['aberto']; $l['_quitado'] = $l['_aberto'] <= 0.009;
} unset($l);
$totPaginas = max(1, (int)ceil($totLinhas / $porPag));

// ── Reembolsos por evento (para a aba de quitados) ───────────
$refundsPorEvento = [];
foreach ($db->fetchAll("
    SELECT r.*, u.username FROM refunds r LEFT JOIN users u ON u.id = r.created_by
    " . ($allowedEventIds === null ? '' :
        ($allowedEventIds ? 'WHERE r.event_id IN (' . implode(',', $allowedEventIds) . ')' : 'WHERE 0')) . "
    ORDER BY r.refund_date DESC, r.id DESC") as $r) {
    $refundsPorEvento[(int)$r['event_id']][] = $r;
}

$refunds = $db->fetchAll("
    SELECT r.*, e.name AS event_name, u.username
    FROM refunds r
    LEFT JOIN events e ON e.id = r.event_id
    LEFT JOIN users u ON u.id = r.created_by
    " . (function() use ($fEvent, $allowedEventIds) {
            $w = [];
            if ($fEvent) $w[] = 'r.event_id = ' . (int)$fEvent;
            if ($allowedEventIds !== null) {
                $w[] = $allowedEventIds ? 'r.event_id IN (' . implode(',', $allowedEventIds) . ')' : '0';
            }
            return $w ? 'WHERE ' . implode(' AND ', $w) : '';
        })() . "
    ORDER BY r.refund_date DESC, r.id DESC LIMIT 40");

$eventos = $allowedEventIds === null
    ? $db->fetchAll("SELECT id, name FROM events ORDER BY name")
    : ($allowedEventIds
        ? $db->fetchAll("SELECT id, name FROM events WHERE id IN (" . implode(',', $allowedEventIds) . ") ORDER BY name")
        : []);
function qsr($over = []) { return http_build_query(array_merge($_GET, $over)); }
$brl = function($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); };

// Em aberto por evento (para o resumo do formulário), independente dos filtros da tela
$abertoPorEvento = [];
foreach ($db->fetchAll("
    SELECT i.event_id,
           SUM(i.value * (1 + IFNULL(t.taxes,0) / NULLIF(t.subtotal,0))) AS bruto,
           COALESCE(SUM((SELECT SUM(a.amount) FROM refund_allocations a WHERE a.item_id = i.id)), 0) AS pago,
           COUNT(*) AS n
    FROM paid_transaction_items i
    JOIN paid_transactions t ON t.transaction_id = i.transaction_id AND t.status = 'Pago'
    WHERE i.event_id IS NOT NULL AND i.refund_exempt = 0
      " . ($allowedEventIds === null ? '' : ($allowedEventIds ? 'AND i.event_id IN (' . implode(',', $allowedEventIds) . ')' : 'AND 0')) . "
    GROUP BY i.event_id") as $r) {
    $abertoPorEvento[(int)$r['event_id']] = [
        'aberto' => round((float)$r['bruto'] - (float)$r['pago'], 2),
        'n'      => (int)$r['n'],
    ];
}
$totAberto = $totCobrado - $totPago;
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reembolsos — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:22px}
.kpi{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:15px 17px}
.kpi .lb{font-size:10px;color:var(--mt);text-transform:uppercase;letter-spacing:.06em}
.kpi .vl{font-size:22px;font-weight:800;margin-top:3px}
.ev-row{display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid var(--bd);font-size:13px}
.bar{flex:1;height:7px;background:var(--s2);border-radius:4px;overflow:hidden;min-width:60px}
.bar span{display:block;height:100%;background:var(--gn,#34d399)}
.chg{display:grid;grid-template-columns:96px 1fr 118px 118px 118px 120px;gap:10px;align-items:center;
     padding:9px 0;border-bottom:1px solid var(--bd);font-size:12px}
.chg .mut{color:var(--mt);font-size:11px}
.pill{font-size:10.5px;font-weight:700;padding:2px 9px;border-radius:20px;white-space:nowrap}
.p-open{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#f87171}
.p-part{background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3);color:#fbbf24}
.p-done{background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.3);color:#34d399}
@media(max-width:860px){.chg{grid-template-columns:1fr 1fr;row-gap:3px}.chg .hide-m{display:none}}
</style>
</head>
<body>
<div class="app-wrap">
<?php $currentPage = 'refunds'; require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div style="margin-bottom:18px">
    <h1 class="page-title">💸 Reembolsos</h1>
    <p class="page-sub">Cobranças da Meta por evento e o que já voltou do cliente</p>
  </div>

  <?php if ($allowedEventIds !== null && !$allowedEventIds): ?>
  <div style="padding:12px 16px;border-radius:12px;margin-bottom:18px;background:rgba(245,166,35,.08);
       border:1px solid rgba(245,166,35,.25);color:var(--yw);font-size:13px">
    Você ainda não tem nenhum evento liberado. Peça ao administrador para dar acesso na tela de Usuários.
  </div>
  <?php endif; ?>

  <?php if ($message): ?>
  <div class="alert alert-<?= $msgType ?>" style="padding:12px 16px;border-radius:12px;margin-bottom:18px;
       background:<?= $msgType==='error'?'rgba(248,113,113,.08)':'rgba(52,211,153,.08)' ?>;
       border:1px solid <?= $msgType==='error'?'rgba(248,113,113,.3)':'rgba(52,211,153,.3)' ?>;
       color:<?= $msgType==='error'?'#f87171':'#34d399' ?>"><?= $message ?></div>
  <?php endif; ?>

  <div class="kpis">
    <div class="kpi"><div class="lb">Cobrado pela Meta</div><div class="vl"><?= $brl($totCobrado) ?></div>
      <div class="mut" style="font-size:11px;color:var(--mt)">valor cheio, com impostos</div></div>
    <div class="kpi"><div class="lb">Já reembolsado</div><div class="vl" style="color:var(--gn,#34d399)"><?= $brl($totPago) ?></div>
      <div style="font-size:11px;color:var(--mt)"><?= $totCobrado>0 ? number_format($totPago/$totCobrado*100,1,',','.') : 0 ?>% do total</div></div>
    <div class="kpi"><div class="lb">Pendente de reembolso</div><div class="vl" style="color:<?= $totAberto>0.01?'#f87171':'var(--gn,#34d399)' ?>"><?= $brl($totAberto) ?></div>
      <div style="font-size:11px;color:var(--mt)"><?= count(array_filter($linhas, fn($l)=>!$l['_quitado'])) ?> cobrança(s) em aberto</div></div>
  </div>

  <!-- ABAS -->
  <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center">
    <a href="?tab=aberto" style="padding:7px 15px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;
       border:1px solid <?= $tab==='aberto'?'var(--ac)':'var(--bd)' ?>;background:<?= $tab==='aberto'?'var(--ac)':'transparent' ?>;color:<?= $tab==='aberto'?'#fff':'var(--mt2)' ?>">
       🔴 Em aberto <span style="opacity:.75"><?= count($evAberto) ?></span></a>
    <a href="?tab=quitado" style="padding:7px 15px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;
       border:1px solid <?= $tab==='quitado'?'var(--ac)':'var(--bd)' ?>;background:<?= $tab==='quitado'?'var(--ac)':'transparent' ?>;color:<?= $tab==='quitado'?'#fff':'var(--mt2)' ?>">
       ✅ Quitados <span style="opacity:.75"><?= count($evQuitado) ?></span></a>
  </div>

  <?php if ($tab === 'aberto'): ?>
  <div class="card" style="margin-bottom:20px">
    <div class="card-title">📊 Eventos com valor a receber</div>
    <?php foreach ($evAberto as $pe): ?>
    <div class="ev-row">
      <a href="?tab=aberto&event=<?= $pe['id'] ?>&s=pendente" style="flex:0 0 210px;color:var(--tx);text-decoration:none;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">🎪 <?= htmlspecialchars($pe['name']) ?></a>
      <span class="bar"><span style="width:<?= $pe['pct'] ?>%"></span></span>
      <span style="width:46px;text-align:right;color:var(--mt);font-size:11px"><?= $pe['pct'] ?>%</span>
      <span style="width:150px;text-align:right"><?= $brl($pe['pago']) ?> <span style="color:var(--mt)">/ <?= $brl($pe['bruto']) ?></span></span>
      <span style="width:130px;text-align:right;font-weight:700;color:#f87171"><?= $brl($pe['aberto']) ?> aberto</span>
    </div>
    <?php endforeach; ?>
    <?php if (!$evAberto): ?><div style="color:var(--gn,#34d399);font-size:14px;padding:24px 0;text-align:center">🎉 Tudo reembolsado! Nenhum evento com valor a receber.</div><?php endif; ?>
  </div>

  <?php else: /* ══ QUITADOS ══ */ ?>
  <div class="card" style="margin-bottom:20px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
      <div class="card-title" style="margin:0">✅ Eventos quitados</div>
      <form method="GET" style="display:flex;gap:7px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="tab" value="quitado">
        <input type="text" name="q" value="<?= htmlspecialchars($busca) ?>" placeholder="buscar evento…" style="width:150px;font-size:12px;padding:4px 9px">
        <select name="j" onchange="this.form.submit()" style="width:auto;font-size:12px;padding:4px 8px">
          <?php foreach (['90'=>'últimos 90 dias','180'=>'últimos 6 meses','365'=>'último ano','tudo'=>'todos'] as $k=>$lb): ?>
          <option value="<?= $k ?>" <?= $janela===$k?'selected':'' ?>><?= $lb ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-o btn-sm" style="font-size:12px;padding:4px 10px">Buscar</button>
      </form>
    </div>

    <?php foreach ($evQuitado as $pe): ?>
    <div style="border-bottom:1px solid var(--bd);padding:10px 0">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <span style="flex:0 0 210px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">🎪 <?= htmlspecialchars($pe['name']) ?></span>
        <span style="flex:1;font-size:12px;color:var(--mt)"><?= $pe['cobrancas'] ?> cobrança(s) · <?= $brl($pe['bruto']) ?></span>
        <span class="pill p-done">quitado<?= isset($pe['dias']) && $pe['dias'] < 9000 ? ' há ' . $pe['dias'] . 'd' : '' ?></span>
      </div>
      <?php $rl = $refundsPorEvento[$pe['id']] ?? []; if ($rl): ?>
      <div style="margin-top:6px;padding-left:14px;border-left:2px solid var(--bd)">
        <?php foreach ($rl as $r): ?>
        <div style="display:flex;align-items:center;gap:9px;font-size:11.5px;color:var(--mt);padding:2px 0;flex-wrap:wrap">
          <span><?= date('d/m/Y', strtotime($r['refund_date'])) ?></span>
          <b style="color:var(--gn,#34d399)"><?= $brl($r['amount']) ?></b>
          <span><?= htmlspecialchars((string)$r['method']) ?></span>
          <?php if ($r['receipt_file']): ?><a href="refund_receipt.php?id=<?= $r['id'] ?>" target="_blank" style="color:var(--ac);text-decoration:none">📎 comprovante</a><?php endif; ?>
          <?php if ($r['receipt_url']): ?><a href="<?= htmlspecialchars($r['receipt_url']) ?>" target="_blank" style="color:var(--ac);text-decoration:none">🔗 link</a><?php endif; ?>
          <span>por <?= htmlspecialchars((string)$r['username']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!$evQuitado): ?>
    <div style="color:var(--mt);font-size:13px;padding:24px 0;text-align:center">
      <?= $busca !== '' ? 'Nenhum evento quitado com esse nome.' : 'Nenhum evento quitado nesse período.' ?>
    </div>
    <?php endif; ?>
    <?php if ($cortadosPorTempo > 0): ?>
    <div style="font-size:12px;color:var(--mt);padding-top:10px">
      +<?= $cortadosPorTempo ?> evento(s) quitado(s) fora deste período ·
      <a href="?<?= qsr(['j'=>'tudo']) ?>" style="color:var(--ac);text-decoration:none">ver todos</a>
      · ou busque pelo nome acima
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- REGISTRAR REEMBOLSO -->
  <?php if ($tab === 'aberto'): ?>
  <div class="card" style="margin-bottom:20px">
    <div class="card-title">➕ Registrar reembolso recebido</div>
    <form method="POST" enctype="multipart/form-data">
      <?= Auth::csrfField() ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:12px">
        <div><label style="font-size:11px;color:var(--mt);display:block;margin-bottom:3px">Evento</label>
          <select name="event_id" id="refEvent" onchange="atualizarResumo()">
            <option value="">— selecione —</option>
            <?php foreach ($eventos as $ev): ?><option value="<?= $ev['id'] ?>" <?= $fEvent==$ev['id']?'selected':'' ?>><?= htmlspecialchars($ev['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label style="font-size:11px;color:var(--mt);display:block;margin-bottom:3px">Valor reembolsado *</label>
          <input type="text" name="amount" id="refAmount" placeholder="2.000,00" required></div>
        <div><label style="font-size:11px;color:var(--mt);display:block;margin-bottom:3px">Data *</label>
          <input type="date" name="refund_date" value="<?= date('Y-m-d') ?>" required></div>
        <div><label style="font-size:11px;color:var(--mt);display:block;margin-bottom:3px">Forma</label>
          <select name="method"><option value="PIX">PIX</option><option value="Transferência">Transferência</option><option value="Dinheiro">Dinheiro</option><option value="Outro">Outro</option></select></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:12px">
        <div><label style="font-size:11px;color:var(--mt);display:block;margin-bottom:3px">Comprovante (opcional)</label>
          <input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
        <div><label style="font-size:11px;color:var(--mt);display:block;margin-bottom:3px">…ou link do comprovante</label>
          <input type="text" name="receipt_url" placeholder="https://drive.google.com/..."></div>
        <div><label style="font-size:11px;color:var(--mt);display:block;margin-bottom:3px">Observação</label>
          <input type="text" name="notes" placeholder="opcional"></div>
      </div>
      <div id="refResumo" style="display:none;align-items:center;gap:12px;flex-wrap:wrap;padding:11px 14px;margin-bottom:12px;
           border-radius:10px;background:var(--s2);border:1px solid var(--bd);font-size:13px">
        <span id="refResumoTxt"></span>
        <button type="button" class="btn btn-o btn-sm" id="refUsarTudo" style="font-size:11.5px;padding:4px 10px">usar valor total</button>
      </div>
      <div style="font-size:11px;color:var(--mt);margin-bottom:12px">
        As cobranças são quitadas automaticamente, da mais antiga para a mais recente. Se o valor passar do que está em aberto, a diferença fica como crédito para as próximas cobranças do evento.
      </div>
      <button type="submit" name="add_refund" value="1" class="btn btn-p">💸 Registrar reembolso</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- COBRANÇAS -->
  <div class="card" style="margin-bottom:20px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
      <div class="card-title" style="margin:0">🧾 Cobranças</div>
      <div style="display:flex;gap:6px">
        <?php foreach (['pendente'=>'Pendentes','quitado'=>'Quitadas','todos'=>'Todas'] as $k=>$lb): ?>
        <a href="?<?= qsr(['s'=>$k,'pg'=>1]) ?>" style="padding:4px 11px;border-radius:8px;font-size:11.5px;font-weight:600;text-decoration:none;
           border:1px solid <?= $fStatus===$k?'var(--ac)':'var(--bd)' ?>;background:<?= $fStatus===$k?'var(--ac)':'transparent' ?>;color:<?= $fStatus===$k?'#fff':'var(--mt2)' ?>"><?= $lb ?></a>
        <?php endforeach; ?>
        <?php if ($fEvent): ?><a href="?<?= qsr(['event'=>0,'pg'=>1]) ?>" class="btn btn-o btn-sm" style="font-size:11.5px;padding:4px 10px">Limpar evento</a><?php endif; ?>
      </div>
    </div>
    <div class="chg" style="font-weight:700;color:var(--mt);font-size:10.5px;text-transform:uppercase;letter-spacing:.05em">
      <span>Data</span><span>Evento / campanha</span><span style="text-align:right">Cobrado</span>
      <span style="text-align:right">Reembolsado</span><span style="text-align:right">Em aberto</span><span style="text-align:right">Status</span>
    </div>
    <?php foreach ($linhas as $l):
      $parcial = $l['_pago'] > 0.009 && !$l['_quitado']; ?>
    <div class="chg">
      <span class="mut"><?= date('d/m/Y', strtotime($l['paid_at'])) ?></span>
      <span style="min-width:0"><b><?= htmlspecialchars($l['event_name']) ?></b>
        <span class="mut hide-m"> · <?= htmlspecialchars(mb_substr($l['campaign_name'], 0, 40)) ?></span></span>
      <span style="text-align:right"><?= $brl($l['_bruto']) ?></span>
      <span style="text-align:right;color:var(--gn,#34d399)"><?= $l['_pago']>0 ? $brl($l['_pago']) : '—' ?></span>
      <span style="text-align:right;font-weight:700;color:<?= $l['_aberto']>0.01?'#f87171':'var(--mt)' ?>"><?= $l['_aberto']>0.01 ? $brl($l['_aberto']) : '—' ?></span>
      <span style="text-align:right;display:flex;gap:5px;justify-content:flex-end;align-items:center">
        <span class="pill <?= $l['_quitado']?'p-done':($parcial?'p-part':'p-open') ?>"><?= $l['_quitado']?'reembolsado':($parcial?'parcial':'pendente') ?></span>
        <?php if ($isAdmin): ?>
        <form method="POST" style="display:inline"><?= Auth::csrfField() ?>
          <button type="submit" name="toggle_exempt" value="<?= $l['id'] ?>" title="Marcar como não reembolsável (mídia própria)"
                  style="background:none;border:none;color:var(--mt);cursor:pointer;font-size:12px">🚫</button>
        </form>
        <?php endif; ?>
      </span>
    </div>
    <?php endforeach; ?>
    <?php if (!$linhas): ?><div style="text-align:center;padding:30px;color:var(--mt);font-size:13px">Nenhuma cobrança <?= $fStatus==='pendente'?'pendente':'' ?> com esses filtros. 🎉</div><?php endif; ?>
    <?php if ($totPaginas > 1): ?>
    <div style="display:flex;justify-content:center;align-items:center;gap:10px;margin-top:14px;font-size:12px">
      <?php if ($pag > 1): ?><a href="?<?= qsr(['pg'=>$pag-1]) ?>" class="btn btn-o btn-sm" style="padding:4px 11px">← Anterior</a><?php endif; ?>
      <span style="color:var(--mt)">Página <?= $pag ?> de <?= $totPaginas ?> · <?= number_format($totLinhas,0,',','.') ?> cobranças</span>
      <?php if ($pag < $totPaginas): ?><a href="?<?= qsr(['pg'=>$pag+1]) ?>" class="btn btn-o btn-sm" style="padding:4px 11px">Próxima →</a><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- HISTÓRICO -->
  <?php if ($tab === 'aberto'): ?>
  <div class="card">
    <div class="card-title">📜 Reembolsos registrados</div>
    <?php foreach ($refunds as $r): ?>
    <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--bd);font-size:12.5px;flex-wrap:wrap">
      <span style="width:82px;color:var(--mt);font-size:11px"><?= date('d/m/Y', strtotime($r['refund_date'])) ?></span>
      <b style="width:110px;color:var(--gn,#34d399)"><?= $brl($r['amount']) ?></b>
      <span style="flex:1;min-width:120px">🎪 <?= htmlspecialchars($r['event_name'] ?: '—') ?>
        <span style="color:var(--mt)"> · <?= htmlspecialchars((string)$r['method']) ?></span>
        <?php if ($r['notes']): ?><span style="color:var(--mt)"> · <?= htmlspecialchars($r['notes']) ?></span><?php endif; ?></span>
      <?php if ($r['receipt_file']): ?><a href="refund_receipt.php?id=<?= $r['id'] ?>" target="_blank" style="color:var(--ac);text-decoration:none">📎 comprovante</a><?php endif; ?>
      <?php if ($r['receipt_url']): ?><a href="<?= htmlspecialchars($r['receipt_url']) ?>" target="_blank" style="color:var(--ac);text-decoration:none">🔗 link</a><?php endif; ?>
      <span style="color:var(--mt);font-size:11px">por <?= htmlspecialchars((string)$r['username']) ?></span>
      <?php if ($isAdmin || (int)$r['created_by'] === $uid): ?>
      <form method="POST" style="display:inline" onsubmit="return confirm('Remover este reembolso?')"><?= Auth::csrfField() ?>
        <button type="submit" name="del_refund" value="<?= $r['id'] ?>" class="btn btn-d btn-sm" style="padding:2px 8px">🗑️</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (!$refunds): ?><div style="color:var(--mt);font-size:13px;padding:10px 0">Nenhum reembolso registrado ainda.</div><?php endif; ?>
  </div>
  <?php endif; ?>

</main>
</div>
<script>
var ABERTO = <?= json_encode($abertoPorEvento) ?>;

function brl(v) { return 'R$ ' + Number(v).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}); }

function atualizarResumo() {
  var ev  = document.getElementById('refEvent').value;
  var box = document.getElementById('refResumo');
  var txt = document.getElementById('refResumoTxt');
  var btn = document.getElementById('refUsarTudo');
  if (!ev || !ABERTO[ev]) {
    box.style.display = 'none';
    return;
  }
  var d = ABERTO[ev];
  if (d.aberto <= 0.01) {
    txt.innerHTML = '<span style="color:var(--gn,#34d399);font-weight:600">✅ Este evento já está quitado.</span> Um novo lançamento entra como crédito.';
    btn.style.display = 'none';
  } else {
    txt.innerHTML = 'Em aberto neste evento: <b style="color:#f87171;font-size:15px">' + brl(d.aberto) + '</b>'
      + ' <span style="color:var(--mt)">· ' + d.n + ' cobrança' + (d.n != 1 ? 's' : '') + ' da Meta</span>';
    btn.style.display = '';
    btn.onclick = function() {
      document.getElementById('refAmount').value = Number(d.aberto).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
      document.getElementById('refAmount').focus();
    };
  }
  box.style.display = 'flex';
}

document.addEventListener('DOMContentLoaded', atualizarResumo);
</script>
</body>
</html>
