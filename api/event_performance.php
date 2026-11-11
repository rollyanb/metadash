<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/fx.php';

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$db   = Database::getInstance();
$uid  = Auth::getUserId();
$role = Auth::getRole();
$isAdmin = in_array($role, ['admin','chefe']);

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? $body['action'] ?? '';

// Diagnóstico: com ?debug=1 a API devolve o erro real em vez de "Erro interno".
// Só admin, e só nesta API — nunca expõe detalhe pra usuário comum.
// Quem o sistema acha que sou eu (ajuda quando dá "Sem acesso" inesperado)
if (($_GET['debug'] ?? '') === 'quem') {
    echo json_encode([
        'user_id'     => $uid,
        'role'        => $role,
        'is_admin'    => $isAdmin,
        'session_id'  => session_id() ? substr(session_id(), 0, 8) . '…' : null,
        'username'    => $_SESSION['username'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($_GET['debug']) && $isAdmin) {
    set_exception_handler(function ($e) {
        http_response_code(200);
        echo json_encode([
            'debug_error' => get_class($e) . ': ' . $e->getMessage(),
            'file'        => basename($e->getFile()),
            'line'        => $e->getLine(),
            'trace'       => array_slice(array_map(function ($t) {
                return ($t['file'] ?? '?') . ':' . ($t['line'] ?? '?') . ' ' . ($t['function'] ?? '');
            }, $e->getTrace()), 0, 5),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            echo json_encode(['debug_fatal' => $e['message'], 'file' => basename($e['file']), 'line' => $e['line']], JSON_UNESCAPED_UNICODE);
        }
    });
}

/**
 * Status real da campanha para exibição/projeção.
 * A Meta mantém `status` = ACTIVE mesmo com todos os conjuntos pausados
 * ou com a data de término vencida; `effective_status` é quem diz a verdade.
 * Retorna ['ATIVA'|'PAUSADA'|'ENCERRADA'|'ARQUIVADA', é_ativa_de_verdade]
 */
function statusReal($c) {
    $eff  = strtoupper((string)($c['effective_status'] ?? ''));
    $conf = strtoupper((string)($c['status'] ?? ''));
    $stop = $c['stop_time'] ?? null;

    if ($stop && strtotime($stop) < time())          return ['ENCERRADA', false];
    if (in_array($eff, ['ARCHIVED','DELETED']))      return ['ARQUIVADA', false];
    if ($eff === 'ACTIVE')                           return ['ATIVA', true];
    if ($eff !== '' && $eff !== 'ACTIVE')            return ['PAUSADA', false]; // PAUSED, CAMPAIGN_PAUSED, ADSET_PAUSED...
    // sem effective_status (sync antigo): cai no configurado
    if ($conf === 'ACTIVE')                          return ['ATIVA', true];
    if (in_array($conf, ['ARCHIVED','DELETED']))     return ['ARQUIVADA', false];
    return ['PAUSADA', false];
}

function eventAccountIds($db, $eventId, $fallback = null) {
    try {
        $ids = array_column($db->fetchAll("SELECT account_id FROM event_accounts WHERE event_id=? ORDER BY id ASC", [$eventId]), 'account_id');
        if ($ids) return $ids;
    } catch (Exception $e) {}
    return $fallback ? [$fallback] : [];
}

function hasEventAccess($db, $eventId, $uid, $isAdmin) {
    if ($isAdmin) return true;
    return (bool)$db->fetch("SELECT 1 FROM event_user_access WHERE event_id=? AND user_id=?", [$eventId, $uid]);
}

// ── GET PERFORMANCE ───────────────────────────────────────────
if ($action === 'get_performance') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }

    $event = $db->fetch("SELECT * FROM events WHERE id=?", [$eventId]);
    if (!$event) { echo json_encode(['error'=>'Evento não encontrado']); exit; }

    $dateStart = $event['date_campaign_start'] ?? $event['created_at'];
    $dateEnd   = date('Y-m-d');

    // Busca campanhas vinculadas
    $campaigns = $db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$eventId]);
    $campaignIds = array_column($campaigns, 'campaign_id');

    $perf = ['spend'=>0,'purchases'=>0,'leads'=>0,'revenue'=>0,'roas'=>0,'impressions'=>0,'clicks'=>0];

    if (!empty($campaignIds)) {
        // Métricas das campanhas vinculadas
        $ph   = implode(',', array_fill(0, count($campaignIds), '?'));
        $data = $db->fetch("
            SELECT
                SUM(spend)       as spend,
                SUM(purchases)   as purchases,
                SUM(leads)       as leads,
                SUM(revenue)     as revenue,
                SUM(impressions) as impressions,
                SUM(clicks)      as clicks
            FROM campaign_snapshots
            WHERE campaign_id IN ({$ph})
              AND snapshot_date BETWEEN ? AND ?
        ", array_merge($campaignIds, [$dateStart, $dateEnd]));

        if ($data) {
            $perf = $data;
            $perf['roas'] = floatval($perf['spend']) > 0
                ? round(floatval($perf['revenue']) / floatval($perf['spend']), 2)
                : 0;
        }

        // Gráfico dia a dia
        $daily = $db->fetchAll("
            SELECT snapshot_date, SUM(spend) as spend, SUM(purchases) as purchases, SUM(leads) as leads
            FROM campaign_snapshots
            WHERE campaign_id IN ({$ph})
              AND snapshot_date BETWEEN ? AND ?
            GROUP BY snapshot_date
            ORDER BY snapshot_date ASC
        ", array_merge($campaignIds, [$dateStart, $dateEnd]));

    } elseif ($accIds = eventAccountIds($db, $eventId, $event['account_id'])) {
        // Fallback: soma conversion_snapshots de TODAS as contas do evento
        $aph = implode(',', array_fill(0, count($accIds), '?'));
        $data = $db->fetch("
            SELECT SUM(spend) as spend, SUM(purchases) as purchases,
                   SUM(leads) as leads, SUM(revenue) as revenue
            FROM conversion_snapshots
            WHERE account_id IN ($aph) AND snapshot_date BETWEEN ? AND ?
        ", array_merge($accIds, [$dateStart, $dateEnd]));
        if ($data) {
            $perf = array_merge($perf, $data);
            $perf['roas'] = floatval($perf['spend']) > 0
                ? round(floatval($perf['revenue']) / floatval($perf['spend']), 2) : 0;
        }

        $daily = $db->fetchAll("
            SELECT snapshot_date, SUM(spend) as spend, SUM(purchases) as purchases, SUM(leads) as leads
            FROM conversion_snapshots
            WHERE account_id IN ($aph) AND snapshot_date BETWEEN ? AND ?
            GROUP BY snapshot_date ORDER BY snapshot_date ASC
        ", array_merge($accIds, [$dateStart, $dateEnd]));
    } else {
        $daily = [];
    }

    // % do budget
    $budget     = floatval($event['budget'] ?? 0);
    $budgetPct  = $budget > 0 ? min(100, round(floatval($perf['spend']) / $budget * 100, 1)) : null;
    $budgetLeft = $budget > 0 ? max(0, $budget - floatval($perf['spend'])) : null;

    // Campanhas por objetivo
    $campStats = $db->fetchAll("
        SELECT objective, status, effective_status, stop_time, COUNT(*) as total
        FROM event_campaigns
        WHERE event_id = ?
        GROUP BY objective, status, effective_status, stop_time
        ORDER BY total DESC
    ", [$eventId]);

    echo json_encode([
        'success'     => true,
        'perf'        => $perf,
        'budget'      => $budget,
        'budget_pct'  => $budgetPct,
        'budget_left' => $budgetLeft,
        'daily'       => $daily ?? [],
        'camp_stats'  => $campStats,
        'campaign_count' => count($campaignIds),
        'period'         => $perC,
        'date_start'     => $dateStart,
        'date_end'       => $dateEnd,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── LIST CAMPAIGNS (para selecionar) ─────────────────────────
if ($action === 'list_account_campaigns') {
    if (!$isAdmin) { echo json_encode(['error'=>'Sem permissão']); exit; }

    // Aceita account_id único (compat) ou event_id — neste caso busca em TODAS as contas do evento
    $accIds = [];
    if (!empty($body['event_id'])) {
        $ev = $db->fetch("SELECT account_id FROM events WHERE id=?", [(int)$body['event_id']]);
        $accIds = eventAccountIds($db, (int)$body['event_id'], $ev['account_id'] ?? null);
    } elseif (!empty($body['account_id'])) {
        $accIds = [$body['account_id']];
    }
    if (!$accIds) { echo json_encode(['error'=>'Configure as contas de anúncios do evento em Configurações primeiro.']); exit; }

    require_once __DIR__ . '/../includes/meta_api.php';
    $api   = new MetaAPI();
    $token = $api->getTokenValue();
    $ver   = metaApiVersion();

    // Nome das contas, pra identificar a origem de cada campanha no modal
    $ph = implode(',', array_fill(0, count($accIds), '?'));
    $nomes = [];
    foreach ($db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE account_id IN ($ph)", $accIds) as $a) {
        $nomes[$a['account_id']] = $a['account_name'];
    }

    $todas = []; $erros = [];
    foreach ($accIds as $accountId) {
        $url = "https://graph.facebook.com/{$ver}/{$accountId}/campaigns"
             . "?fields=id,name,objective,status,created_time"
             . "&effective_status=[\"ACTIVE\",\"PAUSED\",\"ARCHIVED\"]"
             . "&limit=200&access_token={$token}";
        $r = @json_decode(@file_get_contents($url), true);
        if (!$r || isset($r['error'])) {
            $erros[] = ($nomes[$accountId] ?? $accountId) . ': ' . ($r['error']['message'] ?? 'erro na API');
            continue;
        }
        foreach ($r['data'] ?? [] as $c) {
            $c['account_id']   = $accountId;
            $c['account_name'] = $nomes[$accountId] ?? $accountId;
            $todas[] = $c;
        }
    }

    if (!$todas && $erros) { echo json_encode(['error' => implode(' | ', $erros)]); exit; }

    echo json_encode([
        'success'      => true,
        'campaigns'    => $todas,
        'accounts'     => count($accIds),
        'multi'        => count($accIds) > 1,
        'erros'        => $erros,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── SAVE EVENT CAMPAIGNS ──────────────────────────────────────
if ($action === 'save_event_campaigns') {
    if (!$isAdmin) { echo json_encode(['error'=>'Sem permissão']); exit; }
    $eventId   = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    $campaigns = $body['campaigns'] ?? [];

    $db->execute("DELETE FROM event_campaigns WHERE event_id=?", [$eventId]);
    foreach ($campaigns as $c) {
        $db->execute(
            "INSERT INTO event_campaigns (event_id, campaign_id, campaign_name, objective, status) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE campaign_name=VALUES(campaign_name), objective=VALUES(objective), status=VALUES(status)",
            [$eventId, $c['id'], $c['name'], $c['objective']??'', $c['status']??'']
        );
    }
    echo json_encode(['success'=>true, 'saved'=>count($campaigns)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── UPDATE BUDGET ─────────────────────────────────────────────
if ($action === 'update_budget') {
    if (!$isAdmin) { echo json_encode(['error'=>'Sem permissão']); exit; }
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    $budget  = floatval($body['budget'] ?? 0);
    $accountId = $body['account_id'] ?? null;

    $db->execute(
        "UPDATE events SET budget=?, account_id=? WHERE id=?",
        [$budget, $accountId, $eventId]
    );
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── LIST SAVED CAMPAIGNS ─────────────────────────────────────
if ($action === 'list_event_campaigns_saved') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }
    $campaigns = $db->fetchAll(
        "SELECT campaign_id, campaign_name, objective, status, effective_status, stop_time
         FROM event_campaigns WHERE event_id=? ORDER BY campaign_name",
        [$eventId]
    );
    foreach ($campaigns as $i => $c) {
        list($label, $ativa) = statusReal($c);
        $campaigns[$i]['status_label'] = $label;
        $campaigns[$i]['is_active']    = $ativa;
    }
    echo json_encode(['success'=>true,'campaigns'=>$campaigns], JSON_UNESCAPED_UNICODE);
    exit;
}


// ── BUDGET PROJECTION ─────────────────────────────────────────
// Busca lifetime_budget/daily_budget das campanhas via API do Meta
// Ativas: usa o orçamento (quanto ainda pode gastar)
// Pausadas/inativas: usa só o gasto real (não vai mais gastar)
if ($action === 'get_budget_projection') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }

    // A tela lê o cache (alimentado pelo cron). Só calcula na hora se não houver
    // cache nenhum ou se vier ?refresh=1 — assim a lista de eventos nunca trava
    // esperando a Meta responder.
    $forcar = !empty($body['refresh']) || !empty($_GET['refresh']);
    if (!$forcar) {
        try {
            $c = $db->fetch("SELECT payload, TIMESTAMPDIFF(MINUTE, updated_at, NOW()) AS idade
                             FROM projection_cache WHERE event_id = ?", [$eventId]);
            if ($c) {
                $p = json_decode($c['payload'], true);
                // Cache gravado antes da conversão por campanha não serve mais:
                // a chave 'moedas' só existe nos payloads novos.
                if ($p && !array_key_exists('moedas', $p)) $p = null;
                if ($p) {
                    $p['cached'] = true;
                    $p['idade_min'] = (int)$c['idade'];
                    $p['stale'] = (int)$c['idade'] >= 20;
                    echo json_encode($p, JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        } catch (Exception $e) {}
    }

    require_once __DIR__ . '/../includes/projection.php';
    $saida = calcularProjecao($db, $eventId);
    echo json_encode($saida, JSON_UNESCAPED_UNICODE);
    exit;
}


// ── RESUMO DO EVENTO (estilo resumos.php, por campanhas vinculadas) ──
if ($action === 'get_resumo') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }

    $event = $db->fetch("SELECT * FROM events WHERE id=?", [$eventId]);
    if (!$event) { echo json_encode(['error'=>'Evento não encontrado']); exit; }

    $campaignIds = array_column(
        $db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$eventId]),
        'campaign_id'
    );

    if (empty($campaignIds)) {
        echo json_encode(['error'=>'Nenhuma campanha vinculada a este evento']); exit;
    }

    $dateStart = $body['date_start'] ?? null; // null = sem limite inferior (todo período)
    $dateEnd   = $body['date_end']   ?? null;

    $ph     = implode(',', array_fill(0, count($campaignIds), '?'));
    $params = $campaignIds;
    $sql    = "SELECT SUM(purchases) as purchases, SUM(leads) as leads,
                      SUM(revenue) as revenue, SUM(spend) as spend
               FROM campaign_snapshots
               WHERE campaign_id IN ({$ph})";

    if ($dateStart && $dateEnd) {
        $sql .= " AND snapshot_date BETWEEN ? AND ?";
        $params[] = $dateStart;
        $params[] = $dateEnd;
    }

    $data = $db->fetch($sql, $params);

    // Quando não há linha no período, explica o porquê em vez de só "nenhum dado"
    $vazio = !$data || ($data['spend'] === null && $data['purchases'] === null);
    $diag  = null;
    if ($vazio) {
        try {
            $ult = $db->fetch("SELECT MAX(snapshot_date) AS ultima_data, MAX(synced_at) AS ultimo_sync
                               FROM campaign_snapshots WHERE campaign_id IN ({$ph})", $campaignIds);
            $ativas = (int)($db->fetch("SELECT COUNT(*) c FROM event_campaigns
                                        WHERE event_id=? AND (effective_status='ACTIVE'
                                              OR (effective_status IS NULL AND status='ACTIVE'))",
                                        [$eventId])['c'] ?? 0);
            $ultimaData = $ult['ultima_data'] ?? null;

            if (!$ultimaData) {
                $diag = 'Nenhum dado sincronizado ainda para as campanhas deste evento.';
            } elseif ($dateStart && $ultimaData < $dateStart) {
                $diag = 'Sem gasto registrado neste período. Os dados mais recentes vão até '
                      . date('d/m/Y', strtotime($ultimaData))
                      . ($ult['ultimo_sync'] ? ' (última sincronização às ' . date('H:i', strtotime($ult['ultimo_sync'])) . ')' : '')
                      . ($ativas === 0 ? ' · nenhuma campanha ativa no momento.' : ' · o sync roda a cada 30 minutos.');
            } else {
                $diag = 'Não houve gasto nas campanhas deste evento neste período.'
                      . ($ativas === 0 ? ' Nenhuma campanha está ativa.' : '');
            }
        } catch (Exception $e) {}
    }

    // Vendas totais lançadas no mesmo período (para o comparativo no resumo)
    $vendasResumo = null;
    try {
        $condData = $dateStart ? "AND sale_date BETWEEN ? AND ?" : "";
        $parData  = $dateStart ? [$eventId, $dateStart, $dateEnd] : [$eventId];
        $v = $db->fetch("SELECT SUM(total_sales) tot, COUNT(*) dias FROM event_daily_sales
                         WHERE event_id=? {$condData}", $parData);
        if ($v && (int)$v['dias'] > 0) {
            $tot  = (int)$v['tot'];
            $adsP = (int)($data['purchases'] ?? 0);
            $vendasResumo = [
                'total'    => $tot,
                'ads'      => $adsP,
                'organico' => max(0, $tot - $adsP),
                'pct_ads'  => $tot > 0 ? round($adsP / $tot * 100, 1) : null,
                'dias'     => (int)$v['dias'],
            ];
        }
    } catch (Exception $e) {}

    echo json_encode([
        'success'        => true,
        'data'           => $data,
        'vazio'          => $vazio,
        'motivo'         => $diag,
        'vendas_totais'  => $vendasResumo,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


// ── SUGESTÕES — monta o prompt para colar no chat com Claude ───
if ($action === 'get_suggestions_prompt') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }

    $event = $db->fetch("SELECT * FROM events WHERE id=?", [$eventId]);
    if (!$event) { echo json_encode(['error'=>'Evento não encontrado']); exit; }

    $accIdsPrompt = eventAccountIds($db, $eventId, $event['account_id']);
    if (!$accIdsPrompt) {
        echo json_encode(['error'=>'Este evento não tem conta de anúncios vinculada. Configure na aba Configurações do evento.']); exit;
    }

    $campaigns = $db->fetchAll(
        "SELECT campaign_id, campaign_name, status, effective_status, stop_time
         FROM event_campaigns WHERE event_id=? ORDER BY status DESC, campaign_name",
        [$eventId]
    );

    // account_id no banco vem como act_XXXXXXXXX — o MCP do Facebook Ads precisa do ID puro (sem act_)
    $accountIdRaw = preg_replace('/^act_/', '', $accIdsPrompt[0]);

    $activeCampaigns = [];
    foreach ($campaigns as $c) {
        if ($c['status'] === 'ACTIVE') $activeCampaigns[] = $c;
    }
    $campaignIds     = array_column($activeCampaigns, 'campaign_id');

    $prompt  = "Analisa a conta de anúncios do evento \"{$event['name']}\" pelo MCP do Facebook Ads.\n\n";
    $prompt .= "account_id: {$accountIdRaw}\n\n";

    if (!empty($campaignIds)) {
        $prompt .= "Campanhas ativas vinculadas a este evento (entity_ids):\n";
        foreach ($activeCampaigns as $c) {
            $prompt .= "- {$c['campaign_id']} — {$c['campaign_name']}\n";
        }
        $prompt .= "\n";
    }

    $prompt .= "Quero que você rode opportunity score, anomaly signal e auction benchmarks, ";
    $prompt .= "focando nessas campanhas, e me dê um resumo com sugestões práticas de melhoria.";

    echo json_encode([
        'success'    => true,
        'prompt'     => $prompt,
        'account_id' => $accountIdRaw,
        'campaigns'  => array_values($campaigns),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── SUGESTÕES — salva o texto colado da resposta do Claude ─────
if ($action === 'save_suggestion') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    $content = trim($body['content'] ?? '');

    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }
    if ($content === '') {
        echo json_encode(['error'=>'Conteúdo vazio']); exit;
    }

    $db->execute(
        "INSERT INTO event_suggestions (event_id, content, created_by) VALUES (?, ?, ?)",
        [$eventId, $content, $uid]
    );

    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── SUGESTÕES — lista o histórico salvo ─────────────────────────
if ($action === 'list_suggestions') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }

    $rows = $db->fetchAll(
        "SELECT es.id, es.content, es.created_at, u.username
         FROM event_suggestions es
         JOIN users u ON u.id = es.created_by
         WHERE es.event_id = ?
         ORDER BY es.created_at DESC",
        [$eventId]
    );

    echo json_encode(['success'=>true, 'suggestions'=>$rows], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── SUGESTÕES — remove uma entrada do histórico ─────────────────
if ($action === 'delete_suggestion') {
    $id = intval($body['id'] ?? 0);
    if (!$id) { echo json_encode(['error'=>'ID inválido']); exit; }

    $row = $db->fetch("SELECT event_id FROM event_suggestions WHERE id=?", [$id]);
    if (!$row || !hasEventAccess($db, $row['event_id'], $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }

    $db->execute("DELETE FROM event_suggestions WHERE id=?", [$id]);
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── CRIATIVOS — melhores criativos das campanhas vinculadas ao evento ──
if ($action === 'get_event_creatives') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }

    $event = $db->fetch("SELECT * FROM events WHERE id=?", [$eventId]);
    if (!$event) { echo json_encode(['error'=>'Evento não encontrado']); exit; }

    $allowedSorts = ['purchases','leads','roas','spend','reach','impressions','score'];
    $sort = $body['sort'] ?? $_GET['sort'] ?? 'purchases';
    if (!in_array($sort, $allowedSorts, true)) $sort = 'purchases';

    $campaignRows = $db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$eventId]);
    $campaignIds  = array_column($campaignRows, 'campaign_id');
    // Fallback: sem campanha vinculada, mostra os criativos da conta inteira
    // (com aviso no front) em vez de deixar a aba vazia.
    $fallbackAccount = empty($campaignIds);

    // Período: usa as datas de campanha do evento; sem elas, cai pros últimos 30 dias
    // (mesma janela que o sync de criativos mantém em ad_creatives como fallback).
    // Período: padrão é todo o evento; aceita atalhos e intervalo personalizado
    $campStartC = $event['date_campaign_start'] ?: date('Y-m-d', strtotime('-30 days'));
    $perC  = (string)($body['period'] ?? $_GET['period'] ?? 'tudo');
    $hojeC = date('Y-m-d');
    $fimC  = $hojeC;
    switch ($perC) {
        case 'hoje':  $dateStart = $fimC = $hojeC; break;
        case 'ontem': $dateStart = $fimC = date('Y-m-d', strtotime('-1 day')); break;
        case '3': case '7': case '15': case '30':
            $dateStart = date('Y-m-d', strtotime('-' . ((int)$perC - 1) . ' days'));
            if ($dateStart < $campStartC) $dateStart = $campStartC;
            break;
        case 'custom':
            $vd = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : null; };
            $iC = $vd($body['date_start'] ?? $_GET['date_start'] ?? '');
            $fC = $vd($body['date_end']   ?? $_GET['date_end']   ?? '');
            if (!$iC || !$fC) { echo json_encode(['error' => 'Informe as duas datas do período.']); exit; }
            if ($iC > $fC) { $tmpC = $iC; $iC = $fC; $fC = $tmpC; }
            if ($fC > $hojeC) $fC = $hojeC;
            if ($iC < $campStartC) $iC = $campStartC;
            $dateStart = $iC; $fimC = $fC;
            break;
        default: $perC = 'tudo'; $dateStart = $campStartC;
    }
    $today     = date('Y-m-d');
    // Nunca passa de hoje — se a campanha ainda não terminou (fim no futuro ou vazio),
    // usa a data atual como limite pra não pedir dados de dias que ainda não aconteceram.
    $dateEnd = ($perC !== 'tudo')
        ? $fimC
        : ((!empty($event['date_campaign_end']) && $event['date_campaign_end'] < $today)
            ? $event['date_campaign_end']
            : $today);

    if ($fallbackAccount) {
        $accIds   = eventAccountIds($db, $eventId, $event['account_id']);
        $aph      = $accIds ? implode(',', array_fill(0, count($accIds), '?')) : "''";
        $whereSql = "ac.account_id IN ($aph)";
        $params   = array_merge([$dateStart, $dateEnd], $accIds);
    } else {
        $ph       = implode(',', array_fill(0, count($campaignIds), '?'));
        $whereSql = "ac.campaign_id IN ($ph)";
        $params   = array_merge([$dateStart, $dateEnd], $campaignIds);
    }

    // Com período selecionado, só entram criativos que realmente rodaram no
    // intervalo: usamos INNER JOIN e os números vêm SÓ dos snapshots.
    // (Antes o LEFT JOIN + COALESCE caía no total de todo o histórico quando
    //  não havia snapshot no período — por isso "Hoje" trazia criativo parado.)
    $filtraPeriodo = ($perC !== 'tudo');
    $joinTipo = $filtraPeriodo ? 'INNER JOIN' : 'LEFT JOIN';
    $sel = $filtraPeriodo
        ? "COALESCE(SUM(s.impressions),0) as impressions,
           COALESCE(SUM(s.reach),0)       as reach,
           COALESCE(SUM(s.clicks),0)      as clicks,
           COALESCE(SUM(s.spend),0)       as spend,
           COALESCE(SUM(s.purchases),0)   as purchases,
           COALESCE(SUM(s.leads),0)       as leads,
           COALESCE(SUM(s.revenue),0)     as revenue,
           CASE WHEN COALESCE(SUM(s.spend),0) > 0
                THEN ROUND(COALESCE(SUM(s.revenue),0) / SUM(s.spend), 2) ELSE 0 END as roas"
        : "COALESCE(SUM(s.impressions), ac.impressions) as impressions,
           COALESCE(SUM(s.reach),       ac.reach)       as reach,
           COALESCE(SUM(s.clicks),      ac.clicks)      as clicks,
           COALESCE(SUM(s.spend),       ac.spend)       as spend,
           COALESCE(SUM(s.purchases),   ac.purchases)   as purchases,
           COALESCE(SUM(s.leads),       ac.leads)       as leads,
           COALESCE(SUM(s.revenue),     ac.revenue)     as revenue,
           CASE WHEN COALESCE(SUM(s.spend), ac.spend) > 0
                THEN ROUND(COALESCE(SUM(s.revenue), ac.revenue) / COALESCE(SUM(s.spend), ac.spend), 2)
                ELSE 0 END as roas";
    // com período, esconde quem não teve entrega
    $having = $filtraPeriodo ? 'HAVING impressions > 0 OR spend > 0' : '';

    $matchParams = array_slice($params, 2);
    $countRow = $db->fetch("SELECT COUNT(*) AS total FROM ad_creatives ac WHERE {$whereSql}", $matchParams);
    $creativeCount = intval($countRow['total'] ?? 0);

    $creatives = $db->fetchAll(" 
        SELECT
            ac.ad_id, ac.account_id, ac.campaign_id, ac.ad_name, ac.is_dynamic,
            ac.thumbnail_url, ac.image_url, ac.title, ac.body,
            CASE WHEN ac.video_views > 0 THEN 'video' ELSE 'image' END as ad_type,
            {$sel},
            ac.score
        FROM ad_creatives ac
        {$joinTipo} ad_creative_snapshots s
            ON s.ad_id = ac.ad_id AND s.account_id = ac.account_id
            AND s.snapshot_date BETWEEN ? AND ?
        WHERE {$whereSql}
        GROUP BY ac.ad_id, ac.account_id, ac.campaign_id, ac.ad_name, ac.is_dynamic,
                 ac.thumbnail_url, ac.image_url, ac.title, ac.body, ac.video_views, ac.score
        {$having}
        ORDER BY {$sort} DESC
        LIMIT 60
    ", $params);

    // A classificação is_dynamic depende dos campos que a Meta expõe no creative
    // e pode falhar. Se já existem assets medidos para o anúncio, a tela também
    // deve permitir abri-los mesmo quando is_dynamic veio como zero.
    if ($creatives) {
        $assetAdIds = array_values(array_unique(array_column($creatives, 'ad_id')));
        $assetPlaceholders = implode(',', array_fill(0, count($assetAdIds), '?'));
        $assetRows = $db->fetchAll("SELECT DISTINCT ad_id,account_id FROM ad_creative_assets WHERE ad_id IN ($assetPlaceholders)", $assetAdIds);
        $assetKeys = [];
        foreach ($assetRows as $assetRow) {
            $assetKeys[(string)$assetRow['account_id'] . ':' . (string)$assetRow['ad_id']] = true;
        }
        foreach ($creatives as &$creative) {
            $creative['has_assets'] = !empty($assetKeys[(string)$creative['account_id'] . ':' . (string)$creative['ad_id']]);
        }
        unset($creative);
    }

    echo json_encode(['success'=>true,'creatives'=>$creatives,'sort'=>$sort,
        'fallback_account'=>$fallbackAccount,
        'campaign_count'=>count($campaignIds),
        'creative_count'=>$creativeCount,
        'date_start'=>$dateStart,'date_end'=>$dateEnd], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_creative_assets') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    $adId    = trim($body['ad_id'] ?? $_GET['ad_id'] ?? '');
    if (!$eventId || !$adId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }

    // O anúncio precisa pertencer à conta do evento (e, se houver campanhas
    // vinculadas, a uma delas) — impede consultar asset de outro cliente.
    $event = $db->fetch("SELECT account_id FROM events WHERE id=?", [$eventId]);
    if (!$event) { echo json_encode(['error'=>'Evento não encontrado']); exit; }

    $campaignIds = array_column(
        $db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$eventId]),
        'campaign_id'
    );
    if (!empty($campaignIds)) {
        $ph = implode(',', array_fill(0, count($campaignIds), '?'));
        $ok = $db->fetch("SELECT 1 FROM ad_creatives WHERE ad_id=? AND campaign_id IN ($ph) LIMIT 1",
            array_merge([$adId], $campaignIds));
    } else {
        $accIds = eventAccountIds($db, $eventId, $event['account_id']);
        $aph    = $accIds ? implode(',', array_fill(0, count($accIds), '?')) : "''";
        $ok = $db->fetch("SELECT 1 FROM ad_creatives WHERE ad_id=? AND account_id IN ($aph) LIMIT 1",
            array_merge([$adId], $accIds));
    }
    if (!$ok) { echo json_encode(['error'=>'Anúncio não pertence a este evento']); exit; }

    $allowedSorts = ['purchases','leads','roas','spend','reach','impressions'];
    $sort = $body['sort'] ?? 'purchases';
    if (!in_array($sort, $allowedSorts, true)) $sort = 'purchases';

    $assets = $db->fetchAll("
        SELECT
            asset_id, asset_type, asset_name, thumbnail_url,
            SUM(impressions) as impressions,
            SUM(reach)       as reach,
            SUM(clicks)      as clicks,
            SUM(spend)       as spend,
            SUM(purchases)   as purchases,
            SUM(leads)       as leads,
            SUM(revenue)     as revenue,
            ROUND(SUM(spend)   / NULLIF(SUM(purchases), 0), 2) as cpa,
            ROUND(SUM(spend)   / NULLIF(SUM(leads), 0), 2)     as cpl,
            ROUND(SUM(revenue) / NULLIF(SUM(spend), 0), 2)     as roas
        FROM ad_creative_assets
        WHERE ad_id = ?
        GROUP BY asset_id, asset_type, asset_name, thumbnail_url
        ORDER BY {$sort} DESC, impressions DESC
    ", [$adId]);

    echo json_encode(['success'=>true, 'assets'=>$assets, 'sort'=>$sort], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_perfil') {
    // Perfil de audiência + projeção de vendas até o fim da campanha.
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) { echo json_encode(['error'=>'Sem acesso']); exit; }

    $ev = $db->fetch("SELECT name, date_event, date_campaign_start, date_campaign_end FROM events WHERE id=?", [$eventId]);
    if (!$ev) { echo json_encode(['error'=>'Evento não encontrado']); exit; }

    // ── perfil (breakdowns) ──
    $perfil = [];
    try {
        foreach ($db->fetchAll("SELECT tipo, valor, impressions, reach, clicks, spend, purchases
                                FROM event_audience WHERE event_id=? ORDER BY impressions DESC", [$eventId]) as $r) {
            $perfil[$r['tipo']][] = [
                'valor'       => $r['valor'],
                'impressions' => (int)$r['impressions'],
                'reach'       => (int)$r['reach'],
                'clicks'      => (int)$r['clicks'],
                'spend'       => (float)$r['spend'],
                'purchases'   => (int)$r['purchases'],
            ];
        }
    } catch (Exception $e) {}

    // ── projeção de vendas ──
    $campaignIds = array_column($db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$eventId]), 'campaign_id');
    $proj = null;
    if ($campaignIds) {
        $ph = implode(',', array_fill(0, count($campaignIds), '?'));
        $tot = $db->fetch("SELECT COALESCE(SUM(purchases),0) compras, COALESCE(SUM(revenue),0) receita,
                                  COALESCE(SUM(spend),0) gasto
                           FROM campaign_snapshots WHERE campaign_id IN ($ph)", $campaignIds);
        $r7 = $db->fetch("SELECT COALESCE(SUM(purchases),0) compras, COUNT(DISTINCT snapshot_date) dias
                          FROM campaign_snapshots WHERE campaign_id IN ($ph) AND snapshot_date >= ?",
                          array_merge($campaignIds, [date('Y-m-d', strtotime('-6 days'))]));

        // vendas totais lançadas (se houver) dão a base real, incluindo orgânico
        $vendasTot = null; $melhorDia = null;
        try {
            $v = $db->fetch("SELECT SUM(total_sales) tot FROM event_daily_sales WHERE event_id=?", [$eventId]);
            if ($v && (int)$v['tot'] > 0) $vendasTot = (int)$v['tot'];
            $md = $db->fetch("SELECT sale_date, total_sales FROM event_daily_sales
                              WHERE event_id=? ORDER BY total_sales DESC LIMIT 1", [$eventId]);
            if ($md) $melhorDia = ['data' => $md['sale_date'], 'qtd' => (int)$md['total_sales']];
        } catch (Exception $e) {}

        $compras = $vendasTot ?: (int)$tot['compras'];
        $ticket  = (int)$tot['compras'] > 0 ? (float)$tot['receita'] / (int)$tot['compras'] : 0;
        $receita = $vendasTot ? $vendasTot * $ticket : (float)$tot['receita'];

        // ritmo dos últimos 7 dias
        $diasR7 = max(1, (int)$r7['dias']);
        $ritmoAds = (int)$r7['compras'] / $diasR7;
        $ritmo = $ritmoAds;
        try {
            $v7 = $db->fetch("SELECT COALESCE(SUM(total_sales),0) t, COUNT(*) d FROM event_daily_sales
                              WHERE event_id=? AND sale_date >= ?", [$eventId, date('Y-m-d', strtotime('-6 days'))]);
            if ($v7 && (int)$v7['d'] > 0) $ritmo = (int)$v7['t'] / max(1, (int)$v7['d']);
        } catch (Exception $e) {}

        // dias até o fim da venda (data do evento)
        $fim = $ev['date_event'] ?: $ev['date_campaign_end'];
        $diasRest = $fim ? max(0, (int)floor((strtotime($fim) - strtotime(date('Y-m-d'))) / 86400)) : 0;

        // Curva de venda: concentra na reta final. Calibrada com os eventos
        // encerrados desta base — a última semana costuma responder por ~45%.
        $fatorFinal = 1.9;
        try {
            $hist = $db->fetch("SELECT COUNT(*) n FROM events WHERE status <> 'active'");
            $nHist = (int)($hist['n'] ?? 0);
        } catch (Exception $e) { $nHist = 0; }

        $aMais = $ritmo * $diasRest * ($diasRest <= 10 ? $fatorFinal : 1.25);
        $projIngressos = (int)round($compras + $aMais);
        $projReceita   = $ticket > 0 ? $projIngressos * $ticket : 0;

        $proj = [
            'ja_vendido'       => $compras,
            'ja_receita'       => round($receita, 2),
            'ticket'           => round($ticket, 2),
            'ritmo_dia'        => round($ritmo, 1),
            'dias_restantes'   => $diasRest,
            'proj_ingressos'   => $projIngressos,
            'proj_receita'     => round($projReceita, 2),
            'faixa_min'        => (int)round($compras + $ritmo * $diasRest * 0.8),
            'faixa_max'        => (int)round($compras + $ritmo * $diasRest * ($diasRest <= 10 ? 2.4 : 1.7)),
            'melhor_dia'       => $melhorDia,
            'eventos_base'     => $nHist,
            'tem_lancamento'   => $vendasTot !== null,
        ];
    }

    echo json_encode(['success'=>true, 'perfil'=>$perfil, 'projecao'=>$proj,
                      'evento'=>['nome'=>$ev['name'], 'data'=>$ev['date_event']]], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_simulacao') {
    // Base para a simulação de meta: CPA/ticket do histórico inteiro do evento,
    // fatia orgânica (quando há vendas totais lançadas) e ritmo recente.
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) { echo json_encode(['error'=>'Sem acesso']); exit; }

    $ev = $db->fetch("SELECT name, date_event, budget, date_campaign_start FROM events WHERE id=?", [$eventId]);
    if (!$ev) { echo json_encode(['error'=>'Evento não encontrado']); exit; }

    $campaignIds = array_column($db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$eventId]), 'campaign_id');
    if (!$campaignIds) { echo json_encode(['error'=>'Vincule campanhas ao evento para simular.']); exit; }
    $ph = implode(',', array_fill(0, count($campaignIds), '?'));

    // Histórico completo — usa campaign_snapshots, a MESMA fonte do Budget/projeção.
    // (campaign_conversion_snapshots guarda só os últimos 32 dias e deixava o
    //  gasto da simulação menor que o da aba Performance.)
    $h = $db->fetch("SELECT COALESCE(SUM(spend),0) spend, COALESCE(SUM(purchases),0) compras,
                            COALESCE(SUM(revenue),0) receita, COALESCE(SUM(leads),0) leads,
                            COUNT(DISTINCT CASE WHEN spend > 0 THEN snapshot_date END) dias
                     FROM campaign_snapshots WHERE campaign_id IN ($ph)", $campaignIds);

    // últimos 7 dias (para comparar o momento com a média)
    $r7 = $db->fetch("SELECT COALESCE(SUM(spend),0) spend, COALESCE(SUM(purchases),0) compras
                      FROM campaign_snapshots
                      WHERE campaign_id IN ($ph) AND snapshot_date >= ?",
                      array_merge($campaignIds, [date('Y-m-d', strtotime('-6 days'))]));

    // alcance/frequência (indicam saturação e entram no cenário conservador)
    $fr = null;
    try {
        $fr = $db->fetch("SELECT COALESCE(SUM(reach),0) reach, COALESCE(SUM(impressions),0) impr
                          FROM ad_creatives WHERE campaign_id IN ($ph)", $campaignIds);
    } catch (Exception $e) {}

    $spend   = (float)$h['spend'];
    $compras = (int)$h['compras'];
    $receita = (float)$h['receita'];
    $cpa     = $compras > 0 ? $spend / $compras : 0;
    $ticket  = $compras > 0 ? $receita / $compras : 0;
    $roas    = $spend > 0 ? $receita / $spend : 0;
    $cpa7    = (int)$r7['compras'] > 0 ? (float)$r7['spend'] / (int)$r7['compras'] : 0;
    $freq    = ($fr && (int)$fr['reach'] > 0) ? (float)$fr['impr'] / (int)$fr['reach'] : 0;

    // vendas totais lançadas → fatia que vem do Ads
    $pctAds = null; $vendasTotais = null;
    try {
        $v = $db->fetch("SELECT SUM(total_sales) tot, COUNT(*) dias FROM event_daily_sales WHERE event_id=?", [$eventId]);
        if ($v && (int)$v['dias'] > 0 && (int)$v['tot'] > 0) {
            $vendasTotais = (int)$v['tot'];
            $pctAds = min(100, round($compras / $vendasTotais * 100, 1));
        }
    } catch (Exception $e) {}

    $diasRestantes = $ev['date_event'] ? max(0, (int)floor((strtotime($ev['date_event']) - strtotime(date('Y-m-d'))) / 86400)) : null;
    $gastoDiario   = (int)$h['dias'] > 0 ? $spend / (int)$h['dias'] : 0;

    echo json_encode([
        'success' => true,
        'evento'  => ['nome' => $ev['name'], 'data' => $ev['date_event'], 'budget' => (float)$ev['budget']],
        'base'    => [
            'gasto'          => round($spend, 2),
            'compras'        => $compras,
            'receita'        => round($receita, 2),
            'cpa'            => round($cpa, 2),
            'cpa_7d'         => round($cpa7, 2),
            'ticket'         => round($ticket, 2),
            'roas'           => round($roas, 2),
            'dias_rodando'   => (int)$h['dias'],
            'gasto_diario'   => round($gastoDiario, 2),
            'frequencia'     => round($freq, 2),
            'vendas_totais'  => $vendasTotais,
            'pct_ads'        => $pctAds,
            'dias_restantes' => $diasRestantes,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list_daily_sales') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) { echo json_encode(['error'=>'Sem acesso']); exit; }

    $campaignIds = array_column($db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$eventId]), 'campaign_id');

    // vendas do Ads por dia (compras atribuídas às campanhas do evento)
    $ads = [];
    if ($campaignIds) {
        $ph = implode(',', array_fill(0, count($campaignIds), '?'));
        try {
            // campaign_snapshots cobre todo o período do evento (a tabela de
            // conversões guarda só 32 dias e deixava dias antigos sem o Ads)
            foreach ($db->fetchAll("
                SELECT snapshot_date, SUM(purchases) p, SUM(revenue) r, SUM(spend) s
                FROM campaign_snapshots
                WHERE campaign_id IN ($ph) GROUP BY snapshot_date", $campaignIds) as $x) {
                $ads[$x['snapshot_date']] = ['ads' => (int)$x['p'], 'rev' => (float)$x['r'], 'spend' => (float)$x['s']];
            }
        } catch (Exception $e) {}
    }

    $lancados = [];
    try {
        foreach ($db->fetchAll("SELECT sale_date, total_sales, revenue, notes
                                FROM event_daily_sales WHERE event_id=? ORDER BY sale_date DESC", [$eventId]) as $x) {
            $lancados[$x['sale_date']] = $x;
        }
    } catch (Exception $e) {}

    // une os dois lados: dias lançados + dias com venda no Ads
    $datas = array_unique(array_merge(array_keys($lancados), array_keys($ads)));
    rsort($datas);

    $linhas = []; $somaTotal = 0; $somaAds = 0;
    foreach ($datas as $d) {
        $tot = isset($lancados[$d]) ? (int)$lancados[$d]['total_sales'] : null;
        $a   = $ads[$d]['ads'] ?? 0;
        $org = ($tot !== null) ? max(0, $tot - $a) : null;
        $linhas[] = [
            'data'     => $d,
            'total'    => $tot,
            'ads'      => $a,
            'organico' => $org,
            'pct_ads'  => ($tot > 0) ? round($a / $tot * 100, 1) : null,
            'receita'  => isset($lancados[$d]) ? $lancados[$d]['revenue'] : null,
            'gasto'    => $ads[$d]['spend'] ?? 0,
            'obs'      => $lancados[$d]['notes'] ?? null,
            'lancado'  => isset($lancados[$d]),
        ];
        if ($tot !== null) { $somaTotal += $tot; $somaAds += $a; }
    }

    echo json_encode([
        'success' => true,
        'linhas'  => $linhas,
        'totais'  => [
            'total'    => $somaTotal,
            'ads'      => $somaAds,
            'organico' => max(0, $somaTotal - $somaAds),
            'pct_ads'  => $somaTotal > 0 ? round($somaAds / $somaTotal * 100, 1) : null,
            'dias'     => count(array_filter($linhas, function ($l) { return $l['lancado']; })),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_daily_sales') {
    $eventId = intval($body['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) { echo json_encode(['error'=>'Sem acesso']); exit; }

    // Aceita um dia ou vários (cadastro em massa)
    $itens = $body['itens'] ?? null;
    if ($itens === null) {
        $itens = [[
            'data'    => $body['data'] ?? '',
            'total'   => $body['total'] ?? 0,
            'receita' => $body['receita'] ?? null,
            'obs'     => $body['obs'] ?? null,
        ]];
    }
    if (!is_array($itens) || !$itens) { echo json_encode(['error'=>'Nada para salvar']); exit; }
    if (count($itens) > 400) { echo json_encode(['error'=>'Máximo de 400 linhas por vez']); exit; }

    $ok = 0; $erros = [];
    foreach ($itens as $it) {
        $d = trim((string)($it['data'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $erros[] = ($d ?: '(vazio)') . ': data inválida'; continue; }
        $tot = (int)($it['total'] ?? 0);
        if ($tot < 0) { $erros[] = $d . ': valor negativo'; continue; }
        try {
            $db->execute("INSERT INTO event_daily_sales (event_id, sale_date, total_sales, revenue, notes, created_by)
                          VALUES (?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE total_sales=VALUES(total_sales),
                              revenue=VALUES(revenue), notes=VALUES(notes)",
                [$eventId, $d, $tot,
                 ($it['receita'] ?? '') !== '' && $it['receita'] !== null ? (float)$it['receita'] : null,
                 mb_substr((string)($it['obs'] ?? ''), 0, 255) ?: null, $uid]);
            $ok++;
        } catch (Exception $e) { $erros[] = $d . ': ' . $e->getMessage(); }
    }
    echo json_encode(['success'=>true, 'salvos'=>$ok, 'erros'=>$erros], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete_daily_sales') {
    $eventId = intval($body['event_id'] ?? 0);
    $d = trim((string)($body['data'] ?? ''));
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) { echo json_encode(['error'=>'Sem acesso']); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { echo json_encode(['error'=>'Data inválida']); exit; }
    try {
        $db->execute("DELETE FROM event_daily_sales WHERE event_id=? AND sale_date=?", [$eventId, $d]);
        echo json_encode(['success'=>true]);
    } catch (Exception $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

if ($action === 'get_event_conversions') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }
    $event = $db->fetch("SELECT * FROM events WHERE id=?", [$eventId]);
    if (!$event) { echo json_encode(['error'=>'Evento não encontrado']); exit; }

    $campaignIds = array_column(
        $db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$eventId]), 'campaign_id');
    if (empty($campaignIds)) {
        echo json_encode(['success'=>true, 'no_campaigns'=>true, 'daily'=>[], 'totals'=>null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Período selecionado na aba (padrão: campanha inteira)
    $campStart = $event['date_campaign_start'] ?: date('Y-m-d', strtotime($event['created_at']));
    $periodKey = (string)($body['period'] ?? $_GET['period'] ?? 'tudo');
    $dateEnd   = date('Y-m-d');
    switch ($periodKey) {
        case 'custom':
            // Intervalo escolhido pelo usuário
            $ini = $body['date_start'] ?? $_GET['date_start'] ?? '';
            $fim = $body['date_end']   ?? $_GET['date_end']   ?? '';
            $val = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : null; };
            $ini = $val($ini); $fim = $val($fim);
            if (!$ini || !$fim) { echo json_encode(['error' => 'Informe as duas datas do período.']); exit; }
            if ($ini > $fim) { $tmp = $ini; $ini = $fim; $fim = $tmp; }
            if ($fim > $dateEnd) $fim = $dateEnd;          // não busca no futuro
            if ($ini < $campStart) $ini = $campStart;      // nem antes da campanha
            $dateStart = $ini; $dateEnd = $fim;
            break;
        case 'hoje':  $dateStart = $dateEnd = date('Y-m-d'); break;
        case 'ontem': $dateStart = $dateEnd = date('Y-m-d', strtotime('-1 day')); break;
        case '3': case '7': case '15': case '30':
            $dateStart = date('Y-m-d', strtotime('-' . ((int)$periodKey - 1) . ' days'));
            if ($dateStart < $campStart) $dateStart = $campStart;
            break;
        default: $periodKey = 'tudo'; $dateStart = $campStart;
    }
    $ph = implode(',', array_fill(0, count($campaignIds), '?'));
    $params = array_merge($campaignIds, [$dateStart, $dateEnd]);

    $daily = $db->fetchAll("
        SELECT snapshot_date,
               SUM(views) views, SUM(add_to_cart) add_to_cart, SUM(checkout) checkout,
               SUM(purchases) purchases, SUM(leads) leads,
               SUM(revenue) revenue, SUM(spend) spend
        FROM campaign_conversion_snapshots
        WHERE campaign_id IN ($ph) AND snapshot_date BETWEEN ? AND ?
        GROUP BY snapshot_date ORDER BY snapshot_date DESC", $params);

    $t = ['views'=>0,'add_to_cart'=>0,'checkout'=>0,'purchases'=>0,'leads'=>0,'revenue'=>0.0,'spend'=>0.0];
    foreach ($daily as $d) foreach ($t as $k => $v) $t[$k] += $d[$k];
    $t['roas'] = $t['spend'] > 0 ? round($t['revenue'] / $t['spend'], 2) : 0;
    $t['cpa']  = $t['purchases'] > 0 ? round($t['spend'] / $t['purchases'], 2) : 0;
    $t['cpl']  = $t['leads'] > 0 ? round($t['spend'] / $t['leads'], 2) : 0;
    $t['cpv']  = $t['views'] > 0 ? round($t['spend'] / $t['views'], 4) : 0;

    // por campanha do evento
    $byCampaign = $db->fetchAll("
        SELECT campaign_id, MAX(campaign_name) campaign_name,
               SUM(views) views, SUM(add_to_cart) add_to_cart, SUM(checkout) checkout,
               SUM(purchases) purchases, SUM(leads) leads, SUM(revenue) revenue, SUM(spend) spend
        FROM campaign_conversion_snapshots
        WHERE campaign_id IN ($ph) AND snapshot_date BETWEEN ? AND ?
        GROUP BY campaign_id ORDER BY SUM(spend) DESC", $params);

    // Dias com veiculação e status real, para não recomendar sobre campanha nova ou parada
    $diasPorCamp = [];
    foreach ($db->fetchAll("
        SELECT campaign_id, COUNT(DISTINCT snapshot_date) dias
        FROM campaign_conversion_snapshots
        WHERE campaign_id IN ($ph) AND snapshot_date BETWEEN ? AND ? AND spend > 0
        GROUP BY campaign_id", $params) as $r) {
        $diasPorCamp[$r['campaign_id']] = (int)$r['dias'];
    }
    // Alcance e engajamento por campanha — campanhas de awareness/engagement
    // não se julgam por lead nem por compra.
    $alcancePorCamp = [];
    try {
        foreach ($db->fetchAll("
            SELECT campaign_id,
                   SUM(reach) reach, SUM(impressions) impressions,
                   SUM(engagement) engagement, SUM(video_views) video_views, SUM(spend) spend
            FROM ad_creatives
            WHERE campaign_id IN ($ph) AND date_start <= ? AND date_end >= ?
            GROUP BY campaign_id", array_merge($campaignIds, [$dateEnd, $dateStart])) as $r) {
            $alcancePorCamp[$r['campaign_id']] = [
                'reach'       => (int)$r['reach'],
                'impressions' => (int)$r['impressions'],
                'engagement'  => (int)$r['engagement'],
                'video_views' => (int)$r['video_views'],
                'spend'       => (float)$r['spend'],
            ];
        }
    } catch (Exception $e) {}

    $objetivoPorCamp = [];
    $statusPorCamp = [];
    foreach ($db->fetchAll("SELECT campaign_id, objective, status, effective_status, stop_time
                            FROM event_campaigns WHERE event_id = ?", [$eventId]) as $r) {
        $objetivoPorCamp[$r['campaign_id']] = strtoupper((string)$r['objective']);
        list($lbl, $ativa) = statusReal($r);
        $statusPorCamp[$r['campaign_id']] = ['label' => $lbl, 'ativa' => $ativa];
    }

    // Melhor dia: prioriza compras; sem compras, leads; sem leads, faturamento
    $bestDay = null;
    foreach ($daily as $d) {
        $score = ((int)$d['purchases'] * 1000000) + ((int)$d['leads'] * 1000) + (float)$d['revenue'];
        if ($score > 0 && ($bestDay === null || $score > $bestDay['_score'])) {
            $bestDay = $d; $bestDay['_score'] = $score;
        }
    }
    // Melhor campanha: entre as que gastaram, a de melhor ROAS; sem receita, menor CPL
    $bestCampaign = null; $bestMetric = null;
    // Se o evento é predominantemente de leads, o melhor é o menor CPL, não o maior ROAS
    $eventoDeLeads = ($t['leads'] > 0 && $t['purchases'] < $t['leads'] * 0.2);
    foreach ($byCampaign as $c) {
        if ((float)$c['spend'] <= 0) continue;
        if (!$eventoDeLeads && (float)$c['revenue'] > 0) {
            $m = (float)$c['revenue'] / (float)$c['spend'];
            if ($bestMetric === null || $bestMetric[0] !== 'roas' || $m > $bestMetric[1]) {
                if ($bestMetric === null || $bestMetric[0] !== 'roas') { $bestMetric = ['roas', $m]; $bestCampaign = $c; }
                elseif ($m > $bestMetric[1]) { $bestMetric = ['roas', $m]; $bestCampaign = $c; }
            }
        } elseif ($bestMetric === null && (int)$c['leads'] > 0) {
            $m = (float)$c['spend'] / (int)$c['leads'];
            if ($bestCampaign === null || $m < ($bestMetric[1] ?? PHP_FLOAT_MAX)) { $bestMetric = ['cpl', $m]; $bestCampaign = $c; }
        }
    }

    // ── RECOMENDAÇÕES ────────────────────────────────────────────
    // Benchmark é o próprio evento (o que é "bom ROAS" muda de evento pra evento).
    // Campanha de venda é julgada por ROAS/CPA; campanha de lead, por CPL.
    // Carência: campanha escalada há pouco não volta a ser sugerida —
    // precisa de alguns dias para a Meta reestabilizar a entrega.
    $DIAS_CARENCIA = 3;
    $escaladaRecente = [];
    try {
        foreach ($db->fetchAll("
            SELECT campaign_id, MAX(created_at) AS quando,
                   TIMESTAMPDIFF(HOUR, MAX(created_at), NOW()) AS horas
            FROM campaign_actions
            WHERE event_id = ? AND action = 'budget' AND result = 'ok'
              AND new_budget > IFNULL(previous_budget, 0)
              AND created_at > NOW() - INTERVAL ? DAY
            GROUP BY campaign_id", [$eventId, $DIAS_CARENCIA]) as $a) {
            $escaladaRecente[$a['campaign_id']] = [
                'quando' => $a['quando'],
                'horas'  => (int)$a['horas'],
            ];
        }
    } catch (Exception $e) {}

    $MIN_CONV = 10;   // conversões mínimas para conclusão confiável
    $MIN_DIAS = 3;    // dias mínimos rodando
    $MIN_GASTO = 50;  // gasto mínimo para opinar

    // Benchmarks separados: alcance julga CPM; engajamento, custo por engajamento
    $somaAlc = ['spend'=>0,'reach'=>0,'impr'=>0]; $somaEng = ['spend'=>0,'eng'=>0];
    foreach ($byCampaign as $c) {
        $obj  = strtoupper($objetivoPorCamp[$c['campaign_id']] ?? '');
        $nome = mb_strtoupper($c['campaign_name']);
        $a    = $alcancePorCamp[$c['campaign_id']] ?? null;
        if (!$a) continue;
        if (in_array($obj, ['OUTCOME_AWARENESS','REACH','BRAND_AWARENESS']) || preg_match('/\bALCANCE\b/u', $nome)) {
            $somaAlc['spend'] += $a['spend']; $somaAlc['reach'] += $a['reach']; $somaAlc['impr'] += $a['impressions'];
        } elseif (in_array($obj, ['OUTCOME_ENGAGEMENT','POST_ENGAGEMENT','VIDEO_VIEWS']) || preg_match('/\bENGAJAMENTO\b/u', $nome)) {
            $somaEng['spend'] += $a['spend']; $somaEng['eng'] += $a['engagement'];
        }
    }
    $cpmMedio = $somaAlc['impr'] > 0 ? $somaAlc['spend'] / $somaAlc['impr'] * 1000 : 0;
    $cpeMedio = $somaEng['eng']  > 0 ? $somaEng['spend'] / $somaEng['eng'] : 0;

    $roasMedio = $t['spend'] > 0 ? $t['revenue'] / $t['spend'] : 0;
    $cpaMedio  = $t['purchases'] > 0 ? $t['spend'] / $t['purchases'] : 0;
    $cplMedio  = $t['leads'] > 0 ? $t['spend'] / $t['leads'] : 0;

    $recs = ['escalar'=>[], 'pausar'=>[], 'aguardando'=>[], 'observar'=>[], 'manter'=>[]];
    foreach ($byCampaign as $c) {
        $cid   = $c['campaign_id'];
        $spend = (float)$c['spend'];
        if ($spend < $MIN_GASTO) continue;

        $st = $statusPorCamp[$cid] ?? ['label'=>'ATIVA', 'ativa'=>true];
        if (!$st['ativa']) continue; // não se opina sobre o que não está entregando

        $dias   = $diasPorCamp[$cid] ?? 0;
        $purch  = (int)$c['purchases'];
        $leads  = (int)$c['leads'];
        $rev    = (float)$c['revenue'];

        // Qual métrica julga esta campanha? Ordem de confiança:
        //   1) objetivo configurado na Meta   2) prefixo/nome  3) dados
        // Campanha de LEADS não é julgada por ROAS mesmo que tenha alguma compra avulsa.
        $obj  = $objetivoPorCamp[$cid] ?? '';
        $nome = mb_strtoupper($c['campaign_name']);

        // tipo: venda | lead | alcance | engajamento
        if (in_array($obj, ['OUTCOME_AWARENESS', 'REACH', 'BRAND_AWARENESS'])) {
            $tipo = 'alcance';
        } elseif (in_array($obj, ['OUTCOME_ENGAGEMENT', 'POST_ENGAGEMENT', 'PAGE_LIKES', 'VIDEO_VIEWS'])) {
            $tipo = 'engajamento';
        } elseif (in_array($obj, ['OUTCOME_LEADS', 'LEAD_GENERATION', 'MESSAGES'])) {
            $tipo = 'lead';
        } elseif (in_array($obj, ['OUTCOME_SALES', 'CONVERSIONS', 'PRODUCT_CATALOG_SALES'])) {
            $tipo = 'venda';
        } elseif (preg_match('/\bALCANCE\b/u', $nome)) {
            $tipo = 'alcance';
        } elseif (preg_match('/\bENGAJAMENTO\b|\bVIEW/u', $nome)) {
            $tipo = 'engajamento';
        } elseif (preg_match('/\bLEADS?\b|\bCADASTRO|\bQUIZ\b|\bWHATS/u', $nome)) {
            $tipo = 'lead';
        } elseif (preg_match('/\bVENDAS?\b|\bCONVERS/u', $nome)) {
            $tipo = 'venda';
        } else {
            $tipo = ($purch > 0 || $rev > 0) ? 'venda' : 'lead';
        }
        // O nome pode dizer o que a campanha realmente busca, acima do objetivo genérico.
        // Ex.: "[ALCANCE] SÓ LEADS" é comprada como alcance, mas o alvo é lead.
        if (in_array($tipo, ['alcance','engajamento']) && preg_match('/\bLEADS?\b|\bCADASTRO|\bQUIZ\b/u', $nome) && $leads > 0) {
            $tipo = 'lead';
        }
        $ehVenda = ($tipo === 'venda');
        $conv    = $ehVenda ? $purch : $leads;

        $item = [
            'campaign_id' => $cid, 'campaign_name' => $c['campaign_name'],
            'spend' => round($spend, 2), 'purchases' => $purch, 'leads' => $leads,
            'revenue' => round($rev, 2), 'dias' => $dias,
            'tipo' => $tipo,
            'objetivo' => $obj,
        ];

        // Escalada nos últimos dias: sai das sugestões até dar tempo de avaliar
        if (isset($escaladaRecente[$cid])) {
            $h = $escaladaRecente[$cid]['horas'];
            $falta = max(1, (int)ceil(($DIAS_CARENCIA * 24 - $h) / 24));
            $item['metrica'] = 'aguardando';
            $item['escalada_em'] = $escaladaRecente[$cid]['quando'];
            $item['motivo'] = 'Orçamento aumentado há ' . ($h < 24 ? $h . 'h' : (int)floor($h / 24) . ' dia(s)')
                . '. Reavaliação em ' . $falta . ' dia(s) — a Meta precisa reestabilizar a entrega antes de comparar.';
            $recs['aguardando'][] = $item;
            continue;
        }

        if ($tipo === 'alcance' || $tipo === 'engajamento') {
            $a = $alcancePorCamp[$cid] ?? null;
            if (!$a || ($tipo === 'alcance' ? $a['impressions'] : $a['engagement']) <= 0) {
                // Sem dado da métrica certa: NUNCA sugerir pausa por lead/compra
                $item['metrica'] = $tipo === 'alcance' ? 'sem dados de alcance' : 'sem dados de engajamento';
                $item['motivo']  = 'Campanha de ' . $tipo . ' — não se avalia por lead nem por compra. '
                    . 'Ainda não há ' . ($tipo === 'alcance' ? 'alcance/impressões' : 'engajamento')
                    . ' sincronizado para julgar (rode "Sincronizar criativos").';
                $recs['observar'][] = $item;
                continue;
            }

            if ($tipo === 'alcance') {
                $cpm = $a['impressions'] > 0 ? $a['spend'] / $a['impressions'] * 1000 : 0;
                $cpAlc = $a['reach'] > 0 ? $a['spend'] / $a['reach'] : 0;
                $item['reach'] = $a['reach']; $item['impressions'] = $a['impressions'];
                $item['cpm'] = round($cpm, 2);
                $item['metrica'] = 'CPM R$ ' . number_format($cpm, 2, ',', '.');
                $item['detalhe'] = number_format($a['reach'], 0, ',', '.') . ' pessoas alcançadas'
                    . ($cpAlc > 0 ? ' · R$ ' . number_format($cpAlc, 3, ',', '.') . ' por pessoa' : '');
                $ref = $cpmMedio; $valor = $cpm; $nomeRef = 'CPM médio';
            } else {
                $cpe = $a['engagement'] > 0 ? $a['spend'] / $a['engagement'] : 0;
                $item['engagement'] = $a['engagement'];
                $item['cpe'] = round($cpe, 2);
                $item['metrica'] = 'R$ ' . number_format($cpe, 2, ',', '.') . '/engaj.';
                $item['detalhe'] = number_format($a['engagement'], 0, ',', '.') . ' engajamentos';
                $ref = $cpeMedio; $valor = $cpe; $nomeRef = 'custo médio por engajamento';
            }

            if ($dias < $MIN_DIAS) {
                $item['motivo'] = 'Rodando há ' . $dias . ' dia(s) — deixe amadurecer antes de decidir.';
                $recs['observar'][] = $item;
            } elseif ($ref <= 0) {
                $item['motivo'] = 'Sem outra campanha de ' . $tipo . ' no evento para comparar. ' . $item['detalhe'] . '.';
                $recs['observar'][] = $item;
            } elseif ($valor <= $ref * 0.7) {
                $item['motivo'] = $item['metrica'] . ' contra ' . $nomeRef . ' de R$ '
                    . number_format($ref, 2, ',', '.') . ' no evento — está entregando mais barato. ' . $item['detalhe'] . '.';
                $recs['escalar'][] = $item;
            } elseif ($valor >= $ref * 2) {
                $item['motivo'] = $item['metrica'] . ' — o dobro do ' . $nomeRef . ' do evento (R$ '
                    . number_format($ref, 2, ',', '.') . '). ' . $item['detalhe'] . '.';
                $recs['pausar'][] = $item;
            } else {
                $item['motivo'] = 'Dentro da média de ' . $tipo . ' do evento. ' . $item['detalhe'] . '.';
                $recs['manter'][] = $item;
            }
        } elseif ($ehVenda) {
            $roas = $spend > 0 ? $rev / $spend : 0;
            $cpa  = $purch > 0 ? $spend / $purch : 0;
            $item['roas'] = round($roas, 2);
            $item['cpa']  = round($cpa, 2);
            $item['metrica'] = 'ROAS ' . number_format($roas, 2, ',', '.') . 'x';

            if ($purch === 0 && $cpaMedio > 0 && $spend >= $cpaMedio * 2 && $dias >= $MIN_DIAS) {
                $item['motivo'] = 'Gastou R$ ' . number_format($spend, 2, ',', '.')
                    . ' sem nenhuma compra — mais que o dobro do CPA médio do evento (R$ '
                    . number_format($cpaMedio, 2, ',', '.') . ').';
                $recs['pausar'][] = $item;
            } elseif ($dias < $MIN_DIAS || $conv < $MIN_CONV) {
                $item['motivo'] = 'Ainda sem dados suficientes (' . $conv . ' compra(s) em ' . $dias
                    . ' dia(s)). Deixe rodar antes de decidir.';
                $recs['observar'][] = $item;
            } elseif ($roasMedio > 0 && $roas >= $roasMedio * 1.5) {
                $item['motivo'] = 'ROAS ' . number_format($roas, 2, ',', '.') . 'x contra '
                    . number_format($roasMedio, 2, ',', '.') . 'x de média do evento, com '
                    . $purch . ' compras. É a que mais entrega retorno.';
                $recs['escalar'][] = $item;
            } elseif ($roasMedio > 0 && $roas < $roasMedio * 0.5) {
                $item['motivo'] = 'ROAS ' . number_format($roas, 2, ',', '.') . 'x contra '
                    . number_format($roasMedio, 2, ',', '.') . 'x de média, já com ' . $purch
                    . ' compras — não é falta de dado.';
                $recs['pausar'][] = $item;
            } else {
                $item['motivo'] = 'Performance na média do evento.';
                $recs['manter'][] = $item;
            }
        } else {
            $cpl = $leads > 0 ? $spend / $leads : 0;
            $item['cpl'] = round($cpl, 2);
            $item['metrica'] = $leads > 0 ? 'CPL R$ ' . number_format($cpl, 2, ',', '.') : 'sem leads';

            if ($leads === 0 && $cplMedio > 0 && $spend >= $cplMedio * 5 && $dias >= $MIN_DIAS) {
                $item['motivo'] = 'Gastou R$ ' . number_format($spend, 2, ',', '.')
                    . ' sem nenhum lead — 5× o CPL médio do evento (R$ '
                    . number_format($cplMedio, 2, ',', '.') . ').';
                $recs['pausar'][] = $item;
            } elseif ($dias < $MIN_DIAS || $conv < $MIN_CONV) {
                $item['motivo'] = 'Ainda sem dados suficientes (' . $conv . ' lead(s) em ' . $dias
                    . ' dia(s)). Deixe rodar antes de decidir.';
                $recs['observar'][] = $item;
            } elseif ($cplMedio > 0 && $cpl <= $cplMedio * 0.7) {
                $item['motivo'] = 'CPL R$ ' . number_format($cpl, 2, ',', '.') . ' contra R$ '
                    . number_format($cplMedio, 2, ',', '.') . ' de média do evento, com ' . $leads
                    . ' leads. Está comprando lead mais barato.';
                $recs['escalar'][] = $item;
            } elseif ($cplMedio > 0 && $cpl >= $cplMedio * 2) {
                $item['motivo'] = 'CPL R$ ' . number_format($cpl, 2, ',', '.') . ' — o dobro da média do evento (R$ '
                    . number_format($cplMedio, 2, ',', '.') . '), com volume suficiente para concluir.';
                $recs['pausar'][] = $item;
            } else {
                $item['motivo'] = 'Performance na média do evento.';
                $recs['manter'][] = $item;
            }
        }
    }
    usort($recs['escalar'], function($a,$b){ return $b['spend'] <=> $a['spend']; });
    usort($recs['pausar'],  function($a,$b){ return $b['spend'] <=> $a['spend']; });

    // Comparativo com as vendas totais lançadas manualmente (se houver)
    $vendasTot = null;
    try {
        $v = $db->fetch("SELECT SUM(total_sales) tot, COUNT(*) dias
                         FROM event_daily_sales
                         WHERE event_id=? AND sale_date BETWEEN ? AND ?", [$eventId, $dateStart, $dateEnd]);
        if ($v && (int)$v['dias'] > 0) {
            $tot = (int)$v['tot'];
            // usa a mesma fonte do restante do sistema para as compras do Ads no período
            $adsP = (int)$t['purchases'];
            try {
                $cs = $db->fetch("SELECT COALESCE(SUM(purchases),0) p FROM campaign_snapshots
                                  WHERE campaign_id IN ($ph) AND snapshot_date BETWEEN ? AND ?",
                                  array_merge($campaignIds, [$dateStart, $dateEnd]));
                if ($cs && (int)$cs['p'] > 0) $adsP = (int)$cs['p'];
            } catch (Exception $e) {}
            $vendasTot = [
                'total'    => $tot,
                'ads'      => $adsP,
                'organico' => max(0, $tot - $adsP),
                'pct_ads'  => $tot > 0 ? round($adsP / $tot * 100, 1) : null,
                'dias'     => (int)$v['dias'],
                'parcial'  => $tot < $adsP,   // lançamento incompleto
            ];
        }
    } catch (Exception $e) {}

    echo json_encode([
        'success'=>true, 'daily'=>$daily, 'totals'=>$t, 'by_campaign'=>$byCampaign,
        'vendas_totais'=>$vendasTot,
        'recomendacoes'=>$recs,
        'benchmark'=>['roas'=>round($roasMedio,2), 'cpa'=>round($cpaMedio,2), 'cpl'=>round($cplMedio,2),
                      'cpm'=>round($cpmMedio,2), 'cpe'=>round($cpeMedio,2)],
        'campaign_count'=>count($campaignIds), 'date_start'=>$dateStart, 'date_end'=>$dateEnd,
        'period'=>$periodKey, 'campaign_start'=>$campStart,
        'moedas'=> (function () use ($db, $eventId) {
            try {
                return array_column($db->fetchAll(
                    "SELECT DISTINCT a.currency FROM event_accounts ea
                     JOIN ad_accounts a ON a.account_id = ea.account_id
                     WHERE ea.event_id = ?", [$eventId]), 'currency');
            } catch (Exception $e) { return []; }
        })(),
        'ajuste'=> ($periodKey === 'custom' && isset($ini) && (
                      ($body['date_start'] ?? '') !== $dateStart || ($body['date_end'] ?? '') !== $dateEnd))
                   ? 'Período ajustado para o intervalo com dados: '
                     . date('d/m/Y', strtotime($dateStart)) . ' a ' . date('d/m/Y', strtotime($dateEnd))
                   : null,
        'best_day'=>$bestDay ? ['date'=>$bestDay['snapshot_date'], 'purchases'=>(int)$bestDay['purchases'],
                                'leads'=>(int)$bestDay['leads'], 'revenue'=>(float)$bestDay['revenue'],
                                'spend'=>(float)$bestDay['spend']] : null,
        'best_campaign'=>$bestCampaign ? ['id'=>$bestCampaign['campaign_id'], 'name'=>$bestCampaign['campaign_name'],
                                'metric'=>$bestMetric[0], 'value'=>round($bestMetric[1], 2),
                                'purchases'=>(int)$bestCampaign['purchases'], 'leads'=>(int)$bestCampaign['leads'],
                                'spend'=>(float)$bestCampaign['spend']] : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (in_array($action, ['list_report_links', 'create_report_link', 'toggle_report_link'], true)) {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }

    if ($action === 'create_report_link') {
        $label = trim($body['label'] ?? '');
        if ($label === '') {
            $ev = $db->fetch("SELECT name FROM events WHERE id=?", [$eventId]);
            $label = 'Relatório — ' . ($ev['name'] ?? 'evento');
        }
        $newToken = bin2hex(random_bytes(24));
        $db->execute("INSERT INTO report_tokens (event_id, token, label, active, created_by) VALUES (?,?,?,1,?)",
            [$eventId, $newToken, mb_substr($label, 0, 255), $uid]);
        echo json_encode(['success'=>true, 'token'=>$newToken], JSON_UNESCAPED_UNICODE); exit;
    }

    if ($action === 'toggle_report_link') {
        $id = intval($body['id'] ?? 0);
        $db->execute("UPDATE report_tokens SET active = IF(active=1,0,1) WHERE id=? AND event_id=?", [$id, $eventId]);
        echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE); exit;
    }

    $links = $db->fetchAll("SELECT id, token, label, active, created_at FROM report_tokens
                            WHERE event_id=? ORDER BY created_at DESC", [$eventId]);
    echo json_encode(['success'=>true, 'links'=>$links], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'get_campaign_budget') {
    if (!$isAdmin) { echo json_encode(['error'=>'Apenas administradores']); exit; }
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    $cid     = trim((string)($body['campaign_id'] ?? ''));
    if (!$eventId || !$cid || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }
    $ok = $db->fetch("SELECT 1 FROM event_campaigns WHERE event_id=? AND campaign_id=?", [$eventId, $cid]);
    if (!$ok) { echo json_encode(['error'=>'Campanha não pertence a este evento']); exit; }

    require_once __DIR__ . '/../includes/meta_api.php';
    $api = new MetaAPI(); $token = $api->getTokenValue(); $ver = metaApiVersion();
    if (!$token) { echo json_encode(['error'=>'Token não configurado']); exit; }

    $url = "https://graph.facebook.com/{$ver}/{$cid}?fields=id,name,daily_budget,lifetime_budget&access_token={$token}";
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL=>$url, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
    $raw = curl_exec($ch); curl_close($ch);
    $r = $raw !== false ? json_decode($raw, true) : null;
    if (!$r || isset($r['error'])) {
        echo json_encode(['error'=> $r['error']['message'] ?? 'Erro ao consultar a Meta']); exit;
    }

    // A Meta devolve na moeda da conta QUE RODA ESTA CAMPANHA — num evento com
    // contas em moedas diferentes, a primeira conta do evento não serve.
    list($moedaB, $taxaB) = FX::moedaDaCampanha($db, $cid, $eventId);

    $daily    = isset($r['daily_budget'])    ? (float)$r['daily_budget'] / 100 * $taxaB : null;
    $lifetime = isset($r['lifetime_budget']) ? (float)$r['lifetime_budget'] / 100 * $taxaB : null;

    if ($daily === null && $lifetime === null) {
        // CBO desligado: o orçamento está nos conjuntos, não na campanha
        echo json_encode(['success'=>true, 'campaign_id'=>$cid, 'name'=>$r['name'] ?? '',
            'budget'=>null, 'type'=>null,
            'aviso'=>'Esta campanha usa orçamento por conjunto (ABO). O aumento precisa ser feito no conjunto, pelo Gerenciador da Meta.'],
            JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success'=>true, 'campaign_id'=>$cid, 'name'=>$r['name'] ?? '',
        'budget'=> $daily !== null ? $daily : $lifetime,
        'type'  => $daily !== null ? 'daily' : 'lifetime',
        'currency' => $moedaB, 'fx_rate' => $taxaB], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'set_campaign_budget') {
    if (!$isAdmin) { echo json_encode(['error'=>'Apenas administradores podem alterar orçamento']); exit; }
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    $cid     = trim((string)($body['campaign_id'] ?? ''));
    $novo    = (float)($body['new_budget'] ?? 0);
    $tipo    = ($body['budget_type'] ?? 'daily') === 'lifetime' ? 'lifetime' : 'daily';
    $anterior= (float)($body['previous_budget'] ?? 0);

    if (!$eventId || !$cid || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }
    $ok = $db->fetch("SELECT campaign_name FROM event_campaigns WHERE event_id=? AND campaign_id=?", [$eventId, $cid]);
    if (!$ok) { echo json_encode(['error'=>'Campanha não pertence a este evento']); exit; }
    if ($novo <= 0) { echo json_encode(['error'=>'Valor inválido']); exit; }

    // Trava de segurança: nunca mais que o triplo do valor atual de uma vez
    if ($anterior > 0 && $novo > $anterior * 3) {
        echo json_encode(['error'=>'Aumento acima de 3× o orçamento atual. Faça em etapas ou ajuste pelo Gerenciador da Meta.']);
        exit;
    }

    require_once __DIR__ . '/../includes/meta_api.php';
    $api = new MetaAPI(); $token = $api->getTokenValue(); $ver = metaApiVersion();
    if (!$token) { echo json_encode(['error'=>'Token não configurado']); exit; }

    // O valor digitado está em BRL; a Meta espera na moeda da conta que roda
    // esta campanha. Errar a taxa aqui grava orçamento errado na Meta.
    list($moedaS, $taxaS) = FX::moedaDaCampanha($db, $cid, $eventId);
    $novoNaMoeda = $taxaS > 0 ? $novo / $taxaS : $novo;

    $campo = $tipo === 'lifetime' ? 'lifetime_budget' : 'daily_budget';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://graph.facebook.com/{$ver}/{$cid}",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([$campo => (int)round($novoNaMoeda * 100), 'access_token' => $token]),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch); $curlErr = curl_error($ch); curl_close($ch);
    $resp = $raw !== false ? json_decode($raw, true) : null;
    $sucesso = $resp && empty($resp['error']);
    $msg = $sucesso ? null : ($resp['error']['message'] ?? ($curlErr ?: 'Erro desconhecido'));

    try {
        $db->execute("INSERT INTO campaign_actions
            (event_id, campaign_id, campaign_name, action, previous_budget, new_budget, budget_type, reason, result, error_message, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$eventId, $cid, $ok['campaign_name'], 'budget', $anterior ?: null, $novo, $tipo,
             mb_substr((string)($body['reason'] ?? ''), 0, 500), $sucesso ? 'ok' : 'erro', $msg, $uid]);
    } catch (Exception $e) {}

    if (!$sucesso) { echo json_encode(['error'=>$msg]); exit; }
    echo json_encode(['success'=>true, 'novo'=>$novo, 'tipo'=>$tipo,
        'currency'=>$moedaS, 'enviado_na_moeda'=>round($novoNaMoeda, 2)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'apply_campaign_actions') {
    // Aplica pausa/reativação nas campanhas escolhidas pelo usuário.
    // Só admin, sempre com lista explícita — nunca decide sozinho o que mexer.
    if (!$isAdmin) { echo json_encode(['error'=>'Apenas administradores podem alterar campanhas']); exit; }
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }
    $itens = $body['items'] ?? [];
    if (!is_array($itens) || !$itens) { echo json_encode(['error'=>'Nada selecionado']); exit; }
    if (count($itens) > 50) { echo json_encode(['error'=>'Muitas campanhas de uma vez (máx. 50)']); exit; }

    require_once __DIR__ . '/../includes/meta_api.php';
    $api   = new MetaAPI();
    $token = $api->getTokenValue();
    $ver   = metaApiVersion();
    if (!$token) { echo json_encode(['error'=>'Token da Meta não configurado']); exit; }

    // Só aceita campanhas que pertencem a este evento
    $permitidas = array_column(
        $db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$eventId]), 'campaign_id');

    $resultados = [];
    foreach ($itens as $it) {
        $cid = trim((string)($it['campaign_id'] ?? ''));
        $act = ($it['action'] ?? '') === 'activate' ? 'activate' : 'pause';
        if (!$cid || !in_array($cid, $permitidas, true)) {
            $resultados[] = ['campaign_id'=>$cid, 'ok'=>false, 'erro'=>'Campanha não pertence a este evento'];
            continue;
        }

        $atual = $db->fetch("SELECT campaign_name, status FROM event_campaigns WHERE event_id=? AND campaign_id=?",
            [$eventId, $cid]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://graph.facebook.com/{$ver}/{$cid}",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'status'       => $act === 'activate' ? 'ACTIVE' : 'PAUSED',
                'access_token' => $token,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $resp = $raw !== false ? json_decode($raw, true) : null;

        $ok  = $resp && empty($resp['error']);
        $msg = $ok ? null : ($resp['error']['message'] ?? ($curlErr ?: 'Erro desconhecido'));

        try {
            $db->execute("INSERT INTO campaign_actions
                (event_id, campaign_id, campaign_name, action, previous_status, reason, result, error_message, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)",
                [$eventId, $cid, $atual['campaign_name'] ?? null, $act, $atual['status'] ?? null,
                 mb_substr((string)($it['reason'] ?? ''), 0, 500), $ok ? 'ok' : 'erro', $msg, $uid]);
        } catch (Exception $e) {}

        if ($ok) {
            $novoStatus = $act === 'activate' ? 'ACTIVE' : 'PAUSED';
            try {
                $db->execute("UPDATE event_campaigns SET status=?, effective_status=? WHERE event_id=? AND campaign_id=?",
                    [$novoStatus, $novoStatus, $eventId, $cid]);
            } catch (Exception $e) {}
        }

        $resultados[] = [
            'campaign_id'   => $cid,
            'campaign_name' => $atual['campaign_name'] ?? $cid,
            'action'        => $act,
            'ok'            => $ok,
            'erro'          => $msg,
        ];
        usleep(250000); // respeita o rate limit da Meta
    }

    $okN = count(array_filter($resultados, function($r){ return $r['ok']; }));
    echo json_encode(['success'=>true, 'aplicadas'=>$okN, 'total'=>count($resultados),
                      'resultados'=>$resultados], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'list_campaign_actions') {
    $eventId = intval($body['event_id'] ?? $_GET['event_id'] ?? 0);
    if (!$eventId || !hasEventAccess($db, $eventId, $uid, $isAdmin)) {
        echo json_encode(['error'=>'Sem acesso']); exit;
    }
    try {
        $acoes = $db->fetchAll("SELECT a.*, u.username FROM campaign_actions a
                                LEFT JOIN users u ON u.id = a.created_by
                                WHERE a.event_id = ? AND a.result = 'ok'
                                ORDER BY a.id DESC LIMIT 25", [$eventId]);
    } catch (Exception $e) { $acoes = []; }
    echo json_encode(['success'=>true, 'acoes'=>$acoes], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error'=>'Ação inválida'], JSON_UNESCAPED_UNICODE);
