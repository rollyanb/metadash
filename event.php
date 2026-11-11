<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/config/database.php';
Auth::requireLogin();
$role = Auth::getRole();
if (!in_array($role, ['admin','chefe','marketing','financeiro','user'])) { header('Location: index.php'); exit; }
$isAdmin = in_array($role, ['admin','chefe']);
$isStrictAdmin = $role === 'admin';
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
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{max-width:100%;overflow-x:hidden}
/* .main in theme.php */

/* HEADER */
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--mt2);text-decoration:none;font-size:13px;margin-bottom:16px;transition:color .15s}
.back-link:hover{color:var(--ac)}
.ev-header{background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:24px;margin-bottom:20px}
.ev-title{font-family:'Inter',sans-serif;font-size:24px;font-weight:900;margin-bottom:8px}
.ev-info{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.ev-info-item{font-size:13px;color:var(--mt2);display:flex;align-items:center;gap:5px}
.ev-info-item strong{color:var(--tx)}

/* TIMELINE */
.timeline{margin-bottom:16px}
.timeline-bar{height:8px;background:var(--s2);border-radius:4px;overflow:visible;position:relative;margin-bottom:8px}
.timeline-fill{height:100%;background:linear-gradient(90deg,var(--ac),var(--gn));border-radius:4px}
.timeline-markers{position:relative;height:24px}
.timeline-marker{position:absolute;transform:translateX(-50%);text-align:center;font-size:10px;color:#826eff;white-space:nowrap}
.timeline-marker::before{content:'';position:absolute;top:-18px;left:50%;width:2px;height:12px;background:#826eff;transform:translateX(-50%)}

/* ABAS */
.tabs{display:flex;gap:0;border-bottom:1px solid var(--bd);margin-bottom:24px}
.tab{padding:10px 20px;font-family:'Inter',sans-serif;font-weight:600;font-size:13px;cursor:pointer;border:none;background:transparent;color:var(--mt2);border-bottom:2px solid transparent;transition:all .15s;margin-bottom:-1px}
.tab:hover{color:var(--tx)}
.tab.active{color:var(--ac);border-bottom-color:var(--ac)}
.tab-content{display:none}.tab-content.active{display:block}

/* TAREFAS */
.task-group{margin-bottom:24px}
.task-group-title{font-family:'Inter',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--mt2);margin-bottom:10px;display:flex;align-items:center;gap:8px}
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
.cat-criativo{background:rgba(167,139,250,.15);color:#826eff}
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
.lot-dot{position:absolute;left:-22px;top:50%;transform:translateY(-50%);width:14px;height:14px;border-radius:50%;border:2px solid #826eff;background:var(--bg);z-index:1}
.lot-dot.past{border-color:var(--mt);background:var(--mt)}
.lot-dot.today{border-color:var(--gn);background:var(--gn);box-shadow:0 0 8px var(--gn)}
.lot-dot.upcoming{border-color:#826eff;background:var(--bg)}
.lot-card{background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:14px 16px}
.lot-card.upcoming{border-color:rgba(167,139,250,.3)}
.lot-header{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.lot-name{font-family:'Inter',sans-serif;font-size:14px;font-weight:700}
.lot-date{font-size:12px;color:var(--mt2)}
.lot-price{font-family:'Inter',sans-serif;font-size:16px;font-weight:800;color:var(--gn)}
.lot-type{font-size:10px;padding:2px 8px;border-radius:4px;font-weight:600}
.type-lote{background:rgba(79,110,247,.15);color:var(--ac)}
.type-sem_taxa{background:rgba(52,211,153,.15);color:var(--gn)}
.type-outros{background:rgba(255,255,255,.05);color:var(--mt2)}
.lot-material{margin-top:8px;font-size:12px;color:var(--ac)}

/* BOTÕES */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:10px;font-family:'Inter',sans-serif;font-weight:600;font-size:13px;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
.btn-p{background:var(--ac);color:#fff}.btn-p:hover{opacity:.85}
.btn-o{background:var(--s2);border:1px solid var(--bd);color:var(--mt2)}.btn-o:hover{border-color:var(--ac);color:var(--ac)}
.btn-d{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.2);color:var(--rd)}.btn-d:hover{background:rgba(248,113,113,.2)}
.btn-sm{padding:5px 10px;font-size:11px}

/* MODAL */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;padding:16px}
.modal-bg.open{display:flex}
.modal{background:var(--sf);border:1px solid var(--bd);border-radius:20px;padding:28px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto}
.modal h2{font-family:'Inter',sans-serif;font-size:18px;font-weight:800;margin-bottom:20px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt);margin-bottom:6px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;background:var(--s2);border:1px solid var(--bd);border-radius:10px;color:var(--tx);font-family:'Inter',sans-serif;font-size:14px;outline:none}
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
.kpi-box-val{font-family:'Inter',sans-serif;font-size:18px;font-weight:800;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kpi-box-lbl{font-size:11px;color:var(--mt2);text-transform:uppercase;letter-spacing:.6px}
.camp-item{background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:12px 16px;margin-bottom:8px;display:flex;align-items:center;gap:12px}
.camp-status{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.camp-active{background:var(--gn)}
.camp-paused{background:var(--mt)}
.camp-info{flex:1;min-width:0}
.camp-name{font-size:13px;font-weight:500;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.camp-obj{font-size:11px;color:var(--mt2);margin-top:2px}
.obj-group{margin-bottom:20px}
.obj-title{font-family:'Inter',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--mt2);margin-bottom:8px;display:flex;align-items:center;gap:8px}
.daily-chart{background:var(--s2);border-radius:12px;padding:16px;margin-bottom:20px}
.chart-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:4px}
.chart-bars{display:flex;align-items:flex-end;gap:3px;min-height:160px}
.chart-col{display:flex;flex-direction:column;align-items:center;gap:3px;flex-shrink:0;width:40px}
.chart-bar{background:var(--ac);border-radius:3px 3px 0 0;min-height:4px;width:30px}
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
    <button class="tab active" onclick="switchTab('performance',this);loadPerformance()">📊 Performance</button>
    <button class="tab" onclick="switchTab('conversions',this);loadEventConversions()">📈 Conversões</button>
    <button class="tab" onclick="switchTab('creatives',this);loadCreatives()">🎨 Criativos</button>
    <button class="tab" onclick="switchTab('vendas',this);loadVendas()">🛒 Comparativo de vendas</button>
    <button class="tab" onclick="switchTab('simulacao',this);loadSimulacao()">🎯 Simulação de meta</button>
    <button class="tab" onclick="switchTab('perfil',this);loadPerfil()">👥 Perfil &amp; Projeção</button>
    <button class="tab" onclick="switchTab('resumo',this)">📝 Resumo</button>
    <?php if (in_array($role, ['admin','chefe','financeiro'])): ?>
    <button class="tab" onclick="switchTab('financial',this);loadFinancial()">💰 Financeiro</button>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
    <button class="tab" onclick="switchTab('campaigns',this);loadCampaigns()">📣 Campanhas</button>
    <button class="tab" onclick="switchTab('settings',this);loadReportLinks()">⚙️ Configurações</button>
    <?php endif; ?>
  </div>

  <!-- TAREFAS -->
  <div class="tab-content" id="tab-tasks">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px">
      <div id="tasksSummary" style="font-size:12px;color:var(--mt)"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-o btn-sm" onclick="openTaskModal(null,'lote')">🎟️ Nova virada de lote</button>
        <button class="btn btn-p btn-sm" onclick="openTaskModal()">➕ Nova tarefa</button>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
      <div id="tasksGroupBy" style="display:flex;gap:6px"></div>
      <span style="flex:1"></span>
      <label style="font-size:12px;color:var(--mt);display:flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" id="tasksHideDone" onchange="renderTasks()" style="width:auto;margin:0"> ocultar concluídas
      </label>
    </div>
    <div id="tasksLinks"></div>
    <div id="tasksList"></div>
  </div>

  <!-- MATERIAIS -->
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
  .rp-menu-item{display:block;width:100%;text-align:left;padding:10px 14px;background:transparent;border:none;border-bottom:1px solid var(--bd,var(--border));color:var(--tx,var(--text));font-size:13px;font-family:'Inter',sans-serif;cursor:pointer;transition:background .15s}
  .rp-menu-item:last-child{border-bottom:none}
  .rp-menu-item:hover{background:rgba(79,110,247,.1);color:var(--ac,var(--accent))}
  </style>

  <!-- RESUMO -->
  <div class="tab-content" id="tab-vendas">
    <div id="vendasResumoBox" style="margin-bottom:16px"></div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin-bottom:18px">
      <div class="card" style="margin:0">
        <div class="card-title">➕ Lançar um dia</div>
        <div style="display:flex;gap:9px;align-items:flex-end;flex-wrap:wrap">
          <div>
            <label style="display:block;font-size:10.5px;color:var(--mt);margin-bottom:3px">Data</label>
            <input type="date" id="vdData" style="font-size:13px;padding:7px 9px">
          </div>
          <div style="width:110px">
            <label style="display:block;font-size:10.5px;color:var(--mt);margin-bottom:3px">Vendas no dia</label>
            <input type="number" id="vdTotal" min="0" placeholder="60" style="font-size:13px;padding:7px 9px">
          </div>
          <button class="btn btn-p btn-sm" onclick="salvarVendaDia()" style="padding:8px 14px">Salvar</button>
        </div>
        <div style="font-size:11px;color:var(--mt);margin-top:8px">Lançar a mesma data de novo substitui o valor anterior.</div>
      </div>

      <div class="card" style="margin:0">
        <div class="card-title">📋 Cadastro em massa</div>
        <textarea id="vdMassa" placeholder="18/09/2026 - 60&#10;19/09/2026 - 100&#10;20/09/2026 - 85"
                  style="width:100%;min-height:92px;font-family:'JetBrains Mono',monospace;font-size:12.5px;resize:vertical"></textarea>
        <div style="display:flex;gap:8px;align-items:center;margin-top:9px;flex-wrap:wrap">
          <button class="btn btn-p btn-sm" onclick="salvarVendasMassa()" style="padding:8px 14px">Importar linhas</button>
          <span style="font-size:11px;color:var(--mt)">uma por linha · aceita 18/09/2026, 18/09 ou 2026-09-18</span>
        </div>
        <div id="vdMassaMsg" style="font-size:12px;margin-top:8px"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-title">📅 Dia a dia</div>
      <div id="vendasTabela"></div>
    </div>
  </div>

  <div class="tab-content" id="tab-simulacao">
    <div id="simBase" style="margin-bottom:16px"></div>

    <div class="card" style="margin-bottom:16px">
      <div class="card-title">🎯 Simular meta</div>

      <div style="display:flex;gap:7px;margin-bottom:14px;flex-wrap:wrap">
        <button class="sim-tipo on" data-tipo="ingressos" onclick="simTipo(this)"
                style="padding:7px 14px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;background:var(--ac);border:1px solid var(--ac);color:#fff">🎫 Ingressos</button>
        <button class="sim-tipo" data-tipo="faturamento" onclick="simTipo(this)"
                style="padding:7px 14px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;background:transparent;border:1px solid var(--bd);color:var(--mt2)">💰 Faturamento</button>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:13px">
        <div>
          <label style="display:block;font-size:11px;color:var(--mt);margin-bottom:4px" id="simLabelVendido">
            Vendidos até agora (total)
          </label>
          <input type="number" id="simVendido" min="0" step="1" style="font-size:16px;padding:10px 12px;width:100%"
                 oninput="calcularSimulacao()">
          <div style="font-size:10.5px;color:var(--mt);margin-top:4px" id="simVendidoDica"></div>
        </div>
        <div>
          <label style="display:block;font-size:11px;color:var(--mt);margin-bottom:4px" id="simLabelMeta">
            Meta (quanto quer vender no total)
          </label>
          <input type="number" id="simMeta" min="0" step="1" placeholder="1000" style="font-size:16px;padding:10px 12px;width:100%"
                 oninput="calcularSimulacao()">
        </div>
      </div>

      <div style="margin-top:14px;padding-top:13px;border-top:1px solid var(--bd)">
        <label style="display:block;font-size:11px;color:var(--mt);margin-bottom:4px">
          Caminho inverso: tenho esta verba e quero saber quanto vende (R$)
        </label>
        <input type="number" id="simVerba" min="0" step="50" placeholder="5000"
               style="font-size:15px;padding:9px 11px;max-width:220px" oninput="calcularSimulacaoInversa()">
      </div>
    </div>

    <div id="simResultado"></div>
  </div>

  <div class="tab-content" id="tab-perfil">
    <div id="perfilProj" style="margin-bottom:18px"></div>
    <div id="perfilGraficos"></div>
  </div>

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
        <button class="rs-pbtn" id="rsBtnCustom" onclick="abrirResumoCustom(this)" title="Escolher um intervalo de datas">📅 Escolher datas</button>
      </div>
      <div id="resumoCustomBox" style="display:none;gap:9px;align-items:flex-end;flex-wrap:wrap;margin-bottom:16px;
           padding:11px 13px;background:var(--s2);border:1px solid var(--bd);border-radius:10px">
        <div>
          <label style="display:block;font-size:10.5px;color:var(--mt);margin-bottom:3px">De</label>
          <input type="date" id="rsIni" style="font-size:13px;padding:6px 9px">
        </div>
        <div>
          <label style="display:block;font-size:10.5px;color:var(--mt);margin-bottom:3px">Até</label>
          <input type="date" id="rsFim" style="font-size:13px;padding:6px 9px">
        </div>
        <button class="btn btn-p btn-sm" onclick="aplicarResumoCustom()" style="font-size:12.5px;padding:7px 14px">Aplicar</button>
        <button class="btn btn-o btn-sm" onclick="document.getElementById('resumoCustomBox').style.display='none'" style="font-size:12.5px;padding:7px 12px">Fechar</button>
      </div>
      <button class="btn btn-p btn-sm" onclick="gerarResumoEvento()" style="width:100%" id="btnGerarResumo">📊 Gerar resumo</button>
    </div>

    <div id="resumoResultCard" style="background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:14px;overflow:hidden;display:none">
      <div style="padding:14px 18px;border-bottom:1px solid var(--bd,var(--border));display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div style="font-family:'Inter',sans-serif;font-size:14px;font-weight:700" id="resumoResultTitle">Resumo</div>
        <button onclick="copyResumoAll()" id="btnCopyResumoAll" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.25);color:var(--gn);font-family:'Inter',sans-serif;font-weight:700;font-size:12px;cursor:pointer">📋 Copiar tudo</button>
      </div>
      <div id="resumoBlocksContainer" style="display:flex;flex-direction:column;gap:10px;padding:18px"></div>
    </div>
  </div>

  <style>
  .rs-pbtn{padding:7px 14px;border-radius:8px;border:1px solid var(--bd,var(--border));background:transparent;color:var(--mt2);font-size:12px;font-family:'Inter',sans-serif;font-weight:700;cursor:pointer;transition:all .15s}
  .rs-pbtn:hover{border-color:var(--ac,var(--accent));color:var(--ac,var(--accent))}
  .rs-pbtn.active{background:rgba(79,110,247,.15);border-color:var(--ac,var(--accent));color:var(--tx,var(--text))}
  .rs-block{background:var(--s2,var(--surface2));border:1px solid var(--bd,var(--border));border-radius:10px;overflow:hidden}
  .rs-block-header{padding:9px 14px;border-bottom:1px solid var(--bd,var(--border));display:flex;align-items:center;justify-content:space-between}
  .rs-block-title{font-size:11px;font-weight:600;color:var(--mt2);text-transform:uppercase;letter-spacing:.5px}
  .rs-block-copy{background:none;border:none;color:var(--ac,var(--accent));font-size:11px;cursor:pointer;font-family:'Inter',sans-serif;padding:2px 6px}
  .rs-block-copy:hover{text-decoration:underline}
  .rs-block-text{padding:12px 14px;font-family:monospace;font-size:13px;line-height:1.8;color:var(--tx,var(--text));white-space:pre-wrap}
  .sg-step{background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:14px;padding:18px;margin-bottom:14px}
  .sg-step-num{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:rgba(79,110,247,.15);color:var(--ac,var(--accent));font-family:'Inter',sans-serif;font-weight:800;font-size:12px;margin-right:8px}
  .sg-step-title{font-family:'Inter',sans-serif;font-size:14px;font-weight:700;display:flex;align-items:center;margin-bottom:10px}
  .sg-prompt-box{background:var(--s2,var(--surface2));border:1px solid var(--bd,var(--border));border-radius:10px;padding:14px;font-family:monospace;font-size:12px;line-height:1.7;color:var(--tx,var(--text));white-space:pre-wrap;max-height:260px;overflow-y:auto;margin-bottom:10px}
  .sg-textarea{width:100%;min-height:160px;background:var(--s2,var(--surface2));border:1px solid var(--bd,var(--border));border-radius:10px;padding:12px;font-family:'Inter',sans-serif;font-size:13px;color:var(--tx,var(--text));resize:vertical;margin-bottom:10px}
  .sg-card{background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:12px;padding:16px;margin-bottom:12px}
  .sg-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;gap:10px;flex-wrap:wrap}
  .sg-card-meta{font-size:11px;color:var(--mt2)}
  .sg-card-text{font-size:13px;line-height:1.7;color:var(--tx,var(--text));white-space:pre-wrap}
  .sg-del{background:none;border:none;color:var(--rd);font-size:11px;cursor:pointer;font-family:'Inter',sans-serif}
  .sg-del:hover{text-decoration:underline}
  </style>

  <!-- SUGESTÕES -->
  <div class="tab-content" id="tab-financial">
    <div id="refundBox"></div>
    <div id="financialContent" style="color:var(--mt2);text-align:center;padding:40px">Carregando...</div>
  </div>

  <!-- CONVERSÕES -->
  <div class="tab-content" id="tab-conversions">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px">
      <div style="font-weight:700;font-size:15px">📈 Funil de conversões</div>
      <div id="convScope" style="font-size:11px;color:var(--mt)"></div>
    </div>
    <div id="convPeriods" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px"></div>
    <div id="convCustomBox" style="display:none;gap:9px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px;
         padding:11px 13px;background:var(--s2);border:1px solid var(--bd);border-radius:10px">
      <div>
        <label style="display:block;font-size:10.5px;color:var(--mt);margin-bottom:3px">De</label>
        <input type="date" id="convIni" style="font-size:13px;padding:6px 9px">
      </div>
      <div>
        <label style="display:block;font-size:10.5px;color:var(--mt);margin-bottom:3px">Até</label>
        <input type="date" id="convFim" style="font-size:13px;padding:6px 9px">
      </div>
      <button class="btn btn-p btn-sm" onclick="aplicarPeriodoCustom()" style="font-size:12.5px;padding:7px 14px">Aplicar</button>
      <button class="btn btn-o btn-sm" onclick="document.getElementById('convCustomBox').style.display='none'" style="font-size:12.5px;padding:7px 12px">Fechar</button>
    </div>
    <div id="convHighlights" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;margin-bottom:14px"></div>
    <div id="convVendasTotais"></div>
    <div id="convRecs" style="margin-bottom:16px"></div>
    <div id="convFunnel" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:16px"></div>
    <div id="convByCampaign" style="margin-bottom:16px"></div>
    <div id="convDaily"></div>
    <div id="convEmpty" style="display:none;color:var(--mt);text-align:center;padding:40px 0"></div>
  </div>

  <!-- CRIATIVOS -->
  <div class="tab-content" id="tab-creatives">
    <div id="crtPeriods" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px"></div>
    <div id="crtCustomBox" style="display:none;gap:9px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px;
         padding:11px 13px;background:var(--s2);border:1px solid var(--bd);border-radius:10px">
      <div>
        <label style="display:block;font-size:10.5px;color:var(--mt);margin-bottom:3px">De</label>
        <input type="date" id="crtIni" style="font-size:13px;padding:6px 9px">
      </div>
      <div>
        <label style="display:block;font-size:10.5px;color:var(--mt);margin-bottom:3px">Até</label>
        <input type="date" id="crtFim" style="font-size:13px;padding:6px 9px">
      </div>
      <button class="btn btn-p btn-sm" onclick="aplicarCrtCustom()" style="font-size:12.5px;padding:7px 14px">Aplicar</button>
      <button class="btn btn-o btn-sm" onclick="document.getElementById('crtCustomBox').style.display='none'" style="font-size:12.5px;padding:7px 12px">Fechar</button>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px">
      <div style="font-weight:700;font-size:15px">🎨 Melhores Criativos</div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <?php if ($isStrictAdmin): ?>
        <button class="btn btn-o btn-sm" id="btnSyncEventCreatives" onclick="syncEventCreatives()" title="Sincroniza apenas anúncios das campanhas vinculadas a este evento">🔄 Sincronizar criativos</button>
        <?php endif; ?>
        <select id="creativesSort" onchange="loadCreatives()" style="width:auto;min-width:180px">
          <option value="purchases">Ordenar por: Compras</option>
          <option value="leads">Ordenar por: Leads</option>
          <option value="roas">Ordenar por: ROAS</option>
          <option value="spend">Ordenar por: Gasto</option>
          <option value="reach">Ordenar por: Alcance</option>
          <option value="impressions">Ordenar por: Impressões</option>
          <option value="score">Ordenar por: Score</option>
        </select>
      </div>
    </div>
    <?php if ($isStrictAdmin): ?>
    <div id="syncEventCreativesStatus" style="display:none;font-size:12px;color:var(--mt);margin-bottom:12px;padding:8px 12px;background:var(--s2);border-radius:8px"></div>
    <?php endif; ?>
    <div id="creativesGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px"></div>
    <div id="creativesEmpty" style="display:none;color:var(--mt);text-align:center;padding:40px 0"></div>
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
    <div style="background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:20px;margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px">
        <div style="font-weight:700;font-size:14px">🔗 Link do relatório público</div>
        <button class="btn btn-p btn-sm" onclick="createReportLink()">+ Gerar link</button>
      </div>
      <div style="font-size:12px;color:var(--mt);margin-bottom:12px">Relatório com os números só deste evento (campanhas vinculadas). Quem tiver o link acessa sem login — desative quando não precisar mais.</div>
      <div id="reportLinks"></div>
    </div>
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
          <label>Contas de anúncios <span style="font-weight:400;color:var(--mt);font-size:11px">— marque todas que rodam este evento</span></label>
          <div id="cfgAccounts" style="max-height:190px;overflow:auto;border:1px solid var(--bd);border-radius:10px;padding:6px 8px">
            <?php
            try {
              $allAccounts = $db->fetchAll("SELECT account_id, account_name FROM ad_accounts WHERE status IN ('ACTIVE','INACTIVE') ORDER BY account_name");
            } catch (Exception $e) { $allAccounts = []; }
            foreach ($allAccounts as $acc):
            ?>
            <label style="display:flex;align-items:center;gap:9px;padding:5px 6px;cursor:pointer;font-size:13px">
              <input type="checkbox" class="cfg-acc" value="<?= htmlspecialchars($acc['account_id']) ?>" style="width:16px;height:16px;accent-color:var(--ac);cursor:pointer">
              <span><?= htmlspecialchars($acc['account_name']) ?></span>
              <span style="margin-left:auto;font-size:10px;color:var(--mt2);font-family:'JetBrains Mono',monospace"><?= htmlspecialchars($acc['account_id']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
          <div style="font-size:11px;color:var(--mt);margin-top:4px">A primeira marcada é a conta principal do evento.</div>
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
        <select id="taskCat" onchange="toggleLotFields()">
          <option value="criativo">🎨 Criativo</option>
          <option value="copy">✍️ Copy</option>
          <option value="trafego">📊 Tráfego</option>
          <option value="lote">🎟️ Virada de lote</option>
          <option value="sem_taxa">🎫 Lote sem taxa</option>
          <option value="material">📁 Material</option>
          <option value="reuniao">🗓️ Reunião</option>
          <option value="pagamento">💰 Pagamento</option>
          <option value="outro">📌 Outro</option>
        </select>
      </div>
      <div class="form-group">
        <label>Data limite</label>
        <input type="date" id="taskDue">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Responsável</label>
        <select id="taskAssigned">
          <option value="">Sem responsável</option>
        </select>
      </div>
      <div class="form-group">
        <label>Prioridade</label>
        <select id="taskPriority">
          <option value="alta">🔴 Alta</option>
          <option value="media" selected>🟡 Média</option>
          <option value="baixa">⚪ Baixa</option>
        </select>
      </div>
    </div>
    <div class="form-row" id="lotFields" style="display:none">
      <div class="form-group">
        <label>Preço do lote (R$)</label>
        <input type="number" step="0.01" id="taskLotPrice" placeholder="80,00">
      </div>
      <div class="form-group">
        <label>Status</label>
        <select id="taskStatus">
          <option value="pendente">⚪ Pendente</option>
          <option value="em_andamento">◐ Em andamento</option>
          <option value="concluido">✓ Concluída</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Link (opcional)</label>
      <input type="text" id="taskLink" placeholder="https://... (material, briefing, planilha)">
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


<!-- MODAL LOTE -->


<script>
var EVENT_ID = <?= $eventId ?>;
var IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
  var USER_ID  = <?= (int)Auth::getUserId() ?>;
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
          (daysLabel?'<div class="ev-info-item" style="color:#826eff"><strong>'+daysLabel+'</strong></div>':'')+
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

var TASK_GROUP = 'data';

function setTaskGroup(g) { TASK_GROUP = g; renderTasks(); }

function taskCatMeta(c) {
  var m = {
    criativo:  ['🎨 Criativo',  '#a78bfa'], copy:      ['✍️ Copy',      '#f0abfc'],
    trafego:   ['📊 Tráfego',   '#60a5fa'], lote:      ['🎟️ Lote',      '#fbbf24'],
    sem_taxa:  ['🎫 Sem taxa',  '#fbbf24'], material:  ['📁 Material',  '#34d399'],
    reuniao:   ['🗓️ Reunião',   '#22d3ee'], pagamento: ['💰 Pagamento', '#4ade80'],
    outro:     ['📌 Outro',     'var(--mt)']
  };
  return m[c] || [c, 'var(--mt)'];
}
function taskPrioMeta(p) {
  var m = { alta: ['🔴 Alta', '#f87171'], media: ['🟡 Média', '#fbbf24'], baixa: ['⚪ Baixa', 'var(--mt)'] };
  return m[p] || m.media;
}
function isLotCat(c) { return c === 'lote' || c === 'sem_taxa'; }

function renderTasks() {
  var all = EVENT_DATA.tasks || [];
  var container = document.getElementById('tasksList');
  var hideDone = document.getElementById('tasksHideDone') && document.getElementById('tasksHideDone').checked;
  // Materiais/links ficam numa faixa própria no topo (não poluem a lista de afazeres)
  var links = all.filter(function(t){ return t.category === 'material' && t.link; });
  var rest  = all.filter(function(t){ return !(t.category === 'material' && t.link); });
  var tasks = hideDone ? rest.filter(function(t){ return t.status !== 'concluido'; }) : rest.slice();

  var lk = document.getElementById('tasksLinks');
  if (lk) {
    lk.innerHTML = links.length
      ? '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--bd)">'
        + '<span style="font-size:11px;color:var(--mt);text-transform:uppercase;letter-spacing:.05em">📎 Links &amp; materiais</span>'
        + links.map(function(t){
            var host = ''; try { host = new URL(t.link).hostname.replace('www.',''); } catch(e) {}
            var ico = /drive\.google/.test(t.link) ? '📁' : /docs\.google/.test(t.link) ? '📄' : /canva/.test(t.link) ? '🎨' : '🔗';
            return '<a href="'+esc(t.link)+'" target="_blank" title="'+esc(host)+'" style="display:inline-flex;align-items:center;gap:6px;'
              + 'font-size:12px;padding:4px 11px;border-radius:20px;background:var(--s2);border:1px solid var(--bd);color:var(--tx);text-decoration:none">'
              + ico+' '+esc(t.title)
              + '<span onclick="event.preventDefault();event.stopPropagation();editTask('+t.id+')" style="opacity:.45;cursor:pointer" title="Editar">✏️</span></a>';
          }).join('')
        + '<button class="btn btn-o btn-sm" onclick="openTaskModal(null,\'material\')" style="font-size:11px;padding:3px 10px">+ link</button>'
        + '</div>'
      : '<div style="margin-bottom:14px"><button class="btn btn-o btn-sm" onclick="openTaskModal(null,\'material\')" style="font-size:11px;padding:3px 10px">📎 + adicionar link/material</button></div>';
  }

  // resumo + seletor de agrupamento
  var pend = all.filter(function(t){ return t.status !== 'concluido'; }).length;
  var done = all.length - pend;
  var hoje = new Date().toISOString().slice(0,10);
  var atrasadas = all.filter(function(t){ return t.status !== 'concluido' && t.due_date && t.due_date < hoje; }).length;
  var lotes = all.filter(function(t){ return isLotCat(t.category) && t.status !== 'concluido'; }).length;
  var sum = document.getElementById('tasksSummary');
  if (sum) sum.innerHTML = '<b style="color:var(--tx)">'+pend+'</b> aberta'+(pend!=1?'s':'')+' · '+done+' concluída'+(done!=1?'s':'')
    + (atrasadas ? ' · <span style="color:#f87171;font-weight:600">'+atrasadas+' atrasada'+(atrasadas!=1?'s':'')+'</span>' : '')
    + (lotes ? ' · <span style="color:#fbbf24;font-weight:600">'+lotes+' virada'+(lotes!=1?'s':'')+' de lote</span>' : '');

  var gb = document.getElementById('tasksGroupBy');
  if (gb) gb.innerHTML = [['data','📅 Por data'],['status','⚙️ Por status'],['category','🏷️ Por tipo'],['assigned','👤 Por responsável']]
    .map(function(g){
      var on = TASK_GROUP === g[0];
      return '<button onclick="setTaskGroup(\''+g[0]+'\')" style="padding:4px 11px;border-radius:7px;font-size:11.5px;font-weight:600;cursor:pointer;'
        + 'border:1px solid '+(on?'var(--ac)':'var(--bd)')+';background:'+(on?'var(--ac)':'transparent')+';color:'+(on?'#fff':'var(--mt2)')+'">'+g[1]+'</button>';
    }).join('');

  if (!tasks.length) {
    container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--mt2)">📋 '
      + (all.length ? 'Nada por aqui com esse filtro.' : 'Nenhuma tarefa ainda. Adicione a primeira!') + '</div>';
    return;
  }

  // ── agrupamento ──
  var groups = {}, order = [];
  tasks.forEach(function(t) {
    var key, label;
    if (TASK_GROUP === 'status') {
      key = t.status;
      label = {pendente:'⚪ Pendente', em_andamento:'◐ Em andamento', concluido:'✓ Concluídas'}[t.status] || t.status;
    } else if (TASK_GROUP === 'category') {
      key = t.category; label = taskCatMeta(t.category)[0];
    } else if (TASK_GROUP === 'assigned') {
      key = t.assigned_name || 'zz_sem'; label = t.assigned_name ? '👤 '+t.assigned_name : '👤 Sem responsável';
    } else {
      key = t.due_date || 'zz_sem';
      if (!t.due_date) label = '📌 Sem data definida';
      else {
        var dt = new Date(t.due_date+'T00:00:00');
        var dias = Math.round((dt - new Date(hoje+'T00:00:00')) / 86400000);
        var rel = dias === 0 ? 'hoje' : dias === 1 ? 'amanhã' : dias === -1 ? 'ontem'
                : dias > 0 ? 'em '+dias+' dias' : dias+'d atrás';
        label = dt.toLocaleDateString('pt-BR',{weekday:'long',day:'2-digit',month:'long'})
              + ' <span style="font-weight:400;color:'+(dias<0?'#f87171':'var(--mt)')+'">· '+rel+'</span>';
      }
    }
    if (!groups[key]) { groups[key] = {label:label, items:[]}; order.push(key); }
    groups[key].items.push(t);
  });

  if (TASK_GROUP === 'status') {
    var ord = {pendente:0, em_andamento:1, concluido:2};
    order.sort(function(a,b){ return (ord[a]!==undefined?ord[a]:9) - (ord[b]!==undefined?ord[b]:9); });
  } else {
    order.sort(function(a,b){ return a > b ? 1 : -1; });
  }

  container.innerHTML = order.map(function(key) {
    var g = groups[key];
    var items = g.items.map(function(t) {
      var isDone = t.status === 'concluido';
      var isDoing = t.status === 'em_andamento';
      var cat = taskCatMeta(t.category);
      var prio = taskPrioMeta(t.priority || 'media');
      var lot = isLotCat(t.category);
      var atrasada = !isDone && t.due_date && t.due_date < hoje;
      var podeApagar = IS_ADMIN || String(t.created_by) === String(USER_ID);

      var props = '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;padding:1px 8px;border-radius:20px;'
        + 'background:var(--s2);border:1px solid var(--bd);color:'+cat[1]+'">'+cat[0]+'</span>';
      if (lot && t.lot_price) props += '<span style="font-size:11px;padding:1px 8px;border-radius:20px;background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3);color:#fbbf24;font-weight:600">R$ '+Number(t.lot_price).toLocaleString('pt-BR',{minimumFractionDigits:2})+'</span>';
      if (TASK_GROUP !== 'data' && t.due_date) {
        props += '<span style="font-size:11px;color:'+(atrasada?'#f87171':'var(--mt)')+'">📅 '+t.due_date.split('-').reverse().slice(0,2).join('/')+(atrasada?' (atrasada)':'')+'</span>';
      } else if (atrasada) {
        props += '<span style="font-size:11px;color:#f87171;font-weight:600">⚠️ atrasada</span>';
      }
      if ((t.priority||'media') !== 'media') props += '<span style="font-size:11px;color:'+prio[1]+'">'+prio[0]+'</span>';
      if (t.assigned_name && TASK_GROUP !== 'assigned') props += '<span class="task-assigned">👤 '+esc(t.assigned_name)+'</span>';
      if (t.link) props += '<a href="'+esc(t.link)+'" target="_blank" onclick="event.stopPropagation()" style="font-size:11px;color:var(--ac);text-decoration:none">🔗 abrir</a>';
      if (t.notes) props += '<span style="font-size:11px;color:var(--mt)" title="'+esc(t.notes)+'">📝 nota</span>';

      return '<div class="task-item'+(lot?' task-lot':'')+'" id="task-'+t.id+'"'
        + (lot ? ' style="border-left:3px solid #fbbf24"' : '') + '>'
        + '<div class="task-check '+(isDone?'done':isDoing?'doing':'')+'" onclick="cycleStatus('+t.id+',\''+t.status+'\')" title="Clique para mudar o status">'+(isDone?'✓':isDoing?'◐':'')+'</div>'
        + '<div class="task-info" onclick="editTask('+t.id+')" style="cursor:pointer">'
        +   '<div class="task-title'+(isDone?' done':'')+'">'+esc(t.title)+'</div>'
        +   '<div class="task-meta" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:3px">'+props+'</div>'
        + '</div>'
        + '<div class="task-actions">'
        +   '<button class="btn btn-o btn-sm" onclick="editTask('+t.id+')" title="Editar">✏️</button>'
        +   (podeApagar ? '<button class="btn btn-d btn-sm" onclick="deleteTask('+t.id+')" title="Remover">🗑️</button>' : '')
        + '</div>'
        + '</div>';
    }).join('');
    return '<div class="task-group"><div class="task-group-title">'+g.label
      + ' <span style="font-weight:400;color:var(--mt);font-size:11px">'+g.items.length+'</span></div>'+items+'</div>';
  }).join('');
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
function toggleLotFields() {
  var cat = document.getElementById('taskCat').value;
  document.getElementById('lotFields').style.display = isLotCat(cat) ? '' : 'none';
}

async function openTaskModal(task, presetCat) {
  await loadUsers();
  var isLot = task ? isLotCat(task.category) : isLotCat(presetCat || '');
  document.getElementById('taskModalTitle').textContent = task
    ? (isLot ? '✏️ Editar virada de lote' : (task.category === 'material' ? '✏️ Editar link/material' : '✏️ Editar tarefa'))
    : (isLot ? '🎟️ Nova virada de lote'  : (presetCat === 'material' ? '📎 Novo link/material' : '➕ Nova tarefa'));
  document.getElementById('taskId').value       = task ? task.id : '';
  document.getElementById('taskTitle').value    = task ? task.title : '';
  var isMat = (task ? task.category : (presetCat||'')) === 'material';
  document.getElementById('taskTitle').placeholder = isLot ? 'Ex.: Virada do 2º lote'
    : isMat ? 'Ex.: Drive do evento' : 'O que precisa ser feito?';
  if (isMat && !task) document.getElementById('taskPriority').value = 'baixa';
  document.getElementById('taskCat').value      = task ? task.category : (presetCat || 'outro');
  document.getElementById('taskDue').value      = task ? (task.due_date||'') : '';
  document.getElementById('taskNotes').value    = task ? (task.notes||'') : '';
  document.getElementById('taskPriority').value = task ? (task.priority||'media') : (isLot ? 'alta' : 'media');
  document.getElementById('taskLotPrice').value = task ? (task.lot_price||'') : '';
  document.getElementById('taskLink').value     = task ? (task.link||'') : '';
  document.getElementById('taskStatus').value   = task ? task.status : 'pendente';
  toggleLotFields();

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

  var cat = document.getElementById('taskCat').value;
  var body = {
    title: title, category: cat,
    due_date: document.getElementById('taskDue').value || null,
    assigned_to: document.getElementById('taskAssigned').value || null,
    priority: document.getElementById('taskPriority').value,
    lot_price: isLotCat(cat) ? (document.getElementById('taskLotPrice').value || '') : '',
    link: document.getElementById('taskLink').value.trim() || null,
    notes: document.getElementById('taskNotes').value
  };
  if (isLotCat(cat)) body.status = document.getElementById('taskStatus').value;

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
  if (!confirm('Remover este item?')) return;
  await api('delete_task', {id:id});
  EVENT_DATA.tasks = EVENT_DATA.tasks.filter(function(t){return t.id!=id;});
  renderTasks();
}

// ── MATERIAIS CRUD ────────────────────────────────────────────




// ── LOTES CRUD ────────────────────────────────────────────────



// ── CONFIGURAÇÕES ─────────────────────────────────────────────
async function populateSettings() {
  var e = EVENT_DATA;
  document.getElementById('cfgName').value      = e.name;
  document.getElementById('cfgDesc').value      = e.description || '';
  document.getElementById('cfgDate').value      = e.date_event || '';
  document.getElementById('cfgCampStart').value = e.date_campaign_start || '';
  document.getElementById('cfgCampEnd').value   = e.date_campaign_end || '';
  document.getElementById('cfgStatus').value    = e.status;
  var cfgBud = document.getElementById('cfgBudget');
  var accIds = (e.account_ids && e.account_ids.length) ? e.account_ids : (e.account_id ? [e.account_id] : []);
  document.querySelectorAll('.cfg-acc').forEach(function(cb){ cb.checked = accIds.indexOf(cb.value) !== -1; });
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
    account_ids: Array.from(document.querySelectorAll('.cfg-acc:checked')).map(function(i){ return i.value; }),
    budget: parseFloat(document.getElementById('cfgBudget').value) || 0
  });
  if (d.error) { alert(d.error); return; }
  alert('✅ Configurações salvas!');
  location.reload();
}

// ── HELPERS ───────────────────────────────────────────────────
function switchTab(name, btn) {
  var target = document.getElementById('tab-'+name);
  if (!target) { console.warn('switchTab: tab-'+name+' não existe no DOM'); return; }
  document.querySelectorAll('.tab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('.tab-content').forEach(function(t){t.classList.remove('active');});
  btn.classList.add('active');
  target.classList.add('active');
}
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
function catLabel(c){ return taskCatMeta(c)[0]; }
function typeLabel(t){var m={lote:'🎟️ Lote',sem_taxa:'✅ Sem Taxa',outros:'📌 Outros'};return m[t]||t;}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

console.log('event.php build: multimoeda-v34 (' + new Date().toISOString() + ')');
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
      html += '<span style="font-family:Inter,sans-serif;font-size:14px;font-weight:700">💰 Budget do Evento</span>';
      if (IS_ADMIN) html += '<button class="btn btn-o btn-sm" onclick="editBudget()">✏️ Editar</button>';
      html += '</div>';
      html += '<div style="font-size:24px;font-family:Inter,sans-serif;font-weight:900;color:var(--gn)">R$'+fmtN(spend)+'</div>';
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
    if (leads > 0) html += '<div class="kpi-box"><div class="kpi-box-val" style="color:#826eff">'+leads+'</div><div class="kpi-box-lbl">Leads</div></div>';
    if (cpl > 0) html += '<div class="kpi-box"><div class="kpi-box-val" style="color:#826eff">R$'+fmtN(cpl)+'</div><div class="kpi-box-lbl">Custo/lead</div></div>';
    html += '</div>';

    // Gráfico de gasto por dia
    var daily = d.daily || [];
    if (daily.length > 0) {
      var maxSpend = Math.max.apply(null, daily.map(function(x){ return parseFloat(x.spend)||0; })) || 1;
      html += '<div class="daily-chart">';
      html += '<div style="font-family:Inter,sans-serif;font-size:13px;font-weight:700;margin-bottom:12px">📅 Gasto por dia</div>';
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
    if (d.budget > 0) await loadBudgetProjection();

  } catch(e) {
    container.innerHTML = '<div style="color:var(--rd)">Erro ao carregar: '+e.message+'</div>';
  }
}

async function loadReportLinks() {
  var box = document.getElementById('reportLinks');
  if (!box) return;
  box.innerHTML = '<div style="color:var(--mt);font-size:12px">Carregando...</div>';
  try {
    var res = await fetch('api/event_performance.php?action=list_report_links', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'list_report_links', event_id: EVENT_ID})
    });
    var d = await res.json();
    if (!d.success) { box.innerHTML = '<div style="color:var(--mt);font-size:12px">'+(d.error||'Erro')+'</div>'; return; }
    if (!d.links.length) { box.innerHTML = '<div style="color:var(--mt);font-size:12px">Nenhum link gerado ainda.</div>'; return; }
    box.innerHTML = d.links.map(function(l){
      var url = location.origin + location.pathname.replace(/event\.php.*$/, '') + 'report.php?token=' + l.token;
      var on = String(l.active) === '1';
      return '<div style="display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--bd);flex-wrap:wrap">'
        + '<span style="font-size:12px;font-weight:600;flex:1;min-width:110px">'+(l.label||'Relatório')
        +   (on ? '' : ' <span style="color:var(--mt);font-weight:400">(desativado)</span>')+'</span>'
        + '<input readonly value="'+url+'" onclick="this.select()" style="flex:2;min-width:190px;font-size:11px;padding:4px 7px">'
        + '<button class="btn btn-o btn-sm" onclick="copyReportLink(this,\''+url+'\')">Copiar</button>'
        + '<a class="btn btn-o btn-sm" href="'+url+'" target="_blank">Abrir</a>'
        + '<button class="btn btn-o btn-sm" onclick="toggleReportLink('+l.id+')">'+(on?'Desativar':'Reativar')+'</button>'
        + '</div>';
    }).join('');
  } catch(e) { box.innerHTML = '<div style="color:var(--mt);font-size:12px">Erro ao carregar.</div>'; }
}

