<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
Auth::requireAdmin();
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Importar Faturas — MetaDash</title>
<?php echoThemeAssets(''); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<style>
.page-title{font-size:22px;font-weight:700;margin-bottom:4px}
.page-sub{font-size:13px;color:var(--mt2);margin-bottom:22px}

/* TIP */
.tip{background:rgba(84,112,255,.05);border:1px solid rgba(84,112,255,.15);border-radius:10px;padding:14px 18px;font-size:13px;color:var(--mt2);line-height:1.75;margin-bottom:20px}
.tip strong{color:var(--tx)}
code{background:var(--s2);border:1px solid var(--bd);padding:1px 6px;border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:12px}

/* CARD */
.card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:22px;margin-bottom:18px}
.card-title{font-size:14px;font-weight:700;color:var(--tx);margin-bottom:16px}

/* DROPZONE */
.dz{border:2px dashed rgba(84,112,255,.3);border-radius:12px;padding:36px 20px;text-align:center;cursor:pointer;transition:all .2s;background:rgba(84,112,255,.03)}
.dz:hover,.dz.over{border-color:var(--ac);background:rgba(84,112,255,.07)}
.dz-icon{font-size:36px;margin-bottom:10px}
.dz-title{font-size:15px;font-weight:700;color:var(--tx);margin-bottom:4px}
.dz-sub{font-size:13px;color:var(--mt2);margin-bottom:14px}

