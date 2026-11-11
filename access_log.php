<?php
// access_log.php — Log de acessos e navegação (somente admin)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();
if (!Auth::isAdmin()) { header('Location: index.php'); exit; }
$db = Database::getInstance();

// ── Filtros ──
$fUser  = trim($_GET['u'] ?? '');
$fEvent = $_GET['e'] ?? '';
$fDays  = (int)($_GET['d'] ?? 7);
if (!in_array($fDays, [1, 7, 30, 90])) $fDays = 7;
$page   = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 100;

$eventTypes = [
    'login'       => ['Entrou',           'var(--gr, #34d399)'],
    'login_auto'  => ['Entrou (lembrar)', 'var(--mt2)'],
    'logout'      => ['Saiu',             'var(--mt)'],
    'login_falha' => ['Senha errada',     '#f87171'],
    'bloqueado'   => ['Bloqueado',        '#f87171'],
    'pagina'      => ['Abriu página',     'var(--ac)'],
];
$pageNames = [
    'index.php' => 'Dashboard', 'events.php' => 'Eventos', 'event.php' => 'Evento',
    'transactions.php' => 'Transações', 'paid_transactions.php' => 'Transações pagas',
    'conversions.php' => 'Conversões', 'marketing.php' => 'Criativos', 'accounts.php' => 'Contas',
    'resumos.php' => 'Resumos', 'settings.php' => 'Configurações', 'report_tokens.php' => 'Relatórios',
    'sync_manual.php' => 'Sincronizar', 'import_invoice.php' => 'Importar fatura',
    'tiktok_import.php' => 'Importar TikTok', 'push_settings.php' => 'Notif. Push',
    'push_test.php' => 'Teste Push', 'cron_status.php' => 'Status dos crons',
    'access_log.php' => 'Log de acessos', 'users.php' => 'Usuários', 'companies.php' => 'Empresas',
];
function prettyPage($p, $pageNames) {
    if (!$p) return '—';
    $parts = explode('?', $p, 2);
    $nome = $pageNames[$parts[0]] ?? $parts[0];
    if (isset($parts[1])) $nome .= ' (' . str_replace(['id=', 'conta='], ['#', ''], $parts[1]) . ')';
    return $nome;
}

