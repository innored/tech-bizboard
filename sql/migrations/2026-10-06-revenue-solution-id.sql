-- '우리 솔루션'의 안정적 식별자(client_id) 저장. solution_name은 표시용 스냅샷으로 유지.
ALTER TABLE tb_team_revenues
  ADD COLUMN solution_id VARCHAR(100) NOT NULL DEFAULT '' AFTER solution_name;

ALTER TABLE tb_revenue_templates
  ADD COLUMN solution_id VARCHAR(100) NOT NULL DEFAULT '' AFTER solution_name;
