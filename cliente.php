<?php
// cliente.php — detalhe de um negócio local.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();
$db      = Database::getInstance();
$uid     = Auth::getUserId();
$isAdmin = Auth::isAdmin();

$id = (int)($_GET['id'] ?? 0);
$cliente = $db->fetch("SELECT * FROM local_clients WHERE id=?", [$id]);
if (!$cliente) { header('Location: clientes.php'); exit; }

if (!$isAdmin) {
    $ok = $db->fetch("SELECT 1 FROM local_client_access WHERE client_id=? AND user_id=?", [$id, $uid]);
    if (!$ok) { header('Location: clientes.php'); exit; }
}

$contas = array_column($db->fetchAll("SELECT account_id FROM local_client_accounts WHERE client_id=?", [$id]), 'account_id');

// ── período ───────────────────────────────────────────────────
$periodos = ['7' => '7 dias', '15' => '15 dias', '30' => '30 dias', 'mes' => 'Este mês'];
$per = $_GET['p'] ?? 'mes';
if (!isset($periodos[$per])) $per = 'mes';
if ($per === 'mes') { $de = date('Y-m-01'); } else { $de = date('Y-m-d', strtotime('-' . ((int)$per - 1) . ' days')); }
$ate = date('Y-m-d');
$dias = (int)max(1, floor((strtotime($ate) - strtotime($de)) / 86400) + 1);
$deAnt  = date('Y-m-d', strtotime($de . ' -' . $dias . ' days'));
$ateAnt = date('Y-m-d', strtotime($de . ' -1 day'));

$vazio = ['spend'=>0,'conversations'=>0,'reach'=>0,'impressions'=>0,'post_engagement'=>0,
          'page_likes'=>0,'follows'=>0,'link_clicks'=>0,'first_replies'=>0,'saves'=>0,'video_views'=>0];

function soma($db, $contas, $de, $ate, $vazio) {
    if (!$contas) return $vazio;
    $ph = implode(',', array_fill(0, count($contas), '?'));
    try {
        $r = $db->fetch("
            SELECT COALESCE(SUM(spend),0) spend, COALESCE(SUM(conversations),0) conversations,
                   COALESCE(SUM(reach),0) reach, COALESCE(SUM(impressions),0) impressions,
                   COALESCE(SUM(post_engagement),0) post_engagement,
                   COALESCE(SUM(page_likes),0) page_likes, COALESCE(SUM(follows),0) follows,
                   COALESCE(SUM(link_clicks),0) link_clicks,
                   COALESCE(SUM(first_replies),0) first_replies,
                   COALESCE(SUM(saves),0) saves, COALESCE(SUM(video_views),0) video_views
            FROM local_snapshots
            WHERE account_id IN ($ph) AND snapshot_date BETWEEN ? AND ?",
            array_merge($contas, [$de, $ate]));
        return $r ?: $vazio;
    } catch (Exception $e) { return $vazio; }
}

$t  = soma($db, $contas, $de, $ate, $vazio);
$ta = soma($db, $contas, $deAnt, $ateAnt, $vazio);

$diario = [];
if ($contas) {
    $ph = implode(',', array_fill(0, count($contas), '?'));
    try {
        $diario = $db->fetchAll("
            SELECT snapshot_date, SUM(spend) spend, SUM(conversations) conversations,
                   SUM(reach) reach, SUM(impressions) impressions, SUM(post_engagement) engaj,
                   SUM(follows)+SUM(page_likes) seguidores
            FROM local_snapshots
            WHERE account_id IN ($ph) AND snapshot_date BETWEEN ? AND ?
            GROUP BY snapshot_date ORDER BY snapshot_date ASC",
            array_merge($contas, [$de, $ate]));
    } catch (Exception $e) {}
}

$custoConv    = $t['conversations'] > 0 ? $t['spend'] / $t['conversations'] : 0;
$custoConvAnt = $ta['conversations'] > 0 ? $ta['spend'] / $ta['conversations'] : 0;
$freq         = $t['reach'] > 0 ? $t['impressions'] / $t['reach'] : 0;
$cpm          = $t['impressions'] > 0 ? $t['spend'] / $t['impressions'] * 1000 : 0;
$segTotal     = $t['follows'] + $t['page_likes'];
$segAnt       = $ta['follows'] + $ta['page_likes'];

$brl = function ($v, $d = 2) { return 'R$ ' . number_format((float)$v, $d, ',', '.'); };
$num = function ($v) { return number_format((float)$v, 0, ',', '.'); };
$delta = function ($a, $b, $menorMelhor = false) {
    $b = (float)$b;
    if (abs($b) < 0.0001) return '';
    $p = ($a - $b) / $b * 100;
    $bom = $menorMelhor ? $p < -2 : $p > 2;
    $ruim = $menorMelhor ? $p > 2 : $p < -2;
    $cor = $bom ? 'var(--gn,#34d399)' : ($ruim ? '#f87171' : 'var(--mt)');
    return '<span style="font-size:11.5px;font-weight:600;color:' . $cor . '">'
         . ($p >= 0 ? '▲ +' : '▼ ') . number_format(abs($p), 0, ',', '.') . '%</span>';
};

