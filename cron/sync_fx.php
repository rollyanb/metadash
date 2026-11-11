<?php
// cron/sync_fx.php — moedas das contas, cotações e conversão do histórico.
//
// Funciona por cron e pelo navegador. Para não estourar o tempo do servidor
// (erro 504), trabalha em blocos: se sobrar coisa, avisa — basta rodar de novo.
//
// Sugestão de agendamento: 0 5 * * *
ini_set('display_errors', 0);
set_time_limit(0);
date_default_timezone_set('America/Sao_Paulo');

$base = dirname(__DIR__);
require_once $base . '/config/database.php';
require_once $base . '/includes/fx.php';
require_once $base . '/includes/meta_api.php';

$db     = Database::getInstance();
$naWeb  = PHP_SAPI !== 'cli';
$inicio = time();
$LIMITE = $naWeb ? 18 : 240;     // segundos de trabalho por execução
$log    = [];

// Lista de dias (0..99 atrás) usada nas consultas
$DIAS_SQL = "SELECT CURDATE() - INTERVAL (a.n + b.n) DAY AS dia
             FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
                   UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
                  (SELECT 0 n UNION SELECT 10 UNION SELECT 20 UNION SELECT 30 UNION SELECT 40
                   UNION SELECT 50 UNION SELECT 60 UNION SELECT 70 UNION SELECT 80 UNION SELECT 90) b";

// ── 1. Moeda de cada conta, direto da Meta ───────────────────
// Sem este passo todas as contas ficam como BRL e nada é convertido.
$moedasAtualizadas = 0;
try {
    $api   = new MetaAPI();
    $token = $api->getTokenValue();
    $ver   = function_exists('metaApiVersion') ? metaApiVersion() : 'v21.0';

    if (!$token) {
        $log[] = '  token da Meta não configurado — moedas não verificadas';
    } else {
        foreach ($db->fetchAll("SELECT account_id, account_name, currency FROM ad_accounts") as $c) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://graph.facebook.com/{$ver}/{$c['account_id']}?fields=currency&access_token={$token}",
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            $r = $raw !== false ? json_decode($raw, true) : null;
            if (!$r || empty($r['currency'])) continue;

            $nova = strtoupper($r['currency']);
            if ($nova !== strtoupper((string)$c['currency'])) {
                $db->execute("UPDATE ad_accounts SET currency=? WHERE account_id=?", [$nova, $c['account_id']]);
                $log[] = "  \"{$c['account_name']}\": {$c['currency']} -> {$nova}";
                $moedasAtualizadas++;
            }
            usleep(100000);
            if (time() - $inicio > $LIMITE) { $log[] = '  (parcial - rode de novo)'; break; }
        }
    }
} catch (Exception $e) { $log[] = '  erro ao ler moedas: ' . $e->getMessage(); }

$moedas = array_column($db->fetchAll(
    "SELECT DISTINCT currency FROM ad_accounts WHERE currency IS NOT NULL AND currency <> 'BRL'"), 'currency');

if (!$moedas) {
    echo "FX: nenhuma conta em outra moeda ({$moedasAtualizadas} moeda(s) verificada(s)).\n" . implode("\n", $log) . "\n";
    exit;
}
$log[] = '  moedas em uso: ' . implode(', ', $moedas);

