-- ============================================================
-- MetaDash — Tarefas unificadas (lotes viram tarefas)
-- Idempotente. Rode antes de subir os arquivos.
-- ============================================================

-- 1. Novos campos na tarefa
ALTER TABLE event_tasks ADD COLUMN IF NOT EXISTS priority ENUM('baixa','media','alta') NOT NULL DEFAULT 'media' AFTER status;
ALTER TABLE event_tasks ADD COLUMN IF NOT EXISTS lot_price DECIMAL(10,2) NULL AFTER due_date;
ALTER TABLE event_tasks ADD COLUMN IF NOT EXISTS link TEXT NULL AFTER notes;
ALTER TABLE event_tasks ADD COLUMN IF NOT EXISTS done_at DATETIME NULL AFTER updated_at;
ALTER TABLE event_tasks ADD INDEX IF NOT EXISTS idx_event_due (event_id, due_date);
ALTER TABLE event_tasks ADD INDEX IF NOT EXISTS idx_status (status);

-- 2. Categoria ganha os tipos de lote
ALTER TABLE event_tasks MODIFY category
  ENUM('criativo','copy','trafego','lote','sem_taxa','material','reuniao','pagamento','outro')
  NOT NULL DEFAULT 'outro';

-- 3. Migra os lotes existentes para tarefas (só uma vez — não duplica ao repetir)
INSERT INTO event_tasks (event_id, title, category, status, priority, due_date, lot_price, notes, link, created_by, created_at)
SELECT l.event_id, l.name,
       CASE l.type WHEN 'sem_taxa' THEN 'sem_taxa' WHEN 'lote' THEN 'lote' ELSE 'outro' END,
       IF(l.lot_date < CURDATE(), 'concluido', 'pendente'),
       'alta', l.lot_date, NULLIF(l.price, 0),
       NULLIF(l.material_name, ''), NULLIF(l.material_link, ''),
       (SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1), l.created_at
FROM event_lots l
WHERE NOT EXISTS (
  SELECT 1 FROM event_tasks t
  WHERE t.event_id = l.event_id AND t.title = l.name AND t.due_date <=> l.lot_date
);

-- 4. event_lots fica intacta como backup. Confira as tarefas migradas e,
--    quando tiver certeza, você pode remover a tabela:
--    DROP TABLE event_lots;
