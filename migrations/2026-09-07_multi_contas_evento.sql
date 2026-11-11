-- ============================================================
-- MetaDash — Evento com mais de uma conta de anúncios
-- Idempotente. Rode antes de subir os arquivos.
-- events.account_id continua existindo como "conta principal".
-- ============================================================

CREATE TABLE IF NOT EXISTS event_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  account_id VARCHAR(50) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event_account (event_id, account_id),
  KEY idx_account (account_id),
  CONSTRAINT fk_evacc_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Traz a conta atual de cada evento para a nova tabela
INSERT IGNORE INTO event_accounts (event_id, account_id)
SELECT id, account_id FROM events WHERE account_id IS NOT NULL AND account_id <> '';