function copyReportLink(btn, url) {
  navigator.clipboard.writeText(url);
  btn.textContent = 'Copiado!';
  setTimeout(function(){ btn.textContent = 'Copiar'; }, 1500);
}

async function createReportLink() {
  var nome = (typeof EVENT_DATA !== 'undefined' && EVENT_DATA && EVENT_DATA.name) ? EVENT_DATA.name : 'evento';
  var label = prompt('Nome do link (ex.: Relatório para o cliente):', 'Relatório — ' + nome);
  if (label === null) return;
  await fetch('api/event_performance.php?action=create_report_link', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'create_report_link', event_id: EVENT_ID, label: label})
  });
  loadReportLinks();
}

async function toggleReportLink(id) {
  await fetch('api/event_performance.php?action=toggle_report_link', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'toggle_report_link', event_id: EVENT_ID, id: id})
  });
  loadReportLinks();
}

var ORC_ATUAL = null;

async function abrirOrcamento(cid, nome) {
  var m = document.getElementById('orcModal');
  if (!m) {
    m = document.createElement('div');
    m.id = 'orcModal';
    m.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:9999;align-items:center;justify-content:center;padding:20px';
    m.onclick = function(e){ if (e.target === m) fecharOrcamento(); };
    m.innerHTML = '<div class="orc-box" style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;max-width:440px;width:100%;padding:20px" onclick="event.stopPropagation()"></div>';
    document.body.appendChild(m);
  }
  var box = m.querySelector('.orc-box');
  box.innerHTML = '<div style="font-size:13px;color:var(--mt)">Consultando orçamento na Meta...</div>';
  m.style.display = 'flex';

  try {
    var res = await fetch('api/event_performance.php?action=get_campaign_budget', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_campaign_budget', event_id: EVENT_ID, campaign_id: cid})
    });
    var d = await res.json();
    if (!d.success) {
      box.innerHTML = '<div style="color:#f87171;font-size:13px">'+(d.error||'Erro')+'</div><button class="btn btn-o btn-sm" style="margin-top:12px" onclick="fecharOrcamento()">Fechar</button>';
      return;
    }
    if (d.budget === null) {
      box.innerHTML = '<div style="font-size:14px;font-weight:700;margin-bottom:8px">💰 '+nome+'</div>'
        + '<div style="font-size:12.5px;color:var(--yw);line-height:1.5">'+d.aviso+'</div>'
        + '<button class="btn btn-o btn-sm" style="margin-top:14px" onclick="fecharOrcamento()">Entendi</button>';
      return;
    }

    ORC_ATUAL = {cid: cid, nome: nome, atual: d.budget, tipo: d.type};
    var tipoTxt = d.type === 'daily' ? 'diário' : 'total (lifetime)';
    box.innerHTML =
        '<div style="font-size:14px;font-weight:700;margin-bottom:3px">💰 Aumentar orçamento</div>'
      + '<div style="font-size:12px;color:var(--mt);margin-bottom:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+nome+'</div>'
      + '<div style="background:var(--s2);border-radius:10px;padding:11px 13px;margin-bottom:14px">'
      +   '<div style="font-size:11px;color:var(--mt);text-transform:uppercase">Orçamento '+tipoTxt+' hoje</div>'
      +   '<div style="font-size:20px;font-weight:800">R$ '+fmtN(d.budget)+'</div>'
      + '</div>'
      + '<div style="display:flex;gap:7px;margin-bottom:12px">'
      +   '<button class="btn btn-o btn-sm" style="flex:1;font-size:12px" onclick="sugereOrc(20)">+20%</button>'
      +   '<button class="btn btn-o btn-sm" style="flex:1;font-size:12px" onclick="sugereOrc(50)">+50%</button>'
      +   '<button class="btn btn-o btn-sm" style="flex:1;font-size:12px" onclick="sugereOrc(100)">+100%</button>'
      + '</div>'
      + '<label style="font-size:11px;color:var(--mt);display:block;margin-bottom:4px">Novo orçamento '+tipoTxt+' (R$)</label>'
      + '<input type="number" id="orcNovo" step="0.01" min="0" value="'+(Math.round(d.budget*1.2*100)/100)+'" oninput="previewOrc()" style="width:100%;margin-bottom:8px">'
      + '<div id="orcPreview" style="font-size:12.5px;margin-bottom:14px"></div>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end">'
      +   '<button class="btn btn-o btn-sm" onclick="fecharOrcamento()">Cancelar</button>'
      +   '<button class="btn btn-p btn-sm" id="orcAplicar" onclick="aplicarOrcamento()">Aplicar na Meta</button>'
      + '</div>';
    previewOrc();
  } catch(e) {
    box.innerHTML = '<div style="color:#f87171;font-size:13px">Erro: '+e.message+'</div>';
  }
}

