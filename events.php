<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
Auth::requireLogin();
$role    = Auth::getRole();
$isAdmin = in_array($role, ['admin','chefe']);
if (!in_array($role, ['admin','chefe','marketing','financeiro','user'])) { header('Location: index.php'); exit; }

$db       = Database::getInstance();
$accounts = $isAdmin ? $db->fetchAll("SELECT account_id, account_name FROM ad_accounts ORDER BY account_name") : [];
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Eventos — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
/* Modo privado: esconde valores para gravar story/print sem expor números */
body.privado .sensivel {
  filter: blur(7px);
  user-select: none;
  transition: filter .15s;
}
body.privado .sensivel:hover { filter: blur(3px); }
.btn-privado {
  display:flex;align-items:center;justify-content:center;
  width:36px;height:36px;border:1px solid var(--bd);border-radius:9px;
  background:none;color:var(--mt2);cursor:pointer;font-size:15px;transition:all .15s;
}
.btn-privado:hover { background:var(--s2);color:var(--tx); }
body.privado .btn-privado { background:rgba(130,110,255,.12);border-color:rgba(130,110,255,.4);color:#826eff; }

.page-title{font-size:22px;font-weight:700;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--mt2);margin-bottom:22px}
.hrow{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap}

/* FILTER TABS */
.filter-tabs{display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap}
.ftab{padding:7px 16px;border-radius:7px;border:1px solid var(--bd);background:var(--s2);color:var(--mt2);font-family:'Inter',sans-serif;font-size:12px;font-weight:500;cursor:pointer;transition:all .15s}
.ftab:hover{border-color:var(--ac);color:var(--tx)}
.ftab.active{background:var(--ac);border-color:var(--ac);color:#fff;font-weight:600}

/* EVENTS GRID */
.events-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-bottom:24px}
.ev-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;overflow:hidden;text-decoration:none;color:inherit;display:block;transition:border-color .15s,transform .15s}
.ev-card:hover{border-color:var(--ac);transform:translateY(-1px)}
.ev-banner{height:6px;background:linear-gradient(90deg,var(--ac),#826eff)}
.ev-banner.finished{background:var(--mt)}
.ev-body{padding:16px 18px;display:flex;flex-direction:column;gap:10px}
.ev-name{font-size:15px;font-weight:700;color:var(--tx)}
.ev-date{font-size:12px;color:var(--mt2)}
.ev-timeline{display:flex;flex-direction:column;gap:4px}
.ev-timeline-bar{height:4px;background:var(--s2);border-radius:2px;overflow:hidden}
.ev-timeline-fill{height:100%;background:var(--ac);border-radius:2px;transition:width .3s}
.ev-timeline-labels{display:flex;justify-content:space-between;font-size:10px;color:var(--mt)}
.ev-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ev-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:5px;font-size:11px;font-weight:600}
.badge-active{background:rgba(62,207,142,.1);color:var(--gn)}
.badge-finished{background:rgba(148,148,163,.1);color:var(--mt2)}
.badge-pending{background:rgba(245,166,35,.1);color:var(--yw)}
.ev-lot{background:var(--s2);border-radius:9px;padding:9px 12px}
.ev-lot-label{font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--mt);margin-bottom:3px}
.ev-lot-name{font-size:12px;font-weight:600;color:var(--tx)}
.ev-perf{background:var(--s2);border-radius:9px;padding:10px 12px;display:flex;flex-direction:column;gap:7px}
.ev-perf-row{display:flex;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--mt2)}
.ev-perf-row strong{color:var(--tx)}
.ev-budget{display:flex;flex-direction:column;gap:4px}
.ev-budget-bar{height:4px;background:var(--bd);border-radius:2px;overflow:hidden}
.ev-budget-fill{height:100%;border-radius:2px}
.ev-budget-lbl{font-size:11px;color:var(--mt2)}

/* EMPTY */
.empty{text-align:center;padding:60px;color:var(--mt)}
.empty-icon{font-size:36px;margin-bottom:12px}

