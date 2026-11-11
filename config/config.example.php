<?php
// config/config.php — COPIE este arquivo, remova o .example e preencha com suas credenciais reais
// NÃO comite o config.php real — ele está no .gitignore

define('APP_NAME',    'MetaDash');
define('APP_URL',     'https://seudominio.com');
define('APP_ENV',     'production'); // production | development
define('DEBUG',       false);

// Chave de sessão (gere uma aleatória, ex: openssl rand -hex 32)
define('SESSION_KEY', 'TROQUE_POR_UMA_CHAVE_ALEATORIA_DE_32_CARACTERES');

// VAPID para Web Push (gere em: https://web-push-codelab.glitch.me/)
define('VAPID_PUBLIC_KEY',  'SUA_VAPID_PUBLIC_KEY');
define('VAPID_PRIVATE_KEY', 'SUA_VAPID_PRIVATE_KEY');

// Fuso horário
define('APP_TIMEZONE', 'America/Sao_Paulo');