function sugereOrc(pct) {
  if (!ORC_ATUAL) return;
  document.getElementById('orcNovo').value = Math.round(ORC_ATUAL.atual * (1 + pct/100) * 100) / 100;
  previewOrc();
}

function previewOrc() {
  if (!ORC_ATUAL) return;
  var novo = parseFloat(document.getElementById('orcNovo').value) || 0;
  var el = document.getElementById('orcPreview');
  var btn = document.getElementById('orcAplicar');
  var dif = novo - ORC_ATUAL.atual;
  var pct = ORC_ATUAL.atual > 0 ? (dif / ORC_ATUAL.atual * 100) : 0;
  if (novo <= 0) { el.innerHTML = '<span style="color:#f87171">Informe um valor.</span>'; btn.disabled = true; return; }
  if (ORC_ATUAL.atual > 0 && novo > ORC_ATUAL.atual * 3) {
    el.innerHTML = '<span style="color:#f87171">⚠️ Acima de 3× o valor atual — o sistema não aplica de uma vez. Faça em etapas.</span>';
    btn.disabled = true; return;
  }
  btn.disabled = false;
  var extra = ORC_ATUAL.tipo === 'daily' ? ' · <b>R$ '+fmtN(Math.abs(dif))+'/dia</b> a '+(dif>=0?'mais':'menos') : '';
  el.innerHTML = 'R$ '+fmtN(ORC_ATUAL.atual)+' → <b style="color:'+(dif>=0?'var(--gn,#34d399)':'#f87171')+'">R$ '+fmtN(novo)+'</b> ('+(dif>=0?'+':'')+pct.toFixed(0)+'%)'+extra;
}

async function aplicarOrcamento() {
  if (!ORC_ATUAL) return;
  var novo = parseFloat(document.getElementById('orcNovo').value) || 0;
  if (novo <= 0) return;
  var tipoTxt = ORC_ATUAL.tipo === 'daily' ? 'diário' : 'total';
  if (!confirm('Alterar o orçamento ' + tipoTxt + ' de "' + ORC_ATUAL.nome + '"?\n\nDe R$ ' + fmtN(ORC_ATUAL.atual) + ' para R$ ' + fmtN(novo) + '\n\nIsso altera a conta de anúncios de verdade e passa a gastar mais.')) return;
  var btn = document.getElementById('orcAplicar');
  btn.disabled = true; btn.textContent = 'aplicando...';
  try {
    var res = await fetch('api/event_performance.php?action=set_campaign_budget', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'set_campaign_budget', event_id: EVENT_ID, campaign_id: ORC_ATUAL.cid,
        new_budget: novo, budget_type: ORC_ATUAL.tipo, previous_budget: ORC_ATUAL.atual, reason: 'recomendacao: escalar'})
    });
    var d = await res.json();
    if (!d.success) { alert(d.error || 'Erro ao aplicar'); btn.disabled = false; btn.textContent = 'Aplicar na Meta'; return; }
    fecharOrcamento();
    alert('✅ Orçamento atualizado para R$ ' + fmtN(novo) + '.');
    loadEventConversions();
  } catch(e) { alert('Erro: ' + e.message); btn.disabled = false; btn.textContent = 'Aplicar na Meta'; }
}

function fecharOrcamento() {
  var m = document.getElementById('orcModal');
  if (m) m.style.display = 'none';
  ORC_ATUAL = null;
}

async function desfazerOrcamento(cid, nome, anterior, tipo, atual) {
  if (!confirm('Voltar o orçamento de "' + nome + '" para R$ ' + fmtN(anterior) + '?')) return;
  var res = await fetch('api/event_performance.php?action=set_campaign_budget', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'set_campaign_budget', event_id: EVENT_ID, campaign_id: cid,
      new_budget: anterior, budget_type: tipo, previous_budget: atual, reason: 'desfazer'})
  });
  var d = await res.json();
  if (!d.success) alert(d.error || 'Erro');
  loadEventConversions();
}

function atualizaBarraAcoes() {
  var marcadas = document.querySelectorAll('.rec-check:checked');
  var barra = document.getElementById('recBarra');
  if (!barra) return;
  barra.style.display = marcadas.length ? 'flex' : 'none';
  var r = document.getElementById('recResumo');
  if (r) r.textContent = marcadas.length + ' campanha' + (marcadas.length > 1 ? 's' : '') + ' será' + (marcadas.length > 1 ? 'o' : '') + ' pausada' + (marcadas.length > 1 ? 's' : '') + ' na Meta';
}

function marcarTodasPausar() {
  document.querySelectorAll('.rec-check').forEach(function(cb){ cb.checked = true; });
  atualizaBarraAcoes();
}

async function aplicarPausas() {
  var marcadas = Array.from(document.querySelectorAll('.rec-check:checked'));
  if (!marcadas.length) return;

  var nomes = marcadas.map(function(cb){ return '• ' + cb.dataset.nome; }).join('\n');
  if (!confirm('Pausar ' + marcadas.length + ' campanha(s) na Meta?\n\n' + nomes
      + '\n\nIsso altera a conta de anúncios de verdade. Você pode reativar depois pelo histórico.')) return;

  var barra = document.getElementById('recBarra');
  var resumo = document.getElementById('recResumo');
  resumo.textContent = 'aplicando...';
  barra.querySelectorAll('button').forEach(function(b){ b.disabled = true; });

  try {
    var res = await fetch('api/event_performance.php?action=apply_campaign_actions', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        action:'apply_campaign_actions', event_id: EVENT_ID,
        items: marcadas.map(function(cb){
          return {campaign_id: cb.dataset.cid, action: 'pause', reason: cb.dataset.motivo};
        })
      })
    });
    var d = await res.json();
    if (!d.success) { alert(d.error || 'Erro ao aplicar'); }
    else {
      var falhas = (d.resultados||[]).filter(function(r){ return !r.ok; });
      alert('✅ ' + d.aplicadas + ' de ' + d.total + ' campanha(s) pausada(s).'
        + (falhas.length ? '\n\nFalhas:\n' + falhas.map(function(f){ return '• ' + f.campaign_name + ': ' + f.erro; }).join('\n') : ''));
      loadEventConversions();
    }
  } catch(e) { alert('Erro: ' + e.message); }
  barra.querySelectorAll('button').forEach(function(b){ b.disabled = false; });
}

async function carregarHistoricoAcoes() {
  var box = document.getElementById('recHistorico');
  if (!box || !IS_ADMIN) return;
  try {
    var res = await fetch('api/event_performance.php?action=list_campaign_actions', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'list_campaign_actions', event_id: EVENT_ID})
    });
    var d = await res.json();
    if (!d.success || !d.acoes.length) { box.innerHTML = ''; return; }
    box.innerHTML = '<div style="margin-top:10px;padding:9px 12px;background:var(--s2);border-radius:10px">'
      + '<div style="font-size:11px;color:var(--mt);margin-bottom:5px">Alterações feitas por aqui</div>'
      + d.acoes.map(function(a){
          var quando = new Date(a.created_at.replace(' ','T')).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
          var pausada = a.action === 'pause';
          return '<div style="display:flex;align-items:center;gap:9px;font-size:11.5px;padding:3px 0;flex-wrap:wrap">'
            + '<span style="color:var(--mt);width:88px">'+quando+'</span>'
            + '<span style="font-weight:600">'+(a.action==='budget'?'💰 orçamento':(pausada?'⏸️ pausou':'▶️ reativou'))+'</span>'
            + '<span style="flex:1;min-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+(a.campaign_name||a.campaign_id)+(a.action==='budget'&&a.new_budget?' <span style="color:var(--mt)">R$'+fmtN(a.previous_budget||0)+' → R$'+fmtN(a.new_budget)+'</span>':'')+'</span>'
            + '<span style="color:var(--mt)">por '+(a.username||'—')+'</span>'
            + (a.action==='budget'&&a.previous_budget ? '<button class="btn btn-o btn-sm" style="font-size:10.5px;padding:2px 9px" onclick="desfazerOrcamento(\''+a.campaign_id+'\',\''+(a.campaign_name||'').replace(/'/g,'')+'\','+a.previous_budget+',\''+(a.budget_type||'daily')+'\','+(a.new_budget||0)+')">desfazer</button>' : (pausada ? '<button class="btn btn-o btn-sm" style="font-size:10.5px;padding:2px 9px" onclick="reativarCampanha(\''+a.campaign_id+'\',\''+(a.campaign_name||'').replace(/'/g,'')+'\')">desfazer</button>' : ''))
            + '</div>';
        }).join('')
      + '</div>';
  } catch(e) {}
}

async function reativarCampanha(cid, nome) {
  if (!confirm('Reativar "' + nome + '" na Meta?')) return;
  var res = await fetch('api/event_performance.php?action=apply_campaign_actions', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'apply_campaign_actions', event_id: EVENT_ID,
      items: [{campaign_id: cid, action: 'activate', reason: 'desfazer'}]})
  });
  var d = await res.json();
  if (!d.success) alert(d.error || 'Erro');
  loadEventConversions();
}

var CONV_PERIOD = 'tudo';
var CONV_PERIODS = [['hoje','Hoje'],['ontem','Ontem'],['3','3 dias'],['7','7 dias'],['15','15 dias'],['30','30 dias'],['tudo','Todo o período']];

var CONV_INI = null, CONV_FIM = null;   // intervalo personalizado

function setConvPeriod(p) {
  CONV_PERIOD = p;
  if (p !== 'custom') { CONV_INI = null; CONV_FIM = null; }
  loadEventConversions();
}

function abrirPeriodoCustom() {
  var box = document.getElementById('convCustomBox');
  if (!box) return;
  var aberto = box.style.display !== 'none';
  box.style.display = aberto ? 'none' : 'flex';
  if (!aberto) {
    // sugere os últimos 7 dias como ponto de partida
    var fim = new Date(), ini = new Date(Date.now() - 6 * 86400000);
    var iso = function(d){ return d.toISOString().slice(0,10); };
    if (!document.getElementById('convIni').value) document.getElementById('convIni').value = CONV_INI || iso(ini);
    if (!document.getElementById('convFim').value) document.getElementById('convFim').value = CONV_FIM || iso(fim);
  }
}

function aplicarPeriodoCustom() {
  var ini = document.getElementById('convIni').value;
  var fim = document.getElementById('convFim').value;
  if (!ini || !fim) { alert('Escolha a data inicial e a final.'); return; }
  if (ini > fim) { var t = ini; ini = fim; fim = t;
    document.getElementById('convIni').value = ini;
    document.getElementById('convFim').value = fim; }
  CONV_INI = ini; CONV_FIM = fim; CONV_PERIOD = 'custom';
  loadEventConversions();
}

function renderConvPeriods() {
  var el = document.getElementById('convPeriods');
  if (!el) return;
  var custom = CONV_PERIOD === 'custom';
  el.innerHTML = CONV_PERIODS.map(function(p){
    var on = CONV_PERIOD === p[0];
    return '<button onclick="setConvPeriod(\''+p[0]+'\')" style="padding:5px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;'
      + 'border:1px solid '+(on?'var(--ac)':'var(--bd)')+';background:'+(on?'var(--ac)':'transparent')+';color:'+(on?'#fff':'var(--mt2)')+'">'
      + p[1] + '</button>';
  }).join('')
  + '<button onclick="abrirPeriodoCustom()" title="Escolher um intervalo de datas" style="padding:5px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;'
  +   'border:1px solid ' + (custom ? 'var(--ac)' : 'var(--bd)') + ';background:' + (custom ? 'var(--ac)' : 'transparent') + ';color:' + (custom ? '#fff' : 'var(--mt2)') + '">'
  +   '📅 ' + (custom && CONV_INI ? fmtDataBR(CONV_INI) + ' – ' + fmtDataBR(CONV_FIM) : 'Escolher datas') + '</button>';

  var box = document.getElementById('convCustomBox');
  if (box && custom) box.style.display = 'flex';
}

function fmtDataBR(iso) {
  if (!iso) return '';
  var p = iso.split('-');
  return p[2] + '/' + p[1];
}

// ── PERFIL & PROJEÇÃO ────────────────────────────────────────
var PERFIL_DADOS = null;
var PERFIL_ORDEM = {};      // por bloco: 'maior' | 'menor' | 'fixa'
var PERFIL_ABERTO = {};     // por bloco: true = mostra tudo

function alternarOrdem(id) {
  var atual = PERFIL_ORDEM[id] || 'maior';
  PERFIL_ORDEM[id] = atual === 'maior' ? 'menor' : (atual === 'menor' ? 'fixa' : 'maior');
  renderPerfilGraficos();
}
function alternarVerMais(id) {
  PERFIL_ABERTO[id] = !PERFIL_ABERTO[id];
  renderPerfilGraficos();
}

