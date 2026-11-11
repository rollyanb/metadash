<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();

$db              = Database::getInstance();
$allowedAccounts = Auth::getAllowedAccounts();
$isAdmin         = Auth::isAdmin();
$role            = Auth::getRole();

$accountWhere  = '';
$accountParams = [];
if ($allowedAccounts !== null) {
    if (empty($allowedAccounts)) {
        $accountWhere = ' AND 1=0';
    } else {
        $ph            = implode(',', array_fill(0, count($allowedAccounts), '?'));
        $accountWhere  = " AND a.account_id IN ({$ph})";
        $accountParams = $allowedAccounts;
    }
}

$accounts = $db->fetchAll("
    SELECT a.*,
        COALESCE((SELECT SUM(spend) FROM spend_snapshots s WHERE s.account_id = a.account_id AND s.snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)), 0) AS spend_30d,
        COALESCE((SELECT SUM(spend) FROM spend_snapshots s WHERE s.account_id = a.account_id AND s.snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)), 0) AS spend_7d
    FROM ad_accounts a
    WHERE 1=1 {$accountWhere}
    ORDER BY spend_30d DESC
", $accountParams);
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contas — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
.page-title { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
.page-sub   { font-size: 13px; color: var(--mt2); margin-bottom: 24px; }

/* GRID */
.accounts-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 14px;
}

/* CARD */
.account-card {
  background: var(--sf);
  border: 1px solid var(--bd);
  border-radius: 14px;
  padding: 20px;
  transition: border-color .15s, transform .15s;
}
.account-card:hover {
  border-color: rgba(84,112,255,.3);
  transform: translateY(-1px);
}

/* HEADER */
.acc-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 14px;
}
.acc-name {
  font-size: 14px;
  font-weight: 700;
  color: var(--tx);
  line-height: 1.3;
}
.acc-id {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  color: var(--mt);
  margin-top: 3px;
}

/* STATUS */
.status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
  margin-top: 5px;
}
.status-dot.active   { background: var(--gn); box-shadow: 0 0 6px var(--gn); }
.status-dot.inactive { background: var(--rd); }
.status-dot.payment  { background: var(--yw); box-shadow: 0 0 5px var(--yw); }

/* PLAT BADGE */
.plat-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: 5px;
  font-size: 10px;
  font-weight: 700;
  margin-bottom: 10px;
}
.plat-meta   { background: rgba(24,119,242,.12); color: #1877f2; }
.plat-tiktok { background: rgba(0,196,204,.12);  color: #00c4cc; }

/* CARTÃO */
.card-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: rgba(130,110,255,.08);
  border: 1px solid rgba(130,110,255,.18);
  border-radius: 7px;
  padding: 5px 10px;
  font-size: 12px;
  color: #826eff;
  margin-bottom: 14px;
  font-weight: 500;
}

/* DIVIDER */
.divider {
  border: none;
  border-top: 1px solid var(--bd);
  margin: 13px 0;
}

/* SPEND */
.spend-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 7px;
}
.spend-label { font-size: 12px; color: var(--mt2); }
.spend-val {
  font-family: 'JetBrains Mono', monospace;
  font-size: 14px;
  font-weight: 700;
}
.spend-val.green  { color: var(--gn); }
.spend-val.yellow { color: var(--yw); }

/* SYNC */
.sync-info {
  font-size: 11px;
  color: var(--mt);
  margin-top: 12px;
  display: flex;
  align-items: center;
  gap: 5px;
}

/* EMPTY */
.empty-state {
  text-align: center;
  padding: 64px 20px;
  color: var(--mt2);
}
.empty-state .ico { font-size: 44px; margin-bottom: 14px; }
.empty-state a { color: var(--ac); text-decoration: none; }
.empty-state a:hover { text-decoration: underline; }

@media (max-width: 600px) {
  .accounts-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<?php $currentPage = 'accounts'; $sidebarBase = ''; require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
  <h1 class="page-title">Contas de Anúncio</h1>
  <p class="page-sub"><?= count($accounts) ?> conta(s) cadastradas — gastos nos últimos 30 e 7 dias</p>

  <?php if (empty($accounts)): ?>
  <div class="empty-state">
    <div class="ico">📭</div>
    <div style="font-weight:700;margin-bottom:6px">Nenhuma conta sincronizada</div>
    <div style="font-size:13px">Vá em <a href="sync_manual.php">Sincronizar</a> para importar as contas.</div>
  </div>
  <?php else: ?>
  <div class="accounts-grid">
    <?php foreach ($accounts as $acc):
      $plat      = $acc['platform'] ?? 'meta';
      $platClass = $plat === 'tiktok' ? 'plat-tiktok' : 'plat-meta';
      $platLabel = $plat === 'tiktok' ? '🎵 TikTok Ads' : '📘 Meta Ads';
    ?>
    <div class="account-card">
      <div class="acc-header">
        <div>
          <span class="plat-badge <?= $platClass ?>"><?= $platLabel ?></span>
          <div class="acc-name"><?= htmlspecialchars($acc['account_name']) ?></div>
          <div class="acc-id"><?= htmlspecialchars($acc['account_id']) ?></div>
        </div>
        <div class="status-dot <?= $acc['status']==='ACTIVE'?'active':($acc['status']==='PAYMENT_REQUIRED'?'payment':'inactive') ?>"
             title="<?= htmlspecialchars($acc['status']) ?>"></div>
      </div>

      <?php if (!empty($acc['card_display'])): ?>
      <div class="card-badge">💳 <?= htmlspecialchars($acc['card_display']) ?></div>
      <?php else: ?>
      <div style="font-size:12px;color:var(--mt);margin-bottom:14px">Cartão não identificado</div>
      <?php endif; ?>

      <hr class="divider">

      <div class="spend-row">
        <span class="spend-label">Últimos 30 dias</span>
        <span class="spend-val green">R$ <?= number_format($acc['spend_30d'], 2, ',', '.') ?></span>
      </div>
      <div class="spend-row">
        <span class="spend-label">Últimos 7 dias</span>
        <span class="spend-val yellow">R$ <?= number_format($acc['spend_7d'], 2, ',', '.') ?></span>
      </div>

      <div class="sync-info">
        🕐 Sync: <?= $acc['last_sync'] ? date('d/m/Y H:i', strtotime($acc['last_sync'])) : 'Nunca' ?>
        · <?= htmlspecialchars($acc['currency'] ?? 'BRL') ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>
</body>
</html>