-- ============================================================
-- MetaDash — Materiais viram itens da aba Tarefas
-- Idempotente. Rode antes de subir os arquivos.
-- ============================================================

-- Migra materiais para event_tasks (categoria 'material').
-- "enviado" vira concluído; o link vai para o campo link da tarefa.
INSERT INTO event_tasks (event_id, title, category, status, priority, due_date, link, created_by, created_at)
SELECT m.event_id, m.name, 'material',
       IF(m.status = 'enviado', 'concluido', 'pendente'),
       'baixa', m.due_date, NULLIF(m.link, ''),
       COALESCE(m.created_by, (SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1)),
       m.created_at
FROM event_materials m
WHERE NOT EXISTS (
  SELECT 1 FROM event_tasks t
  WHERE t.event_id = m.event_id AND t.title = m.name AND t.category = 'material'
);

-- event_materials fica intacta como backup. Depois de conferir, você pode:
--   DROP TABLE event_materials;
