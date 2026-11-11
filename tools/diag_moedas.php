<?php
// tools/diag_moedas.php — conferência de moeda por conta / evento / campanha.
// Mostra o que está cadastrado na conta, o que está gravado nos snapshots e
// aponta divergência (gasto convertido com orçamento não convertido e vice-versa).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/fx.php';

Auth::requireAdmin();

$db = Database::getInstance();
$eventoFiltro = isset($_GET['event']) ? (int)$_GET['event'] : 0;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Contas ───────────────────────────────────────────────────
$contas = $db->fetchAll(
    "SELECT a.account_id, a.account_name, a.currency,
            (SELECT COUNT(*) FROM campaign_snapshots s
              WHERE s.account_id = a.account_id) AS snaps,
            (SELECT COUNT(*) FROM campaign_snapshots s
              WHERE s.account_id = a.account_id AND s.spend_original IS NULL AND s.spend > 0) AS nao_convertidos,
            (SELECT MAX(s.currency) FROM campaign_snapshots s
              WHERE s.account_id = a.account_id AND s.currency IS NOT NULL AND s.currency <> '') AS moeda_snap
     FROM ad_accounts a ORDER BY a.account_name");

// ── Campanhas do evento ──────────────────────────────────────
$camps = [];
if ($eventoFiltro) {
    $camps = $db->fetchAll(
        "SELECT ec.campaign_id, ec.campaign_name,
                MAX(cs.account_id)  AS conta,
                MAX(cs.currency)    AS moeda_snap,
                MAX(cs.fx_rate)     AS taxa_snap,
                MAX(a.currency)     AS moeda_conta,
                ROUND(SUM(cs.spend),2)          AS gasto_brl,
                ROUND(SUM(cs.spend_original),2) AS gasto_orig
         FROM event_campaigns ec
         LEFT JOIN campaign_snapshots cs ON cs.campaign_id = ec.campaign_id
         LEFT JOIN ad_accounts a ON a.account_id = cs.account_id
         WHERE ec.event_id = ?
         GROUP BY ec.campaign_id, ec.campaign_name
         ORDER BY ec.campaign_name", [$eventoFiltro]);
}

$eventos = $db->fetchAll("SELECT id, name FROM events ORDER BY date_event DESC LIMIT 100");
?>
<!doctype html><html lang="pt-BR"><meta charset="utf-8">
<title>Diagnóstico de moedas — MetaDash</title>
<style>
 body{font:14px/1.5 system-ui,sans-serif;margin:24px;max-width:1100px;color:#222}
 h1{font-size:20px} h2{font-size:16px;margin-top:28px}
 table{border-collapse:collapse;width:100%;margin-top:10px;font-size:13px}
 th,td{border:1px solid #ddd;padding:6px 8px;text-align:left}
 th{background:#f4f4f5}
 .ok{color:#15803d;font-weight:600}
 .bad{color:#b91c1c;font-weight:600}
 .warn{color:#b45309;font-weight:600}
 code{background:#f4f4f5;padding:1px 4px;border-radius:3px}
 p.nota{color:#555;font-size:13px}
</style>
<h1>Diagnóstico de moedas</h1>
<p class="nota">O gasto é gravado sempre em reais. Se a conta está em dólar e a coluna
<strong>moeda nos snapshots</strong> aparece vazia, o gasto está sendo exibido como real sem ter sido
convertido — rode <code>cron/sync_fx.php</code> até o resumo dizer “histórico completo”.</p>

<h2>Contas de anúncio</h2>
<table>
<tr><th>Conta</th><th>Moeda cadastrada</th><th>Moeda nos snapshots</th><th>Snapshots</th><th>Falta converter</th><th>Situação</th></tr>
<?php foreach ($contas as $c):
    $cad  = strtoupper((string)$c['currency']);
    $snap = strtoupper((string)$c['moeda_snap']);
    $falta= (int)$c['nao_convertidos'];
    if ($cad === '' || $cad === 'BRL') {
        if ($snap !== '' && $snap !== 'BRL') { $cls='bad'; $txt='Conta marcada como BRL mas os dados estão em '.$snap.' — recadastre a moeda'; }
        else { $cls='ok'; $txt='BRL, nada a converter'; }
    } else {
        if ($falta > 0) { $cls='bad'; $txt="Conta em {$cad} com {$falta} registro(s) ainda em {$cad} — rode o sync_fx"; }
        elseif ($snap === '' ) { $cls='warn'; $txt='Conta em '.$cad.' mas nenhum snapshot convertido ainda'; }
        else { $cls='ok'; $txt='Convertida de '.$cad.' para BRL'; }
    }
?>
<tr>
  <td><?= h($c['account_name']) ?><br><small><code><?= h($c['account_id']) ?></code></small></td>
  <td><?= $cad === '' ? '<span class="warn">(vazia)</span>' : h($cad) ?></td>
  <td><?= $snap === '' ? '—' : h($snap) ?></td>
  <td><?= (int)$c['snaps'] ?></td>
  <td><?= $falta ? '<span class="bad">'.$falta.'</span>' : '0' ?></td>
  <td class="<?= $cls ?>"><?= h($txt) ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>Campanhas de um evento</h2>
<form method="get">
  <select name="event" onchange="this.form.submit()">
    <option value="">— escolha o evento —</option>
    <?php foreach ($eventos as $e): ?>
      <option value="<?= (int)$e['id'] ?>" <?= $eventoFiltro === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['name']) ?></option>
    <?php endforeach; ?>
  </select>
</form>
<?php if ($eventoFiltro): ?>
<?php
  $moedasEvento = [];
  foreach ($camps as $cp) {
      $m = strtoupper((string)($cp['moeda_snap'] ?: $cp['moeda_conta'] ?: 'BRL'));
      $moedasEvento[$m === '' ? 'BRL' : $m] = true;
  }
?>
<p class="nota">Moedas neste evento: <strong><?= h(implode(', ', array_keys($moedasEvento))) ?></strong>
<?= count($moedasEvento) > 1 ? ' — evento com contas em moedas diferentes. Cada campanha é convertida pela cotação da conta dela.' : '' ?></p>
<table>
<tr><th>Campanha</th><th>Conta</th><th>Moeda (snapshot)</th><th>Moeda (conta)</th><th>Cotação gravada</th><th>Gasto em BRL</th><th>Gasto original</th></tr>
<?php foreach ($camps as $cp):
    $ms = strtoupper((string)$cp['moeda_snap']); $mc = strtoupper((string)$cp['moeda_conta']);
    $div = ($ms !== '' && $mc !== '' && $ms !== $mc);
?>
<tr>
  <td><?= h($cp['campaign_name']) ?></td>
  <td><small><code><?= h($cp['conta'] ?: '—') ?></code></small></td>
  <td class="<?= $div ? 'bad' : '' ?>"><?= $ms === '' ? '—' : h($ms) ?></td>
  <td class="<?= $div ? 'bad' : '' ?>"><?= $mc === '' ? '<span class="warn">(vazia)</span>' : h($mc) ?></td>
  <td><?= $cp['taxa_snap'] ? number_format((float)$cp['taxa_snap'], 4, ',', '.') : '—' ?></td>
  <td>R$ <?= number_format((float)$cp['gasto_brl'], 2, ',', '.') ?></td>
  <td><?= $cp['gasto_orig'] !== null ? h($ms ?: '') . ' ' . number_format((float)$cp['gasto_orig'], 2, ',', '.') : '—' ?></td>
</tr>
<?php endforeach; ?>
</table>
<p class="nota">Divergência entre as duas colunas de moeda (em vermelho) significa que a conta foi
recadastrada depois da conversão. Nesse caso o valor correto é o do snapshot.</p>
<?php endif; ?>
</html>
