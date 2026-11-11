<?php
// cron/sync_local.php — métricas diárias dos clientes de negócio local.
//
// Diferente do sync de eventos: aqui o que importa é conversa iniciada no
// WhatsApp/Direct, alcance, frequência, engajamento e seguidores ganhos.
// Sugestão de agendamento: 15,45 * * * *
ini_set('display_errors', 0);
set_time_limit(0);
date_default_timezone_set('America/Sao_Paulo');

$base = dirname(__DIR__);
require_once $base . '/config/database.php';
require_once $base . '/includes/meta_api.php';
require_once $base . '/includes/fx.php';

$db  = Database::getInstance();
$api = new MetaAPI();
$token = $api->getTokenValue();
$ver   = function_exists('metaApiVersion') ? metaApiVersion() : 'v21.0';

if (!$token) { echo "Token da Meta não configurado\n"; exit; }

// Janela: últimos 32 dias (a Meta ainda ajusta números recentes)
$desde = date('Y-m-d', strtotime('-31 days'));
$ate   = date('Y-m-d');

$contas = $db->fetchAll("
    SELECT DISTINCT lca.account_id
    FROM local_client_accounts lca
    JOIN local_clients c ON c.id = lca.client_id AND c.status = 'active'");

if (!$contas) { echo "Nenhum cliente local com conta vinculada\n"; exit; }

/**
 * Soma os action_types que interessam. A Meta devolve variações redundantes
 * (omni_*, onsite_web_*), então casamos pelo nome canônico.
 */
function extrai($actions) {
    $m = [
        'conversations' => 0, 'first_replies' => 0, 'post_engagement' => 0,
        'page_engagement' => 0, 'reactions' => 0, 'saves' => 0,
        'link_clicks' => 0, 'video_views' => 0, 'page_likes' => 0,
        'follows' => 0, 'profile_visits' => 0,
    ];
    foreach ((array)$actions as $a) {
        $t = $a['action_type'] ?? '';
        $v = (int)($a['value'] ?? 0);
        if ($t === 'onsite_conversion.messaging_conversation_started_7d') $m['conversations']   += $v;
        elseif ($t === 'onsite_conversion.messaging_first_reply')         $m['first_replies']   += $v;
        elseif ($t === 'post_engagement')                                 $m['post_engagement'] += $v;
        elseif ($t === 'page_engagement')                                 $m['page_engagement'] += $v;
        elseif ($t === 'post_reaction')                                   $m['reactions']       += $v;
        elseif ($t === 'onsite_conversion.post_save')                     $m['saves']           += $v;
        elseif ($t === 'link_click')                                      $m['link_clicks']     += $v;
        elseif ($t === 'video_view')                                      $m['video_views']     += $v;
        elseif ($t === 'like')                                            $m['page_likes']      += $v;
        // seguidores: a Meta usa nomes diferentes conforme a plataforma/objetivo
        elseif ($t === 'follow' || $t === 'onsite_conversion.follow'
                || strpos($t, 'follow') !== false)                        $m['follows']         += $v;
        elseif (strpos($t, 'profile_visit') !== false
                || $t === 'onsite_conversion.ig_profile_visit')           $m['profile_visits']  += $v;
    }
    return $m;
}

$totalDias = 0; $erros = [];

foreach ($contas as $c) {
    $accountId = $c['account_id'];

    $url = "https://graph.facebook.com/{$ver}/{$accountId}/insights"
         . "?fields=spend,impressions,reach,frequency,clicks,actions"
         . "&level=account&time_increment=1"
         . "&time_range=" . urlencode(json_encode(['since' => $desde, 'until' => $ate]))
         . "&limit=200&access_token={$token}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45, CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $errC = curl_error($ch);
    curl_close($ch);

    $r = $raw !== false ? json_decode($raw, true) : null;
    if (!$r || isset($r['error'])) {
        $erros[] = $accountId . ': ' . ($r['error']['message'] ?? ($errC ?: 'erro na API'));
        continue;
    }

    $moedaL = FX::moedaDaConta($db, $accountId);
    foreach ($r['data'] ?? [] as $dia) {
        $m = extrai($dia['actions'] ?? []);
        $spendL = (float)($dia['spend'] ?? 0);
        $spendOrigL = $spendL;
        if ($moedaL !== 'BRL') { list($spendL, $txL) = FX::paraBRL($db, $spendOrigL, $moedaL, $dia['date_start']); }
        try {
            $db->execute("
                INSERT INTO local_snapshots
                  (account_id, snapshot_date, spend, impressions, reach, frequency, clicks,
                   conversations, first_replies, post_engagement, page_engagement, reactions,
                   saves, link_clicks, video_views, page_likes, follows, profile_visits, actions_raw,
                   spend_original, currency)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  spend=VALUES(spend), impressions=VALUES(impressions), reach=VALUES(reach),
                  frequency=VALUES(frequency), clicks=VALUES(clicks),
                  conversations=VALUES(conversations), first_replies=VALUES(first_replies),
                  post_engagement=VALUES(post_engagement), page_engagement=VALUES(page_engagement),
                  reactions=VALUES(reactions), saves=VALUES(saves), link_clicks=VALUES(link_clicks),
                  video_views=VALUES(video_views), page_likes=VALUES(page_likes),
                  follows=VALUES(follows), profile_visits=VALUES(profile_visits),
                  actions_raw=VALUES(actions_raw),
                  spend_original=VALUES(spend_original), currency=VALUES(currency)",
                [
                    $accountId, $dia['date_start'],
                    $spendL, (int)($dia['impressions'] ?? 0),
                    (int)($dia['reach'] ?? 0), (float)($dia['frequency'] ?? 0),
                    (int)($dia['clicks'] ?? 0),
                    $m['conversations'], $m['first_replies'], $m['post_engagement'],
                    $m['page_engagement'], $m['reactions'], $m['saves'], $m['link_clicks'],
                    $m['video_views'], $m['page_likes'], $m['follows'], $m['profile_visits'],
                    json_encode($dia['actions'] ?? [], JSON_UNESCAPED_UNICODE),
                    $spendOrigL, $moedaL,
                ]
            );
            $totalDias++;
        } catch (Exception $e) {
            $erros[] = $accountId . ' ' . $dia['date_start'] . ': ' . $e->getMessage();
        }
    }
    usleep(400000);   // respeita o rate limit
}

$msg = "{$totalDias} dia(s) gravado(s) em " . count($contas) . " conta(s)"
     . ($erros ? ' · ' . count($erros) . ' erro(s): ' . implode(' | ', array_slice($erros, 0, 3)) : '');
echo "Locais: {$msg}\n";
try {
    $db->execute("INSERT INTO sync_log (sync_type, status, message) VALUES ('local', ?, ?)",
        [$erros ? 'partial' : 'ok', $msg]);
} catch (Exception $e) {}
