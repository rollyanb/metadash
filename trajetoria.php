<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();
if (Auth::getRole() !== 'admin') {
    http_response_code(403);
    exit('Acesso restrito a administradores.');
}

$db = Database::getInstance();

function money($value) { return 'R$' . number_format((float)$value, 2, ',', '.'); }
function moneyShort($v) {
    $v = (float)$v;
    if (abs($v) >= 1000000) return 'R$' . number_format($v / 1000000, 1, ',', '.') . 'M';
    if (abs($v) >= 1000)    return 'R$' . number_format($v / 1000, 0, ',', '.') . 'k';
    return 'R$' . number_format($v, 0, ',', '.');
}
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function num($v, $d = 0) { return number_format((float)$v, $d, ',', '.'); }

$MESES = [1=>'jan',2=>'fev',3=>'mar',4=>'abr',5=>'mai',6=>'jun',7=>'jul',8=>'ago',9=>'set',10=>'out',11=>'nov',12=>'dez'];
$mesAtual = date('Y-m');

$loadError = '';
$meses = [];      // por data do GASTO
$rows  = [];      // por evento
$sazon = [];      // por mês do calendário (data do evento)

try {
    // ── Série mensal pela data do gasto ──────────────────────
    // Agrupar pela data do evento misturava coisas diferentes: uma campanha
    // que roda em outubro para um evento de janeiro caía toda em janeiro.
    // Aqui cada real aparece no mês em que foi gasto.
    $meses = $db->fetchAll("
        SELECT DATE_FORMAT(cs.snapshot_date, '%Y-%m') AS ym,
               SUM(cs.spend)     AS spend,
               SUM(cs.revenue)   AS revenue,
               SUM(cs.purchases) AS purchases
        FROM campaign_snapshots cs
        JOIN event_campaigns ec ON ec.campaign_id = cs.campaign_id
        WHERE cs.snapshot_date IS NOT NULL
        GROUP BY ym
        HAVING SUM(cs.spend) > 0
        ORDER BY ym ASC
    ");

    // ── Consolidado por evento ───────────────────────────────
    $rows = $db->fetchAll("
        SELECT e.id, e.name, e.status, e.date_event,
               COALESCE(ads.spend, 0)      AS ad_spend,
               COALESCE(ads.revenue, 0)    AS ads_revenue,
               COALESCE(ads.purchases, 0)  AS ads_purchases,
               COALESCE(sales.sales_count, 0) AS sales_count
        FROM events e
        LEFT JOIN (
            SELECT ec.event_id,
                   SUM(cs.spend)     AS spend,
                   SUM(cs.revenue)   AS revenue,
                   SUM(cs.purchases) AS purchases
            FROM event_campaigns ec
            JOIN campaign_snapshots cs ON cs.campaign_id = ec.campaign_id
            GROUP BY ec.event_id
        ) ads ON ads.event_id = e.id
        LEFT JOIN (
            SELECT event_id, SUM(COALESCE(total_sales, 0)) AS sales_count
            FROM event_daily_sales GROUP BY event_id
        ) sales ON sales.event_id = e.id
        ORDER BY (e.date_event IS NULL), e.date_event DESC, e.id DESC
    ");
} catch (Exception $e) {
    $loadError = 'Não foi possível carregar os dados: ' . $e->getMessage();
}

// ── Totais e variação ────────────────────────────────────────
$totalSpend = 0.0; $totalRevenue = 0.0; $totalPurch = 0; $totalTickets = 0;
$activeEvents = 0;
foreach ($meses as $m) {
    $totalSpend   += (float)$m['spend'];
    $totalRevenue += (float)$m['revenue'];
    $totalPurch   += (int)$m['purchases'];
}
foreach ($rows as $r) {
    if ($r['status'] === 'active') $activeEvents++;
    $totalTickets += (int)$r['sales_count'];
}
$roasTotal = $totalSpend > 0 ? $totalRevenue / $totalSpend : 0;
$cpaTotal  = $totalPurch  > 0 ? $totalSpend / $totalPurch : 0;

// Compara o último mês FECHADO com o anterior. O mês corrente está pela
// metade e compará-lo sugeriria uma queda que não existe.
$fechados = [];
foreach ($meses as $m) { if ($m['ym'] !== $mesAtual) $fechados[] = $m; }
$ult = count($fechados) >= 1 ? $fechados[count($fechados) - 1] : null;
$pen = count($fechados) >= 2 ? $fechados[count($fechados) - 2] : null;

function variacao($atual, $anterior) {
    if ($anterior === null || (float)$anterior == 0.0) return null;
    return (((float)$atual - (float)$anterior) / (float)$anterior) * 100;
}
function selo($pct, $bomSubir = true) {
    if ($pct === null) return '';
    $sobe = $pct >= 0;
    $bom  = $bomSubir ? $sobe : !$sobe;
    $cls  = abs($pct) < 0.5 ? 'flat' : ($bom ? 'up' : 'down');
    $seta = abs($pct) < 0.5 ? '•' : ($sobe ? '▲' : '▼');
    return '<span class="delta ' . $cls . '">' . $seta . ' ' . number_format(abs($pct), 1, ',', '.') . '%</span>';
}

$roasUlt = $ult && (float)$ult['spend'] > 0 ? (float)$ult['revenue'] / (float)$ult['spend'] : null;
$roasPen = $pen && (float)$pen['spend'] > 0 ? (float)$pen['revenue'] / (float)$pen['spend'] : null;
$cpaUlt  = $ult && (int)$ult['purchases'] > 0 ? (float)$ult['spend'] / (int)$ult['purchases'] : null;
$cpaPen  = $pen && (int)$pen['purchases'] > 0 ? (float)$pen['spend'] / (int)$pen['purchases'] : null;

$dSpend = $ult && $pen ? variacao($ult['spend'],   $pen['spend'])   : null;
$dRev   = $ult && $pen ? variacao($ult['revenue'], $pen['revenue']) : null;
$dRoas  = ($roasUlt !== null && $roasPen !== null) ? variacao($roasUlt, $roasPen) : null;
$dCpa   = ($cpaUlt  !== null && $cpaPen  !== null) ? variacao($cpaUlt,  $cpaPen)  : null;

$refMes = $ult ? $MESES[(int)substr($ult['ym'], 5, 2)] . '/' . substr($ult['ym'], 2, 2) : null;

// ── Melhor mês (só meses fechados, por ROAS) ────────────────
$melhorMes = null;
foreach ($fechados as $m) {
    if ((float)$m['spend'] <= 0) continue;
    $r = (float)$m['revenue'] / (float)$m['spend'];
    if ($melhorMes === null || $r > $melhorMes['roas']) {
        $melhorMes = ['ym'=>$m['ym'], 'roas'=>$r, 'spend'=>(float)$m['spend'], 'revenue'=>(float)$m['revenue']];
    }
}

// ── Melhor e pior evento ────────────────────────────────────
// Dois filtros. O corte de gasto tira o ruído: com investimento pequeno
// qualquer venda vira ROAS alto. O teto de ROAS tira o dado quebrado:
// retorno acima de 25x quase sempre significa que faltam campanhas
// vinculadas ao evento, então a receita existe mas o gasto não aparece
// inteiro. Esses casos viram aviso, não recorde.
$CORTE     = 1000.0;
$TETO_ROAS = 25.0;
// Só entra no ranking evento já encerrado. Evento em andamento está no meio
// da curva — campanha recém-aberta sempre tem retorno baixo — e apareceria
// como "pior" sem ter dado errado.
$hoje = date('Y-m-d');
$comRoas = []; $suspeitos = []; $emCurso = 0;
foreach ($rows as $r) {
    $sp = (float)$r['ad_spend'];
    if ($sp <= 0) continue;
    $ro = (float)$r['ads_revenue'] / $sp;
    if ($ro >= $TETO_ROAS) { $suspeitos[] = $r + ['roas' => $ro]; continue; }
    if ($sp < $CORTE) continue;
    $encerrado = ($r['status'] === 'finished')
         || (!empty($r['date_event']) && $r['date_event'] < $hoje);
    if (!$encerrado) { $emCurso++; continue; }
    $comRoas[] = $r + ['roas' => $ro];
}
usort($comRoas, function ($a, $b) { return $b['roas'] <=> $a['roas']; });
$melhores = array_slice($comRoas, 0, 5);
$piores   = array_slice(array_reverse($comRoas), 0, 5);

// ── Sazonalidade: mês do calendário em que o evento acontece ─
foreach ($rows as $r) {
    if (empty($r['date_event'])) continue;
    $mn = (int)date('n', strtotime($r['date_event']));
    if (!isset($sazon[$mn])) $sazon[$mn] = ['spend'=>0.0, 'revenue'=>0.0, 'n'=>0];
    $sazon[$mn]['spend']   += (float)$r['ad_spend'];
    $sazon[$mn]['revenue'] += (float)$r['ads_revenue'];
    $sazon[$mn]['n']++;
}
ksort($sazon);
$maxSazon = 1.0;
foreach ($sazon as $s) $maxSazon = max($maxSazon, $s['revenue']);

// Escalas independentes: com ROAS alto, uma escala só deixa a barra de
// investimento invisível.
$maxSpend = 1.0; $maxRev = 1.0; $maxRoas = 1.0;
foreach ($meses as $m) {
    $maxSpend = max($maxSpend, (float)$m['spend']);
    $maxRev   = max($maxRev,   (float)$m['revenue']);
    if ((float)$m['spend'] > 0) $maxRoas = max($maxRoas, (float)$m['revenue'] / (float)$m['spend']);
}
$mesesDesc = array_reverse($meses);
?>
<!doctype html>
<html lang="pt-BR" class="<?= h(getThemeClass()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Trajetória — MetaDash</title>
  <?php echoThemeAssets(''); ?>
  <style>
    .page-title{font-size:22px;font-weight:700;margin:0 0 4px}.page-sub{font-size:13px;color:var(--mt2);margin:0 0 22px}
    .summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
    .summary-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px}
    .summary-label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--mt);font-weight:700;margin-bottom:7px}
    .summary-value{font-size:23px;font-weight:700;letter-spacing:-.03em}
    .summary-help{font-size:11px;color:var(--mt2);margin-top:6px;line-height:1.45}
    .positive{color:var(--gn)}.negative{color:var(--rd)}.accent{color:var(--ac)}
    .delta{font-size:11px;font-weight:700;margin-left:7px;vertical-align:2px}
    .delta.up{color:var(--gn)}.delta.down{color:var(--rd)}.delta.flat{color:var(--mt2)}
    .section{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px;margin-bottom:18px}
    .section-title{font-size:15px;font-weight:700;margin:0 0 4px}
    .section-sub{font-size:12px;color:var(--mt2);margin:0 0 16px}
    .grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}

    /* linha mensal: rótulo · investido · faturado · roas */
    .mrow{display:grid;grid-template-columns:76px 1fr 1fr 64px;gap:14px;align-items:center;margin:14px 0}
    .mlabel{font-size:12px;color:var(--mt2);font-weight:600}
    .mlabel .tag{display:block;font-size:9px;color:var(--yw);font-weight:700;letter-spacing:.04em}
    .mbar{position:relative}
    .mbar .track{height:10px;border-radius:99px;background:var(--s2);overflow:hidden}
    .mbar .track>span{display:block;height:100%;border-radius:inherit}
    .fill-spend{background:var(--rd)}.fill-rev{background:var(--gn)}
    .mbar .val{font-size:11px;margin-top:5px;font-variant-numeric:tabular-nums;color:var(--tx)}
    .mbar .cap{font-size:9px;color:var(--mt);text-transform:uppercase;letter-spacing:.06em}
    .mroas{text-align:right;font-size:13px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--tx)}
    .mhead{display:grid;grid-template-columns:76px 1fr 1fr 64px;gap:14px;font-size:9px;text-transform:uppercase;
           letter-spacing:.07em;color:var(--mt);font-weight:700;padding-bottom:8px;border-bottom:1px solid var(--bd)}
    .mhead .r{text-align:right}

    /* pódio */
    .podium{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .pod{background:var(--s2);border-radius:12px;padding:16px}
    .pod .cap{font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--mt);font-weight:700;margin-bottom:8px}
    .pod .big{font-size:18px;font-weight:700;letter-spacing:-.02em;margin-bottom:3px}
    .pod .sub{font-size:11px;color:var(--mt2);line-height:1.5}

    /* sazonalidade */
    .season{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:7px;align-items:end;height:130px;margin-bottom:8px}
    .scol{display:flex;flex-direction:column;justify-content:flex-end;height:100%;gap:5px}
    .scol .sb{background:var(--gn);border-radius:5px 5px 0 0;min-height:3px;opacity:.85}
    .scol .sl{font-size:10px;color:var(--mt2);text-align:center}
    .scol.vazio .sb{background:var(--s3);opacity:.5}

    .table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:12px}
    th{text-align:left;color:var(--mt);font-size:10px;text-transform:uppercase;letter-spacing:.07em;padding:0 10px 10px;border-bottom:1px solid var(--bd)}
    td{padding:11px 10px;border-bottom:1px solid var(--bd);vertical-align:middle}
    tr:last-child td{border-bottom:0}
    .right{text-align:right;font-variant-numeric:tabular-nums}
    .event-name{font-weight:600;color:var(--tx)}.date{font-size:11px;color:var(--mt2);margin-top:3px}
    .badge{font-size:10px;font-weight:700;border-radius:99px;padding:3px 8px;display:inline-block}
    .active-badge{background:rgba(62,207,142,.12);color:var(--gn)}.finished-badge{background:var(--s2);color:var(--mt2)}
    .empty{text-align:center;color:var(--mt2);padding:38px}
    .notice{background:rgba(84,112,255,.10);border:1px solid rgba(84,112,255,.2);border-radius:10px;color:var(--mt2);
            padding:12px 14px;font-size:12px;line-height:1.5;margin-bottom:18px}
    .toggle{background:var(--s2);border:1px solid var(--bd);color:var(--tx);border-radius:9px;padding:8px 14px;
            font-size:12px;font-weight:600;cursor:pointer;margin-top:14px}
    .toggle:hover{background:var(--s3)}
    #todosEventos{display:none;margin-top:16px}
    @media(max-width:1000px){
      .summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
      .grid2{grid-template-columns:1fr}
      .podium{grid-template-columns:1fr}
    }
    @media(max-width:620px){
      .summary-grid{grid-template-columns:1fr}
      .mrow,.mhead{grid-template-columns:58px 1fr 1fr 48px;gap:8px}
      .season{gap:3px}.scol .sl{font-size:8px}
      th:nth-child(2),td:nth-child(2){display:none}
    }
  </style>
