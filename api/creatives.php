<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$db              = Database::getInstance();
$allowedAccounts = Auth::getAllowedAccounts();

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? $_GET['action'] ?? 'list';

// Verifica acesso à conta
function checkAccess($accountId, $allowed) {
    if ($allowed === null) return true;
    return in_array($accountId, $allowed);
}

// ── LIST — busca criativos de uma conta no período ────────────
if ($action === 'list') {
    $accountId = $body['account_id'] ?? $_GET['account_id'] ?? '';
    $dateStart = $body['date_start'] ?? date('Y-m-d', strtotime('-7 days'));
    $dateEnd   = $body['date_end']   ?? date('Y-m-d');

    if (!$accountId || !checkAccess($accountId, $allowedAccounts)) {
        echo json_encode(['error' => 'Sem acesso']); exit;
    }

    // Busca criativos com métricas agregadas do período
    $creatives = $db->fetchAll("
        SELECT
            ac.ad_id,
            ac.account_id,
            ac.ad_name,
            ac.is_dynamic,
            ac.thumbnail_url,
            ac.image_url,
            ac.title,
            ac.body,
            a.account_name,
            CASE
                WHEN ac.video_views > 0 THEN 'video'
                ELSE 'image'
            END as ad_type,
            COALESCE(SUM(s.impressions), ac.impressions) as impressions,
            COALESCE(SUM(s.clicks),      ac.clicks)      as clicks,
            COALESCE(SUM(s.spend),       ac.spend)       as spend,
            COALESCE(SUM(s.purchases),   ac.purchases)   as purchases,
            COALESCE(SUM(s.leads),       ac.leads)       as leads,
            COALESCE(SUM(s.revenue),     ac.revenue)     as revenue,
            COALESCE(AVG(s.roas),        ac.roas)        as roas,
            COALESCE(SUM(s.reach),       ac.reach)       as reach,
            CASE
                WHEN COALESCE(SUM(s.impressions), ac.impressions) > 0
                THEN ROUND(COALESCE(SUM(s.clicks), ac.clicks) / COALESCE(SUM(s.impressions), ac.impressions), 6)
                ELSE ac.ctr
            END as ctr
        FROM ad_creatives ac
        JOIN ad_accounts a ON a.account_id = ac.account_id
        LEFT JOIN ad_creative_snapshots s
            ON s.ad_id = ac.ad_id
            AND s.account_id = ac.account_id
            AND s.snapshot_date BETWEEN ? AND ?
        WHERE ac.account_id = ?
        GROUP BY ac.ad_id, ac.ad_name, ac.is_dynamic
        ORDER BY purchases DESC, leads DESC, spend DESC
        LIMIT 100
    ", [$dateStart, $dateEnd, $accountId]);

    echo json_encode(['success' => true, 'creatives' => $creatives], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── ASSETS — busca assets de um criativo dinâmico ─────────────
if ($action === 'assets') {
    $adId      = $body['ad_id']      ?? $_GET['ad_id'] ?? '';
    $accountId = $body['account_id'] ?? $_GET['account_id'] ?? '';

    if (!$adId || !checkAccess($accountId, $allowedAccounts)) {
        echo json_encode(['error' => 'Sem acesso']); exit;
    }

    $assets = $db->fetchAll("
        SELECT
            asset_id,
            asset_type,
            asset_name,
            thumbnail_url,
            SUM(impressions) as impressions,
            SUM(reach)       as reach,
            SUM(clicks)      as clicks,
            SUM(spend)       as spend,
            SUM(purchases)   as purchases,
            SUM(leads)       as leads,
            SUM(revenue)     as revenue,
            ROUND(SUM(spend) / NULLIF(SUM(purchases), 0), 2) as cpa,
            ROUND(SUM(spend) / NULLIF(SUM(leads), 0), 2)     as cpl,
            ROUND(SUM(revenue) / NULLIF(SUM(spend), 0), 2)   as roas
        FROM ad_creative_assets
        WHERE ad_id = ?
        GROUP BY asset_id, asset_type, asset_name, thumbnail_url
        ORDER BY purchases DESC, leads DESC, impressions DESC
    ", [$adId]);

    echo json_encode(['success' => true, 'assets' => $assets], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Ação inválida'], JSON_UNESCAPED_UNICODE);