/* BOTÕES */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:8px;font-family:'Inter',sans-serif;font-weight:600;font-size:13px;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-p{background:var(--ac);color:#fff}.btn-p:hover{opacity:.85}
.btn-o{background:var(--s2);border:1px solid var(--bd);color:var(--mt2)}.btn-o:hover{border-color:var(--ac);color:var(--ac)}
.btn-d{background:rgba(240,100,90,.1);border:1px solid rgba(240,100,90,.2);color:var(--rd)}.btn-d:hover{background:rgba(240,100,90,.2)}
.btn-sm{padding:5px 10px;font-size:11px}

/* MODAL */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1000;align-items:center;justify-content:center;padding:20px}
.modal-bg.open{display:flex}
.modal{background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:26px;width:100%;max-width:480px;max-height:88vh;overflow-y:auto}
.modal h2{font-size:17px;font-weight:700;margin-bottom:18px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--mt);margin-bottom:5px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:9px 12px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-family:'Inter',sans-serif;font-size:13px;outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--ac)}
.form-group textarea{min-height:72px;resize:vertical}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.modal-actions{display:flex;gap:8px;margin-top:18px;justify-content:flex-end}
.user-checkboxes{display:flex;flex-direction:column;gap:5px;max-height:140px;overflow-y:auto;padding:4px 0}
.user-cb{display:flex;align-items:center;gap:8px;padding:6px 10px;background:var(--s2);border-radius:7px;cursor:pointer;font-size:13px}
.user-cb input{width:14px;height:14px;accent-color:var(--ac);cursor:pointer}
</style>
</head>
<body>
<?php $currentPage='events'; $sidebarBase=''; require __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
  <div class="hrow">
    <div>
      <h1 class="page-title">📅 Eventos</h1>
      <p class="page-sub">Planejamento e gestão de eventos <span id="activeEventsSummary" style="margin-left:6px;color:var(--ac);font-weight:600"></span></p>
    </div>
    <div style="display:flex;align-items:center;gap:9px">
      <button type="button" class="btn-privado" id="btnPrivado" onclick="togglePrivado()"
              title="Ocultar valores (para gravar story ou compartilhar tela)">👁️</button>
      <?php if ($isAdmin): ?>
      <button type="button" class="btn btn-p" onclick="openCreateModal()">➕ Novo Evento</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="filter-tabs">
    <button type="button" class="ftab" onclick="filterEvents('all',this)">Todos</button>
    <button type="button" class="ftab active" onclick="filterEvents('active',this)">Ativos</button>
    <button type="button" class="ftab" onclick="filterEvents('finished',this)">Encerrados</button>
  </div>

  <div id="eventsGrid" class="events-grid">
    <div class="empty"><div class="empty-icon">⏳</div><div>Carregando...</div></div>
  </div>
</main>

<!-- MODAL CRIAR EVENTO -->
<div class="modal-bg" id="modalCreate">
  <div class="modal">
    <h2>➕ Novo Evento</h2>
    <div class="form-group">
      <label>Nome do evento *</label>
      <input type="text" id="evName" placeholder="Ex: Baile Lotadão BH — Julho 2026">
    </div>
    <div class="form-group">
      <label>Descrição</label>
      <textarea id="evDesc" placeholder="Detalhes do evento..."></textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Data do evento *</label>
        <input type="date" id="evDate">
      </div>
      <div class="form-group">
        <label>Início das campanhas</label>
        <input type="date" id="evCampStart">
      </div>
    </div>
    <div class="form-group">
      <label>Fim das campanhas</label>
      <input type="date" id="evCampEnd">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Conta de anúncios</label>
        <select id="evAccount">
          <option value="">Sem conta vinculada</option>
          <?php foreach ($accounts as $a): ?>
          <option value="<?= htmlspecialchars($a['account_id']) ?>"><?= htmlspecialchars($a['account_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Budget total R$</label>
        <input type="number" id="evBudget" placeholder="Ex: 50000" min="0" step="100">
      </div>
    </div>
    <div class="form-group">
      <label>Usuários com acesso</label>
      <div class="user-checkboxes" id="userCheckboxes">
        <div style="color:var(--mt2);font-size:13px">Carregando...</div>
      </div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-o" onclick="closeModal('modalCreate')">Cancelar</button>
      <button type="button" class="btn btn-p" id="btnCreateEvent">✅ Criar Evento</button>
    </div>
  </div>
</div>

<script>
var ALL_EVENTS = [], ALL_USERS = [];
var IS_ADMIN   = <?= $isAdmin ? 'true' : 'false' ?>;
var currentFilter = 'active';

async function api(action, body) {
  var r = await fetch('api/events.php?action='+action, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({action:action},body||{}))});
  return r.json();
}

