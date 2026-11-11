<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme.php';

if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    $lockMin = $username !== '' ? Auth::loginLockedMinutes($username) : 0;
    if ($lockMin > 0) {
        $error = "Muitas tentativas. Tente novamente em {$lockMin} min.";
    } elseif (Auth::login($username, $password, $remember)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Usuário ou senha inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= getThemeClass() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Meta Ads Dashboard — Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {--bg: #0a0a0f;--surface: #12121a;--border: rgba(255,255,255,0.06);--accent: #5470ff;--accent2: #7c91ff;--text: #f0f0f5;--muted: #6b6b80;--error: #f87171;}

  body {
    font-family:'Inter',sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }

  .bg-glow {
    position: fixed;
    width: 600px; height: 600px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(79,110,247,0.12) 0%, transparent 70%);
    top: -200px; left: -200px;
    pointer-events: none;
  }
  .bg-glow2 {
    position: fixed;
    width: 400px; height: 400px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(167,139,250,0.08) 0%, transparent 70%);
    bottom: -100px; right: -100px;
    pointer-events: none;
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 48px 40px;
    width: 100%;
    max-width: 420px;
    position: relative;
    z-index: 1;
    animation: fadeUp 0.5s ease forwards;
  }

  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .logo {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 36px;
  }

  .logo-icon {
    width: 42px; height: 42px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
  }

  .logo-text {
    font-family:'Inter',sans-serif;
    font-weight: 800;
    font-size: 18px;
    letter-spacing: -0.5px;
  }

  .logo-text span { color: var(--accent); }

  h1 {
    font-family:'Inter',sans-serif;
    font-size: 26px;
    font-weight: 700;
    margin-bottom: 6px;
  }

  .subtitle { color: var(--muted); font-size: 14px; margin-bottom: 32px; }

  label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--muted);
    margin-bottom: 6px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
  }

  input[type="text"],
  input[type="password"] {
    width: 100%;
    padding: 13px 16px;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--text);
    font-family:'Inter',sans-serif;
    font-size: 15px;
    margin-bottom: 20px;
    transition: border-color 0.2s;
    outline: none;
  }

  input[type="text"]:focus,
  input[type="password"]:focus { border-color: var(--accent); }

  .error-msg {
    background: rgba(248,113,113,0.1);
    border: 1px solid rgba(248,113,113,0.3);
    color: var(--error);
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    margin-bottom: 20px;
  }

  /* Checkbox "manter logado" */
  .remember-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 24px;
    cursor: pointer;
    user-select: none;
  }
  .remember-row input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin: 0;
    accent-color: var(--accent);
    cursor: pointer;
    flex-shrink: 0;
  }
  .remember-row span {
    font-size: 13px;
    color: var(--muted);
    line-height: 1;
  }

  button[type="submit"] {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border: none;
    border-radius: 10px;
    color: white;
    font-family:'Inter',sans-serif;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    letter-spacing: 0.3px;
    transition: opacity 0.2s, transform 0.1s;
  }

  button[type="submit"]:hover  { opacity: 0.9; }
  button[type="submit"]:active { transform: scale(0.98); }

  .hint {
    text-align: center;
    margin-top: 20px;
    font-size: 12px;
    color: var(--muted);
  }
</style>
<?php require_once __DIR__ . '/includes/theme.php'; echoThemeAssets(''); ?>
</head>
<body>
<div class="bg-glow"></div>
<div class="bg-glow2"></div>
<div class="card">
  <div class="logo">
    <div class="logo-icon">📊</div>
    <div class="logo-text">Meta<span>Dash</span></div>
  </div>
  <h1>Entrar</h1>
  <p class="subtitle">Painel de gestão de anúncios</p>

  <?php if ($error): ?>
  <div class="error-msg">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="">
      <?= Auth::csrfField() ?>
    <label>Usuário</label>
    <input type="text" name="username" placeholder="admin" required autofocus>
    <label>Senha</label>
    <input type="password" name="password" placeholder="••••••••" required>

    <label class="remember-row">
      <input type="checkbox" name="remember" value="1" checked>
      <span>Manter-me logado por <?= Auth::REMEMBER_DAYS ?> dias</span>
    </label>

    <button type="submit">Acessar painel →</button>
  </form>
</div>
</body>
</html>