.btn-sel{display:inline-flex;align-items:center;gap:6px;background:var(--ac);color:#fff;font-family:'Inter',sans-serif;font-weight:600;font-size:13px;padding:9px 20px;border-radius:9px;cursor:pointer;border:none;transition:opacity .15s}
.btn-sel:hover{opacity:.85}

/* QUEUE */
.queue{display:flex;flex-direction:column;gap:8px;margin-top:18px}
.qi{background:var(--s2);border:1px solid var(--bd);border-radius:11px;padding:13px 15px;display:flex;align-items:center;gap:12px;position:relative;overflow:hidden}
.qbar{position:absolute;left:0;top:0;bottom:0;background:rgba(84,112,255,.1);transition:width .4s;pointer-events:none}
.qi-ico{font-size:20px;flex-shrink:0;position:relative;z-index:1}
.qi-info{flex:1;min-width:0;position:relative;z-index:1}
.qi-name{font-size:13px;font-weight:500;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.qi-st{font-size:12px;color:var(--mt2);margin-top:3px}
.qbadge{font-size:11px;padding:3px 9px;border-radius:5px;font-weight:600;flex-shrink:0;position:relative;z-index:1;white-space:nowrap}
.bw{background:rgba(148,148,163,.1);color:var(--mt2)}
.bp{background:rgba(251,191,36,.1);color:var(--yw);animation:pulse 1.2s infinite}
.bo{background:rgba(52,211,153,.1);color:var(--gn)}
.be{background:rgba(248,113,113,.1);color:var(--rd)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}

/* CONTROLS */
.flex-gap{display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:9px;font-family:'Inter',sans-serif;font-weight:600;font-size:13px;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-p{background:var(--ac);color:#fff}.btn-p:hover{opacity:.85}.btn-p:disabled{opacity:.4;cursor:not-allowed}
.btn-o{background:var(--s2);border:1px solid var(--bd);color:var(--mt2)}.btn-o:hover{border-color:var(--ac);color:var(--ac)}

/* RESULT */
.rgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:16px}
.rc{background:var(--s2);border:1px solid var(--bd);border-radius:10px;padding:14px;text-align:center}
.rl{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt2);margin-bottom:6px;font-weight:600}
.rv{font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:700;color:var(--tx)}
.rv.gn{color:var(--gn)}.rv.yw{color:var(--yw)}.rv.rd{color:var(--rd)}.rv.pu{color:#826eff}

/* LOG */
.log{background:var(--s2);border:1px solid var(--bd);border-radius:8px;padding:12px 16px;font-size:11px;font-family:'JetBrains Mono',monospace;color:var(--mt2);max-height:180px;overflow-y:auto;margin-top:12px;display:none}
.log.on{display:block}
.log .ok{color:var(--gn)}.log .er{color:var(--rd)}.log .in{color:var(--ac)}
</style>
</head>
<body>
<?php $currentPage='import_invoice'; $sidebarBase=''; require __DIR__.'/includes/sidebar.php'; ?>

<main class="main">
  <h1 class="page-title">📄 Importar Faturas PDF</h1>
  <p class="page-sub">PDF lido direto no browser — sem servidor, sem timeout</p>

  <div class="tip">
    <strong>Como obter:</strong> Gerenciador de Anúncios → Faturamento → conta → Histórico de transações → Exportar PDF.<br>
    ✅ Leitura 100% local &nbsp;·&nbsp; ✅ Sem limite de arquivos &nbsp;·&nbsp; ✅ Cartão correto por transação &nbsp;·&nbsp; ✅ Sem duplicatas
  </div>

  <div class="card">
    <div class="card-title">⬆️ Selecionar Faturas</div>
    <div class="dz" id="dz" onclick="document.getElementById('fi').click()">
      <div class="dz-icon">📋</div>
      <div class="dz-title">Arraste os PDFs aqui</div>
      <div class="dz-sub">ou clique para selecionar</div>
      <button type="button" class="btn-sel" onclick="event.stopPropagation();document.getElementById('fi').click()">Selecionar PDFs</button>
      <input type="file" id="fi" accept=".pdf" multiple style="display:none">
    </div>
    <div class="queue" id="queue" style="display:none"></div>
    <div class="flex-gap" id="controls" style="display:none">
      <button type="button" class="btn btn-p" id="btnStart" onclick="startQueue()">▶ Iniciar Importação</button>
      <button type="button" class="btn btn-o" onclick="clearAll()">🗑 Limpar</button>
      <span id="qc" style="font-size:13px;color:var(--mt2)"></span>
    </div>
  </div>

  <div class="card" id="resultCard" style="display:none">
    <div class="card-title">📊 Resultado</div>
    <div class="rgrid">
      <div class="rc"><div class="rl">Arquivos</div><div class="rv" id="rF">0</div></div>
      <div class="rc"><div class="rl">Novas</div><div class="rv gn" id="rN">0</div></div>
      <div class="rc"><div class="rl">Já existiam</div><div class="rv pu" id="rD">0</div></div>
      <div class="rc"><div class="rl">Ignoradas</div><div class="rv yw" id="rS">0</div></div>
      <div class="rc"><div class="rl">Erros</div><div class="rv rd" id="rE">0</div></div>
    </div>
    <div class="flex-gap">
      <a href="transactions.php" class="btn btn-p">Ver Transações →</a>
      <button type="button" class="btn btn-o" onclick="clearAll()">Importar mais</button>
      <button type="button" class="btn btn-o" style="font-size:12px" onclick="document.getElementById('logBox').classList.toggle('on')">📋 Log</button>
    </div>
    <div class="log" id="logBox"></div>
  </div>
</main>

<script>
pdfjsLib.GlobalWorkerOptions.workerSrc =
  'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

var files = [], running = false;
var stats = {f:0,n:0,d:0,s:0,e:0};
var API = 'api/process_invoice.php';

var dz = document.getElementById('dz');
var fi = document.getElementById('fi');
fi.addEventListener('change', function(){ addFiles(Array.from(fi.files)); fi.value=''; });
dz.addEventListener('dragover',  function(e){ e.preventDefault(); e.stopPropagation(); dz.classList.add('over'); });
dz.addEventListener('dragleave', function(){ dz.classList.remove('over'); });
dz.addEventListener('drop', function(e){
  e.preventDefault(); e.stopPropagation(); dz.classList.remove('over');
  addFiles(Array.from(e.dataTransfer.files).filter(function(f){
    return f.name.toLowerCase().endsWith('.pdf');
  }));
});

function addFiles(newFiles) {
  newFiles.forEach(function(f) {
    if (!files.some(function(x){ return x.file.name===f.name && x.file.size===f.size; })) {
      var id = 'q'+Date.now()+'_'+Math.floor(Math.random()*9999);
      files.push({file:f, id:id, status:'wait'});
      renderItem(files[files.length-1]);
    }
  });
  document.getElementById('queue').style.display   = files.length ? 'flex' : 'none';
  document.getElementById('controls').style.display = files.length ? 'flex' : 'none';
  document.getElementById('qc').textContent = files.length + ' arquivo(s)';
  document.getElementById('resultCard').style.display = 'none';
}

function renderItem(item) {
  var q=document.getElementById('queue'), d=document.createElement('div');
  d.className='qi'; d.id=item.id;
  d.innerHTML='<div class="qbar" id="bar_'+item.id+'" style="width:0%"></div>'+
    '<div class="qi-ico">📄</div>'+
    '<div class="qi-info"><div class="qi-name">'+esc(item.file.name)+'</div>'+
    '<div class="qi-st" id="st_'+item.id+'">Aguardando...</div></div>'+
    '<span class="qbadge bw" id="badge_'+item.id+'">Fila</span>';
  q.appendChild(d);
}

function upd(id, st, bc, bt, pct) {
  var s=document.getElementById('st_'+id),b=document.getElementById('badge_'+id),bar=document.getElementById('bar_'+id);
  if(s) s.textContent=st;
  if(b){ b.className='qbadge '+bc; b.textContent=bt; }
  if(bar) bar.style.width=(pct||0)+'%';
}

function log(msg, cls) {
  var box=document.getElementById('logBox'), d=document.createElement('div');
  d.className=cls||'in';
  d.textContent='['+new Date().toLocaleTimeString()+'] '+msg;
  box.appendChild(d); box.scrollTop=box.scrollHeight;
}

async function startQueue() {
  if (running) return;
  running=true;
  document.getElementById('btnStart').disabled=true;
  stats={f:0,n:0,d:0,s:0,e:0};
  document.getElementById('resultCard').style.display='none';

  for (var i=0; i<files.length; i++) {
    var item=files[i];
    if (item.status!=='wait') continue;

    upd(item.id,'Lendo PDF...','bp','⏳ Lendo',20);
    log('Iniciando: '+item.file.name);

    var extracted;
    try { extracted = await extractPdfText(item.file); }
    catch(e) {
      upd(item.id,'Erro: '+e.message,'be','Erro',100);
      log('ERRO: '+e.message,'er'); stats.e++; item.status='error'; continue;
    }

    if (!extracted.transactions||extracted.transactions.length===0) {
      upd(item.id,'Nenhuma transação encontrada','be','Erro',100);
      log('ERRO: sem transações em '+item.file.name,'er'); stats.e++; item.status='error'; continue;
    }

    log('Conta: '+extracted.account_id+' | Cartão: '+extracted.card_display+' | '+extracted.transactions.length+' transações');
    upd(item.id,'Salvando...','bp','⏳ Salvando',65);

    var result = await apiImport(extracted);
    if (!result||result.error) {
      upd(item.id,'Erro: '+(result?result.error:'sem resposta'),'be','Erro',100);
      log('ERRO import: '+(result?result.error:'sem resposta'),'er'); stats.e++; item.status='error'; continue;
    }

    var onlyUpd=result.imported===0&&result.updated>0;
    upd(item.id,result.imported+' novas · '+result.updated+' já existiam',onlyUpd?'bw':'bo',onlyUpd?'↩ Atualizadas':'✅ OK',100);
    log(item.file.name+' → '+result.imported+' novas, '+result.updated+' duplicatas','ok');
    stats.f++; stats.n+=result.imported; stats.d+=result.updated; stats.s+=result.skipped;
    item.status='done';
    await sleep(200);
  }

  document.getElementById('rF').textContent=stats.f;
  document.getElementById('rN').textContent=stats.n;
  document.getElementById('rD').textContent=stats.d;
  document.getElementById('rS').textContent=stats.s;
  document.getElementById('rE').textContent=stats.e;
  document.getElementById('resultCard').style.display='block';
  document.getElementById('resultCard').scrollIntoView({behavior:'smooth'});
  running=false;
  document.getElementById('btnStart').disabled=false;
}

// ── EXTRAÇÃO COM PDF.JS ───────────────────────────────────────
async function extractPdfText(file) {
  var buf = await file.arrayBuffer();
  var pdf = await pdfjsLib.getDocument({data:buf}).promise;
  var allLines = [];

  for (var p=1; p<=pdf.numPages; p++) {
    var page = await pdf.getPage(p);
    var content = await page.getTextContent();
    var lineMap = {};
    content.items.forEach(function(item) {
      var y = Math.round(item.transform[5]);
      if (!lineMap[y]) lineMap[y] = [];
      lineMap[y].push({x: item.transform[4], str: item.str});
    });
    Object.keys(lineMap).map(Number).sort(function(a,b){return b-a;}).forEach(function(y) {
      lineMap[y].sort(function(a,b){return a.x-b.x;});
      var line = lineMap[y].map(function(i){return i.str;}).join(' ').replace(/\s+/g,' ').trim();
      if (line) allLines.push(line);
    });
  }

  return parseLines(allLines, file.name);
}

// ── PARSER ────────────────────────────────────────────────────
function parseLines(lines, filename) {
  function classify(l) {
    if (/^\d{2}\/\d{2}\/\d{4}/.test(l))   return 'DATE';
    if (/^\d{10,}-\d{3,}$/.test(l))        return 'PART1';
    if (/^\d{10,}$/.test(l))               return 'PART2';
    if (/^\d{15,}-\d{15,}$/.test(l))       return 'CREDIT_ID';
    return 'OTHER';
  }

  var fullText = lines.join('\n');
  var mConta = fullText.match(/Conta:\s*(\d+)/);
  if (!mConta) throw new Error('Número de conta não encontrado no PDF');
  var accountNum = mConta[1];

  var mPer  = fullText.match(/Relat[oó]rio de cobran[cç]a[^:]*:\s*([\d\/]+)\s*a\s*([\d\/]+)/i);
  var mCard = fullText.match(/(Visa|Mastercard|MasterCard|American Express|Amex)[\s\u00b7\u2022\-]+(\d{4})/);
  var cardDisplay = mCard ? mCard[1]+' \u00b7\u00b7\u00b7\u00b7 '+mCard[2] : 'N/D';
  var cardLast4   = mCard ? mCard[2] : null;
  var transactions = {};

  var i = 0;
  while (i < lines.length) {
    var line = lines[i];
    var type = classify(line);

    if (type === 'DATE') {
      var part2 = '', part1 = '';
      var j = i + 1;
      while (j < lines.length && classify(lines[j]) !== 'DATE') {
        if (classify(lines[j]) === 'PART2') part2 = lines[j];
        if (classify(lines[j]) === 'PART1') part1 = lines[j];
        if (classify(lines[j]) === 'CREDIT_ID') {
          var cDate = line.match(/^(\d{2}\/\d{2}\/\d{4})/);
          var cVal  = line.match(/(R\$[\d\.,]+)\s+BRL/);
          if (cDate && cVal) {
            var cId = lines[j];
            transactions[cId] = {
              date: convDate(cDate[1]), txnId: cId,
              card: 'Crédito para anúncio', last4: null,
              value: convVal(cVal[1]), status: 'SETTLED'
            };
          }
        }
        j++;
      }

      var fullId = part1 && part2 ? part1 + part2 : (part1 || part2 || '');
      if (!fullId) {
        var mInline = line.match(/(\d{10,}-\d{10,})/);
        if (mInline) fullId = mInline[1];
      }

      if (fullId) {
        var m = null, last4, pv, card, txnDate, txnVal, txnStatus;

        m = line.match(/^(\d{2}\/\d{2}\/\d{4})\s+(?:Pagamento|Reembolso)[^\n]*(Visa|Mastercard|MasterCard|Amex|American Express|N\/D)(?:[\s\u00b7\u2022\-]+(\d{4}))?\s+(-?R\$[\d\.,]+)\s+BRL\s+(Pago|Reembolsado|Com fundos|Pendente|Cancelado|Falhou)/);
        if (m) { txnDate=m[1]; pv=m[2].trim(); last4=m[3]||cardLast4; txnVal=m[4]; txnStatus=m[5]; }

        if (!m) {
          m = line.match(/^(\d{2}\/\d{2}\/\d{4})\s+(Visa|Mastercard|MasterCard|Amex|American Express|N\/D)[\s\u00b7\u2022\-]+(\d{4})\s+(-?R\$[\d\.,]+)\s+BRL\s+(Pago|Reembolsado|Com fundos|Pendente|Cancelado|Falhou)/);
          if (m) { txnDate=m[1]; pv=m[2].trim(); last4=m[3]; txnVal=m[4]; txnStatus=m[5]; }
        }

        if (!m) {
          m = line.match(/^(\d{2}\/\d{2}\/\d{4})\s+(N\/D)\s+(-?R\$[\d\.,]+)\s+BRL\s+(Pago|Reembolsado|Com fundos|Pendente|Cancelado|Falhou)/);
          if (m) { txnDate=m[1]; pv='N/D'; last4=null; txnVal=m[3]; txnStatus=m[4]; }
        }

        if (!m && fullId) {
          var escId = fullId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
          var reC = new RegExp('^(\\d{2}\\/\\d{2}\\/\\d{4})\\s+'+escId+'\\s+(-?R\\$[\\d\\.,]+)\\s+BRL\\s+(Pago|Reembolsado|Com fundos|Pendente|Cancelado|Falhou)');
          m = line.match(reC);
          if (m) { txnDate=m[1]; pv=null; last4=cardLast4; txnVal=m[2]; txnStatus=m[3]; }
        }

        if (txnDate) {
          card = (pv && pv!=='N/D' && last4) ? pv+' \u00b7\u00b7\u00b7\u00b7 '+last4 : (pv==='N/D' ? 'N/D' : cardDisplay);
          transactions[fullId] = {
            date: convDate(txnDate), txnId: fullId,
            card: card, last4: last4||null,
            value: convVal(txnVal), status: convStatus(txnStatus)
          };
        }
      }
      i = j;
    } else { i++; }
  }

  lines.forEach(function(line) {
    var m = line.match(/^(\d{2}\/\d{2}\/\d{4})\s+([\d\-]{35,})\s+(R\$[\d\.,]+)\s+BRL\s+Pago/);
    if (m && !transactions[m[2]]) {
      transactions[m[2]] = {
        date: convDate(m[1]), txnId: m[2],
        card: 'Crédito para anúncio', last4: null,
        value: convVal(m[3]), status: 'SETTLED'
      };
    }
  });

  return {
    account_id:   'act_'+accountNum, account_num: accountNum,
    card_display: cardDisplay, card_last4: cardLast4,
    period_start: mPer?mPer[1]:'', period_end: mPer?mPer[2]:'',
    filename: filename, transactions: Object.values(transactions)
  };
}

function convDate(dmy) { var p=dmy.split('/'); return p.length===3?p[2]+'-'+p[1]+'-'+p[0]:dmy; }
function convVal(raw)  { return parseFloat((raw||'').replace('R$','').replace(/\./g,'').replace(',','.').trim())||0; }
function convStatus(s) {
  var m={'Reembolsado':'REFUNDED','Com fundos':'FUNDED','Pendente':'PENDING','Cancelado':'CANCELLED','Falhou':'FAILED'};
  return m[s]||'SETTLED';
}

async function apiImport(parsed) {
  var fd=new FormData();
  fd.append('action','import');
  fd.append('parsed',JSON.stringify(parsed));
  try {
    var r=await fetch(API,{method:'POST',body:fd});
    if(!r.ok) return {error:'HTTP '+r.status};
    var t=await r.text();
    if(!t.trim()) return {error:'Resposta vazia'};
    try { return JSON.parse(t); } catch(e){ return {error:'Inválido: '+t.substring(0,100)}; }
  } catch(e){ return {error:'Rede: '+e.message}; }
}

function clearAll() {
  if(running) return;
  files=[];
  var q=document.getElementById('queue');
  q.innerHTML=''; q.style.display='none';
  document.getElementById('controls').style.display='none';
  document.getElementById('resultCard').style.display='none';
  document.getElementById('logBox').innerHTML='';
  document.getElementById('qc').textContent='';
}

function esc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function sleep(ms){ return new Promise(function(r){ setTimeout(r,ms); }); }
</script>
</body>
</html>