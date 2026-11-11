<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/meta_api.php';

Auth::requireLogin();
if (Auth::getRole() !== 'admin') { header('Location: index.php'); exit; }

$db  = Database::getInstance();
$api = new MetaAPI();
$message = '';
$msgType = '';

// OAuth callback
if (isset($_GET['code'])) {
    $redirectUri = (isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'];
    $result = $api->exchangeCodeForToken($_GET['code'], $redirectUri);
    if (!empty($result['access_token'])) {
        $api->saveToken($result['access_token']);
        $message = '✅ Token salvo com sucesso! Token de longa duração (~60 dias) ativado.';
        $msgType = 'success';
    } else {
        $message = '❌ Erro ao obter token: '.($result['error']['message'] ?? 'desconhecido');
        $msgType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_notify_accounts'])) {
        $db->execute("DELETE FROM notify_accounts");
        foreach ($_POST['notify_accounts'] ?? [] as $id) {
            $db->execute("INSERT IGNORE INTO notify_accounts (account_id) VALUES (?)", [trim($id)]);
        }
        $message = '✅ Contas para notificação Telegram salvas!'; $msgType = 'success';
    }
    if (isset($_POST['save_creative_sync'])) {
        $db->execute("DELETE FROM creative_sync_accounts");
        foreach ($_POST['creative_sync_accounts'] ?? [] as $id) {
            $db->execute("INSERT IGNORE INTO creative_sync_accounts (account_id) VALUES (?)", [trim($id)]);
        }
        $message = '✅ Contas para sync de criativos salvas!'; $msgType = 'success';
    }
    if (isset($_POST['save_api_version'])) {
    if (isset($_POST['rodar_fx'])) {
        // roda o mesmo trabalho do cron, em bloco curto para não estourar o tempo
        ob_start();
        try { include __DIR__ . '/cron/sync_fx.php'; } catch (Exception $e) { echo 'erro: ' . $e->getMessage(); }
        $saidaFx = ob_get_clean();
        $message = '💱 ' . nl2br(htmlspecialchars(trim($saidaFx)));
        $msgType = strpos($saidaFx, 'rode de novo') !== false ? 'warning' : 'success';
    }

    if (isset($_POST['fx_mode'])) {
        $modo = $_POST['fx_mode'] === 'manual' ? 'manual' : 'auto';
        $db->execute("INSERT INTO system_settings (`key`,`value`) VALUES ('fx_mode',?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$modo]);
        foreach (['usd','eur'] as $mo) {
            $val = trim(str_replace(',', '.', $_POST['fx_fixed_' . $mo] ?? ''));
            $db->execute("INSERT INTO system_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
                ['fx_fixed_' . $mo, $val]);
        }
        $db->execute("DELETE FROM projection_cache");
        $message = '💱 Modo de cotação salvo (' . $modo . ').'; $msgType = 'success';
    }

        $v = trim($_POST['meta_api_version'] ?? '');
        if (preg_match('/^v\d+\.\d+$/', $v)) {
            $db->execute("INSERT INTO system_settings (`key`,`value`) VALUES ('meta_api_version',?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$v]);
            $message = '✅ Versão da Graph API atualizada para '.$v.' (vale para o próximo sync)'; $msgType = 'success';
        } else { $message = '❌ Formato inválido. Use ex.: v23.0'; $msgType = 'error'; }
    }
    if (isset($_POST['manual_token'])) {
        $token = trim($_POST['manual_token']);
        if ($token) {
            // converte para longa duração e checa a validade real na Meta
            $res = $api->saveTokenVerificado($token);
            $message = ($res['ok'] ? '✅ ' : '❌ ') . $res['msg'];
            $msgType = $res['ok'] ? (strpos($res['msg'], '⚠️') !== false ? 'warning' : 'success') : 'error';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_token'])) {
    $db->execute("DELETE FROM meta_token");
    $message = '🗑️ Token removido.'; $msgType = 'info';
}

$hasToken = $api->hasToken();
$allActiveAccounts = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE status='ACTIVE' ORDER BY account_name");
try { $db->execute("CREATE TABLE IF NOT EXISTS creative_sync_accounts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, account_id VARCHAR(50) NOT NULL COLLATE utf8mb4_unicode_ci, UNIQUE KEY uq_account (account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch(Exception $e) {}
$creativeSyncIds  = array_column($db->fetchAll("SELECT account_id FROM creative_sync_accounts") ?: [], 'account_id');
try { $notifyAccountIds = array_column($db->fetchAll("SELECT account_id FROM notify_accounts") ?: [], 'account_id'); } catch(Exception $e) { $notifyAccountIds = []; }
$tokenRow = $db->fetch("SELECT * FROM meta_token ORDER BY id DESC LIMIT 1");
$tokenStatus = null;
if ($hasToken) {
    try { $tokenStatus = $api->validadeReal(); } catch (Exception $e) {}
}
$syncLogs = $db->fetchAll("SELECT * FROM sync_log ORDER BY created_at DESC LIMIT 20");
try { $appErrors = $db->fetchAll("SELECT * FROM app_errors ORDER BY created_at DESC LIMIT 15"); } catch (Exception $e) { $appErrors = []; }
$apiVersionNow = metaApiVersion();
$tokenEncrypted = $tokenRow ? Crypto::isEncrypted($tokenRow['access_token']) : false;
$loginUrl = $api->getLoginUrl((isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Configurações — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
.page-title{font-size:22px;font-weight:700;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--mt2);margin-bottom:22px}

/* ALERT */
.alert{border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:9px}
.alert-success{background:rgba(62,207,142,.08);border:1px solid rgba(62,207,142,.2);color:var(--gn)}
.alert-error{background:rgba(240,100,90,.08);border:1px solid rgba(240,100,90,.2);color:var(--rd)}
.alert-info{background:rgba(84,112,255,.08);border:1px solid rgba(84,112,255,.2);color:var(--ac)}

/* CARDS */
.card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:22px;margin-bottom:18px}
.card-title{font-size:14px;font-weight:700;color:var(--tx);margin-bottom:18px}

/* TOKEN STATUS */
.token-status{display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--s2);border-radius:10px;margin-bottom:16px;flex-wrap:wrap}
.token-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.token-dot.active{background:var(--gn);box-shadow:0 0 7px var(--gn)}
.token-dot.inactive{background:var(--rd)}
.token-info{flex:1;min-width:0}
.token-label{font-size:13px;font-weight:600;color:var(--tx)}
.token-sub{font-size:11px;color:var(--mt2);margin-top:2px;word-break:break-all}

/* STEPS */
.step{display:flex;gap:14px;align-items:flex-start;padding:14px 0;border-bottom:1px solid var(--bd)}
.step:last-child{border-bottom:none}
.step-num{width:27px;height:27px;border-radius:50%;background:var(--ac);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;margin-top:1px}
.step-num.alpha{background:rgba(84,112,255,.15);color:var(--ac);border:1px solid rgba(84,112,255,.3)}
.step-title{font-size:14px;font-weight:600;color:var(--tx);margin-bottom:4px}
.step-desc{font-size:13px;color:var(--mt2);line-height:1.65}
code{background:var(--s2);border:1px solid var(--bd);padding:2px 7px;border-radius:5px;font-family:'JetBrains Mono',monospace;font-size:12px}
.code-block{display:block;padding:12px 14px;background:var(--s2);border:1px solid var(--bd);border-radius:9px;font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.7;margin-top:10px;word-break:break-all;color:var(--mt2)}

/* BOTÕES */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:8px;font-family:'Inter',sans-serif;font-weight:600;font-size:13px;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-p{background:var(--ac);color:#fff}.btn-p:hover{opacity:.85}
.btn-d{background:rgba(240,100,90,.1);border:1px solid rgba(240,100,90,.2);color:var(--rd)}.btn-d:hover{background:rgba(240,100,90,.2)}
.btn-o{background:var(--s2);border:1px solid var(--bd);color:var(--mt2)}.btn-o:hover{border-color:var(--ac);color:var(--ac)}

/* FORM */
.fg{margin-bottom:13px}
.fg label{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--mt);margin-bottom:5px}
.fg input[type=text],.fg input[type=password]{width:100%;padding:9px 13px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-family:'Inter',sans-serif;font-size:13px;outline:none;transition:border-color .2s}
.fg input:focus{border-color:var(--ac)}

/* CHECKBOXES */
.check-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:7px;margin-bottom:14px}
.check-item{display:flex;align-items:center;gap:10px;padding:9px 13px;background:var(--s2);border:1px solid var(--bd);border-radius:9px;cursor:pointer;transition:border-color .15s}
.check-item:hover{border-color:rgba(84,112,255,.3)}
.check-item.checked{border-color:rgba(84,112,255,.4);background:rgba(84,112,255,.05)}
.check-item input{width:15px;height:15px;accent-color:var(--ac);cursor:pointer;flex-shrink:0}
.check-item span{font-size:13px;font-weight:500;color:var(--tx)}
.count-note{font-size:12px;color:var(--mt2)}

/* DIVIDER */
.divider{border:none;border-top:1px solid var(--bd);margin:16px 0}

/* LOG TABLE */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:500px}
thead th{padding:9px 14px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--mt);background:var(--s2);border-bottom:1px solid var(--bd)}
tbody td{padding:10px 14px;font-size:12px;border-bottom:1px solid var(--bd)}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:rgba(255,255,255,.02)}
.badge{display:inline-flex;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:600}
.badge-success{background:rgba(62,207,142,.1);color:var(--gn)}
.badge-error{background:rgba(240,100,90,.1);color:var(--rd)}

@media(max-width:768px){
  .check-grid{grid-template-columns:1fr}
  .token-status{gap:8px}
  .step{flex-direction:column;gap:10px}
  .code-block{font-size:11px}
}
</style>
</head>
<body>
<?php $currentPage='settings'; $sidebarBase=''; require __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
  <h1 class="page-title">Configurações</h1>
  <p class="page-sub">Gerencie o token da API e sincronizações</p>

  <?php if ($message): ?>
  <div class="alert alert-<?= $msgType ?>"><?= $message ?></div>
  <?php endif; ?>

  <!-- TOKEN -->
  <div class="card">
    <div class="card-title">🔑 Token de Acesso Meta</div>
    <div class="token-status">
      <div class="token-dot <?= $hasToken ? 'active' : 'inactive' ?>"></div>
      <div class="token-info">
        <div class="token-label"><?= $hasToken ? 'Token ativo' : 'Token não configurado' ?></div>
        <div class="token-sub">
          <?php if ($tokenRow): ?>
            Atualizado: <?= date('d/m/Y H:i', strtotime($tokenRow['updated_at'])) ?>
            <?php
              // validade real consultada na Meta (a data guardada podia estar otimista)
              if ($tokenStatus) {
                  if (empty($tokenStatus['valido'])) {
                      echo ' · <span style="color:#f87171;font-weight:700">❌ EXPIRADO'
                         . ($tokenStatus['erro'] ? ': ' . htmlspecialchars(mb_substr($tokenStatus['erro'], 0, 90)) : '')
                         . '</span>';
                  } elseif ((int)$tokenStatus['expires_at'] === 0) {
                      echo ' · <span style="color:var(--gn)">sem expiração (usuário do sistema)</span>';
                  } else {
                      $h = ((int)$tokenStatus['expires_at'] - time()) / 3600;
                      $cor = $h < 48 ? '#f87171' : ($h < 24 * 10 ? 'var(--yw)' : 'var(--gn)');
                      echo ' · <span style="color:' . $cor . ';font-weight:600">expira em '
                         . ($h >= 48 ? round($h / 24) . ' dia(s)' : round($h) . ' hora(s)')
                         . '</span> (' . date('d/m/Y H:i', (int)$tokenStatus['expires_at']) . ')';
                      if ($h < 48) echo '<br><span style="color:var(--yw);font-size:11px">⚠️ Token de curta duração — gere um de longa duração ou use um usuário do sistema para não parar sozinho.</span>';
                  }
              } elseif ($tokenRow['expires_at']) {
                  echo ' · Expira: ' . date('d/m/Y', strtotime($tokenRow['expires_at']));
              }
            ?>
          <?php else: ?>
            Configure abaixo para ativar a integração
          <?php endif; ?>
        </div>
      </div>
      <?php if ($hasToken): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Remover token?')"><?= Auth::csrfField() ?><input type="hidden" name="clear_token" value="1"><button type="submit" class="btn btn-d">🗑️ Remover</button></form>
      <?php endif; ?>
    </div>

    <hr class="divider">

    <div class="step">
      <div class="step-num alpha">A</div>
      <div class="step-content" style="flex:1">
        <div class="step-title">Login com Facebook (recomendado)</div>
        <div class="step-desc">Autorize o sistema. Token de longa duração (~60 dias) gerado automaticamente.</div>
        <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn btn-p" style="margin-top:12px">🔵 Conectar com Facebook</a>
      </div>
    </div>

    <div class="step">
      <div class="step-num alpha">B</div>
      <div class="step-content" style="flex:1">
        <div class="step-title">Inserir token manualmente</div>
        <div class="step-desc">Obtenha em <a href="https://developers.facebook.com/tools/explorer/" target="_blank" style="color:var(--ac)">Meta Graph Explorer</a> com permissões <code>ads_read</code>, <code>ads_management</code>.</div>
        <form method="POST" action="" style="margin-top:12px"><?= Auth::csrfField() ?>
          <div class="fg">
            <input type="password" name="manual_token" placeholder="EAAxxxxxxx...">
          </div>
          <button type="submit" class="btn btn-o">💾 Salvar token</button>
        </form>
      </div>
    </div>
  </div>

  <!-- CRON -->
  <div class="card">
    <div class="card-title">⏰ Configuração do Cron Job</div>

    <div class="step">
      <div class="step-num">1</div>
      <div class="step-content" style="flex:1">
        <div class="step-title">Sincronização automática (a cada 30 min)</div>
        <div class="step-desc">No hPanel → Cron Jobs, adicione:</div>
        <code class="code-block">*/30 * * * *   /usr/local/bin/php <?= realpath(__DIR__) ?>/cron/sync.php</code>
      </div>
    </div>

    <div class="step">
      <div class="step-num">2</div>
      <div class="step-content" style="flex:1">
        <div class="step-title">Sincronização de criativos (2x/dia)</div>
        <code class="code-block">0 6,18 * * *   /usr/local/bin/php <?= realpath(__DIR__) ?>/cron/sync_creatives.php</code>
      </div>
    </div>

    <div class="step">
      <div class="step-num">3</div>
      <div class="step-content" style="flex:1">
        <div class="step-title">Ou sincronize manualmente agora</div>
        <button type="button" class="btn btn-p" onclick="runSync(this)">🔄 Sincronizar tudo agora</button>
        <span id="syncResult" style="margin-left:12px;font-size:13px;color:var(--mt2)"></span>
      </div>
    </div>
  </div>

  <!-- API / SEGURANÇA -->
  <!-- MOEDAS -->
  <div class="card">
    <div class="card-title">💱 Moedas e cotação</div>
    <?php
      $contasMoeda = [];
      try {
        $contasMoeda = $db->fetchAll("SELECT account_name, account_id, currency FROM ad_accounts
                                      WHERE currency IS NOT NULL AND currency <> 'BRL' ORDER BY account_name");
      } catch (Exception $e) {}
      $fxModo = 'auto'; $fxUsd = ''; $fxEur = '';
      try {
        foreach ($db->fetchAll("SELECT `key`,`value` FROM system_settings WHERE `key` LIKE 'fx_%'") as $r) {
          if ($r['key'] === 'fx_mode') $fxModo = $r['value'];
          if ($r['key'] === 'fx_fixed_usd') $fxUsd = $r['value'];
          if ($r['key'] === 'fx_fixed_eur') $fxEur = $r['value'];
        }
      } catch (Exception $e) {}
      $ultimasFx = [];
      try {
        $ultimasFx = $db->fetchAll("SELECT currency, rate, rate_date FROM fx_rates
                                    WHERE (currency, rate_date) IN
                                    (SELECT currency, MAX(rate_date) FROM fx_rates GROUP BY currency)");
      } catch (Exception $e) {}
      $pendentes = 0;
      try {
        $p1 = $db->fetch("SELECT COUNT(*) c FROM campaign_snapshots s
                          JOIN ad_accounts a ON a.account_id = s.account_id AND a.currency <> 'BRL'
                          WHERE s.spend_original IS NULL AND s.spend > 0");
        $pendentes = (int)($p1['c'] ?? 0);
      } catch (Exception $e) {}
    ?>

    <div style="font-size:12.5px;color:var(--mt);line-height:1.6;margin-bottom:14px">
      Contas em moeda estrangeira têm gasto, receita e orçamento convertidos para reais
      no momento da gravação, usando a cotação do dia do gasto. O valor original fica guardado.
    </div>

    <?php if ($contasMoeda): ?>
    <div style="margin-bottom:14px">
      <div style="font-size:11px;color:var(--mt);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">Contas em outra moeda</div>
      <?php foreach ($contasMoeda as $cm): ?>
      <div style="display:flex;justify-content:space-between;font-size:13px;padding:5px 0;border-bottom:1px solid var(--bd)">
        <span><?= htmlspecialchars($cm['account_name']) ?></span>
        <span style="font-weight:700;color:var(--yw)"><?= htmlspecialchars($cm['currency']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="font-size:13px;color:var(--mt);padding:10px 0">
      Nenhuma conta em moeda estrangeira detectada ainda.
      Clique em <b>Atualizar moedas e converter</b> para o sistema ler isso da Meta.
    </div>
    <?php endif; ?>

    <?php if ($ultimasFx): ?>
    <div style="font-size:12px;color:var(--mt);margin-bottom:12px">
      Últimas cotações:
      <?= implode(' · ', array_map(function($f){
            return $f['currency'] . ' ' . number_format((float)$f['rate'], 4, ',', '.')
                 . ' (' . date('d/m', strtotime($f['rate_date'])) . ')';
          }, $ultimasFx)) ?>
    </div>
    <?php endif; ?>

    <?php if ($pendentes > 0): ?>
    <div style="padding:10px 13px;border-radius:9px;background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3);
                color:var(--yw);font-size:12.5px;margin-bottom:12px">
      ⚠️ <?= number_format($pendentes, 0, ',', '.') ?> registro(s) ainda em moeda original.
      Rode a conversão até a mensagem dizer "histórico completo".
    </div>
    <?php endif; ?>

    <form method="POST" style="margin-bottom:16px">
      <?= Auth::csrfField() ?>
      <button type="submit" name="rodar_fx" value="1" class="btn btn-p">💱 Atualizar moedas e converter</button>
      <span style="font-size:11.5px;color:var(--mt);margin-left:8px">trabalha em blocos — pode precisar de alguns cliques</span>
    </form>

    <form method="POST" style="border-top:1px solid var(--bd);padding-top:14px">
      <?= Auth::csrfField() ?>
      <div style="font-size:11px;color:var(--mt);text-transform:uppercase;letter-spacing:.05em;margin-bottom:7px">Como obter a cotação</div>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:6px;cursor:pointer">
        <input type="radio" name="fx_mode" value="auto" <?= $fxModo !== 'manual' ? 'checked' : '' ?> style="width:auto">
        Automática — cotação do dia do gasto (recomendado)
      </label>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:10px;cursor:pointer">
        <input type="radio" name="fx_mode" value="manual" <?= $fxModo === 'manual' ? 'checked' : '' ?> style="width:auto">
        Fixa — eu informo o valor
      </label>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px">
        <div><label style="font-size:11px;color:var(--mt);display:block">USD fixo</label>
          <input type="text" name="fx_fixed_usd" value="<?= htmlspecialchars($fxUsd) ?>" placeholder="5,40" style="width:110px"></div>
        <div><label style="font-size:11px;color:var(--mt);display:block">EUR fixo</label>
          <input type="text" name="fx_fixed_eur" value="<?= htmlspecialchars($fxEur) ?>" placeholder="5,90" style="width:110px"></div>
      </div>
      <button type="submit" class="btn btn-o btn-sm">Salvar modo de cotação</button>
    </form>
  </div>

  <div class="card">
    <div class="card-title">🔧 Graph API &amp; Segurança</div>
    <form method="POST" action="" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap"><?= Auth::csrfField() ?>
      <div>
        <label style="font-size:11px;color:var(--mt2);display:block;margin-bottom:4px">Versão da Graph API (atual: <b><?= htmlspecialchars($apiVersionNow) ?></b>)</label>
        <input type="text" name="meta_api_version" value="<?= htmlspecialchars($apiVersionNow) ?>" placeholder="v23.0" style="width:120px">
      </div>
      <button type="submit" name="save_api_version" value="1" class="btn btn-p">Salvar</button>
      <span style="font-size:12px;color:var(--mt2)">A Meta descontinua cada versão ~2 anos após o lançamento. Confira em developers.facebook.com/docs/graph-api/changelog</span>
    </form>
    <div style="margin-top:14px;font-size:12px;color:var(--mt2);display:flex;gap:18px;flex-wrap:wrap">
      <span>Token em repouso: <?= $tokenRow ? ($tokenEncrypted ? '<span class="badge badge-success">cifrado</span>' : '<span class="badge badge-error">texto puro</span> — rode <code>tools/migrate_encrypt_token.php</code>') : '—' ?></span>
      <span>Chave de cifragem: <?= Crypto::enabled() ? '<span class="badge badge-success">ok</span>' : '<span class="badge badge-error">ausente (config/.enc_key)</span>' ?></span>
      <span>CSRF: <span class="badge badge-success">ativo</span></span>
    </div>
  </div>

  <!-- ERROS -->
  <?php if (!empty($appErrors)): ?>
  <div class="card">
    <div class="card-title">🚨 Erros recentes da aplicação (app_errors)</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Data</th><th>Nível</th><th>Script</th><th>Mensagem</th></tr></thead>
        <tbody>
          <?php foreach ($appErrors as $er): ?>
          <tr>
            <td style="color:var(--mt2);white-space:nowrap"><?= date('d/m H:i', strtotime($er['created_at'])) ?></td>
            <td><span class="badge badge-<?= $er['level']==='error'?'error':'success' ?>"><?= htmlspecialchars($er['level']) ?></span></td>
            <td style="color:var(--mt2);font-size:11px"><?= htmlspecialchars(basename((string)$er['script'])) ?></td>
            <td style="color:var(--mt2);font-size:12px"><?= htmlspecialchars(mb_substr($er['message'],0,180)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- LOG -->
  <?php if (!empty($syncLogs)): ?>
  <div class="card">
    <div class="card-title">📋 Log de Sincronizações</div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Data</th><th>Tipo</th><th>Status</th><th>Contas</th><th>Mensagem</th></tr>
        </thead>
        <tbody>
          <?php foreach ($syncLogs as $log): ?>
          <tr>
            <td style="color:var(--mt2);white-space:nowrap"><?= date('d/m H:i', strtotime($log['created_at'])) ?></td>
            <td><?= htmlspecialchars($log['sync_type']) ?></td>
            <td><span class="badge badge-<?= $log['status']==='success'?'success':'error' ?>"><?= $log['status'] ?></span></td>
            <td style="font-family:'JetBrains Mono',monospace"><?= $log['accounts_synced'] ?></td>
            <td style="color:var(--mt2)"><?= htmlspecialchars($log['message'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- CRIATIVOS -->
  <div class="card">
    <div class="card-title">🎨 Contas para Sync de Criativos</div>
    <p style="font-size:13px;color:var(--mt2);margin-bottom:16px">Selecione quais contas terão criativos sincronizados automaticamente.</p>
    <form method="POST"><?= Auth::csrfField() ?>
      <input type="hidden" name="save_creative_sync" value="1">
      <div class="check-grid">
        <?php foreach ($allActiveAccounts as $acc): ?>
        <?php $ck = in_array($acc['account_id'], $creativeSyncIds); ?>
        <label class="check-item <?= $ck ? 'checked' : '' ?>">
          <input type="checkbox" name="creative_sync_accounts[]" value="<?= htmlspecialchars($acc['account_id']) ?>" <?= $ck?'checked':'' ?> onchange="this.closest('.check-item').classList.toggle('checked',this.checked)">
          <span><?= htmlspecialchars($acc['account_name']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;align-items:center;gap:12px">
        <button type="submit" class="btn btn-p">💾 Salvar seleção</button>
        <span class="count-note"><?= count($creativeSyncIds) ?> conta(s) selecionada(s)</span>
      </div>
    </form>
  </div>

  <!-- TELEGRAM -->
  <div class="card">
    <div class="card-title">📲 Contas para Notificação Telegram</div>
    <p style="font-size:13px;color:var(--mt2);margin-bottom:16px">Selecione quais contas geram alertas no Telegram a cada sync. Deixe vazio para não enviar.</p>
    <form method="POST"><?= Auth::csrfField() ?>
      <input type="hidden" name="save_notify_accounts" value="1">
      <div class="check-grid">
        <?php foreach ($allActiveAccounts as $acc): ?>
        <?php $ck = in_array($acc['account_id'], $notifyAccountIds); ?>
        <label class="check-item <?= $ck ? 'checked' : '' ?>">
          <input type="checkbox" name="notify_accounts[]" value="<?= htmlspecialchars($acc['account_id']) ?>" <?= $ck?'checked':'' ?> onchange="this.closest('.check-item').classList.toggle('checked',this.checked)">
          <span><?= htmlspecialchars($acc['account_name']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;align-items:center;gap:12px">
        <button type="submit" class="btn btn-p">💾 Salvar seleção</button>
        <span class="count-note"><?= count($notifyAccountIds) ?> conta(s) selecionada(s)</span>
      </div>
    </form>
  </div>

</main>

<script>
async function runSync(btn) {
  btn.disabled = true;
  btn.textContent = '⏳ Sincronizando...';
  var res = document.getElementById('syncResult');
  res.textContent = '';
  try {
    var r = await fetch('api/sync.php', {method:'POST'});
    var d = await r.json();
    res.textContent = d.success ? '✅ '+d.message : '❌ '+(d.message||'Erro');
    if (d.success) setTimeout(function(){ location.reload(); }, 2000);
  } catch(e) {
    res.textContent = '❌ Erro de conexão';
  }
  btn.disabled = false;
  btn.textContent = '🔄 Sincronizar tudo agora';
}
</script>
</body>
</html>