</head>
<body>
<?php $currentPage='trajectory'; $sidebarBase=''; require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
  <h1 class="page-title">📈 Trajetória</h1>
  <p class="page-sub">Evolução do investimento em anúncios e do retorno atribuído pela Meta.</p>

  <?php if (!empty($loadError)): ?><div class="notice"><?= h($loadError) ?></div><?php endif; ?>

  <div class="summary-grid">
    <div class="summary-card">
      <div class="summary-label">Investido</div>
      <div class="summary-value negative"><?= money($totalSpend) ?></div>
      <div class="summary-help"><?= $activeEvents ?> eventos ativos de <?= count($rows) ?><?= $dSpend !== null ? '<br>' . h($refMes) . ': ' . selo($dSpend, true) : '' ?></div>
    </div>
    <div class="summary-card">
      <div class="summary-label">Faturamento Meta</div>
      <div class="summary-value positive"><?= money($totalRevenue) ?></div>
      <div class="summary-help"><?= num($totalPurch) ?> compras atribuídas<?= $dRev !== null ? '<br>' . h($refMes) . ': ' . selo($dRev, true) : '' ?></div>
    </div>
    <div class="summary-card">
      <div class="summary-label">ROAS</div>
      <div class="summary-value accent"><?= num($roasTotal, 2) ?>x</div>
      <div class="summary-help">Cada R$1 traz <?= money($roasTotal) ?> atribuídos<?= $dRoas !== null ? '<br>' . h($refMes) . ': ' . selo($dRoas, true) : '' ?></div>
    </div>
    <div class="summary-card">
      <div class="summary-label">Custo por compra</div>
      <div class="summary-value"><?= $cpaTotal > 0 ? money($cpaTotal) : '—' ?></div>
      <div class="summary-help">Média do período<?= $cpaUlt !== null ? ' · ' . h($refMes) . ': ' . money($cpaUlt) . ' ' . selo($dCpa, false) : '' ?></div>
    </div>
  </div>

  <div class="notice">
    Os valores usam a receita que a Meta atribui aos anúncios, dentro da janela de atribuição do pixel. É estimativa da
    plataforma, não caixa: conta venda atribuída por visualização e ignora venda que o pixel não registrou. A variação
    compara o último mês fechado com o anterior — o mês corrente fica de fora para não parecer queda.
  </div>

  <?php if ($melhorMes || $melhores): ?>
  <section class="section">
    <h2 class="section-title">🏆 Destaques</h2>
    <p class="section-sub">Ranking só com eventos encerrados, acima de <?= money($CORTE) ?> investidos e abaixo de <?= num($TETO_ROAS) ?>x<?= $emCurso > 0 ? ' · ' . $emCurso . ' evento(s) em andamento ficam de fora até terminar' : '' ?>.</p>
    <?php if ($suspeitos): ?>
    <div class="notice" style="margin-bottom:16px">
      <strong>⚠️ <?= count($suspeitos) ?> evento(s) com retorno implausível, provavelmente por campanha não vinculada.</strong><br>
      <?php foreach ($suspeitos as $sp): ?>
        <?= h($sp['name']) ?> — <?= money($sp['ad_spend']) ?> investidos para <?= money($sp['ads_revenue']) ?> atribuídos
        (<?= num($sp['roas'], 1) ?>x). <a href="event.php?id=<?= (int)$sp['id'] ?>" style="color:var(--ac)">Conferir campanhas</a><br>
      <?php endforeach; ?>
      A receita é real, mas o gasto só conta as campanhas ligadas ao evento. Vincule as que faltam para o número fechar.
    </div>
    <?php endif; ?>
    <div class="podium">
      <div class="pod">
        <div class="cap">Melhor mês</div>
        <?php if ($melhorMes): ?>
          <div class="big accent"><?= h($MESES[(int)substr($melhorMes['ym'],5,2)] . '/' . substr($melhorMes['ym'],0,4)) ?></div>
          <div class="sub"><?= num($melhorMes['roas'], 2) ?>x de retorno<br><?= money($melhorMes['spend']) ?> investidos → <?= money($melhorMes['revenue']) ?></div>
        <?php else: ?><div class="sub">Sem meses fechados ainda.</div><?php endif; ?>
      </div>
      <div class="pod">
        <div class="cap">Melhor evento</div>
        <?php if (!empty($melhores[0])): $b = $melhores[0]; ?>
          <div class="big positive"><?= num($b['roas'], 2) ?>x</div>
          <div class="sub"><?= h($b['name']) ?><br><?= money($b['ad_spend']) ?> → <?= money($b['ads_revenue']) ?></div>
        <?php else: ?><div class="sub">Nenhum evento acima do corte.</div><?php endif; ?>
      </div>
      <div class="pod">
        <div class="cap">Pior evento</div>
        <?php if (!empty($piores[0])): $w = $piores[0]; ?>
          <div class="big negative"><?= num($w['roas'], 2) ?>x</div>
          <div class="sub"><?= h($w['name']) ?><br><?= money($w['ad_spend']) ?> → <?= money($w['ads_revenue']) ?></div>
        <?php else: ?><div class="sub">Nenhum evento acima do corte.</div><?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="section">
    <h2 class="section-title">Mês a mês</h2>
    <p class="section-sub">Pela data do gasto: cada real aparece no mês em que foi investido, não no mês do evento.</p>
    <?php if (!$meses): ?>
      <div class="empty">Nenhum gasto registrado ainda.</div>
    <?php else: ?>
      <div class="mhead"><div>Mês</div><div>Investido</div><div>Faturamento Meta</div><div class="r">ROAS</div></div>
      <?php foreach ($mesesDesc as $m):
        $sp = (float)$m['spend']; $rv = (float)$m['revenue'];
        $ro = $sp > 0 ? $rv / $sp : 0;
        $emAndamento = ($m['ym'] === $mesAtual);
      ?>
      <div class="mrow">
        <div class="mlabel">
          <?= h($MESES[(int)substr($m['ym'],5,2)] . '/' . substr($m['ym'],2,2)) ?>
          <?php if ($emAndamento): ?><span class="tag">em andamento</span><?php endif; ?>
        </div>
        <div class="mbar">
          <div class="track"><span class="fill-spend" style="width:<?= round($sp / $maxSpend * 100, 2) ?>%"></span></div>
          <div class="val"><?= money($sp) ?></div>
        </div>
        <div class="mbar">
          <div class="track"><span class="fill-rev" style="width:<?= round($rv / $maxRev * 100, 2) ?>%"></span></div>
          <div class="val"><?= money($rv) ?> <span class="cap">· <?= num($m['purchases']) ?> compras</span></div>
        </div>
        <div class="mroas <?= $ro >= $roasTotal ? 'positive' : '' ?>"><?= num($ro, 1) ?>x</div>
      </div>
      <?php endforeach; ?>
      <p class="section-sub" style="margin:14px 0 0">As duas barras têm escalas próprias — comparar o comprimento entre elas não significa nada, só o valor escrito.</p>
    <?php endif; ?>
  </section>

  <?php if (count($sazon) > 1): ?>
  <section class="section">
    <h2 class="section-title">Sazonalidade</h2>
    <p class="section-sub">Faturamento somado por mês do calendário em que o evento acontece, com todos os anos juntos. Serve para negociar calendário.</p>
    <div class="season">
      <?php for ($mn = 1; $mn <= 12; $mn++):
        $s = $sazon[$mn] ?? null;
        $alt = $s ? max(3, round($s['revenue'] / $maxSazon * 100)) : 3;
      ?>
      <div class="scol <?= $s ? '' : 'vazio' ?>" title="<?= $s ? h($MESES[$mn] . ': ' . money($s['revenue']) . ' · ' . $s['n'] . ' evento(s)') : h($MESES[$mn] . ': sem eventos') ?>">
        <div class="sb" style="height:<?= $alt ?>%"></div>
        <div class="sl"><?= h($MESES[$mn]) ?><?php if ($s): ?><br><strong><?= moneyShort($s['revenue']) ?></strong><?php endif; ?></div>
      </div>
      <?php endfor; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="section">
    <h2 class="section-title">Eventos</h2>
    <p class="section-sub">Os cinco melhores e os cinco piores entre os encerrados. A lista completa, com os que ainda estão rodando, fica recolhida abaixo.</p>
    <?php
    function linhasEventos($lista, $MESES) {
        foreach ($lista as $row):
            $sp = (float)$row['ad_spend']; $rv = (float)$row['ads_revenue'];
            $ro = $sp > 0 ? $rv / $sp : 0; $res = $rv - $sp;
    ?>
      <tr>
        <td><div class="event-name"><?= h($row['name']) ?></div>
            <div class="date"><?= $row['date_event'] ? date('d/m/Y', strtotime($row['date_event'])) : 'Sem data' ?></div></td>
        <td><span class="badge <?= $row['status'] === 'active' ? 'active-badge' : 'finished-badge' ?>"><?= $row['status'] === 'active' ? '● Ativo' : '● Encerrado' ?></span></td>
        <td class="right negative"><?= money($sp) ?></td>
        <td class="right positive"><?= money($rv) ?></td>
        <td class="right"><strong><?= num($ro, 2) ?>x</strong></td>
        <td class="right <?= $res >= 0 ? 'positive' : 'negative' ?>"><?= money($res) ?></td>
      </tr>
    <?php endforeach;
    }
    ?>
    <?php if (!$comRoas): ?>
      <div class="empty">Nenhum evento com investimento suficiente para ranquear.</div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Evento</th><th>Status</th><th class="right">Investido</th><th class="right">Faturamento Meta</th><th class="right">ROAS</th><th class="right">Resultado</th></tr></thead>
      <tbody>
        <tr><td colspan="6" style="padding:14px 10px 6px;font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--gn);font-weight:700;border-bottom:0">Melhores</td></tr>
        <?php linhasEventos($melhores, $MESES); ?>
        <tr><td colspan="6" style="padding:18px 10px 6px;font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--rd);font-weight:700;border-bottom:0">Piores</td></tr>
        <?php linhasEventos($piores, $MESES); ?>
      </tbody>
    </table></div>
    <?php endif; ?>

    <button class="toggle" type="button" id="btnTodos">Ver todos os <?= count($rows) ?> eventos</button>
    <div id="todosEventos">
      <div class="table-wrap"><table>
        <thead><tr><th>Evento</th><th>Status</th><th class="right">Investido</th><th class="right">Faturamento Meta</th><th class="right">ROAS</th><th class="right">Resultado</th></tr></thead>
        <tbody>
          <?php if (!$rows): ?><tr><td colspan="6" class="empty">Nenhum evento encontrado.</td></tr><?php endif; ?>
          <?php linhasEventos($rows, $MESES); ?>
        </tbody>
      </table></div>
    </div>
  </section>
</main>
<script>
(function () {
  var btn = document.getElementById('btnTodos');
  var box = document.getElementById('todosEventos');
  if (!btn || !box) return;
  btn.addEventListener('click', function () {
    var aberto = box.style.display === 'block';
    box.style.display = aberto ? 'none' : 'block';
    btn.textContent = aberto ? 'Ver todos os <?= count($rows) ?> eventos' : 'Recolher lista completa';
  });
})();
</script>
</body>
</html>
