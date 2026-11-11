<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
Auth::requireLogin();
$role = Auth::getRole();
if (!in_array($role, ['admin','chefe','marketing','financeiro','user'])) { header('Location: index.php'); exit; }
$isAdmin = in_array($role, ['admin','chefe']);
$db = Database::getInstance();
$eventId = intval($_GET['id'] ?? 0);
if (!$eventId) { header('Location: events.php'); exit; }
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Evento — MetaDash</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800;900&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{max-width:100%;overflow-x:hidden}
:root{--ac:#4f6ef7;--ac2:#a78bfa;--gn:#34d399;--rd:#f87171;--yw:#fbbf24;--or:#fb923c;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--tx);min-height:100vh;display:flex}
.sidebar{width:240px;min-height:100vh;background:var(--sf);border-right:1px solid var(--bd);display:flex;flex-direction:column;padding:24px 16px;position:fixed;top:0;left:0;bottom:0}
.logo{display:flex;align-items:center;gap:10px;padding:8px 12px 28px;border-bottom:1px solid var(--bd);margin-bottom:20px}
.logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--ac),var(--ac2));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px}
.logo-text{font-family:'Syne',sans-serif;font-weight:800;font-size:16px}.logo-text span{color:var(--ac)}
.nav-label{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--mt);padding:0 12px;margin-bottom:8px;margin-top:16px}
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:var(--mt2);text-decoration:none;font-size:14px;font-weight:500;transition:all .15s;margin-bottom:2px}
.nav-item:hover,.nav-item.active{background:rgba(79,110,247,.12);color:var(--tx)}.nav-item.active{color:var(--ac)}
.sidebar-footer{margin-top:auto;padding-top:20px;border-top:1px solid var(--bd)}
.logout-link{display:block;text-align:center;color:var(--mt);font-size:12px;text-decoration:none;padding:6px}
.main{margin-left:240px;flex:1;padding:32px;min-height:100vh;overflow-x:hidden;max-width:calc(100vw - 240px)}

/* HEADER */
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--mt2);text-decoration:none;font-size:13px;margin-bottom:16px;transition:color .15s}
.back-link:hover{color:var(--ac)}
.ev-header{background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:24px;margin-bottom:20px}
.ev-title{font-family:'Syne',sans-serif;font-size:24px;font-weight:900;margin-bottom:8px}
.ev-info{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.ev-info-item{font-size:13px;color:var(--mt2);display:flex;align-items:center;gap:5px}
.ev-info-item strong{color:var(--tx)}

/* TIMELINE */
.timeline{margin-bottom:16px}
.timeline-bar{height:8px;background:var(--s2);border-radius:4px;overflow:visible;position:relative;margin-bottom:8px}
.timeline-fill{height:100%;background:linear-gradient(90deg,var(--ac),var(--gn));border-radius:4px}
.timeline-markers{position:relative;height:24px}
.timeline-marker{position:absolute;transform:translateX(-50%);text-align:center;font-size:10px;color:var(--ac2);white-space:nowrap}
.timeline-marker::before{content:'';position:absolute;top:-18px;left:50%;width:2px;height:12px;background:var(--ac2);transform:translateX(-50%)}

/* ABAS */
.tabs{display:flex;gap:0;border-bottom:1px solid var(--bd);margin-bottom:24px}
.tab{padding:10px 20px;font-family:'Syne',sans-serif;font-weight:700;font-size:13px;cursor:pointer;border:none;background:transparent;color:var(--mt2);border-bottom:2px solid transparent;transition:all .15s;margin-bottom:-1px}
.tab:hover{color:var(--tx)}
.tab.active{color:var(--ac);border-bottom-color:var(--ac)}
.tab-content{display:none}.tab-content.active{display:block}

/* TAREFAS */
.task-group{margin-bottom:24px}
.task-group-title{font-family:'Syne',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--mt2);margin-bottom:10px;display:flex;align-items:center;gap:8px}
.task-item{background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:14px 16px;margin-bottom:8px;display:flex;align-items:center;gap:12px;transition:border-color .15s}
.task-item:hover{border-color:rgba(79,110,247,.3)}
.task-check{width:20px;height:20px;border-radius:6px;border:2px solid var(--bd);cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:all .15s}
.task-check.done{background:var(--gn);border-color:var(--gn)}
.task-check.doing{background:var(--yw);border-color:var(--yw)}
.task-info{flex:1;min-width:0}
.task-title{font-size:14px;font-weight:500}
.task-title.done{text-decoration:line-through;color:var(--mt2)}
.task-meta{display:flex;gap:8px;margin-top:4px;flex-wrap:wrap}
.task-cat{font-size:10px;padding:2px 8px;border-radius:4px;font-weight:600}
.cat-criativo{background:rgba(167,139,250,.15);color:var(--ac2)}
.cat-copy{background:rgba(251,191,36,.15);color:var(--yw)}
.cat-trafego{background:rgba(79,110,247,.15);color:var(--ac)}
.cat-lote{background:rgba(52,211,153,.15);color:var(--gn)}
.cat-material{background:rgba(251,146,60,.15);color:var(--or)}
.cat-outro{background:rgba(255,255,255,.05);color:var(--mt2)}
.task-due{font-size:11px;color:var(--mt2)}
.task-assigned{font-size:11px;color:var(--mt)}
.task-actions{display:flex;gap:6px;flex-shrink:0}

