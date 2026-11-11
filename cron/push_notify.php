<?php
// cron/push_notify.php — push por usuario, contas definidas pelo admin
// 5,35 * * * *   curl https://metadash.space/cron/push_notify.php

ini_set('display_errors', 0);
date_default_timezone_set('America/Sao_Paulo');
$base = dirname(__DIR__);

require_once $base . '/config/database.php';
require_once $base . '/includes/webpush.php';


define('VAPID_PUBLIC',  'BOD8saPXZbuLU_Tf1HvD-eNQekZBjmjcyuBeOhyd5Khld_THUGpP4adEgl_RD9q3YPr-oEDPezxS3buQk7Mippo');
define('VAPID_PRIVATE', 'ejsdX8aOvZVs8xuw9uvriYbtB-xHqmLsvgTuCJjX-II');
define('VAPID_EMAIL',   'mailto:contato@rollyan.com.br');

$db    = Database::getInstance();
$today = date('Y-m-d');
$now   = date('d/m H:i');

// Horário de envio configurável via painel (push_test.php)
$settingStart = $db->fetch("SELECT value FROM system_settings WHERE `key` = 'push_hour_start'");
$settingEnd   = $db->fetch("SELECT value FROM system_settings WHERE `key` = 'push_hour_end'");
$hourStart    = intval($settingStart['value'] ?? 10);
$hourEnd      = intval($settingEnd['value']   ?? 20);
$hourNow      = intval(date('H'));
if ($hourNow < $hourStart || $hourNow >= $hourEnd) exit;

