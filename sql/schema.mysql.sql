CREATE TABLE IF NOT EXISTS tb_expense_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    assignee VARCHAR(50) NOT NULL,
    account_info VARCHAR(100),
    payment_site VARCHAR(255),
    cycle_type ENUM('MONTHLY', 'YEARLY', 'IRREGULAR') NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'KRW',
    default_amount DECIMAL(10, 2),
    payment_type ENUM('AUTO', 'MANUAL'),
    next_renewal_date DATE,
    title_pattern TEXT NOT NULL,
    filename_pattern TEXT,
    body_pattern TEXT NOT NULL,
    vendor VARCHAR(200),
    period_pattern VARCHAR(200),
    payment_method_text VARCHAR(200),
    pay_request_pattern VARCHAR(200),
    attachment_text VARCHAR(200),
    content_mode VARCHAR(16) NOT NULL DEFAULT 'LINES',
    content_include_vendor TINYINT(4) NOT NULL DEFAULT 1,
    note TEXT,
    created_by VARCHAR(255) NOT NULL DEFAULT '',
    is_active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_draft_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_template_id INT,
    target_year_month VARCHAR(7) NOT NULL,
    draft_title VARCHAR(200) NOT NULL,
    draft_body TEXT NOT NULL,
    currency VARCHAR(10) NOT NULL,
    amount_foreign DECIMAL(10, 2),
    exchange_rate DECIMAL(10, 4),
    exchange_unit INT NOT NULL DEFAULT 1,
    rate_date DATE,
    amount_krw INT NOT NULL,
    payment_date DATE,
    status ENUM('DRAFT', 'DONE') NOT NULL DEFAULT 'DRAFT',
    contents_text TEXT,
    created_by VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_tb_draft_expenses_template
        FOREIGN KEY (expense_template_id) REFERENCES tb_expense_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_tb_draft_expenses_template_month
    ON tb_draft_expenses (expense_template_id, target_year_month);

CREATE TABLE IF NOT EXISTS tb_draft_payment_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    draft_expense_id INT NOT NULL,
    receipt_id INT NULL,
    payment_date DATE,
    description VARCHAR(255),
    vendor VARCHAR(200),
    note TEXT,
    item_kind VARCHAR(16) NOT NULL DEFAULT 'PAY',
    currency VARCHAR(10) NOT NULL,
    amount_foreign DECIMAL(12, 2) NOT NULL,
    exchange_rate DECIMAL(12, 4),
    exchange_unit INT NOT NULL DEFAULT 1,
    rate_date DATE,
    amount_krw INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_tb_draft_payment_items_draft
        FOREIGN KEY (draft_expense_id) REFERENCES tb_draft_expenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_tb_draft_payment_items_draft
    ON tb_draft_payment_items (draft_expense_id, sort_order);

CREATE TABLE IF NOT EXISTS tb_revenue_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_name VARCHAR(150) NOT NULL,
    client_name VARCHAR(100),
    service_category VARCHAR(30) NOT NULL DEFAULT '',
    supply_krw INT NOT NULL DEFAULT 0,
    assignee VARCHAR(50) NOT NULL,
    start_year_month VARCHAR(7) NOT NULL,
    end_year_month VARCHAR(7),
    note TEXT,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_by VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_team_revenues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    revenue_template_id INT,
    target_year_month VARCHAR(7) NOT NULL,
    project_name VARCHAR(150) NOT NULL,
    client_name VARCHAR(100),
    service_category VARCHAR(30) NOT NULL DEFAULT '',
    supply_krw INT NOT NULL DEFAULT 0,
    vat_krw INT NOT NULL DEFAULT 0,
    amount_krw INT NOT NULL,
    billing_type ENUM('PAID','FREE') NOT NULL DEFAULT 'PAID',
    list_value_krw INT NOT NULL DEFAULT 0,
    status ENUM('PENDING', 'COMPLETED') NOT NULL DEFAULT 'COMPLETED',
    received_date DATE NOT NULL,
    assignee VARCHAR(50) NOT NULL,
    note TEXT,
    created_by VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_tb_team_revenues_template
        FOREIGN KEY (revenue_template_id) REFERENCES tb_revenue_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX ux_tb_team_revenues_template_month
    ON tb_team_revenues (revenue_template_id, target_year_month);

CREATE OR REPLACE VIEW vi_monthly_pnl AS
SELECT
    ym,
    SUM(revenue_krw) AS revenue_krw,
    SUM(expense_krw) AS expense_krw,
    SUM(revenue_krw) - SUM(expense_krw) AS margin_krw
FROM (
    SELECT target_year_month AS ym, amount_krw AS revenue_krw, 0 AS expense_krw
    FROM tb_team_revenues
    UNION ALL
    SELECT d.target_year_month AS ym, 0 AS revenue_krw,
           COALESCE((
               SELECT SUM(i.amount_krw) FROM tb_draft_payment_items i
               WHERE i.draft_expense_id = d.id
           ), d.amount_krw) AS expense_krw
    FROM tb_draft_expenses d
    WHERE d.status IN ('DRAFT', 'DONE')
) AS pnl
GROUP BY ym;

CREATE TABLE IF NOT EXISTS tb_draft_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_template_id INT NOT NULL,
    target_year_month VARCHAR(7) NOT NULL,
    display_name VARCHAR(512) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime VARCHAR(100),
    size_bytes INT NOT NULL DEFAULT 0,
    uploaded_by VARCHAR(255),
    uploaded_name VARCHAR(255),
    created_at DATETIME NOT NULL,
    analysis_json LONGTEXT,
    analyzed_at DATETIME,
    KEY ix_tb_draft_receipts_tpl_ym (expense_template_id, target_year_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- SSO 로그인 시 적립하는 사용자 디렉터리(email→name). 영수증 이메일 매칭에 사용.
CREATE TABLE IF NOT EXISTS tb_users (
    email VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
