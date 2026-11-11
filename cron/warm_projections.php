<?php
// cron/warm_projections.php — pré-calcula a projeção de gasto dos eventos ativos.
// Sem isso, cada card da lista de eventos consulta a Meta na hora e estoura o
// tempo do navegador. Sugestão: */20 * * * *
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acesso permitido somente via CLI.\n");
}

ini_set('display_errors', 0);
set_time_limit(0);
date_default_timezone_set('America/Sao_Paulo');

$base = dirname(__DIR__);
require_once $base . '/config/database.php';
require_once $base . '/includes/projection.php';

$db = Database::getInstance();
$inicio = time();

$eventos = $db->fetchAll("
    SELECT e.id, e.name
    FROM events e
    WHERE e.status = 'active' AND e.budget > 0
      AND EXISTS (SELECT 1 FROM event_campaigns ec WHERE ec.event_id = e.id)
    ORDER BY e.date_event ASC");

$ok = 0; $falhas = 0;
foreach ($eventos as $ev) {
    try {
        $r = calcularProjecao($db, $ev['id']);
        if (!empty($r['success'])) { $ok++; }
        else { $falhas++; echo "  falhou #{$ev['id']} {$ev['name']}: " . ($r['error'] ?? '?') . "\n"; }
    } catch (Exception $e) {
        $falhas++;
        echo "  erro #{$ev['id']}: " . $e->getMessage() . "\n";
    }
    usleep(300000); // respeita o rate limit da Meta
}

$dur = time() - $inicio;
echo "Projecoes: {$ok} ok, {$falhas} falhas em {$dur}s\n";
try {
    $db->execute("INSERT INTO sync_log (sync_type, status, message) VALUES ('projections', ?, ?)",
        [$falhas ? 'partial' : 'ok', "{$ok} ok, {$falhas} falhas em {$dur}s"]);
} catch (Exception $e) {}
