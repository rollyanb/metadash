<?php
// api/push_user_prefs.php — salva contas de notificação por usuário
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

Auth::requireLogin();
header('Content-Type: application/json');

$role = Auth::getRole();
if (!in_array($role, ['admin','chefe'])) {
    echo json_encode(['error' => 'Sem permissao']); exit;
}

$db   = Database::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?: [];

$action = $body['action'] ?? '';

// Salva contas de um usuário
if ($action === 'save') {
    $userId     = intval($body['user_id'] ?? 0);
    $accountIds = $body['account_ids'] ?? [];
    if (!$userId) { echo json_encode(['error' => 'user_id invalido']); exit; }

    $db->execute("DELETE FROM push_user_accounts WHERE user_id = ?", [$userId]);
    foreach ($accountIds as $aid) {
        $db->execute(
            "INSERT IGNORE INTO push_user_accounts (user_id, account_id) VALUES (?,?)",
            [$userId, trim($aid)]
        );
    }
    echo json_encode(['success' => true]);
    exit;
}

// Busca contas de um usuário
if ($action === 'get') {
    $userId = intval($body['user_id'] ?? 0);
    $rows   = $db->fetchAll("SELECT account_id FROM push_user_accounts WHERE user_id = ?", [$userId]);
    echo json_encode(['success' => true, 'account_ids' => array_column($rows, 'account_id')]);
    exit;
}

// Lista todos os usuários com suas contas configuradas
if ($action === 'list_all') {
    $users = $db->fetchAll("
        SELECT u.id, u.username, u.role,
               COUNT(pua.account_id) as notify_count,
               COUNT(ps.id) as has_push
        FROM users u
        LEFT JOIN push_user_accounts pua ON pua.user_id = u.id
        LEFT JOIN push_subscriptions ps ON ps.user_id = u.id
        GROUP BY u.id
        ORDER BY u.username
    ");
    echo json_encode(['success' => true, 'users' => $users]);
    exit;
}

// Lista todas as contas ativas
if ($action === 'get_accounts') {
    $accounts = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE status IN ('ACTIVE','INACTIVE') ORDER BY account_name");
    echo json_encode(['success' => true, 'accounts' => $accounts]);
    exit;
}

echo json_encode(['error' => 'Acao invalida']);