/* MATERIAIS */
.mat-item{background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:14px 16px;margin-bottom:8px;display:flex;align-items:center;gap:12px}
.mat-info{flex:1;min-width:0}
.mat-name{font-size:14px;font-weight:500}
.mat-link{font-size:12px;color:var(--ac);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mat-meta{display:flex;gap:8px;margin-top:4px;flex-wrap:wrap;font-size:11px;color:var(--mt2)}
.mat-status{font-size:11px;padding:2px 8px;border-radius:4px;font-weight:600}
.status-pendente{background:rgba(251,191,36,.1);color:var(--yw)}
.status-enviado{background:rgba(52,211,153,.1);color:var(--gn)}

/* LOTES / TIMELINE */
.lot-timeline{position:relative;padding-left:28px}
.lot-timeline::before{content:'';position:absolute;left:10px;top:0;bottom:0;width:2px;background:var(--bd)}
.lot-item{position:relative;margin-bottom:16px}
.lot-dot{position:absolute;left:-22px;top:50%;transform:translateY(-50%);width:14px;height:14px;border-radius:50%;border:2px solid var(--ac2);background:var(--bg);z-index:1}
.lot-dot.past{border-color:var(--mt);background:var(--mt)}
.lot-dot.today{border-color:var(--gn);background:var(--gn);box-shadow:0 0 8px var(--gn)}
.lot-dot.upcoming{border-color:var(--ac2);background:var(--bg)}
.lot-card{background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:14px 16px}
.lot-card.upcoming{border-color:rgba(167,139,250,.3)}
.lot-header{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.lot-name{font-family:'Syne',sans-serif;font-size:14px;font-weight:700}
.lot-date{font-size:12px;color:var(--mt2)}
.lot-price{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--gn)}
.lot-type{font-size:10px;padding:2px 8px;border-radius:4px;font-weight:600}
.type-lote{background:rgba(79,110,247,.15);color:var(--ac)}
.type-sem_taxa{background:rgba(52,211,153,.15);color:var(--gn)}
.type-outros{background:rgba(255,255,255,.05);color:var(--mt2)}
.lot-material{margin-top:8px;font-size:12px;color:var(--ac)}

/* BOTÕES */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:10px;font-family:'Syne',sans-serif;font-weight:700;font-size:13px;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-p{background:linear-gradient(135deg,var(--ac),var(--ac2));color:#fff}.btn-p:hover{opacity:.85}
.btn-o{background:rgba(255,255,255,.05);border:1px solid var(--bd);color:var(--mt2)}.btn-o:hover{border-color:var(--ac);color:var(--ac)}
.btn-d{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.2);color:var(--rd)}.btn-d:hover{background:rgba(248,113,113,.2)}
.btn-sm{padding:5px 10px;font-size:11px}

/* MODAL */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;padding:16px}
.modal-bg.open{display:flex}
.modal{background:var(--sf);border:1px solid var(--bd);border-radius:20px;padding:28px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto}
.modal h2{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:20px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt);margin-bottom:6px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;background:var(--s2);border:1px solid var(--bd);border-radius:10px;color:var(--tx);font-family:'DM Sans',sans-serif;font-size:14px;outline:none}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--ac)}
.form-group textarea{resize:vertical;min-height:70px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.modal-actions{display:flex;gap:10px;margin-top:20px;justify-content:flex-end}

/* ADD ROW */
.add-row{display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px dashed var(--bd);border-radius:10px;color:var(--mt2);cursor:pointer;font-size:13px;transition:all .15s;margin-top:8px}
.add-row:hover{border-color:var(--ac);color:var(--ac)}

.budget-bar{height:10px;background:rgba(255,255,255,.06);border-radius:5px;overflow:hidden;margin:10px 0}
.budget-fill{height:100%;border-radius:5px;transition:width .6s}
.budget-fill.ok{background:linear-gradient(90deg,var(--gn),var(--ac))}
.budget-fill.warn{background:linear-gradient(90deg,var(--yw),var(--or))}
.budget-fill.over{background:linear-gradient(90deg,var(--or),var(--rd))}
.kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.kpi-box{background:var(--s2);border:1px solid var(--bd);border-radius:12px;padding:16px;text-align:center;min-width:0}
.kpi-box-val{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kpi-box-lbl{font-size:11px;color:var(--mt2);text-transform:uppercase;letter-spacing:.6px}
.camp-item{background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:12px 16px;margin-bottom:8px;display:flex;align-items:center;gap:12px}
.camp-status{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.camp-active{background:var(--gn)}
.camp-paused{background:var(--mt)}
.camp-info{flex:1;min-width:0}
.camp-name{font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.camp-obj{font-size:11px;color:var(--mt2);margin-top:2px}
.obj-group{margin-bottom:20px}
.obj-title{font-family:'Syne',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--mt2);margin-bottom:8px;display:flex;align-items:center;gap:8px}
.daily-chart{background:var(--s2);border-radius:12px;padding:16px;margin-bottom:20px}
.chart-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:4px}
.chart-bars{display:flex;align-items:flex-end;gap:3px;min-height:160px}
.chart-col{display:flex;flex-direction:column;align-items:center;gap:3px;flex-shrink:0;width:40px}
.chart-bar{background:linear-gradient(180deg,var(--ac),var(--ac2));border-radius:3px 3px 0 0;min-height:4px;width:30px}
.chart-bar:hover{background:linear-gradient(180deg,var(--gn),var(--ac))}
.chart-lbl{font-size:9px;color:var(--mt2);white-space:nowrap;text-align:center}

@media(max-width:768px){
  .main{margin-left:0 !important;padding:72px 16px 24px !important;max-width:100vw !important}
  .ev-title{font-size:18px}
  .tabs{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .tab{white-space:nowrap;flex-shrink:0}
  .form-row{grid-template-columns:1fr}
  .task-item{flex-wrap:wrap}
  .task-actions{width:100%;justify-content:flex-end}
  .kpi-row{grid-template-columns:repeat(2,1fr) !important}
}
</style>
<?php require_once __DIR__ . '/includes/theme.php'; echoThemeAssets(''); ?>
</head>
<body>
<?php
$currentPage = 'events';
require __DIR__ . '/includes/sidebar.php';
?>
<main class="main">
  <a class="back-link" href="events.php">← Voltar aos eventos</a>

  <div class="ev-header" id="evHeader">
    <div style="color:var(--mt2)">Carregando...</div>
  </div>

  <div class="tabs">
    <button class="tab" onclick="switchTab('tasks',this)">📋 Tarefas</button>
    <button class="tab" onclick="switchTab('materials',this)">📁 Materiais</button>
    <button class="tab" onclick="switchTab('lots',this)">🎟️ Lotes</button>
    <button class="tab active" onclick="switchTab('performance',this);loadPerformance()">📊 Performance</button>
    <button class="tab" onclick="switchTab('resumo',this)">📝 Resumo</button>
    <button class="tab" onclick="switchTab('suggestions',this);loadSuggestions()">💡 Sugestões</button>
    <?php if (in_array($role, ['admin','chefe','financeiro'])): ?>
    <button class="tab" onclick="switchTab('financial',this);loadFinancial()">💰 Financeiro</button>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
    <button class="tab" onclick="switchTab('campaigns',this);loadCampaigns()">📣 Campanhas</button>
    <button class="tab" onclick="switchTab('settings',this)">⚙️ Configurações</button>
    <?php endif; ?>
  </div>

  <!-- TAREFAS -->
  <div class="tab-content" id="tab-tasks">
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
      <button class="btn btn-p btn-sm" onclick="openTaskModal()">➕ Nova Tarefa</button>
    </div>
    <div id="tasksList"></div>
  </div>

  <!-- MATERIAIS -->
  <div class="tab-content" id="tab-materials">
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
      <button class="btn btn-p btn-sm" onclick="openMatModal()">➕ Novo Material</button>
    </div>
    <div id="matsList"></div>
  </div>

  <!-- LOTES -->
  <div class="tab-content" id="tab-lots">
    <?php if ($isAdmin): ?>
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
      <button class="btn btn-p btn-sm" onclick="openLotModal()">➕ Nova Virada</button>
    </div>
    <?php endif; ?>
    <div id="lotsList"></div>
  </div>

  <!-- PERFORMANCE -->
  <div class="tab-content active" id="tab-performance">
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:16px;position:relative" id="reportBtns">
      <div style="position:relative">
        <button class="btn btn-o btn-sm" onclick="toggleReportPeriodMenu()" title="Relatório parcial — escolha o período">
          📊 Relatório Parcial ▾
        </button>
        <div id="reportPeriodMenu" style="display:none;position:absolute;top:calc(100% + 6px);right:0;background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.3);min-width:200px;z-index:50;overflow:hidden">
          <button class="rp-menu-item" onclick="gerarRelatorioPeriodo('all')">Total até agora</button>
          <button class="rp-menu-item" onclick="gerarRelatorioPeriodo('today')">Hoje</button>
          <button class="rp-menu-item" onclick="gerarRelatorioPeriodo('yesterday')">Ontem</button>
          <button class="rp-menu-item" onclick="gerarRelatorioPeriodo('3')">Últimos 3 dias</button>
          <button class="rp-menu-item" onclick="gerarRelatorioPeriodo('7')">Últimos 7 dias</button>
          <button class="rp-menu-item" onclick="gerarRelatorioPeriodo('15')">Últimos 15 dias</button>
          <button class="rp-menu-item" onclick="gerarRelatorioPeriodo('30')">Últimos 30 dias</button>
        </div>
      </div>
      <button class="btn btn-p btn-sm" id="btnRelatorioFinal" style="display:none" onclick="gerarRelatorio('final')" title="Relatório final do evento encerrado">
        🏁 Relatório Final
      </button>
    </div>
    <div id="perfContent" style="color:var(--mt2);text-align:center;padding:40px">Carregando...</div>
  </div>

  <style>
  .rp-menu-item{display:block;width:100%;text-align:left;padding:10px 14px;background:transparent;border:none;border-bottom:1px solid var(--bd,var(--border));color:var(--tx,var(--text));font-size:13px;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .15s}
  .rp-menu-item:last-child{border-bottom:none}
  .rp-menu-item:hover{background:rgba(79,110,247,.1);color:var(--ac,var(--accent))}
  </style>

  <!-- RESUMO -->
  <div class="tab-content" id="tab-resumo">
    <div style="background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:14px;padding:20px;margin-bottom:20px">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt2);margin-bottom:10px">Período</div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px" id="resumoPeriodBtns">
        <button class="rs-pbtn active" onclick="setResumoPeriod('all',this)">Todo período</button>
        <button class="rs-pbtn" onclick="setResumoPeriod('today',this)">Hoje</button>
        <button class="rs-pbtn" onclick="setResumoPeriod('yesterday',this)">Ontem</button>
        <button class="rs-pbtn" onclick="setResumoPeriod('3',this)">3 dias</button>
        <button class="rs-pbtn" onclick="setResumoPeriod('7',this)">7 dias</button>
        <button class="rs-pbtn" onclick="setResumoPeriod('15',this)">15 dias</button>
        <button class="rs-pbtn" onclick="setResumoPeriod('30',this)">30 dias</button>
      </div>
      <button class="btn btn-p btn-sm" onclick="gerarResumoEvento()" style="width:100%" id="btnGerarResumo">📊 Gerar resumo</button>
    </div>

    <div id="resumoResultCard" style="background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:14px;overflow:hidden;display:none">
      <div style="padding:14px 18px;border-bottom:1px solid var(--bd,var(--border));display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700" id="resumoResultTitle">Resumo</div>
        <button onclick="copyResumoAll()" id="btnCopyResumoAll" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.25);color:var(--gn);font-family:'Syne',sans-serif;font-weight:700;font-size:12px;cursor:pointer">📋 Copiar tudo</button>
      </div>
      <div id="resumoBlocksContainer" style="display:flex;flex-direction:column;gap:10px;padding:18px"></div>
    </div>
  </div>

  <style>
  .rs-pbtn{padding:7px 14px;border-radius:8px;border:1px solid var(--bd,var(--border));background:transparent;color:var(--mt2);font-size:12px;font-family:'Syne',sans-serif;font-weight:700;cursor:pointer;transition:all .15s}
  .rs-pbtn:hover{border-color:var(--ac,var(--accent));color:var(--ac,var(--accent))}
  .rs-pbtn.active{background:rgba(79,110,247,.15);border-color:var(--ac,var(--accent));color:var(--tx,var(--text))}
  .rs-block{background:var(--s2,var(--surface2));border:1px solid var(--bd,var(--border));border-radius:10px;overflow:hidden}
  .rs-block-header{padding:9px 14px;border-bottom:1px solid var(--bd,var(--border));display:flex;align-items:center;justify-content:space-between}
  .rs-block-title{font-size:11px;font-weight:600;color:var(--mt2);text-transform:uppercase;letter-spacing:.5px}
  .rs-block-copy{background:none;border:none;color:var(--ac,var(--accent));font-size:11px;cursor:pointer;font-family:'DM Sans',sans-serif;padding:2px 6px}
  .rs-block-copy:hover{text-decoration:underline}
  .rs-block-text{padding:12px 14px;font-family:monospace;font-size:13px;line-height:1.8;color:var(--tx,var(--text));white-space:pre-wrap}
  .sg-step{background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:14px;padding:18px;margin-bottom:14px}
  .sg-step-num{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:rgba(79,110,247,.15);color:var(--ac,var(--accent));font-family:'Syne',sans-serif;font-weight:800;font-size:12px;margin-right:8px}
  .sg-step-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;display:flex;align-items:center;margin-bottom:10px}
  .sg-prompt-box{background:var(--s2,var(--surface2));border:1px solid var(--bd,var(--border));border-radius:10px;padding:14px;font-family:monospace;font-size:12px;line-height:1.7;color:var(--tx,var(--text));white-space:pre-wrap;max-height:260px;overflow-y:auto;margin-bottom:10px}
  .sg-textarea{width:100%;min-height:160px;background:var(--s2,var(--surface2));border:1px solid var(--bd,var(--border));border-radius:10px;padding:12px;font-family:'DM Sans',sans-serif;font-size:13px;color:var(--tx,var(--text));resize:vertical;margin-bottom:10px}
  .sg-card{background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:12px;padding:16px;margin-bottom:12px}
  .sg-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;gap:10px;flex-wrap:wrap}
  .sg-card-meta{font-size:11px;color:var(--mt2)}
  .sg-card-text{font-size:13px;line-height:1.7;color:var(--tx,var(--text));white-space:pre-wrap}
  .sg-del{background:none;border:none;color:var(--rd);font-size:11px;cursor:pointer;font-family:'DM Sans',sans-serif}
  .sg-del:hover{text-decoration:underline}
  </style>

  <!-- SUGESTÕES -->
  <div class="tab-content" id="tab-suggestions">

    <div class="sg-step">
      <div class="sg-step-title"><span class="sg-step-num">1</span>Gere o prompt de análise</div>
      <p style="font-size:12px;color:var(--mt2);margin-bottom:12px">
        Isso monta um texto com a conta e as campanhas ativas deste evento. Copie e cole na conversa com o Claude (fora do MetaDash) para rodar a análise via Facebook Ads MCP.
      </p>
      <button class="btn btn-p btn-sm" onclick="generateSuggestionPrompt()" id="btnGenPrompt">⚙️ Gerar prompt</button>
      <div id="sgPromptWrap" style="display:none;margin-top:14px">
        <div class="sg-prompt-box" id="sgPromptBox"></div>
        <button class="btn btn-o btn-sm" onclick="copySuggestionPrompt()" id="btnCopyPrompt">📋 Copiar prompt</button>
      </div>
    </div>

    <div class="sg-step">
      <div class="sg-step-title"><span class="sg-step-num">2</span>Cole a resposta do Claude aqui</div>
      <textarea class="sg-textarea" id="sgPasteArea" placeholder="Cole aqui o resumo de sugestões que o Claude te devolveu..."></textarea>
      <button class="btn btn-p btn-sm" onclick="saveSuggestion()" id="btnSaveSuggestion">💾 Salvar sugestão</button>
    </div>

    <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--mt2);text-transform:uppercase;letter-spacing:.8px;margin:24px 0 12px">Histórico</div>
    <div id="suggestionsList" style="color:var(--mt2);font-size:13px">Carregando...</div>

  </div>

  <!-- FINANCEIRO -->
  <div class="tab-content" id="tab-financial">
    <div id="financialContent" style="color:var(--mt2);text-align:center;padding:40px">Carregando...</div>
  </div>

  <?php if ($isAdmin): ?>
  <!-- CAMPANHAS -->
  <div class="tab-content" id="tab-campaigns">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
      <div style="font-size:13px;color:var(--mt2)" id="campInfo">Carregando campanhas...</div>
      <div style="display:flex;gap:8px">
        <input type="text" id="campSearch" placeholder="Buscar campanha..." oninput="filterCampaigns()" style="padding:7px 12px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-size:13px;outline:none;width:220px">
        <button class="btn btn-p btn-sm" onclick="openLinkCampaigns()">🔗 Vincular campanhas</button>
      </div>
    </div>
    <div id="campList"></div>
  </div>
  <?php endif; ?>

  <?php if ($isAdmin): ?>
  <!-- CONFIGURAÇÕES -->
  <div class="tab-content" id="tab-settings">
    <div style="background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:24px">
      <div class="form-group">
        <label>Nome do evento</label>
        <input type="text" id="cfgName">
      </div>
      <div class="form-group">
        <label>Descrição</label>
        <textarea id="cfgDesc"></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Data do evento</label>
          <input type="date" id="cfgDate">
        </div>
        <div class="form-group">
          <label>Início campanhas</label>
          <input type="date" id="cfgCampStart">
        </div>
      </div>
      <div class="form-group">
        <label>Fim campanhas</label>
        <input type="date" id="cfgCampEnd">
      </div>
      <div class="form-group">
        <label>Status</label>
        <select id="cfgStatus">
          <option value="active">Ativo</option>
          <option value="finished">Encerrado</option>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Conta de anúncios</label>
          <select id="cfgAccount">
            <option value="">Sem conta vinculada</option>
            <?php
            try {
              $allAccounts = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE status='ACTIVE' ORDER BY account_name");
            } catch (Exception $e) { $allAccounts = []; }
            foreach ($allAccounts as $acc):
            ?>
            <option value="<?= htmlspecialchars($acc['account_id']) ?>"><?= htmlspecialchars($acc['account_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Budget total (R$)</label>
          <input type="number" id="cfgBudget" placeholder="Ex: 50000" min="0" step="100">
        </div>
      </div>
      <div class="form-group">
        <label>Usuários com acesso</label>
        <div class="user-checkboxes" id="cfgUsers" style="display:flex;flex-direction:column;gap:6px;max-height:180px;overflow-y:auto"></div>
      </div>
      <button class="btn btn-p" onclick="saveSettings()">💾 Salvar configurações</button>
    </div>
  </div>
  <?php endif; ?>
</main>

<!-- MODAL TAREFA -->
<div class="modal-bg" id="modalTask">
  <div class="modal">
    <h2 id="taskModalTitle">➕ Nova Tarefa</h2>
    <input type="hidden" id="taskId">
    <div class="form-group">
      <label>Título *</label>
      <input type="text" id="taskTitle" placeholder="O que precisa ser feito?">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Categoria</label>
        <select id="taskCat">
          <option value="criativo">🎨 Criativo</option>
          <option value="copy">✍️ Copy</option>
          <option value="trafego">📊 Tráfego</option>
          <option value="lote">🎟️ Lote</option>
          <option value="material">📁 Material</option>
          <option value="outro">📌 Outro</option>
        </select>
      </div>
      <div class="form-group">
        <label>Data limite</label>
        <input type="date" id="taskDue">
      </div>
    </div>
    <div class="form-group">
      <label>Responsável</label>
      <select id="taskAssigned">
        <option value="">Sem responsável</option>
      </select>
    </div>
    <div class="form-group">
      <label>Observações</label>
      <textarea id="taskNotes" placeholder="Detalhes opcionais..."></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn btn-o" onclick="closeModal('modalTask')">Cancelar</button>
      <button class="btn btn-p" onclick="saveTask()">✅ Salvar</button>
    </div>
  </div>
</div>

<!-- MODAL MATERIAL -->
<div class="modal-bg" id="modalMat">
  <div class="modal">
    <h2>📁 Novo Material</h2>
    <input type="hidden" id="matId">
    <div class="form-group">
      <label>Nome *</label>
      <input type="text" id="matName" placeholder="Ex: Arte do Stories Lote 2">
    </div>
    <div class="form-group">
      <label>Link (Drive, Canva, etc)</label>
      <input type="url" id="matLink" placeholder="https://...">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Data de entrega</label>
        <input type="date" id="matDue">
      </div>
      <div class="form-group">
        <label>Status</label>
        <select id="matStatus">
          <option value="pendente">Pendente</option>
          <option value="enviado">Enviado</option>
        </select>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-o" onclick="closeModal('modalMat')">Cancelar</button>
      <button class="btn btn-p" onclick="saveMaterial()">✅ Salvar</button>
    </div>
  </div>
</div>

<!-- MODAL LOTE -->
<div class="modal-bg" id="modalLot">
  <div class="modal">
    <h2>🎟️ Virada de Lote</h2>
    <div class="form-group">
      <label>Nome *</label>
      <input type="text" id="lotName" placeholder="Ex: Lote 2, Sem Taxa, Último Lote">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Tipo</label>
        <select id="lotType">
          <option value="lote">🎟️ Lote</option>
          <option value="sem_taxa">✅ Sem Taxa</option>
          <option value="outros">📌 Outros</option>
        </select>
      </div>
      <div class="form-group">
        <label>Data *</label>
        <input type="date" id="lotDate">
      </div>
    </div>
    <div class="form-group">
      <label>Preço (R$)</label>
      <input type="number" id="lotPrice" placeholder="Ex: 80.00" step="0.01" min="0">
    </div>
    <div class="form-group">
      <label>Material de divulgação</label>
      <input type="text" id="lotMatName" placeholder="Nome do material">
      <input type="url" id="lotMatLink" placeholder="Link (Drive, Canva...)" style="margin-top:8px">
    </div>
    <div class="modal-actions">
      <button class="btn btn-o" onclick="closeModal('modalLot')">Cancelar</button>
      <button class="btn btn-p" onclick="saveLot()">✅ Salvar</button>
    </div>
  </div>
</div>

<script>
var EVENT_ID = <?= $eventId ?>;
var IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
var USER_ROLE = <?= json_encode($role) ?>;
var EVENT_DATA = null;
var ALL_USERS = [];

async function api(action, body) {
  var r = await fetch('api/events.php?action='+action, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify(Object.assign({action:action}, body||{}))
  });
  return r.json();
}

async function loadEvent() {
  var d = await api('get_event', {id: EVENT_ID});
  if (d.error) { alert(d.error); return; }
  EVENT_DATA = d.event;
  renderHeader();
  renderTasks();
  renderMaterials();
  renderLots();
  if (IS_ADMIN) populateSettings();

  // Financeiro vai direto pra aba Financeiro
  if (USER_ROLE === 'financeiro') {
    var btn = document.querySelector('.tab[onclick*="financial"]');
    if (btn) { switchTab('financial', btn); loadFinancial(); }
  } else {
    loadPerformance();
  }

  checkRelatorioFinalBtn();
}

async function loadUsers() {
  var d = await api('get_users');
  ALL_USERS = d.users || [];
}

function renderHeader() {
  var e = EVENT_DATA;
  var evDate = e.date_event ? new Date(e.date_event+'T00:00:00').toLocaleDateString('pt-BR') : '—';
  var campStart = e.date_campaign_start ? new Date(e.date_campaign_start+'T00:00:00').toLocaleDateString('pt-BR') : null;
  var campEnd   = e.date_campaign_end   ? new Date(e.date_campaign_end+'T00:00:00').toLocaleDateString('pt-BR') : null;

  var pct = 0;
  var markersHtml = '';
  if (e.date_campaign_start && e.date_event) {
    var start = new Date(e.date_campaign_start+'T00:00:00').getTime();
    var end   = new Date(e.date_event+'T00:00:00').getTime();
    var now   = Date.now();
    pct = Math.min(100, Math.max(0, Math.round((now-start)/(end-start)*100)));

    // Marcadores de lotes na timeline
    if (e.lots && e.lots.length) {
      markersHtml = e.lots.map(function(l) {
        var lp = Math.min(100,Math.max(0,Math.round((new Date(l.lot_date+'T00:00:00').getTime()-start)/(end-start)*100)));
        return '<div class="timeline-marker" style="left:'+lp+'%">'+esc(l.name)+'</div>';
      }).join('');
    }
  }

  var diff = e.date_event ? Math.ceil((new Date(e.date_event+'T00:00:00')-Date.now())/86400000) : null;
  var daysLabel = diff !== null ? (diff > 0 ? diff+' dias para o evento' : diff === 0 ? '🎉 Hoje!' : 'Encerrado') : '';

  document.getElementById('evHeader').innerHTML =
    '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">'+
      '<div style="flex:1;min-width:0">'+
        '<div class="ev-title">'+esc(e.name)+'</div>'+
        (e.description?'<div style="color:var(--mt2);font-size:13px;margin-bottom:10px">'+esc(e.description)+'</div>':'')+
        '<div class="ev-info">'+
          '<div class="ev-info-item">📅 <strong>'+evDate+'</strong></div>'+
          (campStart?'<div class="ev-info-item">🚀 Campanhas: <strong>'+campStart+(campEnd?' → '+campEnd:'')+'</strong></div>':'')+
          (daysLabel?'<div class="ev-info-item" style="color:var(--ac2)"><strong>'+daysLabel+'</strong></div>':'')+
        '</div>'+
        (e.date_campaign_start&&e.date_event?
          '<div class="timeline">'+
            '<div class="timeline-bar"><div class="timeline-fill" style="width:'+pct+'%"></div></div>'+
            (markersHtml?'<div class="timeline-markers">'+markersHtml+'</div>':'')+
          '</div>':'')+
      '</div>'+
    '</div>';
  document.title = esc(e.name) + ' — MetaDash';
}

function renderTasks() {
  var tasks = EVENT_DATA.tasks || [];
  var container = document.getElementById('tasksList');
  if (!tasks.length) {
    container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--mt2)">📋 Nenhuma tarefa ainda. Adicione a primeira!</div>';
    return;
  }

  // Agrupa por data
  var groups = {};
  tasks.forEach(function(t) {
    var key = t.due_date || 'sem_data';
    if (!groups[key]) groups[key] = [];
    groups[key].push(t);
  });

  var keys = Object.keys(groups).sort(function(a,b){
    if (a==='sem_data') return 1;
    if (b==='sem_data') return -1;
    return a > b ? 1 : -1;
  });

  container.innerHTML = keys.map(function(key) {
    var label = key==='sem_data' ? '📌 Sem data definida' : '📅 '+new Date(key+'T00:00:00').toLocaleDateString('pt-BR',{weekday:'long',day:'2-digit',month:'long'});
    var items = groups[key].map(function(t) {
      var isDone = t.status==='concluido';
      var isDoing = t.status==='em_andamento';
      var checkCls = isDone?'done':isDoing?'doing':'';
      var checkIcon = isDone?'✓':isDoing?'◐':'';
      return '<div class="task-item" id="task-'+t.id+'">'+
        '<div class="task-check '+checkCls+'" onclick="cycleStatus('+t.id+',\''+t.status+'\')">'+checkIcon+'</div>'+
        '<div class="task-info">'+
          '<div class="task-title'+(isDone?' done':'')+'">'+esc(t.title)+'</div>'+
          '<div class="task-meta">'+
            '<span class="task-cat cat-'+t.category+'">'+catLabel(t.category)+'</span>'+
            (t.assigned_name?'<span class="task-assigned">👤 '+esc(t.assigned_name)+'</span>':'')+
            (t.notes?'<span class="task-due" title="'+esc(t.notes)+'">📝 Nota</span>':'')+
          '</div>'+
        '</div>'+
        (IS_ADMIN?'<div class="task-actions">'+
          '<button class="btn btn-o btn-sm" onclick="editTask('+t.id+')">✏️</button>'+
          '<button class="btn btn-d btn-sm" onclick="deleteTask('+t.id+')">🗑️</button>'+
        '</div>':'')+
      '</div>';
    }).join('');
    return '<div class="task-group"><div class="task-group-title">'+label+'</div>'+items+'</div>';
  }).join('');
}

