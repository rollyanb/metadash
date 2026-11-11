<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
Auth::requireLogin();
if (!in_array(Auth::getRole(), ['admin','chefe','marketing'])) { header('Location: index.php'); exit; }

$role            = Auth::getRole();
$db              = Database::getInstance();
$allowedAccounts = Auth::getAllowedAccounts();
$isAdmin         = in_array($role, ['admin','chefe']);

if ($allowedAccounts === null) {
    $accounts = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE status IN ('ACTIVE','INACTIVE') ORDER BY account_name");
} elseif (!empty($allowedAccounts)) {
    $ph = implode(',', array_fill(0, count($allowedAccounts), '?'));
    $accounts = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE account_id IN ({$ph}) AND status IN ('ACTIVE','INACTIVE') ORDER BY account_name", $allowedAccounts);
} else { $accounts = []; }
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Conversões — MetaDash</title>
<?php echoThemeAssets(''); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.page-title{font-size:22px;font-weight:700;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--mt2);margin-bottom:22px}

/* FILTROS */
.filter-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px 20px;margin-bottom:22px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}
.fg{display:flex;flex-direction:column;gap:5px}
.fg label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--mt)}
.fg select,.fg input{padding:8px 12px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-family:'Inter',sans-serif;font-size:13px;outline:none;min-width:140px;transition:border-color .2s}
.fg select:focus,.fg input:focus{border-color:var(--ac)}
.btn-buscar{padding:9px 20px;background:var(--ac);color:#fff;border:none;border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:opacity .15s;align-self:flex-end;white-space:nowrap}
.btn-buscar:hover{opacity:.85}

/* ESTADO */
.state-box{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:56px;text-align:center;color:var(--mt2)}
@keyframes spin{to{transform:rotate(360deg)}}
.spinner{width:36px;height:36px;border:3px solid var(--bd);border-top-color:var(--ac);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 14px}

/* FUNIL */
.funnel-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:24px;margin-bottom:18px}
.card-title{font-size:14px;font-weight:700;color:var(--tx);margin-bottom:20px;display:flex;align-items:center;gap:8px}
.funnel{display:flex;align-items:stretch;gap:0;overflow-x:auto;padding-bottom:4px}
.funnel-step{flex:1;min-width:120px;position:relative}
.funnel-step+.funnel-step{padding-left:24px}
.funnel-step+.funnel-step::before{content:'→';position:absolute;left:4px;top:50%;transform:translateY(-50%);font-size:18px;color:var(--mt);pointer-events:none}
.fs-inner{background:var(--s2);border:1px solid var(--bd);border-radius:10px;padding:16px 12px;text-align:center;cursor:pointer;transition:border-color .15s,background .15s;height:100%;position:relative;overflow:hidden}
.fs-inner::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;border-radius:0 0 10px 10px}
.fs-views    .fs-inner::after{background:var(--ac)}
.fs-cart     .fs-inner::after{background:#826eff}
.fs-checkout .fs-inner::after{background:var(--yw)}
.fs-purchase .fs-inner::after{background:var(--gn)}
.fs-revenue  .fs-inner::after{background:#f59e0b}
.fs-leads    .fs-inner::after{background:#fb923c}
.fs-inner:hover{border-color:var(--ac)}
.fs-inner.active{border-color:var(--ac);background:rgba(84,112,255,.08)}
.fs-icon{font-size:20px;margin-bottom:6px}
.fs-label{font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--mt2);margin-bottom:5px;font-weight:600}
.fs-value{font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:700;line-height:1.1}
.fs-views    .fs-value{color:var(--ac)}
.fs-cart     .fs-value{color:#826eff}
.fs-checkout .fs-value{color:var(--yw)}
.fs-purchase .fs-value{color:var(--gn)}
.fs-revenue  .fs-value{color:#f59e0b}
.fs-leads    .fs-value{color:#fb923c}
.fs-conv{font-size:10px;color:var(--mt2);margin-top:3px}
.fs-cost{font-size:10px;color:var(--mt);margin-top:2px}

/* GRÁFICO */
.chart-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:22px;margin-bottom:18px}
.metric-btns{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.mbtn{padding:5px 13px;border-radius:20px;border:1px solid var(--bd);background:transparent;color:var(--mt2);font-family:'Inter',sans-serif;font-size:12px;cursor:pointer;transition:all .15s}
.mbtn:hover{border-color:var(--ac);color:var(--ac)}
.mbtn.active{border-color:var(--ac);background:rgba(84,112,255,.12);color:var(--tx);font-weight:600}

/* CARDS POR CONTA */
.section-title{font-size:15px;font-weight:700;color:var(--tx);margin-bottom:14px;margin-top:4px}
.accounts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;margin-bottom:22px}
.acc-card{background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:18px;transition:border-color .15s}
.acc-card:hover{border-color:rgba(84,112,255,.3)}
.acc-name{font-size:13px;font-weight:700;color:var(--tx);margin-bottom:12px}
.acc-funnel{display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-bottom:12px}
.af-step{text-align:center}
.af-v{font-family:'JetBrains Mono',monospace;font-size:14px;font-weight:700}
.af-l{font-size:10px;color:var(--mt2);text-transform:uppercase}
.af-arrow{color:var(--mt);font-size:12px;padding:0 2px}
.acc-footer{display:flex;justify-content:space-between;align-items:center;padding-top:10px;border-top:1px solid var(--bd);font-size:12px;color:var(--mt2)}
.acc-roas{font-family:'JetBrains Mono',monospace;font-weight:700;color:#f59e0b;font-size:15px}
.acc-cpa{font-family:'JetBrains Mono',monospace;font-weight:700;color:var(--gn)}
.empty-note{background:var(--s2);border-radius:9px;padding:11px 15px;font-size:12px;color:var(--mt2);margin-bottom:14px}

@media(max-width:768px){
  .filter-card{flex-direction:column;align-items:stretch}
  .btn-buscar{width:100%}
  .accounts-grid{grid-template-columns:1fr}
  .funnel{flex-direction:column;gap:8px}
  .funnel-step+.funnel-step{padding-left:0;padding-top:14px}
  .funnel-step+.funnel-step::before{content:'↓';left:50%;top:-10px;transform:translateX(-50%)}
}
</style>
</head>
<body>
<?php $currentPage='conversions'; $sidebarBase=''; require __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
  <h1 class="page-title">📈 Conversões</h1>
  <p class="page-sub">Funil completo — visitas, carrinho, checkout, compras e receita</p>

  <div class="filter-card">
    <div class="fg">
      <label>Conta</label>
      <select id="selAcc">
        <option value="">Todas as contas</option>
        <?php foreach ($accounts as $a): ?>
        <option value="<?= htmlspecialchars($a['account_id']) ?>"><?= htmlspecialchars($a['account_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg">
      <label>Data início</label>
      <input type="date" id="ds" value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
    </div>
    <div class="fg">
      <label>Data fim</label>
      <input type="date" id="de" value="<?= date('Y-m-d') ?>">
    </div>
    <button type="button" class="btn-buscar" onclick="loadData()">🔍 Buscar</button>
  </div>

  <div id="stateBox" class="state-box">
    <div class="spinner"></div>
    <div>Carregando dados...</div>
  </div>

  <div id="content" style="display:none">
    <div class="funnel-card">
      <div class="card-title">🔽 Funil de Conversão — Total</div>
      <div class="funnel" id="funnelTotal"></div>
    </div>

    <div class="chart-card">
      <div class="card-title">📊 Evolução no período</div>
      <div class="metric-btns" id="metricBtns"></div>
      <canvas id="convChart" height="80"></canvas>
    </div>

    <div>
      <div class="section-title">📋 Por conta de anúncio</div>
      <div class="empty-note" id="noConvNote" style="display:none">⚠️ Algumas contas não possuem pixel configurado e aparecem ocultas.</div>
      <div class="accounts-grid" id="accGrid"></div>
    </div>
  </div>
</main>

<script>
var chartInstance = null;
var dailyData = [], dailyByAccount = [];
var activeMetric = 'purchases';

var COLORS = ['#5470ff','#34d399','#f59e0b','#f87171','#826eff','#fb923c','#38bdf8','#4ade80','#e879f9','#fbbf24','#6366f1','#14b8a6','#ef4444','#8b5cf6','#0ea5e9'];

async function loadData() {
  showState();
  var body = {
    date_start: document.getElementById('ds').value,
    date_end:   document.getElementById('de').value,
    account_id: document.getElementById('selAcc').value
  };
  try {
    var r = await fetch('api/conversions.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    var text = await r.text();
    var d;
    try { d = JSON.parse(text); } catch(e) { showErr('Erro no servidor'); return; }
    if (d.error) { showErr(d.error); return; }
    dailyData = d.daily || [];
    dailyByAccount = d.daily_by_account || [];
    renderFunnel(d.totals || {});
    renderChart(activeMetric);
    renderByAccount(d.by_account || []);
    document.getElementById('stateBox').style.display = 'none';
    document.getElementById('content').style.display  = 'block';
  } catch(e) { showErr('Erro de conexão: ' + e.message); }
}

function renderFunnel(t) {
  var steps = [
    {key:'views',       label:'Visitas',  icon:'👁️', cls:'fs-views',    val:fmtN(t.views),         cost:t.cpv?'R$'+fmtM(t.cpv)+'/visita':'',      conv:''},
    {key:'add_to_cart', label:'Carrinho', icon:'🛒', cls:'fs-cart',     val:fmtN(t.add_to_cart),   cost:t.cpa_cart?'R$'+fmtM(t.cpa_cart)+'/un':'', conv:pct(t.add_to_cart,t.views)},
    {key:'checkout',    label:'Checkout', icon:'💳', cls:'fs-checkout', val:fmtN(t.checkout),       cost:t.cpa_checkout?'R$'+fmtM(t.cpa_checkout)+'/un':'', conv:pct(t.checkout,t.add_to_cart)},
    {key:'purchases',   label:'Compras',  icon:'✅', cls:'fs-purchase', val:fmtN(t.purchases),      cost:t.cpa?'R$'+fmtM(t.cpa)+'/compra':'',       conv:pct(t.purchases,t.checkout)},
    {key:'revenue',     label:'Receita',  icon:'💰', cls:'fs-revenue',  val:'R$'+fmtM(t.revenue),   cost:t.roas?'ROAS '+t.roas+'x':'',               conv:''},
    {key:'leads',       label:'Leads',    icon:'📋', cls:'fs-leads',    val:fmtN(t.leads),          cost:t.cpl?'R$'+fmtM(t.cpl)+'/lead':'',         conv:''},
  ];
  var visible = steps.filter(function(s){
    if (s.key==='revenue') return parseFloat(t.revenue||0) > 0;
    if (s.key==='leads')   return parseInt(t.leads||0) > 0;
    return parseInt(t[s.key]||0) > 0;
  });
  var html = '';
  visible.forEach(function(s) {
    html += '<div class="funnel-step '+s.cls+'">'+
      '<div class="fs-inner'+(s.key===activeMetric?' active':'')+'" onclick="setMetric(\''+s.key+'\')">'+
        '<div class="fs-icon">'+s.icon+'</div>'+
        '<div class="fs-label">'+s.label+'</div>'+
        '<div class="fs-value">'+s.val+'</div>'+
        (s.conv?'<div class="fs-conv">'+s.conv+' conv.</div>':'')+
        (s.cost?'<div class="fs-cost">'+s.cost+'</div>':'')+
      '</div></div>';
  });
  if (!html) html='<div style="color:var(--mt2);padding:20px;font-size:13px">Nenhum dado de conversão no período. Sincronize ou escolha outro intervalo.</div>';
  document.getElementById('funnelTotal').innerHTML = html;

  var btns = '';
  visible.forEach(function(s) {
    btns += '<button type="button" class="mbtn'+(s.key===activeMetric?' active':'')+'" onclick="setMetric(\''+s.key+'\')">'+s.icon+' '+s.label+'</button>';
  });
  document.getElementById('metricBtns').innerHTML = btns;
}

function setMetric(key) {
  activeMetric = key;
  document.querySelectorAll('.mbtn').forEach(function(b){ b.classList.remove('active'); if(b.textContent.trim().includes(metricLabel(key))) b.classList.add('active'); });
  document.querySelectorAll('.fs-inner').forEach(function(el){ el.classList.remove('active'); });
  document.querySelectorAll('.funnel-step').forEach(function(s){
    var oc = s.querySelector('.fs-inner').getAttribute('onclick')||'';
    if (oc.includes("'"+key+"'")) s.querySelector('.fs-inner').classList.add('active');
  });
  renderChart(key);
}

function metricLabel(k){
  var m={views:'Visitas',add_to_cart:'Carrinho',checkout:'Checkout',purchases:'Compras',revenue:'Receita',leads:'Leads'};
  return m[k]||k;
}
function pct(a,b){ if(!b||!a)return''; return (a/b*100).toFixed(1)+'%'; }

function renderChart(metric) {
  if (chartInstance) { chartInstance.destroy(); chartInstance=null; }
  var isMoney = (metric==='revenue');
  var accountMap={};
  dailyByAccount.forEach(function(d){
    if (!accountMap[d.account_id]) accountMap[d.account_id]={name:d.account_name||d.account_id,data:{}};
    accountMap[d.account_id].data[d.snapshot_date]=parseFloat(d[metric]||0);
  });
  var allDates=[], seen={};
  dailyByAccount.forEach(function(d){ if(!seen[d.snapshot_date]){seen[d.snapshot_date]=1;allDates.push(d.snapshot_date);} });
  allDates.sort();
  var labels = allDates.map(formatDate);
  var activeAccts = Object.keys(accountMap).filter(function(k){ return Object.values(accountMap[k].data).some(function(v){return v>0;}); });
  var isDark = document.documentElement.classList.contains('dark');
  var gridColor = isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)';
  var tickColor = isDark ? '#5c5c78' : '#9ca3af';
  var opts = {
    responsive:true, interaction:{mode:'index',intersect:false},
    plugins:{
      legend:{display:true,position:'bottom',labels:{color:tickColor,font:{size:11},boxWidth:12,padding:16}},
      tooltip:{callbacks:{label:function(ctx){return ' '+ctx.dataset.label+': '+(isMoney?'R$ '+fmtM(ctx.parsed.y):ctx.parsed.y);}}}
    },
    scales:{
      x:{grid:{color:gridColor},ticks:{color:tickColor,font:{size:11}}},
      y:{grid:{color:gridColor},ticks:{color:tickColor,font:{size:11},callback:function(v){return isMoney?'R$'+fmtM(v):v;}}}
    }
  };
  if (!activeAccts.length) {
    if (!dailyData.length) return;
    chartInstance=new Chart(document.getElementById('convChart'),{type:'line',data:{labels:dailyData.map(function(d){return formatDate(d.snapshot_date);}),datasets:[{label:'Total',data:dailyData.map(function(d){return parseFloat(d[metric]||0);}),borderColor:'#5470ff',backgroundColor:'rgba(84,112,255,0.08)',borderWidth:2.5,fill:true,tension:0.4,pointRadius:3}]},options:opts});
    return;
  }
  var datasets = activeAccts.map(function(k,i){
    var color=COLORS[i%COLORS.length];
    var rgb=parseInt(color.replace('#',''),16);
    var r=(rgb>>16)&255,g=(rgb>>8)&255,b=rgb&255;
    return {label:accountMap[k].name,data:allDates.map(function(dt){return accountMap[k].data[dt]||0;}),borderColor:color,backgroundColor:'rgba('+r+','+g+','+b+',0.06)',borderWidth:2,fill:false,tension:0.4,pointBackgroundColor:color,pointRadius:3,pointHoverRadius:6};
  });
  chartInstance=new Chart(document.getElementById('convChart'),{type:'line',data:{labels:labels,datasets:datasets},options:opts});
}

function renderByAccount(accounts) {
  var grid=document.getElementById('accGrid');
  var note=document.getElementById('noConvNote');
  grid.innerHTML='';
  var hasHidden=false;
  var visible=accounts.filter(function(a){
    var has=(a.views||0)>0||(a.add_to_cart||0)>0||(a.checkout||0)>0||(a.purchases||0)>0||(a.leads||0)>0||(a.revenue||0)>0;
    if(!has)hasHidden=true;
    return has;
  });
  note.style.display=hasHidden?'block':'none';
  if(!visible.length){grid.innerHTML='<div style="color:var(--mt2);font-size:13px">Nenhuma conta com dados de conversão no período.</div>';return;}
  visible.forEach(function(a){
    var steps=[];
    if((a.views||0)>0)       steps.push({l:'Visitas', v:fmtN(a.views),       c:'var(--ac)'});
    if((a.add_to_cart||0)>0) steps.push({l:'Carrinho',v:fmtN(a.add_to_cart), c:'#826eff'});
    if((a.checkout||0)>0)    steps.push({l:'Checkout',v:fmtN(a.checkout),     c:'var(--yw)'});
    if((a.purchases||0)>0)   steps.push({l:'Compras', v:fmtN(a.purchases),    c:'var(--gn)'});
    if((a.leads||0)>0)       steps.push({l:'Leads',   v:fmtN(a.leads),        c:'#fb923c'});
    var funnelHtml=steps.map(function(s,i){return(i>0?'<span class="af-arrow">→</span>':'')+'<div class="af-step"><div class="af-v" style="color:'+s.c+'">'+s.v+'</div><div class="af-l">'+s.l+'</div></div>';}).join('');
    var card=document.createElement('div');
    card.className='acc-card';
    card.innerHTML='<div class="acc-name">'+esc(a.account_name||a.account_id)+'</div>'+
      '<div class="acc-funnel">'+funnelHtml+'</div>'+
      '<div class="acc-footer"><div>'+
        (a.roas>0?'<span>ROAS <strong class="acc-roas">'+a.roas+'x</strong></span> ':'')+''+
        (a.cpa>0?'<span>CPA <strong class="acc-cpa">R$'+fmtM(a.cpa)+'</strong></span>':'')+
        (a.cpl>0?'<span>CPL <strong class="acc-cpa">R$'+fmtM(a.cpl)+'</strong></span>':'')+
      '</div>'+(a.revenue>0?'<span>💰 R$'+fmtM(a.revenue)+'</span>':'')+
      '<span>💸 R$'+fmtM(a.spend)+'</span></div>';
    grid.appendChild(card);
  });
}

function showState(){document.getElementById('stateBox').style.display='block';document.getElementById('content').style.display='none';}
function showErr(msg){document.getElementById('stateBox').innerHTML='<div style="font-size:32px;margin-bottom:12px">❌</div><div style="font-size:13px">'+esc(msg)+'</div>';}
function fmtN(n){n=parseInt(n)||0;if(n>=1000000)return(n/1000000).toFixed(1)+'M';if(n>=1000)return(n/1000).toFixed(1)+'K';return String(n);}
function fmtM(v){return(parseFloat(v)||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function formatDate(d){var p=d.split('-');return p[2]+'/'+p[1];}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

loadData();
</script>
</body>
</html>