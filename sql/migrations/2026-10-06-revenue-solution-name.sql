-- 서비스구분이 '솔루션 서비스'일 때 선택하는 '우리 솔루션' 이름
-- 솔루션 목록은 account-hub API에서 받아 드롭다운으로 제공
ALTER TABLE tb_team_revenues
  ADD COLUMN solution_name VARCHAR(100) NOT NULL DEFAULT '' AFTER service_category;

ALTER TABLE tb_revenue_templates
  ADD COLUMN solution_name VARCHAR(100) NOT NULL DEFAULT '' AFTER service_category;
