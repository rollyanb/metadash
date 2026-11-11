<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();
if (Auth::getRole() !== 'admin') {
    http_response_code(403);
    exit('Acesso restrito a administradores.');
}

$db = Database::getInstance();
$rows = [];
try {
    $rows = $db->fetchAll("\n        SELECT e.id, e.name, e.status, e.date_event,\n               COALESCE(ads.spend, 0) AS ad_spend,\n               COALESCE(ads.revenue, 0) AS ads_revenue,\n               COALESCE(ads.purchases, 0) AS ads_purchases,\n               COALESCE(sales.revenue, 0) AS event_revenue,\n               COALESCE(sales.sales_count, 0) AS sales_count\n        FROM events e\n        LEFT JOIN (\n            SELECT ec.event_id,\n                   SUM(cs.spend) AS spend,\n                   SUM(cs.revenue) AS revenue,\n                   SUM(cs.purchases) AS purchases\n            FROM event_campaigns ec\n            JOIN campaign_snapshots cs ON cs.campaign_id = ec.campaign_id\n            GROUP BY ec.event_id\n        ) ads ON ads.event_id = e.id\n        LEFT JOIN (\n            SELECT event_id,\n                   SUM(COALESCE(revenue, 0)) AS revenue,\n                   SUM(COALESCE(total_sales, 0)) AS sales_count\n            FROM event_daily_sales\n            GROUP BY event_id\n        ) sales ON sales.event_id = e.id\n        ORDER BY (e.date_event IS NULL), e.date_event DESC, e.id DESC\n    ");
} catch (Exception $e) {
    $loadError = 'Não foi possível carregar a trajetória financeira.';
}

function money($value) { return 'R$' . number_format((float)$value, 2, ',', '.'); }
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$activeEvents = 0;
$totalSpend = 0.0;
$totalEventRevenue = 0.0;
$totalAdsRevenue = 0.0;
$totalSales = 0;
$months = [];
foreach ($rows as $row) {
    if ($row['status'] === 'active') $activeEvents++;
    $totalSpend += (float)$row['ad_spend'];
    $totalEventRevenue += (float)$row['event_revenue'];
    $totalAdsRevenue += (float)$row['ads_revenue'];
    $totalSales += (int)$row['sales_count'];
    if (!empty($row['date_event'])) {
        $monthKey = date('Y-m', strtotime($row['date_event']));
        if (!isset($months[$monthKey])) $months[$monthKey] = ['spend'=>0.0, 'revenue'=>0.0];
        $months[$monthKey]['spend'] += (float)$row['ad_spend'];
        $months[$monthKey]['revenue'] += (float)$row['ads_revenue'];
    }
}
// A base da pagina e a receita atribuida pela Meta (ads_revenue).
// O faturamento lancado manualmente fica como coluna de conferencia.
$netResult   = $totalAdsRevenue - $totalSpend;
$returnOnAds = $totalSpend > 0 ? $totalAdsRevenue / $totalSpend : 0;
$maxMonthValue = 1;
foreach ($months as $month) $maxMonthValue = max($maxMonthValue, $month['spend'], $month['revenue']);
?>
<!doctype html>
<html lang="pt-BR" class="<?= h(getThemeClass()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Trajetória — MetaDash</title>
  <?php echoThemeAssets(''); ?>
  <style>
    .page-title{font-size:22px;font-weight:700;margin:0 0 4px}.page-sub{font-size:13px;color:var(--mt2);margin:0 0 24px}
    .summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:24px}.summary-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px}.summary-label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--mt);font-weight:700;margin-bottom:7px}.summary-value{font-size:22px;font-weight:700;letter-spacing:-.03em}.summary-help{font-size:11px;color:var(--mt2);margin-top:6px;line-height:1.45}.positive{color:var(--gn)}.negative{color:var(--rd)}.accent{color:var(--ac)}
    .section{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px;margin-bottom:18px}.section-title{font-size:15px;font-weight:700;margin:0 0 4px}.section-sub{font-size:12px;color:var(--mt2);margin:0 0 16px}.month-row{display:grid;grid-template-columns:88px minmax(90px,1fr) minmax(90px,1fr) 105px;gap:12px;align-items:center;margin:10px 0;font-size:12px}.month-label{color:var(--mt2);font-weight:600}.bar{height:9px;border-radius:99px;background:var(--s2);overflow:hidden}.bar>span{display:block;height:100%;border-radius:inherit}.bar-spend{background:var(--rd)}.bar-revenue{background:var(--gn)}.month-value{text-align:right;color:var(--tx);font-variant-numeric:tabular-nums}
    .table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:12px}th{text-align:left;color:var(--mt);font-size:10px;text-transform:uppercase;letter-spacing:.07em;padding:0 10px 10px;border-bottom:1px solid var(--bd)}td{padding:12px 10px;border-bottom:1px solid var(--bd);vertical-align:middle}tr:last-child td{border-bottom:0}.right{text-align:right;font-variant-numeric:tabular-nums}.event-name{font-weight:600;color:var(--tx)}.date{font-size:11px;color:var(--mt2);margin-top:3px}.badge{font-size:10px;font-weight:700;border-radius:99px;padding:3px 8px;display:inline-block}.active-badge{background:rgba(62,207,142,.12);color:var(--gn)}.finished-badge{background:var(--s2);color:var(--mt2)}.empty{text-align:center;color:var(--mt2);padding:38px}.notice{background:rgba(84,112,255,.10);border:1px solid rgba(84,112,255,.2);border-radius:10px;color:var(--mt2);padding:12px 14px;font-size:12px;line-height:1.5;margin-bottom:18px}
    @media(max-width:1000px){.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.month-row{grid-template-columns:70px 1fr 1fr 90px}}@media(max-width:600px){.summary-grid{grid-template-columns:1fr}.month-row{grid-template-columns:62px 1fr 84px}.month-row .bar:nth-of-type(2){display:none}th:nth-child(5),td:nth-child(5),th:nth-child(6),td:nth-child(6){display:none}}
  </style>