async function loadEvents() {
  var d = await api('list_events');
  ALL_EVENTS = d.events || [];
  updateActiveEventsSummary();
  await loadAllPerformance();
  renderEvents();
  loadAllProjections();
}

function updateActiveEventsSummary() {
  var summary = document.getElementById('activeEventsSummary');
  if (!summary) return;
  var active = ALL_EVENTS.filter(function (e) { return e.status === 'active'; }).length;
  summary.textContent = '• Você tem ' + active + ' evento' + (active === 1 ? '' : 's') + ' ativo' + (active === 1 ? '' : 's') + '.';
}

async function loadAllProjections() {
  // Uma única requisição para todos os eventos (o cálculo é feito pelo cron
  // warm_projections.php). Antes era uma requisição por card, o que estourava
  // o limite de conexões simultâneas do banco e derrubava tudo com erro 500.
  var events = ALL_EVENTS.filter(function(e){ return parseFloat(e.budget) > 0 && e.status === 'active'; });
  if (!events.length) return;

  try {
    var r = await fetch('api/events.php?action=list_projections', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'list_projections'})
    });
    if (!r.ok) {
      var t = await r.text();
      throw new Error('HTTP ' + r.status + ' — ' + t.slice(0, 120));
    }
    var d = await r.json();
    if (!d.success) throw new Error(d.error || 'falhou');

    events.forEach(function(e) {
      var p = d.projections[e.id];
      if (p) {
        e.projection = p;
        try { updateCardOverflowBadge(e); }
        catch (err) { e.projFalhou = 'erro ao exibir: ' + err.message; marcarProjFalha(e); }
      } else {
        e.projFalhou = 'ainda não calculada (o cron roda a cada 20 min)';
        marcarProjFalha(e);
      }
    });
  } catch (err) {
    events.forEach(function(e) { e.projFalhou = err.message; marcarProjFalha(e); });
  }
}

