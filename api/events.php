<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notify.php';

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$db   = Database::getInstance();
$role = Auth::getRole();
$uid  = Auth::getUserId();
$isAdmin      = in_array($role, ['admin','chefe']);
$canViewEvents = in_array($role, ['admin','chefe','financeiro','marketing','user']);


$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? $body['action'] ?? '';

// ── HELPER: notificar usuários ────────────────────────────────
function notify($db, $userIds, $title, $message, $link) {
    // agora também dispara push na hora (antes só acendia o sino)
    notificar($db, $userIds, $title, $message, $link);
}

// ── HELPER: usuários com acesso ao evento ─────────────────────
function eventUsers($db, $eventId) {
    $rows = $db->fetchAll(
        "SELECT u.id FROM users u
         JOIN event_user_access ea ON ea.user_id = u.id
         WHERE ea.event_id = ?", [$eventId]
    );
    return array_column($rows, 'id');
}

// ── HELPER: verifica acesso ao evento ────────────────────────
function hasAccess($db, $eventId, $uid, $isAdmin) {
    global $role;
    // Só admin vê tudo — chefe, financeiro e demais precisam de liberação explícita
    if ($role === 'admin') return true;
    $row = $db->fetch(
        "SELECT 1 FROM event_user_access WHERE event_id=? AND user_id=?",
        [$eventId, $uid]
    );
    return (bool)$row;
}

// ════════════════════════════════════════════════════════════
// EVENTOS
// ════════════════════════════════════════════════════════════

/**
 * Contas de um evento. Usa event_accounts; se a migração ainda não rodou,
 * cai na conta única de events.account_id.
 */
function eventAccounts($db, $eventId, $fallback = null) {
    try {
        $rows = $db->fetchAll("SELECT account_id FROM event_accounts WHERE event_id=? ORDER BY id ASC", [$eventId]);
        $ids = array_column($rows, 'account_id');
        if ($ids) return $ids;
    } catch (Exception $e) {}
    return $fallback ? [$fallback] : [];
}

/**
 * Contagem rápida de oportunidades por evento (escalar / pausar).
 * Usa só o banco — nada de chamada à Meta, pra não travar a lista.
 * Regras alinhadas com o painel de recomendações da aba Conversões.
 */