function renderMaterials() {
  var mats = EVENT_DATA.materials || [];
  var container = document.getElementById('matsList');
  if (!mats.length) {
    container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--mt2)">📁 Nenhum material ainda.</div>';
    return;
  }
  container.innerHTML = mats.map(function(m) {
    return '<div class="mat-item">'+
      '<div class="mat-info">'+
        '<div class="mat-name">'+esc(m.name)+'</div>'+
        (m.link?'<div class="mat-link"><a href="'+esc(m.link)+'" target="_blank" style="color:var(--ac)">🔗 '+esc(m.link)+'</a></div>':'')+
        '<div class="mat-meta">'+
          (m.due_date?'<span>📅 '+new Date(m.due_date+'T00:00:00').toLocaleDateString('pt-BR')+'</span>':'')+
          '<span class="mat-status status-'+m.status+'">'+m.status+'</span>'+
        '</div>'+
      '</div>'+
      '<div style="display:flex;gap:6px;flex-shrink:0">'+
        '<button class="btn btn-o btn-sm" onclick="editMat('+m.id+')">✏️</button>'+
        '<button class="btn btn-d btn-sm" onclick="deleteMat('+m.id+')">🗑️</button>'+
      '</div>'+
    '</div>';
  }).join('');
}

function renderLots() {
  var lots = EVENT_DATA.lots || [];
  var container = document.getElementById('lotsList');
  if (!lots.length) {
    container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--mt2)">🎟️ Nenhuma virada de lote cadastrada.</div>';
    return;
  }
  var today = new Date().toISOString().split('T')[0];
  container.innerHTML = '<div class="lot-timeline">'+lots.map(function(l) {
    var isPast = l.lot_date < today;
    var isToday = l.lot_date === today;
    var dotCls = isPast?'past':isToday?'today':'upcoming';
    var price = l.price ? 'R$'+parseFloat(l.price).toLocaleString('pt-BR',{minimumFractionDigits:2}) : '';
    var lDate = new Date(l.lot_date+'T00:00:00').toLocaleDateString('pt-BR',{weekday:'short',day:'2-digit',month:'short'});
    var diff = Math.ceil((new Date(l.lot_date+'T00:00:00')-Date.now())/86400000);
    var diffLabel = diff > 0 ? 'em '+diff+' dias' : diff === 0 ? 'hoje' : Math.abs(diff)+' dias atrás';
    return '<div class="lot-item">'+
      '<div class="lot-dot '+dotCls+'"></div>'+
      '<div class="lot-card'+(dotCls==='upcoming'?' upcoming':'')+'">'+
        '<div class="lot-header">'+
          '<div>'+
            '<div class="lot-name">'+esc(l.name)+'</div>'+
            '<div class="lot-date">'+lDate+' · '+diffLabel+'</div>'+
          '</div>'+
          '<div style="display:flex;align-items:center;gap:8px">'+
            (price?'<div class="lot-price">'+price+'</div>':'')+
            '<span class="lot-type type-'+l.type+'">'+typeLabel(l.type)+'</span>'+
            (IS_ADMIN?'<button class="btn btn-d btn-sm" onclick="deleteLot('+l.id+')">🗑️</button>':'')+
          '</div>'+
        '</div>'+
        (l.material_name||l.material_link?'<div class="lot-material">'+
          (l.material_link?'<a href="'+esc(l.material_link)+'" target="_blank" style="color:var(--ac)">📁 '+esc(l.material_name||'Ver material')+'</a>':'📁 '+esc(l.material_name))+
        '</div>':'')+
      '</div>'+
    '</div>';
  }).join('')+'</div>';
}

