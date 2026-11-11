<?php
// Agenda sincronizações automáticas dos eventos ativos. O worker processa a fila.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Acesso permitido somente via CLI.\n");
}

date_default_timezone_set('America/Sao_Paulo');
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/event_campaign_sync.php';

$db = Database::getInstance();
$pdo = $db->getConnection();
$events = $db->fetchAll(
    "SELECT e.* FROM events e WHERE e.status='active'
       AND (e.date_campaign_start IS NULL OR e.date_campaign_start <= CURDATE())
       AND (e.date_campaign_end IS NULL OR e.date_campaign_end >= CURDATE())
       AND EXISTS (
       SELECT 1 FROM event_campaigns ec WHERE ec.event_id=e.id
     ) ORDER BY e.date_event"
);
$queued = 0;
$skipped = 0;

foreach ($events as $event) {
    try {
        $pdo->beginTransaction();
        $lockedEvent = $db->fetch('SELECT * FROM events WHERE id=? FOR UPDATE', [$event['id']]);
        $active = $db->fetch(
            "SELECT id FROM event_sync_runs WHERE event_id=? AND status IN ('queued','running') LIMIT 1",
            [$event['id']]
        );
        if (!$lockedEvent || $active) {
            $pdo->commit();
            $skipped++;
            continue;
        }
        list($start, $end) = eventCampaignDateRange($lockedEvent);
        $db->insert(
            "INSERT INTO event_sync_runs (event_id,status,date_start,date_end,message) VALUES (?,'queued',?,?,?)",
            [$event['id'], $start, $end, 'Agendada pela sincronização automática.']
        );
        $pdo->commit();
        $queued++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "Evento #{$event['id']}: {$e->getMessage()}\n");
    }
}

// Limpa staging antigo que ficou após remoção manual de execuções.
$db->execute('DELETE FROM event_campaign_sync_stage WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
echo "Fila: {$queued} evento(s) incluído(s), {$skipped} já em execução.\n";