function oportunidadesPorEvento($db, $eventIds) {
    $res = [];
    if (!$eventIds) return $res;
    try {
        $ph = implode(',', array_fill(0, count($eventIds), '?'));
        // gasto/conversões por campanha, com o evento e o objetivo
        $rows = $db->fetchAll("
            SELECT ec.event_id, ec.campaign_id, ec.campaign_name, ec.objective,
                   ec.status, ec.effective_status, ec.stop_time,
                   COALESCE(SUM(cc.spend),0)     AS spend,
                   COALESCE(SUM(cc.purchases),0) AS purchases,
                   COALESCE(SUM(cc.leads),0)     AS leads,
                   COALESCE(SUM(cc.revenue),0)   AS revenue,
                   COUNT(DISTINCT CASE WHEN cc.spend > 0 THEN cc.snapshot_date END) AS dias
            FROM event_campaigns ec
            LEFT JOIN campaign_conversion_snapshots cc ON cc.campaign_id = ec.campaign_id
            WHERE ec.event_id IN ($ph)
            GROUP BY ec.event_id, ec.campaign_id, ec.campaign_name, ec.objective,
                     ec.status, ec.effective_status, ec.stop_time", $eventIds);
    } catch (Exception $e) { return $res; }

    // agrupa por evento e calcula o benchmark de cada um
    $porEvento = [];
    foreach ($rows as $r) $porEvento[(int)$r['event_id']][] = $r;

    foreach ($porEvento as $eid => $camps) {
        $tot = ['spend'=>0,'rev'=>0,'purch'=>0,'leads'=>0];
        foreach ($camps as $c) {
            $tot['spend'] += (float)$c['spend']; $tot['rev']   += (float)$c['revenue'];
            $tot['purch'] += (int)$c['purchases']; $tot['leads'] += (int)$c['leads'];
        }
        $roasM = $tot['spend'] > 0 ? $tot['rev'] / $tot['spend'] : 0;
        $cpaM  = $tot['purch'] > 0 ? $tot['spend'] / $tot['purch'] : 0;
        $cplM  = $tot['leads'] > 0 ? $tot['spend'] / $tot['leads'] : 0;

        $escalar = 0; $pausar = 0;
        foreach ($camps as $c) {
            $spend = (float)$c['spend'];
            if ($spend < 50) continue;

            // só campanhas entregando de verdade
            $eff = strtoupper((string)$c['effective_status']);
            $ativa = $eff !== '' ? ($eff === 'ACTIVE') : (strtoupper((string)$c['status']) === 'ACTIVE');
            if (!empty($c['stop_time']) && strtotime($c['stop_time']) < time()) $ativa = false;
            if (!$ativa) continue;

            $dias  = (int)$c['dias'];
            $obj   = strtoupper((string)$c['objective']);
            $nome  = mb_strtoupper((string)$c['campaign_name']);
            $purch = (int)$c['purchases']; $leads = (int)$c['leads']; $rev = (float)$c['revenue'];

            // alcance/engajamento não entram nesta contagem (métrica vem dos criativos)
            if (in_array($obj, ['OUTCOME_AWARENESS','REACH','BRAND_AWARENESS','OUTCOME_ENGAGEMENT','POST_ENGAGEMENT','VIDEO_VIEWS'])
                && !preg_match('/\bLEADS?\b|\bQUIZ\b|\bCADASTRO/u', $nome)) continue;

            $ehVenda = in_array($obj, ['OUTCOME_SALES','CONVERSIONS','PRODUCT_CATALOG_SALES'])
                || (!in_array($obj, ['OUTCOME_LEADS','LEAD_GENERATION','MESSAGES'])
                    && !preg_match('/\bLEADS?\b|\bQUIZ\b|\bCADASTRO|\bWHATS/u', $nome)
                    && ($purch > 0 || $rev > 0));
            $conv = $ehVenda ? $purch : $leads;
            if ($dias < 3) continue;

            if ($ehVenda) {
                $roas = $spend > 0 ? $rev / $spend : 0;
                if ($purch === 0 && $cpaM > 0 && $spend >= $cpaM * 2) { $pausar++; continue; }
                if ($conv < 10) continue;
                if ($roasM > 0 && $roas >= $roasM * 1.5) $escalar++;
                elseif ($roasM > 0 && $roas < $roasM * 0.5) $pausar++;
            } else {
                $cpl = $leads > 0 ? $spend / $leads : 0;
                if ($leads === 0 && $cplM > 0 && $spend >= $cplM * 5) { $pausar++; continue; }
                if ($conv < 10) continue;
                if ($cplM > 0 && $cpl <= $cplM * 0.7) $escalar++;
                elseif ($cplM > 0 && $cpl >= $cplM * 2) $pausar++;
            }
        }
        $res[$eid] = ['escalar' => $escalar, 'pausar' => $pausar];
    }
    return $res;
}

if ($action === 'list_performance') {
    // Performance de TODOS os eventos numa requisição só.
    // Antes era uma requisição por card; com muitos eventos o servidor recusava
    // novas conexões com o MySQL (PDOException 2002) e a tela quebrava.
    $ids = array();
    try {
        if ($isAdmin) {
            $evs = $db->fetchAll("SELECT id, budget FROM events WHERE status = 'active'");
        } else {
            $evs = $db->fetchAll("SELECT e.id, e.budget FROM events e
                                  JOIN event_user_access eua ON eua.event_id = e.id AND eua.user_id = ?
                                  WHERE e.status = 'active'", array($uid));
        }
        foreach ($evs as $e) $ids[(int)$e['id']] = (float)$e['budget'];
    } catch (Exception $e) {
        echo json_encode(array('success'=>false,'error'=>$e->getMessage())); exit;
    }
    if (!$ids) { echo json_encode(array('success'=>true,'performance'=>new stdClass())); exit; }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = array_keys($ids);

    // Uma query agrega tudo: gasto/compras/leads/receita por evento,
    // somando só as campanhas vinculadas a cada um.
    $out = array();
    try {
        $rows = $db->fetchAll("
            SELECT ec.event_id,
                   COALESCE(SUM(cs.spend),0)       AS spend,
                   COALESCE(SUM(cs.purchases),0)   AS purchases,
                   COALESCE(SUM(cs.leads),0)       AS leads,
                   COALESCE(SUM(cs.revenue),0)     AS revenue,
                   COALESCE(SUM(cs.impressions),0) AS impressions,
                   COALESCE(SUM(cs.clicks),0)      AS clicks
            FROM event_campaigns ec
            LEFT JOIN campaign_snapshots cs ON cs.campaign_id = ec.campaign_id
            WHERE ec.event_id IN ($ph)
            GROUP BY ec.event_id", $params);

        foreach ($rows as $r) {
            $eid    = (int)$r['event_id'];
            $spend  = (float)$r['spend'];
            $budget = $ids[$eid] ?? 0;
            $out[$eid] = array(
                'success'     => true,
                'perf'        => array(
                    'spend'       => $spend,
                    'purchases'   => (int)$r['purchases'],
                    'leads'       => (int)$r['leads'],
                    'revenue'     => (float)$r['revenue'],
                    'impressions' => (int)$r['impressions'],
                    'clicks'      => (int)$r['clicks'],
                    'roas'        => $spend > 0 ? round($r['revenue'] / $spend, 2) : 0,
                ),
                'budget'      => $budget,
                'budget_pct'  => $budget > 0 ? round($spend / $budget * 100, 1) : 0,
                'budget_left' => $budget > 0 ? round($budget - $spend, 2) : 0,
            );
        }
    } catch (Exception $e) {
        echo json_encode(array('success'=>false,'error'=>$e->getMessage())); exit;
    }

    echo json_encode(array('success'=>true, 'performance'=>$out), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list_projections') {
    // Uma requisição só devolve a projeção de TODOS os eventos (lida do cache).
    // Antes a tela abria uma requisição por card; com muitos eventos isso
    // estourava o limite de conexões simultâneas do MySQL e dava erro 500.
    $out = [];
    try {
        $rows = $db->fetchAll("SELECT event_id, payload,
                                      TIMESTAMPDIFF(MINUTE, updated_at, NOW()) AS idade
                               FROM projection_cache");
        foreach ($rows as $r) {
            $p = json_decode($r['payload'], true);
            if (!$p || empty($p['success'])) continue;
            // manda só o que o card precisa — o payload completo é grande
            $out[(int)$r['event_id']] = [
                'success'       => true,
                'budget'        => $p['budget'] ?? 0,
                'spent_all'     => $p['spent_all'] ?? 0,
                'projected_all' => $p['projected_all'] ?? 0,
                'margin'        => $p['margin'] ?? 0,
                'will_overflow' => $p['will_overflow'] ?? false,
                'overflow_by'   => $p['overflow_by'] ?? 0,
                'idade_min'     => (int)$r['idade'],
            ];
        }
    } catch (Exception $e) {
        echo json_encode(['success'=>false, 'error'=>'Cache de projeções indisponível: ' . $e->getMessage()]);
        exit;
    }
    echo json_encode(['success'=>true, 'projections'=>$out], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list_events') {
    // Auto-encerra eventos cuja data já passou — só se ainda estiver 'active'
    $db->execute(
        "UPDATE events SET status='finished' WHERE status='active' AND date_event IS NOT NULL AND date_event < CURDATE()",
        []
    );

    if ($role === 'admin') {
        // Admin vê todos os eventos
        $events = $db->fetchAll("SELECT * FROM events
            ORDER BY (date_event IS NULL), (date_event < CURDATE()),
                     CASE WHEN date_event >= CURDATE() THEN date_event END ASC,
                     CASE WHEN date_event <  CURDATE() THEN date_event END DESC");
    } else {
        // Todos os outros (chefe, financeiro, marketing, user) veem só os liberados
        $events = $db->fetchAll(
            "SELECT e.* FROM events e
             JOIN event_user_access ea ON ea.event_id = e.id
             WHERE ea.user_id = ?
             ORDER BY (e.date_event IS NULL), (e.date_event < CURDATE()),
                      CASE WHEN e.date_event >= CURDATE() THEN e.date_event END ASC,
                      CASE WHEN e.date_event <  CURDATE() THEN e.date_event END DESC", [$uid]
        );
    }
    $OPORT = oportunidadesPorEvento($db, array_column($events, 'id'));

    // Adiciona contagem de tarefas e próximo lote
    foreach ($events as &$ev) {
        $ev['task_count']    = $db->fetch("SELECT COUNT(*) as c FROM event_tasks WHERE event_id=?", [$ev['id']])['c'];
        $ev['pending_tasks'] = $db->fetch("SELECT COUNT(*) as c FROM event_tasks WHERE event_id=? AND status!='concluido'", [$ev['id']])['c'];
        $ev['oportunidades'] = $OPORT[(int)$ev['id']] ?? ['escalar'=>0,'pausar'=>0];
        // moedas das contas do evento (valores já são convertidos para BRL na gravação)
        try {
            $moedas = array_column($db->fetchAll(
                "SELECT DISTINCT a.currency FROM event_accounts ea
                 JOIN ad_accounts a ON a.account_id = ea.account_id
                 WHERE ea.event_id = ?", [$ev['id']]), 'currency');
            $ev['moedas'] = $moedas;
            $ev['multi_moeda'] = count($moedas) > 1;
        } catch (Exception $e) { $ev['moedas'] = []; $ev['multi_moeda'] = false; }
        // Se a migração das tarefas unificadas ainda não rodou, cai no formato antigo
        try {
            $ev['next_lot'] = $db->fetch(
                "SELECT id, title AS name, due_date AS lot_date, lot_price AS price
                 FROM event_tasks
                 WHERE event_id=? AND category IN ('lote','sem_taxa') AND status != 'concluido'
                   AND due_date >= CURDATE() ORDER BY due_date ASC LIMIT 1", [$ev['id']]);
        } catch (Exception $e) {
            try {
                $ev['next_lot'] = $db->fetch(
                    "SELECT * FROM event_lots WHERE event_id=? AND lot_date >= CURDATE()
                     ORDER BY lot_date ASC LIMIT 1", [$ev['id']]);
            } catch (Exception $e2) { $ev['next_lot'] = null; }
        }
    }
    echo json_encode(['success'=>true,'events'=>$events], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'create_event') {
    if (!$isAdmin) { echo json_encode(['error'=>'Sem permissao']); exit; }
    $name  = trim($body['name'] ?? '');
    $date  = $body['date_event'] ?? '';
    if (!$name || !$date) { echo json_encode(['error'=>'Nome e data sao obrigatorios']); exit; }

    try {
        $accIds = array_values(array_filter(array_map('trim', (array)($body['account_ids'] ?? ($body['account_id'] ? [$body['account_id']] : [])))));
        $budget = floatval($body['budget'] ?? 0);

        $eventId = $db->insert(
            "INSERT INTO events (name, description, date_event, date_campaign_start, date_campaign_end, budget, account_id, created_by)
             VALUES (?,?,?,?,?,?,?,?)",
            [$name, $body['description']??'', $date, $body['date_campaign_start']??null, $body['date_campaign_end']??null,
             $budget, !empty($accIds) ? $accIds[0] : null, $uid]
        );

    try {
        foreach ($accIds as $aid) {
            $db->execute("INSERT IGNORE INTO event_accounts (event_id, account_id) VALUES (?,?)", [$eventId, $aid]);
        }
    } catch (Exception $e) {}

        if (!empty($body['user_ids'])) {
            foreach ($body['user_ids'] as $userId) {
                $db->execute("INSERT IGNORE INTO event_user_access (event_id, user_id) VALUES (?,?)", [$eventId, $userId]);
            }
            $notifTitle = 'Novo evento: ' . $name;
            $notifMsg   = 'Voce foi adicionado ao evento ' . $name;
            $notifLink  = 'event.php?id=' . $eventId;
            notify($db, $body['user_ids'], $notifTitle, $notifMsg, $notifLink);
        }

        echo json_encode(['success'=>true,'id'=>$eventId]);
    } catch (Exception $e) {
        echo json_encode(['error'=>'Erro ao criar evento: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_event') {
    if (!$isAdmin) { echo json_encode(['error'=>'Sem permissao']); exit; }
    $id = intval($body['id'] ?? 0);
    if (!$id) { echo json_encode(['error'=>'ID invalido']); exit; }
    $db->execute("DELETE FROM event_user_access WHERE event_id=?", [$id]);
    $db->execute("DELETE FROM event_tasks WHERE event_id=?", [$id]);
    $db->execute("DELETE FROM event_materials WHERE event_id=?", [$id]);
    $db->execute("DELETE FROM event_lots WHERE event_id=?", [$id]);
    $db->execute("DELETE FROM events WHERE id=?", [$id]);
    echo json_encode(['success'=>true]);
    exit;
}


if ($action === 'duplicate_event') {
    if (!$isAdmin) { echo json_encode(['error'=>'Sem permissão']); exit; }
    $id = intval($body['id'] ?? 0);
    if (!$id) { echo json_encode(['error'=>'ID inválido']); exit; }

    $src = $db->fetch("SELECT * FROM events WHERE id=?", [$id]);
    if (!$src) { echo json_encode(['error'=>'Evento não encontrado']); exit; }

    $newId = $db->insert(
        "INSERT INTO events (name, description, date_event, date_campaign_start, date_campaign_end, budget, account_id, created_by, status)
         VALUES (?,?,?,?,?,?,?,?,?)",
        [
            $src['name'] . ' (Cópia)',
            $src['description'] ?? '',
            $src['date_event'],
            $src['date_campaign_start'],
            $src['date_campaign_end'],
            $src['budget'] ?? 0,
            $src['account_id'],
            $uid,
            'active',
        ]
    );

    try {
        $accs = $db->fetchAll("SELECT account_id FROM event_accounts WHERE event_id=?", [$id]);
        foreach ($accs as $a) {
            $db->execute("INSERT IGNORE INTO event_accounts (event_id, account_id) VALUES (?,?)", [$newId, $a['account_id']]);
        }
    } catch (Exception $e) {}

    try {
        $usList = $db->fetchAll("SELECT user_id FROM event_user_access WHERE event_id=?", [$id]);
        foreach ($usList as $u) {
            $db->execute("INSERT IGNORE INTO event_user_access (event_id, user_id) VALUES (?,?)", [$newId, $u['user_id']]);
        }
    } catch (Exception $e) {}

    echo json_encode(['success'=>true,'id'=>$newId]);
    exit;
}

if ($action === 'update_event') {
    if (!$isAdmin) { echo json_encode(['error'=>'Sem permissão']); exit; }
    $id = intval($body['id'] ?? 0);
    if (!$id) { echo json_encode(['error'=>'ID inválido']); exit; }

    $db->execute(
        "UPDATE events SET name=?, description=?, date_event=?, date_campaign_start=?, date_campaign_end=?, status=?, account_id=?, budget=? WHERE id=?",
        [$body['name'], $body['description']??'', $body['date_event'], $body['date_campaign_start']??null, $body['date_campaign_end']??null, $body['status']??'active', $body['account_id']??null, floatval($body['budget']??0), $id]
    );

    // Atualiza contas de anúncio do evento (a primeira vira a principal)
    $accIds = $body['account_ids'] ?? null;
    if (is_array($accIds)) {
        $accIds = array_values(array_filter(array_map('trim', $accIds)));
        try {
            $db->execute("DELETE FROM event_accounts WHERE event_id=?", [$id]);
            foreach ($accIds as $aid) {
                $db->execute("INSERT IGNORE INTO event_accounts (event_id, account_id) VALUES (?,?)", [$id, $aid]);
            }
            // mantém a conta principal preenchida — o cron de campanhas depende dela
            if (!empty($accIds)) {
                $db->execute("UPDATE events SET account_id=? WHERE id=?", [$accIds[0], $id]);
            }
        } catch (Exception $e) { /* migração pendente: mantém a conta única já gravada acima */ }
    }

    // Atualiza acessos
    $db->execute("DELETE FROM event_user_access WHERE event_id=?", [$id]);
    if (!empty($body['user_ids'])) {
        foreach ($body['user_ids'] as $userId) {
            $db->execute("INSERT IGNORE INTO event_user_access (event_id, user_id) VALUES (?,?)", [$id, $userId]);
        }
    }

    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_event') {
    $id = intval($_GET['id'] ?? $body['id'] ?? 0);
    if (!$id) { echo json_encode(['error'=>'ID inválido']); exit; }
    if (!hasAccess($db, $id, $uid, $isAdmin)) { echo json_encode(['error'=>'Sem acesso']); exit; }

    $event = $db->fetch("SELECT * FROM events WHERE id=?", [$id]);
    if (!$event) { echo json_encode(['error'=>'Não encontrado']); exit; }

    $event['tasks']     = $db->fetchAll("SELECT t.*, u.username as assigned_name FROM event_tasks t LEFT JOIN users u ON u.id=t.assigned_to WHERE t.event_id=? ORDER BY t.due_date ASC, t.created_at ASC", [$id]);
    $event['materials'] = $db->fetchAll("SELECT m.*, u.username as created_name FROM event_materials m LEFT JOIN users u ON u.id=m.created_by WHERE m.event_id=? ORDER BY m.due_date ASC", [$id]);
    // "lots" continua no payload (linha do tempo do cabeçalho), mas vem das tarefas de lote
    $event['account_ids'] = eventAccounts($db, $id, $event['account_id']);
    try {
        $event['accounts'] = $event['account_ids']
            ? $db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE account_id IN ("
                . implode(',', array_fill(0, count($event['account_ids']), '?')) . ")", $event['account_ids'])
            : [];
    } catch (Exception $e) { $event['accounts'] = []; }

    try {
        $event['lots'] = $db->fetchAll(
            "SELECT id, title AS name, due_date AS lot_date, lot_price AS price, category AS type, status
             FROM event_tasks WHERE event_id=? AND category IN ('lote','sem_taxa') AND due_date IS NOT NULL
             ORDER BY due_date ASC", [$id]);
    } catch (Exception $e) {
        try { $event['lots'] = $db->fetchAll("SELECT * FROM event_lots WHERE event_id=? ORDER BY lot_date ASC", [$id]); }
        catch (Exception $e2) { $event['lots'] = []; }
    }
    $event['users']     = $db->fetchAll("SELECT u.id, u.username, u.role FROM users u JOIN event_user_access ea ON ea.user_id=u.id WHERE ea.event_id=?", [$id]);

    echo json_encode(['success'=>true,'event'=>$event], JSON_UNESCAPED_UNICODE);
    exit;
}

// ════════════════════════════════════════════════════════════
// TAREFAS
// ════════════════════════════════════════════════════════════

if ($action === 'create_task') {
    $eventId = intval($body['event_id'] ?? 0);
    if (!hasAccess($db, $eventId, $uid, $isAdmin)) { echo json_encode(['error'=>'Sem acesso']); exit; }

    $title = trim($body['title'] ?? '');
    if (!$title) { echo json_encode(['error'=>'Título obrigatório']); exit; }

    $assignedTo = !empty($body['assigned_to']) ? intval($body['assigned_to']) : null;
    $category   = $body['category'] ?? 'outro';
    $isLot      = in_array($category, ['lote', 'sem_taxa']);
    try {
        $taskId = $db->insert(
        "INSERT INTO event_tasks (event_id, title, category, assigned_to, status, priority, due_date, lot_price, notes, link, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)",
        [$eventId, $title, $category, $assignedTo, $body['status'] ?? 'pendente',
         $body['priority'] ?? ($isLot ? 'alta' : 'media'), $body['due_date'] ?? null,
         ($body['lot_price'] ?? '') !== '' ? $body['lot_price'] : null,
         $body['notes'] ?? '', $body['link'] ?? null, $uid]
        );
    } catch (Exception $e) {
        echo json_encode(['error' => 'Banco desatualizado: rode a migração 2026-09-03_tarefas_unificadas.sql no phpMyAdmin.']);
        exit;
    }

    $event = $db->fetch("SELECT name FROM events WHERE id=?", [$eventId]);
    if ($isLot) {
        // Virada de lote: avisa todo mundo do evento
        $preco = !empty($body['lot_price']) ? ' — R$' . number_format((float)$body['lot_price'], 2, ',', '.') : '';
        $quando = !empty($body['due_date']) ? date('d/m/Y', strtotime($body['due_date'])) : 'sem data';
        notify($db, eventUsers($db, $eventId), "🎟️ Virada de lote: {$title}{$preco}",
               "Programada para {$quando} no evento {$event['name']}", "event.php?id={$eventId}");
    } elseif ($assignedTo && $assignedTo !== $uid) {
        notify($db, [$assignedTo], "📋 Nova tarefa: {$title}", "Você foi atribuído à tarefa '{$title}' no evento {$event['name']}", "event.php?id={$eventId}");
    }

    $task = $db->fetch("SELECT t.*, u.username as assigned_name FROM event_tasks t LEFT JOIN users u ON u.id=t.assigned_to WHERE t.id=?", [$taskId]);
    echo json_encode(['success'=>true,'task'=>$task], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'update_task') {
    $id = intval($body['id'] ?? 0);
    $task = $db->fetch("SELECT * FROM event_tasks WHERE id=?", [$id]);
    if (!$task) { echo json_encode(['error'=>'Não encontrado']); exit; }
    if (!hasAccess($db, $task['event_id'], $uid, $isAdmin)) { echo json_encode(['error'=>'Sem acesso']); exit; }

    $oldStatus = $task['status'];
    $newStatus = $body['status'] ?? $oldStatus;
    $assignedTo = isset($body['assigned_to']) ? (intval($body['assigned_to']) ?: null) : $task['assigned_to'];

    $db->execute(
        "UPDATE event_tasks SET title=?, category=?, assigned_to=?, status=?, priority=?, due_date=?,
                lot_price=?, notes=?, link=?,
                done_at = IF(? = 'concluido', COALESCE(done_at, NOW()), NULL)
         WHERE id=?",
        [$body['title'] ?? $task['title'], $body['category'] ?? $task['category'], $assignedTo, $newStatus,
         $body['priority'] ?? $task['priority'], array_key_exists('due_date', $body) ? ($body['due_date'] ?: null) : $task['due_date'],
         array_key_exists('lot_price', $body) ? ($body['lot_price'] !== '' ? $body['lot_price'] : null) : $task['lot_price'],
         $body['notes'] ?? $task['notes'],
         array_key_exists('link', $body) ? ($body['link'] ?: null) : $task['link'],
         $newStatus, $id]
    );

    // Notifica criador quando concluída
    if ($oldStatus !== 'concluido' && $newStatus === 'concluido' && $task['created_by'] !== $uid) {
        $event = $db->fetch("SELECT name FROM events WHERE id=?", [$task['event_id']]);
        notify($db, [$task['created_by']], "✅ Tarefa concluída: {$task['title']}", "A tarefa foi marcada como concluída no evento {$event['name']}", "event.php?id={$task['event_id']}");
    }

    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete_task') {
    $id = intval($body['id'] ?? 0);
    $task = $db->fetch("SELECT * FROM event_tasks WHERE id=?", [$id]);
    if (!$task) { echo json_encode(['error'=>'Não encontrado']); exit; }
    if (!$isAdmin && (int)$task['created_by'] !== $uid) { echo json_encode(['error'=>'Sem permissão']); exit; }
    $db->execute("DELETE FROM event_tasks WHERE id=?", [$id]);
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ════════════════════════════════════════════════════════════
// MATERIAIS
// ════════════════════════════════════════════════════════════

if ($action === 'create_material') {
    $eventId = intval($body['event_id'] ?? 0);
    if (!hasAccess($db, $eventId, $uid, $isAdmin)) { echo json_encode(['error'=>'Sem acesso']); exit; }

    $name = trim($body['name'] ?? '');
    if (!$name) { echo json_encode(['error'=>'Nome obrigatório']); exit; }

    $matId = $db->insert(
        "INSERT INTO event_materials (event_id, name, link, due_date, status, created_by) VALUES (?,?,?,?,?,?)",
        [$eventId, $name, $body['link']??'', $body['due_date']??null, $body['status']??'pendente', $uid]
    );
    $mat = $db->fetch("SELECT * FROM event_materials WHERE id=?", [$matId]);
    echo json_encode(['success'=>true,'material'=>$mat], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'update_material') {
    $id  = intval($body['id'] ?? 0);
    $mat = $db->fetch("SELECT * FROM event_materials WHERE id=?", [$id]);
    if (!$mat || !hasAccess($db, $mat['event_id'], $uid, $isAdmin)) { echo json_encode(['error'=>'Sem acesso']); exit; }
    $db->execute(
        "UPDATE event_materials SET name=?, link=?, due_date=?, status=? WHERE id=?",
        [$body['name']??$mat['name'], $body['link']??$mat['link'], $body['due_date']??$mat['due_date'], $body['status']??$mat['status'], $id]
    );
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete_material') {
    $id  = intval($body['id'] ?? 0);
    $mat = $db->fetch("SELECT * FROM event_materials WHERE id=?", [$id]);
    if (!$mat || !hasAccess($db, $mat['event_id'], $uid, $isAdmin)) { echo json_encode(['error'=>'Sem acesso']); exit; }
    $db->execute("DELETE FROM event_materials WHERE id=?", [$id]);
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ════════════════════════════════════════════════════════════
// LOTES
// ════════════════════════════════════════════════════════════

if ($action === 'create_lot') {
    if (!$isAdmin) { echo json_encode(['error'=>'Sem permissão']); exit; }
    $eventId = intval($body['event_id'] ?? 0);
    $name    = trim($body['name'] ?? '');
    $date    = $body['lot_date'] ?? '';
    if (!$name || !$date) { echo json_encode(['error'=>'Nome e data obrigatórios']); exit; }

    $lotId = $db->insert(
        "INSERT INTO event_lots (event_id, name, lot_date, price, type, material_link, material_name) VALUES (?,?,?,?,?,?,?)",
        [$eventId, $name, $date, $body['price']??null, $body['type']??'lote', $body['material_link']??'', $body['material_name']??'']
    );
    $lot = $db->fetch("SELECT * FROM event_lots WHERE id=?", [$lotId]);

    // Notifica todos com acesso
    $users = eventUsers($db, $eventId);
    $event = $db->fetch("SELECT name FROM events WHERE id=?", [$eventId]);
    $price = $body['price'] ? ' — R$'.number_format($body['price'],2,',','.') : '';
    notify($db, $users, "🎟️ Novo lote: {$name}{$price}", "Virada de lote cadastrada em ".date('d/m/Y', strtotime($date))." no evento {$event['name']}", "event.php?id={$eventId}");

    echo json_encode(['success'=>true,'lot'=>$lot], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete_lot') {
    if (!$isAdmin) { echo json_encode(['error'=>'Sem permissão']); exit; }
    $db->execute("DELETE FROM event_lots WHERE id=?", [intval($body['id']??0)]);
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ════════════════════════════════════════════════════════════
// NOTIFICAÇÕES
// ════════════════════════════════════════════════════════════

if ($action === 'get_notifications') {
    $notifs = $db->fetchAll(
        "SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 30", [$uid]
    );
    $unread = $db->fetch("SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND read_at IS NULL", [$uid])['c'];
    echo json_encode(['success'=>true,'notifications'=>$notifs,'unread'=>intval($unread)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'mark_read') {
    $id = intval($body['id'] ?? 0);
    if ($id) {
        $db->execute("UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=?", [$id, $uid]);
    } else {
        $db->execute("UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL", [$uid]);
    }
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── CRON: lotes em 2 dias ────────────────────────────────────
if ($action === 'check_lot_alerts') {
    try {
        $lots = $db->fetchAll(
            "SELECT t.id, t.event_id, t.title AS name, t.due_date AS lot_date, t.lot_price AS price,
                    e.name as event_name
             FROM event_tasks t
             JOIN events e ON e.id = t.event_id
             WHERE t.category IN ('lote','sem_taxa') AND t.status != 'concluido'
               AND t.due_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)"
        );
    } catch (Exception $e) { $lots = []; }
    foreach ($lots as $lot) {
        $users = eventUsers($db, $lot['event_id']);
        $price = $lot['price'] ? ' — R$'.number_format($lot['price'],2,',','.') : '';
        notify($db, $users, "⏰ Lote em 2 dias: {$lot['name']}{$price}", "Virada de lote no evento {$lot['event_name']} em 2 dias ({$lot['lot_date']})", "event.php?id={$lot['event_id']}");
    }
    echo json_encode(['success'=>true,'checked'=>count($lots)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── USUÁRIOS disponíveis para atribuição ─────────────────────
if ($action === 'get_users') {
    $users = $db->fetchAll("SELECT id, username, role FROM users WHERE role IN ('chefe','financeiro','marketing','user') ORDER BY username");
    echo json_encode(['success'=>true,'users'=>$users], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error'=>'Ação inválida'], JSON_UNESCAPED_UNICODE);