// ── STATUS CYCLE ──────────────────────────────────────────────
async function cycleStatus(id, current) {
  var next = current==='pendente'?'em_andamento':current==='em_andamento'?'concluido':'pendente';
  await api('update_task', {id:id, status:next});
  var task = EVENT_DATA.tasks.find(function(t){return t.id==id;});
  if (task) task.status = next;
  renderTasks();
}

// ── TAREFAS CRUD ──────────────────────────────────────────────
async function openTaskModal(task) {
  await loadUsers();
  document.getElementById('taskModalTitle').textContent = task ? '✏️ Editar Tarefa' : '➕ Nova Tarefa';
  document.getElementById('taskId').value     = task ? task.id : '';
  document.getElementById('taskTitle').value  = task ? task.title : '';
  document.getElementById('taskCat').value    = task ? task.category : 'outro';
  document.getElementById('taskDue').value    = task ? (task.due_date||'') : '';
  document.getElementById('taskNotes').value  = task ? (task.notes||'') : '';

  var sel = document.getElementById('taskAssigned');
  sel.innerHTML = '<option value="">Sem responsável</option>' +
    ALL_USERS.map(function(u){ return '<option value="'+u.id+'">'+esc(u.username)+'</option>'; }).join('');
  if (task && task.assigned_to) sel.value = task.assigned_to;

  document.getElementById('modalTask').classList.add('open');
}

async function saveTask() {
  var id    = document.getElementById('taskId').value;
  var title = document.getElementById('taskTitle').value.trim();
  if (!title) { alert('Título obrigatório'); return; }

  var body = {
    title: title, category: document.getElementById('taskCat').value,
    due_date: document.getElementById('taskDue').value || null,
    assigned_to: document.getElementById('taskAssigned').value || null,
    notes: document.getElementById('taskNotes').value
  };

  if (id) {
    body.id = parseInt(id);
    await api('update_task', body);
    var idx = EVENT_DATA.tasks.findIndex(function(t){return t.id==id;});
    if (idx>=0) Object.assign(EVENT_DATA.tasks[idx], body);
  } else {
    body.event_id = EVENT_ID;
    var d = await api('create_task', body);
    if (d.error) { alert(d.error); return; }
    EVENT_DATA.tasks.push(d.task);
  }
  closeModal('modalTask');
  renderTasks();
}

function editTask(id) {
  var t = EVENT_DATA.tasks.find(function(t){return t.id==id;});
  if (t) openTaskModal(t);
}

async function deleteTask(id) {
  if (!confirm('Remover tarefa?')) return;
  await api('delete_task', {id:id});
  EVENT_DATA.tasks = EVENT_DATA.tasks.filter(function(t){return t.id!=id;});
  renderTasks();
}

// ── MATERIAIS CRUD ────────────────────────────────────────────
function openMatModal(mat) {
  document.getElementById('matId').value    = mat ? mat.id : '';
  document.getElementById('matName').value  = mat ? mat.name : '';
  document.getElementById('matLink').value  = mat ? (mat.link||'') : '';
  document.getElementById('matDue').value   = mat ? (mat.due_date||'') : '';
  document.getElementById('matStatus').value = mat ? mat.status : 'pendente';
  document.getElementById('modalMat').classList.add('open');
}

async function saveMaterial() {
  var id   = document.getElementById('matId').value;
  var name = document.getElementById('matName').value.trim();
  if (!name) { alert('Nome obrigatório'); return; }
  var body = {
    name: name, link: document.getElementById('matLink').value,
    due_date: document.getElementById('matDue').value || null,
    status: document.getElementById('matStatus').value
  };
  if (id) {
    body.id = parseInt(id);
    await api('update_material', body);
    var idx = EVENT_DATA.materials.findIndex(function(m){return m.id==id;});
    if (idx>=0) Object.assign(EVENT_DATA.materials[idx], body);
  } else {
    body.event_id = EVENT_ID;
    var d = await api('create_material', body);
    if (d.error) { alert(d.error); return; }
    EVENT_DATA.materials.push(d.material);
  }
  closeModal('modalMat');
  renderMaterials();
}

function editMat(id) {
  var m = EVENT_DATA.materials.find(function(m){return m.id==id;});
  if (m) openMatModal(m);
}

async function deleteMat(id) {
  if (!confirm('Remover material?')) return;
  await api('delete_material', {id:id});
  EVENT_DATA.materials = EVENT_DATA.materials.filter(function(m){return m.id!=id;});
  renderMaterials();
}

// ── LOTES CRUD ────────────────────────────────────────────────
function openLotModal() {
  document.getElementById('lotName').value    = '';
  document.getElementById('lotType').value    = 'lote';
  document.getElementById('lotDate').value    = '';
  document.getElementById('lotPrice').value   = '';
  document.getElementById('lotMatName').value = '';
  document.getElementById('lotMatLink').value = '';
  document.getElementById('modalLot').classList.add('open');
}

async function saveLot() {
  var name = document.getElementById('lotName').value.trim();
  var date = document.getElementById('lotDate').value;
  if (!name || !date) { alert('Nome e data obrigatórios'); return; }
  var d = await api('create_lot', {
    event_id: EVENT_ID, name: name,
    lot_date: date, type: document.getElementById('lotType').value,
    price: document.getElementById('lotPrice').value || null,
    material_name: document.getElementById('lotMatName').value,
    material_link: document.getElementById('lotMatLink').value
  });
  if (d.error) { alert(d.error); return; }
  EVENT_DATA.lots.push(d.lot);
  EVENT_DATA.lots.sort(function(a,b){ return a.lot_date > b.lot_date ? 1:-1; });
  closeModal('modalLot');
  renderLots();
  renderHeader(); // atualiza timeline
}

async function deleteLot(id) {
  if (!confirm('Remover virada de lote?')) return;
  await api('delete_lot', {id:id});
  EVENT_DATA.lots = EVENT_DATA.lots.filter(function(l){return l.id!=id;});
  renderLots();
  renderHeader();
}

// ── CONFIGURAÇÕES ─────────────────────────────────────────────
async function populateSettings() {
  var e = EVENT_DATA;
  document.getElementById('cfgName').value      = e.name;
  document.getElementById('cfgDesc').value      = e.description || '';
  document.getElementById('cfgDate').value      = e.date_event || '';
  document.getElementById('cfgCampStart').value = e.date_campaign_start || '';
  document.getElementById('cfgCampEnd').value   = e.date_campaign_end || '';
  document.getElementById('cfgStatus').value    = e.status;
  var cfgAcc = document.getElementById('cfgAccount');
  var cfgBud = document.getElementById('cfgBudget');
  if (cfgAcc) cfgAcc.value = e.account_id || '';
  if (cfgBud) cfgBud.value = e.budget > 0 ? e.budget : '';

  await loadUsers();
  var currentIds = (e.users||[]).map(function(u){return u.id;});
  var roleBadge = {'chefe':'👑','financeiro':'💰','marketing':'🎨','user':'👤'};
  var box = document.getElementById('cfgUsers');
  box.innerHTML = ALL_USERS.map(function(u){
    var badge = roleBadge[u.role] || '👤';
    return '<label style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--s2);border-radius:8px;border:1px solid var(--bd);cursor:pointer">'+
      '<input type="checkbox" value="'+u.id+'"'+(currentIds.includes(parseInt(u.id))?' checked':'')+' style="width:16px;height:16px;accent-color:var(--ac);cursor:pointer">'+
      '<span style="font-size:13px">'+badge+' '+esc(u.username)+'</span>'+
      '<span style="font-size:10px;color:var(--mt2);margin-left:auto">'+esc(u.role)+'</span></label>';
  }).join('');
}

async function saveSettings() {
  var userIds = Array.from(document.querySelectorAll('#cfgUsers input:checked')).map(function(i){return parseInt(i.value);});
  var d = await api('update_event', {
    id: EVENT_ID,
    name: document.getElementById('cfgName').value,
    description: document.getElementById('cfgDesc').value,
    date_event: document.getElementById('cfgDate').value,
    date_campaign_start: document.getElementById('cfgCampStart').value || null,
    date_campaign_end: document.getElementById('cfgCampEnd').value || null,
    status: document.getElementById('cfgStatus').value,
    user_ids: userIds,
    account_id: document.getElementById('cfgAccount').value || null,
    budget: parseFloat(document.getElementById('cfgBudget').value) || 0
  });
  if (d.error) { alert(d.error); return; }
  alert('✅ Configurações salvas!');
  location.reload();
}