// ── 2. Cotações (um pedido traz 120 dias) ────────────────────
$novasCotacoes = 0;
foreach ($moedas as $m) {
    $falta = $db->fetch("SELECT COUNT(*) c FROM ({$DIAS_SQL}) d
                         LEFT JOIN fx_rates f ON f.currency = ? AND f.rate_date = d.dia
                         WHERE f.rate IS NULL", [$m]);
    if ((int)($falta['c'] ?? 0) === 0) continue;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://economia.awesomeapi.com.br/json/daily/{$m}-BRL/120",
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $lista = $raw !== false ? json_decode($raw, true) : null;

    if (is_array($lista)) {
        foreach ($lista as $item) {
            if (empty($item['timestamp']) || empty($item['bid'])) continue;
            try {
                $db->execute("INSERT INTO fx_rates (currency, rate_date, rate, source) VALUES (?,?,?,'api')
                              ON DUPLICATE KEY UPDATE rate=VALUES(rate)",
                    [$m, date('Y-m-d', (int)$item['timestamp']), (float)$item['bid']]);
                $novasCotacoes++;
            } catch (Exception $e) {}
        }
    }

    // fim de semana e feriado não têm cotação: repete a do último dia útil
    try {
        $db->execute("
            INSERT IGNORE INTO fx_rates (currency, rate_date, rate, source)
            SELECT ?, d.dia,
                   (SELECT f2.rate FROM fx_rates f2
                    WHERE f2.currency = ? AND f2.rate_date <= d.dia
                    ORDER BY f2.rate_date DESC LIMIT 1) AS r, 'fallback'
            FROM ({$DIAS_SQL}) d
            LEFT JOIN fx_rates f ON f.currency = ? AND f.rate_date = d.dia
            WHERE f.rate IS NULL
            HAVING r IS NOT NULL", [$m, $m, $m]);
    } catch (Exception $e) {}
}
$log[] = "  {$novasCotacoes} cotação(ões) salva(s)";

// ── 3. Converte o histórico ainda não convertido ─────────────
$convertidos = 0; $faltaConverter = 0; $estourou = false;
$tabelas = [
    ['campaign_snapshots', true], ['conversion_snapshots', true],
    ['campaign_conversion_snapshots', true], ['ad_creative_snapshots', false],
    ['local_snapshots', false],
];

foreach ($moedas as $m) {
    $contas = array_column($db->fetchAll("SELECT account_id FROM ad_accounts WHERE currency=?", [$m]), 'account_id');
    if (!$contas) continue;
    $ph = implode(',', array_fill(0, count($contas), '?'));

    foreach ($tabelas as $t) {
        list($tab, $temRev) = $t;
        try {
            $sql = $temRev
                ? "UPDATE {$tab} s
                   JOIN fx_rates f ON f.currency = ? AND f.rate_date = s.snapshot_date
                   SET s.spend_original = s.spend, s.revenue_original = s.revenue,
                       s.spend = ROUND(s.spend * f.rate, 2), s.revenue = ROUND(s.revenue * f.rate, 2),
                       s.currency = ?, s.fx_rate = f.rate
                   WHERE s.account_id IN ({$ph}) AND s.spend_original IS NULL"
                : "UPDATE {$tab} s
                   JOIN fx_rates f ON f.currency = ? AND f.rate_date = s.snapshot_date
                   SET s.spend_original = s.spend, s.spend = ROUND(s.spend * f.rate, 2), s.currency = ?
                   WHERE s.account_id IN ({$ph}) AND s.spend_original IS NULL";
            $convertidos += (int)$db->execute($sql, array_merge([$m, $m], $contas));

            $rest = $db->fetch("SELECT COUNT(*) c FROM {$tab}
                                WHERE account_id IN ({$ph}) AND spend_original IS NULL AND spend > 0", $contas);
            $faltaConverter += (int)($rest['c'] ?? 0);
        } catch (Exception $e) {
            $log[] = "  erro em {$tab}: " . mb_substr($e->getMessage(), 0, 90);
        }
        if (time() - $inicio > $LIMITE) { $estourou = true; break 2; }
    }
}

// ── 4. Limpa caches que guardavam valor sem conversão ────────
try { $db->execute("DELETE FROM projection_cache"); $log[] = '  cache de projeção limpo'; } catch (Exception $e) {}

$msg = "{$moedasAtualizadas} moeda(s) de conta · {$novasCotacoes} cotação(ões) · {$convertidos} registro(s) convertido(s)"
     . ($faltaConverter > 0 || $estourou ? " · ainda faltam ~{$faltaConverter} (rode de novo)" : ' · histórico completo');

echo "FX: {$msg}\n" . implode("\n", $log) . "\n";
try {
    $db->execute("INSERT INTO sync_log (sync_type, status, message) VALUES ('fx', ?, ?)",
        [($faltaConverter > 0 || $estourou) ? 'partial' : 'ok', $msg]);
} catch (Exception $e) {}