function cardPerfil(id, itens, titulo, opts) {
  opts = opts || {};
  if (!itens || !itens.length) return '';
  var campo = opts.campo || 'impressions';
  var total = itens.reduce(function(a, i){ return a + i[campo]; }, 0);
  if (total <= 0) return '';

  var ordem = PERFIL_ORDEM[id] || (opts.ordemPadrao || 'maior');
  var lista = itens.slice();
  if (ordem === 'maior')      lista.sort(function(a,b){ return b[campo] - a[campo]; });
  else if (ordem === 'menor') lista.sort(function(a,b){ return a[campo] - b[campo]; });
  else                        lista.sort(function(a,b){ return a.valor > b.valor ? 1 : -1; });

  var limite = opts.limite || 6;
  var aberto = !!PERFIL_ABERTO[id];
  var visiveis = aberto ? lista : lista.slice(0, limite);
  var ocultos  = lista.length - visiveis.length;

  var cores = ['#5470ff','#34d399','#fbbf24','#f87171','#a78bfa','#22d3ee','#f0abfc','#60a5fa','#4ade80','#fb923c'];
  var maiorValor = Math.max.apply(null, lista.map(function(i){ return i[campo]; }));
  var rotOrdem = ordem === 'maior' ? '↓ maior' : (ordem === 'menor' ? '↑ menor' : '≡ ordem natural');

  return '<div style="background:var(--sf);border:1px solid var(--bd);border-radius:13px;padding:15px 17px;align-self:start">'
    + '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:12px">'
    +   '<span style="font-size:13px;font-weight:700">' + titulo + '</span>'
    +   '<button onclick="alternarOrdem(\'' + id + '\')" title="Trocar ordenação"'
    +     ' style="background:var(--s2);border:1px solid var(--bd);color:var(--mt);border-radius:7px;'
    +     'font-size:10.5px;padding:3px 8px;cursor:pointer;white-space:nowrap">' + rotOrdem + '</button>'
    + '</div>'
    + visiveis.map(function(i, idx){
        var pct = i[campo] / total * 100;
        var largura = maiorValor > 0 ? (i[campo] / maiorValor * 100) : 0;
        var rot = opts.rotulo ? opts.rotulo(i.valor) : i.valor;
        var extra = '';
        if (opts.mostraCompra && i.purchases > 0) {
          var cpa = i.purchases > 0 && i.spend > 0 ? i.spend / i.purchases : 0;
          extra = ' <span style="color:var(--gn,#34d399);font-size:10.5px">· ' + fmtInt(i.purchases) + ' compras'
                + (cpa > 0 ? ' (R$' + fmtN(cpa) + ')' : '') + '</span>';
        }
        return '<div style="margin-bottom:9px">'
          + '<div style="display:flex;justify-content:space-between;gap:8px;font-size:12px;margin-bottom:3px">'
          +   '<span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + rot + extra + '</span>'
          +   '<span style="color:var(--mt);white-space:nowrap">' + fmtN(pct, 1) + '% <span style="font-size:10.5px">(' + fmtInt(i[campo]) + ')</span></span>'
          + '</div>'
          + '<div style="height:7px;background:var(--s2);border-radius:5px;overflow:hidden">'
          +   '<div style="height:100%;width:' + largura + '%;background:' + cores[idx % cores.length] + '"></div></div>'
          + '</div>';
      }).join('')
    + (ocultos > 0 || aberto
        ? '<button onclick="alternarVerMais(\'' + id + '\')" style="width:100%;margin-top:4px;background:none;border:none;'
          + 'color:var(--ac);font-size:11.5px;cursor:pointer;padding:5px;font-weight:600">'
          + (aberto ? '▲ ver menos' : '▼ ver mais (' + ocultos + ')') + '</button>'
        : '')
    + (opts.nota ? '<div style="font-size:10.5px;color:var(--mt2);margin-top:4px">' + opts.nota + '</div>' : '')
    + '</div>';
}

function renderPerfilGraficos() {
  var gr = document.getElementById('perfilGraficos');
  var pf = (PERFIL_DADOS && PERFIL_DADOS.perfil) || {};
  if (!Object.keys(pf).length) {
    gr.innerHTML = '<div style="padding:22px;text-align:center;color:var(--mt2);font-size:13px">'
      + '👥 Perfil ainda não sincronizado. O cron <code>sync_audience.php</code> traz os dados a cada 6 horas.</div>';
    return;
  }

  var rotGenero = function(v){ return ({female:'👩 Feminino', male:'👨 Masculino', unknown:'Não informado'})[v] || v; };
  var rotHora   = function(v){ return v + 'h'; };

  var html = '';

  // ── o que a Meta entrega sobre COMPRA ──
  if (pf.funil || pf.atribuicao) {
    html += '<div style="font-size:13px;font-weight:700;margin-bottom:4px">🛒 O que a Meta registra sobre a compra</div>'
      + '<div style="font-size:11.5px;color:var(--mt);margin-bottom:12px">Dados de conversão do pixel, sem corte demográfico — aqui os números são confiáveis.</div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:14px;align-items:start;margin-bottom:22px">'
      + cardPerfil('funil', pf.funil, '🔻 Funil completo', {campo:'purchases', ordemPadrao:'maior', limite:8,
          nota:'Etapas registradas pelo pixel no período do evento.'})
      + cardPerfil('atrib', pf.atribuicao, '🎯 Origem da compra', {campo:'purchases', ordemPadrao:'maior', limite:6,
          nota:'Clique (1d) = comprou logo após clicar. Visualização (1d) = viu o anúncio e comprou sem clicar.'})
      + '</div>';
  }

  html += '<div style="font-size:13px;font-weight:700;margin-bottom:4px">👥 Perfil da audiência alcançada</div>'
    + '<div style="font-size:11.5px;color:var(--mt);margin-bottom:12px">Quem <b>viu e clicou</b> nos anúncios. '
    + 'A Meta não libera compra por faixa demográfica, então isto orienta segmentação — não é o perfil do comprador.</div>'
    + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:14px;align-items:start">'
    + cardPerfil('age',    pf.age,    '🎂 Faixa etária', {ordemPadrao:'fixa', limite:6, mostraCompra:true})
    + cardPerfil('gender', pf.gender, '⚧ Gênero', {rotulo:rotGenero, limite:4, mostraCompra:true})
    + cardPerfil('region', pf.region, '📍 Estado', {limite:6, nota:'A Meta não fornece cidade — apenas estado.', mostraCompra:true})
    + cardPerfil('hour',   pf.hour,   '🕐 Hora do dia', {ordemPadrao:'maior', limite:6, rotulo:rotHora,
        nota:'Horário de entrega do anúncio, no fuso da conta.'})
    + cardPerfil('plat',   pf.platform, '📱 Plataforma e posicionamento', {limite:6, mostraCompra:true})
    + '</div>';

  gr.innerHTML = html;
}

async function loadPerfil() {
  var pj = document.getElementById('perfilProj');
  var gr = document.getElementById('perfilGraficos');
  pj.innerHTML = '<div style="padding:24px;text-align:center;color:var(--mt2)">Carregando...</div>';
  gr.innerHTML = '';

  try {
    var r = await fetch('api/event_performance.php?action=get_perfil', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_perfil', event_id: EVENT_ID})
    });
    var d = await r.json();
    if (!d.success) { pj.innerHTML = '<div style="padding:18px;color:#f87171">' + (d.error || 'Erro') + '</div>'; return; }
    PERFIL_DADOS = d;

    var p = d.projecao;
    if (p && p.ja_vendido > 0) {
      pj.innerHTML = '<div style="background:linear-gradient(135deg,rgba(84,112,255,.14),rgba(52,211,153,.10));'
        + 'border:1px solid var(--bd);border-radius:14px;padding:18px 20px">'
        + '<div style="font-size:11px;color:var(--mt);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px">'
        +   '📈 Projeção até o fim da venda'
        +   (p.dias_restantes > 0 ? ' · faltam ' + p.dias_restantes + ' dia(s)' : ' · venda encerrada')
        + '</div>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:16px">'
        +   '<div><div style="font-size:11px;color:var(--mt)">Projeção de ingressos</div>'
        +     '<div style="font-size:27px;font-weight:800;line-height:1.1">~ ' + fmtInt(p.proj_ingressos) + '</div>'
        +     '<div style="font-size:11px;color:var(--mt)">entre ' + fmtInt(p.faixa_min) + ' e ' + fmtInt(p.faixa_max) + '</div></div>'
        +   (p.ticket > 0
              ? '<div><div style="font-size:11px;color:var(--mt)">Projeção de receita</div>'
                + '<div style="font-size:27px;font-weight:800;line-height:1.1;color:var(--gn,#34d399)">~ R$ ' + fmtN(p.proj_receita) + '</div>'
                + '<div style="font-size:11px;color:var(--mt)">ticket médio R$ ' + fmtN(p.ticket) + '</div></div>'
              : '')
        +   '<div><div style="font-size:11px;color:var(--mt)">Já vendido</div>'
        +     '<div style="font-size:20px;font-weight:700;margin-top:3px">' + fmtInt(p.ja_vendido) + ' ingressos</div>'
        +     (p.ja_receita > 0 ? '<div style="font-size:11px;color:var(--mt)">R$ ' + fmtN(p.ja_receita) + '</div>' : '') + '</div>'
        +   '<div><div style="font-size:11px;color:var(--mt)">Velocidade de venda</div>'
        +     '<div style="font-size:20px;font-weight:700;margin-top:3px">' + fmtN(p.ritmo_dia, 1) + '<span style="font-size:12px;font-weight:500"> /dia</span></div>'
        +     '<div style="font-size:11px;color:var(--mt)">últimos 7 dias</div></div>'
        +   (p.melhor_dia
              ? '<div><div style="font-size:11px;color:var(--mt)">Melhor dia</div>'
                + '<div style="font-size:20px;font-weight:700;margin-top:3px">' + fmtInt(p.melhor_dia.qtd) + '</div>'
                + '<div style="font-size:11px;color:var(--mt)">' + p.melhor_dia.data.split('-').reverse().slice(0,2).join('/') + '</div></div>'
              : '')
        + '</div>'
        + '<div style="font-size:10.5px;color:var(--mt2);margin-top:14px;line-height:1.5;border-top:1px solid var(--bd);padding-top:10px">'
        +   'ℹ️ Venda de ingresso não é linear: concentra no lançamento e volta a subir na reta final. '
        +   'A estimativa cruza o ritmo dos últimos 7 dias com essa curva'
        +   (p.dias_restantes <= 10 && p.dias_restantes > 0 ? ' — faltando menos de 10 dias, o peso da reta final é aplicado' : '')
        +   '. ' + (p.tem_lancamento
                ? 'Base: vendas totais lançadas na aba Comparativo.'
                : '<b>Base: apenas vendas do Ads</b> — lance as vendas totais na aba Comparativo para incluir o orgânico.')
        +   ' Não é garantia de venda.'
        + '</div></div>';
    } else {
      pj.innerHTML = '<div style="padding:16px 18px;background:var(--s2);border-radius:12px;font-size:13px;color:var(--mt)">'
        + 'Ainda não há vendas suficientes para projetar.</div>';
    }

    renderPerfilGraficos();
  } catch (e) {
    pj.innerHTML = '<div style="padding:18px;color:#f87171">Erro: ' + e.message + '</div>';
  }
}

// ── SIMULAÇÃO DE META ────────────────────────────────────────
var SIM = null;
var SIM_TIPO = 'ingressos';

function simTipo(btn) {
  document.querySelectorAll('.sim-tipo').forEach(function(b){
    b.classList.remove('on');
    b.style.background = 'transparent'; b.style.border = '1px solid var(--bd)'; b.style.color = 'var(--mt2)';
  });
  btn.classList.add('on');
  btn.style.background = 'var(--ac)'; btn.style.border = '1px solid var(--ac)'; btn.style.color = '#fff';
  SIM_TIPO = btn.dataset.tipo;

  var b = SIM ? SIM.base : null;
  if (SIM_TIPO === 'faturamento') {
    document.getElementById('simLabelVendido').textContent = 'Faturado até agora (total, R$)';
    document.getElementById('simLabelMeta').textContent    = 'Meta de faturamento (R$)';
    document.getElementById('simMeta').placeholder = '150000';
    document.getElementById('simMeta').step = '100';
    document.getElementById('simVendido').step = '100';
    if (b) document.getElementById('simVendido').value = Math.round(b.receita);
  } else {
    document.getElementById('simLabelVendido').textContent = 'Vendidos até agora (total)';
    document.getElementById('simLabelMeta').textContent    = 'Meta (quanto quer vender no total)';
    document.getElementById('simMeta').placeholder = '1000';
    document.getElementById('simMeta').step = '1';
    document.getElementById('simVendido').step = '1';
    if (b) document.getElementById('simVendido').value = b.vendas_totais || b.compras;
  }
  atualizaDicaVendido();
  calcularSimulacao();
}

function atualizaDicaVendido() {
  if (!SIM) return;
  var b = SIM.base;
  var el = document.getElementById('simVendidoDica');
  var v = parseFloat(document.getElementById('simVendido').value) || 0;

  if (SIM_TIPO === 'faturamento') {
    el.innerHTML = 'O Ads registrou R$ ' + fmtN(b.receita) + '.'
      + (v > b.receita ? ' Orgânico estimado: R$ ' + fmtN(v - b.receita) + ' (' + fmtN((1 - b.receita / v) * 100) + '%).' : '');
    return;
  }
  el.innerHTML = 'O Ads registrou ' + fmtInt(b.compras) + ' venda(s).'
    + (v > b.compras ? ' Orgânico: ' + fmtInt(v - b.compras) + ' (' + fmtN((1 - b.compras / v) * 100) + '%).' : '')
    + (b.vendas_totais ? ' <span style="color:var(--mt2)">Lançado na aba de vendas: ' + fmtInt(b.vendas_totais) + '.</span>' : '');
}

async function loadSimulacao() {
  var base = document.getElementById('simBase');
  base.innerHTML = '<div style="padding:22px;text-align:center;color:var(--mt2)">Carregando base...</div>';
  try {
    var r = await fetch('api/event_performance.php?action=get_simulacao', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_simulacao', event_id: EVENT_ID})
    });
    var d = await r.json();
    if (!d.success) { base.innerHTML = '<div style="padding:18px;color:#f87171">' + (d.error || 'Erro') + '</div>'; return; }
    SIM = d;
    var b = d.base;

    if (b.compras < 1) {
      base.innerHTML = '<div style="padding:16px 18px;background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.3);'
        + 'border-radius:12px;color:var(--yw,#fbbf24);font-size:13.5px">Ainda não há compras registradas no Ads neste evento. '
        + 'Sem CPA não dá para projetar — a simulação libera quando as primeiras vendas entrarem.</div>';
      return;
    }

    var itens = [
      ['CPA do Ads', 'R$ ' + fmtN(b.cpa)],
      ['Vendas pelo Ads', fmtInt(b.compras)],
      ['Investido até agora', 'R$ ' + fmtN(b.gasto)],
      ['Ticket médio', b.ticket > 0 ? 'R$ ' + fmtN(b.ticket) : '—'],
      ['Gasto/dia', 'R$ ' + fmtN(b.gasto_diario)]
    ];
    if (b.dias_restantes !== null) itens.push(['Dias até o evento', b.dias_restantes + 'd']);

    base.innerHTML = '<div style="background:var(--sf);border:1px solid var(--bd);border-radius:13px;padding:15px 17px">'
      + '<div style="font-size:13px;font-weight:700;margin-bottom:11px">📐 Base do cálculo <span style="font-weight:400;color:var(--mt);font-size:11px">· todo o período do evento</span></div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(122px,1fr));gap:12px">'
      + itens.map(function(i){
          return '<div><div style="font-size:10px;color:var(--mt);text-transform:uppercase;letter-spacing:.04em">' + i[0] + '</div>'
               + '<div style="font-size:15px;font-weight:700;margin-top:2px">' + i[1] + '</div></div>';
        }).join('')
      + '</div>'
      + (b.cpa_7d > 0 && Math.abs(b.cpa_7d - b.cpa) / b.cpa > 0.25
          ? '<div style="margin-top:11px;font-size:11.5px;color:var(--yw,#fbbf24)">⚠️ CPA dos últimos 7 dias: R$ '
            + fmtN(b.cpa_7d) + ' — ' + (b.cpa_7d > b.cpa ? 'acima' : 'abaixo') + ' da média usada no cálculo.</div>'
          : '')
      + '</div>';

    // pré-preenche o total vendido com o melhor dado disponível
    var inpV = document.getElementById('simVendido');
    if (!inpV.value) inpV.value = b.vendas_totais || b.compras;
    atualizaDicaVendido();
    calcularSimulacao();
  } catch (e) {
    base.innerHTML = '<div style="padding:18px;color:#f87171">Erro: ' + e.message + '</div>';
  }
}

/** CPA sobe conforme escala: +12% a cada dobra do volume já vendido pelo Ads. */
function cpaEscalado(cpaBase, jaVendidoAds, aMaisAds, fator) {
  if (jaVendidoAds < 1) return cpaBase * fator;
  return cpaBase * fator * (1 + 0.12 * (Math.log2(1 + aMaisAds / jaVendidoAds)));
}

function calcularSimulacao() {
  var box = document.getElementById('simResultado');
  if (!SIM) return;
  var b = SIM.base;
  atualizaDicaVendido();

  var vendidoTxt = document.getElementById('simVendido').value;
  var metaTxt    = document.getElementById('simMeta').value;
  if (metaTxt === '' || parseFloat(metaTxt) <= 0) { box.innerHTML = ''; return; }
  document.getElementById('simVerba').value = '';

  var emIngressos = SIM_TIPO === 'ingressos';
  var vendido = vendidoTxt === '' ? (emIngressos ? b.compras : b.receita) : parseFloat(vendidoTxt);
  var meta    = parseFloat(metaTxt);

  // converte tudo para ingressos, que é a unidade do CPA
  var vendidoIng, metaIng, adsIng = b.compras;
  if (emIngressos) {
    vendidoIng = vendido; metaIng = meta;
  } else {
    if (b.ticket <= 0) { box.innerHTML = '<div style="padding:16px;color:#f87171">Sem ticket médio para converter faturamento em ingressos.</div>'; return; }
    vendidoIng = vendido / b.ticket; metaIng = meta / b.ticket;
  }

  var faltamTotal = metaIng - vendidoIng;
  if (faltamTotal <= 0) {
    box.innerHTML = '<div style="padding:18px 20px;background:rgba(52,211,153,.08);border:1px solid rgba(52,211,153,.35);'
      + 'border-radius:13px;color:var(--gn,#34d399);font-size:14px;font-weight:600">🎉 Meta já alcançada.</div>';
    return;
  }

  // fatia que historicamente vem do Ads (o resto tende a vir orgânico)
  var fatiaAds = (vendidoIng > 0 && adsIng > 0) ? Math.min(1, adsIng / vendidoIng) : 1;
  var faltamAds = Math.ceil(faltamTotal * fatiaAds);

  var html = '<div class="card" style="margin-bottom:14px">'
    + '<div style="font-size:15px;line-height:1.6">'
    +   'De <b>' + (emIngressos ? fmtInt(vendidoIng) : 'R$ ' + fmtN(vendido)) + '</b>'
    +   ' para <b>' + (emIngressos ? fmtInt(metaIng) : 'R$ ' + fmtN(meta)) + '</b>'
    +   ' → faltam <b style="color:var(--ac)">' + (emIngressos ? fmtInt(Math.ceil(faltamTotal)) + ' ingressos' : 'R$ ' + fmtN(meta - vendido)) + '</b>'
    + '</div>'
    + (fatiaAds < 0.999
        ? '<div style="font-size:12.5px;color:var(--mt);margin-top:6px">Historicamente ' + fmtN(fatiaAds * 100)
          + '% das vendas vêm do Ads, então cerca de <b>' + fmtInt(faltamAds) + '</b> precisam vir de anúncio — o resto tende a vir orgânico.</div>'
        : '<div style="font-size:12.5px;color:var(--mt);margin-top:6px">Considerando que tudo precisa vir do Ads.</div>')
    + '</div>';

  html += '<div style="font-size:13px;font-weight:700;margin-bottom:9px">💰 Quanto investir em Ads</div>'
       + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:13px;margin-bottom:16px">';

  [['Otimista', 0.9, 'CPA se mantém ou melhora', 'var(--gn,#34d399)'],
   ['Provável', 1.0, 'CPA na média, com desgaste ao escalar', 'var(--ac)'],
   ['Conservador', 1.35, 'CPA piora — público saturando', 'var(--yw,#fbbf24)']].forEach(function(c){
    var cpaC = cpaEscalado(b.cpa, adsIng, faltamAds, c[1]);
    var invest = faltamAds * cpaC;
    var porDia = (b.dias_restantes && b.dias_restantes > 0) ? invest / b.dias_restantes : null;
    html += '<div style="background:var(--sf);border:1px solid var(--bd);border-left:3px solid ' + c[3] + ';border-radius:12px;padding:15px 16px">'
      + '<div style="font-size:11px;color:' + c[3] + ';font-weight:700;text-transform:uppercase;letter-spacing:.04em">' + c[0] + '</div>'
      + '<div style="font-size:26px;font-weight:800;margin:5px 0 3px">R$ ' + fmtN(invest) + '</div>'
      + '<div style="font-size:11.5px;color:var(--mt)">CPA projetado R$ ' + fmtN(cpaC) + '</div>'
      + (porDia !== null ? '<div style="font-size:11.5px;color:var(--mt);margin-top:3px">≈ R$ ' + fmtN(porDia) + '/dia em ' + b.dias_restantes + ' dia(s)</div>' : '')
      + '<div style="font-size:11px;color:var(--mt2);margin-top:7px;line-height:1.35">' + c[2] + '</div>'
      + '</div>';
  });
  html += '</div>';

  // avisos de viabilidade
  var investProv = faltamAds * cpaEscalado(b.cpa, adsIng, faltamAds, 1.0);
  var avisos = [];
  if (b.dias_restantes !== null && b.dias_restantes > 0 && b.gasto_diario > 0) {
    var porDiaProv = investProv / b.dias_restantes;
    if (porDiaProv > b.gasto_diario * 3) {
      avisos.push('Exigiria R$ ' + fmtN(porDiaProv) + '/dia — mais de 3× o ritmo atual (R$ ' + fmtN(b.gasto_diario)
        + '/dia). Saltos assim costumam piorar o CPA; considere alongar o prazo ou revisar a meta.');
    }
  } else if (b.dias_restantes === 0) {
    avisos.push('O evento é hoje — não há tempo de veiculação para essa meta.');
  }
  if (b.frequencia >= 3) {
    avisos.push('Frequência em ' + fmtN(b.frequencia) + 'x: o mesmo público já viu muitas vezes. Sem criativo ou público novo, tende ao cenário conservador.');
  }
  if (SIM.evento.budget > 0) {
    var restante = SIM.evento.budget - b.gasto;
    avisos.push(restante >= investProv
      ? '✓ A verba restante (R$ ' + fmtN(restante) + ') cobre o cenário provável.'
      : 'A verba cadastrada tem R$ ' + fmtN(Math.max(0, restante)) + ' restantes — faltariam R$ '
        + fmtN(investProv - Math.max(0, restante)) + ' para o cenário provável.');
  }
  if (avisos.length) {
    html += '<div class="card"><div class="card-title">⚠️ Vale considerar</div>'
      + avisos.map(function(a){
          var bom = a.indexOf('✓') === 0;
          return '<div style="font-size:12.5px;line-height:1.5;padding:6px 0;color:' + (bom ? 'var(--gn,#34d399)' : 'var(--mt2)') + '">'
               + (bom ? '' : '• ') + a + '</div>';
        }).join('') + '</div>';
  }

  box.innerHTML = html;
}