// ── HELPERS ───────────────────────────────────────────────────
function switchTab(name, btn) {
  document.querySelectorAll('.tab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('.tab-content').forEach(function(t){t.classList.remove('active');});
  btn.classList.add('active');
  document.getElementById('tab-'+name).classList.add('active');
}
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
function catLabel(c){var m={criativo:'🎨 Criativo',copy:'✍️ Copy',trafego:'📊 Tráfego',lote:'🎟️ Lote',material:'📁 Material',outro:'📌 Outro'};return m[c]||c;}
function typeLabel(t){var m={lote:'🎟️ Lote',sem_taxa:'✅ Sem Taxa',outros:'📌 Outros'};return m[t]||t;}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

loadEvent();

// ── PERFORMANCE ───────────────────────────────────────────────
var perfLoaded = false;
async function loadPerformance() {
  if (perfLoaded) return;
  perfLoaded = true;
  var container = document.getElementById('perfContent');
  if (!container) return;

  try {
    var r = await fetch('api/event_performance.php?action=get_performance', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_performance', event_id: EVENT_ID})
    });
    var d = await r.json();
    if (!d.success) { container.innerHTML = '<div style="color:var(--mt2)">Configure a conta e campanhas primeiro.</div>'; return; }

    var p       = d.perf || {};
    var spend   = parseFloat(p.spend)   || 0;
    var purch   = parseInt(p.purchases) || 0;
    var leads   = parseInt(p.leads)     || 0;
    var revenue = parseFloat(p.revenue) || 0;
    var roas    = spend > 0 ? (revenue/spend).toFixed(2) : 0;
    var roi     = spend > 0 ? (((revenue-spend)/spend)*100).toFixed(1) : 0;
    var cpa     = purch > 0 ? spend/purch : 0;
    var cpl     = leads > 0 ? spend/leads : 0;
    var budget  = parseFloat(d.budget)  || 0;
    var bPct    = d.budget_pct;
    var bLeft   = d.budget_left;

    var fillClass = bPct >= 90 ? 'over' : bPct >= 70 ? 'warn' : 'ok';

    var html = '';

    // Budget
    if (budget > 0) {
      html += '<div style="background:var(--s2);border-radius:12px;padding:16px;margin-bottom:20px;overflow:hidden;max-width:100%">';
      html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
      html += '<span style="font-family:Syne,sans-serif;font-size:14px;font-weight:700">💰 Budget do Evento</span>';
      if (IS_ADMIN) html += '<button class="btn btn-o btn-sm" onclick="editBudget()">✏️ Editar</button>';
      html += '</div>';
      html += '<div style="font-size:24px;font-family:Syne,sans-serif;font-weight:900;color:var(--gn)">R$'+fmtN(spend)+'</div>';
      html += '<div style="font-size:12px;color:var(--mt2);margin-bottom:8px">de R$'+fmtN(budget)+' · '+bPct+'% utilizado</div>';
      html += '<div class="budget-bar" style="max-width:100%;overflow:hidden"><div class="budget-fill '+fillClass+'" style="width:'+Math.min(100,bPct)+'%"></div></div>';
      html += '<div style="font-size:12px;color:var(--mt2)">Restante: <strong style="color:var(--tx)">R$'+fmtN(bLeft)+'</strong></div>';
      html += '<div id="budgetProjection" style="margin-top:12px;font-size:12px;color:var(--mt2)">⏳ Calculando projeção...</div>';
      html += '</div>';
    }

    // KPIs
    html += '<div class="kpi-row">';
    html += '<div class="kpi-box"><div class="kpi-box-val" style="color:var(--rd)">R$'+fmtN(spend)+'</div><div class="kpi-box-lbl">Gasto total</div></div>';
    html += '<div class="kpi-box"><div class="kpi-box-val" style="color:var(--gn)">'+purch+'</div><div class="kpi-box-lbl">Compras</div></div>';
    html += '<div class="kpi-box"><div class="kpi-box-val" style="color:var(--gn)">R$'+fmtN(revenue)+'</div><div class="kpi-box-lbl">Faturado</div></div>';
    html += '<div class="kpi-box"><div class="kpi-box-val" style="color:var(--ac)">'+roas+'x</div><div class="kpi-box-lbl">ROAS</div></div>';
    html += '<div class="kpi-box"><div class="kpi-box-val" style="color:var(--yw)">'+roi+'%</div><div class="kpi-box-lbl">ROI</div></div>';
    if (cpa > 0) html += '<div class="kpi-box"><div class="kpi-box-val" style="color:var(--yw)">R$'+fmtN(cpa)+'</div><div class="kpi-box-lbl">Custo/compra</div></div>';
    if (leads > 0) html += '<div class="kpi-box"><div class="kpi-box-val" style="color:var(--ac2)">'+leads+'</div><div class="kpi-box-lbl">Leads</div></div>';
    if (cpl > 0) html += '<div class="kpi-box"><div class="kpi-box-val" style="color:var(--ac2)">R$'+fmtN(cpl)+'</div><div class="kpi-box-lbl">Custo/lead</div></div>';
    html += '</div>';

    // Gráfico de gasto por dia
    var daily = d.daily || [];
    if (daily.length > 0) {
      var maxSpend = Math.max.apply(null, daily.map(function(x){ return parseFloat(x.spend)||0; })) || 1;
      html += '<div class="daily-chart">';
      html += '<div style="font-family:Syne,sans-serif;font-size:13px;font-weight:700;margin-bottom:12px">📅 Gasto por dia</div>';
      var totalW = daily.length * 43;
      html += '<div class="chart-scroll"><div class="chart-bars" style="width:'+totalW+'px">';
      html += daily.map(function(row) {
        var s  = parseFloat(row.spend) || 0;
        var h  = Math.max(4, Math.round(s / maxSpend * 110));
        var dt = new Date(row.snapshot_date+'T00:00:00').toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'});
        var val = s >= 1000 ? 'R$'+Math.round(s/1000)+'k' : 'R$'+Math.round(s);
        return '<div class="chart-col">'+
          '<div class="chart-lbl">'+val+'</div>'+
          '<div class="chart-bar" style="height:'+h+'px"></div>'+
          '<div class="chart-lbl">'+dt+'</div>'+
        '</div>';
      }).join('');
      html += '</div></div></div>';
    }

    // Configurar budget se não tem
    if (!budget && IS_ADMIN) {
      html += '<div style="background:rgba(79,110,247,.08);border:1px solid rgba(79,110,247,.2);border-radius:10px;padding:14px 16px;margin-top:8px;font-size:13px;color:var(--ac)">';
      html += '💡 Configure a conta e o budget do evento em <button onclick="switchTabByName(\'settings\')" style="background:none;border:none;color:var(--ac);text-decoration:underline;cursor:pointer;font-size:13px">Configuracoes</button>';
      html += '</div>';
    }

    container.style.overflow = 'hidden';
    container.innerHTML = html;

    // Carrega projeção de budget se tiver budget definido
    if (d.budget > 0) loadBudgetProjection();

  } catch(e) {
    container.innerHTML = '<div style="color:var(--rd)">Erro ao carregar: '+e.message+'</div>';
  }
}

async function loadBudgetProjection() {
  var el = document.getElementById('budgetProjection');
  if (!el) return;

  try {
    var res = await fetch('api/event_performance.php?action=get_budget_projection', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_budget_projection', event_id: EVENT_ID})
    });
    var d = await res.json();

    if (!d.success) {
      el.innerHTML = '';
      return;
    }

    var willOverflow = d.will_overflow;
    var color  = willOverflow ? 'var(--rd)' : (d.margin < d.budget * 0.1 ? 'var(--yw)' : 'var(--gn)');
    var icon   = willOverflow ? '🔴' : (d.margin < d.budget * 0.1 ? '🟡' : '🟢');
    var status = willOverflow
      ? 'Vai estourar em <strong style="color:var(--rd)">R$'+fmtN(d.overflow_by)+'</strong>'
      : 'Margem de <strong style="color:var(--gn)">R$'+fmtN(d.margin)+'</strong>';

    var html = '<div style="border-top:1px solid var(--bd);padding-top:12px;margin-top:4px">';
    html += '<div style="font-family:Syne,sans-serif;font-size:12px;font-weight:700;margin-bottom:10px;color:var(--mt2);text-transform:uppercase;letter-spacing:.8px">📊 Projeção de Gasto</div>';

    // Resumo
    html += '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px">';
    html += '<div><div style="font-size:10px;color:var(--mt2);text-transform:uppercase;letter-spacing:.6px">Gasto real</div><div style="font-family:Syne,sans-serif;font-weight:800;font-size:16px;color:var(--rd)">R$'+fmtN(d.spent_all)+'</div></div>';
    html += '<div><div style="font-size:10px;color:var(--mt2);text-transform:uppercase;letter-spacing:.6px">Projeção total</div><div style="font-family:Syne,sans-serif;font-weight:800;font-size:16px;color:'+color+'">R$'+fmtN(d.projected_all)+'</div></div>';
    html += '<div style="display:flex;align-items:center;gap:6px;font-size:13px">'+icon+' '+status+'</div>';
    html += '</div>';

    // Tabela por campanha
    html += '<div style="overflow-x:auto">';
    html += '<table style="width:100%;border-collapse:collapse;font-size:11px">';
    html += '<thead><tr>';
    html += '<th style="text-align:left;padding:5px 8px;color:var(--mt2);font-size:9px;text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--bd)">Campanha</th>';
    html += '<th style="text-align:center;padding:5px 8px;color:var(--mt2);font-size:9px;text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--bd)">Status</th>';
    html += '<th style="text-align:right;padding:5px 8px;color:var(--mt2);font-size:9px;text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--bd)">Orçamento</th>';
    html += '<th style="text-align:right;padding:5px 8px;color:var(--mt2);font-size:9px;text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--bd)">Gasto real</th>';
    html += '<th style="text-align:right;padding:5px 8px;color:var(--mt2);font-size:9px;text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--bd)">Projeção</th>';
    html += '</tr></thead>';

    // Ordena: ativas primeiro, depois pausadas
    d.campaigns.sort(function(a, b) {
      if (a.is_active === b.is_active) return 0;
      return a.is_active ? -1 : 1;
    });

    var INITIAL_SHOW = 10;
    var allCampaigns = d.campaigns;

    function buildRows(list) {
      return list.map(function(c) {
        var isActive = c.is_active;
        var statusBadge = isActive
          ? '<span style="background:rgba(52,211,153,.12);color:var(--gn);padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700">● ATIVA</span>'
          : '<span style="background:rgba(255,255,255,.06);color:var(--mt2);padding:2px 6px;border-radius:4px;font-size:9px">● PAUSADA</span>';
        var budgetCell = c.budget_meta
          ? 'R$'+fmtN(c.budget_meta)+' <span style="color:var(--mt2);font-size:9px">('+c.budget_type+')</span>'
          : (isActive ? '<span style="color:var(--yw)">⚠️ sem budget</span>' : '—');
        var projColor = isActive ? 'var(--ac)' : 'var(--mt2)';
        return '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">'
          +'<td style="padding:7px 8px;max-width:200px;word-break:break-word">'+htmlEsc(c.campaign_name)+'</td>'
          +'<td style="padding:7px 8px;text-align:center">'+statusBadge+'</td>'
          +'<td style="padding:7px 8px;text-align:right">'+budgetCell+'</td>'
          +'<td style="padding:7px 8px;text-align:right;color:var(--rd)">R$'+fmtN(c.spent_real)+'</td>'
          +'<td style="padding:7px 8px;text-align:right;font-family:Syne,sans-serif;font-weight:700;color:'+projColor+'">R$'+fmtN(c.projected)+'</td>'
          +'</tr>';
      }).join('');
    }

    html += '<tbody id="projTbody">' + buildRows(allCampaigns.slice(0, INITIAL_SHOW)) + '</tbody></table></div>';

    if (allCampaigns.length > INITIAL_SHOW) {
      html += '<div id="projShowMore" style="text-align:center;margin-top:10px">'
        +'<button type="button" id="projShowMoreButton" style="background:rgba(79,110,247,.1);border:1px solid rgba(79,110,247,.25);border-radius:8px;color:var(--ac);padding:7px 20px;font-size:12px;font-family:Syne,sans-serif;font-weight:700;cursor:pointer">'
        +'▼ Ver todas as '+allCampaigns.length+' campanhas'
        +'</button></div>';
    }

    html += '</div>';

    el.innerHTML = html;
    el._allCampaigns = allCampaigns;
    var showMoreButton = document.getElementById('projShowMoreButton');
    if (showMoreButton) showMoreButton.addEventListener('click', expandProjTable);

  } catch(e) {
    var el2 = document.getElementById('budgetProjection');
    if (el2) el2.innerHTML = '';
  }
}

