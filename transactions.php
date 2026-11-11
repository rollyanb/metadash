<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();
if (!in_array(Auth::getRole(), ['admin','chefe','financeiro'])) { header('Location: index.php'); exit; }

$db      = Database::getInstance();
$isAdmin = Auth::isAdmin();
$role    = Auth::getRole();
$uid     = Auth::getUserId();

$allowedAccountIds = null;
if (!$isAdmin) {
    $individual = $db->fetchAll("SELECT account_id FROM user_account_permissions WHERE user_id=?", [$uid]);
    if (!empty($individual)) {
        $allowedAccountIds = array_column($individual, 'account_id');
    } else {
        $companyAccounts = $db->fetchAll("
            SELECT ca.account_id FROM company_user_access cu
            JOIN company_accounts ca ON ca.company_id = cu.company_id
            WHERE cu.user_id = ?
        ", [$uid]);
        $allowedAccountIds = array_unique(array_column($companyAccounts, 'account_id'));
    }
    if (isset($_GET['debug'])) {
        header('Content-Type: application/json');
        echo json_encode(['uid'=>$uid,'role'=>$role,'allowedAccountIds'=>$allowedAccountIds], JSON_PRETTY_PRINT);
        exit;
    }
}

$dateEnd        = $_GET['date_end']       ?? date('Y-m-d');
$dateStart      = $_GET['date_start']     ?? date('Y-m-d', strtotime('-30 days'));
$cardFilter     = $_GET['card']           ?? '';
$accountFilter  = $_GET['account']        ?? '';
$searchId       = trim($_GET['search_id']     ?? '');
$searchAmount   = trim($_GET['search_amount'] ?? '');
$statusFilter   = $_GET['status']         ?? '';
$platformFilter = $_GET['platform']       ?? '';
$eventFilter    = (int)($_GET['event'] ?? 0);

$where  = "WHERE 1=1";
$params = [];

$hasSearch = $searchId !== '' || $searchAmount !== '';
if (!$hasSearch) {
    $where  .= " AND t.transaction_date BETWEEN ? AND ?";
    $params  = [$dateStart.' 00:00:00', $dateEnd.' 23:59:59'];
}

if ($allowedAccountIds !== null) {
    if (empty($allowedAccountIds)) { $where .= " AND 1=0"; }
    else {
        $ph = implode(',', array_fill(0, count($allowedAccountIds), '?'));
        $where .= " AND t.account_id IN ({$ph})";
        $params = array_merge($params, $allowedAccountIds);
    }
}
if ($cardFilter)     { $where .= " AND t.card_last4 = ?";        $params[] = $cardFilter; }
if ($accountFilter)  { $where .= " AND t.account_id = ?";        $params[] = $accountFilter; }
if ($statusFilter === 'DEPOSITO') {
    // Depósitos de crédito na conta (PIX/boleto)
    $where .= " AND t.status = 'FUNDED'";
} elseif ($statusFilter === 'SALDO') {
    // Cobranças debitadas do saldo (sem cartão)
    $where .= " AND t.status = 'SETTLED' AND (t.card_last4 IS NULL OR t.card_last4 = ''
                       OR UPPER(TRIM(COALESCE(t.card_display,''))) IN ('N/D','ND','N/A','-',''))";
} elseif ($statusFilter === 'CARTAO') {
    $where .= " AND t.status = 'SETTLED' AND t.card_last4 IS NOT NULL AND t.card_last4 <> ''
                       AND UPPER(TRIM(COALESCE(t.card_display,''))) NOT IN ('N/D','ND','N/A','-')";
} elseif ($statusFilter) {
    $where .= " AND t.status = ?"; $params[] = $statusFilter;
}
if ($platformFilter) { $where .= " AND t.platform = ?";          $params[] = $platformFilter; }
if ($searchId)       { $where .= " AND t.transaction_id LIKE ?"; $params[] = '%'.$searchId.'%'; }
if ($searchAmount) {
    $amountSearch = str_replace(',', '.', $searchAmount);
    if (substr_count($amountSearch, '.') > 1) {
        $amountSearch = str_replace('.', '', substr($amountSearch, 0, strrpos($amountSearch, '.')))
                       .'.'.substr($amountSearch, strrpos($amountSearch, '.') + 1);
    }
    $where   .= " AND CAST(t.amount AS CHAR) LIKE ?";
    $params[] = $amountSearch.'%';
}