// melhor e pior dia por custo por conversa
$melhorDia = null; $piorDia = null;
foreach ($diario as $d) {
    $convDia = (int)$d['conversations'];
    if ($convDia < 1 || (float)$d['spend'] <= 0) continue;
    $cc = (float)$d['spend'] / $convDia;
    if ($melhorDia === null || $cc < $melhorDia['cc']) $melhorDia = ['d' => $d, 'cc' => $cc];
    if ($piorDia   === null || $cc > $piorDia['cc'])   $piorDia   = ['d' => $d, 'cc' => $cc];
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($cliente['name']) ?> — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
.kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:13px; margin-bottom:20px }
.kpi { background:var(--sf); border:1px solid var(--bd); border-radius:13px; padding:14px 16px }
.kpi .l { font-size:10px; color:var(--mt); text-transform:uppercase; letter-spacing:.05em }
.kpi .v { font-size:22px; font-weight:800; margin-top:3px; line-height:1.1 }
.kpi .s { font-size:11px; color:var(--mt); margin-top:3px }
.tabela { width:100%; border-collapse:collapse; font-size:12.5px }
.tabela th { text-align:left; font-size:10px; color:var(--mt); text-transform:uppercase; letter-spacing:.05em; padding:7px 9px; border-bottom:1px solid var(--bd) }
.tabela td { padding:8px 9px; border-bottom:1px solid var(--bd) }
.tabela td.n { text-align:right; font-variant-numeric:tabular-nums }
.pbtn { padding:6px 13px; border-radius:8px; font-size:12.5px; font-weight:600; text-decoration:none; border:1px solid var(--bd); color:var(--mt2) }
.pbtn.on { background:var(--ac); border-color:var(--ac); color:#fff }
</style>
</head>
<body>
<div class="app-wrap">
<?php $currentPage = 'clientes'; require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <a href="clientes.php" style="font-size:12.5px;color:var(--mt);text-decoration:none">← Voltar aos clientes</a>

  <div style="display:flex;align-items:center;gap:12px;margin:12px 0 4px">
    <span style="font-size:28px"><?= htmlspecialchars($cliente['icon'] ?: '🏪') ?></span>
    <div>
      <h1 class="page-title" style="margin:0"><?= htmlspecialchars($cliente['name']) ?></h1>
      <p class="page-sub" style="margin:2px 0 0">
        <?= htmlspecialchars($cliente['segment'] ?: 'negócio local') ?>
        <?php if ($cliente['whatsapp']): ?> · 📱 <?= htmlspecialchars($cliente['whatsapp']) ?><?php endif; ?>
        <?php if ($cliente['monthly_fee']): ?> · fee <?= $brl($cliente['monthly_fee']) ?>/mês<?php endif; ?>
      </p>
    </div>
  </div>

  <div style="display:flex;gap:7px;flex-wrap:wrap;margin:16px 0 18px">
    <?php foreach ($periodos as $k => $lbl): ?>
    <a href="?id=<?= $id ?>&p=<?= $k ?>" class="pbtn <?= $per === $k ? 'on' : '' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
    <span style="flex:1"></span>
    <span style="font-size:11.5px;color:var(--mt);align-self:center">
      <?= date('d/m', strtotime($de)) ?> a <?= date('d/m', strtotime($ate)) ?> · comparado com os <?= (int)$dias ?> dias anteriores
    </span>
  </div>

  <?php if (!$contas): ?>
  <div style="padding:14px 16px;border-radius:12px;background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.3);color:#f87171;font-size:13.5px">
    Este cliente ainda não tem conta de anúncios vinculada. Edite-o na lista de clientes para vincular.
  </div>
  <?php else: ?>

  <div class="kpis">
    <div class="kpi">
      <div class="l">💬 Conversas iniciadas</div>
      <div class="v"><?= $num($t['conversations']) ?></div>
      <div class="s"><?= $delta($t['conversations'], $ta['conversations']) ?> · antes <?= $num($ta['conversations']) ?></div>
    </div>
    <div class="kpi">
      <div class="l">Custo por conversa</div>
      <div class="v"><?= $t['conversations'] > 0 ? $brl($custoConv) : '—' ?></div>
      <div class="s"><?= $custoConvAnt > 0 ? $delta($custoConv, $custoConvAnt, true) . ' · antes ' . $brl($custoConvAnt) : 'sem base anterior' ?></div>
    </div>
    <div class="kpi">
      <div class="l">Investido</div>
      <div class="v"><?= $brl($t['spend']) ?></div>
      <div class="s"><?= $delta($t['spend'], $ta['spend']) ?></div>
    </div>
    <div class="kpi">
      <div class="l">Alcance</div>
      <div class="v"><?= $num($t['reach']) ?></div>
      <div class="s"><?= $delta($t['reach'], $ta['reach']) ?> · <?= $num($t['impressions']) ?> impressões</div>
    </div>
    <div class="kpi">
      <div class="l">👥 Seguidores ganhos</div>
      <div class="v" style="color:<?= $segTotal > 0 ? 'var(--gn,#34d399)' : 'inherit' ?>"><?= $segTotal > 0 ? '+' . $num($segTotal) : '—' ?></div>
      <div class="s">
        <?= $segTotal > 0 ? $delta($segTotal, $segAnt) . ' · antes +' . $num($segAnt) : 'nenhum no período' ?>
        <?php if ($t['follows'] > 0 && $t['page_likes'] > 0): ?>
          <br><?= $num($t['follows']) ?> seguidores · <?= $num($t['page_likes']) ?> curtidas de página
        <?php endif; ?>
      </div>
    </div>
    <div class="kpi">
      <div class="l">Engajamento</div>
      <div class="v"><?= $num($t['post_engagement']) ?></div>
      <div class="s"><?= $delta($t['post_engagement'], $ta['post_engagement']) ?> · <?= $num($t['saves']) ?> salvamentos</div>
    </div>
    <div class="kpi">
      <div class="l">Frequência</div>
      <div class="v" style="color:<?= $freq >= 3.5 ? '#f87171' : ($freq >= 2.5 ? 'var(--yw)' : 'inherit') ?>"><?= $freq > 0 ? number_format($freq, 1, ',', '.') . 'x' : '—' ?></div>
      <div class="s"><?= $freq >= 3.5 ? 'público saturado' : ($freq >= 2.5 ? 'começando a saturar' : 'saudável') ?></div>
    </div>
    <div class="kpi">
      <div class="l">CPM</div>
      <div class="v"><?= $brl($cpm) ?></div>
      <div class="s"><?= $num($t['link_clicks']) ?> cliques no link</div>
    </div>
  </div>

  <?php if ($melhorDia || $piorDia): ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;margin-bottom:20px">
    <?php if ($melhorDia): ?>
    <div style="background:rgba(52,211,153,.07);border:1px solid rgba(52,211,153,.3);border-radius:12px;padding:12px 14px">
      <div style="font-size:10px;color:var(--mt);text-transform:uppercase">🏆 Melhor dia</div>
      <div style="font-size:16px;font-weight:800;margin-top:2px"><?= date('d/m', strtotime($melhorDia['d']['snapshot_date'])) ?></div>
      <div style="font-size:12px;color:var(--mt)"><?= $num($melhorDia['d']['conversations']) ?> conversas a <?= $brl($melhorDia['cc']) ?> cada</div>
    </div>
    <?php endif; ?>
    <?php if ($piorDia && $melhorDia && $melhorDia['cc'] > 0
              && $piorDia['d']['snapshot_date'] !== $melhorDia['d']['snapshot_date']
              && $piorDia['cc'] > $melhorDia['cc'] * 1.5): ?>
    <div style="background:rgba(248,113,113,.06);border:1px solid rgba(248,113,113,.25);border-radius:12px;padding:12px 14px">
      <div style="font-size:10px;color:var(--mt);text-transform:uppercase">💸 Dia mais caro</div>
      <div style="font-size:16px;font-weight:800;margin-top:2px"><?= date('d/m', strtotime($piorDia['d']['snapshot_date'])) ?></div>
      <div style="font-size:12px;color:var(--mt)"><?= $num($piorDia['d']['conversations']) ?> conversas a <?= $brl($piorDia['cc']) ?> cada</div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-title">📅 Dia a dia</div>
    <?php if (!$diario): ?>
      <div style="padding:26px;text-align:center;color:var(--mt);font-size:13px">
        Ainda sem dados. O cron <code>sync_local.php</code> traz os números a cada rodada.
      </div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tabela">
        <thead>
          <tr>
            <th>Dia</th><th class="n">Investido</th><th class="n">Conversas</th>
            <th class="n">Custo/conversa</th><th class="n">Alcance</th>
            <th class="n">Engajamento</th><th class="n">Seguidores</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_reverse($diario) as $d):
            $cc = $d['conversations'] > 0 ? $d['spend'] / $d['conversations'] : 0; ?>
          <tr>
            <td style="white-space:nowrap;color:var(--mt)"><?= date('d/m', strtotime($d['snapshot_date'])) ?>
              <span style="font-size:10px;opacity:.7"><?= mb_substr(['Sun'=>'dom','Mon'=>'seg','Tue'=>'ter','Wed'=>'qua','Thu'=>'qui','Fri'=>'sex','Sat'=>'sáb'][date('D', strtotime($d['snapshot_date']))] ?? '', 0, 3) ?></span>
            </td>
            <td class="n"><?= $brl($d['spend']) ?></td>
            <td class="n" style="font-weight:700"><?= $num($d['conversations']) ?></td>
            <td class="n"><?= $cc > 0 ? $brl($cc) : '—' ?></td>
            <td class="n"><?= $num($d['reach']) ?></td>
            <td class="n"><?= $num($d['engaj']) ?></td>
            <td class="n"><?= $d['seguidores'] > 0 ? '+' . $num($d['seguidores']) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</main>
</div>
</body>
</html>