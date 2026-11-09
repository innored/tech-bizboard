-- 개발비·유지보수 원가(수익 = 공급가 - 원가). 앱이 접속 시 자동으로 추가한다(Database::ensureRevenueColumns). 기록용.
ALTER TABLE tb_team_revenues
  ADD COLUMN cost_krw BIGINT NOT NULL DEFAULT 0 AFTER tech_fee_rate,
  ADD COLUMN cost_note VARCHAR(255) NOT NULL DEFAULT '' AFTER cost_krw;
ALTER TABLE tb_revenue_templates
  ADD COLUMN cost_krw BIGINT NOT NULL DEFAULT 0 AFTER tech_fee_rate,
  ADD COLUMN cost_note VARCHAR(255) NOT NULL DEFAULT '' AFTER cost_krw;
