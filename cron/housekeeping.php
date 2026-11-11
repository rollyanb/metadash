<?php
// cron/housekeeping.php — rotação de logs e limpeza (rodar 1x/dia)
// Sugestão hPanel: 30 3 * * * php /home/u567594569/domains/metadash.space/public_html/cron/housekeeping.php
ini_set('display_errors', 0);
date_default_timezone_set('America/Sao_Paulo');
require_once dirname(__DIR__) . '/config/database.php';
$db = Database::getInstance();

$jobs = [
    "DELETE FROM sync_log        WHERE created_at < NOW() - INTERVAL 30 DAY",
    "DELETE FROM cron_log        WHERE ran_at     < NOW() - INTERVAL 30 DAY",
    "DELETE FROM app_errors      WHERE created_at < NOW() - INTERVAL 60 DAY",
    "DELETE FROM login_attempts  WHERE last_try   < NOW() - INTERVAL 1 DAY",
    "DELETE FROM remember_tokens WHERE expires_at < NOW()",
    "DELETE FROM notifications   WHERE created_at < NOW() - INTERVAL 90 DAY",
    "DELETE FROM access_log      WHERE created_at < NOW() - INTERVAL 180 DAY",
];
foreach ($jobs as $sql) {
    try { $n = $db->execute($sql); echo str_pad(preg_replace('/\s+/', ' ', substr($sql, 12, 22)), 26) . " -> $n linhas\n"; }
    catch (Exception $e) { echo "skip: " . $e->getMessage() . "\n"; }
}

// Alerta: mesmo cron falhando 3x seguidas nas últimas 3 horas
try {
    $bad = $db->fetchAll("SELECT sync_type, COUNT(*) c FROM sync_log
                          WHERE status='error' AND created_at > NOW() - INTERVAL 3 HOUR
                          GROUP BY sync_type HAVING c >= 3");
    foreach ($bad as $b) app_log('error', "Cron '{$b['sync_type']}' falhou {$b['c']}x nas últimas 3h — ver settings.php");
} catch (Exception $e) {}
echo "ok\n";
