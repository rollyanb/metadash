<?php
// api/generate_financial_report.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/theme.php';
require_once __DIR__ . '/../includes/tax_helper.php';
require_once __DIR__ . '/../config/database.php';

Auth::requireLogin();
$role    = Auth::getRole();
$isAdmin = in_array($role, ['admin','chefe','financeiro']);
if (!$isAdmin) { header('Location: ../index.php'); exit; }

$db      = Database::getInstance();
$eventId = intval($_GET['event_id'] ?? 0);
if (!$eventId) { echo 'Evento não encontrado'; exit; }

$event = $db->fetch("SELECT * FROM events WHERE id=?", [$eventId]);
if (!$event) { echo 'Evento não encontrado'; exit; }

// ── DADOS FINANCEIROS ────────────────────────────────────────
$campaignIds = array_column(
    $db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$eventId]),
    'campaign_id'
);

$metaSpend = 0;
if (!empty($campaignIds)) {
    $ph   = implode(',', array_fill(0, count($campaignIds), '?'));
    $meta = $db->fetch("SELECT SUM(spend) as total FROM campaign_snapshots WHERE campaign_id IN ({$ph})", $campaignIds);
    $metaSpend = floatval($meta['total'] ?? 0);
}

$metaTax      = calcMetaTax($metaSpend);
$taxIss       = $metaTax['iss'];
$taxPisCofins = $metaTax['pis_cofins'];
$taxTotal     = $metaTax['total'];

$entries = $db->fetchAll(
    "SELECT efe.*, fc.name as category_name, fc.icon as category_icon
     FROM event_financial_entries efe
     LEFT JOIN financial_categories fc ON fc.id = efe.category_id
     WHERE efe.event_id=?
     ORDER BY efe.type, efe.entry_date DESC",
    [$eventId]
);

$totalEntradas = 0;
$totalDespesas = $metaSpend + $taxTotal;
foreach ($entries as $e) {
    if ($e['type'] === 'entrada') $totalEntradas += floatval($e['amount']);
    else $totalDespesas += floatval($e['amount']);
}
$resultado = $totalEntradas - $totalDespesas;

$entradas = array_filter($entries, function($e){ return $e['type'] === 'entrada'; });
$despesas = array_filter($entries, function($e){ return $e['type'] === 'despesa'; });

function fmtR($v) {
    return 'R$ ' . number_format(floatval($v), 2, ',', '.');
}

