<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();

$role = Auth::getRole();
if ($role !== 'admin') {
    header('Location: index.php'); exit;
}

$db = Database::getInstance();

// Cria tabela de log se não existe
$hasCronLog = false;
try {
    $db->execute("CREATE TABLE IF NOT EXISTS cron_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        duration_sec INT DEFAULT 0,
        accounts_synced INT DEFAULT 0,
        conv_records INT DEFAULT 0,
        status VARCHAR(20) DEFAULT 'ok',
        notes TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $hasCronLog = true;
} catch (Exception $e) {}

$logs  = $hasCronLog ? $db->fetchAll("SELECT * FROM cron_log ORDER BY ran_at DESC LIMIT 20") : [];
$lastS = $db->fetch("SELECT MAX(snapshot_date) as last FROM spend_snapshots");
$lastC = $db->fetch("SELECT MAX(synced_at) as last FROM conversion_snapshots");

$lastRan    = null;
$minutesAgo = null;
if (!empty($logs) && !empty($logs[0]['ran_at'])) {
    try {
        $lastRan    = new DateTime($logs[0]['ran_at']);
        $now        = new DateTime();
        $minutesAgo = round(($now->getTimestamp() - $lastRan->getTimestamp()) / 60);
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Status do Cron — MetaDash</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--ac:#4f6ef7;--ac2:#a78bfa;--gn:#34d399;--rd:#f87171;--yw:#fbbf24;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--tx);min-height:100vh;display:flex}
.sidebar{width:240px;min-height:100vh;background:var(--sf);border-right:1px solid var(--bd);display:flex;flex-direction:column;padding:24px 16px;position:fixed;top:0;left:0;bottom:0}
.logo{display:flex;align-items:center;gap:10px;padding:8px 12px 28px;border-bottom:1px solid var(--bd);margin-bottom:20px}
.logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--ac),var(--ac2));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px}
.logo-text{font-family:'Syne',sans-serif;font-weight:800;font-size:16px}.logo-text span{color:var(--ac)}
.nav-label{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--mt);padding:0 12px;margin-bottom:8px;margin-top:16px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:var(--mt2);text-decoration:none;font-size:14px;font-weight:500;transition:all .15s;margin-bottom:2px}
.nav-item:hover,.nav-item.active{background:rgba(79,110,247,.12);color:var(--tx)}.nav-item.active{color:var(--ac)}
.sidebar-footer{margin-top:auto;padding-top:20px;border-top:1px solid var(--bd)}
.sidebar-footer a{display:block;text-align:center;color:var(--mt);font-size:12px;text-decoration:none;padding:6px}
.main{margin-left:240px;flex:1;padding:32px}
h1{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;margin-bottom:4px}
.sub{color:var(--mt2);font-size:14px;margin-bottom:24px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:28px}
.sc{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:20px}
.sc-label{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt2);margin-bottom:6px}
.sc-value{font-family:'Syne',sans-serif;font-size:20px;font-weight:800}
.sc-sub{font-size:12px;color:var(--mt2);margin-top:4px}
.ok{color:var(--gn)}.warn{color:var(--yw)}.err{color:var(--rd)}
.card{background:var(--sf);border:1px solid var(--bd);border-radius:16px;overflow:hidden;margin-bottom:20px}
.card-hd{padding:18px 20px;border-bottom:1px solid var(--bd);font-family:'Syne',sans-serif;font-size:15px;font-weight:700}
table{width:100%;border-collapse:collapse}
thead th{padding:10px 16px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt);background:rgba(0,0,0,.2);border-bottom:1px solid var(--bd)}
tbody tr{border-bottom:1px solid var(--bd)}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:rgba(255,255,255,.02)}
td{padding:12px 16px;font-size:13px;vertical-align:top}
.badge{display:inline-flex;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-ok{background:rgba(52,211,153,.1);color:var(--gn)}
.badge-partial{background:rgba(251,191,36,.1);color:var(--yw)}
.badge-skip,.badge-{background:rgba(136,136,160,.1);color:var(--mt2)}
.badge-err{background:rgba(248,113,113,.1);color:var(--rd)}
.cmd{background:var(--s2);border:1px solid var(--bd);border-radius:10px;padding:14px;font-family:monospace;font-size:12px;color:var(--ac2);word-break:break-all;margin-bottom:8px}
.notes{font-family:monospace;font-size:11px;color:var(--mt2);white-space:pre-wrap;background:var(--s2);padding:8px;border-radius:6px;margin-top:4px;max-height:80px;overflow-y:auto}
.empty{padding:32px;text-align:center;color:var(--mt2);font-size:13px}
</style>
<?php require_once __DIR__ . '/includes/theme.php'; echoThemeAssets(''); ?>
</head>
<body>

<?php
$currentPage = 'cron_status';
require __DIR__ . '/includes/sidebar.php';
?>

<main class="main">
  <h1>⏱️ Status do Cron</h1>
  <p class="sub">Monitore se a sincronização automática está funcionando</p>

  <div class="grid">
    <div class="sc">
      <div class="sc-label">Última execução via cron</div>
      <?php if ($lastRan && $minutesAgo !== null): ?>
        <?php $cls = $minutesAgo < 35 ? 'ok' : ($minutesAgo < 70 ? 'warn' : 'err'); ?>
        <div class="sc-value <?= $cls ?>"><?= $minutesAgo ?>min atrás</div>
        <div class="sc-sub"><?= date('d/m/Y H:i', strtotime($logs[0]['ran_at'])) ?></div>
      <?php else: ?>
        <div class="sc-value err">Nunca rodou</div>
        <div class="sc-sub">Cron não configurado ou sem execuções</div>
      <?php endif; ?>
    </div>
    <div class="sc">
      <div class="sc-label">Último snapshot gasto</div>
      <div class="sc-value <?= !empty($lastS['last']) ? 'ok' : 'err' ?>">
        <?= !empty($lastS['last']) ? date('d/m H:i', strtotime($lastS['last'])) : 'Nunca' ?>
      </div>
    </div>
    <div class="sc">
      <div class="sc-label">Último snapshot conversão</div>
      <div class="sc-value <?= !empty($lastC['last']) ? 'ok' : 'err' ?>">
        <?= !empty($lastC['last']) ? date('d/m H:i', strtotime($lastC['last'])) : 'Nunca' ?>
      </div>
    </div>
    <div class="sc">
      <div class="sc-label">Hora do servidor agora</div>
      <div class="sc-value"><?= date('H:i:s') ?></div>
      <div class="sc-sub"><?= date('d/m/Y') ?></div>
    </div>
  </div>

  <div style="margin-bottom:20px">
    <div style="font-family:'Syne',sans-serif;font-size:15px;font-weight:700;margin-bottom:10px">⚙️ Configuração do cron no hPanel</div>
    <div class="cmd">domains/metadash.space/public_html/cron/sync.php</div>
    <div style="font-size:12px;color:var(--mt2)">Intervalo: <strong style="color:var(--tx)">*/30 * * * *</strong> &nbsp;·&nbsp; Tipo: <strong style="color:var(--tx)">PHP</strong></div>
  </div>

  <div class="card">
    <div class="card-hd">📋 Histórico de execuções</div>
    <?php if (empty($logs)): ?>
    <div class="empty">
      Nenhuma execução registrada ainda.<br>
      <span style="font-size:12px;margin-top:6px;display:block">O histórico aparecerá aqui após o cron rodar pela primeira vez.</span>
    </div>
    <?php else: ?>
    <div class="table-wrap"><table class="mob-card-table">
      <thead>
        <tr><th>Data/Hora</th><th>Status</th><th>Duração</th><th>Contas</th><th>Conv.</th><th>Log</th></tr>
      </thead>
      <tbody>
      <?php foreach($logs as $l): ?>
      <tr>
        <td data-label="Data/Hora"><?= date('d/m/Y H:i:s', strtotime($l['ran_at'])) ?></td>
        <td data-label="Status"><span class="badge badge-<?= htmlspecialchars($l['status']) ?>"><?= htmlspecialchars($l['status']) ?></span></td>
        <td data-label="Duração"><?= intval($l['duration_sec']) ?>s</td>
        <td data-label="Contas"><?= intval($l['accounts_synced']) ?></td>
        <td data-label="Conv."><?= intval($l['conv_records']) ?></td>
        <td data-label="Log"><?php if(!empty($l['notes'])): ?><div class="notes"><?= htmlspecialchars($l['notes']) ?></div><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>