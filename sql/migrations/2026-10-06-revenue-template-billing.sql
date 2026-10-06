-- 수입 반복 템플릿에 유/무상(billing_type)·정상가(list_value_krw) 추가
-- 수입 등록 모달과 반복 설정 모달 입력 필드 통일
ALTER TABLE tb_revenue_templates
  ADD COLUMN billing_type ENUM('PAID','FREE') NOT NULL DEFAULT 'PAID' AFTER service_category,
  ADD COLUMN list_value_krw INT NOT NULL DEFAULT 0 AFTER supply_krw;
