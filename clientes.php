<?php
// clientes.php — Negócios locais (clientes recorrentes).
// Foco: conversas iniciadas, alcance, engajamento e seguidores — não ROAS.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';

Auth::requireLogin();
$db      = Database::getInstance();
$uid     = Auth::getUserId();
$isAdmin = Auth::isAdmin();

$msg = ''; $msgTipo = 'ok';

// ── AÇÕES (admin) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    try {
        if (isset($_POST['salvar'])) {
            $id    = (int)($_POST['id'] ?? 0);
            $nome  = trim($_POST['name'] ?? '');
            $contas = array_values(array_filter(array_map('trim', $_POST['accounts'] ?? [])));
            if ($nome === '') throw new Exception('Informe o nome do cliente.');

            $dados = [
                $nome, trim($_POST['segment'] ?? '') ?: null, $_POST['icon'] ?? '🏪',
                ($_POST['monthly_budget'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['monthly_budget']) : null,
                ($_POST['monthly_fee'] ?? '') !== ''    ? (float)str_replace(',', '.', $_POST['monthly_fee'])    : null,
                trim($_POST['whatsapp'] ?? '') ?: null,
                $_POST['status'] ?? 'active',
            ];

            if ($id) {
                $db->execute("UPDATE local_clients SET name=?, segment=?, icon=?, monthly_budget=?,
                              monthly_fee=?, whatsapp=?, status=? WHERE id=?", array_merge($dados, [$id]));
            } else {
                $id = $db->insert("INSERT INTO local_clients (name, segment, icon, monthly_budget,
                                   monthly_fee, whatsapp, status, created_by) VALUES (?,?,?,?,?,?,?,?)",
                                   array_merge($dados, [$uid]));
            }

            $db->execute("DELETE FROM local_client_accounts WHERE client_id=?", [$id]);
            foreach ($contas as $acc) {
                $db->execute("INSERT IGNORE INTO local_client_accounts (client_id, account_id) VALUES (?,?)", [$id, $acc]);
            }
            $msg = 'Cliente salvo. As métricas aparecem após a próxima sincronização.';
        } elseif (isset($_POST['excluir'])) {
            $db->execute("DELETE FROM local_clients WHERE id=?", [(int)$_POST['excluir']]);
            $msg = 'Cliente removido.';
        }
    } catch (Exception $e) { $msg = $e->getMessage(); $msgTipo = 'erro'; }
}

// ── PERÍODO ───────────────────────────────────────────────────
// Padrão: mês corrente, comparado com o mesmo número de dias do mês anterior.
$mesIni = date('Y-m-01');
$hoje   = date('Y-m-d');
$diasCorridos = (int)date('j');
$antIni = date('Y-m-01', strtotime('-1 month'));
$antFim = date('Y-m-d', strtotime($antIni . ' +' . ($diasCorridos - 1) . ' days'));

