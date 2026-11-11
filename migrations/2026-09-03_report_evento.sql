-- ============================================================
-- MetaDash — Relatório público por evento
-- Idempotente.
-- ============================================================
ALTER TABLE report_tokens ADD COLUMN IF NOT EXISTS event_id INT UNSIGNED NULL AFTER account_id;
ALTER TABLE report_tokens ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER active;
ALTER TABLE report_tokens ADD INDEX IF NOT EXISTS idx_event (event_id);
-- account_id deixa de ser obrigatório (token de evento não usa)
ALTER TABLE report_tokens MODIFY account_id VARCHAR(50) NULL;
