-- ============================================================
-- MetaDash — registro de mudanças de orçamento
-- Idempotente.
-- ============================================================
ALTER TABLE campaign_actions ADD COLUMN IF NOT EXISTS previous_budget DECIMAL(12,2) NULL AFTER previous_status;
ALTER TABLE campaign_actions ADD COLUMN IF NOT EXISTS new_budget DECIMAL(12,2) NULL AFTER previous_budget;
ALTER TABLE campaign_actions ADD COLUMN IF NOT EXISTS budget_type VARCHAR(20) NULL AFTER new_budget;
