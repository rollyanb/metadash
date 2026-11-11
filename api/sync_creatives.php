<?php
// api/sync_creatives.php — sync paginado, nunca dá timeout
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
set_time_limit(45); // mantém a chamada abaixo do timeout do proxy da hospedagem

function j($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'error'=>'Fatal: '.$e['message'].' em '.$e['file'].':'.$e['line']], JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/../includes/auth.php';
if (!Auth::isLoggedIn() || Auth::getRole() !== 'admin') j(['error'=>'Acesso negado']);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/meta_api.php';
require_once __DIR__ . '/../includes/fx.php';

$db  = Database::getInstance();
$api = new MetaAPI();
if (!$api->hasToken()) j(['success'=>false,'message'=>'Token não configurado']);

$input     = json_decode(file_get_contents('php://input'), true) ?: [];
$action    = $input['action']     ?? 'sync_one';
$token     = $api->getTokenValue();
$ver       = metaApiVersion();

function eventCampaignAccountIds($db, $eventId) {
    $accounts = array_column($db->fetchAll('SELECT account_id FROM event_accounts WHERE event_id=?', [$eventId]), 'account_id');
    if (!$accounts) {
        $event = $db->fetch('SELECT account_id FROM events WHERE id=?', [$eventId]);
        if (!empty($event['account_id'])) $accounts[] = $event['account_id'];
    }
    return array_values(array_unique(array_map(function($id) {
        return 'act_' . preg_replace('/^act_/', '', (string)$id);
    }, $accounts)));
}

function assertEventCampaign($db, $eventId, $campaignId, $accountId) {
    if (!$eventId || !$campaignId || !$accountId) j(['success'=>false,'message'=>'Escopo do evento incompleto.']);
    $linked = $db->fetch('SELECT 1 FROM event_campaigns WHERE event_id=? AND campaign_id=?', [$eventId, $campaignId]);
    $accountId = 'act_' . preg_replace('/^act_/', '', (string)$accountId);
    if (!$linked || !in_array($accountId, eventCampaignAccountIds($db, $eventId), true)) {
        j(['success'=>false,'message'=>'A campanha ou a conta não está vinculada a este evento.']);
    }
}

// O botão dentro do evento trabalha só com as campanhas associadas a ele.
if ($action === 'get_event_scope') {
    $eventId = (int)($input['event_id'] ?? 0);
    $event = $eventId ? $db->fetch('SELECT date_campaign_start,date_campaign_end FROM events WHERE id=?', [$eventId]) : null;
    if (!$event) j(['success'=>false,'message'=>'Evento não encontrado.']);
    $campaigns = $db->fetchAll('SELECT campaign_id,campaign_name FROM event_campaigns WHERE event_id=? ORDER BY campaign_name', [$eventId]);
    if (!$campaigns) j(['success'=>false,'message'=>'Vincule campanhas ao evento antes de sincronizar os criativos.']);
    $ids = array_values(array_unique(array_column($campaigns, 'campaign_id')));
    $accounts = eventCampaignAccountIds($db, $eventId);
    $metaById = [];
    foreach (array_chunk($ids, 50) as $chunk) {
        $encoded = implode(',', array_map('rawurlencode', $chunk));
        $batch = metaGet("https://graph.facebook.com/{$ver}/?ids={$encoded}&fields=id,account_id&access_token=" . rawurlencode($token));
        if (!isset($batch['error'])) {
            foreach ($batch as $campaignId => $meta) {
                if (is_array($meta) && !isset($meta['error']) && !empty($meta['account_id'])) {
                    $metaById[(string)$campaignId] = 'act_' . preg_replace('/^act_/', '', (string)$meta['account_id']);
                }
            }
        }
    }
    $result = [];
    foreach ($campaigns as $campaign) {
        $campaignId = (string)$campaign['campaign_id'];
        $accountId = $metaById[$campaignId] ?? '';
        if (!$accountId || !in_array($accountId, $accounts, true)) {
            j(['success'=>false,'message'=>'Não foi possível confirmar a conta da campanha ' . ($campaign['campaign_name'] ?: $campaignId) . '. Confira as contas vinculadas ao evento.']);
        }
        $result[] = ['campaign_id'=>$campaignId,'campaign_name'=>$campaign['campaign_name'],'account_id'=>$accountId];
    }
    $start = $event['date_campaign_start'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$start)) $start = date('Y-m-d', strtotime('-32 days'));
    $today = date('Y-m-d');
    $end = $event['date_campaign_end'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$end) || $end > $today) $end = $today;
    j(['success'=>true,'campaigns'=>$result,'date_start'=>$start,'date_end'=>$end]);
}

