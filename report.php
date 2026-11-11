<?php
require_once __DIR__ . '/config/database.php';

$token = trim($_GET['token'] ?? '');
if (!$token) showError('Link inválido', 'Este link não contém um token de acesso.');

$db = Database::getInstance();

function showError($title, $msg) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Link inválido — MetaDash</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:Inter,sans-serif;background:#07070f;color:#ededf5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}.box{text-align:center;max-width:400px}.ico{font-size:52px;margin-bottom:18px}.ttl{font-size:22px;font-weight:700;margin-bottom:10px}.msg{color:#8888a0;font-size:14px;line-height:1.7}</style>
    </head><body><div class="box"><div class="ico">🔒</div><div class="ttl">'.htmlspecialchars($title).'</div><div class="msg">'.htmlspecialchars($msg).'</div></div></body></html>';
    exit;
}

$tokenRow = $db->fetch("SELECT t.*, a.account_name, e.name AS event_name, e.account_id AS event_account,
                               e.date_campaign_start, e.date_event
                        FROM report_tokens t
                        LEFT JOIN ad_accounts a ON a.account_id = t.account_id
                        LEFT JOIN events e ON e.id = t.event_id
                        WHERE t.token=? AND t.active=1", [$token]);
if (!$tokenRow) showError('Link inválido ou expirado', 'Este link não existe ou foi desativado. Solicite um novo link ao administrador.');

// Token de EVENTO: soma só as campanhas vinculadas. Token de CONTA: comportamento antigo.
$isEventReport = !empty($tokenRow['event_id']);
$eventCampaignIds = [];
if ($isEventReport) {
    $accountId   = $tokenRow['event_account'];
    $accountName = $tokenRow['event_name'] ?: 'Evento';
    // evento pode rodar em mais de uma conta — o escopo real vem das campanhas
    try {
        $evAccs = array_column($db->fetchAll("SELECT account_id FROM event_accounts WHERE event_id=?",
            [$tokenRow['event_id']]), 'account_id');
        if ($evAccs) $accountId = $evAccs[0];
    } catch (Exception $e) {}
    $eventCampaignIds = array_column(
        $db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$tokenRow['event_id']]), 'campaign_id');
    if (empty($eventCampaignIds)) {
        showError('Relatório sem campanhas', 'Este evento ainda não tem campanhas vinculadas. Fale com o responsável.');
    }
} else {
    $accountId   = $tokenRow['account_id'];
    $accountName = $tokenRow['account_name'] ?? $accountId;
}

// Período
// Relatório de evento: períodos relativos ao evento, com "todo o período" (a campanha inteira).
// Relatório de conta: mantém os períodos antigos em dias.
$eventStart = $isEventReport && !empty($tokenRow['date_campaign_start'])
    ? $tokenRow['date_campaign_start'] : null;

if ($isEventReport) {
    $periodOptions = [
        'hoje'  => 'Hoje', 'ontem' => 'Ontem', '3' => 'Últimos 3d',
        '7'     => 'Últimos 7d', '15' => 'Últimos 15d', 'tudo' => 'Todo o período',
    ];
    $periodKey = (string)($_GET['period'] ?? 'tudo');
    if (!isset($periodOptions[$periodKey])) $periodKey = 'tudo';

    switch ($periodKey) {
        case 'hoje':
            $dateStart = $dateEnd = date('Y-m-d');
            break;
        case 'ontem':
            $dateStart = $dateEnd = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'tudo':
            $dateEnd   = date('Y-m-d');
            $dateStart = $eventStart ?: date('Y-m-d', strtotime('-90 days'));
            break;
        default: // 3, 7, 15
            $dias      = (int)$periodKey;
            $dateEnd   = date('Y-m-d');
            $dateStart = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));
            // nunca antes do início da campanha
            if ($eventStart && $dateStart < $eventStart) $dateStart = $eventStart;
    }
    $period = max(1, (int)ceil((strtotime($dateEnd) - strtotime($dateStart)) / 86400) + 1);
} else {
    $period    = intval($_GET['period'] ?? 30);
    if (!in_array($period, [3,7,14,30,60,90])) $period = 30;
    $periodKey = (string)$period;
    $dateEnd   = date('Y-m-d');
    $dateStart = date('Y-m-d', strtotime("-{$period} days"));
}
// Período anterior para comparativo
$prevEnd   = date('Y-m-d', strtotime($dateStart.' -1 day'));
$prevStart = date('Y-m-d', strtotime($prevEnd." -{$period} days"));
// "Todo o período" não tem período anterior para comparar
$showCompare = !($isEventReport && $periodKey === 'tudo');
if ($isEventReport && $eventStart && $prevStart < $eventStart) $showCompare = $showCompare && $prevEnd >= $eventStart;

// Fonte dos números: por evento usa campaign_conversion_snapshots (só as campanhas
// vinculadas); por conta mantém conversion_snapshots.
$convTable = $isEventReport ? 'campaign_conversion_snapshots' : 'conversion_snapshots';
$campPh    = $isEventReport ? implode(',', array_fill(0, count($eventCampaignIds), '?')) : '';
$scopeSql  = $isEventReport ? "campaign_id IN ($campPh)" : "account_id=?";
$scopeArgs = $isEventReport ? $eventCampaignIds : [$accountId];