function calcularSimulacaoInversa() {
  var box = document.getElementById('simResultado');
  if (!SIM) return;
  var b = SIM.base;
  var v = document.getElementById('simVerba').value;
  if (v === '' || parseFloat(v) <= 0) { box.innerHTML = ''; return; }
  document.getElementById('simMeta').value = '';

  var verba = parseFloat(v);
  var vendidoTxt = document.getElementById('simVendido').value;
  var vendidoIng = vendidoTxt === '' ? b.compras
                 : (SIM_TIPO === 'faturamento' && b.ticket > 0 ? parseFloat(vendidoTxt) / b.ticket : parseFloat(vendidoTxt));
  var fatiaAds = (vendidoIng > 0 && b.compras > 0) ? Math.min(1, b.compras / vendidoIng) : 1;

  var html = '<div class="card" style="margin-bottom:14px"><div style="font-size:15px">'
    + 'Investindo <b style="color:var(--ac)">R$ ' + fmtN(verba) + '</b> a mais em Ads</div></div>'
    + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:13px">';

  [['Otimista', 0.9, 'var(--gn,#34d399)'], ['Provável', 1.0, 'var(--ac)'], ['Conservador', 1.35, 'var(--yw,#fbbf24)']]
  .forEach(function(c){
    var q = Math.floor(verba / (b.cpa * c[1])), i, cpaC;
    for (i = 0; i < 25; i++) {
      cpaC = cpaEscalado(b.cpa, b.compras, q, c[1]);
      var novo = Math.floor(verba / cpaC);
      if (novo === q) break;
      q = novo;
    }
    cpaC = cpaEscalado(b.cpa, b.compras, q, c[1]);
    var totalEstimado = fatiaAds < 0.999 ? Math.round(q / fatiaAds) : q;

    html += '<div style="background:var(--sf);border:1px solid var(--bd);border-left:3px solid ' + c[2] + ';border-radius:12px;padding:15px 16px">'
      + '<div style="font-size:11px;color:' + c[2] + ';font-weight:700;text-transform:uppercase;letter-spacing:.04em">' + c[0] + '</div>'
      + '<div style="font-size:26px;font-weight:800;margin:5px 0 3px">+' + fmtInt(q) + ' <span style="font-size:14px;font-weight:600">pelo Ads</span></div>'
      + (b.ticket > 0 ? '<div style="font-size:11.5px;color:var(--mt)">≈ R$ ' + fmtN(q * b.ticket) + ' de faturamento</div>' : '')
      + '<div style="font-size:11.5px;color:var(--mt);margin-top:3px">CPA projetado R$ ' + fmtN(cpaC) + '</div>'
      + (fatiaAds < 0.999 ? '<div style="font-size:11px;color:var(--mt2);margin-top:7px">Com o orgânico na mesma proporção, o total tende a +'
          + fmtInt(totalEstimado) + '</div>' : '')
      + '</div>';
  });
  html += '</div>';
  box.innerHTML = html;
}

// ── COMPARATIVO DE VENDAS ────────────────────────────────────
function vdApi(acao, extra) {
  var corpo = Object.assign({action: acao, event_id: EVENT_ID}, extra || {});
  return fetch('api/event_performance.php?action=' + acao, {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(corpo)
  }).then(function(r){ return r.json(); });
}

/** Aceita 18/09/2026, 18/09, 2026-09-18 */
function vdParseData(txt) {
  txt = (txt || '').trim();
  var m = txt.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (m) return txt;
  m = txt.match(/^(\d{1,2})[\/\-.](\d{1,2})(?:[\/\-.](\d{2,4}))?$/);
  if (!m) return null;
  var d = m[1].padStart(2, '0'), mes = m[2].padStart(2, '0');
  var ano = m[3] || String(new Date().getFullYear());
  if (ano.length === 2) ano = '20' + ano;
  return ano + '-' + mes + '-' + d;
}

async function loadVendas() {
  var tab = document.getElementById('vendasTabela');
  var box = document.getElementById('vendasResumoBox');
  tab.innerHTML = '<div style="padding:24px;text-align:center;color:var(--mt2)">Carregando...</div>';

  try {
    var d = await vdApi('list_daily_sales');
    if (!d.success) { tab.innerHTML = '<div style="padding:20px;color:#f87171">' + (d.error || 'Erro') + '</div>'; return; }

    var t = d.totais;
    if (t.dias > 0) {
      var pct = t.pct_ads !== null ? t.pct_ads : 0;
      box.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">'
        + '<div style="background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:13px 15px">'
        +   '<div style="font-size:10px;color:var(--mt);text-transform:uppercase">Vendas totais</div>'
        +   '<div style="font-size:23px;font-weight:800">' + fmtInt(t.total) + '</div>'
        +   '<div style="font-size:11px;color:var(--mt)">' + t.dias + ' dia(s) lançado(s)</div></div>'
        + '<div style="background:rgba(84,112,255,.07);border:1px solid rgba(84,112,255,.3);border-radius:12px;padding:13px 15px">'
        +   '<div style="font-size:10px;color:var(--mt);text-transform:uppercase">📣 Vindas do Ads</div>'
        +   '<div style="font-size:23px;font-weight:800;color:var(--ac)">' + fmtInt(t.ads) + '</div>'
        +   '<div style="font-size:11px;color:var(--mt)">' + pct.toFixed(1).replace('.', ',') + '% do total</div></div>'
        + '<div style="background:rgba(52,211,153,.07);border:1px solid rgba(52,211,153,.3);border-radius:12px;padding:13px 15px">'
        +   '<div style="font-size:10px;color:var(--mt);text-transform:uppercase">🌱 Orgânicas</div>'
        +   '<div style="font-size:23px;font-weight:800;color:var(--gn,#34d399)">' + fmtInt(t.organico) + '</div>'
        +   '<div style="font-size:11px;color:var(--mt)">' + (100 - pct).toFixed(1).replace('.', ',') + '% do total</div></div>'
        + '</div>'
        + '<div style="margin-top:11px;height:13px;border-radius:7px;overflow:hidden;display:flex;background:var(--s2)">'
        +   '<div style="width:' + pct + '%;background:var(--ac)"></div>'
        +   '<div style="flex:1;background:var(--gn,#34d399)"></div>'
        + '</div>'
        + '<div style="display:flex;justify-content:space-between;font-size:11px;color:var(--mt);margin-top:4px">'
        +   '<span>📣 Ads ' + pct.toFixed(1).replace('.', ',') + '%</span>'
        +   '<span>🌱 Orgânico ' + (100 - pct).toFixed(1).replace('.', ',') + '%</span></div>';
    } else {
      box.innerHTML = '<div style="padding:15px 17px;background:var(--s2);border-radius:11px;font-size:13px;color:var(--mt)">'
        + 'Lance as vendas totais de cada dia para ver quanto veio do Ads e quanto veio orgânico.</div>';
    }

    if (!d.linhas.length) {
      tab.innerHTML = '<div style="padding:26px;text-align:center;color:var(--mt2);font-size:13px">Nenhum dia lançado ainda.</div>';
      return;
    }

    tab.innerHTML = '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12.5px">'
      + '<thead><tr>'
      +   ['Dia','Total','Ads','Orgânico','% Ads','Gasto',''].map(function(h, i){
            return '<th style="text-align:' + (i === 0 || i === 6 ? 'left' : 'right') + ';font-size:10px;color:var(--mt);'
                 + 'text-transform:uppercase;letter-spacing:.05em;padding:7px 9px;border-bottom:1px solid var(--bd)">' + h + '</th>';
          }).join('')
      + '</tr></thead><tbody>'
      + d.linhas.map(function(l){
          var pa = l.pct_ads;
          var cor = pa === null ? 'var(--mt)' : (pa >= 60 ? 'var(--ac)' : (pa >= 30 ? 'var(--yw,#fbbf24)' : 'var(--gn,#34d399)'));
          return '<tr style="' + (!l.lancado ? 'opacity:.55' : '') + '">'
            + '<td style="padding:8px 9px;border-bottom:1px solid var(--bd);white-space:nowrap">'
            +   l.data.split('-').reverse().slice(0,2).join('/')
            +   (!l.lancado ? ' <span style="font-size:10px;color:var(--yw,#fbbf24)">sem lançamento</span>' : '') + '</td>'
            + '<td style="text-align:right;padding:8px 9px;border-bottom:1px solid var(--bd);font-weight:700">'
            +   (l.total === null ? '—' : fmtInt(l.total)) + '</td>'
            + '<td style="text-align:right;padding:8px 9px;border-bottom:1px solid var(--bd);color:var(--ac)">' + fmtInt(l.ads) + '</td>'
            + '<td style="text-align:right;padding:8px 9px;border-bottom:1px solid var(--bd);color:var(--gn,#34d399)">'
            +   (l.organico === null ? '—' : fmtInt(l.organico)) + '</td>'
            + '<td style="text-align:right;padding:8px 9px;border-bottom:1px solid var(--bd);font-weight:700;color:' + cor + '">'
            +   (pa === null ? '—' : pa.toFixed(1).replace('.', ',') + '%') + '</td>'
            + '<td style="text-align:right;padding:8px 9px;border-bottom:1px solid var(--bd);color:var(--mt)">R$' + fmtN(l.gasto) + '</td>'
            + '<td style="padding:8px 9px;border-bottom:1px solid var(--bd)">'
            +   (l.lancado ? '<button onclick="editarVenda(\'' + l.data + '\',' + l.total + ')" style="background:none;border:none;color:var(--mt);cursor:pointer" title="Editar">✏️</button>'
                           + '<button onclick="apagarVenda(\'' + l.data + '\')" style="background:none;border:none;color:var(--mt);cursor:pointer" title="Remover">🗑️</button>'
                           : '<button onclick="editarVenda(\'' + l.data + '\',0)" style="background:none;border:none;color:var(--ac);cursor:pointer;font-size:11px">lançar</button>') + '</td>'
            + '</tr>';
        }).join('')
      + '</tbody></table></div>';
  } catch (e) {
    tab.innerHTML = '<div style="padding:20px;color:#f87171">Erro: ' + e.message + '</div>';
  }
}

function editarVenda(data, total) {
  document.getElementById('vdData').value = data;
  document.getElementById('vdTotal').value = total || '';
  document.getElementById('vdTotal').focus();
}

async function salvarVendaDia() {
  var data = document.getElementById('vdData').value;
  var total = document.getElementById('vdTotal').value;
  if (!data) { alert('Escolha a data.'); return; }
  if (total === '') { alert('Informe as vendas do dia.'); return; }
  var d = await vdApi('save_daily_sales', {data: data, total: parseInt(total)});
  if (!d.success) { alert(d.error || 'Erro ao salvar'); return; }
  document.getElementById('vdTotal').value = '';
  loadVendas();
}

async function salvarVendasMassa() {
  var txt = document.getElementById('vdMassa').value;
  var msg = document.getElementById('vdMassaMsg');
  var itens = [], ruins = [];

  txt.split('\n').forEach(function(linha){
    var L = linha.trim();
    if (!L) return;
    // Pega a data no início (dd/mm/aaaa, dd-mm-aaaa ou aaaa-mm-dd) e o número no fim.
    // Assim o hífen dentro da data não é confundido com o separador.
    var m = L.match(/^\s*(\d{4}-\d{2}-\d{2}|\d{1,2}[\/\-.]\d{1,2}(?:[\/\-.]\d{2,4})?)\s*[-–—:;|,\t]*\s*(\d+)\s*$/);
    if (!m) { ruins.push(L); return; }
    var data = vdParseData(m[1]);
    var qtd  = parseInt(m[2], 10);
    if (!data || isNaN(qtd)) { ruins.push(L); return; }
    itens.push({data: data, total: qtd});
  });

  if (!itens.length) {
    msg.innerHTML = '<span style="color:#f87171">Nenhuma linha reconhecida. Use o formato 18/09/2026 - 60</span>';
    return;
  }

  var d = await vdApi('save_daily_sales', {itens: itens});
  if (!d.success) { msg.innerHTML = '<span style="color:#f87171">' + (d.error || 'Erro') + '</span>'; return; }

  msg.innerHTML = '<span style="color:var(--gn,#34d399)">✓ ' + d.salvos + ' dia(s) importado(s).</span>'
    + (ruins.length ? '<br><span style="color:var(--yw,#fbbf24)">' + ruins.length + ' linha(s) ignorada(s): ' + ruins.slice(0,3).join(' · ') + '</span>' : '')
    + ((d.erros && d.erros.length) ? '<br><span style="color:#f87171">' + d.erros.slice(0,3).join(' · ') + '</span>' : '');
  if (d.salvos) document.getElementById('vdMassa').value = '';
  loadVendas();
}

async function apagarVenda(data) {
  if (!confirm('Remover o lançamento de ' + data.split('-').reverse().join('/') + '?')) return;
  var d = await vdApi('delete_daily_sales', {data: data});
  if (!d.success) { alert(d.error || 'Erro'); return; }
  loadVendas();
}

