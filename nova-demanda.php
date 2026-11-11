<?php
// nova-demanda.php — página enxuta para a equipe cadastrar demandas.
// Grava em event_tasks, então a demanda aparece na aba Tarefas do evento,
// onde o admin marca como feito. Exige login.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notify.php';

Auth::requireLogin();
$db      = Database::getInstance();
$uid     = Auth::getUserId();
$isAdmin = Auth::isAdmin();

// Eventos que a pessoa pode usar (admin vê todos; demais só os liberados)
if ($isAdmin) {
    $eventos = $db->fetchAll("SELECT id, name, date_event FROM events
                              WHERE status = 'active' ORDER BY date_event ASC");
} else {
    $eventos = $db->fetchAll("SELECT e.id, e.name, e.date_event FROM events e
                              JOIN event_user_access eua ON eua.event_id = e.id AND eua.user_id = ?
                              WHERE e.status = 'active' ORDER BY e.date_event ASC", [$uid]);
}

$msg = ''; $msgTipo = 'ok'; $ultimoEvento = (int)($_GET['e'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['criar'])) {
    $eventId  = (int)($_POST['event_id'] ?? 0);
    $titulo   = trim($_POST['titulo'] ?? '');
    $link     = trim($_POST['link'] ?? '');
    $prazo    = $_POST['prazo'] ?? date('Y-m-d');
    $categoria= $_POST['categoria'] ?? 'criativo';
    $prio     = $_POST['prioridade'] ?? 'media';
    $obs      = trim($_POST['obs'] ?? '');

    $idsPermitidos = array_map('intval', array_column($eventos, 'id'));

    if ($titulo === '')                             { $msg = 'Escreva o que precisa ser feito.'; $msgTipo = 'erro'; }
    elseif (!$eventId || !in_array($eventId, $idsPermitidos, true)) { $msg = 'Escolha um evento da lista.'; $msgTipo = 'erro'; }
    elseif ($link !== '' && !preg_match('~^https?://~i', $link))    { $msg = 'O link precisa começar com http:// ou https://'; $msgTipo = 'erro'; }
    else {
        try {
            $db->execute(
                "INSERT INTO event_tasks (event_id, title, category, status, priority, due_date, notes, link, created_by)
                 VALUES (?,?,?,'pendente',?,?,?,?,?)",
                [$eventId, mb_substr($titulo, 0, 300), $categoria, $prio, $prazo ?: null,
                 mb_substr($obs, 0, 2000), $link ?: null, $uid]
            );

            // Avisa os admins no sino E no celular (push na hora)
            $ev   = $db->fetch("SELECT name FROM events WHERE id=?", [$eventId]);
            $quem = $_SESSION['username'] ?? 'alguém';
            $destinos = [];
            foreach ($db->fetchAll("SELECT id FROM users WHERE role = 'admin'") as $a) {
                if ((int)$a['id'] !== $uid) $destinos[] = (int)$a['id'];
            }
            $envio = notificar($db, $destinos,
                '📥 Nova demanda: ' . mb_substr($titulo, 0, 60),
                $quem . ' cadastrou em ' . ($ev['name'] ?? 'evento'),
                'event.php?id=' . $eventId);

            $msg = 'Demanda cadastrada! Pode cadastrar outra.';
            if ($isAdmin && !empty($envio['erros'])) {
                // só o admin vê o diagnóstico do push
                $msg .= ' (push: ' . $envio['push'] . ' enviado(s) — ' . implode(' | ', array_slice($envio['erros'], 0, 2)) . ')';
            }
            $ultimoEvento = $eventId;
            $_POST = [];   // limpa o formulário
        } catch (Exception $e) {
            $msg = 'Não foi possível salvar: ' . $e->getMessage();
            $msgTipo = 'erro';
        }
    }
}

// Concluir / reabrir direto daqui (só quem criou ou admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alternar'])) {
    $tid = (int)$_POST['alternar'];
    try {
        $t = $db->fetch("SELECT id, status, created_by FROM event_tasks WHERE id=?", [$tid]);
        if ($t && ($isAdmin || (int)$t['created_by'] === $uid)) {
            $novo = $t['status'] === 'concluido' ? 'pendente' : 'concluido';
            $db->execute("UPDATE event_tasks SET status=?, done_at = IF(?='concluido', NOW(), NULL) WHERE id=?",
                [$novo, $novo, $tid]);
        }
    } catch (Exception $e) {}
}

// Demandas desta pessoa, separadas por situação
$aba = ($_GET['aba'] ?? 'pendentes') === 'feitas' ? 'feitas' : 'pendentes';
$pendentes = []; $feitas = [];
try {
    $todas = $db->fetchAll("
        SELECT t.id, t.title, t.status, t.due_date, t.link, t.notes, t.priority,
               t.category, t.created_at, t.done_at, e.name AS evento, e.id AS event_id
        FROM event_tasks t
        JOIN events e ON e.id = t.event_id
        WHERE t.created_by = ?
        ORDER BY (t.due_date IS NULL), t.due_date ASC, t.id DESC
        LIMIT 200", [$uid]);
    foreach ($todas as $t) {
        if ($t['status'] === 'concluido') $feitas[] = $t; else $pendentes[] = $t;
    }
} catch (Exception $e) {}
$lista = $aba === 'feitas' ? $feitas : $pendentes;

$categorias = [
    'criativo'  => '🎨 Criativo',
    'copy'      => '✍️ Copy',
    'material'  => '📁 Material',
    'trafego'   => '📊 Tráfego',
    'reuniao'   => '🗓️ Reunião',
    'outro'     => '📌 Outro',
];
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demandas — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
.wrap { max-width: 620px; margin: 0 auto; padding: 22px 16px 60px; }
.campo { margin-bottom: 15px; }
.campo label { display:block; font-size:12px; color:var(--mt); margin-bottom:5px; font-weight:600 }
.campo input, .campo select, .campo textarea { width:100%; font-size:15px; padding:11px 13px }
.campo textarea { min-height:72px; resize:vertical }
.chips { display:flex; gap:7px; flex-wrap:wrap }
.chip { padding:7px 13px; border-radius:20px; border:1px solid var(--bd); background:transparent;
        color:var(--mt2); font-size:13px; cursor:pointer; font-family:inherit }
.chip.on { background:var(--ac); border-color:var(--ac); color:#fff; font-weight:600 }
.aviso { padding:12px 15px; border-radius:11px; margin-bottom:16px; font-size:14px }
.aviso.ok   { background:rgba(52,211,153,.1); border:1px solid rgba(52,211,153,.35); color:#34d399 }
.aviso.erro { background:rgba(248,113,113,.1); border:1px solid rgba(248,113,113,.35); color:#f87171 }
.card-demanda { display:flex; align-items:flex-start; gap:11px; background:var(--sf); border:1px solid var(--bd);
                 border-radius:11px; padding:12px 14px; margin-bottom:9px }
.check-demanda { width:21px; height:21px; border-radius:6px; border:1.5px solid var(--bd); background:transparent;
                 color:#fff; cursor:pointer; font-size:12px; line-height:1; padding:0; margin-top:1px }
.check-demanda:hover { border-color:var(--ac) }
.check-demanda.feito { background:var(--gn,#34d399); border-color:var(--gn,#34d399) }
.badge-st { font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; white-space:nowrap }
.st-p { background:rgba(255,255,255,.07); color:var(--mt2) }
.st-a { background:rgba(251,191,36,.12); color:#fbbf24 }
.st-c { background:rgba(52,211,153,.12); color:#34d399 }
@media(min-width:760px){ .duas { display:grid; grid-template-columns:1fr 1fr; gap:14px } }
</style>
</head>
<body>
<div class="app-wrap">
<?php $currentPage = 'nova_demanda'; require __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
<div class="wrap">

  <h1 class="page-title" style="font-size:22px">📥 Demandas</h1>
  <p class="page-sub" style="margin-bottom:18px">Cadastre o que precisa ser feito e acompanhe o que já saiu.</p>

  <?php if ($msg): ?>
  <div class="aviso <?= $msgTipo === 'erro' ? 'erro' : 'ok' ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <?php if (!$eventos): ?>
    <div class="aviso erro">Você ainda não tem nenhum evento liberado. Peça ao administrador.</div>
  <?php else: ?>

  <details id="formDemanda" <?= (!$pendentes && !$feitas) || $msg ? 'open' : '' ?>
           style="background:var(--sf);border:1px solid var(--bd);border-radius:13px;padding:14px 16px;margin-bottom:6px">
    <summary style="cursor:pointer;font-size:14px;font-weight:700;list-style:none;display:flex;align-items:center;gap:8px">
      ➕ Cadastrar nova demanda
      <span style="margin-left:auto;font-size:11px;color:var(--mt);font-weight:400">clique para abrir</span>
    </summary>
    <div style="height:14px"></div>
  <form method="POST" autocomplete="off">
    <?= Auth::csrfField() ?>

    <div class="campo">
      <label>O que precisa ser feito? *</label>
      <input type="text" name="titulo" required autofocus maxlength="300"
             placeholder="Ex.: 3 criativos novos para a virada de lote"
             value="<?= htmlspecialchars($_POST['titulo'] ?? '') ?>">
    </div>

    <div class="campo">
      <label>Evento *</label>
      <select name="event_id" required>
        <option value="">— escolha —</option>
        <?php foreach ($eventos as $ev): ?>
        <option value="<?= $ev['id'] ?>" <?= $ultimoEvento == $ev['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($ev['name']) ?><?= $ev['date_event'] ? ' · ' . date('d/m', strtotime($ev['date_event'])) : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="campo">
      <label>Link (Drive, Canva, referência…)</label>
      <input type="url" name="link" placeholder="https://drive.google.com/..."
             value="<?= htmlspecialchars($_POST['link'] ?? '') ?>">
    </div>

    <div class="duas">
      <div class="campo">
        <label>Prazo</label>
        <input type="date" name="prazo" value="<?= htmlspecialchars($_POST['prazo'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="campo">
        <label>Tipo</label>
        <select name="categoria">
          <?php foreach ($categorias as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= ($_POST['categoria'] ?? 'criativo') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="campo">
      <label>Prioridade</label>
      <div class="chips" id="chipsPrio">
        <?php foreach (['alta'=>'🔴 Alta','media'=>'🟡 Normal','baixa'=>'⚪ Quando der'] as $k => $lbl): ?>
        <button type="button" class="chip <?= ($_POST['prioridade'] ?? 'media') === $k ? 'on' : '' ?>"
                data-v="<?= $k ?>" onclick="escolhePrio(this)"><?= $lbl ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="prioridade" id="prioridade" value="<?= htmlspecialchars($_POST['prioridade'] ?? 'media') ?>">
    </div>

    <div class="campo">
      <label>Observações</label>
      <textarea name="obs" placeholder="Detalhes, referências, o que não pode faltar…"><?= htmlspecialchars($_POST['obs'] ?? '') ?></textarea>
    </div>

    <button type="submit" name="criar" value="1" class="btn btn-p" style="width:100%;padding:13px;font-size:15px">
      ➕ Cadastrar demanda
    </button>
  </form>
  </details>

  <?php endif; ?>

  <div style="margin-top:34px">
    <div style="display:flex;gap:8px;margin-bottom:14px">
      <a href="?aba=pendentes" style="padding:8px 15px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;
         border:1px solid <?= $aba==='pendentes'?'var(--ac)':'var(--bd)' ?>;
         background:<?= $aba==='pendentes'?'var(--ac)':'transparent' ?>;color:<?= $aba==='pendentes'?'#fff':'var(--mt2)' ?>">
         ⏳ Pendentes <span style="opacity:.8"><?= count($pendentes) ?></span></a>
      <a href="?aba=feitas" style="padding:8px 15px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;
         border:1px solid <?= $aba==='feitas'?'var(--ac)':'var(--bd)' ?>;
         background:<?= $aba==='feitas'?'var(--ac)':'transparent' ?>;color:<?= $aba==='feitas'?'#fff':'var(--mt2)' ?>">
         ✓ Feitas <span style="opacity:.8"><?= count($feitas) ?></span></a>
    </div>

    <?php if (!$lista): ?>
      <div style="text-align:center;padding:34px 16px;color:var(--mt);font-size:14px">
        <?= $aba === 'feitas' ? 'Nada concluído ainda.' : '🎉 Nenhuma demanda pendente.' ?>
      </div>
    <?php endif; ?>

    <?php
    $hoje = date('Y-m-d');
    $catIco = ['criativo'=>'🎨','copy'=>'✍️','material'=>'📁','trafego'=>'📊','reuniao'=>'🗓️','outro'=>'📌'];
    foreach ($lista as $d):
      $feita    = $d['status'] === 'concluido';
      $atrasada = !$feita && $d['due_date'] && $d['due_date'] < $hoje;
      $prazoTxt = '';
      if ($d['due_date']) {
          $dias = (int)floor((strtotime($d['due_date']) - strtotime($hoje)) / 86400);
          $prazoTxt = date('d/m', strtotime($d['due_date']))
              . ($feita ? '' : ($dias === 0 ? ' · hoje' : ($dias === 1 ? ' · amanhã'
              : ($dias > 0 ? " · em {$dias}d" : ' · ' . abs($dias) . 'd atrasada'))));
      }
    ?>
    <div class="card-demanda" style="border-left:3px solid <?= $feita ? 'var(--gn,#34d399)' : ($atrasada ? '#f87171' : ($d['priority']==='alta' ? '#fbbf24' : 'var(--bd)')) ?>">
      <form method="POST" style="margin:0;flex:0 0 auto"><?= Auth::csrfField() ?>
        <button type="submit" name="alternar" value="<?= $d['id'] ?>" class="check-demanda <?= $feita ? 'feito' : '' ?>"
                title="<?= $feita ? 'Reabrir' : 'Marcar como feita' ?>"><?= $feita ? '✓' : '' ?></button>
      </form>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:600;<?= $feita ? 'text-decoration:line-through;opacity:.5' : '' ?>">
          <?= $catIco[$d['category']] ?? '📌' ?> <?= htmlspecialchars($d['title']) ?>
        </div>
        <div style="font-size:11.5px;color:var(--mt);margin-top:3px;display:flex;gap:9px;flex-wrap:wrap;align-items:center">
          <span>🎪 <?= htmlspecialchars($d['evento']) ?></span>
          <?php if ($prazoTxt): ?><span style="color:<?= $atrasada ? '#f87171' : 'var(--mt)' ?>;font-weight:<?= $atrasada ? '600' : '400' ?>">📅 <?= $prazoTxt ?></span><?php endif; ?>
          <?php if ($d['priority'] === 'alta' && !$feita): ?><span style="color:#fbbf24">🔴 alta</span><?php endif; ?>
          <?php if ($d['link']): ?><a href="<?= htmlspecialchars($d['link']) ?>" target="_blank" style="color:var(--ac);text-decoration:none">🔗 abrir link</a><?php endif; ?>
          <?php if ($feita && $d['done_at']): ?><span style="color:var(--gn,#34d399)">✓ <?= date('d/m', strtotime($d['done_at'])) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($d['notes'])): ?>
        <div style="font-size:11.5px;color:var(--mt);margin-top:4px;opacity:.85"><?= nl2br(htmlspecialchars(mb_substr($d['notes'], 0, 180))) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div>
</main>
</div>
<script>
function escolhePrio(btn) {
  document.querySelectorAll('#chipsPrio .chip').forEach(function (c) { c.classList.remove('on'); });
  btn.classList.add('on');
  document.getElementById('prioridade').value = btn.dataset.v;
}
</script>
</body>
</html>