function expandProjTable() {
  var el   = document.getElementById('budgetProjection');
  var tbody = document.getElementById('projTbody');
  var btn   = document.getElementById('projShowMore');
  if (!el || !tbody || !el._allCampaigns) return;

  // Renderiza todas as campanhas
  tbody.innerHTML = el._allCampaigns.map(function(c) {
    var isActive = c.is_active;
    var statusBadge = isActive
      ? '<span style="background:rgba(52,211,153,.12);color:var(--gn);padding:2px 6px;border-radius:4px;font-size:9px;font-weight:700">● ATIVA</span>'
      : '<span style="background:rgba(255,255,255,.06);color:var(--mt2);padding:2px 6px;border-radius:4px;font-size:9px">● PAUSADA</span>';
    var budgetCell = c.budget_meta
      ? 'R$'+fmtN(c.budget_meta)+' <span style="color:var(--mt2);font-size:9px">('+c.budget_type+')</span>'
      : (isActive ? '<span style="color:var(--yw)">⚠️ sem budget</span>' : '—');
    var projColor = isActive ? 'var(--ac)' : 'var(--mt2)';
    return '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">'
      +'<td style="padding:7px 8px;max-width:200px;word-break:break-word">'+htmlEsc(c.campaign_name)+'</td>'
      +'<td style="padding:7px 8px;text-align:center">'+statusBadge+'</td>'
      +'<td style="padding:7px 8px;text-align:right">'+budgetCell+'</td>'
      +'<td style="padding:7px 8px;text-align:right;color:var(--rd)">R$'+fmtN(c.spent_real)+'</td>'
      +'<td style="padding:7px 8px;text-align:right;font-family:Syne,sans-serif;font-weight:700;color:'+projColor+'">R$'+fmtN(c.projected)+'</td>'
      +'</tr>';
  }).join('');

  if (btn) btn.style.display = 'none';
}

function htmlEsc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── RESUMO DO EVENTO ──────────────────────────────────────────
var resumoPeriod = 'all';

function setResumoPeriod(p, btn) {
  resumoPeriod = p;
  document.querySelectorAll('.rs-pbtn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
}

function getResumoDateRange(period) {
  var today = new Date();
  today.setHours(0,0,0,0);
  var fmt = function(d){ return d.toISOString().split('T')[0]; };

  if (period === 'all') {
    return { start: null, end: null, label: 'Todo o período' };
  }
  if (period === 'today') {
    return { start: fmt(today), end: fmt(today), label: 'Hoje (' + today.toLocaleDateString('pt-BR') + ')' };
  }
  if (period === 'yesterday') {
    var y = new Date(today); y.setDate(y.getDate()-1);
    return { start: fmt(y), end: fmt(y), label: 'Ontem (' + y.toLocaleDateString('pt-BR') + ')' };
  }
  var days  = parseInt(period);
  var end   = new Date(today); end.setDate(end.getDate() - 1);
  var start = new Date(today); start.setDate(start.getDate() - days);
  return {
    start: fmt(start), end: fmt(end),
    label: 'Últimos ' + days + ' dias (' + start.toLocaleDateString('pt-BR') + ' a ' + end.toLocaleDateString('pt-BR') + ')'
  };
}

function fmtRS(n, decimals) {
  if (decimals === undefined) decimals = 2;
  return parseFloat(n||0).toLocaleString('pt-BR', {minimumFractionDigits:decimals, maximumFractionDigits:decimals});
}

async function gerarResumoEvento() {
  var btn = document.getElementById('btnGerarResumo');
  btn.disabled = true;
  btn.textContent = '⏳ Gerando...';

  var range = getResumoDateRange(resumoPeriod);

  try {
    var res = await fetch('api/event_performance.php?action=get_resumo', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        action: 'get_resumo',
        event_id: EVENT_ID,
        date_start: range.start,
        date_end: range.end
      })
    });
    var d = await res.json();

    if (!d.success) { alert(d.error || 'Erro ao gerar resumo'); return; }

    var data       = d.data || {};
    var purchases  = parseInt(data.purchases) || 0;
    var revenue    = parseFloat(data.revenue) || 0;
    var spend      = parseFloat(data.spend) || 0;
    var leads      = parseInt(data.leads) || 0;
    var cpa        = purchases > 0 ? spend / purchases : 0;
    var cpl        = leads > 0 ? spend / leads : 0;
    var roas       = spend > 0 ? revenue / spend : 0;
    var roi        = spend > 0 ? ((revenue - spend) / spend) * 100 : 0;
    var eventName  = (EVENT_DATA && EVENT_DATA.name) ? EVENT_DATA.name : '';

    var blocks = [];

    if (purchases > 0 || spend > 0) {
      var t = eventName + '\n';
      t += 'Período: ' + range.label + '\n\n';
      t += 'Compras: ' + purchases + '\n';
      if (cpa > 0) t += 'Custo por compra: R$' + fmtRS(cpa) + '\n';
      if (revenue > 0) t += 'Valor faturado: R$' + fmtRS(revenue) + '\n';
      t += 'Valor gasto: R$' + fmtRS(spend) + '\n';
      if (roas > 0) t += 'ROAS: ' + fmtRS(roas,2) + 'x\n';
      if (roi !== 0) t += 'ROI: ' + fmtRS(roi,1) + '%';
      blocks.push({title:'🛒 Compras', text:t});
    }

    if (leads > 0) {
      var t2 = eventName + '\n';
      t2 += 'Período: ' + range.label + '\n\n';
      t2 += 'Leads: ' + leads + '\n';
      if (cpl > 0) t2 += 'Custo por lead: R$' + fmtRS(cpl);
      blocks.push({title:'📋 Leads', text:t2});
    }

    var resultCard = document.getElementById('resumoResultCard');
    var title = document.getElementById('resumoResultTitle');
    var container = document.getElementById('resumoBlocksContainer');

    if (!blocks.length) {
      container.innerHTML = '<div style="text-align:center;padding:32px;color:var(--mt2)">Nenhum dado encontrado para este período.</div>';
      title.textContent = eventName + ' — ' + range.label;
      resultCard.style.display = 'block';
      btn.disabled = false; btn.textContent = '📊 Gerar resumo';
      return;
    }

    title.textContent = eventName + ' — ' + range.label;
    container.innerHTML = blocks.map(function(b, idx) {
      return '<div class="rs-block">'
        +'<div class="rs-block-header"><span class="rs-block-title">'+b.title+'</span>'
        +'<button class="rs-block-copy" onclick="copyResumoBlock('+idx+')">📋 Copiar</button></div>'
        +'<div class="rs-block-text" id="rs-block-'+idx+'">'+htmlEsc(b.text)+'</div>'
        +'</div>';
    }).join('');

    window._resumoBlocks = blocks;
    resultCard.style.display = 'block';

  } catch(e) {
    alert('Erro: ' + e.message);
  }

  btn.disabled = false;
  btn.textContent = '📊 Gerar resumo';
}

function copyResumoBlock(idx) {
  var text = window._resumoBlocks[idx].text;
  navigator.clipboard.writeText(text).then(function() {
    var btn = document.querySelectorAll('.rs-block-copy')[idx];
    btn.textContent = '✅ Copiado!';
    setTimeout(function(){ btn.textContent = '📋 Copiar'; }, 2000);
  });
}

function copyResumoAll() {
  var all = (window._resumoBlocks || []).map(function(b){ return b.text; }).join('\n\n---\n\n');
  navigator.clipboard.writeText(all).then(function() {
    var btn = document.getElementById('btnCopyResumoAll');
    btn.textContent = '✅ Copiado!';
    setTimeout(function(){ btn.textContent = '📋 Copiar tudo'; }, 2000);
  });
}

// ── SUGESTÕES (via Claude + Facebook Ads MCP) ───────────────────
var sgCurrentPrompt = '';

async function generateSuggestionPrompt() {
  var btn = document.getElementById('btnGenPrompt');
  btn.disabled = true;
  btn.textContent = '⏳ Gerando...';

  try {
    var res = await fetch('api/event_performance.php?action=get_suggestions_prompt', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_suggestions_prompt', event_id: EVENT_ID})
    });
    var d = await res.json();

    if (!d.success) { alert(d.error || 'Erro ao gerar prompt'); return; }

    sgCurrentPrompt = d.prompt;
    document.getElementById('sgPromptBox').textContent = d.prompt;
    document.getElementById('sgPromptWrap').style.display = 'block';

  } catch(e) {
    alert('Erro: ' + e.message);
  }

  btn.disabled = false;
  btn.textContent = '⚙️ Gerar prompt';
}

function copySuggestionPrompt() {
  navigator.clipboard.writeText(sgCurrentPrompt).then(function() {
    var btn = document.getElementById('btnCopyPrompt');
    btn.textContent = '✅ Copiado!';
    setTimeout(function(){ btn.textContent = '📋 Copiar prompt'; }, 2000);
  });
}

async function saveSuggestion() {
  var area = document.getElementById('sgPasteArea');
  var content = area.value.trim();
  if (!content) { alert('Cole o texto da resposta antes de salvar'); return; }

  var btn = document.getElementById('btnSaveSuggestion');
  btn.disabled = true;
  btn.textContent = '⏳ Salvando...';

  try {
    var res = await fetch('api/event_performance.php?action=save_suggestion', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'save_suggestion', event_id: EVENT_ID, content: content})
    });
    var d = await res.json();

    if (!d.success) { alert(d.error || 'Erro ao salvar'); return; }

    area.value = '';
    loadSuggestions();

  } catch(e) {
    alert('Erro: ' + e.message);
  }

  btn.disabled = false;
  btn.textContent = '💾 Salvar sugestão';
}

async function loadSuggestions() {
  var list = document.getElementById('suggestionsList');
  list.innerHTML = 'Carregando...';

  try {
    var res = await fetch('api/event_performance.php?action=list_suggestions', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'list_suggestions', event_id: EVENT_ID})
    });
    var d = await res.json();

    if (!d.success) { list.innerHTML = '<div style="color:var(--rd)">'+(d.error||'Erro ao carregar')+'</div>'; return; }

    if (!d.suggestions.length) {
      list.innerHTML = '<div style="text-align:center;padding:24px;color:var(--mt2)">Nenhuma sugestão salva ainda.</div>';
      return;
    }

    list.innerHTML = d.suggestions.map(function(s) {
      var date = new Date(s.created_at.replace(' ','T')).toLocaleString('pt-BR', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
      return '<div class="sg-card">'
        +'<div class="sg-card-header">'
        +'<span class="sg-card-meta">'+sgEsc(s.username)+' · '+date+'</span>'
        +'<button class="sg-del" onclick="deleteSuggestion('+s.id+')">🗑️ Remover</button>'
        +'</div>'
        +'<div class="sg-card-text">'+sgEsc(s.content)+'</div>'
        +'</div>';
    }).join('');

  } catch(e) {
    list.innerHTML = '<div style="color:var(--rd)">Erro: '+e.message+'</div>';
  }
}

