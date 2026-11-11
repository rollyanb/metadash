<?php
// ── CONFIGURAÇÃO ──────────────────────────────────────────────
// Substitua os valores abaixo pelas suas credenciais
define('TELEGRAM_TOKEN',   '7837628374:AAHr3XS_XQd6INzhbezlv4NuBdKIhMHf2GY');
define('TELEGRAM_CHAT_ID', '-1002485482569');

// ── FUNÇÃO PRINCIPAL ──────────────────────────────────────────
function sendTelegram($message) {
    $url  = 'https://api.telegram.org/bot' . TELEGRAM_TOKEN . '/sendMessage';
    $data = [
        'chat_id'    => TELEGRAM_CHAT_ID,
        'text'       => $message,
        'parse_mode' => 'HTML',
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        10);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

// ── BUSCA VENDAS DA ÚLTIMA HORA ───────────────────────────────
function checkSalesAndNotify($db) {
    // Busca conversões das últimas 2 horas para comparar
    // (cron roda a cada 30min, então pegamos 1h pra ter margem)
    $dateStart = date('Y-m-d', strtotime('-1 day'));
    $dateEnd   = date('Y-m-d');

    $results = $db->fetchAll("
        SELECT
            a.account_name,
            cs.snapshot_date,
            cs.purchases,
            cs.revenue,
            cs.leads,
            cs.spend
        FROM conversion_snapshots cs
        JOIN ad_accounts a ON a.account_id = cs.account_id
        WHERE cs.snapshot_date BETWEEN ? AND ?
          AND cs.synced_at >= DATE_SUB(NOW(), INTERVAL 35 MINUTE)
          AND (cs.purchases > 0 OR cs.leads > 0)
        ORDER BY cs.purchases DESC, cs.leads DESC
    ", [$dateStart, $dateEnd]);

    if (empty($results)) return; // Nada novo

    // Monta mensagem
    $lines   = [];
    $totalPurchases = 0;
    $totalRevenue   = 0;
    $totalLeads     = 0;
    $totalSpend     = 0;

    foreach ($results as $r) {
        $line = "<b>{$r['account_name']}</b>\n";
        if ($r['purchases'] > 0) {
            $line .= "  🛒 {$r['purchases']} compras";
            if ($r['revenue'] > 0) {
                $line .= " · R$" . number_format($r['revenue'], 2, ',', '.');
            }
            $line .= "\n";
            $totalPurchases += $r['purchases'];
            $totalRevenue   += $r['revenue'];
        }
        if ($r['leads'] > 0) {
            $line .= "  📋 {$r['leads']} leads\n";
            $totalLeads += $r['leads'];
        }
        $totalSpend += $r['spend'];
        $lines[] = $line;
    }

    if (empty($lines)) return;

    $now = date('d/m H:i');
    $msg = "📊 <b>MetaDash — Atualização {$now}</b>\n\n";
    $msg .= implode("\n", $lines);
    $msg .= "\n<b>TOTAL</b>\n";
    if ($totalPurchases > 0) {
        $roas = $totalSpend > 0 ? round($totalRevenue / $totalSpend, 2) : 0;
        $msg .= "🛒 {$totalPurchases} compras · R$" . number_format($totalRevenue, 2, ',', '.') . "\n";
        $msg .= "📈 ROAS {$roas}x · Gasto R$" . number_format($totalSpend, 2, ',', '.') . "\n";
    }
    if ($totalLeads > 0) {
        $msg .= "📋 {$totalLeads} leads\n";
    }

    sendTelegram($msg);
}