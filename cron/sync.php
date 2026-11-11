<?php
// cron/sync.php
// Executa sincronização de dados da Meta — apenas via CLI (cron job)

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso negado');
}

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/logger.php';
require_once ROOT_PATH . '/includes/meta_api.php';
require_once ROOT_PATH . '/includes/sync_service.php';

// SyncService cria suas próprias instâncias de Database e MetaAPI internamente
// NÃO passar $db ou $api — o construtor não aceita parâmetros
$sync = new SyncService();

try {
    $result = $sync->run();
    app_log('info', 'Sync concluído', $result);
    echo "Sync OK\n";
} catch (Throwable $e) {
    app_log('error', 'Sync falhou: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    echo "Sync ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
