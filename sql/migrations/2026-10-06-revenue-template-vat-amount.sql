-- 수입 반복 템플릿에 부가세(vat_krw)·총액(amount_krw) 추가
-- 수입/반복 금액 입력을 공급가·부가세·총액 3칸(자동계산+수정 가능)으로 통일
ALTER TABLE tb_revenue_templates
  ADD COLUMN vat_krw INT NOT NULL DEFAULT 0 AFTER supply_krw,
  ADD COLUMN amount_krw INT NOT NULL DEFAULT 0 AFTER vat_krw;
