-- ============================================================
-- MetaDash — Transações pagas (recibos do Business Manager)
-- Idempotente. Rode no phpMyAdmin do banco de produção.
-- ============================================================

CREATE TABLE IF NOT EXISTS paid_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id VARCHAR(80) NOT NULL,
  account_name VARCHAR(255) NOT NULL DEFAULT '',
  billing_account VARCHAR(50) NULL,
  paid_at DATETIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'desconhecido',
  payment_method VARCHAR(100) NULL,
  reference VARCHAR(50) NULL,
  total DECIMAL(12,2) NULL,
  subtotal DECIMAL(12,2) NULL,
  taxes DECIMAL(12,2) NOT NULL DEFAULT 0,
  imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tid (transaction_id),
  KEY idx_paid_at (paid_at),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS paid_transaction_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id VARCHAR(80) NOT NULL,
  campaign_name VARCHAR(500) NOT NULL,
  value DECIMAL(12,2) NOT NULL,
  period_start DATETIME NULL,
  period_end DATETIME NULL,
  event_id INT UNSIGNED NULL,
  match_source ENUM('campanha','tag','manual','') NOT NULL DEFAULT '',
  KEY idx_tid (transaction_id),
  KEY idx_event (event_id),
  CONSTRAINT fk_pti_tx FOREIGN KEY (transaction_id) REFERENCES paid_transactions(transaction_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tags de evento pro vínculo automático (ex.: "[BL RP]" → evento Baile Lotadão RP)
CREATE TABLE IF NOT EXISTS event_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tag VARCHAR(100) NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_tag (tag),
  KEY idx_event (event_id),
  CONSTRAINT fk_tag_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Log de acessos (também criada automaticamente pelo app) ──
CREATE TABLE IF NOT EXISTS access_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  username VARCHAR(50) NOT NULL DEFAULT '',
  event VARCHAR(20) NOT NULL,
  page VARCHAR(120) NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_user (user_id),
  KEY idx_event (event),
  KEY idx_page (page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- se a tabela já existia sem a coluna:
ALTER TABLE access_log ADD COLUMN IF NOT EXISTS page VARCHAR(120) NULL AFTER event;
ALTER TABLE access_log ADD INDEX IF NOT EXISTS idx_page (page);