function marcarProjFalha(e) {
  var card = document.querySelector('[data-event-id="'+e.id+'"]');
  var slot = card ? card.querySelector('.ev-overflow-slot') : null;
  if (slot) var msg = (e.projFalhou || 'erro').toString();
  slot.innerHTML = '<span style="font-size:10px;color:#f87171;cursor:help" title="'+msg.replace(/"/g,'')+'">⚠ projeção: '+msg.slice(0,28)+'</span>';
  console.warn('[projeção] evento '+e.id+' ('+e.name+'):', msg);
}

function buildOverflowBadgeHtml(p) {
  if (!p) return '';
  if (p.will_overflow) return '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;font-size:10px;font-weight:700;background:rgba(240,100,90,.15);color:var(--rd);white-space:nowrap">🔴 Projeção estoura <span class="sensivel">R$'+fmtN(p.overflow_by)+'</span></span>';
  return '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;font-size:10px;font-weight:700;background:rgba(62,207,142,.1);color:var(--gn);white-space:nowrap">🟢 Dentro da margem</span>';
}

function updateCardOverflowBadge(e) {
  var slot = document.querySelector('[data-event-id="'+e.id+'"] .ev-overflow-slot');
  if (slot) slot.innerHTML = buildOverflowBadgeHtml(e.projection);
}

function togglePrivado() {
  var on = document.body.classList.toggle('privado');
  try { localStorage.setItem('md_privado', on ? '1' : '0'); } catch (e) {}
  var b = document.getElementById('btnPrivado');
  if (b) {
    b.textContent = on ? '🙈' : '👁️';
    b.title = on ? 'Mostrar valores' : 'Ocultar valores (para gravar story ou compartilhar tela)';
  }
}

(function aplicaPrivadoSalvo() {
  try {
    if (localStorage.getItem('md_privado') === '1') {
      document.addEventListener('DOMContentLoaded', function () {
        document.body.classList.add('privado');
        var b = document.getElementById('btnPrivado');
        if (b) { b.textContent = '🙈'; b.title = 'Mostrar valores'; }
      });
    }
  } catch (e) {}
})();

async function loadAllPerformance() {
  // Uma requisição só para todos os eventos. Antes era uma por card, o que
  // esgotava as conexões do MySQL no servidor (PDOException 2002).
  try {
    var r = await fetch('api/events.php?action=list_performance', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'list_performance'})
    });
    if (!r.ok) {
      var t = await r.text();
      console.warn('[performance] HTTP ' + r.status + ' — ' + t.slice(0, 200));
      return;
    }
    var d = await r.json();
    if (!d.success) { console.warn('[performance]', d.error || 'falhou'); return; }

    ALL_EVENTS.forEach(function(e) {
      var p = d.performance[e.id];
      if (!p) return;
      e.perf        = p.perf;
      e.budget      = p.budget;
      e.budget_pct  = p.budget_pct;
      e.budget_left = p.budget_left;
    });
  } catch (err) {
    console.warn('[performance] erro:', err.message);
  }
}

async function loadUsers() {
  var d = await api('get_users');
  ALL_USERS = d.users || [];
  var box = document.getElementById('userCheckboxes');
  if (!ALL_USERS.length) { box.innerHTML='<div style="color:var(--mt2);font-size:13px">Nenhum usuário disponível</div>'; return; }
  var rb = {chefe:'👑',financeiro:'💰',marketing:'🎨',user:'👤'};
  box.innerHTML = ALL_USERS.map(function(u){
    return '<label class="user-cb"><input type="checkbox" value="'+u.id+'"><span>'+(rb[u.role]||'👤')+' '+esc(u.username)+'</span></label>';
  }).join('');
}