async function loadEventConversions() {
  renderConvPeriods();
  var funnel = document.getElementById('convFunnel');
  var byCamp = document.getElementById('convByCampaign');
  var daily  = document.getElementById('convDaily');
  var empty  = document.getElementById('convEmpty');
  var scope  = document.getElementById('convScope');
  var hl     = document.getElementById('convHighlights');
  if (hl) hl.innerHTML = '';
  var rc     = document.getElementById('convRecs');
  if (rc) rc.innerHTML = '';
  empty.style.display = 'none';
  funnel.innerHTML = '<div style="color:var(--mt);font-size:13px">Carregando...</div>';
  byCamp.innerHTML = ''; daily.innerHTML = '';

  try {
    var res = await fetch('api/event_performance.php?action=get_event_conversions', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_event_conversions', event_id: EVENT_ID, period: CONV_PERIOD, date_start: CONV_INI, date_end: CONV_FIM})
    });
    var d = await res.json();
    if (!d.success) { funnel.innerHTML=''; empty.textContent = d.error || 'Erro ao carregar'; empty.style.display='block'; return; }
    if (d.no_campaigns) {
      funnel.innerHTML=''; empty.textContent = 'Nenhuma campanha vinculada a este evento. Vincule na aba Campanhas para ver as conversões.';
      empty.style.display='block'; scope.textContent=''; return;
    }
    if (!d.daily || !d.daily.length) {
      funnel.innerHTML=''; empty.textContent = 'Ainda sem conversões sincronizadas para as campanhas deste evento.';
      empty.style.display='block'; return;
    }

    var t = d.totals;
    if (d.ajuste) {
      var av = document.getElementById('convCustomBox');
      if (av) {
        var a = av.querySelector('.aviso-ajuste');
        if (!a) { a = document.createElement('div'); a.className = 'aviso-ajuste';
                  a.style.cssText = 'flex-basis:100%;font-size:11.5px;color:var(--yw,#fbbf24)'; av.appendChild(a); }
        a.textContent = '⚠️ ' + d.ajuste;
      }
    }
    scope.textContent = d.campaign_count + ' campanha' + (d.campaign_count!=1?'s':'') + ' vinculada' + (d.campaign_count!=1?'s':'') +
      ' · ' + d.date_start.split('-').reverse().join('/') + ' a ' + d.date_end.split('-').reverse().join('/');

    var br = function(v){ return Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); };
    var hlHtml = '';
    if (d.best_day) {
      var bd = d.best_day;
      var dd = bd.date.split('-'); var dow = new Date(dd[0],dd[1]-1,dd[2]).toLocaleDateString('pt-BR',{weekday:'long'});
      var detalhe = bd.purchases > 0 ? fmtInt(bd.purchases)+' compras' : (bd.leads > 0 ? fmtInt(bd.leads)+' leads' : 'R$'+br(bd.revenue));
      hlHtml += '<div style="background:rgba(52,211,153,.07);border:1px solid rgba(52,211,153,.3);border-radius:12px;padding:12px 14px">'
        + '<div style="font-size:10px;color:var(--mt);text-transform:uppercase;letter-spacing:.05em">🏆 Melhor dia</div>'
        + '<div style="font-size:17px;font-weight:800;margin-top:2px">'+dd[2]+'/'+dd[1]+' <span style="font-size:12px;font-weight:500;color:var(--mt);text-transform:capitalize">'+dow.split('-')[0]+'</span></div>'
        + '<div style="font-size:12px;color:var(--mt);margin-top:2px">'+detalhe+' · R$'+br(bd.spend)+' investidos'
        +   (bd.revenue > 0 ? ' · ROAS '+(bd.spend>0?(bd.revenue/bd.spend).toFixed(2):0)+'x' : '')+'</div></div>';
    }
    if (d.best_campaign) {
      var bc = d.best_campaign;
      var mtxt = bc.metric === 'roas' ? 'ROAS '+bc.value.toFixed(2)+'x' : 'CPL R$'+br(bc.value);
      var sub = bc.purchases > 0 ? fmtInt(bc.purchases)+' compras' : (bc.leads > 0 ? fmtInt(bc.leads)+' leads' : '');
      hlHtml += '<div style="background:rgba(84,112,255,.07);border:1px solid rgba(84,112,255,.3);border-radius:12px;padding:12px 14px">'
        + '<div style="font-size:10px;color:var(--mt);text-transform:uppercase;letter-spacing:.05em">⭐ Melhor campanha</div>'
        + '<div style="font-size:13px;font-weight:700;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+(bc.name||'').replace(/"/g,'&quot;')+'">'+(bc.name||bc.id)+'</div>'
        + '<div style="font-size:12px;color:var(--mt);margin-top:2px">'+mtxt+(sub?' · '+sub:'')+' · R$'+br(bc.spend)+' investidos</div></div>';
    }
    if (hl) hl.innerHTML = hlHtml;

    // Evento com conta em outra moeda: deixa claro que os valores já vêm convertidos
    var moedas = d.moedas || [];
    var outras = moedas.filter(function(m){ return m && m !== 'BRL'; });
    var avMoeda = document.getElementById('convMoedaAviso');
    if (!avMoeda && outras.length && scope && scope.parentNode) {
      avMoeda = document.createElement('div');
      avMoeda.id = 'convMoedaAviso';
      avMoeda.style.cssText = 'font-size:11px;color:var(--mt);margin-top:3px';
      scope.parentNode.appendChild(avMoeda);
    }
    if (avMoeda) {
      avMoeda.innerHTML = outras.length
        ? '💱 Este evento usa conta em ' + outras.join(', ') + (moedas.indexOf('BRL') >= 0 ? ' e BRL' : '')
          + ' — todos os valores já estão convertidos para reais pela cotação do dia do gasto.'
        : '';
    }

    // Comparativo com as vendas totais lançadas (aba Comparativo de vendas)
    var vt = d.vendas_totais;
    var vtBox = document.getElementById('convVendasTotais');
    if (vtBox) {
      if (vt && vt.total > 0) {
        var p = vt.pct_ads || 0;
        vtBox.innerHTML = '<div style="background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:13px 15px;margin-bottom:14px">'
          + '<div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">'
          +   '<b style="font-size:13.5px">🛒 Vendas no período: ' + fmtInt(vt.total) + '</b>'
          +   '<span style="font-size:11.5px;color:var(--mt)">' + vt.dias + ' dia(s) lançado(s)'
          +     (vt.parcial ? ' · <span style="color:var(--yw,#fbbf24)">⚠️ total menor que as vendas do Ads — confira os lançamentos</span>' : '') + '</span>'
          + '</div>'
          + '<div style="margin-top:9px;height:13px;border-radius:7px;overflow:hidden;display:flex;background:var(--s2)">'
          +   '<div style="width:' + p + '%;background:var(--ac)"></div><div style="flex:1;background:var(--gn,#34d399)"></div></div>'
          + '<div style="display:flex;justify-content:space-between;font-size:12px;margin-top:5px">'
          +   '<span style="color:var(--ac);font-weight:600">📣 Ads: ' + fmtInt(vt.ads) + ' (' + p.toFixed(1).replace('.', ',') + '%)</span>'
          +   '<span style="color:var(--gn,#34d399);font-weight:600">🌱 Orgânico: ' + fmtInt(vt.organico) + ' (' + (100 - p).toFixed(1).replace('.', ',') + '%)</span>'
          + '</div></div>';
      } else { vtBox.innerHTML = ''; }
    }

    // ── O que escalar / manter / pausar ──
    var R = d.recomendacoes || {};
    var grupos = [
      ['escalar',  '🚀 Escalar',  '#34d399', 'rgba(52,211,153,.07)',  'rgba(52,211,153,.3)'],
      ['pausar',   '🛑 Pausar',   '#f87171', 'rgba(248,113,113,.07)', 'rgba(248,113,113,.3)'],
      ['aguardando','⏳ Escaladas recentemente', '#a78bfa', 'rgba(167,139,250,.07)', 'rgba(167,139,250,.3)'],
      ['observar', '👀 Observar', '#fbbf24', 'rgba(251,191,36,.06)',  'rgba(251,191,36,.25)'],
      ['manter',   '✅ Manter',   'var(--mt2)', 'transparent',        'var(--bd)']
    ];
    var temRec = grupos.some(function(g){ return (R[g[0]]||[]).length; });
    if (temRec && rc) {
      var bm = d.benchmark || {};
      var refs = [];
      if (bm.roas > 0) refs.push('ROAS médio ' + Number(bm.roas).toFixed(2) + 'x');
      if (bm.cpa  > 0) refs.push('CPA médio R$' + fmtN(bm.cpa));
      if (bm.cpl  > 0) refs.push('CPL médio R$' + fmtN(bm.cpl));
      if (bm.cpm  > 0) refs.push('CPM médio R$' + fmtN(bm.cpm));
      if (bm.cpe  > 0) refs.push('R$' + fmtN(bm.cpe) + '/engaj.');

      rc.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">'
        + '<b style="font-size:14px">🎯 O que fazer agora</b>'
        + '<span style="font-size:11px;color:var(--mt)">comparado com este evento' + (refs.length ? ' · ' + refs.join(' · ') : '') + '</span>'
        + '</div>'
        + grupos.map(function(g){
            var lista = R[g[0]] || [];
            if (!lista.length) return '';
            return '<div style="background:'+g[3]+';border:1px solid '+g[4]+';border-radius:12px;padding:11px 14px;margin-bottom:8px">'
              + '<div style="font-size:12px;font-weight:800;color:'+g[2]+';margin-bottom:6px">'+g[1]+' <span style="font-weight:500;opacity:.7">('+lista.length+')</span></div>'
              + lista.map(function(c){
                  var acionavel = IS_ADMIN && g[0] === 'pausar';
                  var escalavel = IS_ADMIN && g[0] === 'escalar';
                  return '<div style="display:flex;gap:10px;align-items:flex-start;padding:5px 0;border-top:1px solid var(--bd)">'
                    + (acionavel
                        ? '<input type="checkbox" class="rec-check" data-cid="'+c.campaign_id+'" data-nome="'+(c.campaign_name||'').replace(/"/g,'&quot;')+'" data-motivo="'+(c.motivo||'').replace(/"/g,'&quot;')+'" onchange="atualizaBarraAcoes()" style="width:16px;height:16px;margin-top:2px;accent-color:#f87171;cursor:pointer">'
                        : '')
                    + '<div style="flex:1;min-width:0">'
                    +   '<div style="font-size:12.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+(c.campaign_name||'').replace(/"/g,'&quot;')+'">'+(c.campaign_name||c.campaign_id)+'</div>'
                    +   '<div style="font-size:11px;color:var(--mt);margin-top:2px">'+c.motivo+'</div>'
                    + '</div>'
                    + '<div style="text-align:right;white-space:nowrap">'
                    +   '<div style="font-size:12px;font-weight:700">'+c.metrica+'</div>'
                    +   '<div style="font-size:10.5px;color:var(--mt)">R$'+fmtN(c.spend)+' · '+c.dias+'d</div>'
                    +   (escalavel ? '<button class="btn btn-o btn-sm" style="font-size:10.5px;padding:3px 9px;margin-top:4px" onclick="abrirOrcamento(\''+c.campaign_id+'\',\''+(c.campaign_name||'').replace(/'/g,'')+'\')">💰 aumentar orçamento</button>' : '')
                    + '</div></div>';
                }).join('')
              + '</div>';
          }).join('')
        + (IS_ADMIN && (R.pausar||[]).length
            ? '<div id="recBarra" style="display:none;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 13px;margin-top:4px;'
              + 'background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.3);border-radius:10px">'
              + '<span id="recResumo" style="font-size:12.5px;font-weight:600"></span>'
              + '<span style="flex:1"></span>'
              + '<button class="btn btn-o btn-sm" onclick="marcarTodasPausar()" style="font-size:11.5px;padding:4px 10px">marcar todas</button>'
              + '<button class="btn btn-d btn-sm" onclick="aplicarPausas()" style="font-size:11.5px;padding:5px 13px">⏸️ Pausar selecionadas na Meta</button>'
              + '</div>'
            : '')
        + '<div id="recHistorico"></div>'
        + '<div style="font-size:10.5px;color:var(--mt);margin-top:6px">Sugestões automáticas com base neste evento — confira antes de aplicar na Meta. Campanhas paradas ou com menos de R$50 gastos não entram.</div>';
      carregarHistoricoAcoes();
    }

    function box(label, val, sub, color) {
      return '<div style="background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:11px 13px">'
        + '<div style="font-size:10px;color:var(--mt);text-transform:uppercase;letter-spacing:.05em">'+label+'</div>'
        + '<div style="font-size:19px;font-weight:800;margin-top:2px'+(color?';color:'+color:'')+'">'+val+'</div>'
        + (sub ? '<div style="font-size:11px;color:var(--mt);margin-top:1px">'+sub+'</div>' : '')
        + '</div>';
    }
    var pct = function(a,b){ return b>0 ? ' ('+(a/b*100).toFixed(1)+'%)' : ''; };
    funnel.innerHTML =
        box('👁 Visualizações', fmtInt(t.views), 'CPV R$'+Number(t.cpv).toFixed(4))
      + box('🛒 Carrinho',      fmtInt(t.add_to_cart), pct(t.add_to_cart, t.views))
      + box('💳 Checkout',      fmtInt(t.checkout),    pct(t.checkout, t.add_to_cart))
      + box('✅ Compras',       fmtInt(t.purchases),   'CPA R$'+fmtN(t.cpa), 'var(--gn, #34d399)')
      + box('🎯 Leads',         fmtInt(t.leads),       'CPL R$'+fmtN(t.cpl))
      + box('💰 Faturamento',   'R$'+fmtN(t.revenue),  'Gasto R$'+fmtN(t.spend))
      + box('📊 ROAS',          Number(t.roas).toFixed(2)+'x', '', t.roas>=3?'var(--gn, #34d399)':(t.roas>=1.5?'':'#f87171'));

    if (d.by_campaign && d.by_campaign.length > 1) {
      byCamp.innerHTML = '<div style="font-size:12px;font-weight:700;margin-bottom:6px">Por campanha</div>'
        + '<div style="overflow:auto"><table style="width:100%;font-size:12px"><thead><tr>'
        + '<th style="text-align:left">Campanha</th><th>Gasto</th><th>Compras</th><th>Leads</th><th>Fat.</th><th>ROAS</th>'
        + '</tr></thead><tbody>'
        + d.by_campaign.map(function(c){
            var r = c.spend>0 ? (c.revenue/c.spend) : 0;
            return '<tr>'
              + '<td style="max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+(c.campaign_name||'').replace(/"/g,'&quot;')+'">'+(c.campaign_name||c.campaign_id)+'</td>'
              + '<td style="text-align:center">R$'+fmtN(c.spend)+'</td>'
              + '<td style="text-align:center">'+fmtInt(c.purchases)+'</td>'
              + '<td style="text-align:center">'+fmtInt(c.leads)+'</td>'
              + '<td style="text-align:center">R$'+fmtN(c.revenue)+'</td>'
              + '<td style="text-align:center;font-weight:700">'+r.toFixed(2)+'x</td>'
              + '</tr>';
          }).join('') + '</tbody></table></div>';
    }

    daily.innerHTML = '<div style="font-size:12px;font-weight:700;margin:14px 0 6px">Dia a dia</div>'
      + '<div style="overflow:auto;max-height:420px"><table style="width:100%;font-size:12px"><thead><tr>'
      + '<th style="text-align:left">Data</th><th>Views</th><th>Carrinho</th><th>Checkout</th><th>Compras</th><th>Leads</th><th>Gasto</th><th>Fat.</th><th>ROAS</th>'
      + '</tr></thead><tbody>'
      + d.daily.map(function(x){
          var r = x.spend>0 ? (x.revenue/x.spend) : 0;
          return '<tr>'
            + '<td style="white-space:nowrap;color:var(--mt)">'+x.snapshot_date.split('-').reverse().join('/')+'</td>'
            + '<td style="text-align:center">'+fmtInt(x.views)+'</td>'
            + '<td style="text-align:center">'+fmtInt(x.add_to_cart)+'</td>'
            + '<td style="text-align:center">'+fmtInt(x.checkout)+'</td>'
            + '<td style="text-align:center;font-weight:600">'+fmtInt(x.purchases)+'</td>'
            + '<td style="text-align:center">'+fmtInt(x.leads)+'</td>'
            + '<td style="text-align:center">R$'+fmtN(x.spend)+'</td>'
            + '<td style="text-align:center">R$'+fmtN(x.revenue)+'</td>'
            + '<td style="text-align:center;color:'+(r>=3?'var(--gn, #34d399)':(r>=1.5?'inherit':'#f87171'))+'">'+r.toFixed(2)+'x</td>'
            + '</tr>';
        }).join('') + '</tbody></table></div>';
  } catch(e) {
    funnel.innerHTML=''; empty.textContent = 'Erro ao carregar conversões.'; empty.style.display='block';
  }
}

function fmtInt(v){ return Number(v||0).toLocaleString('pt-BR'); }

var CRT_PERIOD = 'tudo', CRT_INI = null, CRT_FIM = null;
var CRT_PERIODS = [['hoje','Hoje'],['ontem','Ontem'],['3','3 dias'],['7','7 dias'],['15','15 dias'],['30','30 dias'],['tudo','Todo o período']];

function setCrtPeriod(p) {
  CRT_PERIOD = p;
  if (p !== 'custom') { CRT_INI = null; CRT_FIM = null; }
  loadCreatives();
}

function abrirCrtCustom() {
  var box = document.getElementById('crtCustomBox');
  var aberto = box.style.display !== 'none';
  box.style.display = aberto ? 'none' : 'flex';
  if (!aberto) {
    var iso = function(d){ return d.toISOString().slice(0,10); };
    if (!document.getElementById('crtIni').value) document.getElementById('crtIni').value = CRT_INI || iso(new Date(Date.now() - 6*86400000));
    if (!document.getElementById('crtFim').value) document.getElementById('crtFim').value = CRT_FIM || iso(new Date());
  }
}

function aplicarCrtCustom() {
  var ini = document.getElementById('crtIni').value;
  var fim = document.getElementById('crtFim').value;
  if (!ini || !fim) { alert('Escolha a data inicial e a final.'); return; }
  if (ini > fim) { var t = ini; ini = fim; fim = t;
    document.getElementById('crtIni').value = ini; document.getElementById('crtFim').value = fim; }
  CRT_INI = ini; CRT_FIM = fim; CRT_PERIOD = 'custom';
  loadCreatives();
}

function renderCrtPeriods() {
  var el = document.getElementById('crtPeriods');
  if (!el) return;
  var custom = CRT_PERIOD === 'custom';
  el.innerHTML = CRT_PERIODS.map(function(p){
    var on = CRT_PERIOD === p[0];
    return '<button onclick="setCrtPeriod(\''+p[0]+'\')" style="padding:5px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;'
      + 'border:1px solid '+(on?'var(--ac)':'var(--bd)')+';background:'+(on?'var(--ac)':'transparent')+';color:'+(on?'#fff':'var(--mt2)')+'">'
      + p[1] + '</button>';
  }).join('')
  + '<button onclick="abrirCrtCustom()" title="Escolher um intervalo" style="padding:5px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;'
  +   'border:1px solid '+(custom?'var(--ac)':'var(--bd)')+';background:'+(custom?'var(--ac)':'transparent')+';color:'+(custom?'#fff':'var(--mt2)')+'">'
  +   '📅 ' + (custom && CRT_INI ? CRT_INI.split('-').reverse().slice(0,2).join('/') + ' – ' + CRT_FIM.split('-').reverse().slice(0,2).join('/') : 'Escolher datas') + '</button>';
  if (custom) document.getElementById('crtCustomBox').style.display = 'flex';
}