// ── DADOS ATUAIS ─────────────────────────────────────────────
$totals = $db->fetch("
    SELECT SUM(views) AS views, SUM(add_to_cart) AS add_to_cart,
           SUM(checkout) AS checkout, SUM(purchases) AS purchases,
           SUM(leads) AS leads, SUM(revenue) AS revenue, SUM(spend) AS spend
    FROM {$convTable} WHERE {$scopeSql} AND snapshot_date BETWEEN ? AND ?
", array_merge($scopeArgs, [$dateStart, $dateEnd]));

$reach = $db->fetch("
    SELECT SUM(impressions) AS impressions, SUM(clicks) AS clicks, "
    . ($isEventReport ? "0" : "SUM(reach)") . " AS reach
    FROM " . ($isEventReport ? 'campaign_snapshots' : 'spend_snapshots') . "
    WHERE {$scopeSql} AND snapshot_date BETWEEN ? AND ?
", array_merge($scopeArgs, [$dateStart, $dateEnd]));

// Se conversion_snapshots vazio, usa ad_creative_snapshots como fallback para KPIs
$hasTotals = ($totals['spend'] ?? 0) > 0 || ($totals['leads'] ?? 0) > 0 || ($totals['purchases'] ?? 0) > 0;
if (!$hasTotals) {
    $totals = $db->fetch("
        SELECT SUM(spend) AS spend, SUM(revenue) AS revenue,
               SUM(purchases) AS purchases, SUM(leads) AS leads,
               0 AS views, 0 AS add_to_cart, 0 AS checkout
        FROM ad_creative_snapshots
        WHERE account_id=? AND snapshot_date BETWEEN ? AND ?
    ", [$accountId, $dateStart, $dateEnd]);
    $usingCreativeAsSource = true;
} else {
    $usingCreativeAsSource = false;
}

// effectiveDateStart = mesmo dateStart (sem ajuste extra agora que a fonte é unificada)
$effectiveDateStart = $dateStart;

// ── DADOS PERÍODO ANTERIOR ────────────────────────────────────
$prevTotals = $db->fetch("
    SELECT SUM(purchases) AS purchases, SUM(revenue) AS revenue,
           SUM(spend) AS spend, SUM(leads) AS leads
    FROM {$convTable} WHERE {$scopeSql} AND snapshot_date BETWEEN ? AND ?
", array_merge($scopeArgs, [$prevStart, $prevEnd]));

$prevReach = $db->fetch("
    SELECT SUM(impressions) AS impressions, SUM(clicks) AS clicks
    FROM " . ($isEventReport ? 'campaign_snapshots' : 'spend_snapshots') . "
    WHERE {$scopeSql} AND snapshot_date BETWEEN ? AND ?
", array_merge($scopeArgs, [$prevStart, $prevEnd]));

// ── DADOS DIÁRIOS ─────────────────────────────────────────────
if ($isEventReport) {
    $daily = $db->fetchAll("
        SELECT c.snapshot_date,
               SUM(c.purchases) purchases, SUM(c.revenue) revenue, SUM(c.spend) spend,
               SUM(c.leads) leads, SUM(c.views) views, SUM(c.add_to_cart) add_to_cart,
               SUM(c.checkout) checkout,
               COALESCE(SUM(k.impressions),0) AS impressions,
               COALESCE(SUM(k.clicks),0)      AS clicks,
               0 AS reach
        FROM campaign_conversion_snapshots c
        LEFT JOIN campaign_snapshots k ON k.campaign_id=c.campaign_id AND k.snapshot_date=c.snapshot_date
        WHERE c.campaign_id IN ($campPh) AND c.snapshot_date BETWEEN ? AND ?
        GROUP BY c.snapshot_date ORDER BY c.snapshot_date ASC
    ", array_merge($eventCampaignIds, [$dateStart, $dateEnd]));
} else {
$daily = $db->fetchAll("
    SELECT c.snapshot_date, c.purchases, c.revenue, c.spend, c.leads,
           c.views, c.add_to_cart, c.checkout,
           COALESCE(s.impressions,0) AS impressions,
           COALESCE(s.clicks,0)      AS clicks,
           COALESCE(s.reach,0)       AS reach
    FROM conversion_snapshots c
    LEFT JOIN spend_snapshots s ON s.account_id=c.account_id AND s.snapshot_date=c.snapshot_date
    WHERE c.account_id=? AND c.snapshot_date BETWEEN ? AND ?
    ORDER BY c.snapshot_date ASC
", [$accountId, $dateStart, $dateEnd]);
}

// ── CRIATIVOS ─────────────────────────────────────────────────
$creatives = $db->fetchAll("
    SELECT ac.ad_id, ac.ad_name, ac.is_dynamic,
           ac.image_url, ac.thumbnail_url,
           COALESCE(SUM(s.impressions), 0) AS impressions,
           COALESCE(SUM(s.clicks),      0) AS clicks,
           COALESCE(SUM(s.spend),       0) AS spend,
           COALESCE(SUM(s.purchases),   0) AS purchases,
           COALESCE(SUM(s.leads),       0) AS leads,
           COALESCE(SUM(s.revenue),     0) AS revenue,
           CASE WHEN COALESCE(SUM(s.impressions),0)>0
                THEN ROUND(COALESCE(SUM(s.clicks),0)/COALESCE(SUM(s.impressions),0)*100,2)
                ELSE 0 END AS ctr,
           CASE WHEN COALESCE(SUM(s.spend),0)>0
                THEN ROUND(COALESCE(SUM(s.revenue),0)/COALESCE(SUM(s.spend),0),2)
                ELSE 0 END AS roas,
           CASE WHEN COALESCE(SUM(s.purchases),0)>0
                THEN ROUND(COALESCE(SUM(s.spend),0)/COALESCE(SUM(s.purchases),0),2)
                ELSE 0 END AS cpa
    FROM ad_creatives ac
    INNER JOIN ad_creative_snapshots s ON s.ad_id=ac.ad_id
                                       AND s.account_id=ac.account_id
                                       AND s.snapshot_date BETWEEN ? AND ?
    WHERE " . ($isEventReport ? "ac.campaign_id IN ($campPh)" : "ac.account_id=?") . "
    GROUP BY ac.ad_id
    HAVING (SUM(s.purchases)>0 OR SUM(s.leads)>0 OR SUM(s.spend)>0)
    ORDER BY SUM(s.purchases) DESC, roas DESC
    LIMIT 10
", array_merge([$dateStart, $dateEnd], $isEventReport ? $eventCampaignIds : [$accountId]));

// ── ASSETS dos criativos dinâmicos (para o modal do relatório) ──
$assetsByAd = [];
$dynAdIds = [];
foreach ($creatives as $cr) { if (!empty($cr['is_dynamic'])) $dynAdIds[] = $cr['ad_id']; }
if ($dynAdIds) {
    $aph = implode(',', array_fill(0, count($dynAdIds), '?'));
    $rowsA = $db->fetchAll("
        SELECT ad_id, asset_type, asset_name, MAX(thumbnail_url) thumbnail_url,
               SUM(impressions) impressions, SUM(clicks) clicks, SUM(spend) spend,
               SUM(purchases) purchases, SUM(leads) leads, SUM(revenue) revenue
        FROM ad_creative_assets
        WHERE ad_id IN ($aph) AND date_start <= ? AND date_end >= ?
        GROUP BY ad_id, asset_type, asset_name
        ORDER BY SUM(purchases) DESC, SUM(leads) DESC, SUM(impressions) DESC",
        array_merge($dynAdIds, [$dateEnd, $dateStart]));
    foreach ($rowsA as $ra) {
        if (count($assetsByAd[$ra['ad_id']] ?? []) >= 8) continue;
        $sp = (float)$ra['spend'];
        $assetsByAd[$ra['ad_id']][] = [
            'name'  => $ra['asset_name'] ?: ($ra['asset_type'] === 'video' ? 'Vídeo' : 'Imagem'),
            'type'  => $ra['asset_type'],
            'thumb' => $ra['thumbnail_url'],
            'impr'  => (int)$ra['impressions'],
            'spend' => $sp,
            'purch' => (int)$ra['purchases'],
            'leads' => (int)$ra['leads'],
            'roas'  => $sp > 0 ? round($ra['revenue'] / $sp, 2) : 0,
            'cpa'   => $ra['purchases'] > 0 ? round($sp / $ra['purchases'], 2) : 0,
        ];
    }
}

// ── MÉTRICAS DERIVADAS ────────────────────────────────────────
$spend       = floatval($totals['spend']       ?? 0);
$revenue     = floatval($totals['revenue']     ?? 0);
$purchases   = intval($totals['purchases']     ?? 0);
$leads       = intval($totals['leads']         ?? 0);
$views       = intval($totals['views']         ?? 0);
$addToCart   = intval($totals['add_to_cart']   ?? 0);
$checkout    = intval($totals['checkout']      ?? 0);
$impressions = intval($reach['impressions']    ?? 0);
$clicks      = intval($reach['clicks']         ?? 0);
$reachCount  = intval($reach['reach']          ?? 0);

$roas  = $spend > 0 ? round($revenue / $spend, 2) : 0;
$roi   = $spend > 0 ? round(($revenue - $spend) / $spend * 100, 1) : 0;
$cpa   = $purchases > 0 ? round($spend / $purchases, 2) : 0;
$cpl   = $leads > 0 ? round($spend / $leads, 2) : 0;
$ctr   = $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0;
$cpm   = $impressions > 0 ? round($spend / $impressions * 1000, 2) : 0;
$freq  = $reachCount > 0 ? round($impressions / $reachCount, 1) : 0;

// Taxas de funil
$rateViewsToCart     = $views > 0 && $addToCart > 0 ? round($addToCart / $views * 100, 1) : 0;
$rateViewsToCheckout = $views > 0 && $checkout > 0  ? round($checkout  / $views * 100, 1) : 0;
$rateViewsToPurchase = $views > 0 && $purchases > 0 ? round($purchases / $views * 100, 1) : 0;
$rateCartToCheckout  = $addToCart > 0 && $checkout > 0  ? round($checkout  / $addToCart * 100, 1) : 0;
$rateCheckoutToPurch = $checkout > 0 && $purchases > 0  ? round($purchases / $checkout * 100, 1) : 0;

// Melhor dia
$bestDay = null;
foreach ($daily as $d) {
    if (!$bestDay || floatval($d['revenue']) > floatval($bestDay['revenue'])) $bestDay = $d;
}

// Comparativo vs período anterior
function pctChange($curr, $prev) {
    if (!$prev || $prev == 0) return null;
    return round(($curr - $prev) / $prev * 100, 1);
}
$cmpRevenue  = pctChange($revenue,    floatval($prevTotals['revenue']  ?? 0));
$cmpSpend    = pctChange($spend,      floatval($prevTotals['spend']    ?? 0));
$cmpPurch    = pctChange($purchases,  intval($prevTotals['purchases']  ?? 0));
$cmpLeads    = pctChange($leads,      intval($prevTotals['leads']      ?? 0));
$cmpImpr     = pctChange($impressions,intval($prevReach['impressions'] ?? 0));
$cmpClicks   = pctChange($clicks,     intval($prevReach['clicks']      ?? 0));
$prevRoas    = floatval($prevTotals['spend'] ?? 0) > 0
    ? round(floatval($prevTotals['revenue'] ?? 0) / floatval($prevTotals['spend']), 2) : 0;
$cmpRoas     = pctChange($roas, $prevRoas);

function fmtBRL($v) {
    if ($v >= 1000000) return 'R$ '.number_format($v/1000000,1,',','.').'M';
    if ($v >= 10000)   return 'R$ '.number_format($v/1000,1,',','.').'K';
    return 'R$ '.number_format($v,2,',','.');
}
function fmtN($n) {
    if ($n >= 1000000) return number_format($n/1000000,1,',','.').'M';
    if ($n >= 1000)    return number_format($n/1000,1,',','.').'K';
    return number_format($n,0,',','.');
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($accountName) ?> — Relatório de Performance</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

/* ── TEMAS ──────────────────────────────────────────────── */
:root{
  --bg:#07070f;--sf:#0f0f1a;--s2:#161625;--bd:rgba(255,255,255,.07);
  --tx:#ededf5;--mt:#5c5c78;--mt2:#8888a0;
  --ac:#5470ff;--gn:#34d399;--rd:#f87171;--yw:#fbbf24;--or:#fb923c;--gold:#f59e0b;
  --grid-color:rgba(255,255,255,0.04);--tick-color:#5c5c78;
}
[data-theme="light"]{
  --bg:#f4f4f8;--sf:#ffffff;--s2:#f0f0f5;--bd:rgba(0,0,0,.09);
  --tx:#111118;--mt:#9999aa;--mt2:#666677;
  --grid-color:rgba(0,0,0,0.05);--tick-color:#9999aa;
}

body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--tx);min-height:100vh;padding:28px 20px;max-width:1120px;margin:0 auto;transition:background .25s,color .25s}

/* ── HEADER ─────────────────────────────────────────────── */
.header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:14px}
.header-left .logo-row{display:flex;align-items:center;gap:9px;margin-bottom:6px}
.logo-icon{width:32px;height:32px;background:var(--ac);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;color:#fff;font-weight:800}
.logo-text{font-size:14px;font-weight:700;color:var(--mt2)}
.logo-text span{color:var(--ac)}
.account-name{font-size:22px;font-weight:800;color:var(--tx);margin-bottom:4px}
.period-tag{font-size:12px;color:var(--mt2)}

.header-right{display:flex;flex-direction:column;align-items:flex-end;gap:10px}
.period-btns{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.pbtn{padding:6px 16px;border-radius:20px;border:1px solid var(--bd);background:transparent;color:var(--mt2);font-family:'Inter',sans-serif;font-size:12px;font-weight:500;cursor:pointer;text-decoration:none;transition:all .15s}
.pbtn:hover{border-color:var(--ac);color:var(--ac)}
.pbtn.active{background:rgba(84,112,255,.15);border-color:var(--ac);color:var(--tx);font-weight:600}

.theme-btn{padding:6px 14px;border-radius:20px;border:1px solid var(--bd);background:transparent;color:var(--mt2);font-family:'Inter',sans-serif;font-size:12px;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:6px}
.theme-btn:hover{border-color:var(--ac);color:var(--tx)}

/* ── KPI GRID ───────────────────────────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:18px}
.kpi{background:var(--sf);border:1px solid var(--bd);border-radius:13px;padding:16px 14px;transition:border-color .15s}
.kpi:hover{border-color:rgba(84,112,255,.3)}
.kpi-icon{font-size:15px;margin-bottom:5px}
.kpi-label{font-size:9px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt2);margin-bottom:4px;font-weight:600}
.kpi-value{font-family:'JetBrains Mono',monospace;font-weight:700;line-height:1.1;font-size:22px;color:var(--tx)}
.kpi-value.md{font-size:17px}
.kpi-value.sm{font-size:14px}
.kpi-sub{font-size:10px;color:var(--mt);margin-top:5px}
.kpi-cmp{font-size:10px;font-weight:600;margin-top:3px;display:flex;align-items:center;gap:3px}
.cmp-up{color:var(--gn)}.cmp-dn{color:var(--rd)}.cmp-ne{color:var(--mt2)}
.c-green{color:var(--gn)}.c-gold{color:var(--gold)}.c-blue{color:var(--ac)}
.c-purple{color:#826eff}.c-orange{color:var(--or)}.c-yellow{color:var(--yw)}.c-teal{color:#2dd4bf}

/* ── DIAGNÓSTICO ────────────────────────────────────────── */
.diag-section{background:var(--sf);border:1px solid var(--bd);border-radius:13px;padding:20px;margin-bottom:18px}
.diag-title{font-size:13px;font-weight:700;color:var(--tx);margin-bottom:14px;display:flex;align-items:center;gap:7px}
.diag-grid{display:flex;flex-direction:column;gap:8px}
.diag-item{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border-radius:9px;font-size:13px}
.diag-ok{background:rgba(52,211,153,.07);border:1px solid rgba(52,211,153,.2)}
.diag-warn{background:rgba(251,191,36,.07);border:1px solid rgba(251,191,36,.2)}
.diag-bad{background:rgba(248,113,113,.07);border:1px solid rgba(248,113,113,.2)}
.diag-info{background:rgba(84,112,255,.07);border:1px solid rgba(84,112,255,.2)}
.diag-ico{font-size:16px;flex-shrink:0;margin-top:1px}
.diag-body{}
.diag-head{font-weight:600;margin-bottom:2px;color:var(--tx)}
.diag-desc{font-size:12px;color:var(--mt2);line-height:1.6}

/* ── SEÇÕES ─────────────────────────────────────────────── */
.section{background:var(--sf);border:1px solid var(--bd);border-radius:13px;padding:20px;margin-bottom:18px}
.section-title{font-size:13px;font-weight:700;color:var(--tx);margin-bottom:18px;display:flex;align-items:center;gap:7px}

/* ── FUNIL ──────────────────────────────────────────────── */
.funnel-wrap{overflow-x:auto;padding-bottom:4px}
.funnel{display:flex;align-items:stretch;gap:0;min-width:600px}
.fs{flex:1;position:relative}
.fs+.fs{padding-left:22px}
.fs+.fs::before{content:'→';position:absolute;left:2px;top:50%;transform:translateY(-50%);color:var(--mt);font-size:16px}
.fs-in{background:var(--s2);border:1px solid var(--bd);border-radius:10px;padding:14px 10px;text-align:center;height:100%;position:relative;overflow:hidden}
.fs-in::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;border-radius:0 0 10px 10px}
.f-views    .fs-in::after{background:var(--ac)}
.f-cart     .fs-in::after{background:#826eff}
.f-checkout .fs-in::after{background:var(--yw)}
.f-purchase .fs-in::after{background:var(--gn)}
.f-leads    .fs-in::after{background:var(--or)}
.fs-icon{font-size:18px;margin-bottom:4px}
.fs-lbl{font-size:9px;text-transform:uppercase;letter-spacing:.7px;color:var(--mt2);margin-bottom:5px;font-weight:600}
.fs-val{font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:700}
.f-views    .fs-val{color:var(--ac)}
.f-cart     .fs-val{color:#826eff}
.f-checkout .fs-val{color:var(--yw)}
.f-purchase .fs-val{color:var(--gn)}
.f-leads    .fs-val{color:var(--or)}
.fs-conv{font-size:10px;color:var(--mt2);margin-top:3px}
.fs-cost{font-size:10px;color:var(--mt);margin-top:2px}

/* ── TAXAS DE FUNIL ─────────────────────────────────────── */
.rates-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:9px;margin-top:14px}
.rate-item{background:var(--s2);border:1px solid var(--bd);border-radius:9px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.rate-label{font-size:12px;color:var(--mt2)}
.rate-val{font-family:'JetBrains Mono',monospace;font-size:14px;font-weight:700}
.rate-ok{color:var(--gn)}.rate-warn{color:var(--yw)}.rate-bad{color:var(--rd)}

/* ── GRÁFICO ────────────────────────────────────────────── */
.chart-tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.ctab{padding:5px 13px;border-radius:20px;border:1px solid var(--bd);background:transparent;color:var(--mt2);font-family:'Inter',sans-serif;font-size:11px;font-weight:500;cursor:pointer;transition:all .15s;white-space:nowrap}
.ctab:hover{border-color:var(--ac);color:var(--ac)}
.ctab.active{background:rgba(84,112,255,.15);border-color:var(--ac);color:var(--tx);font-weight:600}

/* ── TABELA DIÁRIA ──────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:12px;min-width:550px}
thead th{padding:9px 12px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt);background:var(--s2);border-bottom:1px solid var(--bd);white-space:nowrap}
tbody td{padding:9px 12px;border-bottom:1px solid var(--bd);font-family:'JetBrains Mono',monospace}
tbody td.lab{font-family:'Inter',sans-serif;color:var(--mt2)}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:rgba(84,112,255,.04)}
tbody tr.best-day td{background:rgba(245,158,11,.06)!important}
.td-green{color:var(--gn)}.td-gold{color:var(--gold)}.td-blue{color:var(--ac)}.td-red{color:var(--rd)}

/* ── CRIATIVOS ──────────────────────────────────────────── */
.creative-list{display:flex;flex-direction:column;gap:8px}
.creative-item{background:var(--s2);border:1px solid var(--bd);border-radius:10px;padding:13px 15px;display:flex;align-items:center;gap:14px;transition:border-color .15s}
.creative-item:hover{border-color:rgba(84,112,255,.3)}
.cr-thumb{width:56px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;cursor:zoom-in;background:var(--bd);border:1px solid var(--bd);transition:transform .15s}
.cr-thumb:hover{transform:scale(1.06)}
.cr-clickable{cursor:pointer;transition:background .15s}
.cr-clickable:hover{background:var(--bd)}
.cr-assets-badge{font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;background:var(--bd);color:var(--mt);margin-left:4px;vertical-align:middle}
#crModal{position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:9999;display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:4vh 12px}
#crModal.open{display:flex}
.crm-box{background:var(--sf,#12121a);border:1px solid var(--bd);border-radius:16px;max-width:720px;width:100%;padding:20px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.crm-head{display:flex;gap:14px;align-items:flex-start;margin-bottom:14px}
.crm-img{width:110px;height:110px;border-radius:10px;object-fit:cover;background:var(--bd);cursor:zoom-in;flex-shrink:0}
.crm-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(88px,1fr));gap:8px;margin-bottom:16px}
.crm-metric{background:var(--bd);border-radius:9px;padding:8px 10px}
.crm-metric b{display:block;font-size:15px;margin-top:1px}
.crm-asset{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--bd)}
.crm-asset img{width:46px;height:46px;border-radius:7px;object-fit:cover;background:var(--bd);cursor:zoom-in;flex-shrink:0}
@media(max-width:560px){.crm-img{width:78px;height:78px}}
.cr-thumb-placeholder{width:56px;height:56px;border-radius:8px;background:var(--bd);border:1px solid var(--bd);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--mt)}
.cr-rank{font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:700;color:var(--mt);min-width:28px;text-align:center;flex-shrink:0}
.cr-body{flex:1;min-width:0}
.cr-name{font-size:13px;font-weight:600;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:5px}
.cr-tags{display:flex;gap:6px;flex-wrap:wrap}
.cr-tag{font-size:10px;font-family:'JetBrains Mono',monospace;padding:2px 7px;border-radius:4px;font-weight:600}
.cr-tag.good{background:rgba(52,211,153,.1);color:var(--gn)}
.cr-tag.warn{background:rgba(251,191,36,.1);color:var(--yw)}
.cr-tag.bad{background:rgba(248,113,113,.1);color:var(--rd)}
.cr-tag.neutral{background:rgba(84,112,255,.1);color:var(--ac)}
.cr-status{font-size:11px;font-weight:700;padding:3px 10px;border-radius:5px;flex-shrink:0}
.status-scale{background:rgba(52,211,153,.12);color:var(--gn)}
.status-watch{background:rgba(251,191,36,.12);color:var(--yw)}
.status-pause{background:rgba(248,113,113,.12);color:var(--rd)}

/* ── LIGHTBOX ───────────────────────────────────────────── */
.lb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:14px;cursor:zoom-out;padding:20px}
.lb-overlay.open{display:flex}
.lb-img{max-width:90vw;max-height:78vh;object-fit:contain;border-radius:10px;box-shadow:0 8px 48px rgba(0,0,0,.6)}
.lb-name{color:#ededf5;font-size:13px;font-family:Inter,sans-serif;text-align:center;opacity:.7;max-width:560px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lb-hint{color:#5c5c78;font-size:11px;font-family:Inter,sans-serif}
.lb-close{position:absolute;top:16px;right:20px;background:rgba(255,255,255,.1);border:none;color:#fff;font-size:20px;cursor:pointer;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;transition:background .15s}
.lb-close:hover{background:rgba(255,255,255,.2)}

/* ── MELHOR DIA ─────────────────────────────────────────── */
.best-box{background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2);border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.best-date{font-size:13px;font-weight:700;color:var(--gold)}
.best-metrics{display:flex;gap:14px;flex-wrap:wrap}
.best-m{font-size:12px;color:var(--mt2)}
.best-m strong{color:var(--tx)}

/* ── FOOTER ─────────────────────────────────────────────── */
.footer{text-align:center;padding:20px;color:var(--mt);font-size:11px;margin-top:8px}

@media(max-width:768px){
  body{padding:16px 14px}
  .header{flex-direction:column}
  .header-right{align-items:flex-start}
  .kpi-grid{grid-template-columns:repeat(2,1fr);gap:8px}
  .kpi-value{font-size:18px!important}
  .funnel{min-width:0;flex-wrap:nowrap;overflow-x:auto}
  .fs{min-width:90px;flex-shrink:0}
  .rates-grid{grid-template-columns:1fr}
}
@media(max-width:400px){.kpi-grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<!-- HEADER -->
<div class="header">
  <div class="header-left">
    <div class="logo-row">
      <div class="logo-icon">M</div>
      <div class="logo-text">Meta<span>Dash</span></div>
    </div>
    <div class="account-name"><?= $isEventReport ? '🎪 ' : '' ?><?= htmlspecialchars($accountName) ?></div>
    <?php if ($isEventReport && !empty($tokenRow['date_event'])): ?>
    <div style="font-size:12px;opacity:.75;margin-top:2px">Evento em <?= date('d/m/Y', strtotime($tokenRow['date_event'])) ?></div>
    <?php endif; ?>
    <div class="period-tag">
      <?php if ($isEventReport && $periodKey === 'tudo'): ?>Campanha completa: <?php endif; ?>
      <?= date('d/m/Y', strtotime($dateStart)) ?> → <?= date('d/m/Y', strtotime($dateEnd)) ?>
      · Gerado em <?= date('d/m H:i') ?>
    </div>
  </div>
  <div class="header-right">
    <button type="button" class="theme-btn" onclick="toggleTheme()" id="themeBtn">☀️ Modo Claro</button>
    <div class="period-btns">
      <?php if ($isEventReport): ?>
        <?php foreach ($periodOptions as $pk => $pl): ?>
        <a href="?token=<?= urlencode($token) ?>&period=<?= $pk ?>" class="pbtn <?= $periodKey === (string)$pk ? 'active' : '' ?>"><?= $pl ?></a>
        <?php endforeach; ?>
      <?php else: ?>
        <?php foreach ([3=>'3d',7=>'7d',14=>'14d',30=>'30d',60=>'60d',90=>'90d'] as $p => $l): ?>
        <a href="?token=<?= urlencode($token) ?>&period=<?= $p ?>" class="pbtn <?= $period==$p?'active':'' ?>"><?= $l ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- KPI GRID -->
<div class="kpi-grid">

  <?php if ($revenue > 0): ?>
  <div class="kpi">
    <div class="kpi-icon">💰</div>
    <div class="kpi-label">Faturamento</div>
    <div class="kpi-value c-green <?= strlen(fmtBRL($revenue))>8?'md':'' ?>"><?= fmtBRL($revenue) ?></div>
    <div class="kpi-sub">receita via pixel</div>
    <?php if ($cmpRevenue !== null): ?>
    <?php if ($showCompare): ?><div class="kpi-cmp <?= $cmpRevenue>=0?'cmp-up':'cmp-dn' ?>"><?= $cmpRevenue>=0?'↑':'↓' ?> <?= abs($cmpRevenue) ?>% vs anterior</div><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($purchases > 0): ?>
  <div class="kpi">
    <div class="kpi-icon">🛒</div>
    <div class="kpi-label">Vendas</div>
    <div class="kpi-value c-green"><?= fmtN($purchases) ?></div>
    <div class="kpi-sub"><?= $cpa > 0 ? 'CPA: '.fmtBRL($cpa) : 'sem CPA' ?></div>
    <?php if ($cmpPurch !== null): ?>
    <?php if ($showCompare): ?><div class="kpi-cmp <?= $cmpPurch>=0?'cmp-up':'cmp-dn' ?>"><?= $cmpPurch>=0?'↑':'↓' ?> <?= abs($cmpPurch) ?>% vs anterior</div><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($roas > 0): ?>
  <div class="kpi">
    <div class="kpi-icon">📈</div>
    <div class="kpi-label">ROAS</div>
    <div class="kpi-value c-gold"><?= number_format($roas,2,',','.').'x' ?></div>
    <div class="kpi-sub">retorno sobre invest.</div>
    <?php if ($cmpRoas !== null): ?>
    <?php if ($showCompare): ?><div class="kpi-cmp <?= $cmpRoas>=0?'cmp-up':'cmp-dn' ?>"><?= $cmpRoas>=0?'↑':'↓' ?> <?= abs($cmpRoas) ?>% vs anterior</div><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($roi != 0): ?>
  <div class="kpi">
    <div class="kpi-icon">💹</div>
    <div class="kpi-label">ROI</div>
    <div class="kpi-value <?= $roi>=0?'c-green':'c-red' ?> <?= strlen($roi.'%')>6?'md':'' ?>"><?= number_format($roi,1,',','.').'%' ?></div>
    <div class="kpi-sub">(receita − gasto) / gasto</div>
  </div>
  <?php endif; ?>

  <div class="kpi">
    <div class="kpi-icon">💸</div>
    <div class="kpi-label">Gasto</div>
    <div class="kpi-value c-purple <?= strlen(fmtBRL($spend))>8?'md':'' ?>"><?= fmtBRL($spend) ?></div>
    <div class="kpi-sub">investido em anúncios</div>
    <?php if ($cmpSpend !== null): ?>
    <?php if ($showCompare): ?><div class="kpi-cmp <?= $cmpSpend>=0?'cmp-dn':'cmp-up' ?>"><?= $cmpSpend>=0?'↑':'↓' ?> <?= abs($cmpSpend) ?>% vs anterior</div><?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($leads > 0): ?>
  <div class="kpi">
    <div class="kpi-icon">📋</div>
    <div class="kpi-label">Leads</div>
    <div class="kpi-value c-orange"><?= fmtN($leads) ?></div>
    <div class="kpi-sub"><?= $cpl > 0 ? 'CPL: '.fmtBRL($cpl) : '' ?></div>
    <?php if ($cmpLeads !== null): ?>
    <?php if ($showCompare): ?><div class="kpi-cmp <?= $cmpLeads>=0?'cmp-up':'cmp-dn' ?>"><?= $cmpLeads>=0?'↑':'↓' ?> <?= abs($cmpLeads) ?>% vs anterior</div><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($ctr > 0): ?>
  <div class="kpi">
    <div class="kpi-icon">👆</div>
    <div class="kpi-label">CTR</div>
    <div class="kpi-value <?= $ctr>=2?'c-green':($ctr>=1?'c-yellow':'c-red') ?>"><?= number_format($ctr,2,',','.').'%' ?></div>
    <div class="kpi-sub"><?= fmtN($clicks) ?> cliques</div>
  </div>
  <?php endif; ?>

  <?php if ($cpm > 0): ?>
  <div class="kpi">
    <div class="kpi-icon">📡</div>
    <div class="kpi-label">CPM</div>
    <div class="kpi-value c-blue <?= strlen(fmtBRL($cpm))>8?'sm':'' ?>"><?= fmtBRL($cpm) ?></div>
    <div class="kpi-sub">custo por mil impressões</div>
  </div>
  <?php endif; ?>

  <?php if ($impressions > 0): ?>
  <div class="kpi">
    <div class="kpi-icon">👁️</div>
    <div class="kpi-label">Impressões</div>
    <div class="kpi-value c-blue"><?= fmtN($impressions) ?></div>
    <div class="kpi-sub"><?= ($showCompare && $cmpImpr !== null) ? ($cmpImpr>=0?'↑':'↓').abs($cmpImpr).'% vs ant.' : 'veiculações totais' ?></div>
  </div>
  <?php endif; ?>

  <?php if ($freq > 0): ?>
  <div class="kpi">
    <div class="kpi-icon">🔁</div>
    <div class="kpi-label">Frequência</div>
    <div class="kpi-value <?= $freq>=4?'c-red':($freq>=2.5?'c-yellow':'c-green') ?>"><?= number_format($freq,1,',','.') ?>x</div>
    <div class="kpi-sub"><?= fmtN($reachCount) ?> pessoas únicas</div>
  </div>
  <?php endif; ?>

</div>

<!-- DIAGNÓSTICO AUTOMÁTICO -->
<?php
$diags = [];

// ROAS
if ($roas >= 4)       $diags[] = ['ok',   '📈 ROAS excelente',        "ROAS de {$roas}x — cada R\$1 investido retorna R\${$roas}. Resultado acima da média do mercado."];
elseif ($roas >= 2)   $diags[] = ['ok',   '📈 ROAS saudável',          "ROAS de {$roas}x — campanha rentável. Margem suficiente para escalar com cautela."];
elseif ($roas >= 1)   $diags[] = ['warn', '⚠️ ROAS abaixo do ideal',   "ROAS de {$roas}x — a campanha cobre o gasto mas sem muita margem. Revise criativos e segmentação."];
elseif ($roas > 0)    $diags[] = ['bad',  '🔴 ROAS negativo',           "ROAS de {$roas}x — gastando mais do que retorna. Pausar ou revisar urgente."];

// CTR
if ($ctr > 0) {
    if ($ctr >= 2)    $diags[] = ['ok',   '👆 CTR excelente',           "CTR de {$ctr}% — os anúncios estão chamando muita atenção. Criativos funcionando bem."];
    elseif ($ctr >= 1) $diags[] = ['info','👆 CTR dentro do padrão',    "CTR de {$ctr}% — dentro da média do Meta Ads (1–2%). Sempre há espaço para melhorar."];
    else              $diags[] = ['warn', '⚠️ CTR baixo',               "CTR de {$ctr}% — abaixo de 1%. Criativos pouco atrativos. Teste novos formatos, headlines ou thumbnails."];
}

// Frequência (saturação)
if ($freq >= 5)       $diags[] = ['bad',  '🔴 Audiência saturada',       "Frequência de {$freq}x — as mesmas pessoas estão vendo o anúncio muitas vezes. Troque os criativos ou expanda a audiência."];
elseif ($freq >= 3)   $diags[] = ['warn', '⚠️ Frequência alta',         "Frequência de {$freq}x — atenção ao cansaço criativo. Considere novas peças ou rotação de anúncios."];
elseif ($freq > 0)    $diags[] = ['ok',   '✅ Frequência saudável',     "Frequência de {$freq}x — audiência ainda absorvendo os anúncios sem saturação."];

// Taxa Visita → Carrinho
if ($rateViewsToCart > 0) {
    if ($rateViewsToCart >= 10)   $diags[] = ['ok',   '🛒 Alta taxa visita → carrinho', "Taxa de {$rateViewsToCart}% — landing page muito relevante e persuasiva para quem visita."];
    elseif ($rateViewsToCart >= 4) $diags[] = ['info','🛒 Taxa visita → carrinho ok',   "Taxa de {$rateViewsToCart}% — razoável. A landing page pode ser otimizada para converter mais visitantes."];
    else                           $diags[] = ['warn', '⚠️ Baixa taxa visita → carrinho',"Taxa de {$rateViewsToCart}% — poucos visitantes adicionam ao carrinho. Revise oferta, copy e imagens da landing page."];
}

// Taxa Checkout → Compra (abandono)
if ($rateCheckoutToPurch > 0) {
    if ($rateCheckoutToPurch >= 70)  $diags[] = ['ok',   '✅ Baixo abandono de checkout', "Taxa de {$rateCheckoutToPurch}% de quem chega ao checkout finaliza. Checkout bem otimizado."];
    elseif ($rateCheckoutToPurch >= 40) $diags[] = ['warn','⚠️ Abandono de checkout médio', "Apenas {$rateCheckoutToPurch}% finalizam após iniciar checkout. Verifique fricções: frete, método de pagamento, velocidade."];
    else                                $diags[] = ['bad', '🔴 Alto abandono de checkout',  "Somente {$rateCheckoutToPurch}% concluem após o checkout. Problema grave no fluxo de compra — simplificação urgente."];
}

// Taxa Visita → Compra
if ($rateViewsToPurchase > 0) {
    if ($rateViewsToPurchase >= 3)   $diags[] = ['ok',   '✅ Boa conversão de visitas',    "Taxa visita→compra de {$rateViewsToPurchase}% — acima da média do e-commerce."];
    elseif ($rateViewsToPurchase >= 1) $diags[] = ['info','💡 Conversão de visitas razoável',"Taxa visita→compra de {$rateViewsToPurchase}% — dentro da média. Otimize cada etapa do funil para ganhar décimos."];
    else                               $diags[] = ['warn', '⚠️ Baixa conversão de visitas', "Taxa visita→compra de {$rateViewsToPurchase}% — abaixo da média do mercado. Revise toda a jornada do cliente."];
}

// CPM
if ($cpm > 0) {
    if ($cpm > 80)    $diags[] = ['warn','⚠️ CPM elevado',              "CPM de ".fmtBRL($cpm)." — leilão caro. Isso pressiona todo o funil. Tente ampliar audiência ou testar novos criativos."];
    elseif ($cpm > 40) $diags[] = ['info','💡 CPM moderado',            "CPM de ".fmtBRL($cpm)." — dentro do esperado para o nicho."];
    else              $diags[] = ['ok',  '✅ CPM eficiente',             "CPM de ".fmtBRL($cpm)." — custo de alcance bom. Leilão competitivo e favorável."];
}
?>
<div class="diag-section">
  <div class="diag-title">🔍 Diagnóstico Automático</div>
  <div class="diag-grid">
    <?php foreach ($diags as $d): ?>
    <div class="diag-item diag-<?= $d[0] ?>">
      <div class="diag-ico"><?= $d[0]==='ok'?'✅':($d[0]==='warn'?'⚠️':($d[0]==='bad'?'🔴':'💡')) ?></div>
      <div class="diag-body">
        <div class="diag-head"><?= $d[1] ?></div>
        <div class="diag-desc"><?= $d[2] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($diags)): ?>
    <div class="diag-item diag-info"><div class="diag-ico">💡</div><div class="diag-body"><div class="diag-desc">Sem dados suficientes para diagnóstico neste período.</div></div></div>
    <?php endif; ?>
  </div>
</div>

<!-- FUNIL -->
<?php if ($views > 0 || $purchases > 0 || $leads > 0): ?>
<div class="section">
  <div class="section-title">🔽 Funil de Conversão</div>
  <div class="funnel-wrap">
    <div class="funnel">
      <?php if ($clicks > 0): ?>
      <div class="fs f-views">
        <div class="fs-in">
          <div class="fs-icon">🌐</div><div class="fs-lbl">Cliques/Visitas</div>
          <div class="fs-val"><?= fmtN($clicks) ?></div>
          <div class="fs-conv">CTR: <?= number_format($ctr,2,',','.').'%' ?></div>
        </div>
      </div>
      <?php elseif ($views > 0): ?>
      <div class="fs f-views">
        <div class="fs-in">
          <div class="fs-icon">👁️</div><div class="fs-lbl">Visitas</div>
          <div class="fs-val"><?= fmtN($views) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($addToCart > 0): ?>
      <?php $base = $clicks > 0 ? $clicks : $views; ?>
      <div class="fs f-cart">
        <div class="fs-in">
          <div class="fs-icon">🛒</div><div class="fs-lbl">Add to Cart</div>
          <div class="fs-val"><?= fmtN($addToCart) ?></div>
          <?php if ($base > 0): ?><div class="fs-conv"><?= round($addToCart/$base*100,1) ?>% das visitas</div><?php endif; ?>
          <?php if ($spend > 0): ?><div class="fs-cost"><?= fmtBRL($spend/$addToCart) ?>/un</div><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($checkout > 0): ?>
      <div class="fs f-checkout">
        <div class="fs-in">
          <div class="fs-icon">💳</div><div class="fs-lbl">Início Checkout</div>
          <div class="fs-val"><?= fmtN($checkout) ?></div>
          <?php if ($addToCart > 0): ?><div class="fs-conv"><?= round($checkout/$addToCart*100,1) ?>% do carrinho</div><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($purchases > 0): ?>
      <div class="fs f-purchase">
        <div class="fs-in">
          <div class="fs-icon">✅</div><div class="fs-lbl">Compras</div>
          <div class="fs-val"><?= fmtN($purchases) ?></div>
          <?php if ($checkout > 0): ?><div class="fs-conv"><?= round($purchases/$checkout*100,1) ?>% do checkout</div><?php endif; ?>
          <?php if ($cpa > 0): ?><div class="fs-cost"><?= fmtBRL($cpa) ?>/compra</div><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($leads > 0): ?>
      <div class="fs f-leads">
        <div class="fs-in">
          <div class="fs-icon">📋</div><div class="fs-lbl">Leads</div>
          <div class="fs-val"><?= fmtN($leads) ?></div>
          <?php if ($cpl > 0): ?><div class="fs-cost"><?= fmtBRL($cpl) ?>/lead</div><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- TAXAS DETALHADAS -->
  <?php if ($rateViewsToCart > 0 || $rateViewsToCheckout > 0 || $rateViewsToPurchase > 0): ?>
  <div class="rates-grid">
    <?php if ($rateViewsToCart > 0): ?>
    <div class="rate-item">
      <span class="rate-label">Visitas → Add to Cart</span>
      <span class="rate-val <?= $rateViewsToCart>=10?'rate-ok':($rateViewsToCart>=4?'rate-warn':'rate-bad') ?>"><?= $rateViewsToCart ?>%</span>
    </div>
    <?php endif; ?>
    <?php if ($rateViewsToCheckout > 0): ?>
    <div class="rate-item">
      <span class="rate-label">Visitas → Checkout</span>
      <span class="rate-val <?= $rateViewsToCheckout>=5?'rate-ok':($rateViewsToCheckout>=2?'rate-warn':'rate-bad') ?>"><?= $rateViewsToCheckout ?>%</span>
    </div>
    <?php endif; ?>
    <?php if ($rateViewsToPurchase > 0): ?>
    <div class="rate-item">
      <span class="rate-label">Visitas → Compras</span>
      <span class="rate-val <?= $rateViewsToPurchase>=3?'rate-ok':($rateViewsToPurchase>=1?'rate-warn':'rate-bad') ?>"><?= $rateViewsToPurchase ?>%</span>
    </div>
    <?php endif; ?>
    <?php if ($rateCartToCheckout > 0): ?>
    <div class="rate-item">
      <span class="rate-label">Carrinho → Checkout</span>
      <span class="rate-val <?= $rateCartToCheckout>=60?'rate-ok':($rateCartToCheckout>=40?'rate-warn':'rate-bad') ?>"><?= $rateCartToCheckout ?>%</span>
    </div>
    <?php endif; ?>
    <?php if ($rateCheckoutToPurch > 0): ?>
    <div class="rate-item">
      <span class="rate-label">Checkout → Compra</span>
      <span class="rate-val <?= $rateCheckoutToPurch>=70?'rate-ok':($rateCheckoutToPurch>=40?'rate-warn':'rate-bad') ?>"><?= $rateCheckoutToPurch ?>%</span>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- MELHOR DIA -->
<?php if ($bestDay && floatval($bestDay['revenue']) > 0): ?>
<div class="section">
  <div class="section-title">🏆 Melhor dia do período</div>
  <div class="best-box">
    <div class="best-date">📅 <?= date('d/m/Y', strtotime($bestDay['snapshot_date'])) ?></div>
    <div class="best-metrics">
      <?php if ($bestDay['purchases'] > 0): ?><div class="best-m">🛒 <strong><?= $bestDay['purchases'] ?></strong> compras</div><?php endif; ?>
      <?php if ($bestDay['revenue'] > 0): ?><div class="best-m">💰 <strong><?= fmtBRL($bestDay['revenue']) ?></strong></div><?php endif; ?>
      <?php if ($bestDay['leads'] > 0): ?><div class="best-m">📋 <strong><?= $bestDay['leads'] ?></strong> leads</div><?php endif; ?>
      <?php if ($bestDay['spend'] > 0): ?>
        <?php $dayRoas = floatval($bestDay['spend'])>0?round(floatval($bestDay['revenue'])/floatval($bestDay['spend']),2):0; ?>
        <div class="best-m">💸 <strong><?= fmtBRL($bestDay['spend']) ?></strong> gasto<?= $dayRoas>0?' · ROAS <strong>'.$dayRoas.'x</strong>':'' ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- GRÁFICO -->
<?php if (!empty($daily)): ?>
<div class="section">
  <div class="section-title">📊 Evolução no período</div>
  <div class="chart-tabs">
    <?php if ($purchases > 0): ?><button type="button" class="ctab active" onclick="setChart('purchases',this)">🛒 Compras</button><?php endif; ?>
    <?php if ($revenue > 0):   ?><button type="button" class="ctab <?= $purchases==0?'active':'' ?>" onclick="setChart('revenue',this)">💰 Receita</button><?php endif; ?>
    <?php if ($leads > 0):     ?><button type="button" class="ctab" onclick="setChart('leads',this)">📋 Leads</button><?php endif; ?>
    <button type="button" class="ctab" onclick="setChart('spend',this)">💸 Gasto</button>
    <?php if ($impressions > 0): ?><button type="button" class="ctab" onclick="setChart('impressions',this)">👁️ Impressões</button><?php endif; ?>
    <?php if ($clicks > 0):      ?><button type="button" class="ctab" onclick="setChart('clicks',this)">👆 Cliques</button><?php endif; ?>
    <?php if ($addToCart > 0):   ?><button type="button" class="ctab" onclick="setChart('add_to_cart',this)">🛒 Carrinho</button><?php endif; ?>
  </div>
  <canvas id="chart" height="70"></canvas>
</div>
<?php endif; ?>

<!-- CRIATIVOS -->
<?php if (!empty($creatives)): ?>
<div class="section">
  <div class="section-title">🎨 Top Criativos — Ranking de Performance</div>
  <div class="creative-list">
    <?php foreach ($creatives as $i => $cr):
      $crRoas = floatval($cr['roas']);
      $crCtr  = floatval($cr['ctr']);
      $crPurch= intval($cr['purchases']);
      $crSpend= floatval($cr['spend']);
      $crCpa  = floatval($cr['cpa']);
      $crImpr = intval($cr['impressions']);
      // Status
      if ($crRoas >= 3 || ($crPurch > 0 && $crRoas == 0)) $crStatus = ['scale', '🟢 Escalar'];
      elseif ($crRoas >= 1) $crStatus = ['watch', '🟡 Monitorar'];
      else $crStatus = ['pause', '🔴 Pausar'];
      // Rank emoji
      $rankIco = $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':($i+1)));
    ?>
    <?php
      $imgSrc = $cr['image_url'] ?: $cr['thumbnail_url'] ?: '';
    ?>
    <?php
      $crAssets = $assetsByAd[$cr['ad_id']] ?? [];
      $crPayload = htmlspecialchars(json_encode([
        'name'   => $cr['ad_name'] . (!empty($cr['is_dynamic']) ? ' ⚡' : ''),
        'img'    => $imgSrc,
        'dyn'    => !empty($cr['is_dynamic']),
        'roas'   => $crRoas, 'ctr' => $crCtr, 'purch' => $crPurch,
        'leads'  => (int)$cr['leads'], 'spend' => $crSpend, 'cpa' => $crCpa, 'impr' => $crImpr,
        'assets' => $crAssets,
      ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="creative-item cr-clickable" onclick="openCr(<?= $crPayload ?>)" title="Ver detalhes do criativo">
      <div class="cr-rank"><?= $rankIco ?></div>
      <?php if ($imgSrc): ?>
      <img class="cr-thumb"
           src="<?= htmlspecialchars($imgSrc) ?>"
           alt="<?= htmlspecialchars($cr['ad_name']) ?>"
           onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
      <div class="cr-thumb-placeholder" style="display:none">🎨</div>
      <?php else: ?>
      <div class="cr-thumb-placeholder">🎨</div>
      <?php endif; ?>
      <div class="cr-body">
        <div class="cr-name" title="<?= htmlspecialchars($cr['ad_name']) ?>"><?= htmlspecialchars($cr['ad_name']) ?><?= $cr['is_dynamic']?' ⚡':'' ?><?php if ($crAssets): ?> <span class="cr-assets-badge"><?= count($crAssets) ?> assets</span><?php endif; ?></div>
        <div class="cr-tags">
          <?php if ($crRoas > 0): ?><span class="cr-tag <?= $crRoas>=3?'good':($crRoas>=1?'warn':'bad') ?>">ROAS <?= $crRoas ?>x</span><?php endif; ?>
          <?php if ($crPurch > 0): ?><span class="cr-tag good"><?= $crPurch ?> vendas</span><?php endif; ?>
          <?php if ($cr['leads'] > 0): ?><span class="cr-tag neutral"><?= $cr['leads'] ?> leads</span><?php endif; ?>
          <?php if ($crCtr > 0): ?><span class="cr-tag <?= $crCtr>=2?'good':($crCtr>=1?'warn':'bad') ?>">CTR <?= $crCtr ?>%</span><?php endif; ?>
          <?php if ($crCpa > 0): ?><span class="cr-tag neutral">CPA <?= fmtBRL($crCpa) ?></span><?php endif; ?>
          <?php if ($crSpend > 0): ?><span class="cr-tag neutral"><?= fmtBRL($crSpend) ?> gasto</span><?php endif; ?>
          <?php if ($crImpr > 0): ?><span class="cr-tag neutral"><?= fmtN($crImpr) ?> impr.</span><?php endif; ?>
        </div>
      </div>
      <span class="cr-status status-<?= $crStatus[0] ?>"><?= $crStatus[1] ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- TABELA DIÁRIA -->
<?php if (!empty($daily)): ?>
<div class="section">
  <div class="section-title">📅 Breakdown diário</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Data</th>
          <?php if ($purchases > 0): ?><th>Compras</th><th>Receita</th><th>ROAS</th><?php endif; ?>
          <?php if ($leads > 0): ?><th>Leads</th><?php endif; ?>
          <th>Gasto</th>
          <?php if ($impressions > 0): ?><th>Impr.</th><th>CTR</th><?php endif; ?>
          <?php if ($addToCart > 0): ?><th>Carrinho</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php
        $bestDate = $bestDay ? $bestDay['snapshot_date'] : null;
        foreach ($daily as $d):
          $dSpend = floatval($d['spend']);
          $dRev   = floatval($d['revenue']);
          $dPurch = intval($d['purchases']);
          $dImpr  = intval($d['impressions']);
          $dClick = intval($d['clicks']);
          $dRoas  = $dSpend > 0 ? round($dRev/$dSpend,2) : 0;
          $dCtr   = $dImpr > 0 ? round($dClick/$dImpr*100,2) : 0;
          $isBest = $bestDate && $d['snapshot_date'] === $bestDate && $dRev > 0;
        ?>
        <tr <?= $isBest?'class="best-day"':'' ?>>
          <td class="lab"><?= date('d/m', strtotime($d['snapshot_date'])) ?><?= $isBest?' 🏆':'' ?></td>
          <?php if ($purchases > 0): ?>
          <td class="<?= $dPurch > 0?'td-green':'' ?>"><?= $dPurch > 0 ? $dPurch : '—' ?></td>
          <td class="<?= $dRev > 0?'td-green':'' ?>"><?= $dRev > 0 ? fmtBRL($dRev) : '—' ?></td>
          <td class="<?= $dRoas>=3?'td-green':($dRoas>=1?'td-gold':'td-red') ?>"><?= $dRoas > 0 ? $dRoas.'x' : '—' ?></td>
          <?php endif; ?>
          <?php if ($leads > 0): ?><td class="<?= $d['leads']>0?'td-blue':'' ?>"><?= $d['leads'] > 0 ? $d['leads'] : '—' ?></td><?php endif; ?>
          <td class="<?= $dSpend>0?'td-blue':'' ?>"><?= $dSpend > 0 ? fmtBRL($dSpend) : '—' ?></td>
          <?php if ($impressions > 0): ?>
          <td><?= $dImpr > 0 ? fmtN($dImpr) : '—' ?></td>
          <td class="<?= $dCtr>=2?'td-green':($dCtr>=1?'td-gold':'') ?>"><?= $dCtr > 0 ? $dCtr.'%' : '—' ?></td>
          <?php endif; ?>
          <?php if ($addToCart > 0): ?><td><?= $d['add_to_cart'] > 0 ? $d['add_to_cart'] : '—' ?></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="footer">
  Relatório gerado em <?= date('d/m/Y \à\s H:i') ?> · MetaDash
  <?php if (!empty($creatives)): ?> · <?= count($creatives) ?> criativo(s) analisados<?php endif; ?>
</div>

<!-- LIGHTBOX -->
<div id="crModal" onclick="if(event.target===this)closeCr()"><div class="crm-box" onclick="event.stopPropagation()"></div></div>

<div class="lb-overlay" id="lb" onclick="closeLb()">
  <button class="lb-close" onclick="closeLb()" type="button">✕</button>
  <img class="lb-img" id="lbImg" src="" alt="">
  <div class="lb-name" id="lbName"></div>
  <div class="lb-hint">Clique para fechar</div>
</div>

<script>
var DAILY = <?= json_encode($daily, JSON_UNESCAPED_UNICODE) ?>;
var chartInst = null;

// ── TEMA ─────────────────────────────────────────────────────
var _theme = localStorage.getItem('md_report_theme') || 'dark';
document.documentElement.setAttribute('data-theme', _theme);
document.getElementById('themeBtn').textContent = _theme==='dark' ? '☀️ Modo Claro' : '🌙 Modo Escuro';

function toggleTheme() {
  _theme = _theme === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', _theme);
  localStorage.setItem('md_report_theme', _theme);
  document.getElementById('themeBtn').textContent = _theme==='dark' ? '☀️ Modo Claro' : '🌙 Modo Escuro';
  if (chartInst) {
    var activeTab = document.querySelector('.ctab.active');
    if (activeTab) activeTab.click();
  }
}

// ── GRÁFICO ───────────────────────────────────────────────────
var COLORS_MAP = {
  purchases:'#34d399', revenue:'#f59e0b', leads:'#fb923c',
  spend:'#826eff', impressions:'#5470ff', clicks:'#2dd4bf', add_to_cart:'#a78bfa'
};
var LABELS_MAP = {
  purchases:'Compras', revenue:'Receita', leads:'Leads',
  spend:'Gasto', impressions:'Impressões', clicks:'Cliques', add_to_cart:'Carrinho'
};

function setChart(metric, btn) {
  document.querySelectorAll('.ctab').forEach(function(b){ b.classList.remove('active'); });
  if (btn) btn.classList.add('active');

  var isMoney = (metric==='revenue'||metric==='spend');
  var labels  = DAILY.map(function(d){ var p=d.snapshot_date.split('-'); return p[2]+'/'+p[1]; });
  var values  = DAILY.map(function(d){ return parseFloat(d[metric]||0); });
  var color   = COLORS_MAP[metric] || '#5470ff';
  var isLight = document.documentElement.getAttribute('data-theme')==='light';
  var gridC   = isLight ? 'rgba(0,0,0,0.06)' : 'rgba(255,255,255,0.04)';
  var tickC   = isLight ? '#9999aa' : '#5c5c78';

  if (chartInst) { chartInst.destroy(); chartInst = null; }

  var hex=color.replace('#','');
  var r=parseInt(hex.substring(0,2),16),g=parseInt(hex.substring(2,4),16),b=parseInt(hex.substring(4,6),16);

  chartInst = new Chart(document.getElementById('chart'), {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: LABELS_MAP[metric],
        data: values,
        borderColor: color,
        backgroundColor: 'rgba('+r+','+g+','+b+',0.08)',
        borderWidth: 2.5, fill: true, tension: 0.4,
        pointBackgroundColor: color, pointRadius: 4, pointHoverRadius: 7
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              return isMoney
                ? ' R$ '+ctx.parsed.y.toLocaleString('pt-BR',{minimumFractionDigits:2})
                : ' '+LABELS_MAP[metric]+': '+ctx.parsed.y.toLocaleString('pt-BR');
            }
          }
        }
      },
      scales: {
        x: { grid:{color:gridC}, ticks:{color:tickC,font:{size:11}} },
        y: { grid:{color:gridC}, ticks:{color:tickC,font:{size:11},
             callback: function(v){ return isMoney?'R$'+v.toLocaleString('pt-BR'):v; }} }
      }
    }
  });
}

document.addEventListener('DOMContentLoaded', function() {
  var first = document.querySelector('.ctab.active') || document.querySelector('.ctab');
  if (first) first.click();
});

function openCr(c) {
  var m = document.getElementById('crModal');
  var esc = function(t){ var d=document.createElement('div'); d.textContent = t==null?'':t; return d.innerHTML; };
  var brl = function(v){ return 'R$ ' + Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); };
  var num = function(v){ return Number(v||0).toLocaleString('pt-BR'); };
  var metric = function(lbl, val){ return '<div class="crm-metric"><span style="font-size:10px;opacity:.6;text-transform:uppercase">'+lbl+'</span><b>'+val+'</b></div>'; };

  var mets = '';
  if (c.roas > 0)  mets += metric('ROAS', c.roas + 'x');
  if (c.purch > 0) mets += metric('Vendas', num(c.purch));
  if (c.cpa > 0)   mets += metric('CPA', brl(c.cpa));
  if (c.leads > 0) mets += metric('Leads', num(c.leads));
  if (c.ctr > 0)   mets += metric('CTR', c.ctr + '%');
  if (c.spend > 0) mets += metric('Gasto', brl(c.spend));
  if (c.impr > 0)  mets += metric('Impressoes', num(c.impr));

  var assets = '';
  if (c.assets && c.assets.length) {
    assets = '<div style="font-size:13px;font-weight:700;margin:6px 0 4px">⚡ Assets deste criativo dinâmico'
           + ' <span style="font-weight:400;opacity:.6;font-size:11px">— do melhor pro pior</span></div>'
           + c.assets.map(function(a, i){
               var safeThumb = String(a.thumb || '').replace(/'/g, '');
               var safeName  = esc(a.name).replace(/'/g, '');
               var th = a.thumb
                 ? '<img src="'+esc(a.thumb)+'" onclick="openLb(\''+safeThumb+'\',\''+safeName+'\')" onerror="this.style.visibility=\'hidden\'">'
                 : '<div style="width:46px;height:46px;border-radius:7px;background:var(--bd);display:flex;align-items:center;justify-content:center">'+(a.type==='video'?'▶':'🖼')+'</div>';
               var right = a.purch > 0 ? num(a.purch)+' vendas' : (a.leads > 0 ? num(a.leads)+' leads' : num(a.impr)+' impr.');
               return '<div class="crm-asset">'
                 + '<span style="width:18px;text-align:center;font-weight:800;opacity:'+(i===0?'1':'.5')+'">'+(i+1)+'</span>'
                 + th
                 + '<div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
                 +   (a.type==='video'?'▶ ':'🖼 ')+esc(a.name)+'</div>'
                 + '<div style="font-size:11px;opacity:.6;margin-top:1px">'+brl(a.spend)+' · ROAS '+(a.roas||0)+'x'+(a.cpa>0?' · CPA '+brl(a.cpa):'')+'</div></div>'
                 + '<div style="font-size:12px;font-weight:700;white-space:nowrap">'+right+'</div>'
                 + '</div>';
             }).join('');
  } else if (c.dyn) {
    assets = '<div style="font-size:12px;opacity:.6;padding:8px 0">Assets deste criativo ainda não sincronizados.</div>';
  }

  var safeImg  = String(c.img || '').replace(/'/g, '');
  var safeName = esc(c.name).replace(/'/g, '');
  m.querySelector('.crm-box').innerHTML =
      '<div class="crm-head">'
    +   (c.img ? '<img class="crm-img" src="'+esc(c.img)+'" onclick="openLb(\''+safeImg+'\',\''+safeName+'\')" onerror="this.style.display=\'none\'">' : '')
    +   '<div style="flex:1;min-width:0"><div style="font-size:15px;font-weight:800;line-height:1.3">'+esc(c.name)+'</div>'
    +   '<div style="font-size:11px;opacity:.6;margin-top:3px">Clique na imagem para ampliar</div></div>'
    +   '<button onclick="closeCr()" style="background:none;border:1px solid var(--bd);border-radius:8px;color:inherit;padding:4px 9px;cursor:pointer">✕</button>'
    + '</div>'
    + '<div class="crm-metrics">' + mets + '</div>'
    + assets;
  m.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeCr() {
  document.getElementById('crModal').classList.remove('open');
  document.body.style.overflow = '';
}

function openLb(url, name) {
  var lb = document.getElementById('lb');
  var img = document.getElementById('lbImg');
  var lbName = document.getElementById('lbName');
  img.src = url;
  lbName.textContent = name || '';
  lb.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeLb() {
  document.getElementById('lb').classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') { closeLb(); closeCr(); }
});
</script>
</body>
</html>