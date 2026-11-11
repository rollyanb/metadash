<?php
// tools/migrate_encrypt_token.php — cifra o token Meta já existente no banco (rodar 1x após deploy)
// Admin only. Idempotente: se já estiver cifrado, não faz nada.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
Auth::requireAdmin();
header('Content-Type: text/plain; charset=utf-8');

if (!Crypto::enabled()) { echo "ERRO: chave de cifragem não encontrada (config/.enc_key). Nada feito.\n"; exit; }

$db  = Database::getInstance();
$row = $db->fetch("SELECT id, access_token FROM meta_token ORDER BY id DESC LIMIT 1");
if (!$row) { echo "Nenhum token no banco.\n"; exit; }
if (Crypto::isEncrypted($row['access_token'])) { echo "Token já está cifrado. OK.\n"; exit; }

$enc = Crypto::encrypt($row['access_token']);
if (Crypto::decrypt($enc) !== $row['access_token']) { echo "ERRO: round-trip de cifragem falhou. Nada gravado.\n"; exit; }
$db->execute("UPDATE meta_token SET access_token = ? WHERE id = ?", [$enc, $row['id']]);
echo "Token cifrado com sucesso (id {$row['id']}).\n";
