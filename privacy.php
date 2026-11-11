<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Política de Privacidade — Financeiro Next</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg: #f7f7f5;
    --surface: #ffffff;
    --border: #e8e8e4;
    --accent: #1a1a2e;
    --accent2: #4f6ef7;
    --text: #1a1a1a;
    --muted: #6b6b6b;
    --muted2: #999;
  }

  body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.7;
    font-size: 16px;
  }

  header {
    background: var(--accent);
    color: white;
    padding: 48px 0 40px;
    text-align: center;
    position: relative;
    overflow: hidden;
  }

  header::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 300px; height: 300px;
    border-radius: 50%;
    background: rgba(79,110,247,0.15);
    pointer-events: none;
  }

  header::after {
    content: '';
    position: absolute;
    bottom: -80px; left: -40px;
    width: 200px; height: 200px;
    border-radius: 50%;
    background: rgba(79,110,247,0.08);
    pointer-events: none;
  }

  .logo {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
  }

  .logo-icon {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, #4f6ef7, #a78bfa);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
  }

  .logo-text {
    font-family: 'Syne', sans-serif;
    font-weight: 800;
    font-size: 20px;
    color: white;
  }

  .logo-text span { color: #a78bfa; }

  h1 {
    font-family: 'Syne', sans-serif;
    font-size: 32px;
    font-weight: 800;
    color: white;
    position: relative;
    z-index: 1;
  }

  .header-sub {
    color: rgba(255,255,255,0.6);
    font-size: 14px;
    margin-top: 8px;
    position: relative;
    z-index: 1;
  }

  .container {
    max-width: 760px;
    margin: 0 auto;
    padding: 48px 24px 80px;
  }

  .last-updated {
    display: inline-block;
    background: white;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 16px;
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 40px;
  }

  .section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 20px;
  }

  h2 {
    font-family: 'Syne', sans-serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--accent);
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
  }

  h2 .icon {
    width: 32px; height: 32px;
    background: rgba(79,110,247,0.08);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
  }

  p {
    color: #3a3a3a;
    margin-bottom: 12px;
    font-size: 15px;
  }

  p:last-child { margin-bottom: 0; }

  ul {
    list-style: none;
    margin: 12px 0;
  }

  ul li {
    padding: 6px 0 6px 22px;
    position: relative;
    font-size: 15px;
    color: #3a3a3a;
    border-bottom: 1px solid var(--border);
  }

  ul li:last-child { border-bottom: none; }

  ul li::before {
    content: '→';
    position: absolute;
    left: 0;
    color: var(--accent2);
    font-weight: 700;
  }

  .highlight {
    background: rgba(79,110,247,0.05);
    border-left: 3px solid var(--accent2);
    border-radius: 0 8px 8px 0;
    padding: 14px 18px;
    margin: 16px 0;
    font-size: 14px;
    color: var(--muted);
    font-style: italic;
  }

  .contact-card {
    background: linear-gradient(135deg, var(--accent), #2d2d5e);
    border-radius: 16px;
    padding: 32px;
    color: white;
    text-align: center;
    margin-top: 32px;
  }

  .contact-card h3 {
    font-family: 'Syne', sans-serif;
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 8px;
  }

  .contact-card p { color: rgba(255,255,255,0.7); margin-bottom: 16px; font-size: 14px; }

  .contact-card a {
    display: inline-block;
    background: white;
    color: var(--accent);
    padding: 10px 24px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 700;
    font-size: 14px;
    font-family: 'Syne', sans-serif;
  }

  footer {
    text-align: center;
    padding: 24px;
    font-size: 12px;
    color: var(--muted2);
    border-top: 1px solid var(--border);
  }
</style>
</head>
<body>

<header>
  <div class="logo">
    <div class="logo-icon">📊</div>
    <div class="logo-text">Financeiro<span>Next</span></div>
  </div>
  <h1>Política de Privacidade</h1>
  <p class="header-sub">Transparência no uso dos seus dados</p>
</header>

<div class="container">
  <div class="last-updated">📅 Última atualização: <?= date('d/m/Y') ?></div>

  <div class="section">
    <h2><div class="icon">📋</div> Sobre esta Política</h2>
    <p>Esta Política de Privacidade descreve como o aplicativo <strong>Financeiro Next</strong> coleta, usa e protege as informações obtidas através da integração com a API do Meta (Facebook).</p>
    <p>O Financeiro Next é uma ferramenta de gestão interna para visualização e análise de dados de contas de anúncio do Meta Ads. O uso é restrito ao proprietário e usuários autorizados expressamente.</p>
  </div>

  <div class="section">
    <h2><div class="icon">📥</div> Dados Coletados</h2>
    <p>Por meio da API do Meta, o aplicativo acessa exclusivamente:</p>
    <ul>
      <li>Nome e ID das contas de anúncio vinculadas ao usuário autenticado</li>
      <li>Dados de desempenho de campanhas (gastos, impressões, cliques)</li>
      <li>Informações de método de pagamento (últimos 4 dígitos e bandeira do cartão)</li>
      <li>Histórico de transações de cobrança das contas de anúncio</li>
    </ul>
    <div class="highlight">
      Nenhum dado sensível completo é armazenado — números de cartão completos, senhas ou dados bancários nunca são acessados ou armazenados pelo sistema.
    </div>
  </div>

  <div class="section">
    <h2><div class="icon">🎯</div> Finalidade do Uso</h2>
    <p>Os dados coletados são utilizados exclusivamente para:</p>
    <ul>
      <li>Exibir relatórios e dashboards de gastos em anúncios</li>
      <li>Identificar qual método de pagamento está vinculado a cada conta</li>
      <li>Gerar análises de desempenho por período e por conta</li>
      <li>Automatizar a sincronização periódica de dados via cron job</li>
    </ul>
    <p>Os dados <strong>não são compartilhados, vendidos ou transferidos</strong> a terceiros sob nenhuma circunstância.</p>
  </div>

  <div class="section">
    <h2><div class="icon">💾</div> Armazenamento e Segurança</h2>
    <p>Os dados são armazenados em banco de dados MySQL em servidor privado com acesso restrito. As seguintes medidas de segurança são aplicadas:</p>
    <ul>
      <li>Acesso ao painel protegido por autenticação com senha criptografada (bcrypt)</li>
      <li>Tokens de acesso à API do Meta armazenados de forma segura no banco de dados</li>
      <li>Comunicação com a API do Meta realizada exclusivamente via HTTPS</li>
      <li>Acesso ao servidor restrito ao proprietário do sistema</li>
    </ul>
  </div>

  <div class="section">
    <h2><div class="icon">🔑</div> Permissões da API do Meta</h2>
    <p>O aplicativo solicita as seguintes permissões ao Meta:</p>
    <ul>
      <li><strong>ads_read</strong> — leitura de dados de campanhas e gastos</li>
      <li><strong>ads_management</strong> — acesso às contas de anúncio</li>
      <li><strong>business_management</strong> — acesso às informações do Business Manager</li>
    </ul>
    <p>Essas permissões são utilizadas exclusivamente para leitura de dados. O aplicativo não cria, edita ou exclui campanhas, anúncios ou qualquer configuração nas contas do Meta.</p>
  </div>

  <div class="section">
    <h2><div class="icon">⏳</div> Retenção de Dados</h2>
    <p>Os dados sincronizados são mantidos no banco de dados enquanto o sistema estiver em uso. O usuário pode, a qualquer momento, solicitar a exclusão dos dados armazenados ou revogar o acesso do aplicativo diretamente nas configurações do Facebook em:</p>
    <p><strong>Facebook → Configurações → Aplicativos e sites → Financeiro Next → Remover</strong></p>
    <p>A revogação do acesso encerra imediatamente a capacidade do sistema de acessar novos dados via API.</p>
  </div>

  <div class="section">
    <h2><div class="icon">🗑️</div> Exclusão de Dados do Usuário</h2>
    <p>Em conformidade com as políticas do Meta, você pode solicitar a exclusão de todos os seus dados armazenados pelo aplicativo. Para isso:</p>
    <ul>
      <li>Revogue o acesso do app nas configurações do Facebook</li>
      <li>Entre em contato pelo e-mail abaixo solicitando a exclusão</li>
      <li>Seus dados serão removidos do banco de dados em até 30 dias</li>
    </ul>
  </div>

  <div class="section">
    <h2><div class="icon">📜</div> Conformidade com o Meta</h2>
    <p>Este aplicativo foi desenvolvido em conformidade com os <a href="https://developers.facebook.com/terms/" target="_blank" style="color:var(--accent2)">Termos de Serviço da Plataforma do Meta</a> e as <a href="https://developers.facebook.com/devpolicy/" target="_blank" style="color:var(--accent2)">Políticas para Desenvolvedores do Meta</a>.</p>
    <p>O aplicativo opera em modo restrito, com acesso limitado ao proprietário e usuários administradores expressamente autorizados.</p>
  </div>

  <div class="contact-card">
    <h3>📬 Dúvidas ou Solicitações</h3>
    <p>Para questões relacionadas à privacidade, exclusão de dados ou revogação de acesso, entre em contato:</p>
    <a href="mailto:contato@rollyan.com.br">contato@rollyan.com.br</a>
  </div>
</div>

<footer>
  © <?= date('Y') ?> Financeiro Next · Todos os direitos reservados · <a href="/" style="color:inherit">metadash.space</a>
</footer>

</body>
</html>
