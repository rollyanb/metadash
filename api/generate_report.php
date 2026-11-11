<?php
// api/generate_report.php
// Gera HTML de relatório de evento para impressão/PDF via browser
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tax_helper.php';
require_once __DIR__ . '/../config/database.php';

Auth::requireLogin();

$db      = Database::getInstance();
$uid     = Auth::getUserId();
$role    = Auth::getRole();
$isAdmin = in_array($role, ['admin','chefe']);

$eventId = intval($_GET['event_id'] ?? 0);
$type    = $_GET['type'] ?? 'parcial'; // 'parcial' ou 'final'

if (!$eventId) { die('Evento não encontrado.'); }

// Verifica acesso
if (!$isAdmin) {
    $access = $db->fetch("SELECT 1 FROM event_user_access WHERE event_id=? AND user_id=?", [$eventId, $uid]);
    if (!$access) { die('Sem permissão.'); }
}

// Busca dados do evento
$event = $db->fetch("SELECT e.*, a.account_name FROM events e LEFT JOIN ad_accounts a ON a.account_id = e.account_id WHERE e.id=?", [$eventId]);
// Evento pode rodar em mais de uma conta: mostra todas no cabeçalho do relatório
if ($event) {
    try {
        $nomesContas = array_column($db->fetchAll(
            "SELECT COALESCE(a.account_name, ea.account_id) AS nome
             FROM event_accounts ea
             LEFT JOIN ad_accounts a ON a.account_id = ea.account_id
             WHERE ea.event_id = ? ORDER BY ea.id ASC", [$eventId]), 'nome');
        if ($nomesContas) {
            $event['account_name'] = count($nomesContas) > 1
                ? $nomesContas[0] . ' +' . (count($nomesContas) - 1)
                : $nomesContas[0];
        }
    } catch (Exception $e) {}
}
if (!$event) { die('Evento não encontrado.'); }

// Validação: relatório final só para evento encerrado
if ($type === 'final' && $event['status'] !== 'finished') {
    die('Relatório final disponível apenas após encerramento do evento.');
}

// Período
$customStart = $_GET['date_start'] ?? null;
$customEnd   = $_GET['date_end']   ?? null;

if ($type === 'parcial' && $customStart && $customEnd) {
    // Período customizado escolhido no menu (hoje, ontem, 3/7/15/30 dias)
    $dateStart = $customStart;
    $dateEnd   = $customEnd;
} else {
    // Padrão: total até agora (ou até o fim da campanha, se for relatório final)
    $dateStart = $event['date_campaign_start'] ?: $event['created_at'];
    $dateEnd   = ($type === 'final' && $event['date_campaign_end'])
        ? $event['date_campaign_end']
        : date('Y-m-d');
}

// Campanhas vinculadas
$campaigns = $db->fetchAll(
    "SELECT campaign_id, campaign_name, objective, status FROM event_campaigns WHERE event_id=? ORDER BY objective, campaign_name",
    [$eventId]
);
$campaignIds = array_column($campaigns, 'campaign_id');

// ── GASTO TOTAL DO EVENTO (sempre todo o período, independente do filtro escolhido) ──
// Usado exclusivamente na barra de Budget, que é um teto fixo do evento inteiro
$spendTotalEvent = 0;
if (!empty($campaignIds)) {
    $phTotal = implode(',', array_fill(0, count($campaignIds), '?'));
    $dataTotal = $db->fetch("
        SELECT SUM(spend) as spend
        FROM campaign_snapshots
        WHERE campaign_id IN ({$phTotal})
    ", $campaignIds);
    $spendTotalEvent = floatval($dataTotal['spend'] ?? 0);
}

// ── MÉTRICAS GERAIS (filtradas pelo período escolhido) ──────────
$perf = ['spend'=>0,'purchases'=>0,'leads'=>0,'revenue'=>0,'impressions'=>0,'clicks'=>0];

if (!empty($campaignIds)) {
    $ph   = implode(',', array_fill(0, count($campaignIds), '?'));
    $data = $db->fetch("
        SELECT SUM(spend) as spend, SUM(purchases) as purchases, SUM(leads) as leads,
               SUM(revenue) as revenue, SUM(impressions) as impressions, SUM(clicks) as clicks
        FROM campaign_snapshots
        WHERE campaign_id IN ({$ph}) AND snapshot_date BETWEEN ? AND ?
    ", array_merge($campaignIds, [$dateStart, $dateEnd]));
    if ($data) $perf = $data;
}

$spend       = floatval($perf['spend'] ?? 0);
$purchases   = intval($perf['purchases'] ?? 0);
$leads       = intval($perf['leads'] ?? 0);
$revenue     = floatval($perf['revenue'] ?? 0);
$impressions = intval($perf['impressions'] ?? 0);
$clicks      = intval($perf['clicks'] ?? 0);
$roas        = $spend > 0 ? round($revenue / $spend, 2) : 0;
$roi         = $spend > 0 ? round((($revenue - $spend) / $spend) * 100, 1) : 0;
$cpa         = $purchases > 0 ? $spend / $purchases : 0;
$cpl         = $leads > 0 ? $spend / $leads : 0;

// Imposto Meta Ads sobre o gasto do período exibido + total geral
// (ROAS/ROI/CPA/CPL acima continuam calculados só sobre $spend, de propósito)
$metaTax    = calcMetaTax($spend);
$taxTotal   = $metaTax['total'];
$totalGasto = $metaTax['total_geral'];
$ctr         = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0;
$cpc         = $clicks > 0 ? $spend / $clicks : 0;
$cpm         = $impressions > 0 ? ($spend / $impressions) * 1000 : 0;

// Budget e % utilizado SEMPRE usam o gasto total do evento, não o período filtrado
$budget      = floatval($event['budget'] ?? 0);
$budgetPct   = $budget > 0 ? min(100, round($spendTotalEvent / $budget * 100, 1)) : 0;

// ── MÉTRICAS POR OBJETIVO ──────────────────────────────────────
$byObjective = [];
foreach ($campaigns as $camp) {
    $obj = $camp['objective'] ?: 'OUTROS';
    if (!isset($byObjective[$obj])) {
        $byObjective[$obj] = [
            'campaigns' => [],
            'spend'=>0,'purchases'=>0,'leads'=>0,'revenue'=>0,'impressions'=>0,'clicks'=>0
        ];
    }

    // Métricas individuais da campanha
    $cs = $db->fetch("
        SELECT SUM(spend) as spend, SUM(purchases) as purchases, SUM(leads) as leads,
               SUM(revenue) as revenue, SUM(impressions) as impressions, SUM(clicks) as clicks
        FROM campaign_snapshots
        WHERE campaign_id = ? AND snapshot_date BETWEEN ? AND ?
    ", [$camp['campaign_id'], $dateStart, $dateEnd]);

    $cs = $cs ?: ['spend'=>0,'purchases'=>0,'leads'=>0,'revenue'=>0,'impressions'=>0,'clicks'=>0];
    $cs_spend = floatval($cs['spend']);
    $cs_imp   = intval($cs['impressions']);
    $cs_clk   = intval($cs['clicks']);

    $camp['metrics'] = [
        'spend'       => $cs_spend,
        'purchases'   => intval($cs['purchases']),
        'leads'       => intval($cs['leads']),
        'revenue'     => floatval($cs['revenue']),
        'impressions' => $cs_imp,
        'clicks'      => $cs_clk,
        'ctr'         => $cs_imp > 0 ? round(($cs_clk/$cs_imp)*100,2) : 0,
        'cpc'         => $cs_clk > 0 ? $cs_spend/$cs_clk : 0,
        'cpm'         => $cs_imp > 0 ? ($cs_spend/$cs_imp)*1000 : 0,
        'roas'        => $cs_spend > 0 ? round(floatval($cs['revenue'])/$cs_spend,2) : 0,
    ];

    $byObjective[$obj]['campaigns'][]   = $camp;
    $byObjective[$obj]['spend']        += $cs_spend;
    $byObjective[$obj]['purchases']    += intval($cs['purchases']);
    $byObjective[$obj]['leads']        += intval($cs['leads']);
    $byObjective[$obj]['revenue']      += floatval($cs['revenue']);
    $byObjective[$obj]['impressions']  += $cs_imp;
    $byObjective[$obj]['clicks']       += $cs_clk;
}

// ── GASTO POR DIA ──────────────────────────────────────────────
$daily = [];
if (!empty($campaignIds)) {
    $ph    = implode(',', array_fill(0, count($campaignIds), '?'));
    $daily = $db->fetchAll("
        SELECT snapshot_date, SUM(spend) as spend, SUM(purchases) as purchases, SUM(leads) as leads
        FROM campaign_snapshots
        WHERE campaign_id IN ({$ph}) AND snapshot_date BETWEEN ? AND ?
        GROUP BY snapshot_date ORDER BY snapshot_date ASC
    ", array_merge($campaignIds, [$dateStart, $dateEnd]));
}

// ── HELPERS ────────────────────────────────────────────────────
function fmtR($v) { return 'R$' . number_format(floatval($v), 2, ',', '.'); }
function fmtN($v) { return number_format(floatval($v), 0, ',', '.'); }
function fmtP($v) { return number_format(floatval($v), 2, ',', '.') . '%'; }

$objLabels = [
    'OUTCOME_SALES'       => ['label'=>'Vendas',       'icon'=>'🛒', 'color'=>'#34d399'],
    'OUTCOME_LEADS'       => ['label'=>'Captação',     'icon'=>'📋', 'color'=>'#a78bfa'],
    'OUTCOME_TRAFFIC'     => ['label'=>'Tráfego',      'icon'=>'🚦', 'color'=>'#4f6ef7'],
    'OUTCOME_ENGAGEMENT'  => ['label'=>'Engajamento',  'icon'=>'❤️', 'color'=>'#fbbf24'],
    'OUTCOME_AWARENESS'   => ['label'=>'Alcance',      'icon'=>'👁️', 'color'=>'#fb923c'],
    'OUTCOME_APP_PROMOTION'=> ['label'=>'App',         'icon'=>'📱', 'color'=>'#f87171'],
    'OUTROS'              => ['label'=>'Outros',       'icon'=>'📣', 'color'=>'#8888a0'],
];

$typeLabel  = $type === 'final' ? 'Relatório Final' : 'Resultado Parcial';
$eventDate  = $event['date_event'] ? date('d/m/Y', strtotime($event['date_event'])) : '—';
$genDate    = date('d/m/Y \à\s H:i');
$periodStr  = date('d/m/Y', strtotime($dateStart)) . ' a ' . date('d/m/Y', strtotime($dateEnd));

// Gráfico de barras SVG
$maxSpend = 1;
foreach ($daily as $d) { if (floatval($d['spend']) > $maxSpend) $maxSpend = floatval($d['spend']); }
$barW   = 28;
$gap    = 6;
$chartH = 120;
$totalW = count($daily) * ($barW + $gap);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($event['name']) ?> — <?= $typeLabel ?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap');

  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg: #f4f6fb;
    --white: #ffffff;
    --dark: #0f1020;
    --dark2: #1a1a2e;
    --muted: #4b5563;
    --muted2: #6b7280;
    --border: rgba(0,0,0,0.08);
    --accent: #4f6ef7;
    --accent2: #a78bfa;
    --green: #34d399;
    --red: #f87171;
    --yellow: #fbbf24;
    --orange: #fb923c;
  }

  body {
    font-family: 'Inter', sans-serif;
    background: var(--bg);
    color: var(--dark);
    font-size: 13px;
    line-height: 1.5;
  }

  .page {
    max-width: 900px;
    margin: 0 auto;
    padding: 32px 24px;
  }

  /* ── CAPA ── */
  .cover {
    background: #ffffff;
    border: 2px solid var(--dark);
    border-radius: 16px;
    padding: 40px 36px;
    margin-bottom: 24px;
    color: var(--dark);
    position: relative;
    overflow: hidden;
  }
  .cover-accent-bar {
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 8px;
    background: linear-gradient(90deg, #4f6ef7, #a78bfa);
  }
  .cover-logo {
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 800;
    color: #6b7280;
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-bottom: 28px;
    margin-top: 8px;
  }
  .cover-logo span { color: #4f6ef7; }
  .cover-badge {
    display: inline-block;
    padding: 5px 16px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    font-family: 'Inter', sans-serif;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 16px;
    border-width: 2px;
    border-style: solid;
  }
  .badge-parcial { background: #fffbeb; color: #b45309; border-color: #f59e0b; }
  .badge-final   { background: #ecfdf5; color: #047857; border-color: #10b981; }
  .cover-title {
    font-family: 'Inter', sans-serif;
    font-size: 32px;
    font-weight: 900;
    margin-bottom: 8px;
    line-height: 1.2;
    color: var(--dark);
  }
  .cover-sub {
    font-size: 14px;
    color: #4b5563;
    margin-bottom: 28px;
  }
  .cover-meta {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
    border-top: 2px solid #e5e7eb;
    padding-top: 18px;
    font-size: 12px;
    color: #6b7280;
  }
  .cover-meta > div { min-width: 110px; }
  .cover-meta strong { color: var(--dark); display: block; font-size: 14px; margin-top: 2px; white-space: nowrap; }

  /* ── SEÇÕES ── */
  .section {
    background: var(--white);
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 20px;
    border: 1px solid var(--border);
  }
  .section-title {
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--muted);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .section-title::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
  }

  /* ── KPI GRID ── */
  .kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 0;
  }
  .kpi-grid-3 { grid-template-columns: repeat(3, 1fr); }
  .kpi-grid-2 { grid-template-columns: repeat(2, 1fr); }

  .kpi-card {
    background: #f8f9fc;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 14px 16px;
    text-align: center;
  }
  .kpi-val {
    font-family: 'Inter', sans-serif;
    font-size: 20px;
    font-weight: 900;
    margin-bottom: 4px;
    line-height: 1;
  }
  .kpi-lbl {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--muted2);
  }
  .color-green  { color: #059669; }
  .color-red    { color: #dc2626; }
  .color-blue   { color: #4f6ef7; }
  .color-purple { color: #7c3aed; }
  .color-yellow { color: #d97706; }
  .color-dark   { color: var(--dark); }

  /* ── BUDGET BAR ── */
  .budget-bar-wrap {
    background: #f8f9fc;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 16px;
  }
  .budget-bar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
  .budget-bar-title { font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 700; }
  .budget-bar-track { height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden; margin-bottom: 6px; }
  .budget-bar-fill  { height: 100%; border-radius: 4px; }
  .bar-ok   { background: linear-gradient(90deg, #34d399, #4f6ef7); }
  .bar-warn { background: linear-gradient(90deg, #fbbf24, #fb923c); }
  .bar-over { background: linear-gradient(90deg, #fb923c, #f87171); }
  .budget-bar-info { font-size: 11px; color: var(--muted); display: flex; justify-content: space-between; }

  /* ── OBJETIVO BLOCO ── */
  .obj-block {
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 16px;
  }
  .obj-header {
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--border);
  }
  .obj-header-left { display: flex; align-items: center; gap: 8px; }
  .obj-icon { font-size: 16px; }
  .obj-name {
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 800;
  }
  .obj-count { font-size: 11px; color: var(--muted2); }
  .obj-kpis { display: flex; gap: 24px; font-size: 12px; }
  .obj-kpi-item { text-align: right; }
  .obj-kpi-val { font-family: 'Inter', sans-serif; font-weight: 700; font-size: 13px; }
  .obj-kpi-lbl { color: var(--muted2); font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }

  /* ── TABELA CAMPANHAS ── */
  .camp-table { width: 100%; border-collapse: collapse; font-size: 11px; }
  .camp-table th {
    padding: 8px 12px;
    text-align: left;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--muted2);
    background: #f8f9fc;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
  }
  .camp-table td {
    padding: 9px 12px;
    border-bottom: 1px solid #f0f2f8;
    vertical-align: middle;
  }
  .camp-table tr:last-child td { border-bottom: none; }
  .camp-table tr:hover td { background: #f8f9fc; }
  .camp-name-cell { font-weight: 500; max-width: 220px; word-break: break-word; }
  .status-dot {
    display: inline-block;
    width: 7px; height: 7px;
    border-radius: 50%;
    margin-right: 5px;
    flex-shrink: 0;
  }
  .dot-active { background: #34d399; }
  .dot-paused { background: #9ca3af; }
  .metric-val { font-family: 'Inter', sans-serif; font-weight: 700; font-size: 12px; }
  .text-right { text-align: right; }
  .text-green { color: #059669; }
  .text-blue  { color: #4f6ef7; }
  .text-muted { color: var(--muted2); }

  /* ── GRÁFICO ── */
  .chart-wrap {
    overflow-x: auto;
    padding-bottom: 4px;
  }
  .chart-svg { display: block; }

  /* ── RODAPÉ ── */
  .footer {
    text-align: center;
    font-size: 11px;
    color: var(--muted2);
    padding: 20px 0 8px;
    border-top: 1px solid var(--border);
    margin-top: 8px;
  }
  .footer strong { color: var(--accent); }

  /* ── PRINT ── */
  @media print {
    body { background: white; }
    .page { padding: 0; max-width: 100%; }
    .cover { border-radius: 0; margin: 0 0 20px; break-inside: avoid; }
    .section { border-radius: 8px; break-inside: avoid; page-break-inside: avoid; }
    .obj-block { break-inside: avoid; page-break-inside: avoid; }
    .no-print { display: none !important; }
    @page { margin: 16mm 12mm; size: A4; }

    /* Garante contraste em impressão real (tinta clara some no papel) */
    .kpi-lbl, .section-title, .budget-bar-info, .obj-count,
    .obj-kpi-lbl, .text-muted, .footer { color: #374151 !important; }
    .section-title::after { background: #d1d5db !important; }
  }
</style>
</head>
<body onload="setTimeout(function(){ if(window.location.search.indexOf('autoprint')>=0) window.print(); }, 600)">

<div class="page">

  <!-- ── BOTÃO IMPRIMIR ── -->
  <div class="no-print" style="text-align:right;margin-bottom:16px">
    <button onclick="window.print()" style="padding:10px 24px;background:linear-gradient(135deg,#4f6ef7,#a78bfa);border:none;border-radius:10px;color:#fff;font-family:'Inter',sans-serif;font-weight:700;font-size:14px;cursor:pointer">
      🖨️ Salvar / Imprimir PDF
    </button>
  </div>

  <!-- ── CAPA ── -->
  <div class="cover">
    <div class="cover-accent-bar"></div>
    <div class="cover-logo">Meta<span>Dash</span> · Next Management</div>
    <div class="cover-badge <?= $type==='final'?'badge-final':'badge-parcial' ?>">
      <?= $type==='final'?'✅ Relatório Final':'📊 Resultado Parcial' ?>
    </div>
    <div class="cover-title"><?= htmlspecialchars($event['name']) ?></div>
    <div class="cover-sub"><?= $event['account_name'] ? htmlspecialchars($event['account_name']) : 'Meta Ads' ?></div>
    <div class="cover-meta">
      <div><strong>Período</strong><?= $periodStr ?></div>
      <div><strong>Data do Evento</strong><?= $eventDate ?></div>
      <div><strong>Gerado em</strong><?= $genDate ?></div>
      <?php if ($budget > 0): ?>
      <div><strong>Budget</strong><?= fmtR($budget) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── RESUMO EXECUTIVO ── -->
  <div class="section">
    <div class="section-title">📊 Resumo Executivo</div>

    <?php if ($budget > 0): ?>
    <?php $barClass = $budgetPct >= 90 ? 'bar-over' : ($budgetPct >= 70 ? 'bar-warn' : 'bar-ok'); ?>
    <div class="budget-bar-wrap">
      <div class="budget-bar-header">
        <div class="budget-bar-title">💰 Budget do Evento</div>
        <div style="font-family:'Inter',sans-serif;font-weight:800;font-size:18px;color:#059669"><?= fmtR($spendTotalEvent) ?></div>
      </div>
      <div class="budget-bar-track">
        <div class="budget-bar-fill <?= $barClass ?>" style="width:<?= min(100,$budgetPct) ?>%"></div>
      </div>
      <div class="budget-bar-info">
        <span><?= $budgetPct ?>% utilizado de <?= fmtR($budget) ?> (total do evento)</span>
        <span>Restante: <strong><?= fmtR(max(0,$budget-$spendTotalEvent)) ?></strong></span>
      </div>
    </div>
    <?php endif; ?>

    <!-- KPIs de Alcance -->
    <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted2);margin-bottom:8px;font-family:'Inter',sans-serif;font-weight:700">Alcance & Entrega</div>
    <div class="kpi-grid" style="margin-bottom:16px">
      <div class="kpi-card">
        <div class="kpi-val color-dark"><?= fmtN($impressions) ?></div>
        <div class="kpi-lbl">Impressões</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val color-dark"><?= fmtN($clicks) ?></div>
        <div class="kpi-lbl">Cliques</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val color-blue"><?= fmtP($ctr) ?></div>
        <div class="kpi-lbl">CTR</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val color-dark"><?= fmtR($cpc) ?></div>
        <div class="kpi-lbl">CPC Médio</div>
      </div>
    </div>

    <!-- KPIs de Resultado -->
    <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted2);margin-bottom:8px;font-family:'Inter',sans-serif;font-weight:700">Resultados</div>
    <div class="kpi-grid" style="margin-bottom:<?= ($leads>0)?'16px':'0' ?>">
      <div class="kpi-card">
        <div class="kpi-val color-red"><?= fmtR($spend) ?></div>
        <div class="kpi-lbl">Valor Gasto em Tráfego</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val color-red"><?= fmtR($taxTotal) ?></div>
        <div class="kpi-lbl">Valor Imposto</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val color-red"><?= fmtR($totalGasto) ?></div>
        <div class="kpi-lbl">Total Geral Gasto</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val color-blue"><?= $roas ?>x</div>
        <div class="kpi-lbl">ROAS</div>
      </div>
    </div>
    <div class="kpi-grid kpi-grid-2" style="margin-top:12px;margin-bottom:<?= ($leads>0)?'16px':'0' ?>">
      <div class="kpi-card">
        <div class="kpi-val color-green"><?= $purchases ?></div>
        <div class="kpi-lbl">Compras</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val color-green"><?= fmtR($revenue) ?></div>
        <div class="kpi-lbl">Faturamento</div>
      </div>
    </div>

    <?php if ($purchases > 0 || $roi != 0): ?>
    <div class="kpi-grid kpi-grid-3" style="margin-top:12px;margin-bottom:<?= ($leads>0)?'16px':'0' ?>">
      <div class="kpi-card">
        <div class="kpi-val color-yellow"><?= $roi ?>%</div>
        <div class="kpi-lbl">ROI</div>
      </div>
      <?php if ($cpa > 0): ?>
      <div class="kpi-card">
        <div class="kpi-val color-dark"><?= fmtR($cpa) ?></div>
        <div class="kpi-lbl">Custo por Compra</div>
      </div>
      <?php endif; ?>
      <div class="kpi-card">
        <div class="kpi-val color-dark"><?= fmtR($cpm) ?></div>
        <div class="kpi-lbl">CPM Médio</div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($leads > 0): ?>
    <div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted2);margin-bottom:8px;margin-top:16px;font-family:'Inter',sans-serif;font-weight:700">Captação de Leads</div>
    <div class="kpi-grid kpi-grid-2">
      <div class="kpi-card">
        <div class="kpi-val color-purple"><?= fmtN($leads) ?></div>
        <div class="kpi-lbl">Total de Leads</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val color-purple"><?= fmtR($cpl) ?></div>
        <div class="kpi-lbl">Custo por Lead</div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── GRÁFICO GASTO POR DIA ── -->
  <?php if (count($daily) > 0): ?>
  <div class="section">
    <div class="section-title">📅 Gasto por Dia</div>
    <div class="chart-wrap">
      <?php
        $svgW = max(600, count($daily) * ($barW + $gap) + 40);
        $svgH = $chartH + 50;
        echo '<svg class="chart-svg" width="'.$svgW.'" height="'.$svgH.'" xmlns="http://www.w3.org/2000/svg">';
        $x = 20;
        foreach ($daily as $row) {
            $s  = floatval($row['spend']);
            $h  = max(4, round($s / $maxSpend * $chartH));
            $y  = $chartH - $h + 10;
            $dt = date('d/m', strtotime($row['snapshot_date']));
            $label = $s >= 1000 ? 'R$'.round($s/1000).'k' : 'R$'.round($s);
            // barra
            echo '<rect x="'.$x.'" y="'.$y.'" width="'.$barW.'" height="'.$h.'" rx="3" fill="url(#grad)"/>';
            // valor
            if ($s > 0) {
                echo '<text x="'.($x+$barW/2).'" y="'.($y-4).'" text-anchor="middle" font-size="8" fill="#6b7280" font-family="Inter">'.$label.'</text>';
            }
            // data
            echo '<text x="'.($x+$barW/2).'" y="'.($chartH+28).'" text-anchor="middle" font-size="9" fill="#9ca3af" font-family="Inter">'.$dt.'</text>';
            $x += $barW + $gap;
        }
        echo '<defs><linearGradient id="grad" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#4f6ef7"/><stop offset="100%" stop-color="#a78bfa"/></linearGradient></defs>';
        echo '</svg>';
      ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── POR OBJETIVO / FASE ── -->
  <?php if (!empty($byObjective)): ?>
  <div class="section">
    <div class="section-title">🎯 Resultado por Fase</div>

    <?php foreach ($byObjective as $obj => $data):
      $meta  = $objLabels[$obj] ?? $objLabels['OUTROS'];
      $oSpend = floatval($data['spend']);
      $oImp   = intval($data['impressions']);
      $oClk   = intval($data['clicks']);
      $oPurch = intval($data['purchases']);
      $oLead  = intval($data['leads']);
      $oRev   = floatval($data['revenue']);
      $oRoas  = $oSpend > 0 ? round($oRev/$oSpend,2) : 0;
      $oCtr   = $oImp > 0 ? round(($oClk/$oImp)*100,2) : 0;
      $oCpc   = $oClk > 0 ? $oSpend/$oClk : 0;
      $oCpa   = $oPurch > 0 ? $oSpend/$oPurch : 0;
    ?>
    <div class="obj-block">
      <div class="obj-header" style="background:<?= $meta['color'] ?>12">
        <div class="obj-header-left">
          <span class="obj-icon"><?= $meta['icon'] ?></span>
          <div>
            <div class="obj-name"><?= $meta['label'] ?></div>
            <div class="obj-count"><?= count($data['campaigns']) ?> campanha(s)</div>
          </div>
        </div>
        <div class="obj-kpis">
          <div class="obj-kpi-item">
            <div class="obj-kpi-val" style="color:<?= $meta['color'] ?>"><?= fmtR($oSpend) ?></div>
            <div class="obj-kpi-lbl">Investido</div>
          </div>
          <?php if ($oPurch > 0): ?>
          <div class="obj-kpi-item">
            <div class="obj-kpi-val text-green"><?= $oPurch ?></div>
            <div class="obj-kpi-lbl">Compras</div>
          </div>
          <div class="obj-kpi-item">
            <div class="obj-kpi-val text-muted"><?= fmtR($oCpa) ?></div>
            <div class="obj-kpi-lbl">CPA</div>
          </div>
          <div class="obj-kpi-item">
            <div class="obj-kpi-val text-blue"><?= $oRoas ?>x</div>
            <div class="obj-kpi-lbl">ROAS</div>
          </div>
          <?php endif; ?>
          <?php if ($oLead > 0): ?>
          <div class="obj-kpi-item">
            <div class="obj-kpi-val" style="color:#7c3aed"><?= fmtN($oLead) ?></div>
            <div class="obj-kpi-lbl">Leads</div>
          </div>
          <?php endif; ?>
          <div class="obj-kpi-item">
            <div class="obj-kpi-val text-muted"><?= fmtP($oCtr) ?></div>
            <div class="obj-kpi-lbl">CTR</div>
          </div>
        </div>
      </div>

      <!-- Tabela de campanhas -->
      <table class="camp-table">
        <thead>
          <tr>
            <th>Campanha</th>
            <th class="text-right">Investido</th>
            <th class="text-right">Impressões</th>
            <th class="text-right">Cliques</th>
            <th class="text-right">CTR</th>
            <th class="text-right">CPC</th>
            <?php if ($oPurch > 0): ?><th class="text-right">Compras</th><th class="text-right">CPA</th><th class="text-right">ROAS</th><?php endif; ?>
            <?php if ($oLead > 0): ?><th class="text-right">Leads</th><th class="text-right">CPL</th><?php endif; ?>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data['campaigns'] as $camp):
            $m = $camp['metrics'];
            $isActive = ($camp['status'] === 'ACTIVE');
          ?>
          <tr>
            <td class="camp-name-cell"><?= htmlspecialchars($camp['campaign_name']) ?></td>
            <td class="text-right"><span class="metric-val"><?= fmtR($m['spend']) ?></span></td>
            <td class="text-right text-muted"><?= fmtN($m['impressions']) ?></td>
            <td class="text-right text-muted"><?= fmtN($m['clicks']) ?></td>
            <td class="text-right text-blue"><?= fmtP($m['ctr']) ?></td>
            <td class="text-right text-muted"><?= $m['clicks']>0 ? fmtR($m['cpc']) : '—' ?></td>
            <?php if ($oPurch > 0): ?>
            <td class="text-right"><span class="metric-val text-green"><?= $m['purchases'] ?: '—' ?></span></td>
            <td class="text-right text-muted"><?= $m['purchases']>0 ? fmtR($m['spend']/$m['purchases']) : '—' ?></td>
            <td class="text-right text-blue"><?= $m['roas'] > 0 ? $m['roas'].'x' : '—' ?></td>
            <?php endif; ?>
            <?php if ($oLead > 0): ?>
            <td class="text-right" style="color:#7c3aed"><span class="metric-val"><?= $m['leads'] ?: '—' ?></span></td>
            <td class="text-right text-muted"><?= $m['leads']>0 ? fmtR($m['spend']/$m['leads']) : '—' ?></td>
            <?php endif; ?>
            <td>
              <span class="status-dot <?= $isActive?'dot-active':'dot-paused' ?>"></span>
              <span style="font-size:10px;color:var(--muted2)"><?= $isActive?'Ativa':'Pausada' ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── RODAPÉ ── -->
  <div class="footer">
    Relatório gerado por <strong>MetaDash</strong> · Next Management · <?= $genDate ?><br>
    <?= $typeLabel ?> · <?= htmlspecialchars($event['name']) ?> · <?= $periodStr ?>
  </div>

</div>
</body>
</html>