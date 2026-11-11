<?php
// cron/refresh_token.php — renova o token de longa duração antes de expirar
// Sugestão hPanel: 0 4 * * * php /home/u567594569/domains/metadash.space/public_html/cron/refresh_token.php
ini_set('display_errors', 0);
date_default_timezone_set('America/Sao_Paulo');
$base = dirname(__DIR__);
require_once $base . '/config/database.php';
require_once $base . '/includes/meta_api.php';

$db  = Database::getInstance();
$api = new MetaAPI();
if (!$api->hasToken()) { echo "Sem token.\n"; exit; }

// Validade REAL, consultada na Meta. A data guardada no banco já esteve
// otimista (token de 2h salvo como 60 dias) e o token expirou sem aviso.
$daysLeft = 0;
$status = null;
try { $status = $api->validadeReal(); } catch (Exception $e) {}

if ($status && empty($status['valido'])) {
    $msg = 'Token INVÁLIDO na Meta' . (!empty($status['erro']) ? ': ' . $status['erro'] : '')
         . ' — reconecte em Configurações.';
    $db->execute("INSERT INTO sync_log (sync_type, status, message) VALUES ('token_refresh','error',?)", [$msg]);
    if (function_exists('app_log')) app_log('error', $msg);
    echo $msg . "\n";
    exit;
}

if ($status && (int)$status['expires_at'] > 0) {
    $daysLeft = ((int)$status['expires_at'] - time()) / 86400;
} else {
    $row = $db->fetch("SELECT expires_at FROM meta_token ORDER BY id DESC LIMIT 1");
    $daysLeft = $row && $row['expires_at'] ? (strtotime($row['expires_at']) - time()) / 86400 : 0;
    if ($status && (int)$status['expires_at'] === 0) { echo "Token sem expiração. Nada a fazer.\n"; exit; }
}

// Renova quando faltar 10 dias ou menos (token longo dura 60)
if ($daysLeft > 10) { echo "Token válido por mais " . round($daysLeft) . " dias. Nada a fazer.\n"; exit; }
if ($daysLeft < 2) {
    $db->execute("INSERT INTO sync_log (sync_type, status, message) VALUES ('token_refresh','error',?)",
        ['Token expira em menos de 2 dias — renovação automática pode não bastar. Gere um token novo.']);
}

if ($api->refreshToken()) {
    $db->execute("INSERT INTO sync_log (sync_type, status, message) VALUES ('token_refresh','ok','Token renovado automaticamente')");
    echo "Token renovado.\n";
} else {
    $db->execute("INSERT INTO sync_log (sync_type, status, message) VALUES ('token_refresh','error','Falha ao renovar token — reconecte em settings.php')");
    app_log('error', 'Falha ao renovar token Meta automaticamente');
    echo "Falha ao renovar.\n";
}
