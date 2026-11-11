<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/webpush.php';

Auth::requireLogin();
if (!Auth::isAdmin()) { header('Location: index.php'); exit; } // área administrativa
if (!Auth::isAdmin()) { header('Location: index.php'); exit; }


define('VAPID_PUBLIC',  'BOD8saPXZbuLU_Tf1HvD-eNQekZBjmjcyuBeOhyd5Khld_THUGpP4adEgl_RD9q3YPr-oEDPezxS3buQk7Mippo');
define('VAPID_PRIVATE', 'ejsdX8aOvZVs8xuw9uvriYbtB-xHqmLsvgTuCJjX-II');
define('VAPID_EMAIL',   'mailto:contato@rollyan.com.br');

$db = Database::getInstance();

// ── SALVAR HORÁRIOS ───────────────────────────────────────────
$settingsResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_hours') {
    $start = intval($_POST['push_hour_start'] ?? 10);
    $end   = intval($_POST['push_hour_end']   ?? 20);
    $start = max(0, min(23, $start));
    $end   = max(1, min(24, $end));
    if ($start >= $end) {
        $settingsResult = ['ok' => false, 'msg' => 'Hora de início deve ser menor que a hora de fim'];
    } else {
        $db->execute("INSERT INTO system_settings (`key`, `value`) VALUES ('push_hour_start', ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$start]);
        $db->execute("INSERT INTO system_settings (`key`, `value`) VALUES ('push_hour_end',   ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$end]);
        $settingsResult = ['ok' => true, 'msg' => "Horário salvo: {$start}h às {$end}h"];
    }
}

// Lê configuração atual
$cfgStart = intval($db->fetch("SELECT value FROM system_settings WHERE `key`='push_hour_start'")['value'] ?? 10);
$cfgEnd   = intval($db->fetch("SELECT value FROM system_settings WHERE `key`='push_hour_end'")['value']   ?? 20);
$hourNow  = intval(date('H'));
$isInWindow = ($hourNow >= $cfgStart && $hourNow < $cfgEnd);

// ── DISPARO VIA POST ──────────────────────────────────────────
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'save_hours') {
    $title  = trim($_POST['title']  ?? 'MetaDash — Teste');
    $body   = trim($_POST['body']   ?? 'Notificação de teste enviada com sucesso!');
    $url    = trim($_POST['url']    ?? '/index.php');
    $target = $_POST['target'] ?? 'all'; // 'all' ou user_id específico

    $where  = $target === 'all' ? '' : ' WHERE user_id = ' . intval($target);
    $subs   = $db->fetchAll("SELECT user_id, endpoint, p256dh, auth FROM push_subscriptions" . $where);

    if (empty($subs)) {
        $result = ['ok' => false, 'msg' => 'Nenhuma inscrição encontrada' . ($target !== 'all' ? ' para este usuário' : '')];
    } else {
        $motivo = null;
        if (!SimpleWebPush::disponivel($motivo)) {
            $result = ['ok' => false, 'msg' => 'Push indisponível neste servidor: ' . $motivo];
        } else {
            $webPush = new SimpleWebPush(VAPID_PUBLIC, VAPID_PRIVATE, VAPID_EMAIL);
            $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);

            $sent = 0; $failed = 0; $removed = 0; $erros = [];
            foreach ($subs as $sub) {
                $r = $webPush->send($sub['endpoint'], $sub['p256dh'], $sub['auth'], $payload);
                if ($r['ok']) {
                    $sent++;
                } else {
                    $failed++;
                    if (count($erros) < 3) $erros[] = 'HTTP ' . $r['status'] . ': ' . mb_substr((string)$r['error'], 0, 80);
                    // só remove quando o serviço diz que a inscrição morreu
                    if ($r['expirado']) {
                        $db->execute("DELETE FROM push_subscriptions WHERE endpoint = ?", [$sub['endpoint']]);
                        $removed++;
                    }
                }
            }

            $result = [
                'ok'      => $sent > 0,
                'msg'     => "Enviado: {$sent} · Falhou: {$failed}"
                             . ($removed > 0 ? " · {$removed} insc. expirada(s) removida(s)" : '')
                             . ($erros ? ' — ' . implode(' | ', $erros) : ''),
                'sent'    => $sent,
                'failed'  => $failed,
                'removed' => $removed,
            ];
        }
    }
}

