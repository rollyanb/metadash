<?php
// includes/logger.php — log central de erros (error_log + tabela app_errors)
// Uso: app_log('error', 'mensagem', ['ctx'=>...]);  níveis: error | warning | info

function app_log($level, $message, $context = array()) {
    $level = in_array($level, array('error','warning','info')) ? $level : 'error';
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : (isset($GLOBALS['argv'][0]) ? $GLOBALS['argv'][0] : 'cli');
    $ctx = $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null;
    error_log("[metadash][$level] $message" . ($ctx ? " $ctx" : ''));
    try {
        if (!class_exists('Database')) require_once __DIR__ . '/../config/database.php';
        $db = Database::getInstance();
        static $ensured = false;
        if (!$ensured) {
            $ensured = true;
            $db->execute("CREATE TABLE IF NOT EXISTS app_errors (
                id INT AUTO_INCREMENT PRIMARY KEY,
                level VARCHAR(10) NOT NULL,
                message TEXT NOT NULL,
                context TEXT NULL,
                script VARCHAR(255) NULL,
                user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_created (created_at),
                KEY idx_level (level)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $uid = (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) ? (int)$_SESSION['user_id'] : null;
        $db->execute("INSERT INTO app_errors (level, message, context, script, user_id) VALUES (?,?,?,?,?)",
            array($level, mb_substr($message, 0, 5000), $ctx ? mb_substr($ctx, 0, 20000) : null, mb_substr($script, 0, 255), $uid));
    } catch (Exception $e) {
        // nunca deixar o logger derrubar a aplicação
    }
}

// Captura fatais e exceções não tratadas (uma vez por request)
if (!defined('MD_LOGGER_HOOKED')) {
    define('MD_LOGGER_HOOKED', true);
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR))) {
            app_log('error', 'FATAL: ' . $e['message'], array('file' => $e['file'], 'line' => $e['line']));
            // Erro fatal não passa pelo set_exception_handler: mostra pro admin aqui
            $ehAdmin = (session_status() === PHP_SESSION_ACTIVE) && (($_SESSION['role'] ?? '') === 'admin');
            if ($ehAdmin && PHP_SAPI !== 'cli' && !headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array('success'=>false,
                    'error'=>'FATAL: ' . $e['message'] . ' — ' . basename($e['file']) . ':' . $e['line'],
                    'debug'=>true), JSON_UNESCAPED_UNICODE);
            }
        }
    });
    set_exception_handler(function ($ex) {
        app_log('error', 'Uncaught ' . get_class($ex) . ': ' . $ex->getMessage(), array('file' => $ex->getFile(), 'line' => $ex->getLine()));
        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
            // Admin vê a causa real; os demais veem só a mensagem genérica.
            $ehAdmin = false;
            try {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $ehAdmin = in_array(($_SESSION['role'] ?? ''), array('admin'));
                }
            } catch (Exception $e) {}
            $detalhe = $ehAdmin
                ? get_class($ex) . ': ' . $ex->getMessage() . ' — ' . basename($ex->getFile()) . ':' . $ex->getLine()
                : null;

            $isApi = strpos(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '', '/api/') !== false;
            if ($isApi) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array(
                    'success' => false,
                    'error'   => $detalhe ?: 'Erro interno. Já foi registrado.',
                    'debug'   => $detalhe !== null,
                ), JSON_UNESCAPED_UNICODE);
            } else {
                echo 'Erro interno. O problema foi registrado.' . ($detalhe ? '<pre style="color:#f87171">' . htmlspecialchars($detalhe) . '</pre>' : '');
            }
        } else {
            fwrite(STDERR, $ex->getMessage() . "\n");
        }
    });
}
