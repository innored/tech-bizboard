# TechBizBoard

테크본부 팀 전용 **지출 기안서 자동 생성 + 수입/지출 손익 대시보드** 웹앱입니다.

반복되는 팀 지출(서버비·AI 구독료·API 요금 등)의 결재 기안서 초안을 자동으로 만들어 그룹웨어에 **원클릭 복사(C&P)**하고, 수입은 간편 입력해 **팀 차원의 대략적 손익(Margin)**을 봅니다. 회계팀의 최종 정산과는 별개인 "대략치" 추적이 목적입니다.

## 핵심 정책

- **대략적인 금액 추적** — 해외 결제건은 결제일 기준 매매기준율 환율만 곱해 대략적인 원화를 계산·표시(원 단위 반올림). 환율은 결제일 기준 1회 스냅샷 후 유지한다.
- **입력 최소화** — 지출은 월간/연간 초안 자동 생성, 수입은 프로젝트 단위 간편 수기 입력.

## 기술 스택

- **PHP 8+** (`declare(strict_types=1)`, PSR 스타일). 빌드 도구·프론트 프레임워크 없음
- **MySQL** (`adtech_team` 공용 DB) + PDO. CLI 테스트만 SQLite로 폴백
- **Vanilla JS / CSS** + `design-source` 디자인 시스템(복사본)
- **ECharts** (차트), **air-datepicker** (날짜 선택)
- 인증: **INNORED Account Hub SSO** (OIDC 클라이언트만, 자체 로그인 폼 없음)
- 환율: 팀 자체 **환율 API** (한국수출입은행 매매기준율 래퍼) 서버사이드 호출

## 디렉터리 구조

```
tech-bizboard/
├── .env / .env.example   # 비밀값(DB·SSO). .env 는 .gitignore + .htaccess 로 차단
├── .htaccess             # 라우팅 + 소스 파일 은닉 (보안 핵심)
├── lib/env_loader.php    # .env 로더
├── src/                  # 도메인 로직 (Provider / Processor / Handler)
├── public/               # 웹 노출 지점 (페이지 + proc/ API + asset)
│   ├── *.php             #   페이지 진입점 (index, drafts, revenues, settings, login)
│   ├── proc/*.php        #   JSON API 핸들러
│   └── sso/*.php         #   SSO 콜백 · 로그아웃
├── view/                 # 화면 템플릿 (_header / _footer + 각 페이지)
├── storage/              # schema.sql / seed / 환율 캐시 (실데이터 git 제외)
├── tests/                # 순수 로직 CLI assert 테스트
├── cron/                 # 선택적 월간 초안 생성 (crontab 예시)
└── docs/                 # PRD(todo.md) + 설계 계획/스펙
```

## 아키텍처

### 라우팅 (`.htaccess`)

DocumentRoot 는 상위 `lucy/` 이고 앱은 `/tech-bizboard/` 경로로 열립니다. `mod_rewrite` 로 URL 을 매핑합니다.

| URL | 실제 파일 |
|-----|-----------|
| `/tech-bizboard/` | `public/index.php` (대시보드) |
| `/tech-bizboard/drafts` `/revenues` `/settings` `/login` | `public/*.php` |
| `/tech-bizboard/api/<name>` | `public/proc/<name>.php` (JSON API) |
| `/tech-bizboard/sso/` | `public/sso/index.php` (OIDC 콜백) |
| `/tech-bizboard/asset/...` | `public/asset/...` |

- 소스 파일명 직접 요청(`.php`, `/src/`, `/view/` 등)은 **404 로 숨김**
- `.env` · `.sql` · `.db` · 닷파일은 **어떤 경로로도 차단**

### 부트스트랩 (`src/bootstrap.php`)

모든 진입점이 `bootstrap.php` 를 require 합니다.

1. `.env` 로드 → 모든 설정을 환경변수에서 읽음 (`tbb_config()`)
2. 클래스 로드
3. `tbb_drafts()` 등 팩토리 함수로 Provider 조립(DI)
4. `tbb_guard()` 가 세션 + SSO 로그인 강제

### 계층 구조

PHP 페이지·API 는 로직을 직접 작성하지 않고 `src/` 의 Provider 에 위임합니다.

