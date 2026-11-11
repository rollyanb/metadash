<?php
// api/process_invoice.php
// Processa upload de fatura PDF e extrai transações via Python/pypdf (fallback PHP)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

function j($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Endpoint de debug — apenas admin
if (isset($_GET['debug'])) {
    if (!Auth::isAdmin()) {
        j(['error' => 'Acesso não autorizado'], 403);
    }
    j([
        'php'       => PHP_VERSION,
        'server'    => php_uname(),
        'extensions'=> get_loaded_extensions(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    j(['error' => 'Método não permitido'], 405);
}

Auth::verifyCsrf();

// Validação do upload
if (!isset($_FILES['invoice']) || $_FILES['invoice']['error'] !== UPLOAD_ERR_OK) {
    j(['error' => 'Nenhum arquivo recebido ou erro no upload.']);
}

$tmp  = $_FILES['invoice']['tmp_name'];
$size = $_FILES['invoice']['size'];

if ($size <= 0) {
    j(['error' => 'Arquivo vazio.']);
}

if ($size > 20 * 1024 * 1024) {
    j(['error' => 'Arquivo muito grande (máximo 20 MB).']);
}

// CORREÇÃO: validação real do MIME — antes só checava UPLOAD_ERR_OK + size
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $tmp);
finfo_close($finfo);
if ($mime !== 'application/pdf') {
    j(['error' => 'Apenas arquivos PDF são aceitos (tipo detectado: ' . $mime . ')']);
}

// Extrai texto do PDF
$text = extractPdfText($tmp);
if (!$text) {
    j(['error' => 'Não foi possível extrair texto do PDF. Verifique se o arquivo não está protegido.']);
}

// Parseia transações
$transactions = parsePdfTransactions($text);
if (empty($transactions)) {
    j(['error' => 'Nenhuma transação encontrada. O formato do extrato pode não ser suportado.']);
}

// Salva no banco
$db = Database::getInstance();
$saved = 0;
$dup   = 0;

foreach ($transactions as $tx) {
    $exists = $db->fetch(
        "SELECT id FROM invoice_transactions WHERE external_id = ? AND amount = ? AND tx_date = ?",
        [$tx['id'], $tx['amount'], $tx['date']]
    );
    if ($exists) { $dup++; continue; }

    $db->execute(
        "INSERT INTO invoice_transactions (external_id, description, amount, tx_date, raw, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())",
        [$tx['id'], $tx['description'], $tx['amount'], $tx['date'], $tx['raw']]
    );
    $saved++;
}

app_log('info', 'Fatura processada', ['saved' => $saved, 'duplicates' => $dup]);
j(['ok' => true, 'saved' => $saved, 'duplicates' => $dup, 'total' => count($transactions)]);

// --- funções auxiliares ---

function extractPdfText($filePath) {
    // Tenta Python/pypdf primeiro
    $py = findPython();
    if ($py) {
        $script = escapeshellarg(ROOT_PATH . '/scripts/extract_pdf.py');
        $file   = escapeshellarg($filePath);
        $cmd    = "{$py} {$script} {$file} 2>/dev/null";
        $out = shell_exec($cmd);
        if ($out && trim($out) !== '') return $out;
    }

    // Fallback PHP puro (simples, funciona para PDFs não protegidos)
    $raw = file_get_contents($filePath);
    if ($raw === false) return null;

    // Extrai streams BT...ET
    $text = '';
    if (preg_match_all('/BT(.*?)ET/s', $raw, $m)) {
        foreach ($m[1] as $block) {
            if (preg_match_all('/\((.*?)\)\s*Tj/s', $block, $t)) {
                $text .= implode(' ', $t[1]) . "\n";
            }
        }
    }
    return $text ?: null;
}

function findPython() {
    foreach (['python3', 'python'] as $bin) {
        $path = trim((string)shell_exec("which {$bin} 2>/dev/null"));
        if ($path) return escapeshellarg($path);
    }
    return null;
}

function parsePdfTransactions($text) {
    $lines = preg_split('/\r?\n/', $text);
    $txs   = [];

    // Formato A: "2024-01-15  Cobrança Meta Ads  act_123456  R$ 1.234,56"
    $pA = '/(\d{4}-\d{2}-\d{2})\s+(.+?)\s+(act_\d+)\s+R\$\s*([\d.,]+)/';
    // Formato B: "15/01/2024  act_123456  1234.56"
    $pB = '/(\d{2}\/\d{2}\/\d{4})\s+(act_\d+)\s+([\d.,]+)/';
    // Formato C: "act_123456 | 2024-01-15 | 1234.56 | Cobrança"
    $pC = '/(act_\d+)\s*\|\s*(\d{4}-\d{2}-\d{2})\s*\|\s*([\d.,]+)\s*\|\s*(.+)/';

    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line) continue;

        if (preg_match($pA, $line, $m)) {
            $txs[] = [
                'id'          => $m[3],
                'date'        => $m[1],
                'description' => trim($m[2]),
                'amount'      => normalizeAmount($m[4]),
                'raw'         => $line,
            ];
        } elseif (preg_match($pB, $line, $m)) {
            $txs[] = [
                'id'          => $m[2],
                'date'        => DateTime::createFromFormat('d/m/Y', $m[1])->format('Y-m-d'),
                'description' => '',
                'amount'      => normalizeAmount($m[3]),
                'raw'         => $line,
            ];
        } elseif (preg_match($pC, $line, $m)) {
            $txs[] = [
                'id'          => $m[1],
                'date'        => $m[2],
                'description' => trim($m[4]),
                'amount'      => normalizeAmount($m[3]),
                'raw'         => $line,
            ];
        }
    }

    return $txs;
}

function normalizeAmount($str) {
    // Remove separadores de milhar e converte vírgula decimal para ponto
    $s = preg_replace('/[^\d,.]/', '', $str);
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        // 1.234,56 → remove ponto, troca vírgula por ponto
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (strpos($s, ',') !== false) {
        $s = str_replace(',', '.', $s);
    }
    return (float)$s;
}
