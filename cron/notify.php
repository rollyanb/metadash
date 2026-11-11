<?php
// cron/notify.php — só envia Telegram, incremental, contas selecionadas
// */30 * * * * /usr/bin/php /home/u567594569/domains/metadash.space/public_html/cron/notify.php

ini_set('display_errors', 0);
$base = dirname(__DIR__);
require_once $base . '/config/database.php';

// ── CONFIGURAÇÃO ──────────────────────────────────────────────
$tgToken  = '7837628374:AAHr3XS_XQd6INzhbezlv4NuBdKIhMHf2GY';
$tgChatId = '-1002485482569';

$db    = Database::getInstance();
$today = date('Y-m-d');

// ── CONTAS SELECIONADAS ───────────────────────────────────────
try {
    $notifyRows = $db->fetchAll("SELECT account_id FROM notify_accounts");
} catch (Exception $e) { exit; }

if (empty($notifyRows)) exit; // nenhuma conta selecionada

$notifyIds = array_column($notifyRows, 'account_id');
$ph        = implode(',', array_fill(0, count($notifyIds), '?'));

// ── DADOS ATUAIS DE HOJE ──────────────────────────────────────
$current = $db->fetchAll("
    SELECT
        cs.account_id,
        a.account_name,
        COALESCE(cs.purchases, 0) as purchases,
        COALESCE(cs.revenue,   0) as revenue,
        COALESCE(cs.leads,     0) as leads,
        COALESCE(cs.spend,     0) as spend,
        COALESCE(cs.cpa,       0) as cpa,
        COALESCE(cs.cpl,       0) as cpl,
        COALESCE(cs.roas,      0) as roas
    FROM conversion_snapshots cs
    JOIN ad_accounts a ON a.account_id = cs.account_id
    WHERE cs.snapshot_date = ?
      AND cs.account_id IN ({$ph})
    ORDER BY cs.purchases DESC, cs.leads DESC
", array_merge([$today], $notifyIds));

if (empty($current)) exit; // sem dados de hoje ainda

// ── ÚLTIMO ENVIO ──────────────────────────────────────────────
$lastSent = $db->fetchAll("
    SELECT account_id, purchases, revenue, leads, spend
    FROM notify_last_sent
    WHERE sent_date = ?
      AND account_id IN ({$ph})
", array_merge([$today], $notifyIds));

$lastMap = [];
foreach ($lastSent as $ls) {
    $lastMap[$ls['account_id']] = $ls;
}

// ── CALCULA DELTA E MONTA MENSAGEM ────────────────────────────
$lines   = [];
$totDayP = 0; $totDayR = 0; $totDayL = 0; $totDayS = 0;
$hasNew  = false;

foreach ($current as $c) {
    $aid  = $c['account_id'];
    $last = $lastMap[$aid] ?? ['purchases'=>0,'revenue'=>0,'leads'=>0,'spend'=>0];

    $newP = max(0, intval($c['purchases'])   - intval($last['purchases']));
    $newR = max(0, floatval($c['revenue'])   - floatval($last['revenue']));
    $newL = max(0, intval($c['leads'])       - intval($last['leads']));

    $totDayP += intval($c['purchases']);
    $totDayR += floatval($c['revenue']);
    $totDayL += intval($c['leads']);
    $totDayS += floatval($c['spend']);

    if ($newP === 0 && $newL === 0) continue;
    $hasNew = true;

    $line = "<b>{$c['account_name']}</b>\n";

    if ($newP > 0) {
        $line .= "  🛒 {$newP} novas compras · R$" . number_format($newR, 2, ',', '.') . "\n";
        $line .= "  Total de compras do dia: {$c['purchases']}\n";
        if ($c['cpa']     > 0) $line .= "  Custo por compra: R$" . number_format($c['cpa'], 2, ',', '.') . "\n";
        if ($c['revenue'] > 0) $line .= "  Faturado: R$" . number_format($c['revenue'], 2, ',', '.') . "\n";
        if ($c['spend']   > 0) $line .= "  Valor gasto: R$" . number_format($c['spend'], 2, ',', '.') . "\n";
        if ($c['roas']    > 0) $line .= "  ROAS: {$c['roas']}x\n";
        if ($c['spend'] > 0 && $c['revenue'] > 0) {
            $roi = round((($c['revenue'] - $c['spend']) / $c['spend']) * 100, 1);
            $line .= "  ROI: {$roi}%\n";
        }
    }
    if ($newL > 0) {
        $line .= "  📋 {$newL} novos leads\n";
        $line .= "  Total leads do dia: {$c['leads']}\n";
        if ($c['cpl'] > 0) $line .= "  Custo por lead: R$" . number_format($c['cpl'], 2, ',', '.') . "\n";
    }

    $lines[] = $line;
}

if (!$hasNew) exit; // nada novo, não envia

// ── MONTA MENSAGEM FINAL ──────────────────────────────────────
$now = date('d/m/Y H:i');
$msg = "📊 <b>MetaDash — Atualização {$now}</b>\n\n";
$msg .= implode("\n", $lines);

$msg .= "\n<b>━━ TOTAL DO DIA ━━</b>\n";
if ($totDayP > 0) {
    $roas = $totDayS > 0 ? round($totDayR / $totDayS, 2) : 0;
    $roi  = $totDayS > 0 ? round((($totDayR - $totDayS) / $totDayS) * 100, 1) : 0;
    $msg .= "🛒 {$totDayP} compras · R$" . number_format($totDayR, 2, ',', '.') . "\n";
    $msg .= "💸 Gasto: R$" . number_format($totDayS, 2, ',', '.') . "\n";
    $msg .= "📈 ROAS: {$roas}x · ROI: {$roi}%\n";
}
if ($totDayL > 0) {
    $cplTotal = $totDayS > 0 && $totDayL > 0 ? round($totDayS / $totDayL, 2) : 0;
    $msg .= "📋 {$totDayL} leads";
    if ($cplTotal > 0) $msg .= " · CPL: R$" . number_format($cplTotal, 2, ',', '.');
    $msg .= "\n";
}

// ── ENVIA TELEGRAM ────────────────────────────────────────────
$ch = curl_init("https://api.telegram.org/bot{$tgToken}/sendMessage");
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query([
    'chat_id'    => $tgChatId,
    'text'       => $msg,
    'parse_mode' => 'HTML',
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        10);
curl_exec($ch);
curl_close($ch);

// ── SALVA SNAPSHOT ────────────────────────────────────────────
foreach ($current as $c) {
    $db->execute("
        INSERT INTO notify_last_sent (account_id, sent_date, purchases, revenue, leads, spend)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            purchases=VALUES(purchases), revenue=VALUES(revenue),
            leads=VALUES(leads), spend=VALUES(spend), sent_at=NOW()
    ", [$c['account_id'], $today, $c['purchases'], $c['revenue'], $c['leads'], $c['spend']]);
}