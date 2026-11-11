<?php
// includes/sync_service.php
// syncTransactions REMOVIDO — transações agora são importadas manualmente via PDF

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/meta_api.php';

class SyncService {
    private $db;
    private $api;

    public function __construct() {
        $this->db  = Database::getInstance();
        $this->api = new MetaAPI();
    }

    // Sincroniza todas as contas e cartões
    public function syncAccounts() {
        if (!$this->api->hasToken()) {
            return ['success' => false, 'message' => 'Token Meta não configurado'];
        }

        $response = $this->api->getAdAccounts();

        if (!empty($response['error']) && empty($response['data'])) {
            $errMsg = $response['error']['message'] ?? 'Erro desconhecido';
            $this->logSync('accounts', 'error', $errMsg, 0);
            return ['success' => false, 'message' => $errMsg];
        }

        $accounts = $response['data'] ?? [];
        $partialError = $response['error']['message'] ?? null;
        if (!$accounts) {
            $errMsg = $partialError ?: 'A Meta não retornou contas de anúncios.';
            $this->logSync('accounts', 'error', $errMsg, 0);
            return ['success' => false, 'partial' => false, 'message' => $errMsg, 'count' => 0];
        }
        $count = 0;

        foreach ($accounts as $account) {
            $accountId = $account['id'];

            $funding = $account['funding_source_details'] ?? [];
            if (empty($funding)) {
                $details = $this->api->getAccountDetails($accountId);
                $funding = $details['funding_source_details'] ?? [];
                usleep(100000);
            }

            $card_last4   = null;
            $card_brand   = null;
            $card_display = null;

            if (!empty($funding)) {
                $card_brand  = $funding['type_name'] ?? ($funding['type'] ?? null);
                if (isset($funding['display_string'])) {
                    preg_match('/\d{4}$/', $funding['display_string'], $matches);
                    $card_last4 = $matches[0] ?? null;
                }
                $card_display = $this->buildCardDisplay($funding);
            }

            // Upsert conta — preserva card_display do PDF se a API não retornar cartão
            $this->db->execute(
                "INSERT INTO ad_accounts (account_id, account_name, currency, card_last4, card_brand, card_display, status, last_sync)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    account_name = VALUES(account_name),
                    currency     = VALUES(currency),
                    card_last4   = COALESCE(VALUES(card_last4), card_last4),
                    card_brand   = COALESCE(VALUES(card_brand), card_brand),
                    card_display = COALESCE(VALUES(card_display), card_display),
                    status       = VALUES(status),
                    last_sync    = NOW()",
                [
                    $accountId,
                    $account['name'],
                    $account['currency'] ?? 'BRL',
                    $card_last4,
                    $card_brand,
                    $card_display,
                    (function($s) {
                        // Meta account_status: 1=ACTIVE, 2=DISABLED, 3=UNSETTLED(payment), 7=PENDING_RISK_REVIEW, 9=IN_GRACE_PERIOD, 101=CLOSED, 201=ANY_CLOSED
                        if ($s == 1) return 'ACTIVE';
                        if ($s == 3 || $s == 9) return 'PAYMENT_REQUIRED';
                        return 'INACTIVE';
                    })($account['account_status'])
                ]
            );

            $count++;
        }

        $status = $partialError ? 'partial' : 'success';
        $message = $partialError
            ? "Sincronização parcial: {$count} contas atualizadas; erro de paginação da Meta: " . mb_substr($partialError, 0, 300)
            : "Sincronizadas {$count} contas";
        $this->logSync('accounts', $status, $message, $count);
        return ['success' => !$partialError, 'partial' => (bool)$partialError, 'message' => $message, 'count' => $count];
    }

    private function buildCardDisplay($funding) {
        $type    = $funding['type_name'] ?? ($funding['type'] ?? '');
        $display = $funding['display_string'] ?? '';

        if (empty($display) && empty($type)) return null;

        preg_match('/\d{4}/', $display, $matches);
        $last4 = $matches[0] ?? null;

        if ($last4) return trim($type . ' •••• ' . $last4);
        return trim($type . ' ' . $display) ?: null;
    }

    // Sincroniza gastos diários dos últimos N dias para todas as contas
    public function syncSpend($days = 30) {
        if (!$this->api->hasToken()) {
            return ['success' => false, 'message' => 'Token Meta não configurado'];
        }

        $accounts = $this->db->fetchAll("SELECT account_id FROM ad_accounts WHERE status IN ('ACTIVE','PAYMENT_REQUIRED')");

        if (empty($accounts)) {
            return ['success' => false, 'message' => 'Nenhuma conta ativa. Sincronize as contas primeiro.'];
        }

        $dateEnd   = date('Y-m-d');
        $dateStart = date('Y-m-d', strtotime("-{$days} days"));
        $count     = 0;
        $errors = [];

        foreach ($accounts as $account) {
            $accountId = $account['account_id'];
            $response  = $this->api->getDailySpend($accountId, $dateStart, $dateEnd);

            if (!$response || isset($response['error']) || !isset($response['data']) || !is_array($response['data'])) {
                $message = $response['error']['message'] ?? 'Resposta inválida da Meta';
                $errors[] = $accountId . ': ' . mb_substr($message, 0, 180);
                continue;
            }

            $data = $response['data'] ?? [];

            foreach ($data as $day) {
                $spend = floatval($day['spend'] ?? 0);
                $date  = $day['date_start'] ?? null;
                if (!$date) continue;

                $this->db->execute(
                    "INSERT INTO spend_snapshots (account_id, snapshot_date, spend, impressions, clicks)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        spend       = VALUES(spend),
                        impressions = VALUES(impressions),
                        clicks      = VALUES(clicks)",
                    [
                        $accountId,
                        $date,
                        $spend,
                        intval($day['impressions'] ?? 0),
                        intval($day['landing_page_view'] ?? 0)
                    ]
                );
            }

            $this->db->execute("UPDATE ad_accounts SET last_sync = NOW() WHERE account_id = ?", [$accountId]);
            $count++;
        }

        $status = $errors ? ($count ? 'partial' : 'error') : 'success';
        $message = $errors
            ? "Sincronização {$status}: gastos para {$count}/" . count($accounts) . " contas ({$days} dias); falhas: " . implode(' | ', array_slice($errors, 0, 8))
            : "Gastos sincronizados para {$count} contas ({$days} dias)";
        $this->logSync('spend', $status, $message, $count);
        return ['success' => !$errors, 'partial' => (bool)($errors && $count), 'message' => $message, 'count' => $count, 'failed' => count($errors)];
    }

    private function logSync($type, $status, $message, $count = 0) {
        $this->db->insert(
            "INSERT INTO sync_log (sync_type, status, message, accounts_synced) VALUES (?, ?, ?, ?)",
            [$type, $status, $message, $count]
        );
    }
}
