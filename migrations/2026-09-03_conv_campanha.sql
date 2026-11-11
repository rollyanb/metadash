-- ============================================================
-- MetaDash — Conversões por campanha (para a aba Conversões do evento)
-- Idempotente. Rode antes de subir o cron/sync.php novo.
-- ============================================================

CREATE TABLE IF NOT EXISTS campaign_conversion_snapshots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id VARCHAR(50) NOT NULL,
  account_id VARCHAR(50) NOT NULL,
  snapshot_date DATE NOT NULL,
  campaign_name VARCHAR(255) NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  add_to_cart INT UNSIGNED NOT NULL DEFAULT 0,
  checkout INT UNSIGNED NOT NULL DEFAULT 0,
  purchases INT UNSIGNED NOT NULL DEFAULT 0,
  leads INT UNSIGNED NOT NULL DEFAULT 0,
  revenue DECIMAL(12,2) NOT NULL DEFAULT 0,
  spend DECIMAL(12,2) NOT NULL DEFAULT 0,
  synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_camp_date (campaign_id, snapshot_date),
  KEY idx_account_date (account_id, snapshot_date),
  KEY idx_date (snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