async function deleteSuggestion(id) {
  if (!confirm('Remover esta sugestão do histórico?')) return;
  await fetch('api/event_performance.php?action=delete_suggestion', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'delete_suggestion', id: id})
  });
  loadSuggestions();
}

function sgEsc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function fmtN(n){ return parseFloat(n||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); }

function editBudget() {
  var cur = prompt('Novo budget do evento (R$):');
  if (cur === null) return;
  var val = parseFloat(cur.replace(',','.'));
  if (isNaN(val) || val < 0) { alert('Valor inválido'); return; }
  fetch('api/event_performance.php?action=update_budget', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'update_budget', event_id:EVENT_ID, budget:val})
  }).then(function(){ perfLoaded=false; loadPerformance(); });
}

function switchTabByName(name) {
  var tabs = document.querySelectorAll('.tab');
  tabs.forEach(function(t){
    if (t.textContent.toLowerCase().includes(name)) t.click();
  });
}

// ── CAMPANHAS ──────────────────────────────────────────────────
var campLoaded = false;
var ALL_CAMP_DATA = [];

async function loadCampaigns() {
  if (campLoaded) return;
  campLoaded = true;

  try {
    var r = await fetch('api/event_performance.php?action=get_performance', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_performance', event_id: EVENT_ID})
    });
    var d = await r.json();
    var stats = d.camp_stats || [];
    var count = d.campaign_count || 0;

    document.getElementById('campInfo').textContent = count + ' campanha(s) vinculada(s)';

    // Lista campanhas vinculadas
    var campR = await fetch('api/events.php?action=get_event', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_event', id: EVENT_ID})
    });
    var campD = await campR.json();

    // Busca via tabela event_campaigns
    var campListR = await fetch('api/event_performance.php?action=list_event_campaigns_saved', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'list_event_campaigns_saved', event_id: EVENT_ID})
    });
    var campListD = await campListR.json();
    ALL_CAMP_DATA = campListD.campaigns || [];

    renderCampaigns(ALL_CAMP_DATA);
  } catch(e) {
    document.getElementById('campList').innerHTML = '<div style="color:var(--rd)">Erro: '+e.message+'</div>';
  }
}

function renderCampaigns(camps) {
  var container = document.getElementById('campList');
  if (!camps.length) {
    container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--mt2)">📣 Nenhuma campanha vinculada. Clique em "Vincular campanhas".</div>';
    return;
  }

  // Agrupa por objetivo
  var groups = {};
  camps.forEach(function(c) {
    var obj = c.objective || 'Outros';
    if (!groups[obj]) groups[obj] = [];
    groups[obj].push(c);
  });

  var objIcons = {
    'OUTCOME_SALES':'🛒','OUTCOME_LEADS':'📋','OUTCOME_TRAFFIC':'🚦',
    'OUTCOME_ENGAGEMENT':'❤️','OUTCOME_AWARENESS':'👁️','OUTCOME_APP_PROMOTION':'📱'
  };

  container.innerHTML = Object.keys(groups).map(function(obj) {
    var icon = objIcons[obj] || '📣';
    var label = obj.replace('OUTCOME_','').replace(/_/g,' ');
    var items = groups[obj].map(function(c) {
      var isActive = c.status === 'ACTIVE';
      return '<div class="camp-item" id="camp-'+c.campaign_id+'">' +
        '<div class="camp-status '+(isActive?'camp-active':'camp-paused')+'"></div>'+
        '<div class="camp-info">'+
          '<div class="camp-name">'+esc(c.campaign_name)+'</div>'+
          '<div class="camp-obj">'+label+' · '+(isActive?'Ativo':'Pausado')+'</div>'+
        '</div>'+
        (IS_ADMIN ? '<button class="btn btn-d btn-sm" onclick="removeCampaign(\'' + c.campaign_id + '\')">✕</button>' : '')+
      '</div>';
    }).join('');
    return '<div class="obj-group"><div class="obj-title">'+icon+' '+label+' ('+groups[obj].length+')</div>'+items+'</div>';
  }).join('');
}

function filterCampaigns() {
  var q = document.getElementById('campSearch').value.toLowerCase();
  var filtered = ALL_CAMP_DATA.filter(function(c){ return c.campaign_name.toLowerCase().includes(q); });
  renderCampaigns(filtered);
}

async function openLinkCampaigns() {
  var event = EVENT_DATA;
  if (!event || !event.users) { alert('Carregando...'); return; }

  // Busca a conta vinculada ao evento
  var r = await fetch('api/events.php?action=get_event', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'get_event', id: EVENT_ID})
  });
  var d = await r.json();
  var accountId = d.event ? d.event.account_id : null;

  if (!accountId) {
    alert('Configure a conta de anúncios do evento em Configurações primeiro.');
    return;
  }

  // Busca campanhas da conta via API
  var campR = await fetch('api/event_performance.php?action=list_account_campaigns', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'list_account_campaigns', account_id: accountId})
  });
  var campD = await campR.json();
  if (campD.error) { alert(campD.error); return; }

  var allCamps = campD.campaigns || [];
  var selected = ALL_CAMP_DATA.map(function(c){ return c.campaign_id; });

  // Modal simples de seleção
  var existing = document.getElementById('campLinkModal');
  if (existing) existing.remove();
  var modal = document.createElement('div');
  modal.id = 'campLinkModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:9000;display:flex;align-items:center;justify-content:center;padding:16px';
  modal.innerHTML = '<div style="background:#0f0f1a;border:1px solid rgba(255,255,255,.07);border-radius:16px;padding:24px;width:100%;max-width:560px;max-height:85vh;overflow-y:auto">'+
    '<div style="font-family:Syne,sans-serif;font-size:16px;font-weight:800;margin-bottom:16px">📣 Vincular Campanhas</div>'+
    '<input type="text" placeholder="Buscar..." oninput="filterModalCamps(this)" style="width:100%;padding:8px 12px;background:#161625;border:1px solid rgba(255,255,255,.07);border-radius:8px;color:#ededf5;font-size:13px;outline:none;margin-bottom:12px">'+
    '<div id="modalCampList" style="max-height:360px;overflow-y:auto;display:flex;flex-direction:column;gap:6px">'+
      allCamps.map(function(camp) {
        var isSel = selected.indexOf(camp.id) >= 0;
        return '<label style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:#161625;border:1px solid '+(isSel?'rgba(79,110,247,.4)':'rgba(255,255,255,.07)')+';border-radius:8px;cursor:pointer">'+
          '<input type="checkbox" value="'+esc(camp.id)+'" '+(isSel?'checked':'')+' data-name="'+esc(camp.name)+'" data-obj="'+esc(camp.objective||'')+'" data-status="'+esc(camp.status||'')+'" style="width:16px;height:16px;accent-color:#4f6ef7;cursor:pointer">'+
          '<div><div style="font-size:13px;font-weight:500">'+esc(camp.name)+'</div>'+
          '<div style="font-size:11px;color:#8888a0">'+(camp.objective||'').replace('OUTCOME_','').replace(/_/g,' ')+' · '+esc(camp.status)+'</div></div>'+
        '</label>';
      }).join('')+
    '</div>'+
    '<div style="display:flex;gap:10px;margin-top:16px;justify-content:flex-end">'+
      '<button onclick="document.getElementById(\'campLinkModal\').remove()" style="padding:9px 16px;border-radius:8px;border:1px solid rgba(255,255,255,.07);background:transparent;color:#8888a0;cursor:pointer;font-family:Syne,sans-serif;font-weight:700">Cancelar</button>'+
      '<button onclick="saveLinkedCampaigns(this)" style="padding:9px 16px;border-radius:8px;background:linear-gradient(135deg,#4f6ef7,#a78bfa);border:none;color:#fff;cursor:pointer;font-family:Syne,sans-serif;font-weight:700">✅ Salvar</button>'+
    '</div>'+
  '</div>';
  document.body.appendChild(modal);
}

function filterModalCamps(input) {
  var q = input.value.toLowerCase();
  document.querySelectorAll('#modalCampList label').forEach(function(el) {
    el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

async function saveLinkedCampaigns(btn) {
  var checked = document.querySelectorAll('#modalCampList input:checked');
  var campaigns = [];
  checked.forEach(function(inp) {
    campaigns.push({id:inp.value, name:inp.dataset.name, objective:inp.dataset.obj, status:inp.dataset.status});
  });

  btn.textContent = 'Salvando...'; btn.disabled = true;

  await fetch('api/event_performance.php?action=save_event_campaigns', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'save_event_campaigns', event_id:EVENT_ID, campaigns:campaigns})
  });

  var lm = document.getElementById('campLinkModal'); if (lm) lm.remove();
  campLoaded = false;
  perfLoaded = false;
  loadCampaigns();
  document.getElementById('campInfo').textContent = campaigns.length + ' campanha(s) vinculada(s)';
}

async function removeCampaign(campId) {
  if (!confirm('Desvincular esta campanha?')) return;
  ALL_CAMP_DATA = ALL_CAMP_DATA.filter(function(c){ return c.campaign_id !== campId; });
  var campaigns = ALL_CAMP_DATA.map(function(c){return {id:c.campaign_id,name:c.campaign_name,objective:c.objective,status:c.status};});
  await fetch('api/event_performance.php?action=save_event_campaigns', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'save_event_campaigns', event_id:EVENT_ID, campaigns:campaigns})
  });
  renderCampaigns(ALL_CAMP_DATA);
  perfLoaded = false;
}

// ── RELATÓRIO PDF ─────────────────────────────────────────────
function gerarRelatorio(type) {
  var url = 'api/generate_report.php?event_id=' + EVENT_ID + '&type=' + type + '&autoprint=1';
  window.open(url, '_blank');
}

function toggleReportPeriodMenu() {
  var menu = document.getElementById('reportPeriodMenu');
  menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

// Fecha o menu ao clicar fora
document.addEventListener('click', function(e) {
  var wrap = document.getElementById('reportBtns');
  var menu = document.getElementById('reportPeriodMenu');
  if (menu && wrap && !wrap.contains(e.target)) menu.style.display = 'none';
});

function gerarRelatorioPeriodo(period) {
  document.getElementById('reportPeriodMenu').style.display = 'none';

  var range = getResumoDateRange(period); // reaproveita a mesma lógica da aba Resumo
  var url = 'api/generate_report.php?event_id=' + EVENT_ID + '&type=parcial&autoprint=1';

  if (range.start && range.end) {
    url += '&date_start=' + range.start + '&date_end=' + range.end;
  }
  // period === 'all' não manda datas — backend usa o período total do evento

  window.open(url, '_blank');
}

// Exibe botao Relatorio Final apenas quando evento estiver encerrado
function checkRelatorioFinalBtn() {
  var btn = document.getElementById('btnRelatorioFinal');
  if (!btn) return;
  if (EVENT_DATA && EVENT_DATA.status === 'finished') {
    btn.style.display = 'inline-flex';
  } else {
    btn.style.display = 'none';
  }
}
</script>
</body>
</html>
<script>
// ── FINANCEIRO DO EVENTO ──────────────────────────────────────
var financialLoaded = false;
var financialData   = null;

async function loadFinancial() {
  if (financialLoaded) return;
  var el = document.getElementById('financialContent');
  el.innerHTML = '<div style="text-align:center;padding:40px;color:var(--mt2)">⏳ Carregando...</div>';

  try {
    var res = await fetch('api/event_financial.php?action=get_financial_summary', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_financial_summary', event_id: EVENT_ID})
    });
    financialData = await res.json();
    if (!financialData.success) { el.innerHTML = '<div style="color:var(--rd)">'+esc(financialData.error||'Erro')+'</div>'; return; }
    renderFinancial();
    financialLoaded = true;
  } catch(e) {
    el.innerHTML = '<div style="color:var(--rd)">Erro: '+e.message+'</div>';
  }
}

