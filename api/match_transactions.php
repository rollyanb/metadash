<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();
$allowedAccounts = Auth::getAllowedAccounts();

$raw = $_POST['raw_list'] ?? '';
if (!is_string($raw) || trim($raw) === '') {
    echo json_encode(['success' => false, 'error' => 'Lista vazia']);
    exit;
}

// ── MESES PT-BR ──────────────────────────────────────────────
$monthMap = [
    'jan' => '01', 'fev' => '02', 'mar' => '03', 'abr' => '04',
    'mai' => '05', 'jun' => '06', 'jul' => '07', 'ago' => '08',
    'set' => '09', 'out' => '10', 'nov' => '11', 'dez' => '12',
];

$currentYear = date('Y');

// ── PARSE DAS LINHAS ─────────────────────────────────────────
// Formato A: "21 de mai. 2026 FACEBK* RFXY3PMVD2 - R$ 608,14"
// Formato B: "07/05 FACEBK *J924MNMPY2 3.415,83"
$lines = preg_split('/\r\n|\r|\n/', $raw);
$items = [];

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;

    $amount  = null;
    $dateStr = null;
    $dateSql = null;

    // ── Formato A: data por extenso "DD de mmm. YYYY" + "R$ X,XX" ──
    if (preg_match('/(\d{1,2})\s+de\s+(\w+)\.?\s+(\d{4})/ui', $line, $dm)) {
        $dateStr = $dm[0];
        $day   = str_pad($dm[1], 2, '0', STR_PAD_LEFT);
        $monAb = strtolower(substr($dm[2], 0, 3));
        $year  = $dm[3];
        if (isset($monthMap[$monAb])) {
            $dateSql = "{$year}-{$monthMap[$monAb]}-{$day}";
        }

        if (preg_match('/R\$\s*([\d.]+,\d{2})/', $line, $am)) {
            $amount = (float) str_replace(['.', ','], ['', '.'], $am[1]);
        }
    }
    // ── Formato B: "DD/MM ... X.XXX,XX" (sem ano, sem R$) ──
    elseif (preg_match('/^(\d{2})\/(\d{2})\s+/', $line, $dm)) {
        $day   = $dm[1];
        $month = $dm[2];
        $dateStr = "{$day}/{$month}";
        $dateSql = "{$currentYear}-{$month}-{$day}";

        // Valor: último número no formato X.XXX,XX (ou X,XX) da linha
        if (preg_match('/([\d.]*\d,\d{2})\s*$/', $line, $am)) {
            $amount = (float) str_replace(['.', ','], ['', '.'], $am[1]);
        }
    }

    // Extrai código alfanumérico após FACEBK (apenas para exibição)
    $code = null;
    if (preg_match('/FACEBK\s*\*?\s*([A-Z0-9]{6,})/i', $line, $m)) {
        $code = strtoupper($m[1]);
    }

    if ($amount === null || $dateSql === null) continue; // linha não reconhecida

    $items[] = [
        'raw'      => $line,
        'date'     => $dateStr,
        'date_sql' => $dateSql,
        'code'     => $code,
        'amount'   => $amount,
    ];
}

if (empty($items)) {
    echo json_encode(['success' => false, 'error' => 'Nenhuma linha reconhecida. Verifique o formato (data e valor).']);
    exit;
}

// ── BUSCA NO BANCO ───────────────────────────────────────────
$accountWhere = '';
$accountParams = [];
if ($allowedAccounts !== null) {
    if (empty($allowedAccounts)) {
        $accountWhere = ' AND 1=0';
    } else {
        $ph = implode(',', array_fill(0, count($allowedAccounts), '?'));
        $accountWhere = " AND t.account_id IN ({$ph})";
        $accountParams = $allowedAccounts;
    }
}

$results = [];
$grouped = []; // account_id => ['account_name'=>..., 'items'=>[], 'total'=>0]
$notFound = [];

foreach ($items as $item) {
    // Janela de datas: cobre variação de processamento do extrato.
    // Buscamos transaction_date no intervalo [data_extrato - 1, data_extrato + 1].
    $dateFrom = date('Y-m-d', strtotime($item['date_sql'] . ' -1 day'));
    $dateTo   = date('Y-m-d', strtotime($item['date_sql'] . ' +1 day'));

    $sql = "
        SELECT t.transaction_id, t.account_id, t.amount, t.transaction_date,
               a.account_name
        FROM transactions t
        LEFT JOIN ad_accounts a ON a.account_id = t.account_id
        WHERE DATE(t.transaction_date) BETWEEN ? AND ?
          AND ABS(t.amount - ?) < 0.005
        {$accountWhere}
        LIMIT 5
    ";
    $params = array_merge([$dateFrom, $dateTo, $item['amount']], $accountParams);
    $matches = $db->fetchAll($sql, $params);

    if (empty($matches)) {
        $notFound[] = $item;
        $results[] = [
            'raw'     => $item['raw'],
            'code'    => $item['code'],
            'amount'  => $item['amount'],
            'date'    => $item['date'],
            'found'   => false,
            'matches' => [],
        ];
        continue;
    }

    $matchList = [];
    foreach ($matches as $m) {
        $matchList[] = [
            'account_id'       => $m['account_id'],
            'account_name'     => $m['account_name'] ?? $m['account_id'],
            'amount'           => (float) $m['amount'],
            'transaction_date' => $m['transaction_date'],
            'transaction_id'   => $m['transaction_id'],
        ];
    }

    // Agrupa apenas quando o match é único (sem ambiguidade)
    if (count($matches) === 1) {
        $accId = $matches[0]['account_id'];
        if (!isset($grouped[$accId])) {
            $grouped[$accId] = [
                'account_id'   => $accId,
                'account_name' => $matches[0]['account_name'] ?? $accId,
                'items'        => [],
                'total'        => 0,
            ];
        }
        $grouped[$accId]['items'][] = $item['raw'];
        $grouped[$accId]['total']  += (float) $matches[0]['amount'];
    }

    $results[] = [
        'raw'       => $item['raw'],
        'code'      => $item['code'],
        'amount'    => $item['amount'],
        'date'      => $item['date'],
        'found'     => true,
        'ambiguous' => count($matches) > 1,
        'matches'   => $matchList,
    ];
}

// Ordena grupos por total desc
$groupedList = array_values($grouped);
usort($groupedList, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});

echo json_encode([
    'success'   => true,
    'total'     => count($items),
    'found'     => count($items) - count($notFound),
    'not_found' => count($notFound),
    'results'   => $results,
    'grouped'   => $groupedList,
], JSON_UNESCAPED_UNICODE);