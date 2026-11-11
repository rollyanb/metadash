<?php
// includes/fx.php — conversão de moeda para BRL.
//
// Contas de anúncio podem estar em USD, EUR etc. Como o sistema soma dinheiro
// de várias contas no mesmo número (evento com 2 contas, dashboard, relatório),
// converter só na exibição daria resultado errado. Então convertemos na
// GRAVAÇÃO: as colunas de dinheiro guardam sempre BRL, e o valor original
// fica ao lado para auditoria.
//
// Ordem de preferência da cotação:
//   1. cotação fixa definida em Configurações (modo manual)
//   2. cotação do dia já salva em fx_rates
//   3. cotação buscada na API pública (e salva)
//   4. última cotação conhecida
//   5. valor de segurança embutido

class FX {

    const FALLBACK = ['USD' => 5.40, 'EUR' => 5.90, 'GBP' => 6.90, 'ARS' => 0.0055];

    /** Cotação de 1 unidade da moeda em BRL, na data informada. */
    public static function rate($db, $currency, $date = null) {
        $currency = strtoupper(trim((string)$currency));
        if ($currency === '' || $currency === 'BRL') return 1.0;
        $date = $date ?: date('Y-m-d');
        if ($date > date('Y-m-d')) $date = date('Y-m-d');

        // 1) cotação fixa configurada
        try {
            $modo = $db->fetch("SELECT `value` v FROM system_settings WHERE `key`='fx_mode'");
            if ($modo && $modo['v'] === 'manual') {
                $chave = 'fx_fixed_' . strtolower($currency);
                $fix = $db->fetch("SELECT `value` v FROM system_settings WHERE `key`=?", [$chave]);
                $val = $fix ? (float)str_replace(',', '.', $fix['v']) : 0;
                if ($val > 0) return $val;
            }
        } catch (Exception $e) {}

        // 2) já temos a cotação do dia
        try {
            $r = $db->fetch("SELECT rate FROM fx_rates WHERE currency=? AND rate_date=?", [$currency, $date]);
            if ($r && (float)$r['rate'] > 0) return (float)$r['rate'];
        } catch (Exception $e) {}

        // 3) busca na API e salva
        $novo = self::buscarApi($currency, $date);
        if ($novo > 0) {
            try {
                $db->execute("INSERT INTO fx_rates (currency, rate_date, rate, source) VALUES (?,?,?,'api')
                              ON DUPLICATE KEY UPDATE rate=VALUES(rate), source='api'",
                    [$currency, $date, $novo]);
            } catch (Exception $e) {}
            return $novo;
        }

        // 4) última cotação conhecida
        try {
            $u = $db->fetch("SELECT rate FROM fx_rates WHERE currency=? ORDER BY rate_date DESC LIMIT 1", [$currency]);
            if ($u && (float)$u['rate'] > 0) return (float)$u['rate'];
        } catch (Exception $e) {}

        // 5) valor de segurança
        return self::FALLBACK[$currency] ?? 1.0;
    }

    /** Converte para BRL. Devolve [valorBRL, cotação]. */
    public static function paraBRL($db, $valor, $currency, $date = null) {
        $taxa = self::rate($db, $currency, $date);
        return [round((float)$valor * $taxa, 2), $taxa];
    }

    /** Moeda de uma conta de anúncios (padrão BRL). */
    public static function moedaDaConta($db, $accountId) {
        static $cache = [];
        if (isset($cache[$accountId])) return $cache[$accountId];
        $m = 'BRL';
        try {
            $r = $db->fetch("SELECT currency FROM ad_accounts WHERE account_id=?", [$accountId]);
            if ($r && !empty($r['currency'])) $m = strtoupper($r['currency']);
        } catch (Exception $e) {}
        return $cache[$accountId] = $m;
    }

    /**
     * Moeda da campanha. Num evento com contas em moedas diferentes (uma em
     * BRL e outra em USD, por exemplo), pegar "a primeira conta do evento"
     * acerta uma campanha e erra todas as outras. A campanha carrega a conta
     * dela nos próprios snapshots — essa é a fonte certa.
     *
     * @return array [moeda, taxa] — ['BRL', 1.0] quando não há conversão.
     */
    public static function moedaDaCampanha($db, $campaignId, $eventIdFallback = null) {
        static $cache = [];
        $k = (string)$campaignId;
        if (isset($cache[$k])) return $cache[$k];

        $m = '';
        try {
            // currency do snapshot é o que o sync_fx realmente aplicou ao gasto
            $r = $db->fetch(
                "SELECT COALESCE(NULLIF(cs.currency,''), a.currency) AS moeda
                 FROM campaign_snapshots cs
                 LEFT JOIN ad_accounts a ON a.account_id = cs.account_id
                 WHERE cs.campaign_id = ?
                   AND COALESCE(NULLIF(cs.currency,''), a.currency) IS NOT NULL
                 ORDER BY cs.snapshot_date DESC LIMIT 1", [$campaignId]);
            if ($r && !empty($r['moeda'])) $m = strtoupper($r['moeda']);
        } catch (Exception $e) {}

        // Campanha nova, ainda sem snapshot: usa a moeda não-BRL do evento.
        if ($m === '' && $eventIdFallback) {
            try {
                $r = $db->fetch("SELECT a.currency FROM event_accounts ea
                                 JOIN ad_accounts a ON a.account_id = ea.account_id
                                 WHERE ea.event_id = ? AND a.currency IS NOT NULL
                                   AND a.currency <> '' AND UPPER(a.currency) <> 'BRL'
                                 LIMIT 1", [$eventIdFallback]);
                if ($r && !empty($r['currency'])) $m = strtoupper($r['currency']);
            } catch (Exception $e) {}
        }

        if ($m === '' || $m === 'BRL') return $cache[$k] = ['BRL', 1.0];
        $t = self::rate($db, $m);
        if (!$t || $t <= 0) $t = 1.0;
        return $cache[$k] = [$m, $t];
    }

    /** Busca a cotação de fechamento do dia (AwesomeAPI, com fallback). */
    private static function buscarApi($currency, $date) {
        $ymd = str_replace('-', '', $date);

        // AwesomeAPI — histórico diário, sem chave
        $url = "https://economia.awesomeapi.com.br/json/daily/{$currency}-BRL/1?start_date={$ymd}&end_date={$ymd}";
        $j = self::http($url);
        if ($j && isset($j[0]['bid']) && (float)$j[0]['bid'] > 0) return (float)$j[0]['bid'];

        // cotação atual, se o histórico não vier
        $j2 = self::http("https://economia.awesomeapi.com.br/last/{$currency}-BRL");
        $k  = $currency . 'BRL';
        if ($j2 && isset($j2[$k]['bid']) && (float)$j2[$k]['bid'] > 0) return (float)$j2[$k]['bid'];

        // segunda fonte
        $j3 = self::http("https://api.exchangerate.host/{$date}?base={$currency}&symbols=BRL");
        if ($j3 && isset($j3['rates']['BRL']) && (float)$j3['rates']['BRL'] > 0) return (float)$j3['rates']['BRL'];

        return 0.0;
    }

    private static function http($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return $raw !== false ? json_decode($raw, true) : null;
    }
}