function renderFinancial() {
  var d   = financialData;
  var fmtR = function(v){ return 'R$ '+parseFloat(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); };
  var res  = parseFloat(d.resultado||0);
  var resColor = res >= 0 ? 'var(--gn)' : 'var(--rd)';

  var entradas  = (d.entries||[]).filter(function(e){ return e.type==='entrada'; });
  var despesas  = (d.entries||[]).filter(function(e){ return e.type==='despesa'; });

  var catOptions = function(type) {
    return (d.categories||[]).filter(function(c){ return c.type===type; }).map(function(c){
      return '<option value="'+c.id+'">'+c.icon+' '+esc(c.name)+'</option>';
    }).join('');
  };

  var html = '';

  // ── Resumo ──
  html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px">';
  html += fCard('💚 Entradas', fmtR(d.total_entradas), 'var(--gn)');
  html += fCard('🔴 Despesas', fmtR(d.total_despesas), 'var(--rd)');
  html += fCard(res>=0?'✅ Resultado':'⚠️ Resultado', fmtR(res), resColor);
  html += '</div>';

  // ── Período ──
  html += '<div style="font-size:11px;color:var(--mt2);margin-bottom:16px">Período: '+esc(d.period.start)+' a '+esc(d.period.end)+'</div>';

  // ── Grid entradas / despesas ──
  html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">';

  // Entradas
  html += '<div style="background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:14px;overflow:hidden">';
  html += '<div style="padding:14px 16px;border-bottom:1px solid var(--bd,var(--border));display:flex;justify-content:space-between;align-items:center">';
  html += '<span style="font-family:Syne,sans-serif;font-weight:700;font-size:13px">💚 Entradas</span>';
  html += '<button onclick="openAddEntry(\'entrada\')" style="background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.25);border-radius:7px;color:var(--gn);padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:Syne,sans-serif">+ Adicionar</button>';
  html += '</div>';
  html += '<div style="padding:12px 16px">';
  if (!entradas.length) {
    html += '<div style="text-align:center;padding:20px;color:var(--mt2);font-size:12px">Nenhuma entrada registrada</div>';
  } else {
    entradas.forEach(function(e) {
      html += fEntry(e, fmtR);
    });
  }
  html += '</div></div>';

  // Despesas
  html += '<div style="background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:14px;overflow:hidden">';
  html += '<div style="padding:14px 16px;border-bottom:1px solid var(--bd,var(--border));display:flex;justify-content:space-between;align-items:center">';
  html += '<span style="font-family:Syne,sans-serif;font-weight:700;font-size:13px">🔴 Despesas</span>';
  html += '<button onclick="openAddEntry(\'despesa\')" style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.25);border-radius:7px;color:var(--rd);padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:Syne,sans-serif">+ Adicionar</button>';
  html += '</div>';
  html += '<div style="padding:12px 16px">';

  // Meta Ads automático
  if (parseFloat(d.meta_spend) > 0) {
    html += '<div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--bd,var(--border))">';
    html += '<span style="font-size:16px">📘</span>';
    html += '<div style="flex:1"><div style="font-size:13px;font-weight:500">Meta Ads</div><div style="font-size:10px;color:var(--mt2)">Automático · período do evento</div></div>';
    html += '<span style="font-family:Syne,sans-serif;font-weight:700;color:var(--rd)">'+fmtR(d.meta_spend)+'</span>';
    html += '</div>';
  }

  if (!despesas.length && !parseFloat(d.meta_spend)) {
    html += '<div style="text-align:center;padding:20px;color:var(--mt2);font-size:12px">Nenhuma despesa registrada</div>';
  } else {
    despesas.forEach(function(e) {
      html += fEntry(e, fmtR);
    });
  }
  html += '</div></div>';
  html += '</div>'; // grid

  // ── Formulário de nova categoria ──
  html += '<div style="background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:12px;padding:16px;margin-bottom:16px">';
  html += '<div style="font-family:Syne,sans-serif;font-weight:700;font-size:12px;color:var(--mt2);text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px">+ Nova categoria</div>';
  html += '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">';
  html += '<input id="newCatIcon" type="text" value="📌" maxlength="2" style="width:48px;padding:8px;background:var(--s2,var(--surface2));border:1px solid var(--bd,var(--border));border-radius:8px;color:var(--tx,var(--text));font-size:18px;text-align:center">';
  html += '<input id="newCatName" type="text" placeholder="Nome da categoria" style="flex:1;min-width:140px;padding:8px 12px;background:var(--s2,var(--surface2));border:1px solid var(--bd,var(--border));border-radius:8px;color:var(--tx,var(--text));font-size:13px">';
  html += '<select id="newCatType" style="padding:8px 12px;background:var(--s2,var(--surface2));border:1px solid var(--bd,var(--border));border-radius:8px;color:var(--tx,var(--text));font-size:13px"><option value="despesa">Despesa</option><option value="entrada">Entrada</option></select>';
  html += '<button onclick="saveNewCategory()" style="padding:8px 14px;background:rgba(79,110,247,.12);border:1px solid rgba(79,110,247,.25);border-radius:8px;color:var(--ac,var(--accent));font-family:Syne,sans-serif;font-weight:700;font-size:12px;cursor:pointer">Salvar</button>';
  html += '</div></div>';

  // ── Modal de adicionar lançamento (oculto) ──
  html += '<div id="addEntryModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:500;align-items:center;justify-content:center">';
  html += '<div style="background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:16px;padding:24px;width:100%;max-width:400px;margin:0 16px">';
  html += '<div style="font-family:Syne,sans-serif;font-weight:800;font-size:16px;margin-bottom:16px" id="addEntryTitle">Adicionar</div>';
  html += '<input type="hidden" id="addEntryType">';
  html += fInput('Categoria','<select id="addEntryCat" style="width:100%">'+catOptions('despesa')+'</select>');
  html += fInput('Descrição','<input type="text" id="addEntryDesc" placeholder="Ex: Cachê DJ, Estrutura..." style="width:100%">');
  html += fInput('Valor (R$)','<input type="number" id="addEntryAmount" placeholder="0,00" step="0.01" min="0" style="width:100%">');
  html += fInput('Data','<input type="date" id="addEntryDate" style="width:100%">');
  html += '<div style="display:flex;gap:8px;margin-top:16px">';
  html += '<button onclick="saveEntry()" style="flex:1;padding:11px;background:linear-gradient(135deg,var(--ac,var(--accent)),var(--ac2,var(--accent2)));border:none;border-radius:10px;color:#fff;font-family:Syne,sans-serif;font-weight:700;font-size:14px;cursor:pointer">💾 Salvar</button>';
  html += '<button onclick="closeAddEntry()" style="padding:11px 16px;background:transparent;border:1px solid var(--bd,var(--border));border-radius:10px;color:var(--mt2);font-family:Syne,sans-serif;font-weight:700;font-size:13px;cursor:pointer">Cancelar</button>';
  html += '</div></div></div>';

  document.getElementById('financialContent').innerHTML = html;

  // Seta input styles do modal
  document.querySelectorAll('#addEntryModal input, #addEntryModal select').forEach(function(el){
    el.style.cssText += 'padding:8px 12px;background:var(--s2,#161625);border:1px solid var(--bd,rgba(255,255,255,.07));border-radius:8px;color:var(--tx,#ededf5);font-size:13px;font-family:DM Sans,sans-serif;outline:none';
  });
}

function fCard(label, val, color) {
  return '<div style="background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:12px;padding:16px">'
    +'<div style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt2);margin-bottom:6px">'+label+'</div>'
    +'<div style="font-family:Syne,sans-serif;font-weight:900;font-size:22px;color:'+color+'">'+val+'</div>'
    +'</div>';
}

function fEntry(e, fmtR) {
  var icon  = e.category_icon || '📌';
  var name  = e.category_name || 'Outros';
  var color = e.type === 'entrada' ? 'var(--gn)' : 'var(--rd)';
  var date  = e.entry_date ? new Date(e.entry_date+'T00:00:00').toLocaleDateString('pt-BR') : '';
  return '<div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--bd,var(--border))">'
    +'<span style="font-size:16px">'+icon+'</span>'
    +'<div style="flex:1">'
    +'<div style="font-size:13px;font-weight:500">'+esc(e.description||name)+'</div>'
    +'<div style="font-size:10px;color:var(--mt2)">'+esc(name)+(date?' · '+date:'')+'</div>'
    +'</div>'
    +'<span style="font-family:Syne,sans-serif;font-weight:700;color:'+color+'">'+fmtR(e.amount)+'</span>'
    +'<button onclick="deleteEntry('+e.id+')" style="background:none;border:none;color:var(--mt2);cursor:pointer;font-size:14px;padding:2px 4px" title="Remover">🗑️</button>'
    +'</div>';
}

function fInput(label, input) {
  return '<div style="margin-bottom:12px">'
    +'<div style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt2);margin-bottom:5px">'+label+'</div>'
    +input+'</div>';
}

function openAddEntry(type) {
  var modal = document.getElementById('addEntryModal');
  if (!modal) return;
  document.getElementById('addEntryType').value  = type;
  document.getElementById('addEntryTitle').textContent = type === 'entrada' ? '💚 Nova Entrada' : '🔴 Nova Despesa';

  // Seta data de hoje
  var today = new Date().toISOString().split('T')[0];
  var dateInput = document.getElementById('addEntryDate');
  if (dateInput) dateInput.value = today;

  var catSel = document.getElementById('addEntryCat');
  catSel.innerHTML = '';
  (financialData.categories||[]).filter(function(c){ return c.type===type; }).forEach(function(c){
    var opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.icon+' '+c.name;
    catSel.appendChild(opt);
  });
  modal.style.display = 'flex';
}
function closeAddEntry() {
  var modal = document.getElementById('addEntryModal');
  if (modal) modal.style.display = 'none';
}

async function saveEntry() {
  var type   = document.getElementById('addEntryType').value;
  var catId  = document.getElementById('addEntryCat').value;
  var desc   = document.getElementById('addEntryDesc').value;
  var amount = document.getElementById('addEntryAmount').value;
  var date   = document.getElementById('addEntryDate').value;

  if (!amount || parseFloat(amount) <= 0) { alert('Informe o valor'); return; }

  var res = await fetch('api/event_financial.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'save_entry', event_id:EVENT_ID, type:type, category_id:catId, description:desc, amount:amount, date:date})
  });
  var d = await res.json();
  if (!d.success) { alert(d.error||'Erro ao salvar'); return; }

  closeAddEntry();
  financialLoaded = false;
  loadFinancial();
}

async function deleteEntry(id) {
  if (!confirm('Remover este lançamento?')) return;
  await fetch('api/event_financial.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'delete_entry', id:id})
  });
  financialLoaded = false;
  loadFinancial();
}

async function saveNewCategory() {
  var name = document.getElementById('newCatName').value.trim();
  var type = document.getElementById('newCatType').value;
  var icon = document.getElementById('newCatIcon').value.trim() || '📌';
  if (!name) { alert('Informe o nome da categoria'); return; }
  var res = await fetch('api/event_financial.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'save_category', name:name, type:type, icon:icon})
  });
  var d = await res.json();
  if (!d.success) { alert(d.error||'Erro'); return; }
  document.getElementById('newCatName').value = '';
  financialLoaded = false;
  loadFinancial();
}
</script>
