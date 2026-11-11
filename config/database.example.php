<?php
// config/database.php — COPIE este arquivo, remova o .example e preencha com suas credenciais reais
// NÃO comite o database.php real — ele está no .gitignore

define('DB_HOST',     '127.0.0.1');
define('DB_PORT',     3306);
define('DB_NAME',     'nome_do_banco');
define('DB_USER',     'usuario_do_banco');
define('DB_PASSWORD', 'senha_do_banco');
define('DB_CHARSET',  'utf8mb4');

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $this->pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public static function getInstance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public function fetch(string $sql, array $params = []): ?array {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function execute(string $sql, array $params = []): void {
        $this->pdo->prepare($sql)->execute($params);
    }

    public function insert(string $sql, array $params = []): int {
        $this->pdo->prepare($sql)->execute($params);
        return (int)$this->pdo->lastInsertId();
    }
}