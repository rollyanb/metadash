-- ============================================================
-- MetaDash — cache da projeção de orçamento (evita bater na Meta
-- uma vez por card a cada abertura da lista de eventos)
-- Idempotente.
-- ============================================================
CREATE TABLE IF NOT EXISTS projection_cache (
  event_id INT UNSIGNED NOT NULL PRIMARY KEY,
  payload MEDIUMTEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
