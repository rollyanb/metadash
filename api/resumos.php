<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$role = Auth::getRole();
// financeiro tem acesso a resumos

$db              = Database::getInstance();
$allowedAccounts = Auth::getAllowedAccounts();
$body            = json_decode(file_get_contents('php://input'), true) ?: [];

$accountId = $body['account_id'] ?? '';
$dateStart = $body['date_start'] ?? date('Y-m-d');
$dateEnd   = $body['date_end']   ?? date('Y-m-d');

if (!$accountId) { echo json_encode(['error'=>'Conta obrigatoria']); exit; }

// Verifica acesso
if ($allowedAccounts !== null && !in_array($accountId, $allowedAccounts)) {
    echo json_encode(['error'=>'Sem acesso a esta conta']); exit;
}

$data = $db->fetch("
    SELECT
        SUM(purchases) as purchases,
        SUM(leads)     as leads,
        SUM(revenue)   as revenue,
        SUM(spend)     as spend
    FROM conversion_snapshots
    WHERE account_id = ?
      AND snapshot_date BETWEEN ? AND ?
", [$accountId, $dateStart, $dateEnd]);

echo json_encode(['success'=>true, 'data'=>$data], JSON_UNESCAPED_UNICODE);