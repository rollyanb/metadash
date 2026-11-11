<?php
// api/sync_account.php - Processa UMA conta por vez via AJAX

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/meta_api.php';

if (!Auth::isLoggedIn()) {
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$db  = Database::getInstance();
$api = new MetaAPI();

$accountId = $_POST['account_id'] ?? null;
if ($accountId) Auth::assertAccountAccess($accountId);
$since     = intval($_POST['since'] ?? strtotime('-90 days'));
$until     = intval($_POST['until'] ?? time());

if (!$accountId) {
    echo json_encode(['error' => 'account_id obrigatório']);
    exit;
}

$account = $db->fetch("SELECT * FROM ad_accounts WHERE account_id = ?", [$accountId]);
if (!$account) {
    echo json_encode(['error' => 'Conta não encontrada']);
    exit;
}

// Tenta activities (cartão automático)
$response   = $api->getBillingActivities($accountId, $since, $until);
$activities = $response['data'] ?? [];
$saved      = 0;
$apiError   = null;

if (isset($response['error'])) {
    $apiError = $response['error']['message'] ?? 'Erro desconhecido';
} else {
    foreach ($activities as $activity) {
        if (($activity['event_type'] ?? '') !== 'ad_account_billing_charge') continue;

        $extraData = json_decode($activity['extra_data'] ?? '{}', true);
        $amount    = floatval($extraData['new_value'] ?? 0) / 100;
        $currency  = $extraData['currency'] ?? 'BRL';
        $txnId     = $extraData['transaction_id'] ?? null;
        $date      = $activity['event_time'] ?? null;

        if ($date) $date = date('Y-m-d H:i:s', strtotime($date));
        if ($amount <= 0) continue;

        $db->execute(
            "INSERT INTO transactions (account_id, transaction_id, amount, currency, transaction_date, status)
             VALUES (?, ?, ?, ?, ?, 'SETTLED')
             ON DUPLICATE KEY UPDATE amount = VALUES(amount)",
            [$accountId, $txnId, $amount, $currency, $date]
        );
        $saved++;
    }
}

// Sync gasto diário desta conta (90 dias)
$dateStart = date('Y-m-d', $since);
$dateEnd   = date('Y-m-d', $until);
$spendResp = $api->getDailySpend($accountId, $dateStart, $dateEnd);
$spendDays = $spendResp['data'] ?? [];
$spendSaved = 0;

foreach ($spendDays as $day) {
    $spend = floatval($day['spend'] ?? 0);
    $date  = $day['date_start'] ?? null;
    if (!$date) continue;

    $db->execute(
        "INSERT INTO spend_snapshots (account_id, snapshot_date, spend, impressions, clicks)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE spend = VALUES(spend), impressions = VALUES(impressions), clicks = VALUES(clicks)",
        [
            $accountId,
            $date,
            $spend,
            intval($day['impressions'] ?? 0),
            intval($day['clicks'] ?? 0)
        ]
    );
    $spendSaved++;
}

$db->execute("UPDATE ad_accounts SET last_sync = NOW() WHERE account_id = ?", [$accountId]);

echo json_encode([
    'success'     => true,
    'account_id'  => $accountId,
    'account_name'=> $account['account_name'],
    'activities'  => count($activities),
    'txn_saved'   => $saved,
    'spend_days'  => $spendSaved,
    'api_error'   => $apiError,
]);