<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/meta_api.php';
Auth::requireLogin();
if (!Auth::isAdmin()) { header('Location: index.php'); exit; } // área administrativa
$role = Auth::getRole();
if ($role !== 'admin') { header('Location: index.php'); exit; }
$db       = Database::getInstance();
$api      = new MetaAPI();
$hasToken = $api->hasToken();
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sincronizar — MetaDash</title>
<?php echoThemeAssets(''); ?>
<style>
.page-title{font-size:22px;font-weight:700;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--mt2);margin-bottom:22px}

/* WARN */
.warn-box{background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.25);border-radius:10px;padding:13px 16px;font-size:13px;color:var(--yw);margin-bottom:18px}

/* BTN ALL */
.btn-all{width:100%;padding:14px;background:var(--ac);color:#fff;border:none;border-radius:10px;font-family:'Inter',sans-serif;font-size:15px;font-weight:700;cursor:pointer;margin-bottom:20px;transition:opacity .15s;display:flex;align-items:center;justify-content:center;gap:10px}
.btn-all:hover{opacity:.88}
.btn-all:disabled{opacity:.45;cursor:not-allowed}
@keyframes spin{to{transform:rotate(360deg)}}
.spin{display:inline-block;animation:spin .7s linear infinite}

/* SYNC GRID */
.sync-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin-bottom:20px}
.sync-card{background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:18px;display:flex;flex-direction:column;gap:10px}
.sc-header{display:flex;align-items:center;gap:10px}
.sc-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.sc-icon.blue{background:rgba(84,112,255,.15)}
.sc-icon.green{background:rgba(62,207,142,.15)}
.sc-icon.purple{background:rgba(130,110,255,.15)}
.sc-title{font-size:14px;font-weight:700;color:var(--tx)}
.sc-desc{font-size:12px;color:var(--mt2);line-height:1.6}
.sc-status{font-size:12px;color:var(--mt2);min-height:18px}
.sc-status.ok{color:var(--gn)}
.sc-status.err{color:var(--rd)}
.sc-status.running{color:var(--yw)}
.btn-sync{padding:9px 14px;border-radius:8px;font-family:'Inter',sans-serif;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--bd);background:var(--s2);color:var(--mt2);transition:all .15s;width:100%;box-sizing:border-box;min-width:0}
.sc-actions{display:flex;gap:8px;align-items:stretch}
.sc-actions .btn-sync{width:auto}
.sc-actions .btn-main{flex:1 1 auto}
.sc-actions .btn-icon{flex:0 0 42px;width:42px;padding:9px 0;text-align:center}
.btn-sync:hover{border-color:var(--ac);color:var(--tx)}
.btn-sync.blue{background:rgba(84,112,255,.1);border-color:rgba(84,112,255,.3);color:var(--ac)}
.btn-sync.green{background:rgba(62,207,142,.1);border-color:rgba(62,207,142,.3);color:var(--gn)}
.btn-sync.purple{background:rgba(130,110,255,.1);border-color:rgba(130,110,255,.3);color:#826eff}
.btn-sync:disabled{opacity:.45;cursor:not-allowed}

/* LOG */
.log-card{background:var(--sf);border:1px solid var(--bd);border-radius:12px;overflow:hidden}
.log-hd{display:flex;align-items:center;justify-content:space-between;padding:13px 16px;border-bottom:1px solid var(--bd);font-size:13px;font-weight:600}
.log-body{font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.7;padding:0;max-height:0;overflow:hidden;transition:max-height .3s,padding .3s}
.log-body.show{max-height:400px;overflow-y:auto;padding:14px 16px}
.log-body .ok{color:var(--gn)}
.log-body .err{color:var(--rd)}
.log-body .info{color:var(--mt2)}
</style>
</head>
<body>
<?php $currentPage='sync_manual'; $sidebarBase=''; require __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
  <h1 class="page-title">🔄 Sincronizar</h1>
  <p class="page-sub">Execute as sincronizações manualmente quando necessário</p>

  <?php if (!$hasToken): ?>
  <div class="warn-box">⚠️ Token da API do Meta não configurado. <a href="settings.php" style="color:inherit;font-weight:600">Configure em Configurações →</a></div>
  <?php endif; ?>

  <button type="button" class="btn-all" id="btnAll" onclick="syncAll()" <?= !$hasToken ? 'disabled' : '' ?>>
    <span id="allIcon">🚀</span> Sincronizar Tudo Agora
  </button>

  <div class="sync-grid">
    <div class="sync-card">
      <div class="sc-header"><div class="sc-icon blue">📋</div><div class="sc-title">Contas de anúncio</div></div>
      <div class="sc-desc">Atualiza a lista e o status das contas. Os gastos por evento são sincronizados pelas campanhas vinculadas.</div>
      <div class="sc-status" id="status-accounts">Aguardando...</div>
      <button type="button" class="btn-sync blue" id="btn-accounts" onclick="runSync('accounts')" <?= !$hasToken ? 'disabled' : '' ?>>🔄 Sincronizar</button>
    </div>
    <div class="sync-card">
      <div class="sc-header"><div class="sc-icon green">📈</div><div class="sc-title">Conversões</div></div>
      <div class="sc-desc">Sincroniza funil de conversão (visitas, carrinho, checkout, compras, leads) dos últimos 32 dias.</div>
      <div class="sc-status" id="status-conversions">Aguardando...</div>
      <button type="button" class="btn-sync green" id="btn-conversions" onclick="runSync('conversions')" <?= !$hasToken ? 'disabled' : '' ?>>🔄 Sincronizar</button>
    </div>
    <div class="sync-card">
      <div class="sc-header"><div class="sc-icon purple">🎨</div><div class="sc-title">Criativos</div></div>
      <div class="sc-desc">Sincroniza métricas diárias dos criativos das contas selecionadas em Configurações.</div>
      <div class="sc-status" id="status-creatives">Aguardando...</div>
      <div class="sc-actions">
        <button type="button" class="btn-sync purple btn-main" id="btn-creatives" onclick="runSync('creatives')" <?= !$hasToken ? 'disabled' : '' ?>>🔄 Sincronizar</button>
        <button type="button" class="btn-sync btn-icon" id="btnForce" onclick="syncCreatives(true)" style="background:rgba(240,100,90,.1);border-color:rgba(240,100,90,.3);color:var(--rd)" title="Forçar sincronização mesmo que tenha rodado há pouco" <?= !$hasToken ? 'disabled' : '' ?>>⚡</button>
      </div>
    </div>
  </div>

  <div class="log-card">
    <div class="log-hd">
      📋 Log de execução
      <button type="button" onclick="clearLog()" style="background:none;border:none;color:var(--mt2);font-size:12px;cursor:pointer">Limpar</button>
    </div>
    <div class="log-body" id="logBody"></div>
  </div>
</main>

<script>
var running={},syncAllRunning=false;
function isRunning(t){return running[t]||syncAllRunning;}

function log(msg,type){
  var body=document.getElementById('logBody');body.classList.add('show');
  var cls=type==='ok'?'ok':type==='err'?'err':'info';
  var time=new Date().toLocaleTimeString('pt-BR');
  body.innerHTML+='<span class="'+cls+'">['+time+'] '+esc(String(msg))+'</span>\n';
  body.scrollTop=body.scrollHeight;
}
function clearLog(){var b=document.getElementById('logBody');b.innerHTML='';b.classList.remove('show');}
function setStatus(id,msg,type){var el=document.getElementById('status-'+id);if(!el)return;el.textContent=msg;el.className='sc-status '+(type||'');}
function setBtnState(id,disabled){var btn=document.getElementById('btn-'+id);if(btn)btn.disabled=disabled;}

async function callApi(url,body){
  var r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  var text=await r.text();try{return JSON.parse(text);}catch(e){throw new Error('Resposta inválida: '+text.substring(0,120));}
}

async function doSync(type){
  var map={accounts:'api/sync.php',conversions:'api/sync_conversions.php'};
  setStatus(type,'⏳ Sincronizando...','running');log('Iniciando: '+type,'info');
  try{
    var data=await callApi(map[type],{type:type});
    if(data.success||data.ok){setStatus(type,'✅ '+(data.message||'Concluído'),'ok');log('✅ '+type+': '+(data.message||'Concluído'),'ok');return true;}
    var message=data.message||data.error||'Erro';
    setStatus(type,'❌ '+message,'err');log('❌ '+type+': '+message,'err');return false;
  }catch(e){setStatus(type,'❌ '+e.message,'err');log('❌ '+e.message,'err');return false;}
}

async function runSync(type){
  if(isRunning(type)){log('Aguarde o sync de '+type+' terminar.','err');return;}
  if(type==='creatives'){await syncCreatives();return;}
  running[type]=true;setBtnState(type,true);
  await doSync(type);
  running[type]=false;setBtnState(type,false);
}

async function syncOneAccount(accountId,accountName,idx,total){
  log('→ ['+idx+'/'+total+'] '+accountName,'info');
  var adsCursor=null,adsTotal=0,dynTotal=0;
  do{
    var body={action:'sync_one',phase:'ads',account_id:accountId};if(adsCursor)body.cursor=adsCursor;
    var r=await callApi('api/sync_creatives.php',body);
    if(!r.success){log('  ❌ ads: '+(r.message||'erro'),'err');return 0;}
    adsTotal+=(r.saved||0);dynTotal+=(r.dynamic||0);adsCursor=r.has_more?r.next_cursor:null;
    await new Promise(function(res){setTimeout(res,300);});
  }while(adsCursor);
  log('  📋 '+adsTotal+' anúncios ('+dynTotal+' dinâmicos)','info');
  var snapCursor=null,snapTotal=0,snapRetries=0;
  do{
    var body2={action:'sync_one',phase:'snapshots',account_id:accountId};if(snapCursor)body2.cursor=snapCursor;
    var r2=await callApi('api/sync_creatives.php',body2);
    if(!r2.success){
      snapRetries++;
      if(snapRetries<=3){
        log('  ⏳ snapshots: falhou ('+(r2.message||'erro')+'), tentando de novo ('+snapRetries+'/3)...','err');
        await new Promise(function(res){setTimeout(res,2000*snapRetries);});
        continue;
      }
      log('  ❌ snapshots: '+(r2.message||'erro')+' — desistindo após 3 tentativas','err');
      break;
    }
    snapRetries=0;
    snapTotal+=(r2.saved||0);snapCursor=r2.has_more?r2.next_cursor:null;
    if(r2.has_more)log('  ⏳ snapshots: '+snapTotal+' até agora...','info');
    await new Promise(function(res){setTimeout(res,300);});
  }while(snapCursor);
  log('  📈 '+snapTotal+' snapshots','info');
  if(dynTotal>0){
    var dynAds=await callApi('api/creatives.php',{account_id:accountId,date_start:'2020-01-01',date_end:new Date().toISOString().split('T')[0]});
    var dynamicList=(dynAds.creatives||[]).filter(function(c){return c.is_dynamic;});
    if(dynamicList.length>0){
      log('  ⚡ Buscando assets de '+dynamicList.length+' anúncios dinâmicos...','info');
      var assetsTotal=0;
      for(var j=0;j<dynamicList.length;j++){
        var adId=dynamicList[j].ad_id;
        var ra=await callApi('api/sync_creatives.php',{action:'sync_one',phase:'assets',account_id:accountId,ad_id:adId,breakdown:'image_asset'});
        if(ra.success)assetsTotal+=(ra.saved||0);
        await new Promise(function(res){setTimeout(res,200);});
        var rv=await callApi('api/sync_creatives.php',{action:'sync_one',phase:'assets',account_id:accountId,ad_id:adId,breakdown:'video_asset'});
        if(rv.success)assetsTotal+=(rv.saved||0);
        await new Promise(function(res){setTimeout(res,200);});
      }
      if(assetsTotal>0)log('  ⚡ '+assetsTotal+' assets salvos','ok');
    }
  }
  await callApi('api/sync_creatives.php',{action:'sync_one',phase:'done',account_id:accountId});
  log('  ✅ '+accountName+' concluída','ok');
  return snapTotal;
}

async function syncCreatives(force){
  if(isRunning('creatives')){log('Aguarde o sync de criativos terminar.','err');return;}
  running['creatives']=true;setBtnState('creatives',true);document.getElementById('btnForce').disabled=true;
  setStatus('creatives','⏳ Verificando contas...','running');
  log('Iniciando: criativos'+(force?' (forçado)':' (verificando 3 dias)'),'info');
  try{
    var d=await callApi('api/sync_creatives.php',{action:'get_accounts',force:!!force});
    if(!d.accounts||!d.accounts.length){setStatus('creatives','⚠️ Nenhuma conta selecionada','err');log('⚠️ Selecione contas em Configurações → Sync de Criativos','err');running['creatives']=false;setBtnState('creatives',false);document.getElementById('btnForce').disabled=false;return false;}
    var total=d.accounts.length,toSync=d.to_sync||total,toSkip=d.to_skip||0;
    log('📋 '+total+' conta(s) | '+toSync+' para sync | '+toSkip+' pular','info');
    if(toSync===0){setStatus('creatives','✅ Todas as contas já sincronizadas (< 3 dias)','ok');log('✅ Use "Forçar" para sincronizar mesmo assim.','ok');running['creatives']=false;setBtnState('creatives',false);document.getElementById('btnForce').disabled=false;return true;}
    var idx=0,totalSnaps=0;
    for(var i=0;i<d.accounts.length;i++){
      var acc=d.accounts[i];
      if(!acc.needs_sync){log('⏭️ '+acc.account_name+' — pulando ('+acc.skip_reason+')','info');continue;}
      idx++;setStatus('creatives','⏳ ['+idx+'/'+toSync+'] '+acc.account_name,'running');
      totalSnaps+=await syncOneAccount(acc.account_id,acc.account_name,idx,toSync);
    }
    setStatus('creatives','✅ '+toSync+' contas | '+toSkip+' puladas | '+totalSnaps+' snapshots','ok');
    log('✅ Criativos concluído!','ok');
    running['creatives']=false;setBtnState('creatives',false);document.getElementById('btnForce').disabled=false;return true;
  }catch(e){setStatus('creatives','❌ '+e.message,'err');log('❌ '+e.message,'err');}
  running['creatives']=false;setBtnState('creatives',false);document.getElementById('btnForce').disabled=false;
  return false;
}

async function syncAll(){
  if(syncAllRunning)return;syncAllRunning=true;
  var btn=document.getElementById('btnAll'),icon=document.getElementById('allIcon');
  btn.disabled=true;icon.className='spin';icon.textContent='⏳';
  setBtnState('accounts',true);setBtnState('conversions',true);setBtnState('creatives',true);document.getElementById('btnForce').disabled=true;
  clearLog();log('=== Iniciando sincronização completa ===','info');
  var allOk=true;
  if(!await doSync('accounts'))allOk=false;await new Promise(function(res){setTimeout(res,800);});
  if(!await doSync('conversions'))allOk=false;await new Promise(function(res){setTimeout(res,800);});
  syncAllRunning=false;if(!await syncCreatives(false))allOk=false;syncAllRunning=true;syncAllRunning=false;
  setBtnState('accounts',false);setBtnState('conversions',false);
  icon.className='';icon.textContent=allOk?'✅':'⚠️';btn.disabled=false;
  log(allOk?'=== Sincronização completa finalizada ===':'=== Sincronização finalizada com falhas; confira as etapas acima ===',allOk?'ok':'err');
  setTimeout(function(){icon.textContent='🚀';},3000);
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
</script>
</body>
</html>