// ── DADOS PARA A PÁGINA ───────────────────────────────────────
// Usuários com inscrições
$users = $db->fetchAll("
    SELECT u.id, u.username, u.role,
           COUNT(ps.id) as sub_count,
           MAX(ps.created_at) as last_sub
    FROM users u
    LEFT JOIN push_subscriptions ps ON ps.user_id = u.id
    GROUP BY u.id
    ORDER BY sub_count DESC, u.username
");

// Total de inscrições
$totalSubs = array_sum(array_column($users, 'sub_count'));

// Histórico recente de push_last_sent
$history = $db->fetchAll("
    SELECT pls.*, u.username, a.account_name
    FROM push_last_sent pls
    JOIN users u ON u.id = pls.user_id
    JOIN ad_accounts a ON a.account_id = pls.account_id
    ORDER BY pls.sent_at DESC
    LIMIT 20
");
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teste Push — MetaDash</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root { --accent:#4f6ef7; --accent2:#a78bfa; --green:#34d399; --red:#f87171; --yellow:#fbbf24; }
body { font-family:'DM Sans',sans-serif; background:var(--bg,#07070f); color:var(--tx,#ededf5); min-height:100vh; display:flex; }
.sidebar { width:240px; min-height:100vh; background:var(--sf,#0f0f1a); border-right:1px solid var(--bd,rgba(255,255,255,.07)); display:flex; flex-direction:column; padding:24px 16px; position:fixed; top:0; left:0; bottom:0; }
.logo { display:flex; align-items:center; gap:10px; padding:8px 12px 28px; border-bottom:1px solid var(--bd,rgba(255,255,255,.07)); margin-bottom:20px; }
.logo-icon { width:36px; height:36px; background:linear-gradient(135deg,var(--accent),var(--accent2)); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; }
.logo-text { font-family:'Syne',sans-serif; font-weight:800; font-size:16px; }
.logo-text span { color:var(--accent); }
.nav-label { font-size:10px; text-transform:uppercase; letter-spacing:1.5px; color:var(--mt,#5c5c78); padding:0 12px; margin-bottom:8px; margin-top:16px; }
.nav-item { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; color:var(--mt2,#8888a0); text-decoration:none; font-size:14px; font-weight:500; transition:all .15s; margin-bottom:2px; }
.nav-item:hover,.nav-item.active { background:rgba(79,110,247,.12); color:var(--tx,#ededf5); }
.sidebar-footer { margin-top:auto; padding-top:20px; border-top:1px solid var(--bd,rgba(255,255,255,.07)); }
.logout-link { display:block; text-align:center; color:var(--mt,#5c5c78); font-size:12px; text-decoration:none; padding:6px; }
.main { margin-left:240px; flex:1; padding:32px; }

.page-title { font-family:'Syne',sans-serif; font-size:26px; font-weight:800; margin-bottom:6px; }
.page-subtitle { color:var(--mt2,#8888a0); font-size:14px; margin-bottom:28px; }

.grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; }
.card { background:var(--sf,#0f0f1a); border:1px solid var(--bd,rgba(255,255,255,.07)); border-radius:16px; padding:24px; }
.card-title { font-family:'Syne',sans-serif; font-size:14px; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px; }

/* Form */
label { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.8px; color:var(--mt2,#8888a0); margin-bottom:6px; margin-top:14px; }
label:first-of-type { margin-top:0; }
input[type="text"], textarea, select {
  width:100%; padding:10px 14px;
  background:var(--s2,#161625); border:1px solid var(--bd,rgba(255,255,255,.07));
  border-radius:8px; color:var(--tx,#ededf5);
  font-family:'DM Sans',sans-serif; font-size:14px; outline:none;
  transition:border-color .15s;
}
input[type="text"]:focus, textarea:focus, select:focus { border-color:var(--accent); }
textarea { resize:vertical; min-height:80px; }

.btn-send {
  margin-top:20px; width:100%; padding:13px;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  border:none; border-radius:10px; color:#fff;
  font-family:'Syne',sans-serif; font-weight:700; font-size:15px;
  cursor:pointer; transition:opacity .2s;
}
.btn-send:hover { opacity:.88; }
.btn-send:disabled { opacity:.5; cursor:not-allowed; }

/* Result */
.result {
  margin-top:16px; padding:14px 16px; border-radius:10px; font-size:14px;
  display:flex; align-items:center; gap:10px;
}
.result.ok  { background:rgba(52,211,153,.1); border:1px solid rgba(52,211,153,.3); color:var(--green); }
.result.err { background:rgba(248,113,113,.1); border:1px solid rgba(248,113,113,.3); color:var(--red); }

/* Stats */
.stat-row { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--bd,rgba(255,255,255,.07)); font-size:13px; }
.stat-row:last-child { border-bottom:none; }
.stat-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:6px; font-size:11px; font-weight:600; }
.badge-has { background:rgba(52,211,153,.1); color:var(--green); }
.badge-no  { background:rgba(248,113,113,.1); color:var(--red); }
.badge-role { background:rgba(79,110,247,.1); color:var(--accent); font-size:10px; padding:2px 6px; border-radius:4px; }

/* History table */
table { width:100%; border-collapse:collapse; font-size:12px; }
thead th { padding:8px 12px; text-align:left; color:var(--mt2,#8888a0); font-size:10px; text-transform:uppercase; letter-spacing:.8px; background:rgba(0,0,0,.2); border-bottom:1px solid var(--bd,rgba(255,255,255,.07)); }
tbody td { padding:9px 12px; border-bottom:1px solid var(--bd,rgba(255,255,255,.04)); }
tbody tr:last-child td { border-bottom:none; }
tbody tr:hover td { background:rgba(255,255,255,.02); }

@media(max-width:768px) {
  .sidebar { display:none; }
  .main { margin-left:0; padding:16px; }
  .grid { grid-template-columns:1fr; }
}
</style>
<?php echoThemeAssets(''); ?>
</head>
<body>
<?php $currentPage = ''; require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main">
  <h1 class="page-title">🔔 Teste de Push</h1>
  <p class="page-subtitle">Disparo manual de notificações — apenas admin</p>

  <div class="grid">

    <!-- ── FORMULÁRIO DE DISPARO ── -->
    <div class="card">
      <div class="card-title">📤 Disparar Notificação</div>

      <?php if ($result): ?>
      <div class="result <?= $result['ok'] ? 'ok' : 'err' ?>">
        <?= $result['ok'] ? '✅' : '❌' ?> <?= htmlspecialchars($result['msg']) ?>
      </div>
      <?php endif; ?>

      <form method="POST" action=""><?= Auth::csrfField() ?>
        <label>Título</label>
        <input type="text" name="title" value="MetaDash — Teste" required>

        <label>Mensagem</label>
        <textarea name="body">Notificação de teste enviada com sucesso! 🎉</textarea>

        <label>URL ao clicar</label>
        <input type="text" name="url" value="/index.php">

        <label>Destinatário</label>
        <select name="target">
          <option value="all">👥 Todos os usuários (<?= $totalSubs ?> inscrição<?= $totalSubs != 1 ? 'ões' : '' ?>)</option>
          <?php foreach ($users as $u): if (!$u['sub_count']) continue; ?>
          <option value="<?= $u['id'] ?>">
            <?= htmlspecialchars($u['username']) ?> (<?= $u['sub_count'] ?> dispos.)
          </option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="btn-send" id="sendBtn" onclick="this.textContent='Enviando...';this.disabled=true;this.form.submit()">
          🚀 Disparar agora
        </button>
      </form>
    </div>

    <!-- ── STATUS DAS INSCRIÇÕES ── -->
    <div class="card">
      <div class="card-title">📋 Inscrições por Usuário</div>

      <?php if (empty($users)): ?>
      <p style="color:var(--mt2);font-size:13px">Nenhum usuário encontrado.</p>
      <?php else: ?>
      <?php foreach ($users as $u): ?>
      <div class="stat-row">
        <div>
          <span style="font-weight:500"><?= htmlspecialchars($u['username']) ?></span>
          <span class="badge-role"><?= htmlspecialchars($u['role']) ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
          <?php if ($u['sub_count'] > 0): ?>
            <span class="stat-badge badge-has">✓ <?= $u['sub_count'] ?> dispos.</span>
            <span style="font-size:10px;color:var(--mt,#5c5c78)">
              <?= $u['last_sub'] ? date('d/m H:i', strtotime($u['last_sub'])) : '' ?>
            </span>
          <?php else: ?>
            <span class="stat-badge badge-no">✗ Sem inscrição</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--bd,rgba(255,255,255,.07))">
        <div style="font-size:12px;color:var(--mt2,#8888a0);line-height:1.6">
          💡 <strong>Se parou de receber:</strong> entre no sistema pelo Safari/iPhone, clique em 
          "🔔 Ativar notificações" na sidebar e aguarde o cron rodar (a cada 30 min entre 10h–20h).
        </div>
      </div>
    </div>

  </div>

  <!-- ── CONFIGURAÇÃO DE HORÁRIO ── -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-title">⏰ Janela de Envio do Cron</div>

    <?php if ($settingsResult): ?>
    <div class="result <?= $settingsResult['ok'] ? 'ok' : 'err' ?>" style="margin-bottom:16px">
      <?= $settingsResult['ok'] ? '✅' : '❌' ?> <?= htmlspecialchars($settingsResult['msg']) ?>
    </div>
    <?php endif; ?>

    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;font-size:13px">
      <span>Agora são <strong><?= date('H:i') ?></strong> —</span>
      <?php if ($isInWindow): ?>
        <span style="color:var(--green);font-weight:600">✅ Dentro da janela de envio</span>
      <?php else: ?>
        <span style="color:var(--red);font-weight:600">⛔ Fora da janela de envio</span>
      <?php endif; ?>
    </div>

    <form method="POST" action="" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap"><?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="save_hours">
      <div>
        <label style="margin-top:0">De (hora início)</label>
        <select name="push_hour_start" style="width:120px">
          <?php for ($h = 0; $h <= 23; $h++): ?>
          <option value="<?= $h ?>" <?= $cfgStart === $h ? 'selected' : '' ?>><?= str_pad($h,2,'0',STR_PAD_LEFT) ?>:00</option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label style="margin-top:0">Até (hora fim)</label>
        <select name="push_hour_end" style="width:120px">
          <?php for ($h = 1; $h <= 24; $h++): ?>
          <option value="<?= $h ?>" <?= $cfgEnd === $h ? 'selected' : '' ?>><?= str_pad($h === 24 ? 0 : $h, 2,'0',STR_PAD_LEFT) ?>:00<?= $h === 24 ? ' (meia-noite)' : '' ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <button type="submit" style="padding:10px 20px;background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;border-radius:8px;color:#fff;font-family:'Syne',sans-serif;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap">
        💾 Salvar horário
      </button>
    </form>

    <div style="margin-top:12px;font-size:12px;color:var(--mt2,#8888a0)">
      O cron roda a cada 30 minutos e só envia notificações dentro desta janela.
    </div>
  </div>

  <!-- ── HISTÓRICO ── -->
  <?php if (!empty($history)): ?>
  <div class="card">
    <div class="card-title">🕐 Histórico de Envios (push_last_sent)</div>
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th>Usuário</th><th>Conta</th><th>Data</th>
            <th>Compras</th><th>Leads</th><th>Faturamento</th><th>Gasto</th><th>Enviado em</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): ?>
          <tr>
            <td><?= htmlspecialchars($h['username']) ?></td>
            <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($h['account_name']) ?></td>
            <td><?= date('d/m', strtotime($h['sent_date'])) ?></td>
            <td style="color:var(--green)"><?= $h['purchases'] ?: '—' ?></td>
            <td style="color:var(--accent2)"><?= $h['leads'] ?: '—' ?></td>
            <td>R$ <?= number_format($h['revenue'], 2, ',', '.') ?></td>
            <td>R$ <?= number_format($h['spend'],   2, ',', '.') ?></td>
            <td style="color:var(--mt2)"><?= date('d/m H:i', strtotime($h['sent_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</main>
</body>
</html>