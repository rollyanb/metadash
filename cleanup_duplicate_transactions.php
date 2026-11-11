<?php
// cleanup_duplicate_transactions.php
// Encontra e remove transações duplicadas: mesma conta + mesma data + mesmo valor,
// mas transaction_id diferente (causado pela Meta gerando IDs de linha diferentes
// pro mesmo lançamento real em exports de PDF com período sobreposto).
//
// USO:
//   https://metadash.space/cleanup_duplicate_transactions.php
//       -> modo DRY-RUN, só lista o que seria removido, não apaga nada
//   https://metadash.space/cleanup_duplicate_transactions.php?confirm=1
//       -> apaga de fato, mantendo o registro mais antigo (menor id) de cada grupo

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();
if (!Auth::isAdmin()) { http_response_code(403); die('Apenas admin.'); }

$db = Database::getInstance();
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';

// Agrupa por conta+data+valor com mais de 1 registro
$groups = $db->fetchAll(
    "SELECT account_id, transaction_date, amount, COUNT(*) as qtd, GROUP_CONCAT(id ORDER BY id) as ids, GROUP_CONCAT(transaction_id ORDER BY id SEPARATOR '||') as txn_ids
     FROM transactions
     GROUP BY account_id, transaction_date, amount
     HAVING COUNT(*) > 1
     ORDER BY transaction_date DESC"
);

echo "<h2>" . ($confirm ? "Removendo duplicatas..." : "Duplicatas encontradas (DRY-RUN — nada foi apagado)") . "</h2>";
echo "<p>Total de grupos duplicados: " . count($groups) . "</p>";
echo "<table border=1 cellpadding=6 style='border-collapse:collapse'>";
echo "<tr><th>Conta</th><th>Data</th><th>Valor</th><th>Qtd</th><th>IDs (mantém o 1º)</th><th>Ação</th></tr>";

$totalRemoved = 0;
foreach ($groups as $g) {
    $ids = explode(',', $g['ids']);
    $keepId = array_shift($ids); // mantém o registro mais antigo
    $toRemove = $ids;

    echo "<tr><td>{$g['account_id']}</td><td>{$g['transaction_date']}</td><td>R$" . number_format($g['amount'],2,',','.') . "</td>";
    echo "<td>{$g['qtd']}</td><td>" . htmlspecialchars($g['txn_ids']) . "</td>";

    if ($confirm && !empty($toRemove)) {
        $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
        $db->execute("DELETE FROM transactions WHERE id IN ($placeholders)", $toRemove);
        $totalRemoved += count($toRemove);
        echo "<td>Removidos: " . implode(',', $toRemove) . " (mantido id {$keepId})</td>";
    } else {
        echo "<td>Removeria: " . implode(',', $toRemove) . " (manteria id {$keepId})</td>";
    }
    echo "</tr>";
}
echo "</table>";

if (!$confirm) {
    echo "<p><strong>Nenhuma alteração foi feita.</strong> Revise a tabela acima e, se estiver correto, adicione <code>?confirm=1</code> na URL pra remover de fato.</p>";
} else {
    echo "<p><strong>{$totalRemoved} registro(s) duplicado(s) removido(s).</strong></p>";
}
