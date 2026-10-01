ALTER TABLE tb_team_revenues
  ADD COLUMN service_category VARCHAR(30) NOT NULL DEFAULT '' AFTER client_name,
  ADD COLUMN billing_type ENUM('PAID','FREE') NOT NULL DEFAULT 'PAID' AFTER amount_krw,
  ADD COLUMN list_value_krw INT NOT NULL DEFAULT 0 AFTER billing_type;

ALTER TABLE tb_revenue_templates
  ADD COLUMN service_category VARCHAR(30) NOT NULL DEFAULT '' AFTER client_name;

CREATE TABLE IF NOT EXISTS tb_revenue_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    revenue_id INT NOT NULL,
    display_name VARCHAR(512) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime VARCHAR(100),
    size_bytes INT NOT NULL DEFAULT 0,
    uploaded_by VARCHAR(255),
    uploaded_name VARCHAR(255),
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_tb_revenue_receipts_revenue
        FOREIGN KEY (revenue_id) REFERENCES tb_team_revenues(id) ON DELETE CASCADE,
    KEY ix_tb_revenue_receipts_revenue (revenue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
