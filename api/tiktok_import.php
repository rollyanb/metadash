<?php
// api/tiktok_import.php
// Importação de relatórios TikTok via XLSX nativo (ZipArchive + SimpleXML)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

// Apenas admin e chefe têm acesso
// CORREÇÃO: removido o primeiro if(!Auth::isAdmin()) que bloqueava chefe antes do segundo check
if (!in_array(Auth::getRole(), ['admin', 'chefe'])) {
    header('Location: ../index.php');
    exit;
}

Auth::requireLogin();

$db    = Database::getInstance();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();

    if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Nenhum arquivo enviado ou erro no upload.';
    } else {
        $tmpFile = $_FILES['xlsx_file']['tmp_name'];
        $origName = $_FILES['xlsx_file']['name'];

        // Valida extensão
        if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'xlsx') {
            $error = 'Apenas arquivos .xlsx são aceitos.';
        } else {
            // Valida MIME real via finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmpFile);
            finfo_close($finfo);
            $validMimes = [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
                'application/octet-stream',
            ];
            if (!in_array($mime, $validMimes)) {
                $error = 'Arquivo inválido (tipo MIME: ' . htmlspecialchars($mime) . ')';
            }
        }

        if (!$error) {
            try {
                $rows = parseTiktokXlsx($tmpFile);
                if (empty($rows)) {
                    $error = 'Nenhuma linha válida encontrada no arquivo.';
                } else {
                    $imported = 0;
                    $skipped  = 0;
                    $db->execute("BEGIN");
                    foreach ($rows as $row) {
                        // Upsert por campaign_id + date
                        $existing = $db->fetch(
                            "SELECT id FROM tiktok_campaigns WHERE campaign_id = ? AND date = ?",
                            [$row['campaign_id'], $row['date']]
                        );
                        if ($existing) {
                            $db->execute(
                                "UPDATE tiktok_campaigns SET spend = ?, impressions = ?, clicks = ?, updated_at = NOW() WHERE id = ?",
                                [$row['spend'], $row['impressions'], $row['clicks'], $existing['id']]
                            );
                            $skipped++;
                        } else {
                            $db->execute(
                                "INSERT INTO tiktok_campaigns (campaign_id, campaign_name, date, spend, impressions, clicks, created_at) VALUES (?,?,?,?,?,?,NOW())",
                                [$row['campaign_id'], $row['campaign_name'], $row['date'], $row['spend'], $row['impressions'], $row['clicks']]
                            );
                            $imported++;
                        }
                    }
                    $db->execute("COMMIT");
                    $success = "Importação concluída: {$imported} novo(s), {$skipped} atualizado(s).";
                    app_log('info', 'TikTok import concluído', ['imported' => $imported, 'skipped' => $skipped]);
                }
            } catch (Throwable $e) {
                $db->execute("ROLLBACK");
                app_log('error', 'TikTok import falhou: ' . $e->getMessage());
                $error = 'Erro ao processar o arquivo. Verifique o formato.';
            }
        }
    }
}

function parseTiktokXlsx($filePath) {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Não foi possível abrir o arquivo XLSX.');
    }

    // Lê strings compartilhadas
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = new SimpleXMLElement($ssXml);
        foreach ($ss->si as $si) {
            $val = '';
            if (isset($si->t)) {
                $val = (string)$si->t;
            } else {
                foreach ($si->r as $r) {
                    if (isset($r->t)) $val .= (string)$r->t;
                }
            }
            $sharedStrings[] = $val;
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if (!$sheetXml) throw new RuntimeException('Planilha não encontrada no XLSX.');

    $sheet = new SimpleXMLElement($sheetXml);
    $rows = [];
    $header = [];

    foreach ($sheet->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $t = (string)($cell['t'] ?? '');
            $v = isset($cell->v) ? (string)$cell->v : '';
            if ($t === 's') {
                $v = $sharedStrings[(int)$v] ?? '';
            }
            $rowData[] = trim($v);
        }

        if (empty($header)) {
            // Normaliza cabeçalhos
            $header = array_map(fn($h) => strtolower(preg_replace('/\s+/', '_', $h)), $rowData);
            continue;
        }

        $combined = array_combine($header, array_pad($rowData, count($header), ''));

        // Mapeamento de nomes de coluna comuns nos exports TikTok
        $campaignId   = $combined['campaign_id']   ?? ($combined['ad_group_id'] ?? '');
        $campaignName = $combined['campaign_name'] ?? ($combined['ad_group_name'] ?? '');
        $date         = $combined['date']          ?? ($combined['stat_time_day'] ?? '');
        $spend        = (float)str_replace(',', '.', $combined['cost'] ?? ($combined['spend'] ?? 0));
        $impressions  = (int)($combined['impressions'] ?? 0);
        $clicks       = (int)($combined['clicks'] ?? 0);

        if (!$campaignId || !$date) continue;

        // Normaliza data para Y-m-d
        try {
            $dateObj = new DateTime($date);
            $date    = $dateObj->format('Y-m-d');
        } catch (Exception $e) {
            continue; // ignora linha com data inválida
        }

        $rows[] = compact('campaign_id', 'campaign_name', 'date', 'spend', 'impressions', 'clicks');
    }

    return $rows;
}

// Renomeia variáveis para usar no template
$campaignId = $campaignName = $date = $spend = $impressions = $clicks = null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Importar TikTok — MetaDash</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<?php include '../includes/header.php'; ?>
<main class="container mt-4">
    <h2>Importar Relatório TikTok</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?= Auth::csrfField() ?>
        <div class="mb-3">
            <label for="xlsx_file" class="form-label">Arquivo XLSX (exportado do TikTok Ads Manager)</label>
            <input type="file" id="xlsx_file" name="xlsx_file" class="form-control" accept=".xlsx" required>
        </div>
        <button type="submit" class="btn btn-primary">Importar</button>
        <a href="../index.php" class="btn btn-secondary">Cancelar</a>
    </form>
</main>
<?php include '../includes/footer.php'; ?>
</body>
</html>
