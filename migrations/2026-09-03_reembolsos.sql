-- ============================================================
-- MetaDash — Controle de reembolso das cobranças da Meta
-- Idempotente. Rode antes de subir os arquivos.
-- ============================================================

-- Um reembolso recebido (PIX, transferência...). Pode quitar várias cobranças.
CREATE TABLE IF NOT EXISTS refunds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  refund_date DATE NOT NULL,
  method VARCHAR(40) NULL,
  receipt_url TEXT NULL,
  receipt_file VARCHAR(255) NULL,
  notes TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_event (event_id),
  KEY idx_date (refund_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quanto de cada reembolso quitou de cada linha de cobrança (permite parcial).
CREATE TABLE IF NOT EXISTS refund_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  refund_id INT NOT NULL,
  item_id INT NOT NULL,               -- paid_transaction_items.id
  amount DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_refund (refund_id),
  KEY idx_item (item_id),
  CONSTRAINT fk_alloc_refund FOREIGN KEY (refund_id) REFERENCES refunds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marca linhas que não devem entrar na cobrança do cliente (ex.: mídia própria).
ALTER TABLE paid_transaction_items ADD COLUMN IF NOT EXISTS refund_exempt TINYINT(1) NOT NULL DEFAULT 0;
