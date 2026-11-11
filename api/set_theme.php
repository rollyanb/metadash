<?php
// api/set_theme.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

Auth::requireLogin();

$input = json_decode(file_get_contents('php://input'), true);
$theme = isset($input['theme']) ? trim($input['theme']) : '';

if (!in_array($theme, ['dark', 'light'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Tema inválido']);
    exit;
}

$db     = Database::getInstance();
$userId = Auth::getUserId();

$db->query("UPDATE users SET theme = ? WHERE id = ?", [$theme, $userId]);

$_SESSION['theme'] = $theme;

echo json_encode(['ok' => true, 'theme' => $theme]);
