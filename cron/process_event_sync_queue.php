<?php
// cron/process_event_sync_queue.php
// Worker para fila de sincronização de gasto de campanhas de eventos
// Protegido por MySQL GET_LOCK — apenas uma instância por vez

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso negado');
}

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/logger.php';
require_once ROOT_PATH . '/includes/meta_api.php';

// CORREÇÃO: limite de tempo — antes era set_time_limit(0), que podia travar o lock indefinidamente
// 20 minutos é suficiente para qualquer lote razoável
define('MAX_RUNTIME', 1200);
set_time_limit(MAX_RUNTIME);
$startedAt = time();

$db  = Database::getInstance();
$api = new MetaAPI();

// Tenta obter o lock exclusivo (timeout = 0 → não bloqueia, desiste imediatamente)
$locked = $db->fetch("SELECT GET_LOCK('metadash_event_sync_worker', 0) AS got");
if (!$locked || !$locked['got']) {
    echo "Outro worker em execução — saindo.\n";
    exit(0);
}

// Libera o lock ao finalizar, independente do motivo (timeout, exceção, exit)
register_shutdown_function(function() use ($db) {
    try {
        $db->execute("SELECT RELEASE_LOCK('metadash_event_sync_worker')");
    } catch (Throwable $e) {
        // conexão pode já ter fechado
    }
});

// Recupera runs abandonados (atualizados há mais de 30 min e ainda em andamento)
$db->execute(
    "UPDATE event_sync_queue SET status = 'pending', worker_pid = NULL
     WHERE status = 'running' AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
);

$pid = getmypid();

try {
    while (true) {
        // Verifica tempo máximo a cada iteração
        if ((time() - $startedAt) >= MAX_RUNTIME - 30) {
            app_log('info', 'process_event_sync_queue: limite de tempo atingido, encerrando graciosamente');
            break;
        }

        // Pega próximo item da fila com FOR UPDATE
        $db->execute("BEGIN");
        $item = $db->fetch(
            "SELECT id, event_id, ad_account_id, date_start, date_end
             FROM event_sync_queue
             WHERE status = 'pending'
             ORDER BY id ASC
             LIMIT 1
             FOR UPDATE SKIP LOCKED"
        );

        if (!$item) {
            $db->execute("ROLLBACK");
            break; // fila vazia
        }

        $db->execute(
            "UPDATE event_sync_queue SET status = 'running', worker_pid = ?, updated_at = NOW() WHERE id = ?",
            [$pid, $item['id']]
        );
        $db->execute("COMMIT");

        // Processa o item
        try {
            $result = syncEventSpend($api, $db, $item);
            $db->execute(
                "UPDATE event_sync_queue SET status = 'done', result = ?, updated_at = NOW() WHERE id = ?",
                [json_encode($result), $item['id']]
            );
            app_log('info', 'event_sync done', ['id' => $item['id'], 'event_id' => $item['event_id']]);
        } catch (Throwable $e) {
            $db->execute(
                "UPDATE event_sync_queue SET status = 'error', result = ?, updated_at = NOW() WHERE id = ?",
                [json_encode(['error' => $e->getMessage()]), $item['id']]
            );
            app_log('error', 'event_sync falhou: ' . $e->getMessage(), ['id' => $item['id']]);
        }

        // Pausa mínima para não saturar a API
        usleep(300000); // 300ms
    }
} catch (Throwable $e) {
    app_log('error', 'process_event_sync_queue: erro fatal: ' . $e->getMessage());
    exit(1);
}

echo "Worker finalizado.\n";
exit(0);

function syncEventSpend(MetaAPI $api, $db, $item) {
    $spend = $api->getAccountSpend(
        $item['ad_account_id'],
        $item['date_start'],
        $item['date_end']
    );

    if (isset($spend['error'])) {
        throw new RuntimeException('Meta API: ' . ($spend['error']['message'] ?? 'erro desconhecido'));
    }

    $totalSpend = 0;
    foreach ($spend['data'] ?? [] as $row) {
        $totalSpend += (float)($row['spend'] ?? 0);
    }

    $db->execute(
        "UPDATE events SET meta_spend = ?, spend_synced_at = NOW() WHERE id = ?",
        [$totalSpend, $item['event_id']]
    );

    return ['spend' => $totalSpend, 'rows' => count($spend['data'] ?? [])];
}
