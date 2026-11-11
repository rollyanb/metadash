<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/meta_api.php';

Auth::requireLogin();

$db  = Database::getInstance();
$api = new MetaAPI();

$allowedAccounts = Auth::getAllowedAccounts();

$accountWhere  = '';
$accountParams = [];
if ($allowedAccounts !== null) {
    if (empty($allowedAccounts)) {
        $accountWhere = ' AND 1=0';
    } else {
        $placeholders  = implode(',', array_fill(0, count($allowedAccounts), '?'));
        $accountWhere  = " AND a.account_id IN ({$placeholders})";
        $accountParams = $allowedAccounts;
    }
}

$isAdmin = Auth::isAdmin();

// ── PERÍODO ───────────────────────────────────────────────────────
$periods = [
    'hoje' => 'Hoje', 'ontem' => 'Ontem', '7d' => '7 dias', '30d' => '30 dias',
];
$period = $_GET['p'] ?? 'hoje';
if (!isset($periods[$period])) $period = 'hoje';

$today = date('Y-m-d');
switch ($period) {
    case 'ontem':
        $curStart = $curEnd = date('Y-m-d', strtotime('-1 day'));
        $prevStart = $prevEnd = date('Y-m-d', strtotime('-2 days'));
        $periodSub = 'Ontem, ' . date('d/m', strtotime('-1 day')) . ' · vs anteontem';
        break;
    case '7d':
        $curStart = date('Y-m-d', strtotime('-6 days'));  $curEnd = $today;
        $prevStart = date('Y-m-d', strtotime('-13 days')); $prevEnd = date('Y-m-d', strtotime('-7 days'));
        $periodSub = date('d/m', strtotime($curStart)) . ' – ' . date('d/m') . ' · vs 7 dias anteriores';
        break;
    case '30d':
        $curStart = date('Y-m-d', strtotime('-29 days')); $curEnd = $today;
        $prevStart = date('Y-m-d', strtotime('-59 days')); $prevEnd = date('Y-m-d', strtotime('-30 days'));
        $periodSub = date('d/m', strtotime($curStart)) . ' – ' . date('d/m') . ' · vs 30 dias anteriores';
        break;
    default: // hoje
        $curStart = $curEnd = $today;
        $prevStart = $prevEnd = date('Y-m-d', strtotime('-1 day'));
        $periodSub = date('d/m/Y') . ' · vs ontem no mesmo horário';
}
// "Hoje" é dia parcial: compara com ontem PROPORCIONAL ao horário atual,
// senão às 10h da manhã tudo parece 60% pior que ontem.
$prevScale = 1.0;
if ($period === 'hoje') {
    $prevScale = max(0.02, (date('G') * 60 + (int)date('i')) / 1440);
}

