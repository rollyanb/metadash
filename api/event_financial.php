<?php
// api/event_financial.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tax_helper.php';
require_once __DIR__ . '/../config/database.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$db     = Database::getInstance();
$uid    = Auth::getUserId();
$role   = Auth::getRole();
$isAdmin= in_array($role, ['admin','chefe','financeiro']);

if (!$isAdmin) {
    echo json_encode(['error'=>'Sem permissão']); exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? $_GET['action'] ?? '';

// ── GET RESUMO FINANCEIRO DO EVENTO ───────────────────────────
if ($action === 'get_refund_summary') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId) { echo json_encode(['error'=>'Evento inválido']); exit; }
    try {
        $r = $db->fetch("
            SELECT
              COALESCE(SUM(i.value * (1 + IFNULL(t.taxes,0) / NULLIF(t.subtotal,0))), 0) AS bruto,
              COALESCE(SUM((SELECT SUM(a.amount) FROM refund_allocations a WHERE a.item_id = i.id)), 0) AS pago,
              COUNT(*) AS cobrancas
            FROM paid_transaction_items i
            JOIN paid_transactions t ON t.transaction_id = i.transaction_id AND t.status = 'Pago'
            WHERE i.event_id = ? AND i.refund_exempt = 0", [$eventId]);
        $bruto = round((float)$r['bruto'], 2);
        $pago  = round((float)$r['pago'], 2);
        echo json_encode(['success'=>true, 'bruto'=>$bruto, 'pago'=>$pago,
            'aberto'=>round($bruto - $pago, 2), 'cobrancas'=>(int)$r['cobrancas'],
            'pct'=>$bruto > 0 ? round($pago / $bruto * 100) : 0], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success'=>true, 'bruto'=>0, 'pago'=>0, 'aberto'=>0, 'cobrancas'=>0, 'pct'=>0]);
    }
    exit;
}

if ($action === 'get_financial_summary') {
    $eventId = intval($body['event_id'] ?? 0);
    if (!$eventId) { echo json_encode(['error'=>'event_id obrigatório']); exit; }

    $event = $db->fetch("SELECT * FROM events WHERE id=?", [$eventId]);
    if (!$event) { echo json_encode(['error'=>'Evento não encontrado']); exit; }

    // Período — igual à aba Performance: sem filtro de data (soma todo o histórico das campanhas)
    $dateStart = $event['date_campaign_start'] ?: null;
    $dateEnd   = $event['date_campaign_end']   ?: null;

    // Gasto Meta — usa campaign_snapshots SEM filtro de data pra bater com a aba Performance
    $metaSpend = 0;
    if (true) {
        $campaignIds = array_column(
            $db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$eventId]),
            'campaign_id'
        );
        if (!empty($campaignIds)) {
            $ph   = implode(',', array_fill(0, count($campaignIds), '?'));
            $meta = $db->fetch(
                "SELECT SUM(spend) as total FROM campaign_snapshots
                 WHERE campaign_id IN ({$ph})",
                $campaignIds
            );
            $metaSpend = floatval($meta['total'] ?? 0);
        }
    }

    // Entradas manuais
    $entries = $db->fetchAll(
        "SELECT efe.*, fc.name as category_name, fc.icon as category_icon
         FROM event_financial_entries efe
         LEFT JOIN financial_categories fc ON fc.id = efe.category_id
         WHERE efe.event_id=?
         ORDER BY efe.entry_date DESC, efe.created_at DESC",
        [$eventId]
    );

    $totalEntradas = 0;
    $totalDespesas = floatval($metaSpend); // Meta já conta como despesa

    // Impostos Meta: ISS 2,9% + PIS/COFINS 9,25% sobre o gasto em anúncios
    $metaTax     = calcMetaTax(floatval($metaSpend));
    $taxIss      = $metaTax['iss'];
    $taxPisCofins= $metaTax['pis_cofins'];
    $taxTotal    = $metaTax['total'];
    $totalDespesas += $taxTotal;
    foreach ($entries as $e) {
        if ($e['type'] === 'entrada') $totalEntradas += floatval($e['amount']);
        else $totalDespesas += floatval($e['amount']);
    }

    $resultado = $totalEntradas - $totalDespesas;

    // Categorias disponíveis
    // Categorias — agrupa por nome+tipo pra evitar duplicatas de migrations repetidas
    $categories = $db->fetchAll(
        "SELECT MIN(id) as id, name, type, icon, MAX(is_default) as is_default
         FROM financial_categories
         GROUP BY name, type
         ORDER BY type, is_default DESC, name"
    );

    echo json_encode([
        'success'         => true,
        'event'           => ['id'=>$eventId,'name'=>$event['name'],'date_event'=>$event['date_event']],
        'period'          => [
            'start' => $event['date_campaign_start'] ?: '—',
            'end'   => $event['date_campaign_end']   ?: date('Y-m-d'),
            'label' => 'Todo o período das campanhas',
        ],
        'meta_spend'      => $metaSpend,
        'tax_iss'         => $taxIss,
        'tax_pis_cofins'  => $taxPisCofins,
        'tax_total'       => $taxTotal,
        'total_entradas'  => $totalEntradas,
        'total_despesas'  => $totalDespesas,
        'resultado'       => $resultado,
        'entries'         => $entries,
        'categories'      => $categories,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── SALVAR LANÇAMENTO ─────────────────────────────────────────
if ($action === 'save_entry') {
    $eventId    = intval($body['event_id'] ?? 0);
    $type       = $body['type'] ?? '';
    $categoryId = intval($body['category_id'] ?? 0) ?: null;
    $description= trim($body['description'] ?? '');
    $amount     = floatval(str_replace(',', '.', $body['amount'] ?? '0'));
    $date       = $body['date'] ?? date('Y-m-d');

    if (!$eventId || !in_array($type, ['entrada','despesa']) || $amount <= 0) {
        echo json_encode(['error'=>'Dados inválidos']); exit;
    }

    $db->execute(
        "INSERT INTO event_financial_entries (event_id, type, category_id, description, amount, entry_date, created_by)
         VALUES (?,?,?,?,?,?,?)",
        [$eventId, $type, $categoryId, $description, $amount, $date, $uid]
    );

    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── DELETAR LANÇAMENTO ────────────────────────────────────────
if ($action === 'delete_entry') {
    $id = intval($body['id'] ?? 0);
    if (!$id) { echo json_encode(['error'=>'ID inválido']); exit; }

    $row = $db->fetch("SELECT event_id FROM event_financial_entries WHERE id=?", [$id]);
    if (!$row) { echo json_encode(['error'=>'Não encontrado']); exit; }

    $db->execute("DELETE FROM event_financial_entries WHERE id=?", [$id]);
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── SALVAR NOVA CATEGORIA ─────────────────────────────────────
if ($action === 'save_category') {
    $name = trim($body['name'] ?? '');
    $type = $body['type'] ?? '';
    $icon = trim($body['icon'] ?? '📌');

    if (!$name || !in_array($type, ['entrada','despesa'])) {
        echo json_encode(['error'=>'Nome e tipo obrigatórios']); exit;
    }

    $db->execute(
        "INSERT INTO financial_categories (name, type, icon, is_default, created_by) VALUES (?,?,?,0,?)",
        [$name, $type, $icon, $uid]
    );

    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── DELETAR CATEGORIA ─────────────────────────────────────────
if ($action === 'delete_category') {
    $id = intval($body['id'] ?? 0);
    if (!$id) { echo json_encode(['error'=>'ID inválido']); exit; }

    // Não deleta categorias padrão do sistema
    $cat = $db->fetch("SELECT is_default FROM financial_categories WHERE id=?", [$id]);
    if (!$cat) { echo json_encode(['error'=>'Categoria não encontrada']); exit; }
    if ($cat['is_default']) { echo json_encode(['error'=>'Não é possível deletar categorias padrão']); exit; }

    $db->execute("DELETE FROM financial_categories WHERE id=?", [$id]);
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error'=>'Ação inválida'], JSON_UNESCAPED_UNICODE);
