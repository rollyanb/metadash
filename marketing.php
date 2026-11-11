<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
Auth::requireLogin();
$role = Auth::getRole();
if (!in_array($role, ['admin','chefe','marketing'])) { header('Location: index.php'); exit; }
$db              = Database::getInstance();
$allowedAccounts = Auth::getAllowedAccounts();
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
<title>Criativos — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
.page-title{font-size:22px;font-weight:700;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--mt2);margin-bottom:20px}

/* FILTROS */
.filter-bar{background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:16px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.filter-bar select{padding:8px 12px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-family:'Inter',sans-serif;font-size:13px;outline:none;min-width:200px;cursor:pointer;transition:border-color .2s}
.filter-bar select:focus{border-color:var(--ac)}
.period-btns,.sort-btns{display:flex;gap:5px;flex-wrap:wrap}
.period-btn,.sort-btn{padding:6px 12px;border-radius:6px;border:1px solid var(--bd);background:var(--s2);color:var(--mt2);font-family:'Inter',sans-serif;font-size:12px;font-weight:500;cursor:pointer;transition:all .15s;white-space:nowrap}
.period-btn:hover,.sort-btn:hover{border-color:var(--ac);color:var(--tx)}
.period-btn.active,.sort-btn.active{background:var(--ac);border-color:var(--ac);color:#fff;font-weight:600}
.divider{width:1px;height:28px;background:var(--bd);flex-shrink:0}

/* GRID */
.creatives-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.empty{text-align:center;padding:60px 20px;color:var(--mt);grid-column:1/-1}
.empty-icon{font-size:36px;margin-bottom:12px}
.loading{text-align:center;padding:48px;color:var(--mt2);grid-column:1/-1}

/* CREATIVE CARD */
.creative-card{background:var(--sf);border:1px solid var(--bd);border-radius:12px;overflow:hidden;display:flex;flex-direction:column;transition:border-color .15s}
.creative-card:hover{border-color:var(--ac)}
.card-thumb{position:relative;height:180px;overflow:hidden;background:var(--s2);cursor:pointer}
.card-thumb img{width:100%;height:100%;object-fit:cover;transition:transform .2s}
.card-thumb:hover img{transform:scale(1.03)}
.no-thumb{display:flex;align-items:center;justify-content:center;height:100%;font-size:40px;color:var(--mt)}
.type-badge{position:absolute;top:8px;left:8px;padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700}
.type-image{background:rgba(84,112,255,.9);color:#fff}
.type-video{background:rgba(240,100,90,.9);color:#fff}
.type-dynamic{background:rgba(62,207,142,.9);color:#fff}
.rank-badge{position:absolute;top:8px;right:8px;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;background:rgba(0,0,0,.5)}
.rank-other{background:rgba(0,0,0,.4);font-size:11px;font-weight:700;color:#fff}
.status-badge{position:absolute;bottom:8px;left:8px;padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700}
.status-scale{background:rgba(62,207,142,.9);color:#fff}
.status-pause{background:rgba(240,100,90,.9);color:#fff}
.status-watch{background:rgba(245,166,35,.9);color:#fff}

.card-body{padding:12px 14px;display:flex;flex-direction:column;gap:8px;flex:1}
.card-name{font-size:12px;font-weight:600;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-account{font-size:11px;color:var(--mt2)}
.metrics-main{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.metric{text-align:center}
.metric-val{font-family:'JetBrains Mono',monospace;font-size:14px;font-weight:700;line-height:1.2}
.metric-lbl{font-size:9px;color:var(--mt);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.green{color:var(--gn)}.yellow{color:var(--yw)}.blue{color:var(--ac)}
.metrics-sec{display:flex;gap:8px;flex-wrap:wrap;font-size:11px;color:var(--mt2)}
.card-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:2px}
.btn-sm{padding:5px 10px;border-radius:6px;font-family:'Inter',sans-serif;font-size:11px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all .15s;display:inline-flex;align-items:center;gap:4px}
.btn-meta{background:rgba(24,119,242,.1);color:#1877f2;border:1px solid rgba(24,119,242,.2)}
.btn-meta:hover{background:rgba(24,119,242,.2)}
.btn-thumb{background:var(--s2);color:var(--mt2);border:1px solid var(--bd)}
.btn-thumb:hover{border-color:var(--ac);color:var(--ac)}
.btn-assets{background:rgba(62,207,142,.1);color:var(--gn);border:1px solid rgba(62,207,142,.2)}
.btn-assets:hover{background:rgba(62,207,142,.2)}

/* ASSETS PANEL */
.assets-panel{display:none;padding:12px 14px;border-top:1px solid var(--bd);background:var(--s2)}
.assets-panel.open{display:block}
.asset-row{display:flex;gap:10px;align-items:flex-start;margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid var(--bd)}
.asset-row:last-child{margin-bottom:0;padding-bottom:0;border-bottom:none}
.asset-thumb{width:56px;height:56px;border-radius:6px;object-fit:cover;flex-shrink:0;background:var(--sf)}
.asset-info{flex:1;min-width:0}
.asset-name{font-size:12px;font-weight:600;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.asset-type{font-size:10px;color:var(--mt2);margin-bottom:4px}
.asset-metrics{display:flex;gap:8px;flex-wrap:wrap;font-size:11px;color:var(--mt2)}
</style>
</head>
<body>
<?php $currentPage='marketing'; $sidebarBase=''; require __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
  <h1 class="page-title">🎨 Criativos</h1>
  <p class="page-sub">Ranking de performance — identifique o que escalar e o que pausar</p>

  <div class="filter-bar">
    <select id="selAccount" onchange="loadCreatives()">
      <option value="">Selecione uma conta...</option>
      <?php foreach ($accounts as $a): ?>
      <option value="<?= htmlspecialchars($a['account_id']) ?>"><?= htmlspecialchars($a['account_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="divider"></div>
    <div class="period-btns">
      <button type="button" class="period-btn" onclick="setPeriod(3,this)">3D</button>
      <button type="button" class="period-btn active" onclick="setPeriod(7,this)">7D</button>
      <button type="button" class="period-btn" onclick="setPeriod(15,this)">15D</button>
      <button type="button" class="period-btn" onclick="setPeriod(30,this)">30D</button>
    </div>
    <div class="divider"></div>
    <div class="sort-btns">
      <button type="button" class="sort-btn active" onclick="setSort('purchases',this)">🛒 Compras</button>
      <button type="button" class="sort-btn" onclick="setSort('leads',this)">📋 Leads</button>
      <button type="button" class="sort-btn" onclick="setSort('roas',this)">📈 ROAS</button>
      <button type="button" class="sort-btn" onclick="setSort('spend',this)">💸 Gasto</button>
      <button type="button" class="sort-btn" onclick="setSort('ctr',this)">👆 CTR</button>
      <button type="button" class="sort-btn" onclick="setSort('impressions',this)">👁️ Impr.</button>
    </div>
  </div>

  <div class="creatives-grid" id="creativesGrid">
    <div class="empty"><div class="empty-icon">🎨</div><div>Selecione uma conta para ver os criativos</div></div>
  </div>
</main>

<script>
var currentPeriod=7,currentSort='purchases',currentData=[];

function setPeriod(days,btn){currentPeriod=days;document.querySelectorAll('.period-btn').forEach(function(b){b.classList.remove('active');});btn.classList.add('active');loadCreatives();}
function setSort(field,btn){currentSort=field;document.querySelectorAll('.sort-btn').forEach(function(b){b.classList.remove('active');});btn.classList.add('active');renderCreatives();}

async function loadCreatives(){
  var accId=document.getElementById('selAccount').value;
  if(!accId)return;
  var grid=document.getElementById('creativesGrid');
  grid.innerHTML='<div class="loading">⏳ Carregando criativos...</div>';
  var dateEnd=new Date(),dateStart=new Date();
  dateStart.setDate(dateEnd.getDate()-currentPeriod);
  var fmt=function(d){return d.toISOString().split('T')[0];};
  try{
    var r=await fetch('api/creatives.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list',account_id:accId,date_start:fmt(dateStart),date_end:fmt(dateEnd)})});
    var d=await r.json();currentData=d.creatives||[];renderCreatives();
  }catch(e){grid.innerHTML='<div class="empty"><div class="empty-icon">❌</div><div>Erro: '+e.message+'</div></div>';}
}

function getStatus(c){var roas=parseFloat(c.roas)||0,p=parseInt(c.purchases)||0,l=parseInt(c.leads)||0;if(roas>=3||(p===0&&l>10&&roas===0))return'scale';if(roas>=1)return'watch';if(p>0||l>0)return roas<1?'pause':'watch';return'watch';}
function getStatusLabel(s){if(s==='scale')return'<span class="status-badge status-scale">🟢 Escalar</span>';if(s==='pause')return'<span class="status-badge status-pause">🔴 Pausar</span>';return'<span class="status-badge status-watch">🟡 Monitorar</span>';}

function renderCreatives(){
  var grid=document.getElementById('creativesGrid');
  var sorted=currentData.slice().sort(function(a,b){return(parseFloat(b[currentSort])||0)-(parseFloat(a[currentSort])||0);});
  if(!sorted.length){grid.innerHTML='<div class="empty"><div class="empty-icon">📭</div><div>Nenhum criativo encontrado neste período</div></div>';return;}
  grid.innerHTML=sorted.map(function(c,i){
    var rank=i+1;
    var rankHtml=rank<=3?'<div class="rank-badge rank-'+rank+'">'+(rank===1?'🥇':rank===2?'🥈':'🥉')+'</div>':'<div class="rank-badge rank-other">'+rank+'</div>';
    var typeClass=c.is_dynamic?'type-dynamic':(c.ad_type==='video'?'type-video':'type-image');
    var typeLabel=c.is_dynamic?'⚡ Dinâmico':(c.ad_type==='video'?'▶ Vídeo':'🖼 Imagem');
    var imgHigh=esc(c.image_url||''),imgLow=esc(c.thumbnail_url||''),fullSrc=c.image_url||c.thumbnail_url||'';
    var thumb;
    if(imgHigh){var fb=imgLow?'this.onerror=function(){this.style.display=\'none\';this.parentElement.querySelector(\'.no-thumb\').style.display=\'flex\'};this.src=\''+imgLow+'\'':'this.style.display=\'none\';this.parentElement.querySelector(\'.no-thumb\').style.display=\'flex\'';thumb='<img src="'+imgHigh+'" onerror="'+fb+'"><div class="no-thumb" style="display:none">🎨</div>';}
    else if(imgLow){thumb='<img src="'+imgLow+'" onerror="this.style.display=\'none\';this.parentElement.querySelector(\'.no-thumb\').style.display=\'flex\'"><div class="no-thumb" style="display:none">🎨</div>';}
    else{thumb='<div class="no-thumb">🎨</div>';}
    var status=getStatus(c);
    var purchases=parseInt(c.purchases)||0,leads=parseInt(c.leads)||0,roas=parseFloat(c.roas)||0,spend=parseFloat(c.spend)||0;
    var cpa=purchases>0?(spend/purchases):0,cpl=leads>0?(spend/leads):0,ctr=parseFloat(c.ctr)||0,impr=parseInt(c.impressions)||0;
    var metaUrl='https://business.facebook.com/adsmanager/manage/ads?act='+c.account_id.replace('act_','')+'&selected_ad_ids='+c.ad_id;
    var assetsBtn=c.is_dynamic?'<button type="button" class="btn-sm btn-assets" onclick="toggleAssets(\''+c.ad_id+'\')">⚡ Ver assets</button>':'';
    var thumbBtn=fullSrc?'<button type="button" class="btn-sm btn-thumb" onclick="openLightbox(\''+esc(fullSrc)+'\',\''+esc(c.ad_name)+'\')">🖼 Ampliar</button>':'';
    return '<div class="creative-card" id="card-'+c.ad_id+'">'+
      '<div class="card-thumb" onclick="openLightbox(\''+esc(fullSrc)+'\',\''+esc(c.ad_name)+'\')">'+
        thumb+'<div class="type-badge '+typeClass+'">'+typeLabel+'</div>'+rankHtml+getStatusLabel(status)+
      '</div>'+
      '<div class="card-body">'+
        '<div class="card-name" title="'+esc(c.ad_name)+'">'+esc(c.ad_name)+'</div>'+
        '<div class="card-account">'+esc(c.account_name||'')+'</div>'+
        '<div class="metrics-main">'+
          '<div class="metric"><div class="metric-val green">'+purchases+'</div><div class="metric-lbl">Compras</div></div>'+
          '<div class="metric"><div class="metric-val yellow">'+(cpa>0?'R$'+fmtN(cpa):'—')+'</div><div class="metric-lbl">Custo/compra</div></div>'+
          '<div class="metric"><div class="metric-val blue">'+(roas>0?roas.toFixed(1)+'x':'—')+'</div><div class="metric-lbl">ROAS</div></div>'+
        '</div>'+
        '<div class="metrics-sec">'+
          (leads>0?'<span>📋 '+leads+' leads'+(cpl>0?' · R$'+fmtN(cpl):'')+' </span>':'')+
          '<span>💸 R$'+fmtN(spend)+'</span>'+
          '<span>👆 '+(ctr*100).toFixed(2)+'%</span>'+
          '<span>👁️ '+fmtK(impr)+'</span>'+
        '</div>'+
        '<div class="card-actions">'+
          '<a class="btn-sm btn-meta" href="'+metaUrl+'" target="_blank">🔗 Ver no Meta</a>'+
          thumbBtn+assetsBtn+
        '</div>'+
      '</div>'+
      (c.is_dynamic?'<div class="assets-panel" id="assets-'+c.ad_id+'"><div style="color:var(--mt2);font-size:12px">Carregando assets...</div></div>':'')+
    '</div>';
  }).join('');
}

async function toggleAssets(adId){
  var panel=document.getElementById('assets-'+adId);if(!panel)return;
  if(panel.classList.contains('open')){panel.classList.remove('open');return;}
  panel.classList.add('open');
  if(panel.dataset.loaded)return;panel.dataset.loaded='1';
  var accId=document.getElementById('selAccount').value;
  try{
    var r=await fetch('api/creatives.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'assets',ad_id:adId,account_id:accId})});
    var d=await r.json();var assets=d.assets||[];
    if(!assets.length){panel.innerHTML='<div style="color:var(--mt2);font-size:12px;padding:8px 0">Nenhum asset sincronizado ainda.</div>';return;}
    panel.innerHTML='<div style="font-size:10px;color:var(--mt);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px">Assets do criativo dinâmico</div>'+
      assets.map(function(a){
        var isVideo=a.asset_type==='video',rawThumb=a.thumbnail_url||'',isFbPage=rawThumb.indexOf('facebook.com')>=0&&rawThumb.indexOf('videos')>=0,thumbUrl=rawThumb&&!isFbPage?rawThumb:'';
        var thumb=thumbUrl?'<img class="asset-thumb" src="'+esc(thumbUrl)+'" onclick="openLightbox(\''+esc(thumbUrl)+'\',\''+esc(a.asset_name||'')+'\')">':'<div class="asset-thumb" style="display:flex;align-items:center;justify-content:center;font-size:24px;background:var(--sf)">'+(isVideo?'▶':'🖼')+'</div>';
        var p=parseInt(a.purchases)||0,l=parseInt(a.leads)||0,s=parseFloat(a.spend)||0,i=parseInt(a.impressions)||0;
        return '<div class="asset-row">'+thumb+'<div class="asset-info"><div class="asset-name">'+esc(a.asset_name||'Asset '+a.asset_id)+'</div><div class="asset-type">'+(isVideo?'▶ Vídeo':'🖼 Imagem')+'</div><div class="asset-metrics"><span>👁️ <strong>'+fmtK(i)+'</strong></span><span>💸 <strong>R$'+fmtN(s)+'</strong></span>'+(p>0?'<span>🛒 <strong>'+p+'</strong></span>':'')+(l>0?'<span>📋 <strong>'+l+'</strong></span>':'')+'</div></div></div>';
      }).join('');
  }catch(e){panel.innerHTML='<div style="color:var(--rd);font-size:12px">Erro ao carregar assets</div>';}
}

function openLightbox(url,title){
  if(!url)return;
  var lb=document.getElementById('lightbox');
  if(!lb){lb=document.createElement('div');lb.id='lightbox';lb.style.cssText='display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:12px;cursor:zoom-out';lb.onclick=function(){lb.style.display='none';};
    lb.innerHTML='<img id="lbImg" style="max-width:92vw;max-height:82vh;border-radius:8px;object-fit:contain"><div id="lbTitle" style="color:#ededf5;font-size:13px;font-family:Inter,sans-serif;text-align:center;opacity:.7"></div><div style="color:#5c5c78;font-size:11px">Clique para fechar</div>';
    document.body.appendChild(lb);}
  document.getElementById('lbImg').src=url;document.getElementById('lbTitle').textContent=title||'';lb.style.display='flex';
}
function fmtN(n){return parseFloat(n||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmtK(n){if(n>=1000000)return(n/1000000).toFixed(1)+'M';if(n>=1000)return(n/1000).toFixed(1)+'K';return String(n);}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>
</body>
</html>