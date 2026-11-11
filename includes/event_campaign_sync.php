<?php
// Serviço único de sincronização de gastos de campanhas vinculadas a evento.
require_once __DIR__ . '/meta_api.php';
require_once __DIR__ . '/fx.php';

function eventCampaignDateRange(array $event, $allowFutureStart = false) {
    $today = date('Y-m-d');
    $start = trim((string)($event['date_campaign_start'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        $start = date('Y-m-d', strtotime('-30 days'));
    }
    $configuredEnd = trim((string)($event['date_campaign_end'] ?? ''));
    $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', $configuredEnd) ? min($configuredEnd, $today) : $today;
    if ($start > $today && !$allowFutureStart) throw new RuntimeException('A data inicial do gasto ainda está no futuro.');
    // Para exibição, um evento futuro deve simplesmente ter uma janela vazia;
    // a sincronização real continua rejeitando datas invertidas.
    if ($end < $start && !($allowFutureStart && $start > $today)) {
        throw new RuntimeException('O fim do período de campanha é anterior ao início.');
    }
    return [$start, $end];
}

function eventMetaGetRetry($url) {
    $last = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $last = metaGet($url, 60);
        if (!isset($last['error'])) return $last;
        $code = (int)($last['error']['code'] ?? 0);
        $retry = !empty($last['error']['_transport']) || in_array($code, [1, 2, 4, 17, 32, 613, 80000, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014], true);
        if (!$retry || $attempt === 3) break;
        sleep($attempt);
    }
    return $last ?: ['error' => ['message' => 'A Meta não retornou resposta.']];
}

function eventCampaignRunUpdate($db, $runId, array $values) {
    $allowed = ['progress_total', 'progress_done', 'current_campaign', 'message', 'details', 'status', 'started_at', 'finished_at'];
    $sets = []; $params = [];
    foreach ($values as $key => $value) {
        if (!in_array($key, $allowed, true)) continue;
        $sets[] = "`{$key}`=?";
        $params[] = $value;
    }
    if (!$sets) return;
    $params[] = $runId;
    $db->execute('UPDATE event_sync_runs SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE id=?', $params);
}

function runEventCampaignSpendSync($db, array $run) {
    $runId = (int)$run['id'];
    $event = $db->fetch('SELECT * FROM events WHERE id=?', [(int)$run['event_id']]);
    if (!$event) throw new RuntimeException('Evento não encontrado.');
    $dateStart = $run['date_start'];
    $dateEnd = $run['date_end'];
    $api = new MetaAPI();
    if (!$api->hasToken()) throw new RuntimeException('Token Meta não configurado ou expirado.');
    $token = $api->getTokenValue();
    $version = metaApiVersion();

    $accounts = array_column($db->fetchAll('SELECT account_id FROM event_accounts WHERE event_id=? ORDER BY id', [$event['id']]), 'account_id');
    if (!$accounts && !empty($event['account_id'])) $accounts = [$event['account_id']];
    if (!$accounts) throw new RuntimeException('Nenhuma conta de anúncios vinculada ao evento.');
    $accounts = array_values(array_unique(array_map('strval', $accounts)));

    $campaigns = $db->fetchAll('SELECT campaign_id,campaign_name FROM event_campaigns WHERE event_id=? ORDER BY campaign_name', [$event['id']]);
    if (!$campaigns) throw new RuntimeException('Nenhuma campanha vinculada ao evento.');
    eventCampaignRunUpdate($db, $runId, [
        'progress_total' => count($campaigns), 'progress_done' => 0,
        'message' => 'Preparando ' . count($campaigns) . ' campanhas.',
    ]);

    $campaignIds = array_column($campaigns, 'campaign_id');
    $campaignMeta = [];
    foreach (array_chunk($campaignIds, 50) as $chunk) {
        $ids = implode(',', array_map('rawurlencode', $chunk));
        $url = "https://graph.facebook.com/{$version}/?ids={$ids}&fields=id,name,objective,status,effective_status,stop_time,account_id&access_token=" . rawurlencode($token);
        $batch = eventMetaGetRetry($url);
        if (isset($batch['error'])) continue;
        foreach ($batch as $cid => $data) {
            if (is_array($data) && !isset($data['error'])) $campaignMeta[(string)$cid] = $data;
        }
    }

    $pdo = $db->getConnection();
    $results = [];
    $done = 0;
    $campaignsWithSpend = 0;
    $totalSpendBrl = 0.0;
    $savedTotal = 0;

    foreach ($campaigns as $campaign) {
        $campaignId = (string)$campaign['campaign_id'];
        $campaignName = trim((string)$campaign['campaign_name']) ?: $campaignId;
        eventCampaignRunUpdate($db, $runId, [
            'progress_done' => $done,
            'current_campaign' => $campaignName,
            'message' => "Consultando {$campaignName} ({$done}/" . count($campaigns) . ').',
        ]);

        $outcome = ['campaign_id' => $campaignId, 'campaign_name' => $campaignName, 'status' => 'failed', 'rows' => 0, 'spend_brl' => 0.0];
        try {
            if (!preg_match('/^\d+$/', $campaignId)) throw new RuntimeException('ID da campanha inválido.');
            $meta = $campaignMeta[$campaignId] ?? null;
            if (!$meta || empty($meta['account_id'])) {
                $single = eventMetaGetRetry("https://graph.facebook.com/{$version}/{$campaignId}?fields=id,name,objective,status,effective_status,stop_time,account_id&access_token=" . rawurlencode($token));
                if (isset($single['error']) || empty($single['account_id'])) {
                    throw new RuntimeException($single['error']['message'] ?? 'Não foi possível identificar a conta desta campanha.');
                }
                $meta = $single;
            }
            $accountId = 'act_' . preg_replace('/^act_/', '', (string)$meta['account_id']);
            if (!in_array($accountId, $accounts, true)) throw new RuntimeException('A campanha pertence a uma conta que não está vinculada ao evento.');

            $db->execute(
                'UPDATE event_campaigns SET campaign_name=?,objective=?,status=?,effective_status=?,stop_time=? WHERE event_id=? AND campaign_id=?',
                [
                    $meta['name'] ?? $campaignName,
                    $meta['objective'] ?? '',
                    $meta['status'] ?? '',
                    $meta['effective_status'] ?? null,
                    !empty($meta['stop_time']) ? date('Y-m-d H:i:s', strtotime($meta['stop_time'])) : null,
                    $event['id'], $campaignId,
                ]
            );

            $url = "https://graph.facebook.com/{$version}/{$campaignId}/insights"
                 . '?fields=campaign_id,spend,actions,action_values,impressions,clicks,date_start'
                 . '&time_range=' . urlencode('{"since":"' . $dateStart . '","until":"' . $dateEnd . '"}')
                 . '&time_increment=1&limit=500&access_token=' . rawurlencode($token);
            $page = 0;
            $rows = 0;
            $spendBrl = 0.0;
            $spendOriginalPositive = false;
            $accountCurrency = FX::moedaDaConta($db, $accountId);

            while ($url && $page < 100) {
                $response = eventMetaGetRetry($url);
                if (isset($response['error']) || !isset($response['data']) || !is_array($response['data'])) {
                    throw new RuntimeException($response['error']['message'] ?? 'Formato inesperado da resposta de Insights.');
                }
                foreach ($response['data'] as $row) {
                    if (!array_key_exists('spend', $row) || empty($row['date_start'])) throw new RuntimeException('Linha de Insights sem gasto ou data.');
                    $snapshotDate = substr((string)$row['date_start'], 0, 10);
                    if ($snapshotDate < $dateStart || $snapshotDate > $dateEnd) throw new RuntimeException('A Meta retornou uma data fora do período solicitado.');
                    $spendOriginal = (float)$row['spend'];
                    $spendOriginalPositive = $spendOriginalPositive || $spendOriginal > 0;
                    $spend = $spendOriginal;
                    $revenueOriginal = 0.0;
                    $purchases = 0; $leads = 0;
                    foreach ($row['actions'] ?? [] as $action) {
                        $type = $action['action_type'] ?? '';
                        if ($type === 'offsite_conversion.fb_pixel_purchase') $purchases += (int)($action['value'] ?? 0);
                        if ($type === 'lead') $leads = (int)($action['value'] ?? 0);
                    }
                    foreach ($row['action_values'] ?? [] as $actionValue) {
                        if (($actionValue['action_type'] ?? '') === 'offsite_conversion.fb_pixel_purchase') $revenueOriginal += (float)($actionValue['value'] ?? 0);
                    }
                    $revenue = $revenueOriginal;
                    $rate = 1.0;
                    if ($accountCurrency !== 'BRL') {
                        list($spend, $rate) = FX::paraBRL($db, $spendOriginal, $accountCurrency, $snapshotDate);
                        list($revenue, $unusedRate) = FX::paraBRL($db, $revenueOriginal, $accountCurrency, $snapshotDate);
                    }
                    $roas = $spend > 0 ? round($revenue / $spend, 2) : 0;
                    $db->execute(
                        'INSERT INTO event_campaign_sync_stage (run_id,event_id,campaign_id,account_id,snapshot_date,impressions,clicks,spend,purchases,leads,revenue,roas,spend_original,revenue_original,currency,fx_rate) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),impressions=VALUES(impressions),clicks=VALUES(clicks),spend=VALUES(spend),purchases=VALUES(purchases),leads=VALUES(leads),revenue=VALUES(revenue),roas=VALUES(roas),spend_original=VALUES(spend_original),revenue_original=VALUES(revenue_original),currency=VALUES(currency),fx_rate=VALUES(fx_rate)',
                        [$runId,$event['id'],$campaignId,$accountId,$snapshotDate,(int)($row['impressions']??0),(int)($row['clicks']??0),$spend,$purchases,$leads,$revenue,$roas,$spendOriginal,$revenueOriginal,$accountCurrency,$rate]
                    );
                    $rows++;
                    $spendBrl += $spend;
                }
                $url = $response['paging']['next'] ?? null;
                $page++;
                if ($url && $page < 100) usleep(200000);
            }
            if ($url && $page >= 100) throw new RuntimeException('Limite de paginação atingido.');

            // Publica atomicamente apenas esta campanha. Campanhas com erro
            // conservam os dados válidos da execução anterior.
            $pdo->beginTransaction();
            $db->execute('DELETE FROM campaign_snapshots WHERE campaign_id=? AND snapshot_date BETWEEN ? AND ?', [$campaignId,$dateStart,$dateEnd]);
            $db->execute(
                'INSERT INTO campaign_snapshots (campaign_id,account_id,snapshot_date,impressions,clicks,spend,purchases,leads,revenue,roas,spend_original,revenue_original,currency,fx_rate) SELECT campaign_id,account_id,snapshot_date,impressions,clicks,spend,purchases,leads,revenue,roas,spend_original,revenue_original,currency,fx_rate FROM event_campaign_sync_stage WHERE run_id=? AND campaign_id=? ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),impressions=VALUES(impressions),clicks=VALUES(clicks),spend=VALUES(spend),purchases=VALUES(purchases),leads=VALUES(leads),revenue=VALUES(revenue),roas=VALUES(roas),spend_original=VALUES(spend_original),revenue_original=VALUES(revenue_original),currency=VALUES(currency),fx_rate=VALUES(fx_rate),synced_at=NOW()',
                [$runId,$campaignId]
            );
            $pdo->commit();

            $outcome['status'] = $spendOriginalPositive ? 'ok' : 'no_delivery';
            $outcome['rows'] = $rows;
            $outcome['spend_brl'] = round($spendBrl, 2);
            $totalSpendBrl += $spendBrl;
            if ($spendOriginalPositive) $campaignsWithSpend++;
            $savedTotal += $rows;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->execute('DELETE FROM event_campaign_sync_stage WHERE run_id=? AND campaign_id=?', [$runId,$campaignId]);
            $outcome['error'] = mb_substr($e->getMessage(), 0, 500);
        }

        $results[] = $outcome;
        $done++;
        eventCampaignRunUpdate($db, $runId, [
            'progress_done' => $done,
            'current_campaign' => $campaignName,
            'message' => "Processadas {$done}/" . count($campaigns) . ' campanhas.',
            'details' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    $failed = count(array_filter($results, function ($result) { return $result['status'] === 'failed'; }));
    $noDelivery = count(array_filter($results, function ($result) { return $result['status'] === 'no_delivery'; }));
    $status = $failed ? ($failed === count($campaigns) ? 'failed' : 'partial') : 'succeeded';
    $db->execute('DELETE FROM projection_cache WHERE event_id=?', [$event['id']]);
    $projectionMessage = 'Projeção recalculada.';
    try {
        require_once __DIR__ . '/projection.php';
        $projectionResult = calcularProjecao($db, $event['id']);
        if (empty($projectionResult['success'])) $projectionMessage = 'Projeção pendente de atualização.';
    } catch (Throwable $projectionError) {
        $projectionMessage = 'Gastos salvos; projeção será atualizada pelo processamento automático.';
    }
    $message = ($status === 'succeeded' ? 'Concluída' : ($status === 'partial' ? 'Parcial' : 'Falhou'))
             . ": " . (count($campaigns) - $failed) . '/' . count($campaigns) . ' campanhas processadas; '
             . $campaignsWithSpend . ' com gasto, ' . $noDelivery . ' sem entrega; '
             . $savedTotal . ' registros diários; total de R$ ' . number_format($totalSpendBrl, 2, ',', '.')
             . " no período {$dateStart} a {$dateEnd}. {$projectionMessage}";

    eventCampaignRunUpdate($db, $runId, [
        'status' => $status,
        'current_campaign' => null,
        'message' => $message,
        'details' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'finished_at' => date('Y-m-d H:i:s'),
    ]);
    $db->execute('DELETE FROM event_campaign_sync_stage WHERE run_id=?', [$runId]);

    $db->execute(
        'INSERT INTO sync_log (sync_type,status,message,accounts_synced) VALUES (?,?,?,?)',
        ['campaigns', $status === 'succeeded' ? 'success' : ($status === 'partial' ? 'partial' : 'error'), $message, count($accounts)]
    );
    return ['status' => $status, 'message' => $message, 'details' => $results];
}