</head>
<body>
<?php $currentPage='trajectory'; $sidebarBase=''; require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
  <h1 class="page-title">📈 Trajetória</h1>
  <p class="page-sub">Visão consolidada de investimentos em anúncios e faturamento dos eventos.</p>

  <?php if (!empty($loadError)): ?><div class="notice"><?= h($loadError) ?></div><?php endif; ?>
  <div class="summary-grid">
    <div class="summary-card"><div class="summary-label">Eventos ativos</div><div class="summary-value accent"><?= $activeEvents ?></div><div class="summary-help">de <?= count($rows) ?> eventos no histórico</div></div>
    <div class="summary-card"><div class="summary-label">Gasto em anúncios</div><div class="summary-value negative"><?= money($totalSpend) ?></div><div class="summary-help">Campanhas vinculadas aos eventos</div></div>
    <div class="summary-card"><div class="summary-label">Faturamento Meta</div><div class="summary-value positive"><?= money($totalAdsRevenue) ?></div><div class="summary-help">Receita atribuída aos anúncios<?= $totalEventRevenue > 0 ? ' · lançado manualmente: ' . money($totalEventRevenue) : '' ?></div></div>
    <div class="summary-card"><div class="summary-label">Resultado após anúncios</div><div class="summary-value <?= $netResult >= 0 ? 'positive' : 'negative' ?>"><?= money($netResult) ?></div><div class="summary-help">Retorno sobre anúncios: <?= $totalSpend > 0 ? number_format($returnOnAds, 2, ',', '.') . 'x' : '—' ?></div></div>
  </div>

  <div class="notice">Os valores desta página usam a receita que a Meta atribui aos anúncios (janela de atribuição do pixel). É uma estimativa da plataforma, não o caixa: inclui venda atribuída por visualização e ignora venda que o pixel não registrou. O faturamento lançado manualmente aparece em coluna separada para conferência.</div>

  <section class="section">
    <h2 class="section-title">Evolução por mês do evento</h2>
    <p class="section-sub">Vermelho: investimento em anúncios · Verde: faturamento Meta</p>
    <?php if (!$months): ?><div class="empty">Ainda não há eventos com data para montar a trajetória.</div><?php else: ?>
      <?php foreach ($months as $monthKey => $month): $label = date('m/Y', strtotime($monthKey . '-01')); ?>
      <div class="month-row"><div class="month-label"><?= h($label) ?></div><div class="bar" title="Gasto: <?= h(money($month['spend'])) ?>"><span class="bar-spend" style="width:<?= round($month['spend'] / $maxMonthValue * 100, 2) ?>%"></span></div><div class="bar" title="Faturamento Meta: <?= h(money($month['revenue'])) ?>"><span class="bar-revenue" style="width:<?= round($month['revenue'] / $maxMonthValue * 100, 2) ?>%"></span></div><div class="month-value"><?= money($month['revenue']) ?></div></div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <section class="section">
    <h2 class="section-title">Histórico por evento</h2>
    <p class="section-sub">O resultado usa o faturamento Meta. A coluna de lançamento manual serve para comparar com o caixa real.</p>
    <div class="table-wrap"><table><thead><tr><th>Evento</th><th>Status</th><th class="right">Gasto em anúncios</th><th class="right">Faturamento Meta</th><th class="right">Lançado manualmente</th><th class="right">Resultado</th></tr></thead><tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="empty">Nenhum evento encontrado.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row): $result = (float)$row['ads_revenue'] - (float)$row['ad_spend']; ?>
      <tr><td><div class="event-name"><?= h($row['name']) ?></div><div class="date"><?= $row['date_event'] ? date('d/m/Y', strtotime($row['date_event'])) : 'Sem data' ?></div></td><td><span class="badge <?= $row['status'] === 'active' ? 'active-badge' : 'finished-badge' ?>"><?= $row['status'] === 'active' ? '● Ativo' : '● Encerrado' ?></span></td><td class="right negative"><?= money($row['ad_spend']) ?></td><td class="right positive"><?= money($row['ads_revenue']) ?></td><td class="right"><?= money($row['event_revenue']) ?></td><td class="right <?= $result >= 0 ? 'positive' : 'negative' ?>"><?= money($result) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>
</main>
</body>
</html>
