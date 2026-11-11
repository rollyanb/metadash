<?php
// api/sync_creatives.php — sync paginado, nunca dá timeout
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
set_time_limit(60); // cada fase tem no máximo 60s

// Quando uma fase roda dentro do mesmo processo (sem shell_exec), j() devolve
// o resultado por exceção em vez de encerrar o script inteiro.
// Guardas obrigatórias: o arquivo é reincluído a cada fase e redeclarar
// classe ou função é erro fatal.
if (!class_exists('FaseConcluida')) {
    class FaseConcluida extends Exception {
        public $dados;
        public function __construct($dados) { $this->dados = $dados; parent::__construct('fase concluida'); }
    }
}
if (!function_exists('j')) {
    function j($d){
        if (!empty($GLOBALS['SYNC_EM_PROCESSO'])) throw new FaseConcluida($d);
        echo json_encode($d, JSON_UNESCAPED_UNICODE); exit;
    }
}

// Só registra o handler uma vez: o arquivo é reincluído a cada fase.
if (empty($GLOBALS['SYNC_SHUTDOWN_OK'])) {
$GLOBALS['SYNC_SHUTDOWN_OK'] = true;
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'error'=>'Fatal: '.$e['message'].' em '.$e['file'].':'.$e['line']], JSON_UNESCAPED_UNICODE);
    }
});
}

require_once __DIR__ . '/../includes/auth.php';
if (PHP_SAPI !== 'cli' && (!Auth::isLoggedIn() || Auth::getRole() !== 'admin')) j(['error'=>'Acesso negado']);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/meta_api.php';
require_once __DIR__ . '/../includes/fx.php';

$db  = Database::getInstance();
$api = new MetaAPI();
if (!$api->hasToken()) j(['success'=>false,'message'=>'Token não configurado']);

$cliPayload = null;
if (PHP_SAPI === 'cli') {
    foreach (($argv ?? []) as $arg) {
        if (strpos($arg, '--payload=') === 0) {
            $decoded = base64_decode(substr($arg, 10), true);
            $cliPayload = $decoded !== false ? json_decode($decoded, true) : null;
            break;
        }
    }
}
if (isset($GLOBALS['SYNC_PAYLOAD_FASE'])) {
    $input = $GLOBALS['SYNC_PAYLOAD_FASE'];
    $cliRunner = false;
} else {
    $input     = is_array($cliPayload) ? $cliPayload : (json_decode(file_get_contents('php://input'), true) ?: []);
    $cliRunner = PHP_SAPI === 'cli' && !is_array($cliPayload);
}
$input['action'] = $input['action'] ?? ($cliRunner ? 'cli_runner' : 'sync_one');
$action    = $input['action']     ?? 'sync_one';
$token     = $api->getTokenValue();
$ver       = metaApiVersion();

