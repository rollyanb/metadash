<?php
// Endpoint AJAX de sincronização de contas e gastos por conta.
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sync_service.php';

function syncJson($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    syncJson(['success' => false, 'message' => 'Método não permitido'], 405);
}

if (!Auth::isLoggedIn() || !Auth::isAdmin()) {
    syncJson(['success' => false, 'message' => 'Apenas administradores podem sincronizar'], 403);
}

// A tela envia application/json; $_POST fica vazio nesse caso.
$raw = file_get_contents('php://input');
$json = json_decode($raw ?: '', true);
$input = is_array($json) ? $json : $_POST;
$type = strtolower(trim((string)($input['type'] ?? 'accounts')));

if (!in_array($type, ['accounts', 'spend', 'all'], true)) {
    syncJson(['success' => false, 'message' => 'Tipo de sincronização inválido'], 400);
}

try {
    $sync = new SyncService();

    if ($type === 'accounts') {
        // Não mistura a atualização de contas com a consulta demorada de insights.
        syncJson($sync->syncAccounts());
    }

    if ($type === 'spend') {
        syncJson($sync->syncSpend(32));
    }

    $accounts = $sync->syncAccounts();
    if (empty($accounts['success'])) {
        syncJson($accounts, 502);
    }

    $spend = $sync->syncSpend(32);
    syncJson([
        'success' => !empty($spend['success']),
        'message' => $accounts['message'] . ' | ' . $spend['message'],
        'accounts' => $accounts,
        'spend' => $spend,
    ], !empty($spend['success']) ? 200 : 502);
} catch (Throwable $e) {
    if (function_exists('app_log')) {
        app_log('error', 'Falha na sincronização manual: ' . $e->getMessage());
    }
    syncJson(['success' => false, 'message' => 'Falha interna durante a sincronização. Consulte o log do sistema.'], 500);
}