$transactions = $db->fetchAll("
    SELECT t.*, a.account_name,
           COALESCE(t.card_display, a.card_display) AS card_display,
           COALESCE(t.card_last4,  a.card_last4)    AS card_last4,
           a.card_brand,
           COALESCE(t.platform, 'meta') AS platform
    FROM transactions t
    LEFT JOIN ad_accounts a ON a.account_id = t.account_id
    {$where}
    ORDER BY t.transaction_date DESC
    LIMIT 500
", $params);

// ── Vínculo com os recibos importados (paid_transactions) → eventos ──
// Regra: id exato OU (mesma conta + mesmo valor + data até 2 dias de diferença).
$txEvents = [];        // idx da linha => [event_id => ['name'=>, 'value'=>]]
$txMeta   = [];        // idx da linha => ['taxes'=>, 'others'=>[rótulo=>valor]]  (impostos e gastos sem evento, por tag)
$eventTotals = [];     // event_id => ['name'=>, 'value'=>]  (respeita os filtros atuais)
try {
    $paid = $db->fetchAll("
        SELECT p.transaction_id, p.paid_at, p.total, p.subtotal, p.taxes, p.status, a.account_id
        FROM paid_transactions p
        JOIN ad_accounts a ON a.account_name = p.account_name
        WHERE p.status = 'Pago'"); // recibo de Falha não corresponde a cobrança efetivada
    $paidItems = [];
    foreach ($db->fetchAll("
        SELECT i.transaction_id, i.event_id, i.value, i.campaign_name, e.name AS event_name
        FROM paid_transaction_items i
        LEFT JOIN events e ON e.id = i.event_id") as $it) {
        $paidItems[$it['transaction_id']][] = $it;
    }
    // ATENÇÃO: a primeira metade do ID da Meta se repete entre transações diferentes
    // da mesma conta, então ID parcial NÃO serve como chave. Regras, em ordem:
    //   1. ID completo idêntico.
    //   2. Mesma conta + mesmo valor total (±R$0,01) + data mais próxima (≤3 dias),
    //      preferindo recibo com status "Pago" e ainda não usado.
    // Sanidade: a soma dos itens (subtotal) nunca pode passar do valor da linha.
    $usedReceipts = [];
    foreach ($transactions as $idx => $txn) {
        $best = null; $bestScore = null;
        foreach ($paid as $pr) {
            if (isset($usedReceipts[$pr['transaction_id']])) continue;
            if ($txn['transaction_id'] && $txn['transaction_id'] === $pr['transaction_id']) {
                $best = $pr; break; // ID completo: certeza
            }
            if ($pr['account_id'] !== $txn['account_id']) continue;
            if (abs((float)$txn['amount'] - (float)$pr['total']) > 0.011) continue;
            if (!$txn['transaction_date'] || !$pr['paid_at']) continue;
            $dayDiff = abs(strtotime(substr($txn['transaction_date'], 0, 10))
                         - strtotime(substr($pr['paid_at'], 0, 10))) / 86400;
            if ($dayDiff > 3) continue;
            $score = $dayDiff; // menor diferença de dias ganha
            if ($bestScore === null || $score < $bestScore) { $best = $pr; $bestScore = $score; }
        }
        if (!$best) continue;
        $items = $paidItems[$best['transaction_id']] ?? [];
        $itemsSum = 0; foreach ($items as $it) $itemsSum += (float)$it['value'];
        if ($itemsSum > (float)$txn['amount'] + 0.02) continue; // recibo não pertence a esta linha
        $usedReceipts[$best['transaction_id']] = true;
        $others = [];
        foreach ($items as $it) {
            if ($it['event_id']) {
                $eid = (int)$it['event_id'];
                $txEvents[$idx][$eid]['name']  = $it['event_name'];
                $txEvents[$idx][$eid]['value'] = ($txEvents[$idx][$eid]['value'] ?? 0) + $it['value'];
            } else {
                // Sem evento: agrupa pela tag "[...]" do nome da campanha (ou pelo começo do nome)
                if (preg_match('/^(\[[^\]]{1,30}\])/u', trim($it['campaign_name']), $tm)) {
                    $label = $tm[1];
                } else {
                    $label = mb_substr(trim($it['campaign_name']), 0, 24);
                    if (mb_strlen(trim($it['campaign_name'])) > 24) $label .= '…';
                }
                $others[$label] = ($others[$label] ?? 0) + (float)$it['value'];
            }
        }
        arsort($others);
        // no máximo 4 balões; o resto vira "+n outros"
        if (count($others) > 4) {
            $top = array_slice($others, 0, 3, true);
            $rest = array_slice($others, 3, null, true);
            $top['+' . count($rest) . ' outros'] = array_sum($rest);
            $others = $top;
        }
        // Rateia o imposto do recibo proporcionalmente ao valor de cada evento.
        // É o que permite cobrar do cliente o valor cheio da parte dele.
        $imposto  = (float)$best['taxes'];
        $subtotal = (float)$best['subtotal'];
        if ($imposto > 0 && $subtotal > 0) {
            foreach ($txEvents[$idx] ?? [] as $eid => $ev) {
                $txEvents[$idx][$eid]['imposto'] = round($ev['value'] / $subtotal * $imposto, 2);
                $txEvents[$idx][$eid]['total']   = round($ev['value'] + $txEvents[$idx][$eid]['imposto'], 2);
            }
            foreach ($others as $lbl => $val) {
                $othersImposto[$lbl] = round($val / $subtotal * $imposto, 2);
            }
        }
        $txMeta[$idx] = [
            'taxes'          => $imposto,
            'others'         => $others,
            'others_imposto' => $othersImposto ?? [],
        ];
        $othersImposto = [];
    }
} catch (Exception $e) { /* tabelas de recibos ainda não migradas — segue sem vínculo */ }

// Filtro por evento: mantém só as linhas cujo recibo contém aquele evento
if ($eventFilter && !Auth::isAdmin()) {
    $temAcesso = $db->fetch("SELECT 1 FROM event_user_access WHERE event_id = ? AND user_id = ?",
        [$eventFilter, Auth::getUserId()]);
    if (!$temAcesso) $eventFilter = 0; // evento não liberado pro usuário: filtro ignorado
}
if ($eventFilter) {
    $ftx = []; $fev = []; $fme = [];
    foreach ($transactions as $idx => $txn) {
        if (isset($txEvents[$idx][$eventFilter])) {
            $fev[count($ftx)] = $txEvents[$idx];
            if (isset($txMeta[$idx])) $fme[count($ftx)] = $txMeta[$idx]; // impostos acompanham a linha
            $ftx[] = $txn;
        }
    }
    $transactions = $ftx; $txEvents = $fev; $txMeta = $fme;
}
foreach ($txEvents as $evs) {
    foreach ($evs as $eid => $ev) {
        $eventTotals[$eid]['name']  = $ev['name'];
        $eventTotals[$eid]['value'] = ($eventTotals[$eid]['value'] ?? 0) + $ev['value'];
    }
}
uasort($eventTotals, function ($a, $b) { return $b['value'] <=> $a['value']; });

$totalAmount = 0; $totalFunded = 0; $totalRefunded = 0; $totalByCard = [];
foreach ($transactions as $txn) {
    if ($txn['status'] === 'FUNDED') { $totalFunded += $txn['amount']; }
    elseif ($txn['status'] === 'REFUNDED') { $totalRefunded += abs($txn['amount']); }
    else {
        $totalAmount += $txn['amount'];
        $cardKey = temCartao($txn['card_display']) ? $txn['card_display'] : 'Crédito da conta';
        if (!isset($totalByCard[$cardKey])) $totalByCard[$cardKey] = 0;
        $totalByCard[$cardKey] += $txn['amount'];
    }
}
arsort($totalByCard);

if ($allowedAccountIds === null) {
    // Só o que teve movimentação no período selecionado (listas curtas e vivas)
    $per = [$dateStart.' 00:00:00', $dateEnd.' 23:59:59'];
    $allCards    = $db->fetchAll("SELECT DISTINCT card_display, card_last4 FROM transactions
                                  WHERE card_display IS NOT NULL
                                    AND UPPER(TRIM(card_display)) NOT IN ('N/D','ND','N/A','-')
                                    AND transaction_date BETWEEN ? AND ?
                                  ORDER BY card_display", $per);
    $allAccounts = $db->fetchAll("SELECT a.account_id, a.account_name FROM ad_accounts a
                                  WHERE EXISTS (SELECT 1 FROM transactions t WHERE t.account_id = a.account_id
                                                AND t.transaction_date BETWEEN ? AND ?)
                                  ORDER BY a.account_name", $per);
} elseif (!empty($allowedAccountIds)) {
    $ph  = implode(',', array_fill(0, count($allowedAccountIds), '?'));
    $per = [$dateStart.' 00:00:00', $dateEnd.' 23:59:59'];
    $allCards    = $db->fetchAll("SELECT DISTINCT card_display, card_last4 FROM transactions
                                  WHERE card_display IS NOT NULL
                                    AND UPPER(TRIM(card_display)) NOT IN ('N/D','ND','N/A','-')
                                    AND account_id IN ({$ph})
                                  AND transaction_date BETWEEN ? AND ?
                                  ORDER BY card_display", array_merge($allowedAccountIds, $per));
    $allAccounts = $db->fetchAll("SELECT a.account_id, a.account_name FROM ad_accounts a
                                  WHERE a.account_id IN ({$ph})
                                  AND EXISTS (SELECT 1 FROM transactions t WHERE t.account_id = a.account_id
                                              AND t.transaction_date BETWEEN ? AND ?)
                                  ORDER BY a.account_name", array_merge($allowedAccountIds, $per));
} else { $allCards = []; $allAccounts = []; }
// Usabilidade: item já selecionado permanece na lista mesmo sem movimento no período
if ($cardFilter && !in_array($cardFilter, array_column($allCards, 'card_last4'))) {
    $sel = $db->fetch("SELECT DISTINCT card_display, card_last4 FROM transactions WHERE card_last4 = ? LIMIT 1", [$cardFilter]);
    if ($sel) $allCards[] = $sel;
}
if ($accountFilter && !in_array($accountFilter, array_column($allAccounts, 'account_id'))) {
    $sel = $db->fetch("SELECT account_id, account_name FROM ad_accounts WHERE account_id = ?", [$accountFilter]);
    if ($sel) $allAccounts[] = $sel;
}

/** "N/D", "-", "ND" e vazio significam que não houve cartão. */
function temCartao($v) {
    $v = strtoupper(trim((string)$v));
    return $v !== '' && !in_array($v, ['N/D', 'ND', 'N/A', '-', '—', 'NULL']);
}

function statusLabel($s) {
    $m = ['SETTLED'=>'Pago','REFUNDED'=>'Reembolso','FUNDED'=>'Depósito','PENDING'=>'Pendente','FAILED'=>'Falhou','CANCELLED'=>'Cancelado'];
    return $m[$s] ?? $s;
}
function statusClass($s) {
    $m = ['SETTLED'=>'st-settled','REFUNDED'=>'st-refunded','FUNDED'=>'st-funded','PENDING'=>'st-pending'];
    return $m[$s] ?? 'st-other';
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transações — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
.page-title{font-size:22px;font-weight:700;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--mt2);margin-bottom:22px}

/* FILTROS */
.filter-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:20px 22px;margin-bottom:18px;display:flex;flex-direction:column;gap:14px}
.filters-row{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}
.fg{display:flex;flex-direction:column;gap:5px}
.fg label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--mt)}
.fg input[type=date],.fg input[type=text],.fg select{padding:8px 12px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-family:'Inter',sans-serif;font-size:13px;outline:none;transition:border-color .2s;min-width:130px}
.fg input:focus,.fg select:focus{border-color:var(--ac)}

.btn-filter{padding:9px 18px;background:var(--ac);color:#fff;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;transition:opacity .15s;align-self:flex-end}
.btn-filter:hover{opacity:.85}
.btn-clear{padding:9px 14px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--mt2);font-family:'Inter',sans-serif;font-size:13px;font-weight:500;text-decoration:none;cursor:pointer;white-space:nowrap;align-self:flex-end;transition:border-color .15s}
.btn-clear:hover{border-color:var(--ac);color:var(--tx)}

.filter-tag{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:500;background:rgba(84,112,255,.08);border:1px solid rgba(84,112,255,.2);color:var(--ac)}
.filter-warn{background:rgba(245,166,35,.08);border-color:rgba(245,166,35,.25);color:var(--yw)}

/* MATCH SECTION */
.match-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px 22px;margin-bottom:18px}
.match-card-title{font-size:14px;font-weight:700;color:var(--tx);margin-bottom:3px}
.match-card-sub{font-size:12px;color:var(--mt2);margin-bottom:12px}
.match-textarea{width:100%;min-height:120px;background:var(--s2);border:1px solid var(--bd);border-radius:9px;color:var(--tx);padding:12px 14px;font-family:'JetBrains Mono',monospace;font-size:12px;resize:vertical;outline:none;transition:border-color .2s}
.match-textarea:focus{border-color:var(--ac)}
.match-actions{display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap}
.match-status{font-size:12px;color:var(--mt2)}

/* SUMMARY */
.summary-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.summary-item{background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:14px 18px;flex:1;min-width:130px}
.s-label{font-size:11px;color:var(--mt);text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px;font-weight:600}
.s-value{font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:600;color:var(--tx)}
.s-green{color:var(--gn)!important}
.s-yellow{color:var(--yw)!important}
.s-blue{color:var(--ac)!important}

/* BREAKDOWN */
.breakdown-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px 22px;margin-bottom:18px}
.breakdown-title{font-size:13px;font-weight:600;color:var(--tx);margin-bottom:12px;display:flex;align-items:center;justify-content:space-between}
.breakdown-title span{font-family:'JetBrains Mono',monospace;font-size:14px;color:var(--gn)}
.bd-list{display:flex;flex-direction:column;gap:7px}
.bd-item{display:flex;align-items:center;gap:10px;padding:9px 14px;background:var(--s2);border-radius:9px;font-size:13px}
.bd-name{flex:1;color:var(--tx);font-weight:500}
.bd-val{font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--tx);font-weight:600}
.bd-pct{font-size:11px;color:var(--mt2);min-width:38px;text-align:right}

/* TABELA */
.table-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;overflow:hidden;margin-bottom:22px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:540px}
thead th{padding:10px 16px;text-align:left;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:var(--mt);background:var(--s2);border-bottom:1px solid var(--bd);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--bd);transition:background .12s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:rgba(255,255,255,.02)}
td{padding:12px 16px;font-size:13px;vertical-align:middle}

