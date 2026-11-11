<?php
// includes/projection.php — cálculo da projeção de gasto de um evento.
// Extraído da API para poder ser pré-calculado pelo cron (a consulta à Meta
// é lenta e, feita na hora, estourava o tempo do navegador).

require_once __DIR__ . '/meta_api.php';
require_once __DIR__ . '/fx.php';
require_once __DIR__ . '/event_campaign_sync.php';

if (!function_exists('statusRealCampanha')) {
    function statusRealCampanha($c) {
        $eff  = strtoupper((string)($c['effective_status'] ?? ''));
        $conf = strtoupper((string)($c['status'] ?? ''));
        $stop = $c['stop_time'] ?? null;
        if ($stop && strtotime($stop) < time())      return ['ENCERRADA', false];
        if (in_array($eff, ['ARCHIVED','DELETED']))  return ['ARQUIVADA', false];
        if ($eff === 'ACTIVE')                       return ['ATIVA', true];
        if ($eff !== '')                             return ['PAUSADA', false];
        if ($conf === 'ACTIVE')                      return ['ATIVA', true];
        if (in_array($conf, ['ARCHIVED','DELETED'])) return ['ARQUIVADA', false];
        return ['PAUSADA', false];
    }
}

/**
 * Calcula a projeção e grava em projection_cache.
 * Retorna o array de saída (mesmo formato da API).
 */