function renderEvents() {
  var grid = document.getElementById('eventsGrid');
  var filtered = ALL_EVENTS.filter(function(e){
    if (currentFilter==='active')   return e.status==='active';
    if (currentFilter==='finished') return e.status==='finished';
    return true;
  });
  if (!filtered.length) { grid.innerHTML='<div class="empty"><div class="empty-icon">📭</div><div>Nenhum evento encontrado</div></div>'; return; }

  grid.innerHTML = filtered.map(function(e){
    var pct=0;
    if (e.date_campaign_start && e.date_event) {
      var s=new Date(e.date_campaign_start).getTime(), en=new Date(e.date_event).getTime(), now=Date.now();
      pct=Math.min(100,Math.max(0,Math.round((now-s)/(en-s)*100)));
    }
    var evDate=e.date_event?new Date(e.date_event+'T00:00:00').toLocaleDateString('pt-BR'):'—';
    var daysLeft='';
    if (e.date_event){var diff=Math.ceil((new Date(e.date_event+'T00:00:00')-Date.now())/86400000);daysLeft=diff>0?diff+' dias':(diff===0?'Hoje!':'Encerrado');}
    var lotHtml='';
    if (e.next_lot){var lDate=new Date(e.next_lot.lot_date+'T00:00:00').toLocaleDateString('pt-BR');var lPrice=e.next_lot.price?' — <span class="sensivel">R$'+parseFloat(e.next_lot.price).toLocaleString('pt-BR',{minimumFractionDigits:2})+'</span>':'';lotHtml='<div class="ev-lot"><div class="ev-lot-label">Próxima virada</div><div class="ev-lot-name">'+esc(e.next_lot.name)+lPrice+' · '+lDate+'</div></div>';}
    var statusBadge=e.status==='finished'?'<span class="ev-badge badge-finished">● Encerrado</span>':'<span class="ev-badge badge-active">● Ativo</span>';
    var taskBadge=e.pending_tasks>0?'<span class="ev-badge badge-pending">'+e.pending_tasks+' pendentes</span>':'';
    var projBadge=e.projection?buildOverflowBadgeHtml(e.projection):'<span style="font-size:10px;color:var(--mt2)">⏳ projeção…</span>';
    var op = e.oportunidades || {escalar:0, pausar:0};
    var opHtml = '';
    var moedasEv = (e.moedas || []).filter(function(m){ return m && m !== 'BRL'; });
    if (moedasEv.length) {
      opHtml += '<span title="Conta em ' + moedasEv.join(', ') + ' — valores convertidos para reais" '
        + 'style="display:inline-flex;align-items:center;gap:3px;background:rgba(251,191,36,.12);border:1px solid rgba(251,191,36,.35);'
        + 'color:#fbbf24;border-radius:20px;padding:1px 8px;font-size:10.5px;font-weight:700;margin-right:4px">💱 ' + moedasEv.join('/') + '</span>';
    }
    if (op.escalar > 0) opHtml += '<span title="'+op.escalar+' campanha(s) com desempenho acima da média — dá pra escalar" style="display:inline-flex;align-items:center;gap:3px;background:rgba(52,211,153,.13);border:1px solid rgba(52,211,153,.35);color:#34d399;border-radius:20px;padding:1px 8px;font-size:10.5px;font-weight:700">🚀 '+op.escalar+'</span>';
    if (op.pausar > 0)  opHtml += '<span title="'+op.pausar+' campanha(s) gastando mal — vale pausar" style="display:inline-flex;align-items:center;gap:3px;background:rgba(248,113,113,.13);border:1px solid rgba(248,113,113,.35);color:#f87171;border-radius:20px;padding:1px 8px;font-size:10.5px;font-weight:700;margin-left:4px">🛑 '+op.pausar+'</span>';
    var perfHtml='';
    if (e.perf){
      var sp=parseFloat(e.perf.spend)||0,pu=parseInt(e.perf.purchases)||0,le=parseInt(e.perf.leads)||0,rv=parseFloat(e.perf.revenue)||0;
      var roas=sp>0?(rv/sp).toFixed(1):0,budget=parseFloat(e.budget)||0,bPct=e.budget_pct,bLeft=e.budget_left;
      perfHtml='<div class="ev-perf"><div class="ev-perf-row">';
      if(pu>0)perfHtml+='<span>🛒 <strong class="sensivel">'+pu+'</strong></span>';
      if(le>0)perfHtml+='<span>📋 <strong class="sensivel">'+le+'</strong></span>';
      if(rv>0)perfHtml+='<span>💰 <strong class="sensivel">R$'+fmtN(rv)+'</strong></span>';
      if(roas>0)perfHtml+='<span>📈 <strong class="sensivel">'+roas+'x</strong></span>';
      if(sp>0)perfHtml+='<span>💸 <strong class="sensivel">R$'+fmtN(sp)+'</strong></span>';
      perfHtml+='</div>';
      if(budget>0&&bPct!==null){var fc=bPct>=90?'var(--rd)':bPct>=70?'var(--yw)':'var(--gn)';perfHtml+='<div class="ev-budget"><div class="ev-budget-bar"><div class="ev-budget-fill" style="width:'+Math.min(100,bPct)+'%;background:'+fc+'"></div></div><div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px"><div class="ev-budget-lbl"><span class="sensivel">'+bPct+'% gasto · R$'+fmtN(bLeft)+' restante</span></div><span class="ev-overflow-slot">'+projBadge+'</span></div></div>';}
      perfHtml+='</div>';
    }
    return '<a class="ev-card" data-event-id="'+e.id+'" href="event.php?id='+e.id+'">'+
      '<div class="ev-banner'+(e.status==='finished'?' finished':'')+'"></div>'+
      '<div class="ev-body">'+
        '<div class="ev-name">'+esc(e.name)+'</div>'+
        '<div class="ev-date">📅 '+evDate+(daysLeft?' · <strong>'+daysLeft+'</strong>':'')+'</div>'+
        (e.date_campaign_start?'<div class="ev-timeline"><div class="ev-timeline-bar"><div class="ev-timeline-fill" style="width:'+pct+'%"></div></div><div class="ev-timeline-labels"><span>Campanhas</span><span>'+pct+'%</span><span>Evento</span></div></div>':'')+
        '<div class="ev-meta">'+
          '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">'+statusBadge+taskBadge+opHtml+'</div>'+
          (IS_ADMIN?'<div style="display:flex;gap:5px;margin-left:auto"><button type="button" class="btn btn-o btn-sm" title="Duplicar" onclick="event.preventDefault();duplicateEvent('+e.id+',\''+esc(e.name)+'\')" style="padding:5px 8px">📋</button><button type="button" class="btn btn-d btn-sm" onclick="event.preventDefault();deleteEvent('+e.id+',\''+esc(e.name)+'\')">🗑️</button></div>':'')+
        '</div>'+
        perfHtml+lotHtml+
      '</div>'+
    '</a>';
  }).join('');
}