/* STATUS */
.st-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:5px;font-size:11px;font-weight:600}
.st-settled{background:rgba(62,207,142,.1);color:var(--gn)}
.st-refunded{background:rgba(245,166,35,.1);color:var(--yw)}
.st-funded{background:rgba(84,112,255,.1);color:var(--ac)}
.st-pending{background:rgba(148,148,163,.1);color:var(--mt2)}
.st-other{background:rgba(148,148,163,.08);color:var(--mt)}

/* PLATAFORMA */
.plat-badge{display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;margin-bottom:3px}
.plat-meta{background:rgba(24,119,242,.12);color:#1877f2}
.plat-tiktok{background:rgba(0,196,204,.12);color:#00c4cc}

.amount{font-family:'JetBrains Mono',monospace;font-size:13px;font-weight:600;color:var(--tx)}

/* EMPTY */
.empty-state{padding:48px;text-align:center;color:var(--mt);font-size:13px}
.empty-state .ico{font-size:28px;margin-bottom:10px}

/* MATCH RESULTS */
.group-header td{background:var(--s2);font-weight:700;font-size:12px;padding:10px 16px;color:var(--tx);border-top:2px solid var(--bd)}

.mob-hide{}
@media(max-width:768px){
  .mob-hide{display:none}
  .filters-row{flex-direction:column;align-items:stretch}
  .summary-item{min-width:calc(50% - 6px)}
}
</style>
</head>
<body>
<?php $currentPage='transactions'; $sidebarBase=''; require __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
  <h1 class="page-title">Transações</h1>
  <p class="page-sub">Histórico de cobranças por conta e cartão</p>

  <form method="GET">
    <div class="filter-card">
      <div class="filters-row">
        <div class="fg">
          <label>Data início</label>
          <input type="date" name="date_start" value="<?= htmlspecialchars($dateStart) ?>">
        </div>
        <div class="fg">
          <label>Data fim</label>
          <input type="date" name="date_end" value="<?= htmlspecialchars($dateEnd) ?>">
        </div>
        <div class="fg">
          <label>Cartão</label>
          <select name="card">
            <option value="">Crédito para anúncio</option>
            <?php foreach ($allCards as $c): ?>
            <option value="<?= htmlspecialchars($c['card_last4'] ?? '') ?>" <?= $cardFilter===($c['card_last4']??'')?'selected':'' ?>>
              <?= htmlspecialchars($c['card_display'] ?? '') ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>Conta</label>
          <select name="account">
            <option value="">Todas as contas</option>
            <?php foreach ($allAccounts as $acc): ?>
            <option value="<?= htmlspecialchars($acc['account_id']) ?>" <?= $accountFilter===$acc['account_id']?'selected':'' ?>>
              <?= htmlspecialchars($acc['account_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>Status</label>
          <select name="status">
            <option value="">Todos</option>
            <option value="SETTLED"  <?= $statusFilter==='SETTLED' ?'selected':'' ?>>Pago</option>
            <option value="CARTAO"   <?= $statusFilter==='CARTAO'  ?'selected':'' ?>>&nbsp;&nbsp;↳ pago no cartão</option>
            <option value="SALDO"    <?= $statusFilter==='SALDO'   ?'selected':'' ?>>&nbsp;&nbsp;↳ pago com crédito</option>
            <option value="DEPOSITO" <?= $statusFilter==='DEPOSITO'?'selected':'' ?>>🏦 Depósitos (crédito)</option>
            <option value="REFUNDED" <?= $statusFilter==='REFUNDED'?'selected':'' ?>>Reembolso</option>
            <option value="PENDING"  <?= $statusFilter==='PENDING' ?'selected':'' ?>>Pendente</option>
          </select>
        </div>
        <div class="fg">
          <label>Evento</label>
          <select name="event">
            <option value="">Todos</option>
            <?php
            // Mesma regra do resto do sistema (hasEventAccess): admin vê todos;
            // demais usuários só os eventos liberados em event_user_access.
            // Só eventos com pagamento dentro do período selecionado
            $evPeriod = [$dateStart.' 00:00:00', $dateEnd.' 23:59:59'];
            $evBase = "SELECT DISTINCT e.id, e.name FROM events e
                       JOIN paid_transaction_items i ON i.event_id = e.id
                       JOIN paid_transactions pt ON pt.transaction_id = i.transaction_id
                            AND pt.status = 'Pago' AND pt.paid_at BETWEEN ? AND ?";
            try {
                $eventOptions = Auth::isAdmin()
                    ? $db->fetchAll($evBase . " ORDER BY e.name", $evPeriod)
                    : $db->fetchAll($evBase . " JOIN event_user_access eua ON eua.event_id = e.id AND eua.user_id = ?
                                    ORDER BY e.name", array_merge($evPeriod, [Auth::getUserId()]));
            } catch (Exception $e) { $eventOptions = []; } // tabelas de recibos ainda não migradas
            // Evento já selecionado permanece na lista mesmo sem movimento no período
            if ($eventFilter && !in_array($eventFilter, array_column($eventOptions, 'id'))) {
                $sel = $db->fetch("SELECT id, name FROM events WHERE id = ?", [$eventFilter]);
                if ($sel) $eventOptions[] = $sel;
            }
            foreach ($eventOptions as $evOpt): ?>
            <option value="<?= $evOpt['id'] ?>" <?= $eventFilter == $evOpt['id'] ? 'selected' : '' ?>><?= htmlspecialchars($evOpt['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>Plataforma</label>
          <select name="platform">
            <option value="">Todas</option>
            <option value="meta"   <?= $platformFilter==='meta'  ?'selected':'' ?>>📘 Meta Ads</option>
            <option value="tiktok" <?= $platformFilter==='tiktok'?'selected':'' ?>>🎵 TikTok Ads</option>
          </select>
        </div>
      </div>
      <div class="filters-row">
        <div class="fg" style="flex:1;min-width:200px">
          <label>Buscar por ID da transação</label>
          <input type="text" name="search_id" placeholder="Cole parte do ID aqui..." value="<?= htmlspecialchars($searchId) ?>">
        </div>
        <div class="fg" style="flex:1;min-width:180px">
          <label>Buscar por valor (R$)</label>
          <input type="text" name="search_amount" placeholder="Ex: 402 busca 402,33 / 402,00" value="<?= htmlspecialchars($searchAmount) ?>">
        </div>
        <button type="submit" class="btn-filter">🔍 Filtrar</button>
        <a href="transactions.php" class="btn-clear">Limpar</a>
      </div>
      <?php if ($hasSearch): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <span class="filter-tag filter-warn">⚠️ Busca por <?= $searchId?'ID':'valor' ?> — filtro de data ignorado</span>
      </div>
      <?php endif; ?>
      <?php
      $tags = [];
      if ($cardFilter)    $tags[] = '💳 '.$cardFilter;
      if ($accountFilter) $tags[] = '🏢 Conta selecionada';
      if ($statusFilter) {
          $rotulos = ['DEPOSITO' => '🏦 Depósitos', 'SALDO' => '💰 Pago com crédito', 'CARTAO' => '💳 Pago no cartão'];
          $tags[] = $rotulos[$statusFilter] ?? ('📋 ' . statusLabel($statusFilter));
      }
      if ($searchId)      $tags[] = '🔎 ID: '.mb_substr($searchId,0,20).'...';
      if ($searchAmount)  $tags[] = '💰 R$ '.$searchAmount;
      ?>
      <?php if (!empty($tags)): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach ($tags as $tag): ?>
        <span class="filter-tag"><?= htmlspecialchars($tag) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </form>

  <!-- LOCALIZAR POR LISTA -->
  <div class="match-card">
    <div class="match-card-title">🔎 Localizar transações por lista</div>
    <div class="match-card-sub">Cole a lista de transações (ex: extrato do cartão) e veja de qual conta é cada uma.</div>
    <textarea id="matchRawList" class="match-textarea" placeholder="21 de mai. 2026 FACEBK* RFXY3PMVD2 - R$ 608,14&#10;22 de mai. 2026 FACEBK* PL2Q6PMVD2 - R$ 609,62&#10;..."></textarea>
    <div class="match-actions">
      <button type="button" class="btn-filter" id="btnMatchSearch">🔍 Buscar contas</button>
      <span class="match-status" id="matchStatus"></span>
    </div>
    <div id="matchResults" style="display:none;margin-top:18px">
      <div id="matchGrouped" style="margin-bottom:14px"></div>
      <div class="table-card">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Transação (original)</th><th>Código</th><th>Valor</th><th>Conta encontrada</th></tr></thead>
            <tbody id="matchTableBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- RESUMO -->
  <div class="summary-row">
    <div class="summary-item">
      <div class="s-label">Total cobrado</div>
      <div class="s-value s-green">R$ <?= number_format($totalAmount,2,',','.') ?></div>
    </div>
    <div class="summary-item">
      <div class="s-label">Nº de transações</div>
      <div class="s-value"><?= count($transactions) ?></div>
    </div>
    <div class="summary-item">
      <div class="s-label">Período</div>
      <div class="s-value" style="font-size:14px"><?= date('d/m',strtotime($dateStart)) ?> → <?= date('d/m/Y',strtotime($dateEnd)) ?></div>
    </div>
    <?php if ($totalRefunded > 0): ?>
    <div class="summary-item">
      <div class="s-label">Reembolsos</div>
      <div class="s-value s-yellow">- R$ <?= number_format($totalRefunded,2,',','.') ?></div>
    </div>
    <?php endif; ?>
    <?php if ($totalFunded > 0): ?>
    <div class="summary-item">
      <div class="s-label">Crédito adicionado</div>
      <div class="s-value s-blue">R$ <?= number_format($totalFunded,2,',','.') ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- PAGO POR EVENTO (recibos importados em Transações pagas) -->
  <?php if (!empty($eventTotals)): ?>
  <div class="breakdown-card">
    <div class="breakdown-title">
      🎪 Pago por evento
      <span>R$ <?= number_format(array_sum(array_column($eventTotals, 'value')),2,',','.') ?></span>
    </div>
    <div class="bd-list">
      <?php $evSum = array_sum(array_column($eventTotals, 'value')); foreach ($eventTotals as $eid => $ev): ?>
      <div class="bd-item">
        <span class="bd-name">🎪 <?= htmlspecialchars($ev['name']) ?></span>
        <span class="bd-val">R$ <?= number_format($ev['value'],2,',','.') ?></span>
        <span class="bd-pct"><?= $evSum > 0 ? number_format($ev['value'] / $evSum * 100, 1) : 0 ?>%</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- BREAKDOWN POR CARTÃO -->
  <?php if (!empty($totalByCard)): ?>
  <div class="breakdown-card">
    <div class="breakdown-title">
      💳 Total por forma de pagamento
      <span>R$ <?= number_format($totalAmount,2,',','.') ?></span>
    </div>
    <div class="bd-list">
      <?php foreach ($totalByCard as $cardName => $cardVal): ?>
      <div class="bd-item">
        <span class="bd-name"><?php
          $ico = ($cardName === 'Crédito da conta') ? '💰' : '💳';
          echo $ico.' '.htmlspecialchars($cardName);
        ?></span>
        <span class="bd-val">R$ <?= number_format($cardVal,2,',','.') ?></span>
        <span class="bd-pct"><?= $totalAmount>0?number_format($cardVal/$totalAmount*100,1):0 ?>%</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- TABELA -->
  <div class="table-card">
    <?php if (empty($transactions)): ?>
    <div class="empty-state">
      <div class="ico">📭</div>
      Nenhuma transação encontrada para os filtros selecionados.
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Data</th><th>Conta</th><th>Forma de pagamento</th>
            <th>Valor</th><th>Status</th><th class="mob-hide">ID Transação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($transactions as $idx => $txn):
            $plat      = $txn['platform'] ?? 'meta';
            $platClass = $plat==='tiktok' ? 'plat-tiktok' : 'plat-meta';
            $platLabel = $plat==='tiktok' ? '🎵 TikTok'   : '📘 Meta';
          ?>
          <tr>
            <td style="color:var(--mt2);white-space:nowrap">
              <?= $txn['transaction_date'] ? date('d/m/Y H:i',strtotime($txn['transaction_date'])) : '—' ?>
            </td>
            <td>
              <div><span class="plat-badge <?= $platClass ?>"><?= $platLabel ?></span></div>
              <div style="font-weight:500"><?= htmlspecialchars($txn['account_name'] ?? $txn['account_id']) ?></div>
              <div style="font-size:10px;color:var(--mt);font-family:'JetBrains Mono',monospace"><?= $txn['account_id'] ?></div>
              <?php foreach ($txEvents[$idx] ?? [] as $evChip):
                $temImp = isset($evChip['imposto']) && $evChip['imposto'] > 0.005;
                $valorCobrar = $temImp ? $evChip['total'] : $evChip['value']; ?>
              <div style="margin-top:3px">
                <span style="display:inline-block;background:var(--s2);border:1px solid var(--bd);color:var(--ac);border-radius:20px;padding:1px 9px;font-size:11px;font-weight:600"
                      title="<?= $temImp ? 'Mídia R$ ' . number_format($evChip['value'],2,',','.') . ' + impostos R$ ' . number_format($evChip['imposto'],2,',','.') . ' = R$ ' . number_format($valorCobrar,2,',','.') . ' (valor a cobrar do cliente)' : '' ?>">
                  🎪 <?= htmlspecialchars($evChip['name']) ?> · R$ <?= number_format($valorCobrar,2,',','.') ?>
                  <?php if ($temImp): ?><span style="font-weight:400;opacity:.7">(mídia <?= number_format($evChip['value'],2,',','.') ?> + imp. <?= number_format($evChip['imposto'],2,',','.') ?>)</span><?php endif; ?>
                </span>
              </div>
              <?php endforeach; ?>
              <?php if (isset($txMeta[$idx])): ?>
                <?php foreach ($txMeta[$idx]['others'] as $olabel => $oval): if ($oval < 0.011) continue;
                  $oImp = $txMeta[$idx]['others_imposto'][$olabel] ?? 0;
                  $oTot = $oImp > 0.005 ? $oval + $oImp : $oval; ?>
                <div style="margin-top:3px"><span style="display:inline-block;background:var(--s2);border:1px solid var(--bd);color:var(--mt);border-radius:20px;padding:1px 9px;font-size:11px" title="Campanhas sem evento vinculado<?= $oImp > 0.005 ? ' · mídia R$ ' . number_format($oval,2,',','.') . ' + imp. R$ ' . number_format($oImp,2,',','.') : '' ?>">◽ <?= htmlspecialchars($olabel) ?> · R$ <?= number_format($oTot,2,',','.') ?></span></div>
                <?php endforeach; ?>
                <?php if ($txMeta[$idx]['taxes'] > 0.011): ?>
                <div style="margin-top:3px"><span style="display:inline-block;background:rgba(248,113,113,.10);border:1px solid rgba(248,113,113,.35);color:#f87171;border-radius:20px;padding:1px 9px;font-size:11px;font-weight:600">🧾 Impostos · R$ <?= number_format($txMeta[$idx]['taxes'],2,',','.') ?> <span style="font-weight:400;opacity:.75">(já distribuídos acima)</span></span></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if (temCartao($txn['card_display'])): ?>
              <span>💳 <?= htmlspecialchars($txn['card_display']) ?></span>
              <?php elseif (strtoupper($txn['status']) === 'FUNDED'): ?>
              <span style="font-size:12px;color:#34d399" title="Depósito de crédito na conta (PIX/boleto)">🏦 Depósito</span>
              <?php elseif (strtoupper($txn['status']) === 'REFUNDED'): ?>
              <span style="font-size:12px;color:var(--mt)" title="Estorno">↩️ Estorno</span>
              <?php else: ?>
              <span style="font-size:12px;color:#a78bfa" title="Cobrança sem cartão — debitada do crédito da conta">💰 Pago com crédito</span>
              <?php endif; ?>
            </td>
            <td><span class="amount">R$ <?= number_format($txn['amount'],2,',','.') ?></span></td>
            <td><span class="st-badge <?= statusClass($txn['status']) ?>"><?= statusLabel($txn['status']) ?></span></td>
            <td class="mob-hide" style="font-size:11px;color:var(--mt);font-family:'JetBrains Mono',monospace;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($txn['transaction_id'] ?? '') ?>">
              <?= htmlspecialchars($txn['transaction_id'] ?? '—') ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</main>

<script>
document.getElementById('btnMatchSearch').addEventListener('click', async function() {
  var raw       = document.getElementById('matchRawList').value.trim();
  var statusEl  = document.getElementById('matchStatus');
  var resultsEl = document.getElementById('matchResults');
  var tbody     = document.getElementById('matchTableBody');
  var groupedEl = document.getElementById('matchGrouped');
  if (!raw) { statusEl.textContent = 'Cole a lista antes de buscar.'; return; }
  statusEl.textContent = 'Buscando...';
  resultsEl.style.display = 'none';
  tbody.innerHTML = '';
  groupedEl.innerHTML = '';
  try {
    var resp = await fetch('api/match_transactions.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'raw_list=' + encodeURIComponent(raw)
    });
    var data = await resp.json();
    if (!data.success) { statusEl.textContent = 'Erro: ' + (data.error||'falha desconhecida'); return; }
    statusEl.textContent = data.found + ' de ' + data.total + ' transações encontradas' +
      (data.not_found > 0 ? ' · ' + data.not_found + ' não encontrada(s)' : '');
    if (data.grouped && data.grouped.length > 0) {
      var html = '<div class="breakdown-card"><div class="breakdown-title">📊 Resumo por conta</div><div class="bd-list">';
      data.grouped.forEach(function(g) {
        html += '<div class="bd-item"><span class="bd-name">'+esc(g.account_name)+
          ' <span style="color:var(--mt);font-size:11px">('+g.items.length+' transações)</span></span>'+
          '<span class="bd-val">R$ '+fmtBRL(g.total)+'</span></div>';
      });
      html += '</div></div>';
      groupedEl.innerHTML = html;
    }
    var AMBIGUOUS_KEY = '__ambiguous__', NOTFOUND_KEY = '__notfound__';
    var buckets = new Map();
    data.results.forEach(function(r) {
      var key, label;
      if (!r.found) { key=NOTFOUND_KEY; label='❓ Não encontradas / sem permissão'; }
      else if (r.ambiguous) { key=AMBIGUOUS_KEY; label='⚠️ Ambíguas (múltiplas contas)'; }
      else { var m=r.matches[0]; key=m.account_id; label=m.account_name; }
      if (!buckets.has(key)) buckets.set(key,{label:label,total:0,rows:[],isSpecial:(key===AMBIGUOUS_KEY||key===NOTFOUND_KEY)});
      var bk=buckets.get(key);
      bk.rows.push(r);
      if (r.found && !r.ambiguous) bk.total += r.amount;
    });
    var sorted = Array.from(buckets.entries()).sort(function(a,b){
      var aS=a[1].isSpecial,bS=b[1].isSpecial;
      if (aS&&!bS) return 1; if (!aS&&bS) return -1;
      if (aS&&bS){if(a[0]===AMBIGUOUS_KEY)return -1;if(b[0]===AMBIGUOUS_KEY)return 1;return 0;}
      return b[1].total-a[1].total;
    });
    sorted.forEach(function(entry) {
      var bk=entry[1];
      var htr=document.createElement('tr'); htr.className='group-header';
      var htd=document.createElement('td'); htd.colSpan=4;
      htd.innerHTML=esc(bk.label)+' <span style="color:var(--mt);font-size:11px;font-weight:400">('+bk.rows.length+' transações)</span>'+
        (!bk.isSpecial?'<span style="float:right;color:var(--gn);font-family:\'JetBrains Mono\',monospace">R$ '+fmtBRL(bk.total)+'</span>':'');
      htr.appendChild(htd); tbody.appendChild(htr);
      bk.rows.forEach(function(r) {
        var tr=document.createElement('tr');
        var contaHtml;
        if (!r.found) { contaHtml='<span style="color:var(--mt);font-size:12px">❓ Não encontrado</span>'; }
        else if (r.ambiguous) { contaHtml='<span style="color:var(--yw);font-size:12px">⚠️ '+r.matches.map(function(m){return esc(m.account_name);}).join(', ')+'</span>'; }
        else { var m=r.matches[0]; contaHtml='<div style="font-weight:500">'+esc(m.account_name)+'</div><div style="font-size:10px;color:var(--mt);font-family:\'JetBrains Mono\',monospace">'+esc(m.account_id)+'</div>'; }
        tr.innerHTML='<td style="font-size:12px;color:var(--mt2)">'+esc(r.raw)+'</td>'+
          '<td style="font-family:\'JetBrains Mono\',monospace;font-size:12px">'+esc(r.code)+'</td>'+
          '<td class="amount">'+(r.amount!==null?'R$ '+fmtBRL(r.amount):'—')+'</td>'+
          '<td>'+contaHtml+'</td>';
        tbody.appendChild(tr);
      });
    });
    resultsEl.style.display = 'block';
  } catch(e) { statusEl.textContent = 'Erro ao buscar: ' + e.message; }
});

function fmtBRL(v) { return Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s) { var d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }
</script>
</body>
</html>