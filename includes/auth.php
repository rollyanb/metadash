<?php
// includes/auth.php
// Roles: admin | chefe | financeiro | marketing | user

class Auth {

    const REMEMBER_COOKIE  = 'md_remember';
    const REMEMBER_DAYS    = 90;

    // ── ACESSO ────────────────────────────────────────────────────

    public static function requireLogin() {
        self::startSession();
        if (empty($_SESSION['user_id'])) {
            // Tenta restaurar sessão via cookie "manter-me logado"
            if (!self::tryRememberToken()) {
                $base = self::baseUrl();
                header('Location: ' . $base . '/login.php');
                exit;
            }
        }
        self::logPageView();
    }

    public static function requireAdmin() {
        self::requireLogin();
        if (!self::isAdmin()) {
            header('Location: ' . self::baseUrl() . '/index.php');
            exit;
        }
    }

    public static function isLoggedIn() {
        self::startSession();
        if (!empty($_SESSION['user_id'])) return true;
        return self::tryRememberToken();
    }

    // ── ROLES ─────────────────────────────────────────────────────

    public static function isAdmin() {
        return self::getRole() === 'admin';
    }

    public static function isChefe() {
        return self::getRole() === 'chefe';
    }

    public static function isFinanceiro() {
        return self::getRole() === 'financeiro';
    }

    public static function isMarketing() {
        return self::getRole() === 'marketing';
    }

    public static function getRole() {
        self::startSession();
        return $_SESSION['role'] ?? 'user';
    }

    public static function canAccess($resource) {
        $role = self::getRole();
        $map  = array(
            'dashboard'    => array('admin','chefe','financeiro','marketing','user'),
            'accounts'     => array('admin','chefe','financeiro','marketing','user'),
            'transactions' => array('admin','chefe','financeiro'),
            'marketing'    => array('admin','chefe','marketing'),
            'settings'     => array('admin'),
            'import'       => array('admin'),
            'users'        => array('admin'),
        );
        return in_array($role, isset($map[$resource]) ? $map[$resource] : array());
    }

    public static function getUserId() {
        self::startSession();
        return intval($_SESSION['user_id'] ?? 0);
    }

    public static function getAllowedAccounts() {
        $role = self::getRole();
        if ($role === 'admin') return null;

        self::startSession();

        if (!array_key_exists('allowed_accounts', $_SESSION) || $_SESSION['allowed_accounts'] === null) {
            if (!empty($_SESSION['user_id'])) {
                if (!class_exists('Database')) {
                    require_once dirname(dirname(__FILE__)) . '/config/database.php';
                }
                $db   = Database::getInstance();
                $rows = $db->fetchAll(
                    "SELECT account_id FROM user_account_permissions WHERE user_id = ?",
                    array($_SESSION['user_id'])
                );
                $_SESSION['allowed_accounts'] = array_column($rows, 'account_id');
            } else {
                $_SESSION['allowed_accounts'] = array();
            }
        }

        return $_SESSION['allowed_accounts'];
    }