// A tarefa cron chama este arquivo sem sessão web. Orquestra as mesmas fases
// em processos curtos para respeitar o limite de execução de cada requisição.
if ($action === 'cli_runner') {
    // A hospedagem compartilhada da Hostinger desativa shell_exec/proc_open, então
    // cada fase roda neste mesmo processo: o arquivo é reincluído com o payload
    // em uma global e j() devolve o resultado por exceção em vez de encerrar.
    // O limite de tempo é renovado a cada fase, que era o motivo original de
    // abrir um processo por fase.
    $runPhase = function (array $payload) {
        $GLOBALS['SYNC_PAYLOAD_FASE'] = $payload;
        $GLOBALS['SYNC_EM_PROCESSO']  = true;
        $resultado = ['success'=>false,'message'=>'A fase terminou sem devolver resultado'];
        ob_start();
        try {
            @set_time_limit(120);
            include __FILE__;
        } catch (FaseConcluida $fim) {
            $resultado = is_array($fim->dados) ? $fim->dados : ['success'=>false,'message'=>'Resultado inesperado da fase'];
        } catch (Throwable $e) {
            $resultado = ['success'=>false,'message'=>get_class($e) . ': ' . $e->getMessage()];
        }
        $lixo = trim((string)ob_get_clean());
        unset($GLOBALS['SYNC_PAYLOAD_FASE'], $GLOBALS['SYNC_EM_PROCESSO']);
        // Se a fase imprimiu algo fora do j(), é aviso do PHP: não contamina o retorno.
        if ($lixo !== '' && empty($resultado['success']) && empty($resultado['message'])) {
            $resultado['message'] = substr($lixo, 0, 180);
        }
        return $resultado;
    };
    $list = $runPhase(['action'=>'get_accounts']);
    if (!isset($list['accounts']) || !is_array($list['accounts'])) { fwrite(STDERR, "Falha ao listar contas: " . ($list['message'] ?? $list['error'] ?? 'resposta inválida') . PHP_EOL); exit(1); }
    $errors = 0; $synced = 0;
    foreach ($list['accounts'] as $account) {
        if (empty($account['needs_sync'])) continue;
        $accountId = (string)$account['account_id'];
        $failed = false;
        foreach (['ads','snapshots'] as $phaseName) {
            $cursor = null;
            do {
                $payload = ['action'=>'sync_one','phase'=>$phaseName,'account_id'=>$accountId];
                if ($cursor) $payload['cursor'] = $cursor;
                $result = $runPhase($payload);
                if (empty($result['success'])) { fwrite(STDERR, "{$accountId}: {$phaseName}: " . ($result['message'] ?? 'erro') . PHP_EOL); $failed = true; break; }
                $cursor = !empty($result['has_more']) ? ($result['next_cursor'] ?? null) : null;
                if (!empty($result['has_more']) && !$cursor) { fwrite(STDERR, "{$accountId}: cursor ausente em {$phaseName}" . PHP_EOL); $failed = true; break; }
            } while ($cursor);
            if ($failed) break;
        }
        if ($failed) { $errors++; continue; }
        $dynamic = $runPhase(['action'=>'list_dynamic','account_id'=>$accountId]);
        foreach (($dynamic['ads'] ?? []) as $adId) {
            foreach (['image_asset','video_asset'] as $breakdown) {
                $runPhase(['action'=>'sync_one','phase'=>'assets','account_id'=>$accountId,'ad_id'=>$adId,'breakdown'=>$breakdown]);
            }
        }
        $done = $runPhase(['action'=>'sync_one','phase'=>'done','account_id'=>$accountId]);
        if (empty($done['success'])) { fwrite(STDERR, "{$accountId}: não foi possível marcar a sincronização" . PHP_EOL); $errors++; continue; }
        fwrite(STDOUT, "{$accountId}: sincronizada" . PHP_EOL); $synced++;
    }
    fwrite(STDOUT, "Concluído: {$synced} conta(s) sincronizada(s), {$errors} com erro." . PHP_EOL);
    exit($errors ? 1 : 0);
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
$phase     = $input['phase']      ?? 'ads'; // ads | snapshots | assets | done
if (!$accountId) j(['success'=>false,'message'=>'account_id não informado']);

$dateEnd = date('Y-m-d');
$lastSnap = $db->fetch("SELECT MAX(snapshot_date) as last FROM ad_creative_snapshots WHERE account_id=?", [$accountId]);
$dateStart = !empty($lastSnap['last']) ? $lastSnap['last'] : date('Y-m-d', strtotime('-32 days'));

// ── FASE 1: ADS ───────────────────────────────────────────────────────────────
if ($phase === 'ads') {
    $cursor = $input['cursor'] ?? null;
    $saved  = 0;
    $dynamic = 0;

    $url = "https://graph.facebook.com/{$ver}/{$accountId}/ads"
         . "?fields=id,name,campaign_id,creative{id,name,title,body,image_url,thumbnail_url,object_story_spec,asset_feed_spec}"
         . "&effective_status=[\"ACTIVE\",\"PAUSED\"]&limit=50&access_token={$token}";
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
        j(['success'=>true,'phase'=>'snapshots','saved'=>0,'has_more'=>false,'message'=>'Já atualizado']);
    }

    // Pede no máximo 7 dias por requisição. Intervalos longos no nível de
    // anúncio com time_increment=1 estouravam o limite da Meta e a fase falhava.
    $janelaFim = $dateEnd;
    $janelaIni = date('Y-m-d', strtotime($dateEnd . ' -6 days'));
    if ($janelaIni < $dateStart) $janelaIni = $dateStart;
    if ($cursor && strpos($cursor, 'JANELA:') === 0) {
        // retoma de onde parou: cursor no formato JANELA:aaaa-mm-dd|cursorMeta
        $partes    = explode('|', substr($cursor, 7), 2);
        $janelaFim = $partes[0];
        $cursor    = $partes[1] ?? '';
        $janelaIni = date('Y-m-d', strtotime($janelaFim . ' -6 days'));
        if ($janelaIni < $dateStart) $janelaIni = $dateStart;
    }

    $url = "https://graph.facebook.com/{$ver}/{$accountId}/insights"
         . "?fields=ad_id,impressions,reach,clicks,ctr,spend,actions,action_values"
         . "&level=ad"
         . "&time_range=" . urlencode('{"since":"'.$janelaIni.'","until":"'.$janelaFim.'"}')
         . "&time_increment=1&limit=200&access_token={$token}";
    if ($cursor) $url .= "&after={$cursor}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 55,
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
                                     CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_TIMEOUT=>55]);
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
                                     CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_TIMEOUT=>55]);
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
        j(['success'=>false,'message'=>$msg]);
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
