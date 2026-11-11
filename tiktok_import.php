<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();
if (!Auth::isAdmin()) { header('Location: index.php'); exit; } // área administrativa
if (!in_array(Auth::getRole(), ['admin','chefe'])) {
    header('Location: index.php'); exit;
}

$db  = Database::getInstance();
$msg = null;
$preview = [];

// ── PARSER XLSX NATIVO (ZipArchive + SimpleXML) ───────────────
function readXlsx($filePath) {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return null;

    // Lê strings compartilhadas (formato padrão XLSX)
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $r) {
                        if (isset($r->t)) $text .= (string)$r->t;
                    }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    // Lê primeira planilha
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheetXml) return null;

    // Registra namespace do XLSX
    $sheetXml = str_replace(
        'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"',
        'xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"',
        $sheetXml
    );

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) return null;

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $type  = (string)($cell['t'] ?? '');
            $value = '';

            if ($type === 'inlineStr') {
                // Formato TikTok: <c t="inlineStr"><is><t>valor</t></is></c>
                if (isset($cell->is->t)) {
                    $value = (string)$cell->is->t;
                } elseif (isset($cell->is->r)) {
                    foreach ($cell->is->r as $r) {
                        if (isset($r->t)) $value .= (string)$r->t;
                    }
                }
            } elseif ($type === 's') {
                // Shared strings
                $idx = intval((string)($cell->v ?? ''));
                $value = isset($sharedStrings[$idx]) ? $sharedStrings[$idx] : '';
            } else {
                // Valor numérico ou texto direto em <v>
                $value = (string)($cell->v ?? '');
            }

            $rowData[] = $value;
        }
        $rows[] = $rowData;
    }

    if (count($rows) < 2) return [];

    // Primeira linha = cabeçalhos
    $headers = $rows[0];
    $result  = [];
    for ($i = 1; $i < count($rows); $i++) {
        $record = [];
        foreach ($headers as $j => $h) {
            $record[$h] = isset($rows[$i][$j]) ? $rows[$i][$j] : '';
        }
        $result[] = $record;
    }
    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['tiktok_file'])) {
    $file = $_FILES['tiktok_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = ['type'=>'err','text'=>'Erro no upload. Código: ' . $file['error']];
    } else {
        $rows = readXlsx($file['tmp_name']);

        if ($rows === null) {
            $msg = ['type'=>'err','text'=>'Extensão ZipArchive não disponível no servidor. Contate o suporte.'];
        } elseif (empty($rows)) {
            $msg = ['type'=>'err','text'=>'Arquivo vazio ou formato inválido. Verifique se é o relatório de transações do TikTok Ads.'];
        } else {
            $imported = 0;
            $skipped  = 0;
            // Contadores por motivo: um número só não dizia se era duplicata,
            // crédito ou filtro — e o usuário ficava sem saber o que houve.
            $pulou = ['status'=>0, 'tipo'=>0, 'valor'=>0, 'duplicata'=>0];
            $tiposVistos = []; $statusVistos = [];

            // O TikTok exporta no idioma da conta: os cabeçalhos e valores vêm
            // em português quando o painel está em PT-BR. Aceitamos os dois.
            $col = function ($row, array $nomes, $padrao = '') {
                foreach ($nomes as $n) {
                    if (isset($row[$n]) && trim((string)$row[$n]) !== '') return trim((string)$row[$n]);
                }
                return $padrao;
            };
            $ehSucesso = function ($v) {
                $v = mb_strtolower(trim((string)$v));
                return in_array($v, ['success', 'successful', 'sucesso', 'concluído', 'concluido']);
            };
            $ehCobranca = function ($v) {
                $v = mb_strtolower(trim((string)$v));
                return in_array($v, ['bill payment', 'pagamento de conta', 'pagamento da conta', 'payment']);
            };

            foreach ($rows as $row) {
                $subtype  = $col($row, ['Transaction subtype', 'Subtipo de transação', 'Subtipo de transacao']);
                $status   = $col($row, ['Status']);
                $fundType = $col($row, ['Fund type', 'Tipo de fundo']);
                $amountRaw= $col($row, ['Amount', 'Valor'], '0');
                // TikTok envia Amount com prefixo + ou - (ex: "+128.00", "-32.90")
                // "+197.00" ou "+1.234,56" (formato PT)
                $limpo = ltrim(str_replace([' ', "\xc2\xa0"], '', $amountRaw), '+');
                if (preg_match('/,\d{1,2}$/', $limpo)) {
                    $limpo = str_replace('.', '', $limpo);
                    $limpo = str_replace(',', '.', $limpo);
                }
                $amount = floatval($limpo);

                if ($subtype !== '') $tiposVistos[$subtype] = ($tiposVistos[$subtype] ?? 0) + 1;
                if ($status !== '')  $statusVistos[$status] = ($statusVistos[$status] ?? 0) + 1;

                if (!$ehSucesso($status))   { $skipped++; $pulou['status']++; continue; }
                if (!$ehCobranca($subtype))  { $skipped++; $pulou['tipo']++;   continue; }
                if ($amount <= 0)            { $skipped++; $pulou['valor']++;  continue; }

                $transactionId = $col($row, ['Transaction ID', 'ID da transação', 'ID da transacao']);
                $accountId     = $col($row, ['Account ID', 'ID da conta']);
                $accountName   = $col($row, ['Account name', 'Nome da conta']);
                $dateRaw       = $col($row, ['Transaction time', 'Hora da transação', 'Hora da transacao']);
                $description   = $col($row, ['Description', 'Descrição', 'Descricao']);
                $document      = $col($row, ['Document', 'Documento']);
                $currency      = $col($row, ['Currency', 'Moeda'], 'BRL');

                // Parse data: "2026/06/18 23:09"
                $dateSql = null;
                if (preg_match('/(\d{4})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})/', $dateRaw, $dm)) {
                    $dateSql = "{$dm[1]}-{$dm[2]}-{$dm[3]} {$dm[4]}:{$dm[5]}:00";
                }

                // Registra conta TikTok
                if ($accountId) {
                    $db->execute(
                        "INSERT INTO tiktok_accounts (account_id, name) VALUES (?,?)
                         ON DUPLICATE KEY UPDATE name=VALUES(name)",
                        [$accountId, $accountName]
                    );
                }

                // Evita duplicatas
                $exists = $db->fetch(
                    "SELECT id FROM transactions WHERE transaction_id=? AND platform='tiktok'",
                    [$transactionId]
                );
                if ($exists) { $skipped++; $pulou['duplicata']++; continue; }

                $db->execute(
                    "INSERT INTO transactions
                     (account_id, platform, transaction_id, amount, currency, transaction_date, status, description, fund_type)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [
                        $accountId ?: 'tiktok',
                        'tiktok',
                        $transactionId,
                        $amount,
                        $currency,
                        $dateSql,
                        'SETTLED',
                        $description ?: $document,
                        $fundType,
                    ]
                );
                $imported++;

                if (count($preview) < 8) {
                    $preview[] = [
                        'date'        => $dateSql,
                        'amount'      => $amount,
                        'description' => $description ?: $document,
                        'account'     => $accountName,
                    ];
                }
            }
            // Detalha o que foi ignorado e por quê
            $detalhes = [];
            if ($pulou['duplicata']) $detalhes[] = $pulou['duplicata'] . ' já importada(s) antes';
            if ($pulou['tipo'])      $detalhes[] = $pulou['tipo'] . ' não são cobrança (crédito, ajuste, transferência)';
            if ($pulou['status'])    $detalhes[] = $pulou['status'] . ' com status diferente de Success';
            if ($pulou['valor'])     $detalhes[] = $pulou['valor'] . ' com valor zero ou negativo';

            $texto = "{$imported} transação(ões) importada(s)";
            if ($skipped) $texto .= " · {$skipped} ignorada(s): " . implode(' · ', $detalhes);

            // Quando nada entra, mostra o que o arquivo realmente tem
            if ($imported === 0) {
                $resumoTipos = [];
                arsort($tiposVistos);
                foreach (array_slice($tiposVistos, 0, 4, true) as $t => $q) $resumoTipos[] = "{$t} ({$q})";
                if ($resumoTipos) $texto .= '. Tipos no arquivo: ' . implode(', ', $resumoTipos);
                if ($pulou['duplicata'] === $skipped && $skipped > 0) {
                    $texto .= '. Tudo deste arquivo já estava no sistema — nada novo a importar.';
                }
            }

            $msg = ['type' => ($imported > 0 ? 'ok' : 'warning'), 'text' => ($imported > 0 ? '✅ ' : 'ℹ️ ') . $texto];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Importar TikTok Ads — MetaDash</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--accent:#4f6ef7;--accent2:#a78bfa;--green:#34d399;--red:#f87171;--yellow:#fbbf24}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--tx,var(--text));min-height:100vh;display:flex}
.sidebar{width:240px;min-height:100vh;background:var(--sf,var(--surface));border-right:1px solid var(--bd,var(--border));display:flex;flex-direction:column;padding:24px 16px;position:fixed;top:0;left:0;bottom:0}
.logo{display:flex;align-items:center;gap:10px;padding:8px 12px 28px;border-bottom:1px solid var(--bd,var(--border));margin-bottom:20px}
.logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px}
.logo-text{font-family:'Syne',sans-serif;font-weight:800;font-size:16px}
.logo-text span{color:var(--accent)}
.nav-label{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--mt,var(--muted));padding:0 12px;margin-bottom:8px;margin-top:16px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:var(--mt2,var(--muted2));text-decoration:none;font-size:14px;font-weight:500;transition:all .15s;margin-bottom:2px}
.nav-item:hover,.nav-item.active{background:rgba(79,110,247,.12);color:var(--tx,var(--text))}
.nav-item.active{color:var(--accent)}
.sidebar-footer{margin-top:auto;padding-top:20px;border-top:1px solid var(--bd,var(--border))}
.logout-link{display:block;text-align:center;color:var(--mt,var(--muted));font-size:12px;text-decoration:none;padding:6px}
.logout-link:hover{color:var(--red)}
.main{margin-left:240px;flex:1;padding:32px;min-height:100vh}
.page-title{font-family:'Syne',sans-serif;font-size:26px;font-weight:800;margin-bottom:6px}
.page-subtitle{color:var(--mt2,var(--muted2));font-size:14px;margin-bottom:28px}
.card{background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:16px;padding:24px;margin-bottom:20px;max-width:680px}
.alert{padding:14px 16px;border-radius:10px;font-size:14px;margin-bottom:16px;max-width:680px}
.alert-ok {background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.3);color:var(--green)}
.alert-err{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:var(--red)}
.alert-warning{background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3);color:#fbbf24}
.upload-area{border:2px dashed var(--bd,var(--border));border-radius:12px;padding:40px;text-align:center;cursor:pointer;transition:border-color .2s}
.upload-area:hover{border-color:var(--accent)}
.upload-area input{display:none}
.upload-icon{font-size:40px;margin-bottom:12px}
.upload-label{font-family:'Syne',sans-serif;font-weight:700;font-size:15px;margin-bottom:6px}
.upload-hint{font-size:12px;color:var(--mt2,var(--muted2))}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:10px;border:none;font-family:'Syne',sans-serif;font-weight:700;font-size:14px;cursor:pointer;transition:opacity .15s;margin-top:16px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff}
.btn:hover{opacity:.88}
.file-selected{display:none;margin-top:10px;font-size:13px;color:var(--accent);font-weight:500}
.instructions{font-size:13px;color:var(--mt2,var(--muted2));line-height:1.7;margin-top:14px}
.instructions b{color:var(--tx,var(--text))}
.preview-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:12px}
.preview-table th{padding:7px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt2,var(--muted2));border-bottom:1px solid var(--bd,var(--border))}
.preview-table td{padding:8px 10px;border-bottom:1px solid var(--bd,rgba(255,255,255,.04))}
.preview-table tr:last-child td{border-bottom:none}
@media(max-width:768px){.sidebar{display:none}.main{margin-left:0;padding:16px}}
</style>
<?php echoThemeAssets(''); ?>
</head>
<body>
<?php $currentPage = 'tiktok_import'; require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
  <h1 class="page-title">🎵 Importar TikTok Ads</h1>
  <p class="page-subtitle">Importe o relatório de transações do TikTok Ads Manager</p>

  <?php if ($msg): ?>
  <div class="alert alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
  <?php endif; ?>

  <?php if (!empty($preview)): ?>
  <div class="card">
    <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;margin-bottom:12px">✅ Prévia do que foi importado</div>
    <table class="preview-table">
      <thead><tr><th>Data</th><th>Valor</th><th>Conta TikTok</th></tr></thead>
      <tbody>
        <?php foreach ($preview as $p): ?>
        <tr>
          <td style="color:var(--mt2,var(--muted2))"><?= $p['date'] ? date('d/m/Y H:i', strtotime($p['date'])) : '—' ?></td>
          <td style="color:var(--green);font-family:'Syne',sans-serif;font-weight:700">R$ <?= number_format($p['amount'],2,',','.') ?></td>
          <td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(substr($p['account'],0,60)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" enctype="multipart/form-data"><?= Auth::csrfField() ?>
      <div class="upload-area" onclick="document.getElementById('fileInput').click()">
        <div class="upload-icon">🎵</div>
        <div class="upload-label">Clique para selecionar o arquivo XLSX</div>
        <div class="upload-hint">Relatório de transações exportado do TikTok Ads Manager</div>
        <input type="file" id="fileInput" name="tiktok_file" accept=".xlsx" onchange="showFile(this)">
        <div class="file-selected" id="fileSelected"></div>
      </div>
      <div class="instructions">
        <b>Como exportar do TikTok Ads Manager:</b><br>
        Conta → Faturamento → Histórico de transações → Exportar (.xlsx)<br><br>
        <b>O que é importado:</b> Apenas cobranças reais (Bill payment, Success, valor &gt; 0)<br>
        <b>O que é ignorado:</b> Créditos promocionais, expirados, cancelados e o que já foi importado antes<br>
        <span style="color:var(--mt2);font-size:12px">Reimportar o mesmo arquivo é seguro: nada é duplicado.</span>
      </div>
      <button type="submit" class="btn">📥 Importar arquivo</button>
    </form>
  </div>

  <?php
  $tiktokTxns = $db->fetchAll(
      "SELECT t.*, ta.name as tiktok_account_name
       FROM transactions t
       LEFT JOIN tiktok_accounts ta ON ta.account_id = t.account_id
       WHERE t.platform = 'tiktok'
       ORDER BY t.transaction_date DESC LIMIT 100"
  );
  ?>
  <div class="card">
    <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;margin-bottom:12px">
      📊 Transações TikTok importadas
      <?php if (!empty($tiktokTxns)): ?>
      <span style="font-family:'DM Sans',sans-serif;font-weight:400;font-size:12px;color:var(--mt2,var(--muted2));margin-left:8px">(últimas 100)</span>
      <?php endif; ?>
    </div>

    <?php if (empty($tiktokTxns)): ?>
    <div style="text-align:center;padding:32px;color:var(--mt2,var(--muted2))">
      <div style="font-size:32px;margin-bottom:8px">📭</div>
      Nenhuma transação TikTok importada ainda.
    </div>
    <?php else: ?>
    <?php
      $totalTikTok = array_sum(array_column($tiktokTxns, 'amount'));
    ?>
    <div style="margin-bottom:14px;font-family:'Syne',sans-serif;font-weight:800;font-size:20px;color:var(--green)">
      R$ <?= number_format($totalTikTok,2,',','.') ?>
      <span style="font-family:'DM Sans',sans-serif;font-weight:400;font-size:12px;color:var(--mt2,var(--muted2))">total importado</span>
    </div>
    <table class="preview-table">
      <thead>
        <tr>
          <th>Data</th>
          <th>Conta TikTok</th>
          <th>Descrição</th>
          <th style="text-align:right">Valor</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tiktokTxns as $t): ?>
        <tr>
          <td style="color:var(--mt2,var(--muted2));white-space:nowrap"><?= $t['transaction_date'] ? date('d/m/Y H:i', strtotime($t['transaction_date'])) : '—' ?></td>
          <td style="font-size:11px;max-width:180px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($t['tiktok_account_name'] ?? $t['account_id']) ?></td>
          <td style="font-size:11px;color:var(--mt2,var(--muted2));max-width:180px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(substr($t['description']??'',0,60)) ?></td>
          <td style="text-align:right;color:var(--green);font-family:'Syne',sans-serif;font-weight:700;white-space:nowrap">R$ <?= number_format($t['amount'],2,',','.') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</main>

<script>
function showFile(input) {
  var el = document.getElementById('fileSelected');
  if (input.files.length > 0) {
    el.style.display = 'block';
    el.textContent = '📄 ' + input.files[0].name + ' (' + Math.round(input.files[0].size/1024) + ' KB)';
  }
}
</script>
</body>
</html>