| 계층 | 파일 | 책임 |
|------|------|------|
| DB | `Database.php` | PDO 싱글톤, MySQL/SQLite 양쪽 대응 마이그레이션, 시드 |
| 인증 | `SsoHandler.php` | Account Hub OIDC, 세션, CSRF, role(admin/member) |
| 환율 | `ExchangeRateProvider.php` | 날짜·통화별 환율 조회 + 로컬 캐시 폴백 |
| 원화 계산 | `KrwAmountProcessor.php` | `외화 × (rate / unit)` 반올림 |
| 기안 텍스트 | `DraftTextProcessor.php` | 제목/본문 플레이스홀더 치환, 날짜 이동 |
| 지출 템플릿 | `ExpenseTemplateProvider.php` | 지출 템플릿 CRUD, 갱신 임박 조회 |
| 지출 기안 | `DraftExpenseProvider.php` | 월간 초안 생성, 결제 항목 가격계산, 상태 관리 |
| 수입 | `TeamRevenueProvider.php` | 수입 CRUD, 부가세 계산, 월잠금 |
| 수입 템플릿 | `RevenueTemplateProvider.php` | 반복 수입 템플릿 |
| 대시보드 | `DashboardProvider.php` | 기간별 합계·월별 시계열 |

## 화면

| 화면 | 경로 | 역할 |
|------|------|------|
| 대시보드 | `/tech-bizboard/` | 기간 필터(월/분기/반기/연), 요약 카드(수입−지출=손익), 월별 수입/지출 차트, D-30/D-14 갱신 알림 배지 |
| 기안서 | `/tech-bizboard/drafts` | 월 선택, 템플릿에서 기안 작성, 결제 항목별 환율 조회, [제목 복사]/[본문 복사], 상태 변경 |
| 수입 | `/tech-bizboard/revenues` | 간편 등록 모달, 공급가/부가세 자동 계산, 상태 토글(예정/완료) |
| 설정 | `/tech-bizboard/settings` | 지출/수입 템플릿 CRUD, 다음 갱신일 |

## 데이터 모델

테이블은 `tb_` 접두사 + snake_case 복수형, PK 는 `id`, FK 는 `[단수]_id` 규칙을 씁니다.

- `tb_expense_templates` — 지출 템플릿 (월간/연간/비정기)
- `tb_draft_expenses` — 실제 지출 기안 (대시보드 집계용)
- `tb_draft_payment_items` — 기안 1장당 결제/충전 항목 여러 줄
- `tb_revenue_templates` — 반복 수입 템플릿
- `tb_team_revenues` — 프로젝트 수입 내역 (공급가·부가세·합계)

스키마는 `storage/schema.sql`(SQLite) / `storage/schema.mysql.sql`(MySQL)에 있고, `Database::migrate()` 가 부팅 시 테이블·컬럼을 자동으로 맞춥니다(idempotent 마이그레이션).

## 설정

설정과 비밀값은 코드/웹루트에 두지 않고 **`.env`** 에서만 읽습니다. `.env.example` 을 복사해 채우세요.

```bash
cp .env.example .env
```

| 키 | 설명 |
|----|------|
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` | MySQL 접속 (값이 없으면 SQLite 폴백) |
| `EXCHANGE_URL` | 팀 환율 API 엔드포인트 |
| `SSO_REQUIRE_LOGIN` | `1`이면 로그인 강제 |
| `SSO_PROVIDER_URL` / `SSO_CLIENT_ID` / `SSO_REDIRECT_URI` / `SSO_HOME_URL` | Account Hub SSO 설정 (secret 없음) |

## 테스트

순수 로직만 CLI assert 로 검증합니다(화면·실환율 호출은 수동).

```bash
# 개별 실행
php tests/draft_generate_test.php

# 전체 실행
for f in tests/*_test.php; do php "$f" && echo "PASS $f" || echo "FAIL $f"; done
```

## 개발 규칙

- 스타일 원본 `design-source` 는 수정 금지(복사만)
- 색·radius 는 CSS 변수만 사용(hex·Tailwind 금지). 모바일 브레이크포인트 `900px`
- 클래스 파일은 PascalCase + `Provider`/`Processor`/`Handler` 접미사. 헬퍼는 snake_case, 뷰는 snake_case
- 새 PHP 파일 헤더에 한국어 설명 + `@date` + `@link`. 소스 주석은 한국어
- 커밋은 사용자가 요청할 때만

## 범위 밖 (1차 제외)

- 그룹웨어 자동 로그인/자동 제출
- 회계 전표·세금계산서·증빙 첨부
- 메일/슬랙/구글챗 알림
- 이미 스냅샷된 초안 환율의 매일 재조회·덮어쓰기