// ── GET ACCOUNTS ─────────────────────────────────────────────────────────────
if ($action === 'get_accounts') {
    $force    = !empty($input['force']);

    // Garante que toda conta usada por evento ativo esteja na lista de sync.
    // Sem isso, a 2ª conta de um evento multi-contas nunca teria criativos.
    try {
        $db->execute("
            INSERT IGNORE INTO creative_sync_accounts (account_id)
            SELECT DISTINCT ea.account_id
            FROM event_accounts ea
            JOIN events e ON e.id = ea.event_id AND e.status = 'active'
            JOIN ad_accounts a ON a.account_id = ea.account_id AND a.status = 'ACTIVE'");
    } catch (Exception $e) {}

    $accounts = $db->fetchAll("
        SELECT c.account_id, a.account_name, c.last_synced_at, c.last_sync_status
        FROM creative_sync_accounts c
        JOIN ad_accounts a ON a.account_id = c.account_id
        WHERE a.status = 'ACTIVE'
        ORDER BY a.account_name
    ");

    $threeDaysAgo = strtotime('-3 days');
    foreach ($accounts as &$acc) {
        if ($force || empty($acc['last_synced_at'])) {
            $acc['needs_sync']  = true;
            $acc['skip_reason'] = '';
        } else {
            $lastSync = strtotime($acc['last_synced_at']);
            if ($lastSync < $threeDaysAgo) {
                $acc['needs_sync']  = true;
                $acc['skip_reason'] = '';
            } else {
                $acc['needs_sync']  = false;
                $hrs = round((time() - $lastSync) / 3600, 1);
                $acc['skip_reason'] = "sync há {$hrs}h";
            }
        }
    }
    unset($acc);

    $toSync = 0;
    foreach ($accounts as $a) { if ($a['needs_sync']) $toSync++; }
    j(['accounts'=>$accounts,'total'=>count($accounts),'to_sync'=>$toSync,'to_skip'=>count($accounts)-$toSync]);
}

// Lista os anúncios dinâmicos da conta, para buscar os assets de cada um
if (($input['action'] ?? '') === 'list_dynamic') {
    $eventId = (int)($input['event_id'] ?? 0);
    if ($eventId) {
        try {
            $rows = $db->fetchAll("SELECT ac.ad_id,ac.account_id,ac.campaign_id FROM ad_creatives ac
                                   JOIN event_campaigns ec ON ec.campaign_id=ac.campaign_id AND ec.event_id=?
                                   ORDER BY ac.is_dynamic DESC,ac.spend DESC LIMIT 25", [$eventId]);
            j(['success'=>true,'ads'=>$rows]);
        } catch (Exception $e) { j(['success'=>false,'message'=>$e->getMessage()]); }
    }
    $accId = $input['account_id'] ?? '';
    if (!$accId) j(['success'=>false,'message'=>'account_id não informado']);
    try {
        // Inclui os marcados como dinâmicos E os de maior gasto: a detecção por
        // asset_feed_spec falha em alguns formatos (Advantage+ sem feed explícito),
        // e a própria Meta responde vazio quando o anúncio não é dinâmico.
        $rows = $db->fetchAll("SELECT ad_id FROM ad_creatives
                               WHERE account_id = ?
                               ORDER BY is_dynamic DESC, spend DESC
                               LIMIT 25", [$accId]);
        j(['success'=>true, 'ads'=>array_column($rows, 'ad_id')]);
    } catch (Exception $e) {
        j(['success'=>false, 'message'=>$e->getMessage()]);
    }
}

// ── FASES DE SYNC ─────────────────────────────────────────────────────────────
$accountId = $input['account_id'] ?? '';
$eventId = (int)($input['event_id'] ?? 0);
$campaignId = (string)($input['campaign_id'] ?? '');
$phase     = $input['phase']      ?? 'ads'; // ads | snapshots | assets | done
if (!$accountId) j(['success'=>false,'message'=>'account_id não informado']);

if ($campaignId) assertEventCampaign($db, $eventId, $campaignId, $accountId);

$dateEnd = date('Y-m-d');
$eventStart = '';
if ($campaignId) {
    $eventDates = $db->fetch('SELECT date_campaign_start,date_campaign_end FROM events WHERE id=?', [$eventId]);
    $eventStart = $eventDates['date_campaign_start'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($eventDates['date_campaign_end'] ?? '')) && $eventDates['date_campaign_end'] < $dateEnd) {
        $dateEnd = $eventDates['date_campaign_end'];
    }
}
$dateStart = $campaignId && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$eventStart)
    ? $eventStart
    : (!empty($input['full_backfill']) ? date('Y-m-d', strtotime('-32 days')) : date('Y-m-d', strtotime('-32 days')));
if ($campaignId) {
    $lastSnap = $db->fetch("SELECT MAX(s.snapshot_date) as last FROM ad_creative_snapshots s
                            JOIN ad_creatives ac ON ac.ad_id=s.ad_id AND ac.account_id=s.account_id
                            WHERE ac.account_id=? AND ac.campaign_id=?", [$accountId,$campaignId]);
    if (!empty($lastSnap['last']) && $lastSnap['last'] > $dateStart) $dateStart = $lastSnap['last'];
} else {
    $lastSnap = $db->fetch("SELECT MAX(snapshot_date) as last FROM ad_creative_snapshots WHERE account_id=?", [$accountId]);
    $dateStart = !empty($input['full_backfill']) ? date('Y-m-d', strtotime('-32 days')) : (!empty($lastSnap['last']) ? $lastSnap['last'] : date('Y-m-d', strtotime('-32 days')));
}

// ── FASE 1: ADS ───────────────────────────────────────────────────────────────
if ($phase === 'ads') {
    $cursor = $input['cursor'] ?? null;
    $saved  = 0;
    $dynamic = 0;

    $adsNode = $campaignId ?: $accountId;
    $url = "https://graph.facebook.com/{$ver}/{$adsNode}/ads"
         . "?fields=id,name,campaign_id,creative{id,name,title,body,image_url,thumbnail_url,object_story_spec,asset_feed_spec}"
         // Respostas menores evitam 504 ao expandir o objeto creative em contas grandes.
         . ($campaignId ? "&limit=10" : "&effective_status=[\"ACTIVE\",\"PAUSED\"]&limit=10")
         . "&access_token={$token}";
    if ($cursor) $url .= "&after={$cursor}";

    $r = metaGet($url);
    if (!$r || isset($r['error'])) {
        $msg = $r['error']['message'] ?? 'Erro ao buscar anúncios';
        j(['success'=>false,'message'=>$msg]);
    }

    foreach ($r['data'] ?? [] as $ad) {
        $cre = $ad['creative'] ?? [];
        $img = $cre['image_url'] ?? '';
        if (!$img) {
            $spec = $cre['object_story_spec'] ?? [];
            $img  = $spec['link_data']['picture'] ?? $spec['video_data']['image_url'] ?? '';
        }
        if (!$img) $img = $cre['thumbnail_url'] ?? '';

        $afs       = $cre['asset_feed_spec'] ?? [];
        $isDynamic = 0;
        if (!empty($afs)) {
            $ic = count($afs['images']??[]);
            $vc = count($afs['videos']??[]);
            $bc = count($afs['bodies']??[]);
            $tc = count($afs['titles']??[]);
            if ($ic > 1 || $vc > 1 || ($ic + $vc > 1) || $bc > 1 || $tc > 1) $isDynamic = 1;
        }
        if ($isDynamic) $dynamic++;

        $db->execute(
            "INSERT INTO ad_creatives (account_id,ad_id,campaign_id,ad_name,creative_id,title,body,image_url,thumbnail_url,is_dynamic)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                campaign_id=VALUES(campaign_id),
                ad_name=VALUES(ad_name),title=VALUES(title),body=VALUES(body),
                image_url=VALUES(image_url),thumbnail_url=VALUES(thumbnail_url),is_dynamic=VALUES(is_dynamic)",
            [$accountId,$ad['id'],$ad['campaign_id']??'',$ad['name']??'',$cre['id']??'',$cre['title']??'',$cre['body']??'',$img,$cre['thumbnail_url']??'',$isDynamic]
        );
        $saved++;
    }

    $nextCursor = $r['paging']['cursors']['after'] ?? null;
    $hasNext    = !empty($r['paging']['next']);

    j(['success'=>true,'phase'=>'ads','saved'=>$saved,'dynamic'=>$dynamic,
       'next_cursor'=>$hasNext ? $nextCursor : null,
       'has_more'=>$hasNext,'message'=>"{$saved} anúncios ({$dynamic} dinâmicos)"]);
}

// ── FASE 2: SNAPSHOTS DIÁRIOS ─────────────────────────────────────────────────
if ($phase === 'snapshots') {
    $cursor = $input['cursor'] ?? null;
    $saved  = 0;

    if ($dateStart > $dateEnd) {
        j(['success'=>true,'phase'=>'snapshots','saved'=>0,'has_more'=>false,
           'message'=>'Já atualizado','date_start'=>$dateStart,'date_end'=>$dateEnd]);
    }

    // Pede no máximo 3 dias por requisição para manter cada resposta leve.
    $janelaFim = $dateEnd;
    $janelaIni = date('Y-m-d', strtotime($janelaFim . ' -2 days'));
    if ($janelaIni < $dateStart) $janelaIni = $dateStart;
    if ($cursor && strpos($cursor, 'JANELA:') === 0) {
        // retoma de onde parou: cursor no formato JANELA:aaaa-mm-dd|cursorMeta
        $partes    = explode('|', substr($cursor, 7), 2);
        $cursorFim = $partes[0] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cursorFim)) {
            j(['success'=>false,'phase'=>'snapshots','message'=>'Cursor de período inválido. Inicie uma nova sincronização.']);
        }
        // Cursor antigo ou fora da janela do evento nunca pode ampliar o intervalo.
        $janelaFim = min($cursorFim, $dateEnd);
        $cursor    = $partes[1] ?? '';
        $janelaIni = date('Y-m-d', strtotime($janelaFim . ' -2 days'));
        if ($janelaIni < $dateStart) $janelaIni = $dateStart;
    }

    // Defesa final: nunca consulta a Meta com since > until.
    if ($janelaIni > $janelaFim || $janelaFim < $dateStart) {
        j(['success'=>true,'phase'=>'snapshots','saved'=>0,'has_more'=>false,
           'message'=>'Período do evento já atualizado','date_start'=>$dateStart,'date_end'=>$dateEnd]);
    }

    $insightsNode = $campaignId ?: $accountId;
    $url = "https://graph.facebook.com/{$ver}/{$insightsNode}/insights"
         . "?fields=ad_id,impressions,reach,clicks,ctr,spend,actions,action_values"
         . "&level=ad"
         . "&time_range=" . urlencode('{"since":"'.$janelaIni.'","until":"'.$janelaFim.'"}')
         . "&time_increment=1&limit=100&access_token={$token}";
    if ($cursor) $url .= "&after={$cursor}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $curlErrNo = curl_errno($ch); $curlErrMsg = curl_error($ch);
    curl_close($ch);
    $r = $raw !== false ? json_decode($raw, true) : null;

    // Uma tentativa extra em erro temporário (limite de uso / instabilidade)
    if (!$r || isset($r['error'])) {
        $codeTmp = $r['error']['code'] ?? 0;
        if (in_array($codeTmp, [1, 2, 4, 17, 32, 613]) || $raw === false) {
            sleep(3);
            $ch2 = curl_init();
            curl_setopt_array($ch2, [CURLOPT_URL=>$url, CURLOPT_RETURNTRANSFER=>true,
                                     CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_TIMEOUT=>20]);
            $raw2 = curl_exec($ch2);
            $curlErrNo = curl_errno($ch2); $curlErrMsg = curl_error($ch2);
            curl_close($ch2);
            $r2 = $raw2 !== false ? json_decode($raw2, true) : null;
            if ($r2 && !isset($r2['error'])) { $r = $r2; $raw = $raw2; }
        }
    }

    // Erro 100 (Invalid parameter) costuma vir de campos não suportados no nível
    // de anúncio com recorte diário. Tenta de novo sem os campos problemáticos
    // antes de desistir, para não travar todo o sync por causa de uma métrica.
    if ($r && isset($r['error']) && (int)($r['error']['code'] ?? 0) === 100) {
        foreach ([
            'ad_id,impressions,clicks,ctr,spend,actions,action_values',   // sem reach
            'ad_id,impressions,clicks,spend,actions,action_values',       // sem reach e ctr
            'ad_id,impressions,clicks,spend',                             // mínimo
        ] as $campos) {
            $urlAlt = preg_replace('/\?fields=[^&]+/', '?fields=' . $campos, $url);
            $chA = curl_init();
            curl_setopt_array($chA, [CURLOPT_URL=>$urlAlt, CURLOPT_RETURNTRANSFER=>true,
                                     CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_TIMEOUT=>20]);
            $rawAlt = curl_exec($chA);
            curl_close($chA);
            $rAlt = $rawAlt !== false ? json_decode($rawAlt, true) : null;
            if ($rAlt && !isset($rAlt['error'])) { $r = $rAlt; $raw = $rawAlt; break; }
            usleep(300000);
        }
    }

    if (!$r || isset($r['error'])) {
        if ($raw === false) {
            $msg = "cURL falhou (errno {$curlErrNo}): {$curlErrMsg}";
        } elseif (isset($r['error'])) {
            $code = $r['error']['code'] ?? '?';
            $det  = $r['error']['error_user_msg'] ?? ($r['error']['error_subcode'] ?? '');
            $msg  = "Graph API erro {$code}: " . ($r['error']['message'] ?? 'desconhecido')
                  . ($det ? " [{$det}]" : '');
        } else {
            $msg = 'Resposta vazia/não decodificável do Graph API';
        }
        j(['success'=>false,'phase'=>'snapshots','message'=>$msg,
           'requested_since'=>$janelaIni,'requested_until'=>$janelaFim]);
    }

    foreach ($r['data'] ?? [] as $ins) {
        $eng=$vid=$leads=$purchases=$checkout=0; $revenue=0.0;
        foreach ($ins['actions']??[] as $a) {
            $v=intval($a['value']); $t=$a['action_type'];
            if (in_array($t,['post_engagement','page_engagement'])) $eng=max($eng,$v);
            if ($t==='video_view') $vid=$v;
            if (in_array($t,['lead','onsite_web_lead','offsite_conversion.fb_pixel_lead'])) $leads+=$v;
            if ($t==='offsite_conversion.fb_pixel_purchase') $purchases+=$v;
            if (in_array($t,['initiate_checkout','omni_initiated_checkout'])) $checkout+=$v;
        }
        foreach ($ins['action_values']??[] as $a) {
            if ($a['action_type']==='offsite_conversion.fb_pixel_purchase') $revenue+=floatval($a['value']);
        }
        $spend = floatval($ins['spend']??0);
        $roas  = $spend>0 ? round($revenue/$spend,2) : 0;

        // conversão de moeda (conta USD/EUR) — coluna guarda BRL
        $moedaS = FX::moedaDaConta($db, $accountId);
        $spendOrigS = $spend; $taxaS = 1.0;
        if ($moedaS !== 'BRL') { list($spend, $taxaS) = FX::paraBRL($db, $spendOrigS, $moedaS, $ins['date_start'] ?? null); }

        $db->execute(
            "INSERT INTO ad_creative_snapshots
                (ad_id,account_id,snapshot_date,spend_original,currency,impressions,reach,clicks,ctr,spend,engagement,video_views,leads,purchases,revenue,roas,checkout)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                impressions=VALUES(impressions),reach=VALUES(reach),clicks=VALUES(clicks),
                ctr=VALUES(ctr),spend=VALUES(spend),engagement=VALUES(engagement),
                video_views=VALUES(video_views),leads=VALUES(leads),purchases=VALUES(purchases),
                revenue=VALUES(revenue),roas=VALUES(roas),checkout=VALUES(checkout),synced_at=NOW()",
            [$ins['ad_id'],$accountId,$ins['date_start'],$spendOrigS,$moedaS,
             intval($ins['impressions']??0),intval($ins['reach']??0),
             intval($ins['clicks']??0),floatval($ins['ctr']??0),$spend,
             $eng,$vid,$leads,$purchases,$revenue,$roas,$checkout]
        );
        $saved++;
    }

    $nextCursor = $r['paging']['cursors']['after'] ?? null;
    $hasNext    = !empty($r['paging']['next']);

    if ($hasNext) {
        // ainda há páginas desta janela de 7 dias
        j(['success'=>true,'phase'=>'snapshots','saved'=>$saved,'has_more'=>true,
           'next_cursor'=>'JANELA:' . $janelaFim . '|' . $nextCursor,
           'message'=>"{$saved} snapshots (" . date('d/m', strtotime($janelaIni)) . " a " . date('d/m', strtotime($janelaFim)) . ")"]);
    }

    // janela concluída: recua 7 dias até cobrir todo o período
    if ($janelaIni > $dateStart) {
        $proxFim = date('Y-m-d', strtotime($janelaIni . ' -1 day'));
        j(['success'=>true,'phase'=>'snapshots','saved'=>$saved,'has_more'=>true,
           'next_cursor'=>'JANELA:' . $proxFim . '|',
           'message'=>"{$saved} snapshots (" . date('d/m', strtotime($janelaIni)) . " a " . date('d/m', strtotime($janelaFim)) . ")"]);
    }

    j(['success'=>true,'phase'=>'snapshots','saved'=>$saved,'has_more'=>false,
       'next_cursor'=>null, 'message'=>"{$saved} snapshots"]);
}

// ── FASE 3: ASSETS DE UM ANÚNCIO DINÂMICO ────────────────────────────────────
if ($phase === 'assets') {
    $adId     = $input['ad_id'] ?? '';
    $breakdown = $input['breakdown'] ?? 'image_asset'; // image_asset | video_asset
    if (!$adId) j(['success'=>false,'message'=>'ad_id não informado']);
    if ($campaignId) {
        $adLinked = $db->fetch('SELECT 1 FROM ad_creatives WHERE ad_id=? AND account_id=? AND campaign_id=? LIMIT 1', [$adId,$accountId,$campaignId]);
        if (!$adLinked) j(['success'=>false,'message'=>'O anúncio não pertence à campanha vinculada ao evento.']);
    }

    // Janela própria: o $dateStart do sync incremental vira "hoje" depois que os
    // snapshots rodam, e a busca de assets voltava vazia. Aqui usamos o período
    // de vida do anúncio (ou os últimos 90 dias).
    // datas reais de veiculação vêm dos snapshots diários; ad_creatives pode estar vazio
    $aDates = $db->fetch("SELECT MIN(snapshot_date) ini, MAX(snapshot_date) fim
                          FROM ad_creative_snapshots WHERE ad_id=?", [$adId]);
    if (empty($aDates['ini'])) {
        $aDates = $db->fetch("SELECT MIN(date_start) ini, MAX(date_end) fim FROM ad_creatives WHERE ad_id=?", [$adId]);
    }
    $aIni = !empty($aDates['ini']) ? $aDates['ini'] : date('Y-m-d', strtotime('-90 days'));
    $aFim = !empty($aDates['fim']) ? min($aDates['fim'], date('Y-m-d')) : date('Y-m-d');
    if ($aIni > $aFim) { $aIni = date('Y-m-d', strtotime('-90 days')); $aFim = date('Y-m-d'); }
    // a Meta limita breakdown de asset a 90 dias
    if (strtotime($aFim) - strtotime($aIni) > 90 * 86400) {
        $aIni = date('Y-m-d', strtotime($aFim . ' -90 days'));
    }

    $url = "https://graph.facebook.com/{$ver}/{$adId}/insights"
         . "?fields=impressions,reach,clicks,spend,actions,action_values"
         . "&breakdowns={$breakdown}"
         . "&time_range=" . urlencode('{"since":"'.$aIni.'","until":"'.$aFim.'"}')
         . "&limit=100&access_token={$token}";

    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL=>$url, CURLOPT_RETURNTRANSFER=>true,
                            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_TIMEOUT=>45]);
    $rawA = curl_exec($ch);
    $errA = curl_error($ch);
    curl_close($ch);
    $r = $rawA !== false ? json_decode($rawA, true) : null;

    if (!$r || isset($r['error'])) {
        // reporta o motivo em vez de esconder como "sem dados"
        $m = $r['error']['message'] ?? ($errA ?: 'resposta inválida');
        j(['success'=>true,'phase'=>'assets','saved'=>0,
           'message'=>'sem assets (' . mb_substr($m, 0, 90) . ')',
           'periodo'=>$aIni . ' a ' . $aFim]);
    }
    if (empty($r['data'])) {
        j(['success'=>true,'phase'=>'assets','saved'=>0,
           'message'=>'sem assets no período', 'periodo'=>$aIni . ' a ' . $aFim]);
    }

    $saved = 0;
    $assetType = ($breakdown === 'video_asset') ? 'video' : 'image';

    foreach ($r['data'] ?? [] as $row) {
        $asset     = $row[$breakdown] ?? [];
        $assetId   = $asset['id'] ?? ($asset['hash'] ?? '');
        if (!$assetId) continue;
        $assetName = $asset['name'] ?? $asset['url'] ?? $assetId;
        $thumbUrl  = $asset['url']  ?? '';

        // Thumbnail de vídeo
        if ($assetType === 'video' && !empty($asset['id'])) {
            $vt = metaGet("https://graph.facebook.com/{$ver}/{$asset['id']}/thumbnails?access_token={$token}");
            if (!empty($vt['data'][0]['uri'])) $thumbUrl = $vt['data'][0]['uri'];
        }

        $spend = floatval($row['spend']??0);
        $leads=$purchases=0; $revenue=0.0;
        foreach ($row['actions']??[] as $a) {
            if (in_array($a['action_type'],['lead','onsite_web_lead','offsite_conversion.fb_pixel_lead'])) $leads+=intval($a['value']);
            if ($a['action_type']==='offsite_conversion.fb_pixel_purchase') $purchases+=intval($a['value']);
        }
        foreach ($row['action_values']??[] as $a) {
            if ($a['action_type']==='offsite_conversion.fb_pixel_purchase') $revenue+=floatval($a['value']);
        }
        $cpl  = $leads>0     ? round($spend/$leads,2)     : 0;
        $cpa  = $purchases>0 ? round($spend/$purchases,2) : 0;
        $roas = $spend>0     ? round($revenue/$spend,2)   : 0;

        $moedaA = FX::moedaDaConta($db, $accountId);
        $spendOrigA = $spend; 
        if ($moedaA !== 'BRL') { list($spend, $txA) = FX::paraBRL($db, $spendOrigA, $moedaA, $aFim ?? null); }

        $db->execute(
            "INSERT INTO ad_creative_assets
                (ad_id,account_id,asset_id,asset_type,asset_name,thumbnail_url,spend_original,currency,date_start,date_end,
                 impressions,reach,clicks,spend,leads,purchases,revenue,cpl,cpa,roas)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                impressions=VALUES(impressions),reach=VALUES(reach),clicks=VALUES(clicks),
                spend=VALUES(spend),leads=VALUES(leads),purchases=VALUES(purchases),
                revenue=VALUES(revenue),cpl=VALUES(cpl),cpa=VALUES(cpa),roas=VALUES(roas),
                thumbnail_url=VALUES(thumbnail_url),synced_at=NOW()",
            [$adId,$accountId,$assetId,$assetType,$assetName,$thumbUrl,$spendOrigA,$moedaA,$aIni,$aFim,
             intval($row['impressions']??0),intval($row['reach']??0),intval($row['clicks']??0),
             $spend,$leads,$purchases,$revenue,$cpl,$cpa,$roas]
        );
        $saved++;
    }

    j(['success'=>true,'phase'=>'assets','saved'=>$saved,'breakdown'=>$breakdown,'message'=>"{$saved} assets ({$assetType})"]);
}

// ── FASE FINAL: MARK DONE ─────────────────────────────────────────────────────
if ($phase === 'done') {
    $db->execute("UPDATE creative_sync_accounts SET last_synced_at=NOW(), last_sync_status='ok' WHERE account_id=?", [$accountId]);
    j(['success'=>true,'phase'=>'done','message'=>'Conta marcada como sincronizada']);
}

j(['success'=>false,'message'=>'Fase desconhecida: '.$phase]);
