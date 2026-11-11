<?php
// refund_receipt.php — serve o comprovante guardado FORA do public_html, só pra quem tem acesso.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
Auth::requireLogin();
if (!in_array(Auth::getRole(), ['admin', 'financeiro', 'chefe'])) { http_response_code(403); exit('Sem permissão'); }

$db = Database::getInstance();
$r = $db->fetch("SELECT receipt_file, event_id FROM refunds WHERE id=?", [(int)($_GET['id'] ?? 0)]);
if (!$r || !$r['receipt_file']) { http_response_code(404); exit('Comprovante não encontrado'); }

// Não-admin só abre comprovante de evento liberado pra ele
if (!Auth::isAdmin() && $r['event_id']) {
    $ok = $db->fetch("SELECT 1 FROM event_user_access WHERE event_id=? AND user_id=?",
        [$r['event_id'], Auth::getUserId()]);
    if (!$ok) { http_response_code(403); exit('Sem permissão para este comprovante'); }
}

$file = basename($r['receipt_file']); // nunca aceita caminho vindo do banco
$path = dirname(__DIR__) . '/refund_receipts/' . $file;
if (!is_file($path)) { http_response_code(404); exit('Arquivo não encontrado no servidor'); }

$ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="comprovante.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
