-- ============================================================
-- MetaDash — migração da auditoria de 26/08/2026
-- MariaDB 11.x. Idempotente (pode rodar mais de uma vez).
-- FAÇA BACKUP ANTES. Rode no phpMyAdmin do Hostinger.
-- ============================================================

-- ── 1. Tabelas novas (também são criadas automaticamente pelo app) ──
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(50) NOT NULL,
  tries INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_try TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ip_user (ip, username),
  KEY idx_locked (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_errors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  level VARCHAR(10) NOT NULL,
  message TEXT NOT NULL,
  context TEXT NULL,
  script VARCHAR(255) NULL,
  user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versão da Graph API configurável sem deploy (troque o valor quando migrar)
INSERT INTO system_settings (`key`, `value`) VALUES ('meta_api_version', 'v21.0')
  ON DUPLICATE KEY UPDATE `key` = `key`;

-- ── 2. Índices que faltavam ──
ALTER TABLE campaign_snapshots ADD INDEX IF NOT EXISTS idx_snapshot_date (snapshot_date);
ALTER TABLE campaign_snapshots ADD INDEX IF NOT EXISTS idx_account_date (account_id, snapshot_date);
ALTER TABLE event_campaigns    ADD INDEX IF NOT EXISTS idx_campaign_id (campaign_id);
ALTER TABLE event_campaigns    ADD INDEX IF NOT EXISTS idx_status (status);
ALTER TABLE sync_log           ADD INDEX IF NOT EXISTS idx_created (created_at);
ALTER TABLE sync_log           ADD INDEX IF NOT EXISTS idx_type_created (sync_type, created_at);
ALTER TABLE cron_log           ADD INDEX IF NOT EXISTS idx_ran_at (ran_at);
ALTER TABLE events             ADD INDEX IF NOT EXISTS idx_account (account_id);
ALTER TABLE events             ADD INDEX IF NOT EXISTS idx_status_date (status, date_event);
ALTER TABLE notifications      ADD INDEX IF NOT EXISTS idx_user_read (user_id, read_at);
ALTER TABLE event_tasks        ADD INDEX IF NOT EXISTS idx_event (event_id);
ALTER TABLE event_lots         ADD INDEX IF NOT EXISTS idx_event (event_id);
ALTER TABLE event_materials    ADD INDEX IF NOT EXISTS idx_event (event_id);
ALTER TABLE push_subscriptions ADD INDEX IF NOT EXISTS idx_user (user_id);
ALTER TABLE ad_creative_snapshots ADD INDEX IF NOT EXISTS idx_date (snapshot_date);

-- Transações: dedup no banco (antes era só no PHP).
-- Antes de criar o UNIQUE, remova duplicatas com cleanup_duplicate_transactions.php (sem dry-run).
-- Se esta linha falhar por "Duplicate entry", rode a limpeza e repita.
ALTER TABLE transactions ADD UNIQUE INDEX IF NOT EXISTS uq_txn (account_id, transaction_id);

-- ── 3. Alinhar tipos para permitir FKs (users.id é INT signed; events.id é INT UNSIGNED) ──
ALTER TABLE notifications           MODIFY user_id INT NOT NULL;
ALTER TABLE push_subscriptions      MODIFY user_id INT NOT NULL;
ALTER TABLE push_user_accounts      MODIFY user_id INT NOT NULL;
ALTER TABLE event_user_access       MODIFY user_id INT NOT NULL;
ALTER TABLE event_financial_entries MODIFY event_id INT UNSIGNED NOT NULL;
ALTER TABLE event_suggestions       MODIFY event_id INT UNSIGNED NOT NULL;

-- ── 4. Limpar órfãos (registros de eventos/usuários já apagados) ──
DELETE FROM event_tasks             WHERE event_id NOT IN (SELECT id FROM events);
DELETE FROM event_lots              WHERE event_id NOT IN (SELECT id FROM events);
DELETE FROM event_materials         WHERE event_id NOT IN (SELECT id FROM events);
DELETE FROM event_campaigns         WHERE event_id NOT IN (SELECT id FROM events);
DELETE FROM event_financial_entries WHERE event_id NOT IN (SELECT id FROM events);
DELETE FROM event_suggestions       WHERE event_id NOT IN (SELECT id FROM events);
DELETE FROM event_user_access       WHERE event_id NOT IN (SELECT id FROM events) OR user_id NOT IN (SELECT id FROM users);
DELETE FROM notifications           WHERE user_id  NOT IN (SELECT id FROM users);
DELETE FROM push_subscriptions      WHERE user_id  NOT IN (SELECT id FROM users);
DELETE FROM push_user_accounts      WHERE user_id  NOT IN (SELECT id FROM users);
DELETE FROM remember_tokens         WHERE user_id  NOT IN (SELECT id FROM users);
DELETE FROM company_user_access     WHERE user_id  NOT IN (SELECT id FROM users);

-- ── 5. Foreign keys com CASCADE (apagar evento/usuário limpa os filhos) ──
ALTER TABLE event_tasks             ADD FOREIGN KEY IF NOT EXISTS fk_task_event (event_id) REFERENCES events(id) ON DELETE CASCADE;
ALTER TABLE event_lots              ADD FOREIGN KEY IF NOT EXISTS fk_lot_event (event_id) REFERENCES events(id) ON DELETE CASCADE;
ALTER TABLE event_materials         ADD FOREIGN KEY IF NOT EXISTS fk_mat_event (event_id) REFERENCES events(id) ON DELETE CASCADE;
ALTER TABLE event_campaigns         ADD FOREIGN KEY IF NOT EXISTS fk_camp_event (event_id) REFERENCES events(id) ON DELETE CASCADE;
ALTER TABLE event_financial_entries ADD FOREIGN KEY IF NOT EXISTS fk_fin_event (event_id) REFERENCES events(id) ON DELETE CASCADE;
ALTER TABLE event_suggestions       ADD FOREIGN KEY IF NOT EXISTS fk_sug_event (event_id) REFERENCES events(id) ON DELETE CASCADE;
ALTER TABLE event_user_access       ADD FOREIGN KEY IF NOT EXISTS fk_eua_event (event_id) REFERENCES events(id) ON DELETE CASCADE;
ALTER TABLE event_user_access       ADD FOREIGN KEY IF NOT EXISTS fk_eua_user (user_id)  REFERENCES users(id)  ON DELETE CASCADE;
ALTER TABLE notifications           ADD FOREIGN KEY IF NOT EXISTS fk_notif_user (user_id)  REFERENCES users(id)  ON DELETE CASCADE;
ALTER TABLE push_subscriptions      ADD FOREIGN KEY IF NOT EXISTS fk_push_user (user_id)  REFERENCES users(id)  ON DELETE CASCADE;
ALTER TABLE push_user_accounts      ADD FOREIGN KEY IF NOT EXISTS fk_pua_user (user_id)  REFERENCES users(id)  ON DELETE CASCADE;
ALTER TABLE remember_tokens         ADD FOREIGN KEY IF NOT EXISTS fk_rt_user (user_id)  REFERENCES users(id)  ON DELETE CASCADE;
ALTER TABLE company_user_access     ADD FOREIGN KEY IF NOT EXISTS fk_cua_user (user_id)  REFERENCES users(id)  ON DELETE CASCADE;

-- ── 6. Rotação inicial de logs (o cron housekeeping.php mantém daqui em diante) ──
DELETE FROM sync_log WHERE created_at < NOW() - INTERVAL 30 DAY;
DELETE FROM cron_log WHERE ran_at     < NOW() - INTERVAL 30 DAY;
DELETE FROM remember_tokens WHERE expires_at < NOW();