// Garante que a tabela existe
$db->execute("CREATE TABLE IF NOT EXISTS push_last_sent (
    user_id    INT UNSIGNED NOT NULL,
    account_id VARCHAR(50)  NOT NULL COLLATE utf8mb4_unicode_ci,
    sent_date  DATE         NOT NULL,
    purchases  INT          DEFAULT 0,
    revenue    DECIMAL(12,2) DEFAULT 0,
    leads      INT          DEFAULT 0,
    spend      DECIMAL(12,2) DEFAULT 0,
    sent_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, account_id, sent_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Busca usuarios com inscricao push E contas configuradas
$users = $db->fetchAll("
    SELECT DISTINCT ps.user_id
    FROM push_subscriptions ps
    JOIN push_user_accounts pua ON pua.user_id = ps.user_id
");

if (empty($users)) exit;

$motivo = null;
if (!SimpleWebPush::disponivel($motivo)) {
    // Falha de ambiente: registra uma vez e sai, sem empilhar erro fatal a cada 30 min
    $db->execute("INSERT INTO sync_log (sync_type, status, message) VALUES ('push','error',?)",
        ['Push indisponível: ' . $motivo]);
    exit;
}

$webPush = new SimpleWebPush(VAPID_PUBLIC, VAPID_PRIVATE, VAPID_EMAIL);
$fila = [];   // envios pendentes

foreach ($users as $userRow) {
    $userId = $userRow['user_id'];

    // Contas configuradas para esse usuario
    $userAccounts = $db->fetchAll(
        "SELECT account_id FROM push_user_accounts WHERE user_id = ?",
        [$userId]
    );
    if (empty($userAccounts)) continue;

    $accountIds = array_column($userAccounts, 'account_id');
    $ph         = implode(',', array_fill(0, count($accountIds), '?'));

    // Dados atuais de hoje das contas desse usuario
    $current = $db->fetchAll("
        SELECT
            cs.account_id,
            a.account_name,
            COALESCE(cs.purchases, 0) as purchases,
            COALESCE(cs.revenue,   0) as revenue,
            COALESCE(cs.leads,     0) as leads,
            COALESCE(cs.spend,     0) as spend,
            COALESCE(cs.cpa,       0) as cpa,
            COALESCE(cs.cpl,       0) as cpl,
            COALESCE(cs.roas,      0) as roas
        FROM conversion_snapshots cs
        JOIN ad_accounts a ON a.account_id = cs.account_id
        WHERE cs.snapshot_date = ?
          AND cs.account_id IN ({$ph})
        ORDER BY cs.purchases DESC, cs.leads DESC
    ", array_merge([$today], $accountIds));

    if (empty($current)) continue;

    // Ultimo envio para esse usuario (tabela separada push_last_sent)
    $lastSent = $db->fetchAll("
        SELECT account_id, purchases, revenue, leads, spend
        FROM push_last_sent
        WHERE sent_date = ? AND user_id = ? AND account_id IN ({$ph})
    ", array_merge([$today, $userId], $accountIds));

    $lastMap = [];
    foreach ($lastSent as $ls) $lastMap[$ls['account_id']] = $ls;

    // Inscricoes push desse usuario
    $subs = $db->fetchAll(
        "SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?",
        [$userId]
    );
    if (empty($subs)) continue;

    // Calcula delta e envia um push por conta com novidade
    $hasAny = false;
    foreach ($current as $c) {
        $aid  = $c['account_id'];
        $last = $lastMap[$aid] ?? ['purchases'=>0,'revenue'=>0,'leads'=>0,'spend'=>0];

        $newP = max(0, intval($c['purchases'])   - intval($last['purchases']));
        $newR = max(0, floatval($c['revenue'])   - floatval($last['revenue']));
        $newL = max(0, intval($c['leads'])       - intval($last['leads']));

        if ($newP === 0 && $newL === 0) continue;
        $hasAny = true;

        // Monta body
        $body = '';
        if ($newP > 0) {
            $body .= "{$newP} novas compras · R$" . number_format($newR, 2, ',', '.') . "\n";
            $body .= "Total do dia: {$c['purchases']} compras\n";
            if ($c['cpa']  > 0) $body .= "Custo por compra: R$" . number_format($c['cpa'], 2, ',', '.') . "\n";
            if ($c['roas'] > 0) {
                $roi = $c['spend'] > 0 ? round((($c['revenue'] - $c['spend']) / $c['spend']) * 100, 1) : 0;
                $body .= "ROAS: {$c['roas']}x · ROI: {$roi}%";
            }
        }
        if ($newL > 0) {
            if ($body) $body .= "\n";
            $body .= "{$newL} novos leads (total: {$c['leads']})";
            if ($c['cpl'] > 0) $body .= "\nCusto por lead: R$" . number_format($c['cpl'], 2, ',', '.');
        }

        $payload = json_encode([
            'title' => $c['account_name'] . ' — ' . $now,
            'body'  => $body,
            'url'   => '/conversions.php',
        ]);

        foreach ($subs as $sub) {
            $fila[] = ['sub' => $sub, 'payload' => $payload];
        }
    }

    // Salva snapshot na tabela SEPARADA do push
    if ($hasAny) {
        foreach ($current as $c) {
            $db->execute("
                INSERT INTO push_last_sent (user_id, account_id, sent_date, purchases, revenue, leads, spend)
                VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    purchases=VALUES(purchases), revenue=VALUES(revenue),
                    leads=VALUES(leads), spend=VALUES(spend), sent_at=NOW()
            ", [$userId, $c['account_id'], $today, $c['purchases'], $c['revenue'], $c['leads'], $c['spend']]);
        }
    }
}

// Envia tudo e remove só as inscrições que a Meta/navegador disse estarem mortas
$enviados = 0; $falhas = 0; $removidos = 0;
foreach ($fila as $item) {
    $sub = $item['sub'];
    $r = $webPush->send($sub['endpoint'], $sub['p256dh'], $sub['auth'], $item['payload']);

    if ($r['ok']) {
        $enviados++;
    } else {
        $falhas++;
        // 404/410 = inscrição expirada; outros erros (rede, 5xx) NÃO apagam a inscrição
        if ($r['expirado']) {
            $db->execute("DELETE FROM push_subscriptions WHERE endpoint=?", [$sub['endpoint']]);
            $removidos++;
        }
    }
    usleep(120000);
}

if ($enviados || $falhas) {
    $db->execute("INSERT INTO sync_log (sync_type, status, message) VALUES ('push', ?, ?)",
        [$falhas ? 'partial' : 'ok',
         "{$enviados} enviado(s), {$falhas} falha(s)" . ($removidos ? ", {$removidos} inscrição(ões) expirada(s) removida(s)" : '')]);
}
echo "Push: {$enviados} enviados, {$falhas} falhas, {$removidos} removidos\n";