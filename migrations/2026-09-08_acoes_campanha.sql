-- ============================================================
-- MetaDash — registro de pausas/reativações feitas pelo painel
-- Idempotente.
-- ============================================================
CREATE TABLE IF NOT EXISTS campaign_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NULL,
  campaign_id VARCHAR(50) NOT NULL,
  campaign_name VARCHAR(255) NULL,
  action VARCHAR(20) NOT NULL,          -- pause | activate
  previous_status VARCHAR(30) NULL,
  reason TEXT NULL,                     -- motivo da recomendação no momento
  result VARCHAR(20) NOT NULL,          -- ok | erro
  error_message TEXT NULL,
  undone_at DATETIME NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_event (event_id),
  KEY idx_campaign (campaign_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