async function loadCreatives() {
  renderCrtPeriods();
  var grid  = document.getElementById('creativesGrid');
  var empty = document.getElementById('creativesEmpty');
  if (!grid) return;

  var sort = document.getElementById('creativesSort')?.value || 'purchases';
  grid.innerHTML = '<div style="color:var(--mt);padding:20px">Carregando...</div>';
  empty.style.display = 'none';

  try {
    var res = await fetch('api/event_performance.php?action=get_event_creatives', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_event_creatives', event_id: EVENT_ID, sort: sort, period: CRT_PERIOD, date_start: CRT_INI, date_end: CRT_FIM})
    });
    var d = await res.json();

    var infoEl = document.getElementById('creativesFilterInfo');
    if (!infoEl) {
      infoEl = document.createElement('div');
      infoEl.id = 'creativesFilterInfo';
      infoEl.style.cssText = 'font-size:12px;color:var(--mt);margin-bottom:10px';
      grid.parentNode.insertBefore(infoEl, grid);
    }
    var warnEl = document.getElementById('creativesFallbackWarn');
    if (d.fallback_account) {
      if (!warnEl) {
        warnEl = document.createElement('div');
        warnEl.id = 'creativesFallbackWarn';
        warnEl.style.cssText = 'font-size:12px;color:var(--mt);margin-bottom:12px;padding:8px 12px;background:var(--s2);border-radius:8px';
        warnEl.textContent = '\u26a0\ufe0f Nenhuma campanha vinculada a este evento \u2014 mostrando todos os criativos da conta. Vincule campanhas na aba Campanhas para filtrar.';
        grid.parentNode.insertBefore(warnEl, grid);
      }
      warnEl.style.display = 'block';
    } else if (warnEl) { warnEl.style.display = 'none'; }

    if (!d.success) { grid.innerHTML=''; empty.textContent = d.error || 'Erro ao carregar criativos'; empty.style.display='block'; return; }
    var dateLabel = d.date_start ? d.date_start.split('-').reverse().join('/') + ' a ' + d.date_end.split('-').reverse().join('/') : '';
    if (!d.creatives || !d.creatives.length) {
      grid.innerHTML = '';
      if (!(d.creative_count || 0)) {
        empty.textContent = 'Ainda não há anúncios sincronizados para esta conta. Use “Sincronizar criativos” para buscar os anúncios da Meta.';
      } else {
        empty.textContent = 'Há ' + d.creative_count + ' anúncios sincronizados, mas nenhum teve entrega no período ' + (dateLabel || 'selecionado') + '. Tente um intervalo maior ou “Todo o período”.';
      }
      empty.style.display='block';
      return;
    }
    infoEl.textContent = d.fallback_account
      ? (d.creatives.length + ' criativos da conta inteira (sem filtro)')
      : ('Mostrando ' + d.creatives.length + ' criativos das ' + (d.campaign_count||0) + ' campanhas vinculadas' + (dateLabel ? ' \u2022 per\u00edodo ' + dateLabel : ''));

    var metricLabels = {purchases:'Compras', leads:'Leads', roas:'ROAS', spend:'Gasto', reach:'Alcance', impressions:'Impressões', score:'Score'};

    grid.innerHTML = d.creatives.map(function(c, i){
      var imgHigh = c.image_url || '', imgLow = c.thumbnail_url || '';
      var fullSrc = imgHigh || imgLow || '';
      var thumb;
      if (imgHigh) {
        var fb = imgLow
          ? "this.onerror=function(){this.style.display='none';this.parentElement.querySelector('.no-thumb').style.display='flex'};this.src='"+imgLow.replace(/'/g,"\\'")+"'"
          : "this.style.display='none';this.parentElement.querySelector('.no-thumb').style.display='flex'";
        thumb = '<img src="'+imgHigh+'" onerror="'+fb+'" style="width:100%;height:100%;object-fit:cover" loading="lazy"><div class="no-thumb" style="display:none;align-items:center;justify-content:center;height:100%;color:var(--mt);font-size:24px">🎨</div>';
      } else if (imgLow) {
        thumb = '<img src="'+imgLow+'" onerror="this.style.display=\'none\';this.parentElement.querySelector(\'.no-thumb\').style.display=\'flex\'" style="width:100%;height:100%;object-fit:cover" loading="lazy"><div class="no-thumb" style="display:none;align-items:center;justify-content:center;height:100%;color:var(--mt);font-size:24px">🎨</div>';
      } else {
        thumb = '<div class="no-thumb" style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--mt);font-size:24px">🎨</div>';
      }

      var typeLabel = c.is_dynamic ? '⚡ Dinâmico' : (c.has_assets ? '⚡ Com assets' : (c.ad_type === 'video' ? '▶ Vídeo' : '🖼 Imagem'));
      var metricVal = sort === 'spend' ? 'R$'+fmtN(c.spend)
                    : sort === 'roas' ? parseFloat(c.roas||0).toFixed(2)+'x'
                    : sort === 'score' ? parseFloat(c.score||0).toFixed(1)
                    : Number(c[sort]||0).toLocaleString('pt-BR');
      var safeName = (c.ad_name||'').replace(/"/g,'&quot;').replace(/'/g,"\\'");
      return ''
        +'<div style="background:var(--sf);border:1px solid var(--bd);border-radius:10px;overflow:hidden">'
        +  '<div style="position:relative;aspect-ratio:4/5;background:var(--s2);cursor:zoom-in" onclick="openCreativeLightbox(\''+fullSrc.replace(/'/g,"\\'")+'\',\''+safeName+'\')">'
        +    thumb
        +    '<div style="position:absolute;top:8px;right:8px;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;background:rgba(0,0,0,.6);color:#fff">'+(i+1)+'</div>'
        +    '<div style="position:absolute;top:8px;left:8px;padding:2px 7px;border-radius:5px;font-size:9px;font-weight:700;background:rgba(0,0,0,.6);color:#fff">'+typeLabel+'</div>'
        +  '</div>'
        +  '<div style="padding:10px 12px">'
        +    '<div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="'+safeName+'">'+(c.ad_name||'(sem nome)')+'</div>'
        +    '<div style="font-size:20px;font-weight:800;color:var(--ac);margin-top:4px">'+metricVal+'</div>'
        +    '<div style="font-size:9px;color:var(--mt);text-transform:uppercase;letter-spacing:.5px">'+(metricLabels[sort]||sort)+'</div>'
        +    '<div style="display:flex;gap:10px;margin-top:8px;font-size:11px;color:var(--mt)">'
        +      '<span>👁 '+Number(c.impressions||0).toLocaleString('pt-BR')+'</span>'
        +      '<span>💰 R$'+fmtN(c.spend)+'</span>'
        +    '</div>'
        +    ((c.is_dynamic || c.has_assets) ? '<button class="btn btn-o btn-sm" style="width:100%;margin-top:8px" onclick="openCreativeAssets(\''+String(c.ad_id).replace(/'/g,"")+'\',\''+safeName+'\')">⚡ Ver assets</button>' : '')
        +  '</div>'
        +'</div>';
    }).join('');
  } catch(e) {
    grid.innerHTML = '';
    empty.textContent = 'Erro ao carregar criativos.';
    empty.style.display = 'block';
  }
}

async function openCreativeAssets(adId, adName) {
  var sort = document.getElementById('creativesSort')?.value || 'purchases';
  if (sort === 'score') sort = 'purchases'; // assets não têm score
  var m = document.getElementById('creativeAssetsModal');
  if (!m) {
    m = document.createElement('div');
    m.id = 'creativeAssetsModal';
    m.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);z-index:9998;align-items:flex-start;justify-content:center;overflow:auto;padding:5vh 12px';
    m.onclick = function(e){ if (e.target === m) m.style.display = 'none'; };
    m.innerHTML = '<div style="background:var(--sf);border:1px solid var(--bd2);border-radius:12px;max-width:560px;width:100%;padding:16px;box-shadow:0 18px 50px rgba(0,0,0,.5)" onclick="event.stopPropagation()">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:4px">'
      +   '<div id="caTitle" style="font-weight:700;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></div>'
      +   '<button class="btn btn-o btn-sm" onclick="document.getElementById(\'creativeAssetsModal\').style.display=\'none\'">✕</button>'
      + '</div>'
      + '<div id="caSub" style="font-size:11px;color:var(--mt);margin-bottom:12px"></div>'
      + '<div id="caList"></div>'
      + '</div>';
    document.body.appendChild(m);
  }
  var labels = {purchases:'Compras', leads:'Leads', roas:'ROAS', spend:'Gasto', reach:'Alcance', impressions:'Impressões'};
  document.getElementById('caTitle').textContent = '⚡ Assets — ' + (adName || adId);
  document.getElementById('caSub').textContent = 'Do melhor pro pior por: ' + (labels[sort] || sort);
  document.getElementById('caList').innerHTML = '<div style="color:var(--mt);font-size:13px;padding:20px;text-align:center">Carregando...</div>';
  m.style.display = 'flex';

  try {
    var res = await fetch('api/event_performance.php?action=get_creative_assets', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'get_creative_assets', event_id: EVENT_ID, ad_id: adId, sort: sort})
    });
    var d = await res.json();
    if (!d.success) { document.getElementById('caList').innerHTML = '<div style="color:var(--mt);font-size:13px;padding:20px;text-align:center">'+(d.error||'Erro ao carregar assets')+'</div>'; return; }
    if (!d.assets || !d.assets.length) {
      document.getElementById('caList').innerHTML =
        '<div style="color:var(--mt);font-size:13px;padding:22px;text-align:center;line-height:1.6">'
        + 'Nenhum asset retornado para este anúncio.<br>'
        + '<span style="font-size:11.5px;color:var(--mt2)">A Meta só devolve desempenho por asset em anúncios com '
        + 'criativo dinâmico (Advantage+ / múltiplas mídias) e apenas dos últimos 90 dias. '
        + 'Se este for dinâmico e ainda assim vier vazio, rode "Sincronizar criativos" e confira o aviso ao final.</span>'
        + '</div>';
      return;
    }

    document.getElementById('caList').innerHTML = d.assets.map(function(a, i){
      var mainVal = sort === 'spend' ? 'R$'+fmtN(a.spend)
                  : sort === 'roas' ? parseFloat(a.roas||0).toFixed(2)+'x'
                  : Number(a[sort]||0).toLocaleString('pt-BR');
      var th = a.thumbnail_url
        ? '<img src="'+a.thumbnail_url+'" style="width:52px;height:52px;object-fit:cover;border-radius:8px;cursor:zoom-in" loading="lazy" onerror="this.style.visibility=\'hidden\'" onclick="openCreativeLightbox(\''+String(a.thumbnail_url).replace(/'/g,"")+'\',\''+String(a.asset_name||'').replace(/["\\']/g,'')+'\')">'
        : '<div style="width:52px;height:52px;border-radius:8px;background:var(--s2);display:flex;align-items:center;justify-content:center">'+(a.asset_type==='video'?'▶':'🖼')+'</div>';
      return '<div style="display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid var(--bd)">'
        + '<div style="width:22px;text-align:center;font-weight:800;font-size:13px;color:'+(i===0?'var(--ac)':'var(--mt)')+'">'+(i+1)+'</div>'
        + th
        + '<div style="flex:1;min-width:0">'
        +   '<div style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+(a.asset_type==='video'?'▶ ':'🖼 ')+(a.asset_name||a.asset_id)+'</div>'
        +   '<div style="font-size:11px;color:var(--mt);margin-top:2px">🛒 '+Number(a.purchases||0).toLocaleString('pt-BR')+' · 🎯 '+Number(a.leads||0).toLocaleString('pt-BR')+' · 💰 R$'+fmtN(a.spend)+' · ROAS '+parseFloat(a.roas||0).toFixed(2)+'x</div>'
        + '</div>'
        + '<div style="text-align:right"><div style="font-size:15px;font-weight:800;color:var(--ac)">'+mainVal+'</div><div style="font-size:9px;color:var(--mt);text-transform:uppercase">'+(labels[sort]||sort)+'</div></div>'
        + '</div>';
    }).join('');
  } catch(e) {
    document.getElementById('caList').innerHTML = '<div style="color:var(--mt);font-size:13px;padding:20px;text-align:center">Erro ao carregar assets.</div>';
  }
}

function openCreativeLightbox(url, title) {
  if (!url) return;
  var lb = document.getElementById('creativeLightbox');
  if (!lb) {
    lb = document.createElement('div');
    lb.id = 'creativeLightbox';
    lb.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:12px;cursor:zoom-out';
    lb.onclick = function(){ lb.style.display = 'none'; };
    lb.innerHTML = '<img id="creativeLbImg" style="max-width:92vw;max-height:82vh;border-radius:8px;object-fit:contain">'
      + '<div id="creativeLbTitle" style="color:#ededf5;font-size:13px;text-align:center;opacity:.7"></div>'
      + '<div style="color:#8a8aa0;font-size:11px">Clique para fechar</div>';
    document.body.appendChild(lb);
  }
  document.getElementById('creativeLbImg').src = url;
  document.getElementById('creativeLbTitle').textContent = title || '';
  lb.style.display = 'flex';
}

// ── SYNC DE CRIATIVOS (campanhas vinculadas ao evento, admin) ───
var PROJ_MOEDA = 'BRL', PROJ_TAXA = 1;
var syncEventCreativesRunning = false;
async function syncCallApi(url, body) {
  var r = await fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
  var text = await r.text();
  try { return JSON.parse(text); } catch(e) {
    if (r.status === 502 || r.status === 503 || r.status === 504) {
      return {success:false, http_status:r.status, message:'Servidor temporariamente ocupado (HTTP '+r.status+'). A página vai tentar novamente.'};
    }
    throw new Error('Resposta inválida do servidor (HTTP '+r.status+').');
  }
}

async function syncEventCreatives() {
  if (syncEventCreativesRunning) return;
  var statusEl = document.getElementById('syncEventCreativesStatus');
  var btn = document.getElementById('btnSyncEventCreatives');
  syncEventCreativesRunning = true;
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Sincronizando...'; }
  if (statusEl) statusEl.style.display = 'block';
  function setStatus(msg) { if (statusEl) statusEl.textContent = msg; }

  var totAds = 0, totDyn = 0, totSnap = 0, falhas = [], totAssetsMsg = '';
  try {
    var scope = await syncCallApi('api/sync_creatives.php', {action:'get_event_scope', event_id:EVENT_ID});
    if (!scope.success) throw new Error(scope.message || 'Não foi possível carregar as campanhas vinculadas.');
    var campaigns = scope.campaigns || [];
    if (!campaigns.length) throw new Error('Vincule campanhas ao evento antes de sincronizar os criativos.');

    for (var ci = 0; ci < campaigns.length; ci++) {
      var campaign = campaigns[ci];
      var accountId = campaign.account_id;
      var campaignId = campaign.campaign_id;
      var pref = '[' + (ci+1) + '/' + campaigns.length + '] ' + (campaign.campaign_name || campaignId) + ' · ';
      setStatus('⏳ ' + pref + 'baixando anúncios desta campanha...');

      // Fase 1: anúncios (paginado)
      var adsCursor = null, adsTotal = 0, dynTotal = 0, adsRetries = 0, abortou = false;
      do {
        var body = {action:'sync_one', phase:'ads', event_id:EVENT_ID, campaign_id:campaignId, account_id: accountId};
        if (adsCursor) body.cursor = adsCursor;
        var r = await syncCallApi('api/sync_creatives.php', body);
        if (!r.success) {
          adsRetries++;
          if (adsRetries <= 3) { setStatus('⏳ ' + pref + 'anúncios: falhou, tentando de novo ('+adsRetries+'/3)...'); await new Promise(function(res){setTimeout(res,2000*adsRetries);}); continue; }
          falhas.push((campaign.campaign_name || campaignId) + ' — anúncios: ' + (r.message || 'erro sem detalhe'));
          setStatus('❌ ' + pref + 'falhou ao buscar anúncios: '+(r.message||'erro')); abortou = true; break;
        }
        adsRetries = 0;
        adsTotal += (r.saved||0); dynTotal += (r.dynamic||0);
        adsCursor = r.has_more ? r.next_cursor : null;
        setStatus('⏳ ' + pref + 'anúncios desta campanha: '+adsTotal+' até agora...');
        await new Promise(function(res){setTimeout(res,300);});
      } while (adsCursor);
      if (abortou) continue;

      // Fase 2: snapshots diários (paginado)
      var snapCursor = null, snapTotal = 0, snapRetries = 0, snapFailed = false;
      do {
        var body2 = {action:'sync_one', phase:'snapshots', event_id:EVENT_ID, campaign_id:campaignId, account_id: accountId};
        if (snapCursor) body2.cursor = snapCursor;
        var r2 = await syncCallApi('api/sync_creatives.php', body2);
        if (!r2.success) {
          snapRetries++;
          if (snapRetries <= 3) { setStatus('⏳ ' + pref + 'snapshots: falhou, tentando de novo ('+snapRetries+'/3)...'); await new Promise(function(res){setTimeout(res,2000*snapRetries);}); continue; }
          var snapError = r2.message || 'erro sem detalhe';
          if (r2.requested_since || r2.requested_until) snapError += ' (período enviado: ' + (r2.requested_since || '?') + ' a ' + (r2.requested_until || '?') + ')';
          falhas.push((campaign.campaign_name || campaignId) + ' — snapshots: ' + snapError);
          setStatus('❌ ' + pref + 'falhou nos snapshots: '+(r2.message||'erro')); snapFailed = true; break;
        }
        snapRetries = 0;
        snapTotal += (r2.saved||0);
        snapCursor = r2.has_more ? r2.next_cursor : null;
        setStatus('⏳ ' + pref + 'snapshots: '+snapTotal+' até agora...');
        await new Promise(function(res){setTimeout(res,300);});
      } while (snapCursor);
      totAds += adsTotal; totDyn += dynTotal; totSnap += snapTotal;
      if (snapFailed) continue;
    }

    // Assets são complementares. Busca apenas os anúncios das campanhas do evento.
    try {
      var dinR = await syncCallApi('api/sync_creatives.php', {action:'list_dynamic', event_id:EVENT_ID});
      var dins = (dinR && dinR.ads) ? dinR.ads : [];
      var assetsOk = 0, ultimoMotivo = '';
      for (var di = 0; di < dins.length; di++) {
        var ad = dins[di];
        setStatus('⏳ assets dos anúncios deste evento: ' + (di + 1) + '/' + dins.length
          + (assetsOk ? ' · ' + assetsOk + ' com assets' : '') + '...');
        var assetScope = {event_id:EVENT_ID, campaign_id:ad.campaign_id, account_id:ad.account_id, ad_id:ad.ad_id};
        var ri = await syncCallApi('api/sync_creatives.php', Object.assign({action:'sync_one', phase:'assets', breakdown:'image_asset'}, assetScope));
        var rv = await syncCallApi('api/sync_creatives.php', Object.assign({action:'sync_one', phase:'assets', breakdown:'video_asset'}, assetScope));
        if (((ri && ri.saved) || 0) + ((rv && rv.saved) || 0) > 0) assetsOk++;
        else if (ri && ri.message) ultimoMotivo = ri.message;
        await new Promise(function(res){setTimeout(res,250);});
      }
      if (dins.length && !assetsOk) totAssetsMsg = ' · nenhum asset retornado pela Meta' + (ultimoMotivo ? ' (' + ultimoMotivo + ')' : '');
      else if (assetsOk) totAssetsMsg = ' · assets retornados para ' + assetsOk + ' anúncio(s)';
    } catch (e) { /* assets são complementares; não interrompe o sync */ }

    var detalheFalhas = falhas.length ? ' · Falhas: ' + falhas.slice(0, 5).join(' | ') + (falhas.length > 5 ? ' | +' + (falhas.length - 5) + ' outras' : '') : '';
    setStatus((falhas.length ? '⚠️ ' : '✅ ') + 'Concluído para ' + campaigns.length + ' campanhas do evento — '
      + totAds + ' anúncios (' + totDyn + ' dinâmicos), ' + totSnap + ' snapshots'
      + totAssetsMsg + (falhas.length ? ' · ' + falhas.length + ' com erro' + detalheFalhas : '') + '.');
    loadCreatives();
  } catch(e) {
    setStatus('❌ Erro: '+e.message);
  } finally {
    syncEventCreativesRunning = false;
    if (btn) { btn.disabled = false; btn.textContent = '🔄 Sincronizar criativos'; }
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

    // Badge 💱 da campanha — usa a moeda/cotação DELA, não a do evento.
    function badgeMoeda(c) {
      var m = c.currency || 'BRL';
      var t = parseFloat(c.fx_rate || 1) || 1;
      if (m === 'BRL' || t <= 0 || !c.budget_meta) return '';
      return '<span style="color:var(--mt2);font-size:9px;cursor:help" title="Na Meta: '
           + m + ' ' + fmtN(c.budget_meta / t) + ' (cotação ' + fmtN(t, 4) + ')">💱</span>';
    }

    // moeda da conta do evento (orçamento da Meta já vem convertido pela API)
    PROJ_MOEDA = d.currency || 'BRL';
    PROJ_TAXA  = parseFloat(d.fx_rate || 1) || 1;

    var willOverflow = d.will_overflow;
    var color  = willOverflow ? 'var(--rd)' : (d.margin < d.budget * 0.1 ? 'var(--yw)' : 'var(--gn)');
    var icon   = willOverflow ? '🔴' : (d.margin < d.budget * 0.1 ? '🟡' : '🟢');
    // Um evento pode ter contas em moedas diferentes: cada campanha é
    // convertida pela taxa da conta dela, não por uma taxa única do evento.
    var moedasEv = (d.moedas || []).filter(function (m) { return m && m !== 'BRL'; });
    var avisoMoeda = moedasEv.length
      ? '<div style="font-size:11px;color:var(--mt);margin-top:6px;text-align:center">💱 '
        + (moedasEv.length > 1
            ? 'Contas em ' + moedasEv.join(' e ') + ' + BRL'
            : 'Conta em ' + moedasEv[0])
        + ' — cada campanha é convertida para reais pela cotação da conta dela</div>'
      : '';
    var status = willOverflow
      ? 'Vai estourar em <strong style="color:var(--rd)">R$'+fmtN(d.overflow_by)+'</strong>'
      : 'Margem de <strong style="color:var(--gn)">R$'+fmtN(d.margin)+'</strong>';

    var html = '<div style="border-top:1px solid var(--bd);padding-top:12px;margin-top:4px">';
    html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px">';
    html += '<div style="font-family:Inter,sans-serif;font-size:12px;font-weight:700;color:var(--mt2);text-transform:uppercase;letter-spacing:.8px">📊 Projeção de Gasto</div>';
    if (IS_ADMIN) html += '<button id="syncEventCampaignSpendBtn" type="button" class="btn btn-o btn-sm" onclick="syncEventCampaignSpend()">🔄 Sincronizar gastos</button>';
    html += '</div>';
    if (d.stale) html += '<div style="font-size:11px;color:var(--yw);margin-bottom:8px">⚠️ Dados do orçamento atualizados há '+parseInt(d.idade_min||0,10)+' min. O painel está mostrando o último cálculo disponível.</div>';
    html += '<div id="syncEventCampaignSpendStatus" style="font-size:11px;color:var(--mt2);margin-bottom:8px" role="status" aria-live="polite"></div>';
    html += avisoMoeda;

    // Resumo
    html += '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px">';
    html += '<div><div style="font-size:10px;color:var(--mt2);text-transform:uppercase;letter-spacing:.6px">Gasto real</div><div style="font-family:Inter,sans-serif;font-weight:800;font-size:16px;color:var(--rd)">R$'+fmtN(d.spent_all)+'</div></div>';
    html += '<div><div style="font-size:10px;color:var(--mt2);text-transform:uppercase;letter-spacing:.6px">Projeção total</div><div style="font-family:Inter,sans-serif;font-weight:800;font-size:16px;color:'+color+'">R$'+fmtN(d.projected_all)+'</div></div>';
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
    html += '</tr></thead><tbody>';

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
          : '<span style="background:rgba(255,255,255,.06);color:var(--mt2);padding:2px 6px;border-radius:4px;font-size:9px" title="'+(c.status==='ACTIVE'?'A campanha está ligada, mas não está entregando (conjuntos pausados ou data encerrada)':'')+'">● '+(c.status_label||'PAUSADA')+'</span>';
        var budgetCell = c.budget_meta
          ? 'R$'+fmtN(c.budget_meta)
        + (badgeMoeda(c))
        + ' <span style="color:var(--mt2);font-size:9px">('+c.budget_type+')</span>'
          : (isActive ? '<span style="color:var(--yw)" title="'+(c.no_budget_reason||'Sem orçamento retornado pela Meta')+'">⚠️ sem budget</span>' : '—');
        var projColor = isActive ? 'var(--ac)' : 'var(--mt2)';
        return '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">'
          +'<td style="padding:7px 8px;max-width:200px;word-break:break-word">'+htmlEsc(c.campaign_name)+'</td>'
          +'<td style="padding:7px 8px;text-align:center">'+statusBadge+'</td>'
          +'<td style="padding:7px 8px;text-align:right">'+budgetCell+'</td>'
          +'<td style="padding:7px 8px;text-align:right;color:var(--rd)">R$'+fmtN(c.spent_real)+'</td>'
          +'<td style="padding:7px 8px;text-align:right;font-family:Inter,sans-serif;font-weight:700;color:'+projColor+'">R$'+fmtN(c.projected)+'</td>'
          +'</tr>';
      }).join('');
    }

    html += '<tbody id="projTbody">' + buildRows(allCampaigns.slice(0, INITIAL_SHOW)) + '</tbody></table></div>';

    if (allCampaigns.length > INITIAL_SHOW) {
      html += '<div id="projShowMore" style="text-align:center;margin-top:10px">'
        +'<button onclick="expandProjTable()" style="background:rgba(79,110,247,.1);border:1px solid rgba(79,110,247,.25);border-radius:8px;color:var(--ac);padding:7px 20px;font-size:12px;font-family:Inter,sans-serif;font-weight:700;cursor:pointer">'
        +'▼ Ver todas as '+allCampaigns.length+' campanhas'
        +'</button></div>';
    }

    html += '</div>';

    el.innerHTML = html;
    el._allCampaigns = allCampaigns;

  } catch(e) {
    var el2 = document.getElementById('budgetProjection');
    if (el2) el2.innerHTML = '';
  }
}

