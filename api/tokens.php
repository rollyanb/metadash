<?php
// api/tokens.php — gerencia tokens de relatório público
header('Content-Type: application/json; charset=utf-8');

function j($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

require_once __DIR__ . '/../includes/auth.php';
if (!Auth::isAdmin()) j(['error' => 'Acesso negado']);

require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance();

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';

if ($action === 'list') {
    $tokens = $db->fetchAll("
        SELECT t.*, a.account_name
        FROM report_tokens t
        LEFT JOIN ad_accounts a ON a.account_id = t.account_id
        ORDER BY t.created_at DESC
    ");
    j(['tokens' => $tokens]);
}

if ($action === 'create') {
    $accountId = $input['account_id'] ?? '';
    $label     = trim($input['label'] ?? '');
    if (!$accountId) j(['error' => 'account_id obrigatório']);

    // Gera token único
    $token = bin2hex(random_bytes(24));

    $db->insert(
        "INSERT INTO report_tokens (account_id, token, label) VALUES (?, ?, ?)",
        [$accountId, $token, $label]
    );
    j(['token' => $token, 'url' => '/report.php?token='.$token]);
}

if ($action === 'toggle') {
    $id = intval($input['id'] ?? 0);
    $db->execute("UPDATE report_tokens SET active = NOT active WHERE id = ?", [$id]);
    j(['ok' => true]);
}

if ($action === 'delete') {
    $id = intval($input['id'] ?? 0);
    $db->execute("DELETE FROM report_tokens WHERE id = ?", [$id]);
    j(['ok' => true]);
}

j(['error' => 'Ação inválida']);