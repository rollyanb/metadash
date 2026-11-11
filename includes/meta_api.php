<?php
// includes/meta_api.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class MetaAPI {
    private $token;
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->token = $this->getToken();
    }

    private function getToken() {
        $row = $this->db->fetch("SELECT access_token FROM meta_token ORDER BY id DESC LIMIT 1");
        if (!$row) return null;
        $t = Crypto::decrypt($row['access_token']);
        if ($t === null) app_log('error', 'Token Meta cifrado mas não foi possível decifrar (chave config/.enc_key ausente ou alterada?)');
        return $t;
    }

    private function base() {
        return 'https://graph.facebook.com/' . metaApiVersion();
    }

    public function hasToken() {
        return !empty($this->token);
    }

    public function getTokenValue() {
        return $this->token;
    }

    // Busca TODAS as transações de uma conta (sem filtro de data)
    public function getTransactionsFull($accountId) {
        $url = $this->base() . "/{$accountId}/transactions"
             . "?fields=id,amount,time,status"
             . "&access_token=" . $this->token
             . "&limit=500";
        return $this->curlGet($url);
    }

    // Busca cobranças via activities (funciona para cartão automático)
    // Retorna eventos do tipo ad_account_billing_charge
    public function getBillingActivities($accountId, $since = null, $until = null) {
        $sinceTs = $since ?? strtotime('-90 days');
        $untilTs = $until ?? time();

        $url = $this->base() . "/{$accountId}/activities"
             . "?fields=event_type,event_time,extra_data"
             . "&filter=ad_account_billing_charge"
             . "&since={$sinceTs}&until={$untilTs}"
             . "&access_token=" . $this->token
             . "&limit=500";
        return $this->curlGet($url);
    }

    public function saveToken($token) {
        // Troca por token de longa duração
        $longToken = $this->exchangeForLongToken($token);
        $useToken  = $longToken ?: $token;

        $this->db->execute("DELETE FROM meta_token");
        $this->db->insert(
            "INSERT INTO meta_token (access_token, expires_at) VALUES (?, ?)",
            [Crypto::encrypt($useToken), date('Y-m-d H:i:s', strtotime('+60 days'))]
        );
        $this->token = $useToken;
        return $useToken;
    }

    private function exchangeForLongToken($shortToken) {
        $url = $this->base() . "/oauth/access_token?grant_type=fb_exchange_token"
             . "&client_id=" . META_APP_ID
             . "&client_secret=" . META_APP_SECRET
             . "&fb_exchange_token=" . $shortToken;

        $response = $this->curlGet($url);
        return $response['access_token'] ?? null;
    }

    /**
     * Troca um token curto por um de longa duração e descobre a validade real.
     * Antes assumíamos 60 dias sempre — um token do Explorer (2h) era salvo
     * como se durasse 60, o cron não renovava e a conta parava sem aviso.
     */
    public function saveTokenVerificado($tokenInformado) {
        $resultado = ['ok' => false, 'msg' => '', 'expira_em' => null, 'trocado' => false];

        // tenta converter para longa duração
        $longo = $tokenInformado;
        $troca = $this->curlGet($this->base() . "/oauth/access_token?grant_type=fb_exchange_token"
               . "&client_id=" . META_APP_ID
               . "&client_secret=" . META_APP_SECRET
               . "&fb_exchange_token=" . urlencode($tokenInformado));
        if (!empty($troca['access_token'])) {
            $longo = $troca['access_token'];
            $resultado['trocado'] = true;
        }

        // validade real, direto da Meta
        $dbg = $this->curlGet($this->base() . "/debug_token?input_token=" . urlencode($longo)
             . "&access_token=" . META_APP_ID . "|" . META_APP_SECRET);
        $info = $dbg['data'] ?? [];

        if (!empty($info['is_valid']) && $info['is_valid'] === false) {
            $resultado['msg'] = 'A Meta recusou este token: ' . ($info['error']['message'] ?? 'inválido');
            return $resultado;
        }

        $expira = !empty($info['expires_at']) ? (int)$info['expires_at'] : 0;
        // expires_at = 0 significa token sem expiração (system user)
        $expiraEm = $expira > 0 ? date('Y-m-d H:i:s', $expira) : date('Y-m-d H:i:s', strtotime('+180 days'));

        $this->db->execute("DELETE FROM meta_token");
        $this->db->execute("INSERT INTO meta_token (access_token, expires_at, created_at, updated_at) VALUES (?,?,NOW(),NOW())",
            [Crypto::encrypt($longo), $expiraEm]);
        $this->token = $longo;

        $horas = $expira > 0 ? round(($expira - time()) / 3600) : null;
        $resultado['ok'] = true;
        $resultado['expira_em'] = $expiraEm;
        $resultado['msg'] = $expira === 0
            ? 'Token salvo — sem data de expiração (usuário do sistema).'
            : 'Token salvo — válido por ' . ($horas >= 48 ? round($horas / 24) . ' dia(s)' : $horas . ' hora(s)')
              . ($resultado['trocado'] ? ' (convertido para longa duração).' : '.')
              . ($horas < 48 ? ' ⚠️ Duração curta: gere um token de longa duração ou use um usuário do sistema.' : '');
        return $resultado;
    }

    /** Validade real do token atual, consultando a Meta. */
    public function validadeReal() {
        if (!$this->token) return null;
        $dbg = $this->curlGet($this->base() . "/debug_token?input_token=" . urlencode($this->token)
             . "&access_token=" . META_APP_ID . "|" . META_APP_SECRET);
        $info = $dbg['data'] ?? null;
        if (!$info) return null;
        return [
            'valido'     => !empty($info['is_valid']),
            'expires_at' => !empty($info['expires_at']) ? (int)$info['expires_at'] : 0,
            'erro'       => $info['error']['message'] ?? null,
            'scopes'     => $info['scopes'] ?? [],
        ];
    }

    public function refreshToken() {
        if (!$this->token) return false;
        // Tokens de longa duração se renovam ao serem usados
        // Para forçar renovação, chame com o token existente
        $url = $this->base() . "/oauth/access_token?grant_type=fb_exchange_token"
             . "&client_id=" . META_APP_ID
             . "&client_secret=" . META_APP_SECRET
             . "&fb_exchange_token=" . $this->token;

        $response = $this->curlGet($url);
        if (!empty($response['access_token'])) {
            // pega a validade real em vez de assumir 60 dias
            $novo = $response['access_token'];
            $dbg  = $this->curlGet($this->base() . "/debug_token?input_token=" . urlencode($novo)
                  . "&access_token=" . META_APP_ID . "|" . META_APP_SECRET);
            $exp  = (int)($dbg['data']['expires_at'] ?? 0);
            $expiraEm = $exp > 0 ? date('Y-m-d H:i:s', $exp) : date('Y-m-d H:i:s', strtotime('+180 days'));

            $this->db->execute("UPDATE meta_token SET access_token = ?, expires_at = ?, updated_at = NOW() WHERE id = (SELECT id FROM (SELECT id FROM meta_token ORDER BY id DESC LIMIT 1) t)",
                [Crypto::encrypt($novo), $expiraEm]);
            $this->token = $response['access_token'];
            return true;
        }
        return false;
    }

    // Busca TODAS as contas de anúncio vinculadas ao token (paginação automática)
    public function getAdAccounts() {
        $allAccounts = [];
        $url = $this->base() . "/me/adaccounts"
             . "?fields=id,name,currency,account_status,funding_source_details"
             . "&access_token=" . $this->token
             . "&limit=200"; // máximo por página

        while ($url) {
            $response = $this->curlGet($url);

            if (isset($response['error'])) {
                // Retorna o que já coletou + o erro para o caller tratar
                return ['data' => $allAccounts, 'error' => $response['error']];
            }

            $page = $response['data'] ?? [];
            $allAccounts = array_merge($allAccounts, $page);

            // Verifica se há próxima página
            $url = $response['paging']['next'] ?? null;

            // Pausa mínima para não bater no rate limit
            if ($url) usleep(200000); // 200ms
        }

        return ['data' => $allAccounts];
    }

    // Busca gasto (spend) de uma conta em um período
    public function getAccountSpend($accountId, $dateStart, $dateEnd) {
        $url = $this->base() . "/{$accountId}/insights"
             . "?fields=spend,impressions,clicks"
             . "&time_range[since]={$dateStart}&time_range[until]={$dateEnd}"
             . "&access_token=" . $this->token;
        return $this->curlGet($url);
    }

    // Busca transações de cobrança de uma conta
    public function getTransactions($accountId) {
        $url = $this->base() . "/{$accountId}/transactions"
             . "?fields=id,amount,time,status"
             . "&access_token=" . $this->token
             . "&limit=500";
        return $this->curlGet($url);
    }

    // Busca detalhes da conta incluindo cartão
    public function getAccountDetails($accountId) {
        $url = $this->base() . "/{$accountId}"
             . "?fields=id,name,currency,account_status,funding_source_details,spend_cap,amount_spent"
             . "&access_token=" . $this->token;
        return $this->curlGet($url);
    }

    // Busca gasto diário de uma conta
    public function getDailySpend($accountId, $dateStart, $dateEnd) {
        $url = $this->base() . "/{$accountId}/insights"
             . "?fields=spend,impressions,clicks,date_start"
             . "&time_range[since]={$dateStart}&time_range[until]={$dateEnd}"
             . "&time_increment=1"
             . "&access_token=" . $this->token;
        return $this->curlGet($url);
    }

    // Códigos da Meta que indicam rate limit / instabilidade (vale tentar de novo)
    private static $retryCodes = [1, 2, 4, 17, 32, 613, 80000, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

    private function curlGet($url, $maxTries = 3) {
        // --- SEGURANÇA: move o user access_token da URL para o header Authorization ---
        // Tokens de app (APP_ID|APP_SECRET, contêm '|') ficam na URL como estão.
        $tok = $this->token ?? '';
        if ($tok !== '') {
            $url = str_replace('?access_token=' . $tok,  '?', $url);
            $url = str_replace('&access_token=' . $tok,  '',  $url);
            $url = rtrim($url, '?&');
        }
        $headers = ['Accept: application/json'];
        if ($tok !== '') $headers[] = 'Authorization: Bearer ' . $tok;
        // --- fim fix ---

        $lastErr = null;
        for ($try = 1; $try <= $maxTries; $try++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $lastErr = ['error' => ['message' => 'Falha na requisição cURL: ' . $curlErr, 'code' => 0]];
            } else {
                $decoded = json_decode($response, true);
                if (!$decoded) {
                    $lastErr = ['error' => ['message' => 'Resposta inválida da API (HTTP ' . $httpCode . ')', 'code' => 0]];
                } elseif (isset($decoded['error'])) {
                    $code = isset($decoded['error']['code']) ? (int)$decoded['error']['code'] : 0;
                    $retry = in_array($code, self::$retryCodes) || $httpCode == 429 || $httpCode >= 500;
                    if (!$retry) return $decoded;
                    $lastErr = $decoded;
                } else {
                    return $decoded;
                }
            }
            if ($try < $maxTries) {
                // backoff exponencial: 2s, 6s
                sleep($try === 1 ? 2 : 6);
            }
        }
        $msg = isset($lastErr['error']['message']) ? $lastErr['error']['message'] : 'erro desconhecido';
        app_log('warning', 'Meta API falhou após ' . $maxTries . ' tentativas: ' . $msg, ['url' => preg_replace('/access_token=[^&]+/', 'access_token=***', $url)]);
        // Compat: código antigo espera $r['error'] como string em alguns pontos, array em outros
        return $lastErr;
    }

    public function getLoginUrl($redirectUri) {
        return "https://www.facebook.com/" . metaApiVersion() . "/dialog/oauth"
             . "?client_id=" . META_APP_ID
             . "&redirect_uri=" . urlencode($redirectUri)
             . "&scope=ads_read,ads_management,business_management"
             . "&response_type=code";
    }

    public function exchangeCodeForToken($code, $redirectUri) {
        $url = $this->base() . "/oauth/access_token"
             . "?client_id=" . META_APP_ID
             . "&client_secret=" . META_APP_SECRET
             . "&redirect_uri=" . urlencode($redirectUri)
             . "&code=" . $code;
        return $this->curlGet($url);
    }
}

