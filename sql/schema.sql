
CREATE TABLE IF NOT EXISTS tb_expense_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    assignee TEXT NOT NULL,
    account_info TEXT,
    payment_site TEXT,
    cycle_type TEXT NOT NULL CHECK (cycle_type IN ('MONTHLY', 'YEARLY', 'IRREGULAR')),
    currency TEXT NOT NULL DEFAULT 'KRW',
    default_amount REAL,
    payment_type TEXT CHECK (payment_type IN ('AUTO', 'MANUAL')),
    next_renewal_date TEXT,
    title_pattern TEXT NOT NULL,
    filename_pattern TEXT,
    body_pattern TEXT NOT NULL,
    vendor TEXT,
    period_pattern TEXT,
    payment_method_text TEXT,
    pay_request_pattern TEXT,
    attachment_text TEXT,
    content_mode TEXT NOT NULL DEFAULT 'LINES',
    content_include_vendor INTEGER NOT NULL DEFAULT 1,
    note TEXT,
    created_by TEXT NOT NULL DEFAULT '',
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS tb_draft_expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    expense_template_id INTEGER,
    target_year_month TEXT NOT NULL,
    draft_title TEXT NOT NULL,
    draft_body TEXT NOT NULL,
    currency TEXT NOT NULL,
    amount_foreign REAL,
    exchange_rate REAL,
    exchange_unit INTEGER NOT NULL DEFAULT 1,
    rate_date TEXT,
    amount_krw INTEGER NOT NULL,
    payment_date TEXT,
    status TEXT NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'DONE')),
    contents_text TEXT,
    created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY (expense_template_id) REFERENCES tb_expense_templates(id)
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_tb_draft_expenses_template_month
    ON tb_draft_expenses (expense_template_id, target_year_month);

CREATE TABLE IF NOT EXISTS tb_draft_payment_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    draft_expense_id INTEGER NOT NULL,
    receipt_id INTEGER NULL,
    payment_date TEXT,
    description TEXT,
    vendor TEXT,
    note TEXT,
    item_kind TEXT NOT NULL DEFAULT 'PAY',
    currency TEXT NOT NULL,
    amount_foreign REAL NOT NULL,
    exchange_rate REAL,
    exchange_unit INTEGER NOT NULL DEFAULT 1,
    rate_date TEXT,
    amount_krw INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    FOREIGN KEY (draft_expense_id) REFERENCES tb_draft_expenses(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS ix_tb_draft_payment_items_draft
    ON tb_draft_payment_items (draft_expense_id, sort_order);

CREATE TABLE IF NOT EXISTS tb_revenue_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_name TEXT NOT NULL,
    client_name TEXT,
    supply_krw INTEGER NOT NULL DEFAULT 0,
    assignee TEXT NOT NULL,
    start_year_month TEXT NOT NULL,
    end_year_month TEXT,
    note TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS tb_team_revenues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    revenue_template_id INTEGER,
    target_year_month TEXT NOT NULL,
    project_name TEXT NOT NULL,
    client_name TEXT,
    supply_krw INTEGER NOT NULL DEFAULT 0,
    vat_krw INTEGER NOT NULL DEFAULT 0,
    amount_krw INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'COMPLETED' CHECK (status IN ('PENDING', 'COMPLETED')),
    received_date TEXT NOT NULL,
    assignee TEXT NOT NULL,
    note TEXT,
    created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY (revenue_template_id) REFERENCES tb_revenue_templates(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_tb_team_revenues_template_month
    ON tb_team_revenues (revenue_template_id, target_year_month);

DROP VIEW IF EXISTS vi_monthly_pnl;
CREATE VIEW vi_monthly_pnl AS
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
)
GROUP BY ym;

CREATE TABLE IF NOT EXISTS tb_draft_receipts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    expense_template_id INTEGER NOT NULL,
    target_year_month TEXT NOT NULL,
    display_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime TEXT,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    uploaded_by TEXT,
    uploaded_name TEXT,
    created_at TEXT NOT NULL,
    analysis_json TEXT,
    analyzed_at TEXT
);
CREATE INDEX IF NOT EXISTS ix_tb_draft_receipts_tpl_ym
    ON tb_draft_receipts (expense_template_id, target_year_month);

-- SSO 로그인 시 적립하는 사용자 디렉터리(email→name). 영수증 이메일 매칭에 사용.
CREATE TABLE IF NOT EXISTS tb_users (
    email TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