// ── CLIENTES VISÍVEIS ─────────────────────────────────────────
if ($isAdmin) {
    $clientes = $db->fetchAll("SELECT * FROM local_clients WHERE status <> 'archived' ORDER BY status, name");
} else {
    $clientes = $db->fetchAll("SELECT c.* FROM local_clients c
                               JOIN local_client_access a ON a.client_id = c.id AND a.user_id = ?
                               WHERE c.status <> 'archived' ORDER BY c.status, c.name", [$uid]);
}

// ── MÉTRICAS (duas queries para todos os clientes) ────────────
$metricas = []; $contasPorCliente = [];
if ($clientes) {
    $ids = array_column($clientes, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    foreach ($db->fetchAll("SELECT lca.client_id, lca.account_id, a.account_name
                            FROM local_client_accounts lca
                            LEFT JOIN ad_accounts a ON a.account_id = lca.account_id
                            WHERE lca.client_id IN ($ph)", $ids) as $r) {
        $contasPorCliente[(int)$r['client_id']][] = $r;
    }

    $agrega = function ($de, $ate) use ($db, $ids, $ph) {
        $out = [];
        try {
            foreach ($db->fetchAll("
                SELECT lca.client_id,
                       COALESCE(SUM(s.spend),0)           AS spend,
                       COALESCE(SUM(s.conversations),0)   AS conversas,
                       COALESCE(SUM(s.reach),0)           AS alcance,
                       COALESCE(SUM(s.impressions),0)     AS impressoes,
                       COALESCE(SUM(s.post_engagement),0) AS engajamento,
                       COALESCE(SUM(s.page_likes),0)      AS curtidas,
                       COALESCE(SUM(s.follows),0)         AS seguidores,
                       COALESCE(SUM(s.link_clicks),0)     AS cliques
                FROM local_client_accounts lca
                LEFT JOIN local_snapshots s
                       ON s.account_id = lca.account_id AND s.snapshot_date BETWEEN ? AND ?
                WHERE lca.client_id IN ($ph)
                GROUP BY lca.client_id", array_merge([$de, $ate], $ids)) as $r) {
                $out[(int)$r['client_id']] = $r;
            }
        } catch (Exception $e) {}
        return $out;
    };
    $atual    = $agrega($mesIni, $hoje);
    $anterior = $agrega($antIni, $antFim);

    foreach ($clientes as $c) {
        $a = $atual[$c['id']]    ?? [];
        $b = $anterior[$c['id']] ?? [];
        $spend = (float)($a['spend'] ?? 0);
        $conv  = (int)($a['conversas'] ?? 0);
        $spendB = (float)($b['spend'] ?? 0);
        $convB  = (int)($b['conversas'] ?? 0);

        $metricas[$c['id']] = [
            'spend'      => $spend,
            'conversas'  => $conv,
            'custo_conv' => $conv > 0 ? $spend / $conv : 0,
            'alcance'    => (int)($a['alcance'] ?? 0),
            'freq'       => ($a['alcance'] ?? 0) > 0 ? ($a['impressoes'] / $a['alcance']) : 0,
            'engajamento'=> (int)($a['engajamento'] ?? 0),
            'seguidores' => (int)($a['seguidores'] ?? 0) + (int)($a['curtidas'] ?? 0),
            'cpm'        => ($a['impressoes'] ?? 0) > 0 ? $spend / $a['impressoes'] * 1000 : 0,
            // variações contra o mesmo intervalo do mês anterior
            'd_conversas' => $convB > 0 ? ($conv - $convB) / $convB * 100 : null,
            'd_custo'     => ($convB > 0 && $conv > 0 && $spendB > 0)
                             ? (($spend / $conv) - ($spendB / $convB)) / ($spendB / $convB) * 100 : null,
            'custo_conv_ant' => $convB > 0 ? $spendB / $convB : 0,
        ];
    }
}

$todasContas = [];
if ($isAdmin) {
    try {
        $todasContas = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts
                                      WHERE status IN ('ACTIVE','INACTIVE') ORDER BY account_name");
    } catch (Exception $e) {}
}
$brl = function ($v, $d = 2) { return 'R$ ' . number_format((float)$v, $d, ',', '.'); };
$num = function ($v) { return number_format((float)$v, 0, ',', '.'); };
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Negócios locais — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
.grid-cli { display:grid; grid-template-columns:repeat(auto-fill,minmax(330px,1fr)); gap:16px }
.cli-card { background:var(--sf); border:1px solid var(--bd); border-radius:14px; padding:16px 17px; position:relative }
.cli-head { display:flex; align-items:flex-start; gap:10px; margin-bottom:13px }
.cli-nome { font-size:16px; font-weight:700; line-height:1.2 }
.cli-seg  { font-size:11px; color:var(--mt); margin-top:2px }
.destaque { background:var(--s2); border-radius:11px; padding:12px 14px; margin-bottom:12px }
.destaque .n { font-size:26px; font-weight:800; line-height:1 }
.destaque .l { font-size:10.5px; color:var(--mt); text-transform:uppercase; letter-spacing:.05em }
.mini { display:grid; grid-template-columns:1fr 1fr; gap:10px 12px }
.mini .l { font-size:10px; color:var(--mt); text-transform:uppercase; letter-spacing:.04em }
.mini .v { font-size:14px; font-weight:700; margin-top:1px }
.delta { font-size:11px; font-weight:600 }
.up { color:var(--gn,#34d399) } .down { color:#f87171 } .flat { color:var(--mt) }
.barra { height:6px; background:var(--s2); border-radius:4px; overflow:hidden; margin-top:9px }
.barra span { display:block; height:100% }
.aviso { padding:12px 15px; border-radius:11px; margin-bottom:16px; font-size:14px }
.aviso.ok { background:rgba(52,211,153,.1); border:1px solid rgba(52,211,153,.35); color:#34d399 }
.aviso.erro { background:rgba(248,113,113,.1); border:1px solid rgba(248,113,113,.35); color:#f87171 }
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.8); z-index:999; align-items:center; justify-content:center; padding:20px }
.modal-bg.open { display:flex }
.modal-box { background:var(--sf); border:1px solid var(--bd); border-radius:15px; max-width:560px; width:100%; max-height:88vh; overflow:auto; padding:22px }
.campo { margin-bottom:13px } .campo label { display:block; font-size:11px; color:var(--mt); margin-bottom:4px; font-weight:600 }
.duas { display:grid; grid-template-columns:1fr 1fr; gap:12px }
.lista-contas { max-height:170px; overflow:auto; border:1px solid var(--bd); border-radius:9px; padding:6px 8px }
</style>
</head>
<body>
<div class="app-wrap">
<?php $currentPage = 'clientes'; require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">

  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:6px">
    <div>
      <h1 class="page-title">🏪 Negócios locais</h1>
      <p class="page-sub">Clientes recorrentes · <?= date('m/Y') ?> (dia 1 a <?= $diasCorridos ?>) vs mesmo período do mês anterior</p>
    </div>
    <?php if ($isAdmin): ?>
    <button type="button" class="btn btn-p" onclick="abrirCliente()">➕ Novo cliente</button>
    <?php endif; ?>
  </div>

  <?php if ($msg): ?><div class="aviso <?= $msgTipo === 'erro' ? 'erro' : 'ok' ?>" style="margin-top:14px"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <?php if (!$clientes): ?>
    <div style="text-align:center;padding:50px 20px;color:var(--mt)">
      🏪 Nenhum cliente cadastrado ainda.<?= $isAdmin ? ' Clique em "Novo cliente" para começar.' : '' ?>
    </div>
  <?php else: ?>

  <div class="grid-cli" style="margin-top:18px">
    <?php foreach ($clientes as $c):
      $m = $metricas[$c['id']] ?? [];
      $contas = $contasPorCliente[$c['id']] ?? [];
      $pausado = $c['status'] === 'paused';
      $freq = $m['freq'] ?? 0;
      $pctVerba = !empty($c['monthly_budget']) ? min(100, ($m['spend'] ?? 0) / $c['monthly_budget'] * 100) : null;
    ?>
    <div class="cli-card" style="<?= $pausado ? 'opacity:.6' : '' ?>">
      <div class="cli-head">
        <span style="font-size:22px"><?= htmlspecialchars($c['icon'] ?: '🏪') ?></span>
        <div style="flex:1;min-width:0">
          <div class="cli-nome"><?= htmlspecialchars($c['name']) ?></div>
          <div class="cli-seg">
            <?= htmlspecialchars($c['segment'] ?: 'negócio local') ?>
            <?= $contas ? ' · ' . count($contas) . ' conta' . (count($contas) > 1 ? 's' : '') : ' · <span style="color:#f87171">sem conta vinculada</span>' ?>
            <?= $pausado ? ' · <span style="color:var(--yw)">pausado</span>' : '' ?>
          </div>
        </div>
        <?php if ($isAdmin): ?>
        <button type="button" onclick='abrirCliente(<?= json_encode([
            "id" => $c["id"], "name" => $c["name"], "segment" => $c["segment"], "icon" => $c["icon"],
            "monthly_budget" => $c["monthly_budget"], "monthly_fee" => $c["monthly_fee"],
            "whatsapp" => $c["whatsapp"], "status" => $c["status"],
            "accounts" => array_column($contas, "account_id"),
        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                style="background:none;border:none;color:var(--mt);cursor:pointer;font-size:14px" title="Editar">✏️</button>
        <?php endif; ?>
      </div>

      <!-- métrica principal: conversas -->
      <div class="destaque">
        <div class="l">💬 Conversas iniciadas</div>
        <div style="display:flex;align-items:baseline;gap:9px;flex-wrap:wrap">
          <span class="n"><?= $num($m['conversas'] ?? 0) ?></span>
          <?php if (isset($m['d_conversas']) && $m['d_conversas'] !== null):
            $d = $m['d_conversas']; $cls = $d > 2 ? 'up' : ($d < -2 ? 'down' : 'flat'); ?>
          <span class="delta <?= $cls ?>"><?= ($d >= 0 ? '▲ +' : '▼ ') . number_format($d, 0, ',', '.') ?>% vs mês passado</span>
          <?php endif; ?>
        </div>
        <div style="font-size:12px;color:var(--mt);margin-top:4px">
          <?= ($m['conversas'] ?? 0) > 0 ? $brl($m['custo_conv']) . ' por conversa' : 'nenhuma conversa no período' ?>
          <?php if (isset($m['d_custo']) && $m['d_custo'] !== null):
            $dc = $m['d_custo']; ?>
            · <span class="<?= $dc < -2 ? 'up' : ($dc > 2 ? 'down' : 'flat') ?>">
              <?= $dc < 0 ? '▼ ' : '▲ +' ?><?= number_format(abs($dc), 0, ',', '.') ?>%
            </span>
          <?php endif; ?>
        </div>
      </div>

      <div class="mini">
        <div><div class="l">Investido</div><div class="v"><?= $brl($m['spend'] ?? 0) ?></div></div>
        <div><div class="l">Alcance</div><div class="v"><?= $num($m['alcance'] ?? 0) ?></div></div>
        <div><div class="l">Engajamento</div><div class="v"><?= $num($m['engajamento'] ?? 0) ?></div></div>
        <div>
          <div class="l">Seguidores ganhos</div>
          <div class="v"><?= ($m['seguidores'] ?? 0) > 0 ? '+' . $num($m['seguidores']) : '—' ?></div>
        </div>
        <div><div class="l">CPM</div><div class="v"><?= $brl($m['cpm'] ?? 0) ?></div></div>
        <div>
          <div class="l">Frequência</div>
          <div class="v" style="color:<?= $freq >= 3.5 ? '#f87171' : ($freq >= 2.5 ? 'var(--yw)' : 'inherit') ?>">
            <?= $freq > 0 ? number_format($freq, 1, ',', '.') . 'x' : '—' ?>
          </div>
        </div>
      </div>

      <?php if ($freq >= 3.5): ?>
      <div style="margin-top:10px;font-size:11px;color:#f87171">
        ⚠️ Frequência alta — o mesmo público está vendo demais. Vale renovar criativo ou ampliar o raio.
      </div>
      <?php endif; ?>

      <?php if ($pctVerba !== null): ?>
      <div style="margin-top:12px">
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--mt)">
          <span>Verba do mês</span>
          <span><?= $brl($m['spend'] ?? 0) ?> / <?= $brl($c['monthly_budget']) ?></span>
        </div>
        <div class="barra"><span style="width:<?= $pctVerba ?>%;background:<?= $pctVerba >= 95 ? '#f87171' : ($pctVerba >= 75 ? 'var(--yw)' : 'var(--ac)') ?>"></span></div>
      </div>
      <?php endif; ?>

      <div style="margin-top:13px;padding-top:11px;border-top:1px solid var(--bd)">
        <a href="cliente.php?id=<?= $c['id'] ?>" style="font-size:13px;color:var(--ac);text-decoration:none;font-weight:600">Abrir cliente →</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</main>
</div>

<?php if ($isAdmin): ?>
<div class="modal-bg" id="modalCli" onclick="if(event.target===this)fecharCliente()">
  <div class="modal-box">
    <div style="font-size:17px;font-weight:700;margin-bottom:16px" id="tituloCli">➕ Novo cliente</div>
    <form method="POST">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="id" id="cliId">

      <div class="campo">
        <label>Nome do cliente *</label>
        <input type="text" name="name" id="cliNome" required placeholder="Ex.: Restaurante Sabor Caseiro">
      </div>

      <div class="duas">
        <div class="campo">
          <label>Segmento</label>
          <input type="text" name="segment" id="cliSeg" placeholder="restaurante, confeitaria, loja…">
        </div>
        <div class="campo">
          <label>Ícone</label>
          <select name="icon" id="cliIcon">
            <?php foreach (['🏪','🍔','🍕','🍰','🥐','☕','🍣','🛍️','💈','💅','🏋️','🐾'] as $ic): ?>
            <option value="<?= $ic ?>"><?= $ic ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="duas">
        <div class="campo">
          <label>Verba de mídia no mês (R$)</label>
          <input type="text" name="monthly_budget" id="cliVerba" placeholder="1500,00">
        </div>
        <div class="campo">
          <label>Seu fee mensal (R$)</label>
          <input type="text" name="monthly_fee" id="cliFee" placeholder="800,00">
        </div>
      </div>

      <div class="duas">
        <div class="campo">
          <label>WhatsApp do cliente</label>
          <input type="text" name="whatsapp" id="cliZap" placeholder="(35) 99999-9999">
        </div>
        <div class="campo">
          <label>Situação</label>
          <select name="status" id="cliStatus">
            <option value="active">Ativo</option>
            <option value="paused">Pausado</option>
            <option value="archived">Arquivado</option>
          </select>
        </div>
      </div>

      <div class="campo">
        <label>Contas de anúncios</label>
        <div class="lista-contas">
          <?php foreach ($todasContas as $a): ?>
          <label style="display:flex;align-items:center;gap:8px;padding:4px 5px;font-size:13px;cursor:pointer">
            <input type="checkbox" class="cli-acc" name="accounts[]" value="<?= htmlspecialchars($a['account_id']) ?>" style="width:16px;height:16px;accent-color:var(--ac)">
            <span><?= htmlspecialchars($a['account_name']) ?></span>
          </label>
          <?php endforeach; ?>
          <?php if (!$todasContas): ?><div style="font-size:12px;color:var(--mt);padding:6px">Nenhuma conta sincronizada ainda.</div><?php endif; ?>
        </div>
      </div>

      <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:18px">
        <button type="submit" name="excluir" id="btnExcluir" value="" class="btn btn-d btn-sm"
                style="display:none" onclick="return confirm('Remover este cliente? As métricas sincronizadas continuam no banco.')">🗑️ Remover</button>
        <span style="flex:1"></span>
        <button type="button" class="btn btn-o btn-sm" onclick="fecharCliente()">Cancelar</button>
        <button type="submit" name="salvar" value="1" class="btn btn-p">💾 Salvar</button>
      </div>
    </form>
  </div>
</div>
<script>
function abrirCliente(c) {
  var m = document.getElementById('modalCli');
  document.getElementById('tituloCli').textContent = c ? '✏️ Editar cliente' : '➕ Novo cliente';
  document.getElementById('cliId').value     = c ? c.id : '';
  document.getElementById('cliNome').value   = c ? (c.name || '') : '';
  document.getElementById('cliSeg').value    = c ? (c.segment || '') : '';
  document.getElementById('cliIcon').value   = c ? (c.icon || '🏪') : '🏪';
  document.getElementById('cliVerba').value  = c && c.monthly_budget ? String(c.monthly_budget).replace('.', ',') : '';
  document.getElementById('cliFee').value    = c && c.monthly_fee ? String(c.monthly_fee).replace('.', ',') : '';
  document.getElementById('cliZap').value    = c ? (c.whatsapp || '') : '';
  document.getElementById('cliStatus').value = c ? (c.status || 'active') : 'active';

  var contas = (c && c.accounts) ? c.accounts : [];
  document.querySelectorAll('.cli-acc').forEach(function (cb) { cb.checked = contas.indexOf(cb.value) !== -1; });

  var btn = document.getElementById('btnExcluir');
  if (c) { btn.style.display = ''; btn.value = c.id; } else { btn.style.display = 'none'; btn.value = ''; }

  m.classList.add('open');
}
function fecharCliente() { document.getElementById('modalCli').classList.remove('open'); }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fecharCliente(); });
</script>
<?php endif; ?>
</body>
</html>
