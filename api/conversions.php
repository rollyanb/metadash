<?php
// api/conversions.php — retorna dados de conversão do banco
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

function j($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

require_once __DIR__ . '/../includes/auth.php';
if (!Auth::isLoggedIn()) j(['error' => 'Não autenticado']);

require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

$input     = json_decode(file_get_contents('php://input'), true) ?: [];
$dateStart = $input['date_start'] ?? date('Y-m-d', strtotime('-30 days'));
$dateEnd   = $input['date_end']   ?? date('Y-m-d');
$accountId = $input['account_id'] ?? '';

$allowedAccounts = Auth::getAllowedAccounts();

// Monta WHERE de contas permitidas
$where  = "WHERE c.snapshot_date BETWEEN ? AND ?";
$params = [$dateStart, $dateEnd];

if ($accountId) {
    if ($allowedAccounts !== null && !in_array($accountId, $allowedAccounts)) {
        j(['error' => 'Acesso negado']);
    }
    $where .= " AND c.account_id = ?";
    $params[] = $accountId;
} elseif ($allowedAccounts !== null && !empty($allowedAccounts)) {
    $ph = implode(',', array_fill(0, count($allowedAccounts), '?'));
    $where .= " AND c.account_id IN ({$ph})";
    $params = array_merge($params, $allowedAccounts);
} elseif ($allowedAccounts !== null && empty($allowedAccounts)) {
    j(['totals' => [], 'daily' => [], 'by_account' => []]);
}

// WHERE sem alias para queries simples
$whereSimple = str_replace("c.", "", $where);

// Totais agregados
$totals = $db->fetch("
    SELECT
        SUM(views)       AS views,
        SUM(add_to_cart) AS add_to_cart,
        SUM(checkout)    AS checkout,
        SUM(purchases)   AS purchases,
        SUM(leads)       AS leads,
        SUM(revenue)     AS revenue,
        SUM(spend)       AS spend,
        CASE WHEN SUM(spend) > 0 THEN ROUND(SUM(revenue)/SUM(spend), 2) ELSE 0 END AS roas,
        CASE WHEN SUM(views) > 0 THEN ROUND(SUM(spend)/SUM(views), 4) ELSE 0 END AS cpv,
        CASE WHEN SUM(add_to_cart) > 0 THEN ROUND(SUM(spend)/SUM(add_to_cart), 2) ELSE 0 END AS cpa_cart,
        CASE WHEN SUM(checkout) > 0 THEN ROUND(SUM(spend)/SUM(checkout), 2) ELSE 0 END AS cpa_checkout,
        CASE WHEN SUM(purchases) > 0 THEN ROUND(SUM(spend)/SUM(purchases), 2) ELSE 0 END AS cpa,
        CASE WHEN SUM(leads) > 0 THEN ROUND(SUM(spend)/SUM(leads), 2) ELSE 0 END AS cpl
    FROM conversion_snapshots {$whereSimple}
", $params);

// Dados diários para gráfico
$daily = $db->fetchAll("
    SELECT
        snapshot_date,
        SUM(views)       AS views,
        SUM(add_to_cart) AS add_to_cart,
        SUM(checkout)    AS checkout,
        SUM(purchases)   AS purchases,
        SUM(leads)       AS leads,
        SUM(revenue)     AS revenue,
        SUM(spend)       AS spend,
        CASE WHEN SUM(spend) > 0 THEN ROUND(SUM(revenue)/SUM(spend), 2) ELSE 0 END AS roas
    FROM conversion_snapshots {$whereSimple}
    GROUP BY snapshot_date
    ORDER BY snapshot_date ASC
", $params);

// Por conta (para cards individuais)
$byAccount = $db->fetchAll("
    SELECT
        c.account_id,
        a.account_name,
        SUM(c.views)       AS views,
        SUM(c.add_to_cart) AS add_to_cart,
        SUM(c.checkout)    AS checkout,
        SUM(c.purchases)   AS purchases,
        SUM(c.leads)       AS leads,
        SUM(c.revenue)     AS revenue,
        SUM(c.spend)       AS spend,
        CASE WHEN SUM(c.spend) > 0 THEN ROUND(SUM(c.revenue)/SUM(c.spend), 2) ELSE 0 END AS roas,
        CASE WHEN SUM(c.purchases) > 0 THEN ROUND(SUM(c.spend)/SUM(c.purchases), 2) ELSE 0 END AS cpa,
        CASE WHEN SUM(c.leads) > 0 THEN ROUND(SUM(c.spend)/SUM(c.leads), 2) ELSE 0 END AS cpl
    FROM conversion_snapshots c
    LEFT JOIN ad_accounts a ON a.account_id = c.account_id
    {$where}
    GROUP BY c.account_id, a.account_name
    ORDER BY SUM(c.revenue) DESC, SUM(c.purchases) DESC, SUM(c.leads) DESC
", $params);

// Dados diários POR CONTA para gráfico multi-linha
$dailyByAccount = $db->fetchAll("
    SELECT
        c.account_id,
        a.account_name,
        c.snapshot_date,
        SUM(c.views)       AS views,
        SUM(c.add_to_cart) AS add_to_cart,
        SUM(c.checkout)    AS checkout,
        SUM(c.purchases)   AS purchases,
        SUM(c.leads)       AS leads,
        SUM(c.revenue)     AS revenue,
        SUM(c.spend)       AS spend,
        CASE WHEN SUM(c.spend) > 0 THEN ROUND(SUM(c.revenue)/SUM(c.spend), 2) ELSE 0 END AS roas
    FROM conversion_snapshots c
    LEFT JOIN ad_accounts a ON a.account_id = c.account_id
    {$where}
    GROUP BY c.account_id, a.account_name, c.snapshot_date
    ORDER BY c.account_id, c.snapshot_date ASC
", $params);

j([
    'totals'            => $totals,
    'daily'             => $daily,
    'daily_by_account'  => $dailyByAccount,
    'by_account'        => $byAccount,
    'period'            => ['start' => $dateStart, 'end' => $dateEnd],
]);