    /**
     * Garante que o usuário logado pode acessar a conta informada.
     * Admin acessa tudo. Demais roles: precisa estar em user_account_permissions.
     * Em falha, responde 403 (JSON se $json) e encerra.
     */
    public static function assertAccountAccess($accountId, $json = true) {
        $allowed = self::getAllowedAccounts();
        if ($allowed === null) return true; // admin
        if ($accountId !== '' && $accountId !== null && in_array($accountId, $allowed)) return true;
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('success' => false, 'error' => 'Sem permissão para esta conta'));
        } else {
            echo 'Sem permissão para esta conta.';
        }
        exit;
    }

    // ── CSRF ──────────────────────────────────────────────────────

    // Endpoints que não podem carregar o token (Service Worker, callbacks externos)
    private static $csrfExempt = array('api/push_subscribe.php');
    private static $csrfChecked = false;

    public static function csrfToken() {
        self::startSession();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function csrfField() {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Valida o token em toda requisição que altera estado (POST/PUT/DELETE/PATCH).
     * Aceita via campo _csrf (forms) ou header X-CSRF-Token (fetch).
     * Chamado automaticamente na primeira interação com a sessão.
     */
    private static function enforceCsrf() {
        if (self::$csrfChecked) return;
        self::$csrfChecked = true;
        if (PHP_SAPI === 'cli') return;
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        if (!in_array($method, array('POST', 'PUT', 'DELETE', 'PATCH'))) return;

        $script = str_replace('\\', '/', isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
        foreach (self::$csrfExempt as $ex) {
            if (substr($script, -strlen($ex)) === $ex) return;
        }

        $sent = '';
        if (!empty($_POST['_csrf'])) {
            $sent = $_POST['_csrf'];
        } elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $sent = $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        $expected = isset($_SESSION['_csrf']) ? $_SESSION['_csrf'] : '';

        if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            http_response_code(403);
            $wantsJson = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                      || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
                      || strpos($script, '/api/') !== false;
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array('success' => false, 'error' => 'Sessão expirada ou token inválido. Recarregue a página e tente novamente.', 'csrf' => true));
            } else {
                echo 'Sessão expirada ou token inválido. Volte e recarregue a página antes de enviar novamente.';
            }
            exit;
        }
    }

    // ── RATE LIMIT DE LOGIN ───────────────────────────────────────

    const LOGIN_MAX_TRIES = 5;
    const LOGIN_LOCK_MIN  = 15;

    private static function clientIp() {
        return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '0.0.0.0';
    }

    private static function ensureLoginAttemptsTable($db) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $db->execute("CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                username VARCHAR(50) NOT NULL,
                tries INT NOT NULL DEFAULT 0,
                locked_until DATETIME NULL,
                last_try TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ip_user (ip, username),
                KEY idx_locked (locked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Exception $e) {}
    }

    /** Retorna minutos restantes de bloqueio (0 = liberado) */
    public static function loginLockedMinutes($username, $db = null) {
        if ($db === null) {
            if (!class_exists('Database')) require_once dirname(dirname(__FILE__)) . '/config/database.php';
            $db = Database::getInstance();
        }
        self::ensureLoginAttemptsTable($db);
        // Calculado no MySQL para não depender do timezone do PHP vs. do banco
        $row = $db->fetch("SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS secs FROM login_attempts
                           WHERE ip = ? AND username = ? AND locked_until > NOW()",
            array(self::clientIp(), $username));
        if (!$row) return 0;
        return max(1, (int)ceil($row['secs'] / 60));
    }

    private static function registerFailedLogin($username, $db) {
        self::ensureLoginAttemptsTable($db);
        $db->execute("INSERT INTO login_attempts (ip, username, tries) VALUES (?, ?, 1)
                      ON DUPLICATE KEY UPDATE
                        tries = IF(last_try < NOW() - INTERVAL ? MINUTE, 1, tries + 1),
                        locked_until = IF(tries >= ?, NOW() + INTERVAL ? MINUTE, locked_until)",
            array(self::clientIp(), $username, self::LOGIN_LOCK_MIN, self::LOGIN_MAX_TRIES, self::LOGIN_LOCK_MIN));
        // Limpa registros antigos ocasionalmente
        if (mt_rand(1, 20) === 1) {
            try { $db->execute("DELETE FROM login_attempts WHERE last_try < NOW() - INTERVAL 1 DAY"); } catch (Exception $e) {}
        }
    }

    private static function clearFailedLogins($username, $db) {
        try { $db->execute("DELETE FROM login_attempts WHERE ip = ? AND username = ?", array(self::clientIp(), $username)); } catch (Exception $e) {}
    }

    // ── LOGIN ─────────────────────────────────────────────────────

    public static function login($username, $password, $remember = false, $db = null) {
        if ($db === null) {
            if (!class_exists('Database')) {
                require_once dirname(dirname(__FILE__)) . '/config/database.php';
            }
            $db = Database::getInstance();
        }

        if (self::loginLockedMinutes($username, $db) > 0) {
            self::logAccess('bloqueado', $username, null, $db);
            return false;
        }

        $user = $db->fetch("SELECT * FROM users WHERE username = ?", array($username));
        if (!$user || !password_verify($password, $user['password'])) {
            self::registerFailedLogin($username, $db);
            self::logAccess('login_falha', $username, null, $db);
            // Custo constante para não vazar existência do usuário
            usleep(250000);
            return false;
        }
        self::clearFailedLogins($username, $db);
        self::logAccess('login', $user['username'], $user['id'], $db);

        self::startSession();
        session_regenerate_id(true);
        unset($_SESSION['_csrf']); // token novo por login
        self::populateSession($user, $db);

        // Gera token de "manter logado" se solicitado
        if ($remember) {
            self::createRememberToken($user['id'], $db);
        }

        return true;
    }

    // ── LOGOUT ────────────────────────────────────────────────────

    public static function logout() {
        self::startSession();
        if (!empty($_SESSION['user_id'])) {
            self::logAccess('logout', $_SESSION['username'] ?? '', $_SESSION['user_id']);
        }

        // Remove o token do banco se existir
        if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
            self::deleteRememberToken($_COOKIE[self::REMEMBER_COOKIE]);
            // Apaga o cookie
            setcookie(
                self::REMEMBER_COOKIE, '', time() - 86400,
                '/', '', !empty($_SERVER['HTTPS']), true
            );
        }

        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // ── REMEMBER TOKEN ────────────────────────────────────────────

    /**
     * Tenta restaurar sessão a partir do cookie de remember_token.
     * Retorna true se conseguiu restaurar, false caso contrário.
     */
    private static function tryRememberToken() {
        if (empty($_COOKIE[self::REMEMBER_COOKIE])) return false;

        $token = $_COOKIE[self::REMEMBER_COOKIE];

        if (!class_exists('Database')) {
            $base = dirname(dirname(__FILE__));
            if (!file_exists($base . '/config/database.php')) return false;
            require_once $base . '/config/database.php';
        }

        try {
            $db  = Database::getInstance();
            $row = $db->fetch(
                "SELECT rt.user_id, rt.token, rt.expires_at, u.username, u.role, u.theme
                 FROM remember_tokens rt
                 JOIN users u ON u.id = rt.user_id
                 WHERE rt.token = ? AND rt.expires_at > NOW()",
                array($token)
            );
        } catch (Exception $e) {
            return false;
        }

        if (!$row) {
            // Token inválido ou expirado — limpa o cookie
            setcookie(self::REMEMBER_COOKIE, '', time() - 86400, '/', '', !empty($_SERVER['HTTPS']), true);
            return false;
        }

        // Restaura sessão
        self::startSession();
        $db2 = Database::getInstance();
        self::logAccess('login_auto', $row['username'], $row['user_id'], $db2);
        self::populateSession(array(
            'id'       => $row['user_id'],
            'username' => $row['username'],
            'role'     => $row['role'],
            'theme'    => $row['theme'],
        ), $db2);

        // Renova o token (rotating token — segurança)
        self::deleteRememberToken($token);
        self::createRememberToken($row['user_id'], $db2);

        return true;
    }

    private static function createRememberToken($userId, $db) {
        $token     = bin2hex(random_bytes(32)); // 64 chars hex
        $expiresAt = date('Y-m-d H:i:s', time() + self::REMEMBER_DAYS * 86400);

        // Limpa tokens expirados do usuário antes de inserir
        $db->execute(
            "DELETE FROM remember_tokens WHERE user_id = ? AND expires_at <= NOW()",
            array($userId)
        );

        $db->execute(
            "INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
            array($userId, $token, $expiresAt)
        );

        setcookie(
            self::REMEMBER_COOKIE,
            $token,
            time() + self::REMEMBER_DAYS * 86400,
            '/',
            '',
            !empty($_SERVER['HTTPS']),
            true  // HttpOnly — JS não acessa
        );
    }

    private static function deleteRememberToken($token) {
        if (!class_exists('Database')) return;
        try {
            $db = Database::getInstance();
            $db->execute("DELETE FROM remember_tokens WHERE token = ?", array($token));
        } catch (Exception $e) {}
    }

    // ── LOG DE ACESSOS ────────────────────────────────────────────

    private static function ensureAccessLogTable($db) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $db->execute("CREATE TABLE IF NOT EXISTS access_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                username VARCHAR(50) NOT NULL DEFAULT '',
                event VARCHAR(20) NOT NULL,
                page VARCHAR(120) NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_created (created_at),
                KEY idx_user (user_id),
                KEY idx_event (event),
                KEY idx_page (page)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Exception $e) {}
    }

    /**
     * Registra a página visitada pelo usuário logado.
     * Deduplica: mesma página do mesmo usuário só grava de novo após 5 min.
     */
    public static function logPageView() {
        if (PHP_SAPI === 'cli' || empty($_SESSION['user_id'])) return;
        $script = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
        if ($script === '' || strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false) return;
        if (in_array($script, array('logout.php', 'sw.js', 'manifest.json'))) return;
        $uid = (int)$_SESSION['user_id'];
        $key = 'pv_' . $script;
        if (isset($_SESSION[$key]) && (time() - $_SESSION[$key]) < 300) return; // já registrado há pouco
        $_SESSION[$key] = time();
        try {
            if (!class_exists('Database')) require_once dirname(dirname(__FILE__)) . '/config/database.php';
            $db = Database::getInstance();
            self::ensureAccessLogTable($db);
            $page = $script;
            // contexto útil: id do evento / conta quando houver
            if (!empty($_GET['id']))         $page .= '?id=' . substr(preg_replace('/[^0-9a-zA-Z_-]/', '', $_GET['id']), 0, 20);
            elseif (!empty($_GET['account'])) $page .= '?conta=' . substr(preg_replace('/[^0-9a-zA-Z_]/', '', $_GET['account']), 0, 24);
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
            $db->execute("INSERT INTO access_log (user_id, username, event, page, ip, user_agent) VALUES (?,?,?,?,?,?)",
                array($uid, substr((string)($_SESSION['username'] ?? ''), 0, 50), 'pagina', substr($page, 0, 120), self::clientIp(), $ua));
        } catch (Exception $e) {}
    }

    /**
     * Registra um acesso. $event: login | login_falha | login_auto | logout | bloqueado
     * Nunca derruba a aplicação se o log falhar.
     */
    public static function logAccess($event, $username = '', $userId = null, $db = null) {
        try {
            if ($db === null) {
                if (!class_exists('Database')) require_once dirname(dirname(__FILE__)) . '/config/database.php';
                $db = Database::getInstance();
            }
            self::ensureAccessLogTable($db);
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
            $db->execute("INSERT INTO access_log (user_id, username, event, ip, user_agent) VALUES (?,?,?,?,?)",
                array($userId, substr((string)$username, 0, 50), $event, self::clientIp(), $ua));
            if (mt_rand(1, 50) === 1) {
                $db->execute("DELETE FROM access_log WHERE created_at < NOW() - INTERVAL 180 DAY");
            }
        } catch (Exception $e) {}
    }

    // ── HELPERS ───────────────────────────────────────────────────

    private static function populateSession($user, $db) {
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = isset($user['role']) ? $user['role'] : 'user';
        $_SESSION['theme']    = isset($user['theme']) && in_array($user['theme'], array('dark','light'))
                                ? $user['theme'] : 'dark';

        $rows = $db->fetchAll(
            "SELECT account_id FROM user_account_permissions WHERE user_id = ?",
            array($user['id'])
        );
        $_SESSION['allowed_accounts'] = array_column($rows, 'account_id');
    }

    private static function baseUrl() {
        $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $dir    = dirname($script);
        if (basename($dir) === 'admin' || basename($dir) === 'api') {
            $dir = dirname($dir);
        }
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        return $protocol . '://' . $host . rtrim($dir, '/');
    }

    public static function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                   || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            session_set_cookie_params(array(
                'lifetime' => 30 * 24 * 3600,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
            ini_set('session.use_strict_mode', 1);
            session_start();
        }
        self::enforceCsrf();
    }
}
