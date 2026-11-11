<?php
// api/sync_conversions.php — sync de conversões via AJAX
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
set_time_limit(300);

function j($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

require_once __DIR__ . '/../includes/auth.php';
if (!Auth::isLoggedIn() || Auth::getRole() !== 'admin') j(['error'=>'Acesso negado']);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/meta_api.php';
require_once __DIR__ . '/../includes/fx.php';

$db  = Database::getInstance();
$api = new MetaAPI();
if (!$api->hasToken()) j(['success'=>false,'message'=>'Token não configurado']);

$token     = $api->getTokenValue();
$ver       = metaApiVersion();
$dateStart = date('Y-m-d', strtotime('-32 days'));
$dateEnd   = date('Y-m-d');
$accounts  = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE status = 'ACTIVE'");
$synced = 0; $errors = 0;

foreach ($accounts as $account) {
    $accountId = $account['account_id'];
    try {
        $url = "https://graph.facebook.com/{$ver}/{$accountId}/insights"
             . "?fields=spend,actions,action_values"
             . "&time_range=" . urlencode('{"since":"'.$dateStart.'","until":"'.$dateEnd.'"}')
             . "&time_increment=1&level=account&limit=50"
             . "&access_token={$token}";

        $r = metaGet($url);
        if (!$r || isset($r['error'])) { $errors++; continue; }

        foreach ($r['data'] ?? [] as $day) {
            $date  = $day['date_start'];
            $spend = floatval($day['spend'] ?? 0);
            $views = $cart = $checkout = $purchases = $leads = 0;
            $revenue = 0.0;

            foreach ($day['actions'] ?? [] as $a) {
                $v = intval($a['value']); $t = $a['action_type'];
                if ($t === 'offsite_conversion.fb_pixel_view_content')      $views += $v;
                if ($t === 'offsite_conversion.fb_pixel_add_to_cart')       $cart  += $v;
                if ($t === 'offsite_conversion.fb_pixel_initiate_checkout')  $checkout += $v;
                if ($t === 'offsite_conversion.fb_pixel_purchase')          $purchases += $v;
                if ($t === 'offsite_conversion.fb_pixel_lead')              $leads += $v;
            }
            foreach ($day['action_values'] ?? [] as $a) {
                if ($a['action_type'] === 'offsite_conversion.fb_pixel_purchase') $revenue += floatval($a['value']);
            }

            // conversão de moeda (conta USD/EUR) — grava sempre em BRL
            $moedaV = FX::moedaDaConta($db, $accountId);
            $spendOrigV = $spend; $revOrigV = $revenue; $taxaV = 1.0;
            if ($moedaV !== 'BRL') {
                list($spend, $taxaV) = FX::paraBRL($db, $spendOrigV, $moedaV, $date);
                list($revenue, $zz)  = FX::paraBRL($db, $revOrigV, $moedaV, $date);
            }
            $cpv=$views>0?round($spend/$views,4):0; $cpaCart=$cart>0?round($spend/$cart,2):0;
            $cpaCheck=$checkout>0?round($spend/$checkout,2):0; $cpa=$purchases>0?round($spend/$purchases,2):0;
            $cpl=$leads>0?round($spend/$leads,2):0; $roas=$spend>0?round($revenue/$spend,2):0;

            $db->execute("INSERT INTO conversion_snapshots
                (account_id,snapshot_date,views,add_to_cart,checkout,purchases,leads,revenue,spend,cpv,cpa_cart,cpa_checkout,cpa,cpl,roas,spend_original,revenue_original,currency,fx_rate)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE views=VALUES(views),add_to_cart=VALUES(add_to_cart),checkout=VALUES(checkout),
                purchases=VALUES(purchases),leads=VALUES(leads),revenue=VALUES(revenue),spend=VALUES(spend),
                cpv=VALUES(cpv),cpa_cart=VALUES(cpa_cart),cpa_checkout=VALUES(cpa_checkout),
                cpa=VALUES(cpa),cpl=VALUES(cpl),roas=VALUES(roas),
                spend_original=VALUES(spend_original),revenue_original=VALUES(revenue_original),
                currency=VALUES(currency),fx_rate=VALUES(fx_rate),synced_at=NOW()",
            [$accountId,$date,$views,$cart,$checkout,$purchases,$leads,$revenue,$spend,$cpv,$cpaCart,$cpaCheck,$cpa,$cpl,$roas,$spendOrigV,$revOrigV,$moedaV,$taxaV]);
            $synced++;
        }
        usleep(200000);
    } catch (Exception $e) { $errors++; }
}

j(['success'=>true,'message'=>count($accounts)." contas | {$synced} registros | {$errors} erros"]);