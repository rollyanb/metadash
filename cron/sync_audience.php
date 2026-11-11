<?php
// cron/sync_audience.php — perfil de audiência dos eventos ativos.
//
// Busca os breakdowns que a Meta disponibiliza (idade, gênero, região, hora do
// dia e plataforma) para as campanhas vinculadas a cada evento.
//
// Observação importante: desde as mudanças de privacidade de 2021 a Meta
// restringe métricas de conversão de site quando há breakdown. Por isso
// gravamos compras quando vêm, mas a leitura confiável é de alcance/cliques.
//
// Sugestão de agendamento: 0 */6 * * *
ini_set('display_errors', 0);
set_time_limit(0);
date_default_timezone_set('America/Sao_Paulo');

$base = dirname(__DIR__);
require_once $base . '/config/database.php';
require_once $base . '/includes/meta_api.php';

$db    = Database::getInstance();
$api   = new MetaAPI();
$token = $api->getTokenValue();
$ver   = function_exists('metaApiVersion') ? metaApiVersion() : 'v21.0';
if (!$token) { echo "Token da Meta não configurado\n"; exit; }

$eventos = $db->fetchAll("
    SELECT id, name, date_campaign_start
    FROM events WHERE status = 'active'");

$cortes = [
    'age'      => 'age',
    'gender'   => 'gender',
    'region'   => 'region',
    'hour'     => 'hourly_stats_aggregated_by_advertiser_time_zone',
    'platform' => 'publisher_platform,platform_position',
];

$totLinhas = 0; $erros = [];

foreach ($eventos as $ev) {
    $eventId = (int)$ev['id'];

    $contas = array_column($db->fetchAll("SELECT account_id FROM event_accounts WHERE event_id=?", [$eventId]), 'account_id');
    if (!$contas) continue;

    $campaignIds = array_column($db->fetchAll("SELECT campaign_id FROM event_campaigns WHERE event_id=?", [$eventId]), 'campaign_id');
    if (!$campaignIds) continue;

    $desde = $ev['date_campaign_start'] ?: date('Y-m-d', strtotime('-60 days'));
    $ate   = date('Y-m-d');

    $filtro = json_encode([[
        'field' => 'campaign.id', 'operator' => 'IN', 'value' => $campaignIds,
    ]]);

    $acumulado = [];   // tipo => valor => métricas

    foreach ($cortes as $tipo => $breakdown) {
        foreach ($contas as $acc) {
            $url = "https://graph.facebook.com/{$ver}/{$acc}/insights"
                 . "?fields=impressions,reach,clicks,spend,actions"
                 . "&level=account"
                 . "&breakdowns=" . urlencode($breakdown)
                 . "&time_range=" . urlencode(json_encode(['since' => $desde, 'until' => $ate]))
                 . "&filtering=" . urlencode($filtro)
                 . "&limit=500&access_token={$token}";

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
                $erros[] = "{$ev['name']}/{$tipo}: " . ($r['error']['message'] ?? ($errC ?: 'erro'));
                continue;
            }

            foreach ($r['data'] ?? [] as $linha) {
                // identifica o valor do corte
                if ($tipo === 'age')        $valor = $linha['age'] ?? '?';
                elseif ($tipo === 'gender') $valor = $linha['gender'] ?? '?';
                elseif ($tipo === 'region') $valor = $linha['region'] ?? '?';
                elseif ($tipo === 'hour')   $valor = substr((string)($linha['hourly_stats_aggregated_by_advertiser_time_zone'] ?? '00'), 0, 2);
                else $valor = trim(($linha['publisher_platform'] ?? '?') . ' · ' . ($linha['platform_position'] ?? ''), ' ·');

                $compras = 0; $leads = 0;
                foreach ($linha['actions'] ?? [] as $a) {
                    $t = $a['action_type'] ?? '';
                    if ($t === 'offsite_conversion.fb_pixel_purchase' || $t === 'purchase') $compras += (int)$a['value'];
                    if ($t === 'offsite_conversion.fb_pixel_lead' || $t === 'lead')         $leads   += (int)$a['value'];
                }

                if (!isset($acumulado[$tipo][$valor])) {
                    $acumulado[$tipo][$valor] = ['impressions'=>0,'reach'=>0,'clicks'=>0,'spend'=>0,'purchases'=>0,'leads'=>0];
                }
                $acc2 = &$acumulado[$tipo][$valor];
                $acc2['impressions'] += (int)($linha['impressions'] ?? 0);
                $acc2['reach']       += (int)($linha['reach'] ?? 0);
                $acc2['clicks']      += (int)($linha['clicks'] ?? 0);
                $acc2['spend']       += (float)($linha['spend'] ?? 0);
                $acc2['purchases']   += $compras;
                $acc2['leads']       += $leads;
                unset($acc2);
            }
            usleep(400000);
        }
    }

    // ── Janelas de atribuição (compra por origem) ────────────────
    // Sem breakdown demográfico a Meta devolve conversão normalmente, então
    // aqui dá para saber quanto veio de clique recente, clique antigo ou view.
    foreach ($contas as $acc) {
        $urlA = "https://graph.facebook.com/{$ver}/{$acc}/insights"
              . "?fields=actions,action_values,spend"
              . "&level=account"
              . "&action_attribution_windows=" . urlencode('["1d_click","7d_click","1d_view"]')
              . "&time_range=" . urlencode(json_encode(['since' => $desde, 'until' => $ate]))
              . "&filtering=" . urlencode($filtro)
              . "&limit=100&access_token={$token}";

        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL=>$urlA, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>45]);
        $rawA = curl_exec($ch); curl_close($ch);
        $rA = $rawA !== false ? json_decode($rawA, true) : null;
        if (!$rA || isset($rA['error'])) continue;

        foreach ($rA['data'] ?? [] as $linha) {
            foreach ($linha['actions'] ?? [] as $a) {
                $t = $a['action_type'] ?? '';
                if ($t !== 'offsite_conversion.fb_pixel_purchase' && $t !== 'purchase') continue;
                foreach (['1d_click' => 'Clique (1 dia)', '7d_click' => 'Clique (7 dias)', '1d_view' => 'Visualização (1 dia)'] as $jan => $rot) {
                    if (!isset($a[$jan])) continue;
                    if (!isset($acumulado['atribuicao'][$rot])) {
                        $acumulado['atribuicao'][$rot] = ['impressions'=>0,'reach'=>0,'clicks'=>0,'spend'=>0,'purchases'=>0,'leads'=>0];
                    }
                    $acumulado['atribuicao'][$rot]['purchases'] += (int)$a[$jan];
                    $acumulado['atribuicao'][$rot]['impressions'] += (int)$a[$jan]; // usado só para ordenar/proporção
                }
            }
        }
        usleep(400000);
    }

    // ── Funil completo (etapas que a Meta devolve sem breakdown) ──
    foreach ($contas as $acc) {
        $urlF = "https://graph.facebook.com/{$ver}/{$acc}/insights"
              . "?fields=actions,action_values"
              . "&level=account"
              . "&time_range=" . urlencode(json_encode(['since' => $desde, 'until' => $ate]))
              . "&filtering=" . urlencode($filtro)
              . "&limit=100&access_token={$token}";
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL=>$urlF, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>45]);
        $rawF = curl_exec($ch); curl_close($ch);
        $rF = $rawF !== false ? json_decode($rawF, true) : null;
        if (!$rF || isset($rF['error'])) continue;

        $mapaFunil = [
            'offsite_conversion.fb_pixel_view_content'      => 'Visualizou',
            'landing_page_view'                             => 'Abriu a página',
            'offsite_conversion.fb_pixel_add_to_cart'       => 'Carrinho',
            'offsite_conversion.fb_pixel_initiate_checkout' => 'Checkout',
            'offsite_conversion.fb_pixel_add_payment_info'  => 'Pagamento',
            'offsite_conversion.fb_pixel_purchase'          => 'Compra',
        ];
        foreach ($rF['data'] ?? [] as $linha) {
            foreach ($linha['actions'] ?? [] as $a) {
                $rot = $mapaFunil[$a['action_type'] ?? ''] ?? null;
                if (!$rot) continue;
                if (!isset($acumulado['funil'][$rot])) {
                    $acumulado['funil'][$rot] = ['impressions'=>0,'reach'=>0,'clicks'=>0,'spend'=>0,'purchases'=>0,'leads'=>0];
                }
                $acumulado['funil'][$rot]['purchases']   += (int)$a['value'];
                $acumulado['funil'][$rot]['impressions'] += (int)$a['value'];
            }
        }
        usleep(300000);
    }

    // grava (substitui o perfil anterior do evento)
    if ($acumulado) {
        try {
            $db->execute("DELETE FROM event_audience WHERE event_id=?", [$eventId]);
            foreach ($acumulado as $tipo => $valores) {
                foreach ($valores as $valor => $m) {
                    $db->execute("INSERT INTO event_audience
                        (event_id, tipo, valor, impressions, reach, clicks, spend, purchases, leads)
                        VALUES (?,?,?,?,?,?,?,?,?)",
                        [$eventId, $tipo, mb_substr((string)$valor, 0, 60), $m['impressions'], $m['reach'],
                         $m['clicks'], round($m['spend'], 2), $m['purchases'], $m['leads']]);
                    $totLinhas++;
                }
            }
        } catch (Exception $e) { $erros[] = "{$ev['name']}: " . $e->getMessage(); }
    }

    echo "[{$ev['name']}] perfil atualizado\n";
}

$msg = "{$totLinhas} linha(s) de perfil em " . count($eventos) . " evento(s)"
     . ($erros ? ' · ' . count($erros) . ' erro(s): ' . implode(' | ', array_slice($erros, 0, 3)) : '');
echo "Perfil: {$msg}\n";
try {
    $db->execute("INSERT INTO sync_log (sync_type, status, message) VALUES ('audience', ?, ?)",
        [$erros ? 'partial' : 'ok', $msg]);
} catch (Exception $e) {}
