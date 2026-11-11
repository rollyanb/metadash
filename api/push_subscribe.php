<?php
// api/push_subscribe.php
// NÃO usa Auth::requireLogin() com redirect — retorna JSON de erro
// para não quebrar quando sessão expirar no iOS background
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Sessão pode ter expirado no iOS — não redireciona, retorna erro JSON
if (!Auth::isLoggedIn()) {
    echo json_encode(['error' => 'not_logged_in', 'redirect' => true]);
    exit;
}

$db   = Database::getInstance();
$uid  = Auth::getUserId();
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action === 'subscribe') {
    $endpoint = $body['endpoint'] ?? '';
    $p256dh   = $body['keys']['p256dh'] ?? '';
    $auth     = $body['keys']['auth']   ?? '';

    if (!$endpoint || !$p256dh || !$auth) {
        echo json_encode(['error' => 'Dados incompletos']); exit;
    }

    // Upsert — atualiza chaves se endpoint já existir (iOS pode trocar as chaves)
    $db->execute("
        INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, updated_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            user_id    = VALUES(user_id),
            p256dh     = VALUES(p256dh),
            auth       = VALUES(auth),
            updated_at = NOW()
    ", [$uid, $endpoint, $p256dh, $auth]);

    echo json_encode(['success' => true, 'user_id' => $uid]);
    exit;
}

if ($action === 'unsubscribe') {
    $endpoint = $body['endpoint'] ?? '';
    if ($endpoint) {
        $db->execute("DELETE FROM push_subscriptions WHERE endpoint=? AND user_id=?", [$endpoint, $uid]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// Verifica se subscription atual ainda está válida no banco
if ($action === 'check') {
    $endpoint = $body['endpoint'] ?? '';
    if (!$endpoint) { echo json_encode(['valid' => false]); exit; }
    $row = $db->fetch("SELECT id FROM push_subscriptions WHERE endpoint=? AND user_id=?", [$endpoint, $uid]);
    echo json_encode(['valid' => (bool)$row, 'user_id' => $uid]);
    exit;
}

echo json_encode(['error' => 'Acao invalida']);

// Migration segura — adiciona updated_at se não existir
// Pode rodar uma vez sem problema (IF NOT EXISTS via ALTER IGNORE)
// Descomente na primeira deploy e comente depois:
// try { $db->execute("ALTER TABLE push_subscriptions ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch(Exception $e) {}