async function syncEventCampaignSpend() {
  var btn = document.getElementById('syncEventCampaignSpendBtn');
  var status = document.getElementById('syncEventCampaignSpendStatus');
  if (!btn || btn.disabled) return;
  btn.disabled = true;
  btn.textContent = '⏳ Sincronizando...';
  if (status) status.textContent = 'Colocando a sincronização na fila...';

  try {
    var response = await fetch('api/sync_event_campaigns.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({event_id: EVENT_ID})
    });
    var data = await response.json();
    if (!response.ok || !data.success || !data.run_id) {
      throw new Error(data.message || 'Não foi possível iniciar a sincronização.');
    }

    var runId = data.run_id;
    var finalRun = null;
    while (!finalRun) {
      var poll = await fetch('api/sync_event_campaigns.php?run_id=' + encodeURIComponent(runId), {cache:'no-store'});
      var progress = await poll.json();
      if (!poll.ok || !progress.success) throw new Error(progress.message || 'Não foi possível consultar o progresso.');

      status = document.getElementById('syncEventCampaignSpendStatus');
      if (status) {
        var progressText = progress.message || 'Sincronização na fila.';
        if (progress.status === 'running' && progress.progress_total > 0) {
          progressText = '⏳ ' + progressText + ' (' + progress.progress_done + '/' + progress.progress_total + ')';
        } else if (progress.status === 'queued') {
          progressText = '⏳ ' + progressText + ' O worker iniciará em instantes.';
        }
        status.textContent = progressText;
      }

      if (['succeeded','partial','failed'].indexOf(progress.status) !== -1) finalRun = progress;
      else await new Promise(function(resolve){ setTimeout(resolve, 2500); });
    }

    perfLoaded = false;
    await loadPerformance();
    status = document.getElementById('syncEventCampaignSpendStatus');
    if (status) {
      var failures = (finalRun.details || []).filter(function(item){ return item.status === 'failed'; });
      var detailText = failures.slice(0, 8).map(function(item){ return item.campaign_name + ': ' + (item.error || 'falha sem detalhe'); }).join('\n');
      status.style.whiteSpace = 'pre-wrap';
      status.textContent = (finalRun.status === 'succeeded' ? '✅ ' : '⚠️ ')
        + (finalRun.message || 'Sincronização finalizada.')
        + (detailText ? '\nCampanhas com erro:\n' + detailText : '');
    }
  } catch (e) {
    status = document.getElementById('syncEventCampaignSpendStatus');
    if (status) status.textContent = '❌ ' + e.message;
  } finally {
    btn = document.getElementById('syncEventCampaignSpendBtn');
    if (btn) { btn.disabled = false; btn.textContent = '🔄 Sincronizar gastos'; }
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
      : '<span style="background:rgba(255,255,255,.06);color:var(--mt2);padding:2px 6px;border-radius:4px;font-size:9px" title="'+(c.status==='ACTIVE'?'Ligada na Meta, mas sem entrega (conjuntos pausados ou data encerrada)':'')+'">● '+(c.status_label||'PAUSADA')+'</span>';
    var budgetCell = c.budget_meta
      ? 'R$'+fmtN(c.budget_meta)
        + (badgeMoeda(c))
        + ' <span style="color:var(--mt2);font-size:9px">('+c.budget_type+')</span>'
      : (isActive ? '<span style="color:var(--yw)">⚠️ sem budget</span>' : '—');
    var projColor = isActive ? 'var(--ac)' : 'var(--mt2)';
    return '<tr style="border-bottom:1px solid rgba(255,255,255,.03)">'
      +'<td style="padding:7px 8px;max-width:200px;word-break:break-word">'+htmlEsc(c.campaign_name)+'</td>'
      +'<td style="padding:7px 8px;text-align:center">'+statusBadge+'</td>'
      +'<td style="padding:7px 8px;text-align:right">'+budgetCell+'</td>'
      +'<td style="padding:7px 8px;text-align:right;color:var(--rd)">R$'+fmtN(c.spent_real)+'</td>'
      +'<td style="padding:7px 8px;text-align:right;font-family:Inter,sans-serif;font-weight:700;color:'+projColor+'">R$'+fmtN(c.projected)+'</td>'
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
  var bc = document.getElementById('rsBtnCustom');
  if (bc && p !== 'custom') { bc.textContent = '📅 Escolher datas'; bc.classList.remove('active'); RS_INI = null; RS_FIM = null; }

  resumoPeriod = p;
  document.querySelectorAll('.rs-pbtn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
}

var RS_INI = null, RS_FIM = null;   // intervalo personalizado do resumo

function abrirResumoCustom(btn) {
  var box = document.getElementById('resumoCustomBox');
  var aberto = box.style.display !== 'none';
  box.style.display = aberto ? 'none' : 'flex';
  if (!aberto) {
    var iso = function(d){ return d.toISOString().slice(0,10); };
    if (!document.getElementById('rsIni').value)
      document.getElementById('rsIni').value = RS_INI || iso(new Date(Date.now() - 6 * 86400000));
    if (!document.getElementById('rsFim').value)
      document.getElementById('rsFim').value = RS_FIM || iso(new Date());
  }
}

function aplicarResumoCustom() {
  var ini = document.getElementById('rsIni').value;
  var fim = document.getElementById('rsFim').value;
  if (!ini || !fim) { alert('Escolha a data inicial e a final.'); return; }
  if (ini > fim) { var t = ini; ini = fim; fim = t;
    document.getElementById('rsIni').value = ini;
    document.getElementById('rsFim').value = fim; }
  RS_INI = ini; RS_FIM = fim;

  document.querySelectorAll('#resumoPeriodBtns .rs-pbtn').forEach(function(b){ b.classList.remove('active'); });
  var bc = document.getElementById('rsBtnCustom');
  bc.classList.add('active');
  bc.textContent = '📅 ' + ini.split('-').reverse().slice(0,2).join('/') + ' – ' + fim.split('-').reverse().slice(0,2).join('/');
  resumoPeriod = 'custom';
  gerarResumoEvento();
}

function getResumoDateRange(period) {
  var today = new Date();
  today.setHours(0,0,0,0);
  var fmt = function(d){ return d.toISOString().split('T')[0]; };

  if (period === 'custom' && RS_INI && RS_FIM) {
    var l = function(iso){ return iso.split('-').reverse().join('/'); };
    return { start: RS_INI, end: RS_FIM, label: l(RS_INI) + ' a ' + l(RS_FIM) };
  }
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

function usarTodoPeriodo() {
  var btns = document.querySelectorAll('.rs-pbtn');
  for (var i = 0; i < btns.length; i++) {
    if (btns[i].textContent.trim().toLowerCase().indexOf('todo') === 0) {
      setResumoPeriod('all', btns[i]);
      gerarResumoEvento();
      return;
    }
  }
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

    // Comparativo Ads x orgânico, quando há vendas totais lançadas
    var vt = d.vendas_totais;
    if (vt && vt.total > 0) {
      var p = vt.pct_ads || 0;
      var t3 = eventName + '\n';
      t3 += 'Período: ' + range.label + '\n\n';
      t3 += 'Vendas totais: ' + vt.total + '\n';
      t3 += 'Vindas do Ads: ' + vt.ads + ' (' + p.toFixed(1).replace('.', ',') + '%)\n';
      t3 += 'Orgânicas: ' + vt.organico + ' (' + (100 - p).toFixed(1).replace('.', ',') + '%)\n';
      t3 += '(' + vt.dias + ' dia(s) lançado(s))';
      blocks.push({title:'🛒 Total x Ads', text:t3});
    }

    var resultCard = document.getElementById('resumoResultCard');
    var title = document.getElementById('resumoResultTitle');
    var container = document.getElementById('resumoBlocksContainer');

    if (!blocks.length) {
      var motivo = d.motivo || 'Nenhum dado encontrado para este período.';
      container.innerHTML = '<div style="text-align:center;padding:30px 22px;color:var(--mt2);font-size:13.5px;line-height:1.55">'
        + '📭 ' + motivo
        + '<div style="margin-top:12px"><button class="btn btn-o btn-sm" onclick="usarTodoPeriodo()" style="font-size:12px">Ver todo o período</button></div>'
        + '</div>';
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
      var isActive = (c.is_active === true || (c.is_active === undefined && c.status === 'ACTIVE'));
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
  var accIds = (d.event && d.event.account_ids && d.event.account_ids.length)
    ? d.event.account_ids
    : (d.event && d.event.account_id ? [d.event.account_id] : []);

  if (!accIds.length) {
    alert('Configure as contas de anúncios do evento em Configurações primeiro.');
    return;
  }

  // Busca campanhas de TODAS as contas do evento
  var campR = await fetch('api/event_performance.php?action=list_account_campaigns', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'list_account_campaigns', event_id: EVENT_ID})
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
  modal.innerHTML = '<div style="background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:24px;width:100%;max-width:560px;max-height:85vh;overflow-y:auto">'+
    '<div style="font-family:Inter,sans-serif;font-size:16px;font-weight:700;color:var(--tx);margin-bottom:4px">📣 Vincular Campanhas</div>'+
    '<div style="font-size:11px;color:var(--mt);margin-bottom:12px">'+allCamps.length+' campanha(s) em '+(campD.accounts||1)+' conta(s) do evento'+
      ((campD.erros && campD.erros.length) ? ' <span style="color:#f87171">· erro em: '+esc(campD.erros.join(' | '))+'</span>' : '')+'</div>'+
    '<input type="text" placeholder="Buscar..." oninput="filterModalCamps(this)" style="width:100%;padding:8px 12px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-family:Inter,sans-serif;font-size:13px;outline:none;margin-bottom:10px">'+
    '<div id="modalCampList" style="max-height:360px;overflow-y:auto;display:flex;flex-direction:column;gap:6px">'+
      allCamps.map(function(camp) {
        var isSel = selected.indexOf(camp.id) >= 0;
        return '<label style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--s2);border:1px solid '+(isSel?'rgba(84,112,255,.4)':'var(--bd)')+';border-radius:8px;cursor:pointer;transition:border-color .15s">'+
          '<input type="checkbox" value="'+esc(camp.id)+'" '+(isSel?'checked':'')+' data-name="'+esc(camp.name)+'" data-obj="'+esc(camp.objective||'')+'" data-status="'+esc(camp.status||'')+'" style="width:15px;height:15px;accent-color:var(--ac);cursor:pointer;flex-shrink:0">'+
          '<div><div style="font-size:13px;font-weight:500">'+esc(camp.name)+'</div>'+
          '<div style="font-size:11px;color:var(--mt2);margin-top:2px">'+(camp.objective||'').replace('OUTCOME_','').replace(/_/g,' ')+' · '+esc(camp.status)+
            (campD.multi && camp.account_name ? ' · <span style="color:var(--ac)">'+esc(camp.account_name)+'</span>' : '')+'</div></div>'+
        '</label>';
      }).join('')+
    '</div>'+
    '<div style="display:flex;gap:10px;margin-top:16px;justify-content:flex-end">'+
      '<button onclick="document.getElementById(\'campLinkModal\').remove()" style="padding:9px 16px;border-radius:8px;border:1px solid var(--bd);background:var(--s2);color:var(--mt2);cursor:pointer;font-family:Inter,sans-serif;font-weight:600">Cancelar</button>'+
      '<button onclick="saveLinkedCampaigns(this)" style="padding:9px 16px;border-radius:8px;background:var(--ac);border:none;color:#fff;cursor:pointer;font-family:Inter,sans-serif;font-weight:700">✅ Salvar</button>'+
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

function gerarRelatorioFinanceiro() {
  var url = 'api/generate_financial_report.php?event_id=' + EVENT_ID + '&autoprint=1';
  window.open(url, '_blank');
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
  // Reembolso do cliente (cobranças da Meta × o que já voltou)
  (async function(){
    try {
      var res = await fetch('api/event_financial.php?action=get_refund_summary', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'get_refund_summary', event_id: EVENT_ID})
      });
      var r = await res.json();
      var box = document.getElementById('refundBox');
      if (!box || !r.success || !r.cobrancas) { if (box) box.innerHTML=''; return; }
      var brl = function(v){ return 'R$ ' + Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); };
      var quit = r.aberto <= 0.01;
      box.innerHTML = '<div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:16px 18px;margin-bottom:16px">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">'
        +   '<b style="font-size:14px">💸 Reembolso do cliente</b>'
        +   '<a href="refunds.php?event='+EVENT_ID+'" style="font-size:12px;color:var(--ac);text-decoration:none">gerenciar →</a>'
        + '</div>'
        + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:10px">'
        +   '<div><div style="font-size:10px;color:var(--mt);text-transform:uppercase">Cobrado pela Meta</div><div style="font-size:17px;font-weight:800">'+brl(r.bruto)+'</div></div>'
        +   '<div><div style="font-size:10px;color:var(--mt);text-transform:uppercase">Já reembolsado</div><div style="font-size:17px;font-weight:800;color:var(--gn,#34d399)">'+brl(r.pago)+'</div></div>'
        +   '<div><div style="font-size:10px;color:var(--mt);text-transform:uppercase">Em aberto</div><div style="font-size:17px;font-weight:800;color:'+(quit?'var(--gn,#34d399)':'#f87171')+'">'+(quit?'quitado':brl(r.aberto))+'</div></div>'
        + '</div>'
        + '<div style="height:7px;background:var(--s2);border-radius:4px;overflow:hidden"><div style="height:100%;width:'+r.pct+'%;background:var(--gn,#34d399)"></div></div>'
        + '<div style="font-size:11px;color:var(--mt);margin-top:5px">'+r.pct+'% reembolsado · '+r.cobrancas+' cobrança(s) da Meta neste evento</div>'
        + '</div>';
    } catch(e) {}
  })();

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

  // ── Botão PDF ──
  html += '<div style="display:flex;justify-content:flex-end;margin-bottom:16px">'
    +'<button onclick="gerarRelatorioFinanceiro()" class="btn btn-o btn-sm">📄 Gerar PDF Financeiro</button>'
    +'</div>';

  // ── Resumo ──
  html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px">';
  html += fCard('💚 Entradas', fmtR(d.total_entradas), 'var(--gn)');
  html += fCard('🔴 Despesas', fmtR(d.total_despesas), 'var(--rd)');
  html += fCard(res>=0?'✅ Resultado':'⚠️ Resultado', fmtR(res), resColor);
  html += '</div>';

  // ── Período ──
  html += '<div style="font-size:11px;color:var(--mt2);margin-bottom:16px">'+esc(d.period.label || ('Período: '+d.period.start+' a '+d.period.end))+'</div>';

  // ── Grid entradas / despesas ──
  html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">';

  // Entradas
  html += '<div style="background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:14px;overflow:hidden">';
  html += '<div style="padding:14px 16px;border-bottom:1px solid var(--bd,var(--border));display:flex;justify-content:space-between;align-items:center">';
  html += '<span style="font-family:Inter,sans-serif;font-weight:700;font-size:13px">💚 Entradas</span>';
  html += '<button onclick="openAddEntry(\'entrada\')" style="background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.25);border-radius:7px;color:var(--gn);padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:Inter,sans-serif">+ Adicionar</button>';
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
  html += '<span style="font-family:Inter,sans-serif;font-weight:700;font-size:13px">🔴 Despesas</span>';
  html += '<button onclick="openAddEntry(\'despesa\')" style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.25);border-radius:7px;color:var(--rd);padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:Inter,sans-serif">+ Adicionar</button>';
  html += '</div>';
  html += '<div style="padding:12px 16px">';

  // Meta Ads automático
  if (parseFloat(d.meta_spend) > 0) {
    html += '<div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--bd,var(--border))">';
    html += '<span style="font-size:16px">📘</span>';
    html += '<div style="flex:1"><div style="font-size:13px;font-weight:500">Meta Ads</div><div style="font-size:10px;color:var(--mt2)">Automático · todo o período</div></div>';
    html += '<span style="font-family:Inter,sans-serif;font-weight:700;color:var(--rd)">'+fmtR(d.meta_spend)+'</span>';
    html += '</div>';

    // Impostos Meta (ISS + PIS/COFINS)
    if (parseFloat(d.tax_total) > 0) {
      html += '<div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--bd,var(--border))">';
      html += '<span style="font-size:16px">🧾</span>';
      html += '<div style="flex:1">';
      html += '<div style="font-size:13px;font-weight:500">Impostos Meta</div>';
      html += '<div style="font-size:10px;color:var(--mt2)">ISS 2,9% ('+fmtR(d.tax_iss)+') + PIS/COFINS 9,25% ('+fmtR(d.tax_pis_cofins)+')</div>';
      html += '</div>';
      html += '<span style="font-family:Inter,sans-serif;font-weight:700;color:var(--rd)">'+fmtR(d.tax_total)+'</span>';
      html += '</div>';
    }
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

  // ── Gerenciar categorias ──
  html += '<div style="background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:12px;padding:16px;margin-bottom:16px">';
  html += '<div style="font-family:Inter,sans-serif;font-weight:700;font-size:12px;color:var(--mt2);text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px">Categorias personalizadas</div>';

  var customCats = (financialData.categories||[]).filter(function(c){ return !c.is_default || c.is_default == '0'; });
  if (!customCats.length) {
    html += '<div style="font-size:12px;color:var(--mt2);margin-bottom:12px">Nenhuma categoria personalizada ainda.</div>';
  } else {
    html += '<div style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px">';
    customCats.forEach(function(c) {
      var typeColor = c.type === 'entrada' ? 'var(--gn)' : 'var(--rd)';
      html += '<div style="display:flex;align-items:center;gap:8px;padding:7px 10px;background:var(--s2,var(--surface2));border-radius:8px;border:1px solid var(--bd,var(--border))">';
      html += '<span style="font-size:14px">'+c.icon+'</span>';
      html += '<span style="flex:1;font-size:13px">'+esc(c.name)+'</span>';
      html += '<span style="font-size:10px;color:'+typeColor+';font-weight:700">'+c.type+'</span>';
      html += '<button onclick="deleteCategory('+c.id+')" style="background:none;border:none;color:var(--rd);cursor:pointer;font-size:13px;padding:2px 6px" title="Deletar">🗑️</button>';
      html += '</div>';
    });
    html += '</div>';
  }

  html += '<div style="font-family:Inter,sans-serif;font-weight:700;font-size:12px;color:var(--mt2);text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px">+ Nova categoria</div>';
  html += '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">';
  html += '<input id="newCatIcon" type="text" value="📌" maxlength="2" style="width:48px;padding:8px;background:var(--s2,var(--surface2));border:1px solid var(--bd,var(--border));border-radius:8px;color:var(--tx,var(--text));font-size:18px;text-align:center">';
  html += '<input id="newCatName" type="text" placeholder="Nome da categoria" style="flex:1;min-width:140px;padding:8px 12px;background:var(--s2,var(--surface2));border:1px solid var(--bd,var(--border));border-radius:8px;color:var(--tx,var(--text));font-size:13px">';
  html += '<select id="newCatType" style="padding:8px 12px;background:var(--s2,var(--surface2));border:1px solid var(--bd,var(--border));border-radius:8px;color:var(--tx,var(--text));font-size:13px"><option value="despesa">Despesa</option><option value="entrada">Entrada</option></select>';
  html += '<button onclick="saveNewCategory()" style="padding:8px 14px;background:rgba(79,110,247,.12);border:1px solid rgba(79,110,247,.25);border-radius:8px;color:var(--ac,var(--accent));font-family:Inter,sans-serif;font-weight:700;font-size:12px;cursor:pointer">Salvar</button>';
  html += '</div></div>';

  // ── Modal de adicionar lançamento (oculto) ──
  html += '<div id="addEntryModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:500;align-items:center;justify-content:center">';
  html += '<div style="background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:16px;padding:24px;width:100%;max-width:400px;margin:0 16px">';
  html += '<div style="font-family:Inter,sans-serif;font-weight:800;font-size:16px;margin-bottom:16px" id="addEntryTitle">Adicionar</div>';
  html += '<input type="hidden" id="addEntryType">';
  html += fInput('Categoria','<select id="addEntryCat" style="width:100%">'+catOptions('despesa')+'</select>');
  html += fInput('Descrição','<input type="text" id="addEntryDesc" placeholder="Ex: Cachê DJ, Estrutura..." style="width:100%">');
  html += fInput('Valor (R$)','<input type="number" id="addEntryAmount" placeholder="0,00" step="0.01" min="0" style="width:100%">');
  html += fInput('Data','<input type="date" id="addEntryDate" style="width:100%">');
  html += '<div style="display:flex;gap:8px;margin-top:16px">';
  html += '<button onclick="saveEntry()" style="flex:1;padding:11px;background:var(--ac);border:none;border-radius:10px;color:#fff;font-family:Inter,sans-serif;font-weight:700;font-size:14px;cursor:pointer">💾 Salvar</button>';
  html += '<button onclick="closeAddEntry()" style="padding:11px 16px;background:transparent;border:1px solid var(--bd,var(--border));border-radius:10px;color:var(--mt2);font-family:Inter,sans-serif;font-weight:600;font-size:13px;cursor:pointer">Cancelar</button>';
  html += '</div></div></div>';

  document.getElementById('financialContent').innerHTML = html;

  // Seta input styles do modal
  document.querySelectorAll('#addEntryModal input, #addEntryModal select').forEach(function(el){
    el.style.cssText += 'padding:8px 12px;background:var(--s2);border:1px solid var(--bd);border-radius:8px;color:var(--tx);font-size:13px;font-family:Inter,sans-serif;outline:none';
  });
}

function fCard(label, val, color) {
  return '<div style="background:var(--sf,var(--surface));border:1px solid var(--bd,var(--border));border-radius:12px;padding:16px">'
    +'<div style="font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--mt2);margin-bottom:6px">'+label+'</div>'
    +'<div style="font-family:Inter,sans-serif;font-weight:900;font-size:22px;color:'+color+'">'+val+'</div>'
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
    +'<span style="font-family:Inter,sans-serif;font-weight:700;color:'+color+'">'+fmtR(e.amount)+'</span>'
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

async function deleteCategory(id) {
  if (!confirm('Deletar esta categoria?')) return;
  var res = await fetch('api/event_financial.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'delete_category', id:id})
  });
  var d = await res.json();
  if (!d.success) { alert(d.error||'Erro'); return; }
  financialLoaded = false;
  loadFinancial();
}
</script>