function calcularProjecao($db, $eventId) {
    $event = $db->fetch("SELECT * FROM events WHERE id=?", [$eventId]);
    if (!$event) return ['error' => 'Evento não encontrado'];
    try {
        list($dateStart, $dateEnd) = eventCampaignDateRange($event, true);
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }

    // Orçamento vem da Meta na moeda da conta. Como o gasto já está em BRL,
    // é preciso converter o orçamento também — senão US$600 vira "R$600".
    // ATENÇÃO: um evento pode ter contas em moedas diferentes (ex.: uma em BRL
    // e outra em USD). Por isso a moeda NÃO é resolvida no nível do evento —
    // ela é resolvida campanha por campanha, mais abaixo. Aqui só pegamos a
    // moeda predominante, usada como último recurso e para exibição.
    $moedaEv = 'BRL'; $taxaEv = 1.0;
    try {
        $accEv = $db->fetch("SELECT a.currency, COUNT(*) n
                             FROM event_accounts ea
                             JOIN ad_accounts a ON a.account_id = ea.account_id
                             WHERE ea.event_id = ? AND a.currency IS NOT NULL AND a.currency <> ''
                             GROUP BY a.currency ORDER BY n DESC, a.currency <> 'BRL' DESC LIMIT 1",
                            [$eventId]);
        if ($accEv && !empty($accEv['currency'])) $moedaEv = strtoupper($accEv['currency']);
        if ($moedaEv === 'BRL' && !empty($event['account_id'])) {
            $moedaEv = FX::moedaDaConta($db, $event['account_id']);
        }
        if ($moedaEv !== 'BRL') $taxaEv = FX::rate($db, $moedaEv);
    } catch (Exception $e) {}

    $budget = floatval($event['budget'] ?? 0);
    if (!$budget) return ['error' => 'Nenhum budget definido para o evento'];

    // Se o budget foi cadastrado em outra moeda, converte para BRL também,
    // senão a comparação com o gasto (já em BRL) fica errada.
    $moedaBudget = strtoupper($event['budget_currency'] ?? 'BRL');
    if ($moedaBudget !== 'BRL') {
        $budget = round($budget * FX::rate($db, $moedaBudget), 2);
    }

    // Campanhas vinculadas com status e gasto real
    $campaigns = $db->fetchAll(
        "SELECT ec.campaign_id, ec.campaign_name, ec.objective, ec.status,
                ec.effective_status, ec.stop_time,
                COALESCE(SUM(cs.spend),0) AS spent_real
         FROM event_campaigns ec
         LEFT JOIN campaign_snapshots cs ON cs.campaign_id = ec.campaign_id
              AND cs.snapshot_date BETWEEN ? AND ?
         WHERE ec.event_id = ?
         GROUP BY ec.campaign_id, ec.campaign_name, ec.objective, ec.status,
                  ec.effective_status, ec.stop_time",
        [$dateStart, $dateEnd, $eventId]
    );

    if (empty($campaigns)) {
        return ['error' => 'Nenhuma campanha vinculada'];
    }

    // ── MOEDA POR CAMPANHA ─────────────────────────────────────
    // O orçamento vem da Meta na moeda da conta que roda a campanha. Como o
    // gasto gravado já está em BRL, o orçamento precisa da MESMA taxa — e num
    // evento com contas em moedas diferentes a taxa muda de campanha para
    // campanha. Se o orçamento não for convertido, aparece "orçamento R$150"
    // ao lado de "gasto R$323" na mesma campanha.
    //
    // A fonte mais confiável é o próprio snapshot: é lá que o sync_fx grava a
    // moeda e a taxa que REALMENTE foram aplicadas ao gasto. O cadastro da
    // conta é só o plano B (campanha nova, sem snapshot ainda).
    $moedaCampanha = []; $taxaCampanha = [];
    $cids = array_column($campaigns, 'campaign_id');
    $phC  = implode(',', array_fill(0, count($cids), '?'));
    try {
        $linhas = $db->fetchAll(
            "SELECT cs.campaign_id,
                    MAX(COALESCE(NULLIF(cs.currency,''), a.currency, 'BRL')) AS moeda,
                    MAX(cs.fx_rate) AS taxa
             FROM campaign_snapshots cs
             LEFT JOIN ad_accounts a ON a.account_id = cs.account_id
             WHERE cs.campaign_id IN ({$phC})
               AND cs.snapshot_date BETWEEN ? AND ?
             GROUP BY cs.campaign_id", array_merge($cids, [$dateStart, $dateEnd]));
        foreach ($linhas as $l) {
            $m = strtoupper((string)$l['moeda']);
            if ($m === '' || $m === 'BRL') continue;
            $moedaCampanha[$l['campaign_id']] = $m;
            // fx_rate do snapshot é a taxa histórica do gasto; para o orçamento
            // (que é um valor de hoje) usamos a cotação corrente.
            $taxaCampanha[$l['campaign_id']] = FX::rate($db, $m);
        }
    } catch (Exception $e) {}

    // Plano B: campanha sem snapshot herda a moeda predominante do evento.
    foreach ($cids as $cid) {
        if (!isset($taxaCampanha[$cid]) && $moedaEv !== 'BRL') {
            $moedaCampanha[$cid] = $moedaEv;
            $taxaCampanha[$cid]  = $taxaEv;
        }
    }

    // Taxa de uma campanha específica (1.0 = já está em BRL).
    $taxaDe = function ($cid) use (&$taxaCampanha) {
        $t = isset($taxaCampanha[$cid]) ? (float)$taxaCampanha[$cid] : 1.0;
        return $t > 0 ? $t : 1.0;
    };

    // Busca orçamentos via API do Meta (só campanhas ATIVAS)
    require_once __DIR__ . '/../includes/meta_api.php';
    $api   = new MetaAPI();
    $token = $api->getTokenValue();
    $ver   = metaApiVersion();

    // Só campanhas que estão MESMO entregando entram na projeção
    $activeCampaignIds = [];
    foreach ($campaigns as $i => $c) {
        list($label, $ativa) = statusRealCampanha($c);
        $campaigns[$i]['status_label'] = $label;
        $campaigns[$i]['is_active']    = $ativa;
        if ($ativa) $activeCampaignIds[] = $c['campaign_id'];
    }

    $metaBudgets = [];
    if (!empty($activeCampaignIds) && $token) {
        // Busca em lote via batch request — orçamento no nível da campanha (CBO)
        // Graph API limita a 50 IDs por chamada, então quebramos em chunks.
        // Timeout curto (8s) pra não segurar a conexão com o banco caso a Meta demore.
        foreach (array_chunk($activeCampaignIds, 50) as $chunk) {
            $ids = implode(',', $chunk);
            $url = "https://graph.facebook.com/{$ver}/?ids={$ids}"
                 . "&fields=id,status,lifetime_budget,daily_budget,budget_remaining,account_id"
                 . "&access_token={$token}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            $r = $raw !== false ? json_decode($raw, true) : null;

            if ($r && !isset($r['error'])) {
                foreach ($r as $cid => $data) {
                    // A Meta diz de qual conta a campanha é. Isso é mais
                    // confiável do que qualquer dedução nossa — principalmente
                    // para campanha nova, que ainda não tem snapshot.
                    if (!empty($data['account_id'])) {
                        $accCid = 'act_' . preg_replace('/^act_/', '', (string)$data['account_id']);
                        $mCid   = FX::moedaDaConta($db, $accCid);
                        if ($mCid === 'BRL') {
                            unset($moedaCampanha[$cid]);
                            $taxaCampanha[$cid] = 1.0;
                        } else {
                            $moedaCampanha[$cid] = $mCid;
                            $taxaCampanha[$cid]  = FX::rate($db, $mCid);
                        }
                    }
                    $tx = $taxaDe($cid);
                    $metaBudgets[$cid] = [
                        'lifetime_budget' => isset($data['lifetime_budget'])  ? $data['lifetime_budget']  / 100 * $tx : null,
                        'daily_budget'    => isset($data['daily_budget'])     ? $data['daily_budget']     / 100 * $tx : null,
                        'budget_remaining'=> isset($data['budget_remaining']) ? $data['budget_remaining'] / 100 * $tx : null,
                        'status'          => $data['status'] ?? 'UNKNOWN',
                    ];
                }
            }
            // Se essa chamada falhar/der timeout, seguimos sem os dados dela em vez de travar tudo.
        }

        // ── FALLBACK ABO ───────────────────────────────────────────
        // Campanhas sem lifetime_budget E sem daily_budget usam orçamento por ad set (ABO).
        // Busca os ad sets ATIVOS de cada uma dessas campanhas e soma os orçamentos.
        $aboCampaignIds = [];
        foreach ($activeCampaignIds as $cid) {
            $m = $metaBudgets[$cid] ?? null;
            if (!$m || ($m['lifetime_budget'] === null && $m['daily_budget'] === null)) {
                $aboCampaignIds[] = $cid;
            }
        }
        // Busca os ad sets de TODAS as campanhas ABO em paralelo (curl_multi),
        // em blocos de 10. Antes eram chamadas sequenciais com teto de 20 campanhas,
        // o que deixava eventos grandes com campanhas "sem budget".
        foreach (array_chunk($aboCampaignIds, 10) as $bloco) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($bloco as $cid) {
                $asUrl = "https://graph.facebook.com/{$ver}/{$cid}/adsets"
                       . "?fields=id,status,effective_status,lifetime_budget,daily_budget"
                       . "&limit=200&access_token={$token}";
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $asUrl, CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$cid] = $ch;
            }

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 1.0);
            } while ($running > 0);

            foreach ($handles as $cid => $ch) {
                $rawAs = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                $asResp = $rawAs !== false ? json_decode($rawAs, true) : null;
                if (!$asResp || isset($asResp['error']) || empty($asResp['data'])) continue;

                $aboLifetime = 0; $aboDaily = 0;
                $hasLifetime = false; $hasDaily = false;
                $txAbo = $taxaDe($cid);

                foreach ($asResp['data'] as $adset) {
                    // Conta o conjunto que está entregando. Se a Meta não devolver
                    // effective_status, cai no status configurado.
                    $eff  = strtoupper((string)($adset['effective_status'] ?? ''));
                    $conf = strtoupper((string)($adset['status'] ?? ''));
                    $entregando = $eff !== '' ? in_array($eff, ['ACTIVE', 'PENDING_REVIEW', 'PREAPPROVED'])
                                              : ($conf === 'ACTIVE');
                    if (!$entregando) continue;

                    if (isset($adset['lifetime_budget']) && floatval($adset['lifetime_budget']) > 0) {
                        $aboLifetime += floatval($adset['lifetime_budget']) / 100 * $txAbo;
                        $hasLifetime = true;
                    } elseif (isset($adset['daily_budget']) && floatval($adset['daily_budget']) > 0) {
                        $aboDaily += floatval($adset['daily_budget']) / 100 * $txAbo;
                        $hasDaily = true;
                    }
                }

                if ($hasLifetime || $hasDaily) {
                    $metaBudgets[$cid] = [
                        'lifetime_budget'  => $hasLifetime ? $aboLifetime : null,
                        'daily_budget'     => $hasDaily    ? $aboDaily    : null,
                        'budget_remaining' => null,
                        'status'           => 'ACTIVE',
                        'is_abo'           => true,
                    ];
                }
            }
            curl_multi_close($mh);
        }
    }

    // Monta projeção por campanha
    $rows        = [];
    $spentAll    = 0; // gasto real total (ativas + pausadas)
    $projectedAll= 0; // projeção de gasto total
    $hasWarning  = false;

    foreach ($campaigns as $c) {
        $cid       = $c['campaign_id'];
        // usa o status real (effective_status), não o configurado
        $isActive  = $c['is_active'] ?? ($c['status'] === 'ACTIVE');
        $spentReal = floatval($c['spent_real']);
        $spentAll += $spentReal;

        $meta      = $metaBudgets[$cid] ?? null;
        $budgetMeta= null;
        $budgetType= null;
        $isAbo     = $meta['is_abo'] ?? false;
        $projected = $spentReal; // pausada: não vai mais gastar

        if ($isActive && $meta) {
            if ($meta['lifetime_budget'] !== null) {
                $budgetMeta = $meta['lifetime_budget'];
                $budgetType = $isAbo ? 'lifetime (ABO)' : 'lifetime';
                $projected  = $budgetMeta; // vai gastar até o lifetime
            } elseif ($meta['daily_budget'] !== null) {
                $budgetMeta = $meta['daily_budget'];
                $budgetType = $isAbo ? 'daily (ABO)' : 'daily';
                // Estima dias restantes até o evento
                $daysLeft   = $event['date_event']
                    ? max(1, ceil((strtotime($event['date_event']) - time()) / 86400))
                    : 7;
                $projected  = $spentReal + ($budgetMeta * $daysLeft);
            }
        }

        $projectedAll += $projected;

        $rows[] = [
            'campaign_id'   => $cid,
            'campaign_name' => $c['campaign_name'],
            'objective'     => $c['objective'],
            'status'        => $c['status'],
            'status_label'  => $c['status_label'] ?? null,
            'is_active'     => $isActive,
            'no_budget_reason' => ($isActive && !$meta)
                ? 'A Meta não retornou orçamento para esta campanha (nem na campanha, nem nos conjuntos ativos). Projeção usa só o gasto real.'
                : null,
            'currency'      => $moedaCampanha[$cid] ?? 'BRL',
            'fx_rate'       => $taxaDe($cid),
            'spent_real'    => $spentReal,
            'budget_meta'   => $budgetMeta,
            'budget_type'   => $budgetType,
            'projected'     => round($projected, 2),
        ];
    }

    $projectedAll  = round($projectedAll, 2);
    $margin        = round($budget - $projectedAll, 2);
    $willOverflow  = $projectedAll > $budget;
    $overflowBy    = $willOverflow ? round($projectedAll - $budget, 2) : 0;


    $saida = [
        'success'       => true,
        'budget'        => $budget,
        'spent_all'     => round($spentAll, 2),
        'projected_all' => $projectedAll,
        'margin'        => $margin,
        'will_overflow' => $willOverflow,
        'overflow_by'   => $overflowBy,
        'date_start'    => $dateStart,
        'date_end'      => $dateEnd,
        'campaigns'     => $rows,
        'currency'      => $moedaEv,
        'fx_rate'       => $taxaEv,
        // Moedas presentes no evento — permite avisar quando há mistura.
        'moedas'        => array_values(array_unique(array_merge(['BRL'], array_values($moedaCampanha)))),
    ];
    try {
        $db->execute("INSERT INTO projection_cache (event_id, payload) VALUES (?,?)
                      ON DUPLICATE KEY UPDATE payload=VALUES(payload), updated_at=NOW()",
                     [$eventId, json_encode($saida, JSON_UNESCAPED_UNICODE)]);
    } catch (Exception $e) {}
    return $saida;
}