/**
 * GET na Graph API com erro visível.
 *
 * Antes usávamos @file_get_contents em 11 pontos: quando a chamada falhava
 * (allow_url_fopen desligado, timeout, token sem permissão na conta), o erro
 * era engolido e a tela mostrava "erro na API" ou "sem budget" sem motivo.
 *
 * Devolve o array decodificado. Em falha, devolve
 * ['error' => ['message' => ..., 'code' => ..., '_transport' => true]].
 */
function metaGet($url, $timeout = 30) {
    // --- SEGURANÇA: extrai user access_token da URL e usa header Authorization ---
    // Tokens de app (APP_ID|APP_SECRET, contêm '|') ficam na URL.
    $bearerToken = '';
    if (preg_match('/[?&]access_token=([^&]+)/', $url, $m)) {
        $extracted = urldecode($m[1]);
        if (strpos($extracted, '|') === false) { // token de usuário, não token de app
            $bearerToken = $extracted;
            $url = preg_replace('/([?&])access_token=[^&]+/', '$1', $url);
            $url = preg_replace('/\?&/', '?', $url);
            $url = rtrim($url, '?&');
        }
    }
    // --- fim fix ---

    if (!function_exists('curl_init')) {
        $context = null;
        if ($bearerToken) {
            $context = stream_context_create(['http' => [
                'header' => 'Authorization: Bearer ' . $bearerToken,
            ]]);
        }
        $raw = $context ? @file_get_contents($url, false, $context) : @file_get_contents($url);
        if ($raw === false) {
            return ['error' => ['message' => 'Sem cURL e file_get_contents bloqueado no servidor', '_transport' => true]];
        }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : ['error' => ['message' => 'Resposta inválida da Meta', '_transport' => true]];
    }

    $ch = curl_init();
    $curlHeaders = [];
    if ($bearerToken) $curlHeaders[] = 'Authorization: Bearer ' . $bearerToken;
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($curlHeaders) curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo  = curl_errno($ch);
    $errMsg = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['error' => ['message' => "Falha de rede (cURL {$errNo}): {$errMsg}", '_transport' => true]];
    }

    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return ['error' => ['message' => "Resposta inválida da Meta (HTTP {$status}): " . mb_substr(trim((string)$raw), 0, 120),
                            '_transport' => true]];
    }

    // erro da própria Meta: repassa com o detalhe que ela manda
    if (isset($j['error'])) {
        $e = $j['error'];
        $det = $e['error_user_msg'] ?? ($e['error_user_title'] ?? '');
        $j['error']['message'] = ($e['message'] ?? 'erro desconhecido')
            . (isset($e['code']) ? " (código {$e['code']}" . (isset($e['error_subcode']) ? "/{$e['error_subcode']}" : '') . ")" : '')
            . ($det ? " — {$det}" : '');
    }
    return $j;
}
