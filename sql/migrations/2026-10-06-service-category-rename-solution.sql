-- 서비스 구분 '솔루션 서비스' → '솔루션' 으로 명칭 변경(저장값 통일)
UPDATE tb_team_revenues    SET service_category = '솔루션' WHERE service_category = '솔루션 서비스';
UPDATE tb_revenue_templates SET service_category = '솔루션' WHERE service_category = '솔루션 서비스';
