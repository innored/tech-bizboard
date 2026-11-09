-- 수익 탭: 솔루션구분·집행금액·테크피
-- Database::ensureRevenueColumns() 가 접속 시 자동으로 적용한다(컬럼이 처음 생길 때만 백필). 기록용.

ALTER TABLE tb_team_revenues
  ADD COLUMN solution_group VARCHAR(10) NOT NULL DEFAULT '' AFTER solution_id,
  ADD COLUMN campaign_spend_krw BIGINT NOT NULL DEFAULT 0 AFTER solution_group,
  ADD COLUMN tech_fee_rate DECIMAL(5,2) NULL AFTER campaign_spend_krw;

ALTER TABLE tb_revenue_templates
  ADD COLUMN solution_group VARCHAR(10) NOT NULL DEFAULT '' AFTER solution_id,
  ADD COLUMN campaign_spend_krw BIGINT NOT NULL DEFAULT 0 AFTER solution_group,
  ADD COLUMN tech_fee_rate DECIMAL(5,2) NULL AFTER campaign_spend_krw;

UPDATE tb_team_revenues     SET solution_group = 'GENERAL' WHERE service_category = '솔루션' AND solution_id = 'sr_royalcanin';
UPDATE tb_team_revenues     SET solution_group = 'ADTECH'  WHERE service_category = '솔루션' AND solution_id = 'lift_optima';
UPDATE tb_revenue_templates SET solution_group = 'GENERAL' WHERE service_category = '솔루션' AND solution_id = 'sr_royalcanin';
UPDATE tb_revenue_templates SET solution_group = 'ADTECH'  WHERE service_category = '솔루션' AND solution_id = 'lift_optima';
