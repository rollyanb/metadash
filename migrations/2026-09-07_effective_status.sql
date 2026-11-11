-- ============================================================
-- MetaDash — status real (effective_status) das campanhas
-- A Meta mantém a campanha como ACTIVE mesmo com todos os
-- conjuntos pausados ou com a data de término vencida.
-- Idempotente.
-- ============================================================
ALTER TABLE event_campaigns ADD COLUMN IF NOT EXISTS effective_status VARCHAR(30) NULL AFTER status;
ALTER TABLE event_campaigns ADD COLUMN IF NOT EXISTS stop_time DATETIME NULL AFTER effective_status;
ALTER TABLE event_campaigns ADD INDEX IF NOT EXISTS idx_eff_status (effective_status);