// Soma por conta no intervalo. Gasto: usa o das conversões quando existe
// (mesma fonte do ROAS), senão o do snapshot de gasto.
function sumByAccount($db, $start, $end, $accountWhere, $accountParams) {
    return $db->fetchAll("
        SELECT
            a.account_id, a.account_name, a.status, a.last_sync,
            COALESCE(NULLIF(cs.spend, 0), ss.spend, 0) AS spend,
            COALESCE(ss.impressions, 0) AS impressions,
            COALESCE(cs.purchases, 0)   AS purchases,
            COALESCE(cs.leads, 0)       AS leads,
            COALESCE(cs.revenue, 0)     AS revenue
        FROM ad_accounts a
        LEFT JOIN (SELECT account_id, SUM(spend) spend, SUM(impressions) impressions
                   FROM spend_snapshots WHERE snapshot_date BETWEEN ? AND ? GROUP BY account_id) ss
               ON ss.account_id = a.account_id
        LEFT JOIN (SELECT account_id, SUM(spend) spend, SUM(purchases) purchases,
                          SUM(leads) leads, SUM(revenue) revenue
                   FROM conversion_snapshots WHERE snapshot_date BETWEEN ? AND ? GROUP BY account_id) cs
               ON cs.account_id = a.account_id
        WHERE a.status != 'inactive'
          AND (ss.account_id IS NOT NULL OR cs.account_id IS NOT NULL)
          AND (COALESCE(ss.spend,0) + COALESCE(cs.spend,0) + COALESCE(cs.revenue,0)
               + COALESCE(cs.purchases,0) + COALESCE(cs.leads,0)) > 0
          {$accountWhere}
        ORDER BY COALESCE(NULLIF(cs.spend,0), ss.spend, 0) DESC, COALESCE(cs.revenue,0) DESC
    ", array_merge([$start, $end, $start, $end], $accountParams));
}

$accountsToday = sumByAccount($db, $curStart, $curEnd, $accountWhere, $accountParams);
$accountsPrev  = sumByAccount($db, $prevStart, $prevEnd, $accountWhere, $accountParams);
$prevByAccount = [];
foreach ($accountsPrev as $pa) $prevByAccount[$pa['account_id']] = $pa;

function totalize($rows) {
    $t = [
        'spend'     => array_sum(array_column($rows, 'spend')),
        'purchases' => array_sum(array_column($rows, 'purchases')),
        'revenue'   => array_sum(array_column($rows, 'revenue')),
        'leads'     => array_sum(array_column($rows, 'leads')),
    ];
    $t['roas'] = $t['spend'] > 0 ? round($t['revenue'] / $t['spend'], 2) : 0;
    $t['roi']  = $t['spend'] > 0 ? round((($t['revenue'] - $t['spend']) / $t['spend']) * 100, 1) : 0;
    return $t;
}
$totToday = totalize($accountsToday);
$totPrev  = totalize($accountsPrev);

/**
 * Badge de variação vs período anterior.
 * $dir: 1 = subir é bom (verde), -1 = subir é ruim, 0 = neutro (cinza).
 */
function deltaBadge($cur, $prev, $dir = 1, $scale = 1.0) {
    $prev = $prev * $scale;
    if ($prev <= 0) {
        if ($cur <= 0) return '';
        return '<span style="font-size:10px;color:var(--mt);font-weight:600;margin-left:4px">novo</span>';
    }
    $pct = ($cur - $prev) / $prev * 100;
    if (abs($pct) < 0.5) return '<span style="font-size:10px;color:var(--mt);font-weight:600;margin-left:4px">=</span>';
    $up = $pct > 0;
    if ($dir === 0)      $color = 'var(--mt)';
    elseif ($dir === 1)  $color = $up ? 'var(--gr, #34d399)' : '#f87171';
    else                 $color = $up ? '#f87171' : 'var(--gr, #34d399)';
    $arrow = $up ? '▲' : '▼';
    $txt = (abs($pct) >= 100 ? number_format(abs($pct), 0, ',', '.') : number_format(abs($pct), 1, ',', '.')) . '%';
    return '<span style="font-size:10px;font-weight:700;margin-left:4px;color:' . $color . '" title="vs período anterior">' . $arrow . ' ' . $txt . '</span>';
}

// Contas ativas e com dados no período
$activeCount   = count(array_filter($accountsToday, fn($a) => $a['status'] === 'ACTIVE'));
$withDataCount = count(array_filter($accountsToday, fn($a) => floatval($a['spend']) > 0));

/** Anexa a lista de contas de cada evento (evento pode rodar em mais de uma). */
function anexarContas($db, $eventos) {
    if (!$eventos) return $eventos;
    try {
        $ids = array_column($eventos, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $map = [];
        foreach ($db->fetchAll("SELECT ea.event_id, a.account_name
                                FROM event_accounts ea
                                LEFT JOIN ad_accounts a ON a.account_id = ea.account_id
                                WHERE ea.event_id IN ($ph)", $ids) as $r) {
            $map[(int)$r['event_id']][] = $r['account_name'] ?: '—';
        }
        foreach ($eventos as &$e) {
            $nomes = $map[(int)$e['id']] ?? [];
            if ($nomes) {
                $e['account_name'] = count($nomes) > 1
                    ? $nomes[0] . ' +' . (count($nomes) - 1)
                    : $nomes[0];
                $e['account_count'] = count($nomes);
            }
        } unset($e);
    } catch (Exception $e) {}
    return $eventos;
}

// ── VISÃO: eventos | contas ───────────────────────────────────────
$uid = Auth::getUserId();
if ($isAdmin) {
    $myEvents = $db->fetchAll("SELECT e.*, a.account_name FROM events e
        LEFT JOIN ad_accounts a ON a.account_id = e.account_id
        WHERE e.status = 'active' ORDER BY e.date_event ASC");
    $myEvents = anexarContas($db, $myEvents);
} else {
    $myEvents = $db->fetchAll("SELECT e.*, a.account_name FROM events e
        JOIN event_user_access eua ON eua.event_id = e.id AND eua.user_id = ?
        LEFT JOIN ad_accounts a ON a.account_id = e.account_id
        WHERE e.status = 'active' ORDER BY e.date_event ASC", [$uid]);
    $myEvents = anexarContas($db, $myEvents);
}
$view = $_GET['v'] ?? (count($myEvents) ? 'eventos' : 'contas');
if (!in_array($view, ['eventos', 'contas'])) $view = 'eventos';

$eventCards = [];
if ($view === 'eventos' && $myEvents) {
    $d7  = date('Y-m-d', strtotime('-6 days'));
    $d14 = date('Y-m-d', strtotime('-13 days'));
    $d8  = date('Y-m-d', strtotime('-7 days'));
    foreach ($myEvents as $ev) {
        $cids = array_column($db->fetchAll(
            "SELECT campaign_id FROM event_campaigns WHERE event_id = ?", [$ev['id']]), 'campaign_id');
        $card = ['ev' => $ev, 'has_campaigns' => (bool)$cids, 'spend'=>0,'leads'=>0,'purchases'=>0,'revenue'=>0,
                 'spark'=>[], 'last7'=>0, 'prev7'=>0, 'paid'=>null];
        if ($cids) {
            $ph = implode(',', array_fill(0, count($cids), '?'));
            $start = $ev['date_campaign_start'] ?: date('Y-m-d', strtotime($ev['created_at']));
            $tot = $db->fetch("SELECT SUM(spend) sp, SUM(leads) ld, SUM(purchases) pc, SUM(revenue) rv
                FROM campaign_snapshots WHERE campaign_id IN ($ph) AND snapshot_date BETWEEN ? AND ?",
                array_merge($cids, [$start, $today]));
            $card['spend']     = (float)($tot['sp'] ?? 0);
            $card['leads']     = (int)($tot['ld'] ?? 0);
            $card['purchases'] = (int)($tot['pc'] ?? 0);
            $card['revenue']   = (float)($tot['rv'] ?? 0);
            // sparkline: gasto diário dos últimos 7 dias
            $rows = $db->fetchAll("SELECT snapshot_date d, SUM(spend) sp FROM campaign_snapshots
                WHERE campaign_id IN ($ph) AND snapshot_date BETWEEN ? AND ?
                GROUP BY snapshot_date ORDER BY snapshot_date",
                array_merge($cids, [$d7, $today]));
            $byDay = []; foreach ($rows as $r) $byDay[$r['d']] = (float)$r['sp'];
            for ($i = 6; $i >= 0; $i--) {
                $dk = date('Y-m-d', strtotime("-$i days"));
                $card['spark'][] = $byDay[$dk] ?? 0;
            }
            $card['last7'] = array_sum($card['spark']);
            $pr = $db->fetch("SELECT SUM(spend) sp FROM campaign_snapshots
                WHERE campaign_id IN ($ph) AND snapshot_date BETWEEN ? AND ?",
                array_merge($cids, [$d14, $d8]));
            $card['prev7'] = (float)($pr['sp'] ?? 0);
        }
        // Pago (recibos) — se as tabelas existirem
        try {
            $pd = $db->fetch("SELECT SUM(i.value) v FROM paid_transaction_items i
                JOIN paid_transactions t ON t.transaction_id = i.transaction_id AND t.status = 'Pago'
                WHERE i.event_id = ?", [$ev['id']]);
            $card['paid'] = (float)($pd['v'] ?? 0);
        } catch (Exception $e) {}
        $eventCards[] = $card;
    }
}

// ── ALERTAS ───────────────────────────────────────────────────────
$alerts = [];
try {
    // Cron parado (>75 min sem sync) — só admin, que é quem resolve
    if ($isAdmin) {
        $ls = $db->fetch("SELECT MAX(created_at) m FROM sync_log");
        if ($ls && $ls['m'] && (time() - strtotime($ls['m'])) > 75 * 60) {
            $alerts[] = ['w', '⏱️ Sincronização parada desde ' . date('d/m H:i', strtotime($ls['m'])) . ' — verifique os crons em cron_status.php'];
        }
        // Cobranças recusadas nas últimas 48h
        $rf = $db->fetch("SELECT COUNT(*) c, COALESCE(SUM(total),0) v FROM paid_transactions
                          WHERE status = 'Falha' AND paid_at > NOW() - INTERVAL 2 DAY");
        if ($rf && $rf['c'] > 0) {
            $alerts[] = ['r', '💳 ' . $rf['c'] . ' cobrança' . ($rf['c'] > 1 ? 's' : '') . ' recusada' . ($rf['c'] > 1 ? 's' : '') . ' nas últimas 48h (R$ ' . number_format($rf['v'], 2, ',', '.') . ') — confira o cartão'];
        }
    }
    // Conta que gastava e zerou hoje (média 7d > R$50/dia, hoje = 0) — respeita permissões
    $zeroSql = "SELECT a.account_name FROM ad_accounts a
        JOIN (SELECT account_id, AVG(spend) av FROM spend_snapshots
              WHERE snapshot_date BETWEEN ? AND ? GROUP BY account_id HAVING av > 50) m
          ON m.account_id = a.account_id
        LEFT JOIN spend_snapshots hj ON hj.account_id = a.account_id AND hj.snapshot_date = ?
        WHERE a.status = 'ACTIVE' AND COALESCE(hj.spend, 0) = 0" . $accountWhere . " LIMIT 4";
    $zeroRows = $db->fetchAll($zeroSql,
        array_merge([date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('-1 day')), $today], $accountParams));
    if ($zeroRows && date('G') >= 10) { // antes das 10h dá falso positivo
        $names = implode(', ', array_slice(array_column($zeroRows, 'account_name'), 0, 3));
        $alerts[] = ['w', '🚫 Gasto zerado hoje em conta que vinha ativa: ' . $names . (count($zeroRows) > 3 ? '…' : '')];
    }
} catch (Exception $e) {}

// ── LOG DE ACESSOS (só admin) ─────────────────────────────────────
$accessRows = []; $accessOnline = []; $access7d = [];
if ($isAdmin) {
    try {
        $accessRows = $db->fetchAll("SELECT * FROM access_log ORDER BY created_at DESC LIMIT 25");
        // "online": último login (sem logout depois) nas últimas 2h
        $accessOnline = $db->fetchAll("
            SELECT l.username, MAX(l.created_at) AS ultimo
            FROM access_log l
            WHERE l.event IN ('login','login_auto') AND l.created_at > NOW() - INTERVAL 2 HOUR
              AND NOT EXISTS (SELECT 1 FROM access_log o WHERE o.username = l.username
                              AND o.event = 'logout' AND o.created_at > l.created_at)
            GROUP BY l.username ORDER BY ultimo DESC");
        $access7d = $db->fetchAll("
            SELECT username, COUNT(*) n, MAX(created_at) ultimo
            FROM access_log WHERE event IN ('login','login_auto') AND created_at > NOW() - INTERVAL 7 DAY
            GROUP BY username ORDER BY n DESC LIMIT 12");
        $falhas = $db->fetch("SELECT COUNT(*) c FROM access_log
                              WHERE event IN ('login_falha','bloqueado') AND created_at > NOW() - INTERVAL 24 HOUR");
        if ($falhas && $falhas['c'] >= 5) {
            $alerts[] = ['w', '🔐 ' . $falhas['c'] . ' tentativas de login falhas nas últimas 24h — veja o log de acessos abaixo'];
        }
    } catch (Exception $e) {}
}

$hasToken = $api->hasToken();
$role     = Auth::getRole();

function fmtR($v) { return 'R$ ' . number_format(floatval($v), 2, ',', '.'); }
function fmtN($v) { return number_format(floatval($v), 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MetaDash — Dashboard</title>
<?php echoThemeAssets(''); ?>
<style>
.totals-section-label { font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--mt);font-weight:600;margin-bottom:14px; }
.page-actions { display:flex;gap:8px;align-items:center;flex:none; }
.spinner { display:inline-block;width:13px;height:13px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
.alert { display:flex;align-items:center;gap:10px;padding:13px 18px;border-radius:12px;margin-bottom:22px;font-size:13px; }
.alert-warning { background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);color:var(--yw); }
#toast { position:fixed;bottom:24px;right:24px;background:var(--s2);border:1px solid var(--bd);border-radius:12px;padding:13px 20px;font-size:13px;color:var(--tx);z-index:999;opacity:0;pointer-events:none;transition:opacity .25s;max-width:340px; }
#toast.show { opacity:1;pointer-events:auto; }
.seg { display:flex;border:1px solid var(--bd);border-radius:10px;overflow:hidden; }
.seg-item { padding:8px 14px;font-size:12px;font-weight:600;color:var(--mt2);text-decoration:none;border-right:1px solid var(--bd);transition:background .15s; }
.seg-item:last-child { border-right:none; }
.seg-item:hover { background:var(--s2); }
.seg-item.on { background:var(--s2);color:var(--tx); }
.btn-ghost-sync { display:flex;align-items:center;justify-content:center;width:34px;height:34px;border:1px solid var(--bd);border-radius:9px;background:none;color:var(--mt2);cursor:pointer; }
.btn-ghost-sync:hover { background:var(--s2);color:var(--tx); }
</style>
</head>
<body>

<div class="app-wrap">
<?php
$currentPage = 'index';
require __DIR__ . '/includes/sidebar.php';
?>
<main class="main">

  <!-- Page header -->
  <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:24px;flex-wrap:wrap;">
    <div>
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <h1 class="page-title" style="margin:0">Visão geral</h1>
        <div class="seg">
          <a href="?v=eventos" class="seg-item <?= $view === 'eventos' ? 'on' : '' ?>">🎪 Eventos</a>
          <a href="?v=contas&p=<?= $period ?>" class="seg-item <?= $view === 'contas' ? 'on' : '' ?>">📊 Contas</a>
        </div>
      </div>
      <p class="page-sub"><?= $view === 'eventos'
          ? count($eventCards) . ' evento' . (count($eventCards) != 1 ? 's' : '') . ' ativo' . (count($eventCards) != 1 ? 's' : '') . ' · janela: campanha de cada evento'
          : $periodSub . ' &middot; ' . $withDataCount . ' conta' . ($withDataCount != 1 ? 's' : '') . ' com dados' ?></p>
    </div>
    <div class="page-actions">
      <?php if ($view === 'contas'): ?>
      <div class="seg">
        <?php foreach ($periods as $pk => $plabel): ?>
        <a href="?v=contas&p=<?= $pk ?>" class="seg-item <?= $period === $pk ? 'on' : '' ?>"><?= $plabel ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
      <button class="btn-ghost-sync" onclick="runSync()" id="syncBtn" title="Forçar sincronização agora (o sistema já sincroniza sozinho a cada 30 min)">
        <svg width="13" height="13" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12.5 6.5a5 5 0 1 0 .3 2.5"/><path d="M12.8 3v3.5h-3.5"/></svg>
      </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$hasToken): ?>
  <div class="alert alert-warning">
    <svg width="15" height="15" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M7.5 1L13.5 13H1.5L7.5 1z"/><line x1="7.5" y1="6" x2="7.5" y2="9"/><circle cx="7.5" cy="11" r=".5" fill="currentColor"/></svg>
    Token da API do Meta não configurado. <a href="settings.php" style="color:inherit;font-weight:600;margin-left:4px;">Configurar →</a>
  </div>
  <?php endif; ?>

  <?php foreach ($alerts as $al): ?>
  <div class="alert" style="<?= $al[0] === 'r' ? 'background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.25);color:#f87171' : 'background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);color:var(--yw)' ?>">
    <?= $al[1] ?>
  </div>
  <?php endforeach; ?>

  <?php if ($view === 'eventos'): ?>
  <?php if (empty($eventCards)): ?>
  <div style="text-align:center;padding:60px 20px;color:var(--mt2);">
    <div style="font-size:30px;margin-bottom:10px">🎪</div>
    <div style="font-size:14px;">Nenhum evento ativo<?= $isAdmin ? '' : ' liberado pra você' ?>.</div>
    <div style="margin-top:6px;font-size:12px;color:var(--mt);">Crie eventos na tela <a href="events.php" style="color:var(--ac)">Eventos</a><?= $isAdmin ? '' : ' ou peça acesso ao admin' ?>.</div>
  </div>
  <?php else: ?>
  <div class="cards-grid">
    <?php foreach ($eventCards as $c):
      $ev = $c['ev'];
      $daysLeft = (int)floor((strtotime($ev['date_event']) - strtotime($today)) / 86400);
      $budget   = (float)$ev['budget'];
      $pct      = $budget > 0 ? min(100, round($c['spend'] / $budget * 100)) : 0;
      $avg7     = $c['last7'] / 7;
      $cpl      = $c['leads'] > 0 ? $c['spend'] / $c['leads'] : 0;
      $cpa      = $c['purchases'] > 0 ? $c['spend'] / $c['purchases'] : 0;
      $roasEv   = $c['spend'] > 0 ? round($c['revenue'] / $c['spend'], 2) : 0;
      // projeção de verba no ritmo dos últimos 7 dias
      $runway = null;
      if ($budget > 0 && $avg7 > 1) {
          $diasVerba = (int)floor(($budget - $c['spend']) / $avg7);
          $runway = ['dias' => $diasVerba, 'data' => date('d/m', strtotime("+{$diasVerba} days")),
                     'antes' => $diasVerba < $daysLeft];
      }
      $dCor = $daysLeft <= 7 ? '#f87171' : ($daysLeft <= 14 ? 'var(--yw)' : 'var(--mt2)');
      $barCor = $pct >= 95 ? '#f87171' : ($pct >= 75 ? 'var(--yw)' : 'var(--ac)');
      // sparkline
      $spark = '';
      if ($c['spark'] && max($c['spark']) > 0) {
          $mx = max($c['spark']); $pts = [];
          foreach ($c['spark'] as $i => $v) { $pts[] = round($i * (100/6), 1) . ',' . round(26 - ($v / $mx) * 22, 1); }
          $spark = '<svg width="100" height="28" viewBox="0 0 100 28" style="display:block"><polyline fill="none" stroke="var(--ac)" stroke-width="1.6" points="' . implode(' ', $pts) . '"/></svg>';
      }
      $gap = ($c['paid'] !== null) ? $c['spend'] - $c['paid'] : null;
    ?>
    <div class="card">
      <div class="card-header">
        <div>
          <div class="card-name"><?= htmlspecialchars($ev['name']) ?></div>
          <div class="card-acct"><?= htmlspecialchars($ev['account_name'] ?? $ev['account_id']) ?></div>
        </div>
        <span style="font-size:12px;font-weight:800;color:<?= $dCor ?>;white-space:nowrap">
          <?= $daysLeft > 0 ? "faltam {$daysLeft}d" : ($daysLeft === 0 ? 'É HOJE' : abs($daysLeft) . 'd atrás') ?>
        </span>
      </div>
      <div style="font-size:11px;color:var(--mt);margin:-4px 0 8px">📅 <?= date('d/m/Y', strtotime($ev['date_event'])) ?> · campanha desde <?= date('d/m', strtotime($ev['date_campaign_start'] ?: $ev['created_at'])) ?></div>

      <?php if (!$c['has_campaigns']): ?>
      <div style="font-size:12px;color:var(--mt);padding:8px 0">Nenhuma campanha vinculada — vincule na aba Campanhas do evento.</div>
      <?php else: ?>

      <?php if ($budget > 0): ?>
      <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--mt);margin-bottom:4px">
        <span>Verba: <b style="color:var(--tx)">R$ <?= number_format($c['spend'],0,',','.') ?></b> / R$ <?= number_format($budget,0,',','.') ?></span>
        <span style="font-weight:700;color:<?= $barCor ?>"><?= $pct ?>%</span>
      </div>
      <div style="height:6px;background:var(--s2);border-radius:4px;overflow:hidden;margin-bottom:6px">
        <div style="height:100%;width:<?= $pct ?>%;background:<?= $barCor ?>"></div>
      </div>
      <?php if ($runway): ?>
      <div style="font-size:11px;margin-bottom:10px;color:<?= $runway['antes'] && $daysLeft > 0 ? '#f87171' : 'var(--gr, #34d399)' ?>">
        <?= $runway['antes'] && $daysLeft > 0
            ? '⚠️ No ritmo atual a verba acaba ~' . $runway['data'] . ' (' . max(0, $daysLeft - $runway['dias']) . 'd antes do evento)'
            : '✅ No ritmo atual a verba cobre até o evento' ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <div class="card-metrics">
        <div><div class="metric-label">Gasto (campanha)</div><div class="metric-value red">R$ <?= number_format($c['spend'],0,',','.') ?></div></div>
        <?php if ($c['revenue'] > 0): ?>
        <div><div class="metric-label">Faturamento</div><div class="metric-value green">R$ <?= number_format($c['revenue'],0,',','.') ?></div></div>
        <div><div class="metric-label">ROAS</div><div class="metric-value <?= $roasEv >= 3 ? 'green' : ($roasEv >= 1.5 ? '' : 'red') ?>"><?= $roasEv ?>x</div></div>
        <?php endif; ?>
        <?php if ($c['purchases'] > 0): ?>
        <div><div class="metric-label">Vendas</div><div class="metric-value"><?= number_format($c['purchases'],0,',','.') ?></div></div>
        <div><div class="metric-label">Custo/Venda</div><div class="metric-value blue">R$ <?= number_format($cpa,2,',','.') ?></div></div>
        <?php endif; ?>
        <?php if ($c['leads'] > 0): ?>
        <div><div class="metric-label">Leads</div><div class="metric-value"><?= number_format($c['leads'],0,',','.') ?></div></div>
        <div><div class="metric-label">Custo/Lead</div><div class="metric-value blue">R$ <?= number_format($cpl,2,',','.') ?></div></div>
        <?php endif; ?>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:10px;margin-top:10px">
        <div>
          <div style="font-size:10px;color:var(--mt);text-transform:uppercase;letter-spacing:.05em">Últimos 7 dias</div>
          <div style="font-size:12px;font-weight:700">R$ <?= number_format($c['last7'],0,',','.') ?><?= deltaBadge($c['last7'], $c['prev7'], 0, 1.0) ?></div>
        </div>
        <?= $spark ?>
      </div>

      <?php if ($c['paid'] !== null && $c['paid'] > 0): ?>
      <div style="font-size:11px;color:var(--mt);margin-top:8px;border-top:1px solid var(--bd);padding-top:8px">
        💳 Pago: <b style="color:var(--tx)">R$ <?= number_format($c['paid'],0,',','.') ?></b>
        · Veiculado: R$ <?= number_format($c['spend'],0,',','.') ?>
        <?php if ($gap !== null && $gap > 500 && $c['spend'] > 0 && $gap / $c['spend'] > 0.15): ?>
        <span style="color:var(--yw);font-weight:600"> · ⚠️ R$ <?= number_format($gap,0,',','.') ?> ainda não cobrado</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php endif; ?>
      <div class="card-footer">
        <a href="event.php?id=<?= $ev['id'] ?>" style="color:var(--ac);text-decoration:none;font-weight:600">Abrir evento →</a>
        <span></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php else: /* ══ VISÃO CONTAS ══ */ ?>

  <!-- Stat bar -->
  <?php if ($totToday['spend'] > 0 || $totToday['leads'] > 0): ?>
  <div class="stat-bar">
    <div class="stat-item">
      <div class="stat-label">Gasto Total</div>
      <div class="stat-value red"><?= fmtR($totToday['spend']) ?><?= deltaBadge($totToday['spend'], $totPrev['spend'], 0, $prevScale) ?></div>
    </div>
    <?php if ($totToday['revenue'] > 0): ?>
    <div class="stat-item">
      <div class="stat-label">Faturamento</div>
      <div class="stat-value green"><?= fmtR($totToday['revenue']) ?><?= deltaBadge($totToday['revenue'], $totPrev['revenue'], 1, $prevScale) ?></div>
    </div>
    <div class="stat-item">
      <div class="stat-label">ROAS</div>
      <div class="stat-value blue"><?= $totToday['roas'] ?>x<?= deltaBadge($totToday['roas'], $totPrev['roas'], 1, 1.0) ?></div>
    </div>
    <div class="stat-item">
      <div class="stat-label">ROI</div>
      <div class="stat-value <?= $totToday['roi'] >= 0 ? 'green' : 'red' ?>"><?= $totToday['roi'] ?>%<?= deltaBadge($totToday['roi'], $totPrev['roi'], 1, 1.0) ?></div>
    </div>
    <div class="stat-item">
      <div class="stat-label">Compras</div>
      <div class="stat-value"><?= fmtN($totToday['purchases']) ?><?= deltaBadge($totToday['purchases'], $totPrev['purchases'], 1, $prevScale) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($totToday['leads'] > 0): ?>
    <div class="stat-item">
      <div class="stat-label">Leads</div>
      <div class="stat-value"><?= fmtN($totToday['leads']) ?><?= deltaBadge($totToday['leads'], $totPrev['leads'], 1, $prevScale) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Contas -->
  <div class="totals-section-label">
    Contas <span style="color:var(--mt2)">&middot; <?= count($accountsToday) ?></span>
  </div>

  <?php if (empty($accountsToday)): ?>
  <div style="text-align:center;padding:60px 20px;color:var(--mt2);">
    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" style="opacity:.4;display:block;margin:0 auto 12px"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
    <div style="font-size:14px;">Nenhuma conta com dados no período</div>
    <div style="margin-top:6px;font-size:12px;color:var(--mt);">A sincronização roda automaticamente a cada 30 minutos.</div>
  </div>
  <?php else: ?>

  <div class="cards-grid">
    <?php foreach ($accountsToday as $acc):
      $spend    = floatval($acc['spend']);
      $revenue  = floatval($acc['revenue']);
      $purchases= intval($acc['purchases']);
      $leads    = intval($acc['leads']);
      $roas     = $spend > 0 ? round($revenue / $spend, 2) : 0;
      $cpa      = $purchases > 0 ? round($spend / $purchases, 2) : 0;
      $cpl      = $leads > 0 ? round($spend / $leads, 2) : 0;
      $prev     = $prevByAccount[$acc['account_id']] ?? null;
      $hasData  = $spend > 0 || $leads > 0 || $purchases > 0;
      $roi      = $spend > 0 ? round((($revenue - $spend) / $spend) * 100, 1) : 0;
      $isActive = $acc['status'] === 'ACTIVE';
    ?>
    <div class="card" style="<?= !$hasData ? 'opacity:.5' : '' ?>">

      <!-- Card header -->
      <div class="card-header">
        <div>
          <div class="card-name"><?= htmlspecialchars($acc['account_name']) ?></div>
          <div class="card-acct"><?= htmlspecialchars($acc['account_id']) ?></div>
        </div>
        <span class="status-badge <?= $isActive ? 'active' : 'paused' ?>">
          <span class="status-dot"></span>
          <?= $isActive ? 'Ativa' : 'Pausada' ?>
        </span>
      </div>

      <?php if (!$hasData): ?>
      <div style="font-size:12px;color:var(--mt);text-align:center;padding:8px 0;">
        <?= $isActive ? 'Sem dados hoje' : 'Conta inativa' ?>
      </div>
      <?php else: ?>

      <!-- Metrics grid -->
      <div class="card-metrics">

        <div>
          <div class="metric-label">Gasto</div>
          <div class="metric-value red"><?= fmtR($spend) ?><?= $prev ? deltaBadge($spend, $prev['spend'], 0, $prevScale) : '' ?></div>
        </div>

        <?php if ($revenue > 0): ?>
        <div>
          <div class="metric-label">Faturamento</div>
          <div class="metric-value green"><?= fmtR($revenue) ?><?= $prev ? deltaBadge($revenue, $prev['revenue'], 1, $prevScale) : '' ?></div>
        </div>
        <div>
          <div class="metric-label">ROAS</div>
          <div class="metric-value <?= $roas >= 3 ? 'green' : ($roas >= 1.5 ? '' : 'red') ?>"><?= $roas > 0 ? $roas.'x' : '—' ?></div>
        </div>
        <div>
          <div class="metric-label">ROI</div>
          <div class="metric-value <?= $roi >= 0 ? 'green' : 'red' ?>"><?= $roi ?>%</div>
        </div>
        <?php elseif ($leads > 0): ?>
        <div>
          <div class="metric-label">Leads</div>
          <div class="metric-value"><?= fmtN($leads) ?><?= $prev ? deltaBadge($leads, $prev['leads'], 1, $prevScale) : '' ?></div>
        </div>
        <?php endif; ?>

        <?php if ($purchases > 0): ?>
        <div>
          <div class="metric-label">Vendas</div>
          <div class="metric-value"><?= fmtN($purchases) ?><?= $prev ? deltaBadge($purchases, $prev['purchases'], 1, $prevScale) : '' ?></div>
        </div>
        <div>
          <div class="metric-label">Custo/Venda</div>
          <div class="metric-value blue"><?= $cpa > 0 ? fmtR($cpa) : '—' ?></div>
        </div>
        <?php endif; ?>

        <?php if ($leads > 0 && $revenue > 0): ?>
        <div>
          <div class="metric-label">Leads</div>
          <div class="metric-value"><?= fmtN($leads) ?><?= $prev ? deltaBadge($leads, $prev['leads'], 1, $prevScale) : '' ?></div>
        </div>
        <div>
          <div class="metric-label">Custo/Lead</div>
          <div class="metric-value blue"><?= $cpl > 0 ? fmtR($cpl) : '—' ?></div>
        </div>
        <?php elseif ($leads > 0 && $cpl > 0): ?>
        <div>
          <div class="metric-label">Custo/Lead</div>
          <div class="metric-value blue"><?= fmtR($cpl) ?></div>
        </div>
        <?php endif; ?>

      </div>
      <?php endif; ?>

      <!-- Card footer -->
      <div class="card-footer">
        <span>Sync <?= $acc['last_sync'] ? date('d/m H:i', strtotime($acc['last_sync'])) : 'nunca' ?></span>
        <?php if ($hasData && $isActive): ?>
        <span><span class="sync-dot"></span><?= $periods[$period] ?></span>
        <?php endif; ?>
      </div>

    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php endif; /* fim visão contas */ ?>

  <?php if ($isAdmin && ($accessRows || $accessOnline)): ?>
  <div class="totals-section-label" style="margin-top:34px">
    <a href="access_log.php" style="color:inherit;text-decoration:none">Acessos →</a>
    <?php if ($accessOnline): ?>
    <span style="color:var(--gr, #34d399);font-weight:600;text-transform:none;letter-spacing:0"> &middot; <?= count($accessOnline) ?> ativo<?= count($accessOnline) != 1 ? 's' : '' ?> agora</span>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px">

    <div class="card">
      <div style="font-size:12px;font-weight:700;margin-bottom:10px">🟢 Online agora / logins por usuário (7 dias)</div>
      <?php if ($accessOnline): ?>
      <div style="margin-bottom:12px">
        <?php foreach ($accessOnline as $on): ?>
        <div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0">
          <span><span class="sync-dot"></span> <b><?= htmlspecialchars($on['username']) ?></b></span>
          <span style="color:var(--mt)">desde <?= date('H:i', strtotime($on['ultimo'])) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php foreach ($access7d as $u7):
        $mx = max(1, (int)$access7d[0]['n']); $w = round((int)$u7['n'] / $mx * 100); ?>
      <div style="display:flex;align-items:center;gap:8px;font-size:11px;padding:2px 0">
        <span style="width:88px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($u7['username']) ?></span>
        <span style="flex:1;height:5px;background:var(--s2);border-radius:3px;overflow:hidden"><span style="display:block;height:100%;width:<?= $w ?>%;background:var(--ac)"></span></span>
        <span style="color:var(--mt);width:78px;text-align:right"><?= $u7['n'] ?>x · <?= date('d/m H:i', strtotime($u7['ultimo'])) ?></span>
      </div>
      <?php endforeach; ?>
      <?php if (!$access7d && !$accessOnline): ?>
      <div style="font-size:12px;color:var(--mt)">Nenhum acesso registrado ainda.</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div style="font-size:12px;font-weight:700;margin-bottom:10px">🕘 Últimos acessos</div>
      <div style="max-height:280px;overflow:auto">
        <?php
        $evMap = [
          'login'       => ['Entrou',            'var(--gr, #34d399)'],
          'login_auto'  => ['Entrou (lembrar)',  'var(--mt2)'],
          'logout'      => ['Saiu',              'var(--mt)'],
          'login_falha' => ['Senha errada',      '#f87171'],
          'bloqueado'   => ['Bloqueado',         '#f87171'],
        ];
        foreach ($accessRows as $ar):
          $lbl = $evMap[$ar['event']] ?? [$ar['event'], 'var(--mt)'];
          $ua = (string)$ar['user_agent'];
          $dev = preg_match('/(iPhone|iPad|Android|Mobile)/i', $ua) ? '📱' : '💻';
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:11.5px;padding:5px 0;border-bottom:1px solid var(--bd)">
          <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= $dev ?> <b><?= htmlspecialchars($ar['username'] ?: '—') ?></b>
            <span style="color:<?= $lbl[1] ?>"> · <?= $lbl[0] ?></span>
          </span>
          <span style="color:var(--mt);white-space:nowrap;font-size:10.5px">
            <?= htmlspecialchars((string)$ar['ip']) ?> · <?= date('d/m H:i', strtotime($ar['created_at'])) ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
  <?php endif; ?>

</main>
</div>

<div id="toast"></div>

<script>
function showToast(msg, dur) {
  var t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  setTimeout(function(){ t.classList.remove('show'); }, dur || 3000);
}

async function runSync() {
  var btn = document.getElementById('syncBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Sincronizando...';
  showToast('⏳ Sincronizando contas...', 15000);
  try {
    var res  = await fetch('api/sync.php', { method: 'POST' });
    var data = await res.json();
    if (data.success) {
      showToast('✅ ' + data.message);
      setTimeout(function(){ location.reload(); }, 1400);
    } else {
      showToast('❌ ' + (data.message || 'Erro na sincronização'));
      btn.disabled = false;
      btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 15 15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12.5 6.5a5 5 0 1 0 .3 2.5"/><path d="M12.8 3v3.5h-3.5"/></svg> Sincronizar';
    }
  } catch(e) {
    showToast('❌ Erro de conexão');
    btn.disabled = false;
  }
}
</script>
</body>
</html>