function filterEvents(f,btn){ currentFilter=f; document.querySelectorAll('.ftab').forEach(function(b){b.classList.remove('active');}); btn.classList.add('active'); renderEvents(); }

async function openCreateModal(){
  document.getElementById('evName').value='';document.getElementById('evDesc').value='';
  document.getElementById('evDate').value='';document.getElementById('evCampStart').value='';
  document.getElementById('evCampEnd').value='';
  var ea=document.getElementById('evAccount');var eb=document.getElementById('evBudget');
  if(ea)ea.value='';if(eb)eb.value='';
  await loadUsers();
  document.getElementById('modalCreate').classList.add('open');
}

async function createEvent(){
  var name=document.getElementById('evName').value.trim();
  var date=document.getElementById('evDate').value;
  if(!name||!date){alert('Nome e data do evento são obrigatórios');return;}
  var userIds=[],checked=document.querySelectorAll('#userCheckboxes input[type="checkbox"]:checked');
  for(var ci=0;ci<checked.length;ci++){var v=parseInt(checked[ci].value);if(!isNaN(v))userIds.push(v);}
  var eaEl=document.getElementById('evAccount'),ebEl=document.getElementById('evBudget');
  var acctVal=eaEl?eaEl.value:'',budgetVal=ebEl?parseFloat(ebEl.value)||0:0;
  var payload={name:name,description:document.getElementById('evDesc').value||'',date_event:date,
    date_campaign_start:document.getElementById('evCampStart').value||null,
    date_campaign_end:document.getElementById('evCampEnd').value||null,
    account_ids:acctVal?[acctVal]:[],budget:budgetVal,user_ids:userIds};
  try {
    var r=await fetch('api/events.php?action=create_event',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({action:'create_event'},payload))});
    var text=await r.text();var d=JSON.parse(text);
    if(!d||d.error){alert('Erro: '+(d?d.error:'sem resposta'));return;}
    closeModal('modalCreate');window.location.href='event.php?id='+d.id;
  } catch(e){alert('Erro de conexão: '+e.message);}
}

async function deleteEvent(id,name){
  if(!confirm('Excluir o evento "'+name+'"? Remove todas as tarefas, materiais e lotes.'))return;
  var d=await api('delete_event',{id:id});
  if(d.error){alert(d.error);return;}
  ALL_EVENTS=ALL_EVENTS.filter(function(e){return e.id!=id;});renderEvents();
}

function closeModal(id){document.getElementById(id).classList.remove('open');}
function fmtN(n){return parseFloat(n||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

document.addEventListener('DOMContentLoaded',function(){
  var btn=document.getElementById('btnCreateEvent');
  if(btn)btn.addEventListener('click',function(){createEvent();});
});
loadEvents();
</script>
</body>
</html>