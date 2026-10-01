# 📄 팀 전용 기안서 자동 생성 & 수입/지출 대시보드 솔루션

## 1. 프로젝트 개요 (Overview)
- **프로젝트명 (권장 폴더명):**  `tech-bizboard`
- **목적:**
  1. 매월/매년 반복되는 팀 지출 기안서 작성을 자동화하고, 기존 사내 결재 시스템으로의 복사-붙여넣기(C&P) 생산성 극대화.
  2. 회계팀의 엄격한 최종 정산과 별개로, **우리 팀 차원의 대략적인 수입/지출 흐름 및 손익(Margin) 모니터링**.
- **핵심 정책:**
  - **대략적인 금액 추적:** 해외 결제건은 결제일 기준 **매매기준율 환율만 곱해 대략적인 원화 금액**을 계산 및 표시 (원화 단위 절사/반올림 처리).
  - **입력 최소화:** 지출은 매월/매년 초안 자동 생성, 수입은 프로젝트 단위 간편 수기 입력.

---

## 2. 주요 관리 항목 및 발생 패턴

### A. 지출 (Expenses)
1. **월간 정기 지출 (매월 1일 초안 자동 생성):**
   - `KINX iXCloud 서버 사용료` (담당: 베리 / KRW / 수기결제)
   - `Cursor AI, Claude 구독료` (담당: 단테 / USD $200 / 개인계정 / 자동결제)
   - `Rocket API, X Developer 등` (담당: 루시 / USD, EUR Startup Plan €99 등 / developer 계정 / 자동결제)
   - `Google Gemini API` (담당: 단테 / USD / developer 계정 / 자동결제)
   - `팀 운영비 정산` (담당: 베리 / KRW / 수기결제)
2. **연간/비정기 지출 (만료 D-30 / D-14 작성 알림):**
   - `애플 개발자 계정 갱신` (연간 / USD $99)
   - `도메인 및 SSL 인증서 갱신` (N년 비정기)

### B. 수입 (Revenues)
- **프로젝트/계약 기반 수입 (수기 간편 입력):**
  - 입력 항목: 프로젝트명, 거래처(선택), 금액(KRW), 입금(예정)일, 담당자, 상태(`입금 예정` / `입금 완료`)

---

## 3. 핵심 기능 요구사항 (Functional Requirements)

### ① 기안서 자동 작성 & 원클릭 복사 (C&P)
- **자동 생성:** 매월 1일 고정 지출 항목의 기안서 초안 자동 생성.
- **환율 연동:** 해외 결제건(USD, EUR)은 결제일 기준 환율 API(수출입은행/한국은행 등)를 호출하여 `외화 × 환율`로 예상 원화 금액 산출.
- **원클릭 C&P:** 항목별 **[제목 복사]**, **[본문 복사]** 버튼을 제공하여 클릭 한 번으로 사내 결재 시스템에 붙여넣을 수 있게 함.

### ② 프로젝트 수입 간편 등록
- 모달 또는 간편 폼을 통해 프로젝트 수주/정산 발생 시 10초 내 입력 가능하도록 구성.

### ③ 팀 손익 대시보드
- **기간 필터:** 월별, 분기별, 반기별, 연별 조회 가능.
- **요약 카드:** `[총 수입]` - `[총 지출]` = `[팀 순손익 (Margin)]`
- **시각화:** 월별 수입 vs 지출 비교 차트 (스파이크 지출 및 손익 곡선 표시).

---

## 4. 데이터베이스 스키마 (Minimal DB Schema)

```sql
-- 1. 지출 템플릿 (월간/연간/비정기)
CREATE TABLE tb_expense_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    assignee VARCHAR(50) NOT NULL,
    account_info VARCHAR(100),
    cycle_type ENUM('MONTHLY', 'YEARLY', 'IRREGULAR') NOT NULL,
    currency VARCHAR(10) DEFAULT 'KRW',
    default_amount DECIMAL(10, 2),
    payment_type ENUM('AUTO', 'MANUAL'),
    next_renewal_date DATE,
    note TEXT
);

-- 2. 실제 지출 내역 (대시보드 집계용)
CREATE TABLE tb_draft_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT,
    target_year_month VARCHAR(7) NOT NULL, -- YYYY-MM
    draft_title VARCHAR(200) NOT NULL,
    currency VARCHAR(10) NOT NULL,
    amount_foreign DECIMAL(10, 2),
    exchange_rate DECIMAL(10, 2),
    amount_krw INT NOT NULL,               -- 대략적인 원화 금액
    payment_date DATE,
    status ENUM('DRAFT', 'COPIED', 'DONE') DEFAULT 'DRAFT',
    FOREIGN KEY (template_id) REFERENCES expense_templates(id)
);

-- 3. 프로젝트 수입 내역
CREATE TABLE tb_team_revenues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_name VARCHAR(150) NOT NULL,
    client_name VARCHAR(100),
    amount_krw INT NOT NULL,               -- 대략적인 원화 수입
    status ENUM('PENDING', 'COMPLETED') DEFAULT 'COMPLETED',
    received_date DATE NOT NULL,
    assignee VARCHAR(50) NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

---

## 5. 배포/마이그레이션 안내 (feature/revenue-service-type-attachments)

### 2026-10-01 — 서비스구분 & 증빙 파일 기능

**DB 마이그레이션 (1회 적용, 공유 DB 주의)**

`sql/migrations/2026-10-01-revenue-service-type.sql` 을 운영 DB(`adtech_team`)에 **1회** 실행해야 한다.
이 DB는 dev 와 prod 가 공유하므로 **마이그레이션 실행 즉시 dev 환경도 함께 영향** 받는다.
배포 전 백업 또는 팀 공지 후 적용 권장.

**코드 배포**

- PHP: `src/`, `public/proc/`, `view/` 변경 파일 일괄 운영 docroot 반영
- JS:  `public/asset/js/revenues.js`, `public/asset/js/dashboard.js` 운영 docroot 반영
- 업로드 저장 디렉터리(`public/uploads/receipts/` 또는 설정된 경로) 쓰기 권한 확인