$geradoEm = date('d/m/Y \à\s H:i');
$resColor  = $resultado >= 0 ? '#34d399' : '#f87171';
$resLabel  = $resultado >= 0 ? '✅ Lucro' : '⚠️ Prejuízo';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($event['name']) ?> — Relatório Financeiro</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800;900&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
* { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
body{font-family:'DM Sans',sans-serif;background:#f4f6fb;color:#0f1020;font-size:13px}
.page{max-width:800px;margin:0 auto;padding:32px 24px}

/* CAPA */
.cover{background:#fff;border:2px solid #0f1020;border-radius:16px;padding:36px;margin-bottom:24px;position:relative;overflow:hidden}
.cover-bar{position:absolute;top:0;left:0;right:0;height:8px;background:linear-gradient(90deg,#4f6ef7,#a78bfa)}
.cover-logo{font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:#6b7280;letter-spacing:2px;text-transform:uppercase;margin-bottom:24px;margin-top:6px}
.cover-logo span{color:#4f6ef7}
.cover-badge{display:inline-block;padding:4px 14px;border-radius:20px;font-size:11px;font-weight:800;font-family:'Syne',sans-serif;letter-spacing:1px;text-transform:uppercase;margin-bottom:14px;background:#eff6ff;color:#1d4ed8;border:2px solid #3b82f6}
.cover-title{font-family:'Syne',sans-serif;font-size:30px;font-weight:900;margin-bottom:6px;color:#0f1020}
.cover-sub{font-size:13px;color:#4b5563;margin-bottom:24px}
.cover-meta{display:flex;gap:24px;flex-wrap:wrap;border-top:2px solid #e5e7eb;padding-top:16px;font-size:12px;color:#6b7280}
.cover-meta strong{color:#0f1020;display:block;font-size:14px;margin-top:2px;white-space:nowrap}

/* SEÇÕES */
.section{background:#fff;border-radius:14px;border:1px solid #e5e7eb;padding:20px 24px;margin-bottom:20px}
.section-title{font-family:'Syne',sans-serif;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#6b7280;margin-bottom:16px;display:flex;align-items:center;gap:8px}

/* RESUMO CARDS */
.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.summary-card{background:#f8faff;border:1px solid #e5e7eb;border-radius:10px;padding:14px}
.summary-card .label{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:#6b7280;margin-bottom:6px}
.summary-card .value{font-family:'Syne',sans-serif;font-size:20px;font-weight:900}

/* TABELA */
.fin-table{width:100%;border-collapse:collapse;font-size:13px}
.fin-table th{padding:8px 12px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:#6b7280;border-bottom:2px solid #e5e7eb}
.fin-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9}
.fin-table tr:last-child td{border-bottom:none}
.fin-table .amount{font-family:'Syne',sans-serif;font-weight:700;text-align:right}
.fin-table .auto-badge{font-size:10px;background:#eff6ff;color:#1d4ed8;padding:1px 6px;border-radius:4px;margin-left:6px;font-weight:600}
.total-row td{font-family:'Syne',sans-serif;font-weight:800;border-top:2px solid #e5e7eb;font-size:14px}

/* RESULTADO */
.resultado-box{background:#f8faff;border:2px solid;border-radius:12px;padding:20px 24px;display:flex;align-items:center;justify-content:space-between}
.resultado-label{font-family:'Syne',sans-serif;font-weight:800;font-size:16px}
.resultado-value{font-family:'Syne',sans-serif;font-weight:900;font-size:28px}

/* FOOTER */
.footer{text-align:center;font-size:11px;color:#9ca3af;margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb}

/* PRINT */
@media print {
  body{background:white}
  .page{padding:0;max-width:100%}
  .section,.cover{break-inside:avoid;page-break-inside:avoid}
  @page{margin:16mm 12mm;size:A4}
  .no-print{display:none !important}
}
</style>
</head>
<body onload="setTimeout(function(){ if(window.location.search.indexOf('autoprint')>=0) window.print(); }, 600)">
<div class="page">

  <!-- CAPA -->
  <div class="cover">
    <div class="cover-bar"></div>
    <div class="cover-logo">Meta<span>Dash</span> · Next Management</div>
    <div class="cover-badge">💰 Relatório Financeiro</div>
    <div class="cover-title"><?= htmlspecialchars($event['name']) ?></div>
    <?php if ($event['description']): ?>
    <div class="cover-sub"><?= htmlspecialchars($event['description']) ?></div>
    <?php endif; ?>
    <div class="cover-meta">
      <?php if ($event['date_event']): ?>
      <div><span>Data do Evento</span><strong><?= date('d/m/Y', strtotime($event['date_event'])) ?></strong></div>
      <?php endif; ?>
      <?php if ($event['date_campaign_start']): ?>
      <div><span>Início Campanhas</span><strong><?= date('d/m/Y', strtotime($event['date_campaign_start'])) ?></strong></div>
      <?php endif; ?>
      <?php if ($event['date_campaign_end']): ?>
      <div><span>Fim Campanhas</span><strong><?= date('d/m/Y', strtotime($event['date_campaign_end'])) ?></strong></div>
      <?php endif; ?>
      <div><span>Gerado em</span><strong><?= $geradoEm ?></strong></div>
    </div>
  </div>

  <!-- RESUMO -->
  <div class="summary-grid">
    <div class="summary-card">
      <div class="label">💚 Total Entradas</div>
      <div class="value" style="color:#34d399"><?= fmtR($totalEntradas) ?></div>
    </div>
    <div class="summary-card">
      <div class="label">🔴 Total Despesas</div>
      <div class="value" style="color:#f87171"><?= fmtR($totalDespesas) ?></div>
    </div>
    <div class="summary-card">
      <div class="label"><?= $resLabel ?></div>
      <div class="value" style="color:<?= $resColor ?>"><?= fmtR($resultado) ?></div>
    </div>
  </div>

  <!-- ENTRADAS -->
  <?php if (!empty($entradas)): ?>
  <div class="section">
    <div class="section-title">💚 Entradas</div>
    <table class="fin-table">
      <thead><tr><th>Descrição</th><th>Categoria</th><th>Data</th><th style="text-align:right">Valor</th></tr></thead>
      <tbody>
        <?php foreach ($entradas as $e): ?>
        <tr>
          <td><?= htmlspecialchars($e['description'] ?: ($e['category_name'] ?: 'Entrada')) ?></td>
          <td style="color:#6b7280"><?= htmlspecialchars(($e['category_icon']??'').' '.($e['category_name']??'')) ?></td>
          <td style="color:#6b7280"><?= $e['entry_date'] ? date('d/m/Y', strtotime($e['entry_date'])) : '—' ?></td>
          <td class="amount" style="color:#34d399"><?= fmtR($e['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
          <td colspan="3">Total Entradas</td>
          <td class="amount" style="color:#34d399"><?= fmtR($totalEntradas) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- DESPESAS -->
  <div class="section">
    <div class="section-title">🔴 Despesas</div>
    <table class="fin-table">
      <thead><tr><th>Descrição</th><th>Categoria</th><th>Data</th><th style="text-align:right">Valor</th></tr></thead>
      <tbody>
        <!-- Meta Ads automático -->
        <?php if ($metaSpend > 0): ?>
        <tr>
          <td>Meta Ads <span class="auto-badge">automático</span></td>
          <td style="color:#6b7280">📘 Tráfego</td>
          <td style="color:#6b7280">Todo o período</td>
          <td class="amount" style="color:#f87171"><?= fmtR($metaSpend) ?></td>
        </tr>
        <tr>
          <td>Impostos Meta (ISS 2,9% + PIS/COFINS 9,25%) <span class="auto-badge">automático</span></td>
          <td style="color:#6b7280">🧾 Fiscal</td>
          <td style="color:#6b7280">Todo o período</td>
          <td class="amount" style="color:#f87171"><?= fmtR($taxTotal) ?></td>
        </tr>
        <?php endif; ?>
        <!-- Despesas manuais -->
        <?php foreach ($despesas as $e): ?>
        <tr>
          <td><?= htmlspecialchars($e['description'] ?: ($e['category_name'] ?: 'Despesa')) ?></td>
          <td style="color:#6b7280"><?= htmlspecialchars(($e['category_icon']??'').' '.($e['category_name']??'')) ?></td>
          <td style="color:#6b7280"><?= $e['entry_date'] ? date('d/m/Y', strtotime($e['entry_date'])) : '—' ?></td>
          <td class="amount" style="color:#f87171"><?= fmtR($e['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
          <td colspan="3">Total Despesas</td>
          <td class="amount" style="color:#f87171"><?= fmtR($totalDespesas) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- RESULTADO FINAL -->
  <div class="resultado-box" style="border-color:<?= $resultado >= 0 ? '#34d399' : '#f87171' ?>;background:<?= $resultado >= 0 ? '#ecfdf5' : '#fef2f2' ?>">
    <div class="resultado-label" style="color:<?= $resColor ?>"><?= $resLabel ?></div>
    <div class="resultado-value" style="color:<?= $resColor ?>"><?= fmtR($resultado) ?></div>
  </div>

  <div class="footer">
    MetaDash · Next Management · <?= $geradoEm ?>
  </div>

</div>
</body>
</html>
