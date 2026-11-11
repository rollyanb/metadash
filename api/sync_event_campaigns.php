<?php
// Cria uma execução em fila ou consulta seu progresso; não espera a Meta no HTTP.
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/event_campaign_sync.php';

function eventSyncJson($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

Auth::requireLogin();
if (!in_array(Auth::getRole(), ['admin', 'chefe'], true)) {
    eventSyncJson(['success' => false, 'message' => 'Somente administradores podem sincronizar campanhas.'], 403);
}

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $runId = (int)($_GET['run_id'] ?? 0);
    if ($runId < 1) eventSyncJson(['success' => false, 'message' => 'Execução inválida.'], 400);
    $run = $db->fetch('SELECT * FROM event_sync_runs WHERE id=?', [$runId]);
    if (!$run) eventSyncJson(['success' => false, 'message' => 'Execução não encontrada.'], 404);
    $details = json_decode((string)$run['details'], true);
    eventSyncJson([
        'success' => true,
        'run_id' => (int)$run['id'],
        'event_id' => (int)$run['event_id'],
        'status' => $run['status'],
        'progress_done' => (int)$run['progress_done'],
        'progress_total' => (int)$run['progress_total'],
        'current_campaign' => $run['current_campaign'],
        'message' => $run['message'],
        'details' => is_array($details) ? $details : [],
        'date_start' => $run['date_start'],
        'date_end' => $run['date_end'],
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    eventSyncJson(['success' => false, 'message' => 'Método não permitido.'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
$eventId = (int)($input['event_id'] ?? $_POST['event_id'] ?? 0);
if ($eventId < 1) eventSyncJson(['success' => false, 'message' => 'Evento inválido.'], 400);

$pdo = $db->getConnection();
try {
    $pdo->beginTransaction();
    $event = $db->fetch('SELECT * FROM events WHERE id=? FOR UPDATE', [$eventId]);
    if (!$event) {
        $pdo->rollBack();
        eventSyncJson(['success' => false, 'message' => 'Evento não encontrado.'], 404);
    }
    $active = $db->fetch(
        "SELECT id,status,message,progress_done,progress_total FROM event_sync_runs WHERE event_id=? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1",
        [$eventId]
    );
    if ($active) {
        $pdo->commit();
        eventSyncJson([
            'success' => true,
            'run_id' => (int)$active['id'],
            'status' => $active['status'],
            'message' => $active['message'] ?: 'Já existe uma sincronização deste evento em andamento.',
            'progress_done' => (int)$active['progress_done'],
            'progress_total' => (int)$active['progress_total'],
        ], 202);
    }

    list($dateStart, $dateEnd) = eventCampaignDateRange($event);
    $runId = $db->insert(
        "INSERT INTO event_sync_runs (event_id,requested_by,status,date_start,date_end,message) VALUES (?,?, 'queued',?,?,?)",
        [$eventId, Auth::getUserId(), $dateStart, $dateEnd, 'Aguardando o worker iniciar.']
    );
    $pdo->commit();
    eventSyncJson([
        'success' => true,
        'run_id' => (int)$runId,
        'status' => 'queued',
        'message' => 'Sincronização colocada na fila.',
        'progress_done' => 0,
        'progress_total' => 0,
        'date_start' => $dateStart,
        'date_end' => $dateEnd,
    ], 202);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    eventSyncJson(['success' => false, 'message' => $e->getMessage()], 422);
}