$where = ["created_at > NOW() - INTERVAL {$fDays} DAY"];
$params = [];
if ($fUser  !== '') { $where[] = 'username = ?'; $params[] = $fUser; }
if ($fEvent !== '' && isset($eventTypes[$fEvent])) { $where[] = 'event = ?'; $params[] = $fEvent; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$rows = []; $total = 0; $online = []; $porUser = []; $topPages = []; $ipsPorUser = [];
try {
    $cnt = $db->fetch("SELECT COUNT(*) c FROM access_log $whereSql", $params);
    $total = (int)($cnt['c'] ?? 0);
    $rows = $db->fetchAll("SELECT * FROM access_log $whereSql ORDER BY created_at DESC
                           LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
    $online = $db->fetchAll("
        SELECT l.username, MAX(l.created_at) ultimo FROM access_log l
        WHERE l.event IN ('login','login_auto','pagina') AND l.created_at > NOW() - INTERVAL 30 MINUTE
        GROUP BY l.username ORDER BY ultimo DESC");
    $porUser = $db->fetchAll("
        SELECT username,
               SUM(event IN ('login','login_auto')) logins,
               SUM(event = 'pagina') paginas,
               SUM(event IN ('login_falha','bloqueado')) falhas,
               MAX(created_at) ultimo
        FROM access_log WHERE created_at > NOW() - INTERVAL {$fDays} DAY AND username <> ''
        GROUP BY username ORDER BY ultimo DESC");
    $topPages = $db->fetchAll("
        SELECT SUBSTRING_INDEX(page,'?',1) p, COUNT(*) n FROM access_log
        WHERE event='pagina' AND created_at > NOW() - INTERVAL {$fDays} DAY AND page IS NOT NULL
        GROUP BY p ORDER BY n DESC LIMIT 10");
    $ipsPorUser = $db->fetchAll("
        SELECT username, ip, COUNT(*) n, MAX(created_at) ultimo FROM access_log
        WHERE event IN ('login','login_auto') AND created_at > NOW() - INTERVAL 90 DAY AND ip IS NOT NULL
        GROUP BY username, ip ORDER BY username, n DESC");
} catch (Exception $e) {
    $erro = 'A tabela access_log ainda não existe. Ela é criada automaticamente no próximo login.';
}
$usuarios = array_values(array_unique(array_column($porUser, 'username')));
$totalPages = max(1, (int)ceil($total / $perPage));
function qs($over = []) {
    return http_build_query(array_merge($_GET, $over));
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log de acessos — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
.seg { display:flex;border:1px solid var(--bd);border-radius:10px;overflow:hidden }
.seg-item { padding:7px 13px;font-size:12px;font-weight:600;color:var(--mt2);text-decoration:none;border-right:1px solid var(--bd) }
.seg-item:last-child{border-right:none} .seg-item.on{background:var(--s2);color:var(--tx)}
.log-row { display:grid;grid-template-columns:150px 110px 1fr 130px 150px;gap:10px;align-items:center;
           font-size:12px;padding:7px 0;border-bottom:1px solid var(--bd) }
.log-row .mut { color:var(--mt);font-size:11px }
@media (max-width:820px){ .log-row{grid-template-columns:1fr 1fr;row-gap:2px} .log-row .hide-m{display:none} }
</style>
</head>
<body>
<div class="app-wrap">
<?php $currentPage = 'access_log'; require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:20px;flex-wrap:wrap">
    <div>
      <h1 class="page-title">Log de acessos</h1>
      <p class="page-sub">
        <?= number_format($total, 0, ',', '.') ?> registros nos últimos <?= $fDays ?> dias
        <?php if ($online): ?>&middot; <span style="color:var(--gr, #34d399);font-weight:600"><?= count($online) ?> online agora</span><?php endif; ?>
      </p>
    </div>
    <div class="seg">
      <?php foreach ([1 => '24h', 7 => '7 dias', 30 => '30 dias', 90 => '90 dias'] as $d => $lbl): ?>
      <a href="?<?= qs(['d' => $d, 'pg' => 1]) ?>" class="seg-item <?= $fDays === $d ? 'on' : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!empty($erro)): ?>
  <div class="alert alert-warning" style="padding:13px 18px;border-radius:12px;background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);color:var(--yw);margin-bottom:20px"><?= $erro ?></div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:16px;margin-bottom:22px">
    <div class="card">
      <div style="font-size:12px;font-weight:700;margin-bottom:10px">👥 Por usuário</div>
      <?php foreach ($porUser as $u): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:12px;padding:4px 0;border-bottom:1px solid var(--bd)">
        <span>
          <?php if (in_array($u['username'], array_column($online, 'username'))): ?><span class="sync-dot"></span><?php endif; ?>
          <a href="?<?= qs(['u' => $u['username'], 'pg' => 1]) ?>" style="color:var(--tx);text-decoration:none;font-weight:600"><?= htmlspecialchars($u['username']) ?></a>
        </span>
        <span style="color:var(--mt);font-size:11px;text-align:right">
          <?= $u['logins'] ?> login<?= $u['logins'] != 1 ? 's' : '' ?> · <?= $u['paginas'] ?> páginas
          <?php if ($u['falhas'] > 0): ?><span style="color:#f87171"> · <?= $u['falhas'] ?> falha<?= $u['falhas'] != 1 ? 's' : '' ?></span><?php endif; ?>
          <br><?= date('d/m H:i', strtotime($u['ultimo'])) ?>
        </span>
      </div>
      <?php endforeach; ?>
      <?php if (!$porUser): ?><div style="font-size:12px;color:var(--mt)">Nenhum acesso no período.</div><?php endif; ?>
    </div>

    <div class="card">
      <div style="font-size:12px;font-weight:700;margin-bottom:10px">📄 Páginas mais acessadas</div>
      <?php $mx = $topPages ? max(array_column($topPages, 'n')) : 1; foreach ($topPages as $tp): ?>
      <div style="display:flex;align-items:center;gap:8px;font-size:11.5px;padding:3px 0">
        <span style="width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($pageNames[$tp['p']] ?? $tp['p']) ?></span>
        <span style="flex:1;height:5px;background:var(--s2);border-radius:3px;overflow:hidden"><span style="display:block;height:100%;width:<?= round($tp['n'] / $mx * 100) ?>%;background:var(--ac)"></span></span>
        <span style="color:var(--mt);width:40px;text-align:right"><?= $tp['n'] ?></span>
      </div>
      <?php endforeach; ?>
      <?php if (!$topPages): ?><div style="font-size:12px;color:var(--mt)">Sem navegação registrada ainda.</div><?php endif; ?>
    </div>

    <div class="card">
      <div style="font-size:12px;font-weight:700;margin-bottom:10px">🌐 IPs por usuário (90 dias)</div>
      <div style="max-height:220px;overflow:auto">
      <?php foreach ($ipsPorUser as $ipu): ?>
      <div style="display:flex;justify-content:space-between;gap:8px;font-size:11.5px;padding:3px 0">
        <span><b><?= htmlspecialchars($ipu['username']) ?></b> <span style="color:var(--mt);font-family:'JetBrains Mono',monospace"><?= htmlspecialchars($ipu['ip']) ?></span></span>
        <span style="color:var(--mt);white-space:nowrap"><?= $ipu['n'] ?>x · <?= date('d/m', strtotime($ipu['ultimo'])) ?></span>
      </div>
      <?php endforeach; ?>
      <?php if (!$ipsPorUser): ?><div style="font-size:12px;color:var(--mt)">Nenhum login registrado.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px">
      <div style="font-size:12px;font-weight:700">🕘 Histórico</div>
      <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
        <input type="hidden" name="d" value="<?= $fDays ?>">
        <select name="u" onchange="this.form.submit()" style="width:auto;font-size:12px;padding:5px 8px">
          <option value="">Todos os usuários</option>
          <?php foreach ($usuarios as $un): ?>
          <option value="<?= htmlspecialchars($un) ?>" <?= $fUser === $un ? 'selected' : '' ?>><?= htmlspecialchars($un) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="e" onchange="this.form.submit()" style="width:auto;font-size:12px;padding:5px 8px">
          <option value="">Todos os eventos</option>
          <?php foreach ($eventTypes as $ek => $ev): ?>
          <option value="<?= $ek ?>" <?= $fEvent === $ek ? 'selected' : '' ?>><?= $ev[0] ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($fUser || $fEvent): ?><a href="?d=<?= $fDays ?>" class="btn btn-o" style="font-size:12px;padding:5px 10px">Limpar</a><?php endif; ?>
      </form>
    </div>

    <div class="log-row" style="font-weight:700;color:var(--mt);font-size:10.5px;text-transform:uppercase;letter-spacing:.05em">
      <span>Quando</span><span>Usuário</span><span>Ação</span><span class="hide-m">IP</span><span class="hide-m">Dispositivo</span>
    </div>
    <?php foreach ($rows as $r):
      $et = $eventTypes[$r['event']] ?? [$r['event'], 'var(--mt)'];
      $ua = (string)$r['user_agent'];
      $mob = preg_match('/(iPhone|iPad|Android|Mobile)/i', $ua);
      $nav = preg_match('/Edg/i', $ua) ? 'Edge' : (preg_match('/Chrome/i', $ua) ? 'Chrome' : (preg_match('/Safari/i', $ua) ? 'Safari' : (preg_match('/Firefox/i', $ua) ? 'Firefox' : 'outro')));
    ?>
    <div class="log-row">
      <span class="mut"><?= date('d/m/Y H:i:s', strtotime($r['created_at'])) ?></span>
      <span style="font-weight:600"><?= htmlspecialchars($r['username'] ?: '—') ?></span>
      <span>
        <span style="color:<?= $et[1] ?>;font-weight:600"><?= $et[0] ?></span>
        <?php if ($r['event'] === 'pagina'): ?>
        <span style="color:var(--mt)"> · <?= htmlspecialchars(prettyPage($r['page'], $pageNames)) ?></span>
        <?php endif; ?>
      </span>
      <span class="mut hide-m" style="font-family:'JetBrains Mono',monospace"><?= htmlspecialchars((string)$r['ip']) ?></span>
      <span class="mut hide-m"><?= $mob ? '📱' : '💻' ?> <?= $nav ?></span>
    </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
    <div style="text-align:center;padding:34px;color:var(--mt);font-size:13px">Nenhum registro com esses filtros.</div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <div style="display:flex;justify-content:center;gap:8px;margin-top:14px;font-size:12px">
      <?php if ($page > 1): ?><a href="?<?= qs(['pg' => $page - 1]) ?>" class="btn btn-o" style="padding:5px 12px">← Anterior</a><?php endif; ?>
      <span style="color:var(--mt);padding:6px 4px">Página <?= $page ?> de <?= $totalPages ?></span>
      <?php if ($page < $totalPages): ?><a href="?<?= qs(['pg' => $page + 1]) ?>" class="btn btn-o" style="padding:5px 12px">Próxima →</a><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

</main>
</div>
</body>
</html>
