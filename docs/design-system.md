# LinkBuilder Design System

> UTM · AppsFlyer OneLink 생성 SaaS를 위한 디자인 시스템 문서 (AI/개발자 참조용)

| 리소스 | 경로 |
|--------|------|
| 스타일 가이드 (미리보기) | [`../html/style-guide.html`](../html/style-guide.html) |
| 코어 CSS | [`../asset/css/design-system.css`](../asset/css/design-system.css) |
| 브랜드 팔레트 | [`../asset/css/palettes.css`](../asset/css/palettes.css) |
| 라이트/다크 테마 | [`../asset/css/themes.css`](../asset/css/themes.css) |
| Datepicker 테마 | [`../asset/css/air-datepicker-theme.css`](../asset/css/air-datepicker-theme.css) |
| 컴포넌트 JS | [`../asset/js/design-system.js`](../asset/js/design-system.js) |
| 테마 전환 JS | [`../asset/js/theme-switcher.js`](../asset/js/theme-switcher.js) |
| 팔레트 전환 JS | [`../asset/js/palette-switcher.js`](../asset/js/palette-switcher.js) |

---

## 0. 개요 (Context for AI)

- **제품**: 마케팅 링크(GA4 UTM, AppsFlyer OneLink)를 생성/검증/대량생성/관리하는 웹 SaaS
- **핵심 기능**: UTM Builder, AppsFlyer OneLink, 파라미터 매핑, 대량생성(CSV/Excel 업로드), 링크 파싱/검증기, 히스토리
- **기술 스택**: PHP + MySQL (백엔드), **Vanilla JS / Vanilla CSS** (프론트엔드)
- **CSS 방식**: 순수 CSS 컴포넌트 라이브러리 — **빌드·프레임워크 없음**
- **폰트**: **Pretendard GOV Variable** — 본문/UI·링크·파라미터 코드 표기까지 동일 폰트
- **디자인 톤**: 심플·미니멀 / SaaS 신뢰감 (블루 계열 기본, 팔레트·테마 교체 가능)

### 색상 아키텍처 (2축)

브랜드 accent와 UI 표면은 **독립**이다. 서로 충돌하지 않는다.

| 축 | 담당 파일 | `html` 속성 / 방식 | 바뀌는 토큰 |
|----|-----------|-------------------|-------------|
| **테마** | `themes.css` | `data-theme="light\|dark"` | `--bg-page`, `--surface`, `--ink-*`, 시맨틱 bg, shadow |
| **팔레트** | `palettes.css` + JS | `data-palette="navy"` 또는 inline `--brand-*` | `--brand-50` ~ `--brand-950` |

```
html[data-theme="dark"][data-palette="teal"]
  → 어두운 표면 + 틸 브랜드 버튼/링크
```

> **AI 코드 생성 규칙**
> 1. 색상·radius·shadow는 **CSS 변수**(`var(--brand-600)`, `var(--surface)` 등)만 사용. 임의 hex 금지.
> 2. UI는 **시맨틱 클래스**(`.btn`, `.input`, `.switch` …) 사용. Tailwind 유틸(`h-10`, `bg-blue-500` 등) 금지.
> 3. 카드·인풋 배경은 `#fff` 대신 `var(--surface)`, 페이지 배경은 `var(--bg-page)`.
> 4. brand tinted hover/배지는 `var(--brand-subtle)`, `var(--brand-muted-bg)` 등 **테마 연동 토큰** 사용.
> 5. 새 컴포넌트는 `design-system.css`에 동일 네이밍 규칙으로 추가.

---

## 1. 셋업 (Setup)

### 1.1 최소 구성 (운영 페이지)

컴포넌트만 쓸 때는 **폰트 + `design-system.css` + `design-system.js`** 만으로 충분하다.

```html
<link rel="stylesheet" href="/asset/fonts/PretendardGOV/pretendardvariable-gov.css" />
<link rel="stylesheet" href="/asset/css/design-system.css" />
<link rel="stylesheet" href="/asset/css/themes.css" />   <!-- 라이트/다크 지원 시 -->
```

```html
<script src="/asset/js/design-system.js"></script>
```

운영 환경에서 테마를 고정할 때:

```html
<html lang="ko" data-theme="light">
<!-- 또는 -->
<html lang="ko" data-theme="dark" data-palette="navy">
```

### 1.2 전체 구성 (스타일 가이드 · 미리보기)

테마/팔레트 전환 UI까지 포함할 때 CSS·JS 로드 순서:

```html
<!-- head -->
<link rel="stylesheet" href="/asset/fonts/PretendardGOV/pretendardvariable-gov.css" />
<link rel="stylesheet" href="/asset/css/design-system.css" />
<link rel="stylesheet" href="/asset/css/palettes.css" />
<link rel="stylesheet" href="/asset/css/themes.css" />

<!-- FOUC 방지: 테마 + 팔레트 복원 (head 상단 인라인) -->
<script>
(function () {
  try {
    var root = document.documentElement;

    var theme = localStorage.getItem('lb-theme') || 'light';
    if (theme === 'system') {
      theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    root.setAttribute('data-theme', theme);

    var raw = localStorage.getItem('lb-palette');
    if (!raw) return;
    if (raw.charAt(0) === '{') {
      var data = JSON.parse(raw);
      if (data.mode === 'custom' && data.scale) {
        Object.keys(data.scale).forEach(function (k) {
          root.style.setProperty('--brand-' + k, data.scale[k]);
        });
      } else if (data.mode === 'preset' && data.id && data.id !== 'blue') {
        root.setAttribute('data-palette', data.id);
      }
    } else if (raw !== 'blue') {
      root.setAttribute('data-palette', raw);
    }
  } catch (e) {}
})();
</script>
```

```html
<!-- body 끝 -->
<script src="/asset/js/design-system.js"></script>
<script src="/asset/js/theme-switcher.js"></script>
<script src="/asset/js/palette-switcher.js"></script>
```

스타일 가이드 [`style-guide.html`](../html/style-guide.html)는 우측 **플로팅 패널**에서 테마(라이트/다크/시스템) + SaaS 프리셋 칩 + 커스텀 컬러피커로 전체 UI를 live 미리본다.

### 1.3 localStorage 키

| 키 | 값 예시 | 용도 |
|----|---------|------|
| `lb-theme` | `light` · `dark` · `system` | 테마 모드 |
| `lb-palette` | `{"mode":"preset","id":"navy"}` | 프리셋 팔레트 |
| `lb-palette` | `{"mode":"custom","primary":"#7C3AED","scale":{…}}` | 커스텀 Primary + 생성된 11단계 |

### 1.4 Datepicker (선택)

[Air Datepicker](https://air-datepicker.com/) v3.6.0 UMD(MIT), 자체 호스팅 `asset/vendor/air-datepicker/`.

```html
<!-- head -->
<link rel="stylesheet" href="/asset/vendor/air-datepicker/air-datepicker.css" />
<link rel="stylesheet" href="/asset/css/air-datepicker-theme.css" />
<!-- body 끝 -->
<script src="/asset/vendor/air-datepicker/air-datepicker.js"></script>
<script src="/api/holidays/holidays.bundle.js"></script>   <!-- 공휴일: data.go.kr 정적 번들 -->
<script src="/asset/js/air-datepicker-init.js"></script>
```

- `air-datepicker-theme.css`는 `--adp-*` → 디자인 토큰(`--brand-*`, `--surface`, `--ink-*`)에 매핑. **라이트/다크·팔레트 변경에 자동 연동.**
- 공휴일 데이터는 `api/holidays/holidays.bundle.js`가 `window.KRHolidays` API를 제공. `air-datepicker-init.js`의 `onRenderCell`이 캘린더에 `-holiday-` 클래스·툴팁을 붙인다. 갱신은 `api/holidays/cron/warm_holidays.php` 또는 `refresh.php` — [특일 정보 API](https://www.data.go.kr/data/15012690/openapi.do) 참고.

### 1.5 기타

- 폰트: `asset/fonts/PretendardGOV/PretendardGOVVariable.woff2` (Variable, weight `45~920`)
- font-family: **`"Pretendard GOV Variable"`** (`--font-sans`)
- 스타일 가이드는 `html/` 기준 → `../asset/...` 상대경로
- PHP 서빙 시 `woff2` MIME(`font/woff2`) 설정

---

## 2. 디자인 토큰 (CSS Variables)

토큰 정의 위치:

| 레이어 | 파일 | 설명 |
|--------|------|------|
| Brand · Radius · Shadow · Control | `design-system.css` `:root` | 기본 brand 블루, 공통 sizing |
| Surface · Theme | `themes.css` | 라이트 기본 + `[data-theme="dark"]` 오버라이드 |
| Brand presets | `palettes.css` | `[data-palette="…"]` 프리셋 5종 |
| Custom brand | `palette-switcher.js` | inline `--brand-*` (Primary → 11단계 자동 생성) |

### 2.1 Brand — `--brand-*` (11단계)

기본 Primary는 **신뢰 블루** `#2563eb` (`--brand-600`). 팔레트/컬러피커로 전체 scale 교체 가능.

| 변수 | Hex (기본) | 용도 |
|------|------------|------|
| `--brand-50` | `#eff5ff` | 연한 tinted 배경 (팔레트별 상이) |
| `--brand-100` | `#dbe8fe` | 보더·배지 보조 |
| `--brand-500` | `#3b76f6` | 포커스 링, 아이콘 |
| **`--brand-600`** | **`#2563eb`** | **Primary — 버튼/링크/액티브** |
| `--brand-700` | `#1d4fd7` | Primary hover |
| `--brand-800` | `#1e40af` | Primary active |
| `--brand-900` | `#1e3a8a` | 진한 accent |

( `--brand-200/300/400/950` 포함 )

**컴포넌트에서 직접 `--brand-50`을 hover 배경으로 쓰지 말 것** → 다크 모드에서 어색해질 수 있음. 대신 `--brand-subtle` / `--brand-muted-*` 사용.

### 2.2 Surface · Theme — `themes.css`

| 변수 | 라이트 | 다크 | 용도 |
|------|--------|------|------|
| `--bg-page` | `#f8fafc` | `#0b1220` | `<body>` 배경 |
| `--surface` | `#ffffff` | `#1e293b` | 카드·인풋·드롭다운 패널 |
| `--surface-raised` | `#ffffff` | `#334155` | (예비) elevated surface |
| `--brand-subtle` | brand 8% mix | brand 16% mix | 행 hover, 옵션 hover |
| `--brand-subtle-hover` | brand 14% mix | brand 24% mix | dropzone hover 등 |
| `--brand-muted-bg` | `--brand-50` | brand tinted dark | secondary 버튼, badge-brand |
| `--brand-muted-bg-hover` | `--brand-100` | brand tinted dark | secondary hover |
| `--brand-muted-text` | `--brand-700` | 밝은 brand tint | secondary 텍스트 |
| `--brand-muted-border` | `--brand-100` | brand tinted border | alert-info, listbox-all |
| `--on-primary` | `#ffffff` | `#ffffff` | Primary·Danger 버튼 텍스트 |
| `--code-bg` | `#0f172a` | `#020617` | `.codeblock` 배경 |
| `--code-fg` | `--brand-200` | `--brand-200` | `.codeblock` 텍스트 |
| `--code-val` | `#ffffff` | `#ffffff` | `.codeblock .val` |
| `--tooltip-bg` | `#0f172a` | `#0f172a` | tooltip 배경 (고정 다크) |
| `--tooltip-fg` | `#f8fafc` | `#f8fafc` | tooltip 텍스트 |
| `--info-bg` | `#e0f2fe` | info tinted | stat-icon `is-info` |

#### 테마 전환

| 모드 | 설정 | 비고 |
|------|------|------|
| 라이트 | `data-theme="light"` | 기본 |
| 다크 | `data-theme="dark"` | `--ink-*` invert, 시맨틱 bg 어두운 톤, shadow 강화 |
| 시스템 | JS `LinkBuilderTheme.apply('system')` | `prefers-color-scheme` 추적 |

```js
LinkBuilderTheme.apply('dark');     // 'light' | 'dark' | 'system'
LinkBuilderTheme.getMode();         // 저장된 모드 (resolved 아님)
```

다크 모드에서 `--ink-50`~`--ink-900` **값이 invert**된다. (예: `--ink-900` → `#f8fafc` 밝은 텍스트)

### 2.3 Neutral — `--ink-*` (Slate)

라이트 모드 기준. 다크에서는 `themes.css`가 invert.

| 변수 | Hex (라이트) | 용도 |
|------|--------------|------|
| `--ink-50` | `#f8fafc` | (구) 페이지 bg → **`--bg-page` 사용 권장** |
| `--ink-200` | `#e2e8f0` | 보더 |
| `--ink-300` | `#cbd5e1` | 인풋 보더 |
| `--ink-400` | `#94a3b8` | placeholder, 캡션 |
| `--ink-500` | `#64748b` | 보조 텍스트 |
| `--ink-700` | `#334155` | 본문 |
| `--ink-800` | `#1e293b` | 기본 텍스트 |
| `--ink-900` | `#0f172a` | 제목 |

### 2.4 Semantic

| 변수 | Hex (라이트) | 용도 |
|------|--------------|------|
| `--success` / `-bg` / `-bd` | `#059669` … | 검증 성공 |
| `--warning` / `-bg` / `-bd` | `#d97706` … | 주의 |
| `--danger` / `-bg` / `-bd` | `#dc2626` … | 오류, 삭제 |
| `--info` | `#0284c7` | 정보, 토요일(캘린더) |
| `--alert-success-title` 등 | themes.css | alert 본문·제목 (다크 대응) |

### 2.5 Radius · Shadow · Font · Sizing

| 변수 | 값 |
|------|-----|
| `--r-sm` / `--r-md` / `--r-lg` / `--r-card` / `--r-full` | 6 / 8 / 10 / 14px / pill |
| `--sh-card` | 카드 그림자 (다크에서 재정의) |
| `--sh-float` | 모달·플로팅 그림자 |
| `--sh-focus` | `color-mix(in srgb, var(--brand-600) 25%, transparent)` |
| `--sh-focus-danger` | danger 포커스 링 |
| `--font-sans` / `--font-mono` | Pretendard GOV Variable |
| `--control-h` | `40px` |

- **Spacing**: 4px 배수(8·16·24·32px)를 컴포넌트 내부에서 직접 사용.

### 2.6 브랜드 팔레트 — `palettes.css` + `palette-switcher.js`

SaaS **신뢰감** 중심 프리셋 5종 + **커스텀 컬러피커**(Primary 하나 → 50~950 자동 생성).

| ID | 이름 | Primary | 톤 |
|----|------|---------|-----|
| `blue` (기본) | 신뢰 블루 | `#2563eb` | 기본 `:root`, `data-palette` 생략 가능 |
| `navy` | 엔터프라이즈 | `#1e40af` | B2B·금융 |
| `teal` | 프로페셔널 | `#0d9488` | 핀테크·데이터 |
| `sky` | 클린 스카이 | `#0284c7` | 협업·생산성 |
| `indigo` | B2B 인디고 | `#4f46e5` | 업무용 SaaS |

```html
<html data-palette="navy">
```

```js
LinkBuilderPalette.apply('teal');              // 프리셋
LinkBuilderPalette.applyCustom('#4f46e5');     // 커스텀 Primary
LinkBuilderPalette.generateScale('#2563eb');   // scale 객체 미리보기
LinkBuilderPalette.list;                       // 프리셋 배열
```

**커스텀 동작**: `data-palette` 제거 → `:root`/inline `--brand-*` 11개 `setProperty` → `localStorage`에 scale 저장.

**프리셋 추가**: `palettes.css`에 `[data-palette="id"] { --brand-50: … }` + `palette-switcher.js`의 `PALETTES` 배열.

---

## 3. 타이포그래피 (Typography)

폰트: `--font-sans`(Pretendard GOV Variable) / `--font-mono`도 **동일하게 Pretendard GOV Variable**(링크·파라미터 코드까지 통일). `<body>`에 기본 적용됨.

| 역할 | 클래스 | 크기/굵기 |
|------|--------|-----------|
| Display | `.text-display` | 36 / 800 |
| Heading 1 | `.text-h1` | 24 / 700 |
| Heading 2 | `.text-h2` | 18 / 600 |
| Body | `.text-body` | 16 / 400 |
| Small | `.text-sm` | 14 |
| Caption | `.text-xs` | 12 |
| 보조색 | `.text-muted`(ink-500) / `.text-faint`(ink-400) | — |
| 코드/링크 | `.mono`, `.codeblock` | Pretendard GOV (sans와 동일) |

- 생성된 링크 결과: `.codeblock` (`--code-bg` + `--code-fg`), 값 강조는 `<span class="val">`(`--code-val`).

---

## 4. 컴포넌트 클래스 (Component Recipes)

> 아래 마크업을 **그대로 복사**해 사용. 이것이 표준 패턴.

### 4.1 Buttons — `.btn` (높이 40px, radius 8px)
```html
<button class="btn btn-primary">링크 생성</button>
```
- Variant: `btn-primary`( `--on-primary` 텍스트 ) · `btn-secondary`( `--brand-muted-*` ) · `btn-outline`( `--surface` ) · `btn-ghost` · `btn-danger`
- Size: `btn-sm`(32) · (기본 40) · `btn-lg`(48) · `btn-icon`(정사각 아이콘 버튼)
- State: `disabled` 속성 / 로딩은 `class="btn ... is-loading"` + 스피너 SVG(자동 회전)
- 아이콘은 `<svg>`를 버튼 안에 넣으면 크기·간격 자동 처리.

### 4.2 Text Input — `.input` (높이 40px)
```html
<div class="field">
  <label class="label">라벨 <span class="req">*</span></label>
  <input type="text" class="input" placeholder="…" />
  <p class="hint">헬퍼 텍스트</p>
</div>
```
- **Error**: `class="input input-error"` + `<p class="hint hint-error">` (아이콘 포함)
- **Success**: `class="input input-success"`
- **Disabled**: `disabled` 속성
- 필드 세로 간격은 `.field + .field`가 자동(20px). 커스텀 시 `style="margin:0"`.

> **폰트 원칙**: 입력 필드(`.input`, `.textarea`)는 **Pretendard로 통일**(가독성·일관성).
> monospace는 값을 직접 타이핑하는 인풋이 아니라, **생성된 링크 결과 출력**(`.codeblock`)에만 사용한다.
> `.input mono` 조합은 사용하지 않는다.

### 4.3 아이콘 인풋 (Search 등) — `.input-wrap`
```html
<div class="input-wrap">
  <svg class="icon-left">…</svg>
  <input type="search" class="input has-icon-left" placeholder="검색…" />
</div>
```
- 우측 아이콘: `.icon-right` + `.input.has-icon-right`. 성공 체크는 `.icon-right.is-success`.

### 4.4 Prefix/Suffix Addon — `.input-group`
```html
<div class="input-group">
  <span class="addon addon-prefix">https://</span>
  <input type="text" class="input" placeholder="example.com/landing" />
</div>
```
- 뒤에 붙이는 경우 `addon-suffix` 사용. 그룹 전체가 포커스 링을 받음.

### 4.5 Textarea — `.textarea`
```html
<textarea class="textarea" rows="3" placeholder="…"></textarea>
```

### 4.6 Select — `.select` (커스텀 드롭다운 · 옵션까지 스타일 적용)
> 네이티브 `<select>`의 옵션 목록은 OS가 렌더링해 CSS로 꾸밀 수 없음. 그래서 옵션까지 디자인을 적용하려면 **커스텀 드롭다운**(vanilla JS)을 사용한다. 폼 전송용 값은 `<input type="hidden">`에 자동 반영된다.

```html
<div class="select" data-select>
  <button type="button" class="select-trigger" aria-haspopup="listbox" aria-expanded="false">
    <span class="select-value">google / cpc</span>
    <svg class="chevron">…</svg>
  </button>
  <input type="hidden" name="source_medium" value="google_cpc" />
  <ul class="select-menu" role="listbox">
    <li class="select-option is-selected" data-value="google_cpc">google / cpc</li>
    <li class="select-option" data-value="facebook_paid_social">facebook / paid_social</li>
    …
  </ul>
</div>
```
- `design-system.js`가 `[data-select]`를 자동 초기화(토글·선택·키보드 방향키/Enter/Esc·바깥 클릭 닫기).
- 선택 시 `.select-value` 텍스트 + `hidden` input 값 갱신, 체크 아이콘은 JS가 각 옵션에 자동 주입.
- 값 변경 훅: `root.addEventListener('select:change', e => e.detail.value)`.
- placeholder가 필요하면 `<span class="select-value is-placeholder">선택…</span>`, 초기 `is-selected` 옵션 없이 둔다.
- **폴백**: JS 없이 간단히 쓸 땐 `.select-native > select + .chevron` (네이티브, 옵션은 OS 스타일).

**검색 가능한 Select** — 루트에 `data-searchable`만 추가하면 검색창이 자동 주입됨(옵션 다수·앱 선택 등에 사용).
```html
<div class="select" data-select data-searchable>
  <button type="button" class="select-trigger" aria-haspopup="listbox" aria-expanded="false">
    <span class="select-value is-placeholder">앱을 검색해 선택…</span>
    <svg class="chevron">…</svg>
  </button>
  <input type="hidden" name="app_id" value="" />
  <ul class="select-menu" role="listbox">
    <li class="select-option" data-value="id6446901002">Shopping App — id6446901002</li>
    …
  </ul>
</div>
```
- `.select-option`의 **텍스트 기준**으로 대소문자 무시 필터링. 결과 없으면 “검색 결과가 없습니다” 표시.
- 검색창·빈결과 요소는 JS가 자동 생성(마크업에 직접 넣지 않음). 열면 검색창에 자동 포커스.
- 방향키 탐색은 **보이는 옵션**만 순회.

> ⚠️ `<head>`가 아니라 `</body>` 직전에 `<script src="/asset/js/design-system.js"></script>` 로드 필요.

**검색 + 다중선택 Select** — `data-multiselect`(+`data-searchable`). 선택 항목을 **칩**으로 표시하고 체크박스형 옵션으로 다중 선택.
```html
<div class="select multiselect" data-multiselect data-searchable>
  <button type="button" class="select-trigger" aria-haspopup="listbox" aria-expanded="false">
    <span class="ms-tags" data-placeholder="채널을 선택…"></span>
    <svg class="chevron">…</svg>
  </button>
  <input type="hidden" name="channels" value="" />
  <ul class="select-menu" role="listbox" aria-multiselectable="true">
    <li class="select-option is-selected" data-value="google">google</li>
    <li class="select-option" data-value="naver">naver</li>
    …
  </ul>
</div>
```
- `design-system.js`가 `[data-multiselect]` 자동 초기화. 옵션 클릭 시 **토글**(메뉴 유지), 왼쪽 체크박스로 선택 표시.
- 초기 선택은 옵션에 `is-selected`. 선택 항목은 트리거에 **칩(×로 개별 제거)** 으로 렌더, `hidden` 값은 값들을 **콤마로 결합**.
- 칩 라벨은 옵션 텍스트(또는 `data-label`), 전송 값은 `data-value`.
- 검색·키보드(↑/↓ 이동, Enter 토글, Esc 닫기)는 단일 select와 동일.
- 값 변경 훅: `root.addEventListener('multiselect:change', e => e.detail.values /* 배열 */)`.
- PHP 배열 수신이 필요하면 hidden을 `name="channels[]"` 다중 input으로 렌더하도록 확장 가능.

### 4.7 Checkbox / Radio — `.check`
```html
<label class="check">
  <input type="checkbox" /> <span class="check-label">라벨</span>
</label>
```
- radio는 `type="radio"` + 동일 `name`. 비활성은 라벨에 `.is-disabled` + input `disabled`.
- **커스텀 스타일**: `appearance:none` — 18px, checkbox radius 5px / radio 원형, 미체크 `var(--surface)`+`ink-300` 보더, hover `brand-400`, 체크 `brand-600`+`on-primary` 마크, `:focus-visible` 링.

### 4.8 Toggle Switch — `.switch` (44×24px, 순수 CSS)
```html
<label class="switch">
  <input type="checkbox" checked />
  <span class="track"><span class="thumb"></span></span>
  <span class="switch-label">deep_link 활성화</span>
</label>
```
- ON = `--brand-600`. JS 불필요(체크박스 상태 기반). 비활성은 input `disabled`.

### 4.9 Segmented Control (탭) — `.segmented`
```html
<div class="segmented" role="tablist">
  <button class="is-active">UTM</button>
  <button>OneLink</button>
  <button>대량생성</button>
</div>
```
- 활성 전환은 vanilla JS로 `.is-active` 클래스 토글 (HTML 하단 스크립트 참조).

### 4.10 File Upload (Dropzone) — `.dropzone`
```html
<label class="dropzone">
  <svg>…</svg>
  <span class="dz-title"><strong>파일 선택</strong> 또는 드래그 &amp; 드롭</span>
  <span class="dz-meta">.csv, .xlsx · 최대 10MB</span>
  <input type="file" />
</label>
```
- CSV/Excel 대량생성용. 드래그&드롭 동작은 별도 JS로 연결.

### 4.11 Links — `.link`
```html
<a class="link">기본 링크</a>
<a class="link link-underline">밑줄</a>
<a class="link link-external">문서 열기 <svg>↗</svg></a>
<a class="link-disabled">비활성</a>
```

### 4.12 Badges / Tags — `.badge`
```html
<span class="badge badge-brand">UTM</span>
```
- `badge-brand` · `badge-success` · `badge-warning` · `badge-danger` · `badge-muted`

### 4.13 Alerts — `.alert`
```html
<div class="alert alert-info">
  <svg>…</svg>
  <div><p class="alert-title">안내</p><p class="alert-body">본문</p></div>
</div>
```
- `alert-info` · `alert-success` · `alert-danger` — 다크 모드 텍스트는 `--alert-*-title/body` 토큰

### 4.14 Card — `.card`
```html
<div class="card">…</div>          <!-- background: var(--surface) -->
<div class="card card-float">…</div>
```

### 4.15 Code block (생성된 링크) — `.codeblock` (+ 코드 영역 내 복사 버튼)
```html
<div class="codeblock">
  <button class="code-copy" data-copy aria-label="복사"><svg>⧉</svg></button>
  <code>https://example.com/?utm_source=<span class="val">google</span></code>
</div>
```
- 복사 버튼은 코드 영역 **우상단에 절대배치**(`.code-copy`). `data-copy`가 있으면 `design-system.js`가 초기화.
- 클릭 시 내부 `<code>`(없으면 코드블록 전체)의 텍스트를 클립보드에 복사, **체크 아이콘 1.5초 피드백**(`is-copied`). `navigator.clipboard` → 실패 시 `execCommand` 폴백.
- 복사 대상 텍스트는 반드시 `<code>` 또는 `<pre>`로 감싼다(버튼 텍스트 제외 목적).
- 버튼이 있으면 `:has()`로 우측 패딩이 자동 확보됨.

### 4.16 Tooltip — `.tooltip` + `data-tooltip`
```html
<button class="btn btn-outline tooltip" data-tooltip="클립보드에 복사됩니다">복사</button>
<span class="tooltip tooltip-right" data-tooltip="utm_source: 유입 매체" tabindex="0"><svg>ⓘ</svg></span>
```
- 순수 CSS(`::before/::after`). JS 불필요. hover + `:focus-visible`(키보드)에서 노출.
- 위치: 기본 위 / `tooltip-bottom` / `tooltip-right` / `tooltip-left`.
- 아이콘 등 비인터랙티브 요소에 붙일 땐 `tabindex="0"`으로 키보드 접근 확보.
- 배경 `--tooltip-bg`, 텍스트 `--tooltip-fg` (테마와 무관하게 다크 툴팁). 텍스트는 `data-tooltip` 속성값(한 줄).

### 4.17 Pagination — `.pagination` (숫자 버튼 + "현재/총" 축약)
```html
<nav class="pagination" data-pagination data-total="12" data-current="1" aria-label="페이지 이동">
  <span class="pagination-info"><strong>1</strong> / 12</span>
  <button class="page-btn" data-nav="first" aria-label="맨 앞" disabled><svg>«</svg></button>
  <button class="page-btn" data-nav="prev"  aria-label="이전" disabled><svg>‹</svg></button>
  <button class="page-btn is-active" data-page="1">1</button>
  <button class="page-btn" data-page="2">2</button>
  <button class="page-btn" data-page="3">3</button>
  <button class="page-btn" data-page="4">4</button>
  <button class="page-btn" data-page="5">5</button>
  <span class="ellipsis">…</span>
  <button class="page-btn" data-page="12">12</button>
  <button class="page-btn" data-nav="next" aria-label="다음"><svg>›</svg></button>
  <button class="page-btn" data-nav="last" aria-label="맨 뒤"><svg>»</svg></button>
</nav>
```
- **숫자 버튼(`data-page`)** 으로 페이지 이동, 현재 페이지는 `.is-active`. 생략 구간은 `<span class="ellipsis">…</span>`.
- 총 페이지 표시는 `.pagination-info`에 **`현재 / 총`(예: `1 / 12`)** 로 축약. `data-total` 기준 자동 갱신, 구분선으로 컨트롤과 분리.
- 맨앞/이전/다음/맨뒤는 `data-nav="first|prev|next|last"`, 경계에서 자동 `disabled`.
- 루트에 `data-pagination` + `data-total`(총 페이지) + `data-current`(현재, 기본 1). 모두 **한 행**에 배치.
- 값 변경 훅: `nav.addEventListener('page:change', e => e.detail.page /* , e.detail.total */)` → 서버 조회/렌더에 연결.
- 히스토리·대량생성 결과 목록 하단에 사용.

### 4.19 Date Picker — Air Datepicker (`data-datepicker` / `data-datepicker-range`)
> `air-datepicker-theme.css`가 `--adp-*` → 디자인 토큰(`--surface`, `--brand-*`, `--ink-*`, `--brand-subtle` 등)에 매핑. **라이트/다크·팔레트 변경에 연동.**
```html
<!-- 단일 날짜 -->
<div class="input-wrap">
  <svg class="icon-left">📅</svg>
  <input type="text" class="input has-icon-left" data-datepicker placeholder="날짜 선택" readonly />
</div>

<!-- 기간 (시작 ~ 종료) -->
<div class="input-wrap">
  <svg class="icon-left">📅</svg>
  <input type="text" class="input has-icon-left" data-datepicker-range placeholder="기간 선택" readonly />
</div>
```
- `air-datepicker-init.js`가 `[data-datepicker]`(단일) / `[data-datepicker-range]`(범위)를 자동 초기화. 한국어 로케일 내장, 포맷 `yyyy-MM-dd`(범위는 ` ~ ` 구분), 하단 `오늘/초기화` 버튼.
- `readonly`로 수동 입력을 막아 포맷 일관성 유지(클릭 시 캘린더는 정상 오픈).
- 값 접근: 인스턴스는 `input._adp`에 저장됨. 선택 훅이 필요하면 초기화 옵션에 `onSelect`를 추가.
- 테마는 `.air-datepicker`의 `--adp-*` 변수만 덮음 → 라이브러리 업그레이드에도 커스터마이즈 유지.

**대한민국 공휴일 표시** — `holidays.bundle.js` + `air-datepicker-init.js`의 `onRenderCell`. `air-datepicker.js` 다음, `air-datepicker-init.js` **앞**에 로드:

```html
<script src="/api/holidays/holidays.bundle.js"></script>
```

- 일요일=빨강, 토요일=파랑, 공휴일=빨강(hover 시 공휴일명 `title`). 색상은 `air-datepicker-theme.css`.
- 데이터: [공공데이터포털 특일 정보](https://www.data.go.kr/data/15012690/openapi.do) → `api/holidays/HolidayProvider.php`가 캐시·번들 생성.
- API: `KRHolidays.name('2026-05-25') // '대체공휴일(부처님오신날)'`, `KRHolidays.map(2026)`.
- **갱신**: `api/holidays/cron/warm_holidays.php`(크론) 또는 `api/holidays/refresh.php`(POST). 번들 재생성 시 `holidays.bundle.js` 포함 연도(전년·올해·내년) 갱신.

### 4.22 Summary card — `.stat-card` (대시보드 지표)
```html
<div class="stat-card is-clickable">
  <div class="stat-head">
    <span class="stat-label">총 생성 링크</span>
    <span class="stat-icon"><svg>🔗</svg></span>
  </div>
  <div class="stat-value">12,480</div>
  <div class="stat-trend is-up">
    <svg>↗</svg> 12.5% <span class="trend-note">지난달 대비</span>
  </div>
</div>
```
- 구성: `.stat-label`(라벨) + `.stat-icon`(우상단 아이콘) + `.stat-value`(값, 28/800·tabular) + `.stat-trend`(증감).
- 아이콘 색: 기본(brand) / `is-success` / `is-warning` / `is-info`.
- 트렌드: `is-up`(초록·상승) / `is-down`(빨강·하락) / `is-flat`(회색·변동없음). 부가 설명은 `.trend-note`.
- 값 단위는 `<span class="unit">%</span>`(예: `96.4%`).
- `is-clickable` 추가 시 hover 그림자 강조(드릴다운 링크용).
- 4개 나열은 `.grid.grid-4`(모바일 2열 → 데스크톱 4열). 대시보드/리포트 상단에 사용.

### 4.21 Table — `.table` (헤더 고정 / sticky)
```html
<div class="table-wrap">            <!-- 스크롤 컨테이너(max-height) -->
  <table class="table">
    <thead>
      <tr>
        <th class="col-check"><label class="check"><input type="checkbox" /></label></th>
        <th>생성일</th><th>유형</th><th>Campaign</th><th>링크</th>
        <th>상태</th><th class="num">클릭</th><th class="col-actions">액션</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td class="col-check">…</td>
        <td>2026-07-21</td>
        <td><span class="badge badge-brand">UTM</span></td>
        <td>summer_sale_2026</td>
        <td class="cell-mono cell-truncate">https://ex.com/?utm_source=google…</td>
        <td><span class="badge badge-success">✓ 검증됨</span></td>
        <td class="num">1,204</td>
        <td class="col-actions">…버튼…</td>
      </tr>
    </tbody>
  </table>
</div>
```
- **헤더 고정**: `.table-wrap`(max-height + `overflow:auto`)를 스크롤 컨테이너로 두고, `thead th`가 `position: sticky; top: 0`. `border-collapse: separate` 필수(sticky 보더 유지).
- **정렬(sort)**: `<table class="table" data-sortable>` + 정렬할 헤더에 `<th data-sort="text|num|date">`. `design-system.js`가 초기화 → 헤더 클릭/Enter 시 오름↔내림 전환, 정렬 아이콘·`aria-sort` 자동 갱신.
  - `num`은 콤마·기호 제거 후 숫자 비교, `date`는 ISO(`yyyy-MM-dd`) 문자열 비교, `text`는 소문자 비교.
  - 셀에 `data-value`를 주면 표시(배지 등)와 **정렬 키를 분리** 가능. 예: `<td data-value="1"><span class="badge badge-success">검증됨</span></td>`.
  - 훅: `table.addEventListener('sort:change', e => e.detail /* {column,type,dir} */)` → 서버 정렬로 대체 가능.
  - 체크박스·액션 등 정렬 불필요한 헤더는 `data-sort`를 생략.
- 열 유틸: `.num`(우측·tabular 숫자) / `.col-check`(체크박스 44px) / `.col-actions`(우측 정렬) / `.cell-mono`(링크 monospace) / `.cell-truncate`(말줄임 220px).
- 행 hover는 `--brand-subtle`, 얼룩무늬는 `.table-striped` (`--bg-page` + `--surface` mix).
- 셀 안에서 `.badge`(상태·유형), `.btn.btn-ghost.btn-sm`(복사/삭제), `.check`(선택) 등 기존 컴포넌트를 조합.
- 히스토리·대량생성 결과·파라미터 매핑 목록에 사용.

### 4.20 Progress bar — `.progress`
```html
<div class="progress-field">
  <div class="progress-head">
    <span class="progress-label">링크 생성 중…</span>
    <span class="progress-value">62%</span>
  </div>
  <div class="progress"><div class="progress-bar" style="width:62%"></div></div>
</div>
```
- 진행률은 `.progress-bar`의 `width`(%)로 표현. `width` 트랜지션 내장.
- 색상 상태: 기본(brand) / `is-success` / `is-warning` / `is-danger`.
- 크기: 기본 8px / `progress-sm`(4px) / `progress-lg`(12px).
- **불확정**(진행률 미상): `<div class="progress is-indeterminate"><div class="progress-bar"></div></div>` — 좌우로 흐르는 애니메이션.
- JS로 갱신(대량 생성 xhr 진행 등):
  ```js
  function setProgress(field, pct) {
    field.querySelector('.progress-bar').style.width = pct + '%';
    var v = field.querySelector('.progress-value');
    if (v) v.textContent = Math.round(pct) + '%';
  }
  ```
- 대량생성(CSV/Excel) 진행, 업로드/검증 진행 표시에 사용.

### 4.18 Tabs — `.tabs` (언더라인 · 콘텐츠 패널 전환)
> 상단 UI 전환에는 알약형 [Segmented Control](#48-segmented-control-탭)—좁은 옵션 전환—과, 콘텐츠 영역 전체를 바꾸는 **Tabs**—언더라인—두 가지가 있다. 화면 주 내비게이션은 Tabs를 쓴다.
```html
<div class="tabs" data-tabs>
  <div class="tab-list" role="tablist" aria-label="링크 생성 방식">
    <button class="tab is-active" data-tab="t-utm">UTM 링크</button>
    <button class="tab" data-tab="t-onelink">AppsFlyer OneLink</button>
    <button class="tab" data-tab="t-history">히스토리</button>
  </div>
  <div class="tab-panels">
    <div class="tab-panel is-active" data-panel="t-utm" role="tabpanel">…</div>
    <div class="tab-panel" data-panel="t-onelink" role="tabpanel">…</div>
    <div class="tab-panel" data-panel="t-history" role="tabpanel">…</div>
  </div>
</div>
```
- `design-system.js`가 `[data-tabs]`를 초기화 → 탭의 `data-tab` 과 패널의 `data-panel`을 매칭해 전환.
- 활성 탭은 `.is-active`(브랜드 언더라인). 초기 활성 탭/패널에 `.is-active`를 지정(없으면 첫 번째).
- 키보드: `←`/`→`로 탭 이동(roving tabindex, `aria-selected` 자동 관리).
- 값 변경 훅: `root.addEventListener('tab:change', e => e.detail.tab)`.
- **Segmented와 구분**: Segmented는 `.segmented`(알약, 인라인 옵션), Tabs는 `.tabs`(언더라인, 패널 전환). JS 초기화도 각각 `.segmented` / `[data-tabs]`.

### 4.23 Listbox — `.listbox` (선택 리스트 패널 · 캐스케이딩)
> 드롭다운(`.select`)이 열고 닫는 방식이라면, **Listbox는 옵션을 항상 노출**하는 리스트 패널이다. 좁은 폭에서 계층을 **드릴다운(매체 › 캠페인 › 광고그룹 › 소재)** 할 때, 여러 패널을 `.listbox-grid`로 나열해 **왼쪽 선택이 오른쪽 열을 채우는 캐스케이딩** 구성으로 쓴다. 각 패널은 고정 높이(280px) + 내부 스크롤.

```html
<div class="listbox-grid">
  <!-- 단일 선택 -->
  <div class="listbox">
    <div class="listbox-head">매체 <span class="req">*</span> <span class="listbox-count">2개</span></div>
    <ul class="listbox-list">
      <li class="listbox-option is-selected"><span class="lb-label">YouTube <span class="lb-sub">— Google Ads</span></span></li>
      <li class="listbox-option"><span class="lb-label">Instagram <span class="lb-sub">— Meta</span></span></li>
    </ul>
  </div>

  <!-- 다중 선택(.multi) + 검색 + 전체선택 -->
  <div class="listbox multi">
    <div class="listbox-head">캠페인 <span class="listbox-count">5개</span><button class="listbox-all is-on">전체 해제</button></div>
    <div class="listbox-search"><svg>🔍</svg><input placeholder="검색" /></div>
    <ul class="listbox-list">
      <li class="listbox-option is-selected"><span class="box"><svg>✓</svg></span><span class="lb-label">summer_sale_2026</span></li>
      <li class="listbox-option"><span class="box"><svg>✓</svg></span><span class="lb-label">retargeting_q3 <span class="lb-sub">— YouTube</span></span></li>
    </ul>
  </div>

  <!-- 로딩 / 빈 상태 -->
  <div class="listbox"><div class="listbox-head">광고그룹</div><ul class="listbox-list"><li class="listbox-loading"><span class="listbox-spinner"></span>로딩 중</li></ul></div>
  <div class="listbox"><div class="listbox-head">소재</div><ul class="listbox-list"><li class="listbox-empty">캠페인을 먼저 선택하세요</li></ul></div>
</div>
```

- **구조**: `.listbox`(패널) > `.listbox-head`(제목 + `.listbox-count` + 선택적 `.listbox-all`) + 선택적 `.listbox-search` + `.listbox-list > .listbox-option`.
- **단일/다중**: 기본은 단일(선택 항목에 `.is-selected` 하이라이트). `.listbox.multi`를 주면 각 옵션 왼쪽에 체크박스(`.box` + 체크 SVG)가 그려지고 `.is-selected` 시 채워진다.
- **옵션 라벨**: `.lb-label`(말줄임) + 보조정보 `.lb-sub`(상위 항목명 등). 긴 이름은 `title` 속성으로 전체 노출 권장.
- **전체 선택**: `.listbox-all`(pill 버튼). 모두 선택된 상태면 `.is-on` + "전체 해제" 라벨로 토글.
- **상태**: 빈 목록 `.listbox-empty`, 로딩 `.listbox-loading`(+ `.listbox-spinner`).
- **캐스케이딩**: `.listbox-grid`(반응형 auto-fit, 최소 200px). 필수 열은 head에 `<span class="req">*</span>`.
- **JS(앱 연결)**: 검색 필터·선택 토글·전체선택·캐스케이드 갱신은 앱 JS로 `.is-selected`/`display` 클래스를 토글해 연결한다(디자인 시스템 기본 JS에는 초기화 없음). 검색은 **보이는 항목** 기준으로 전체선택하도록 구현 권장.

### 4.24 Tree — `.tree` (트리 구조 · 계층 펼침/접힘)
> 중첩 목록으로 계층을 표현하고 노드를 **펼침/접힘**으로 탐색한다. Listbox가 열(패널) 단위 캐스케이딩이라면, Tree는 **한 화면에서 상하 계층**을 보여줄 때 쓴다.

```html
<ul class="tree tree-lines" role="tree">
  <li class="tree-item" role="treeitem">
    <div class="tree-node">
      <span class="tree-caret"><svg>▾</svg></span>
      <span class="tree-icon"><svg>📁</svg></span>
      <span class="tree-label">2026 여름 캠페인</span>
      <span class="tree-count">12</span>
    </div>
    <ul role="group">
      <li class="tree-item">
        <div class="tree-node is-selected">
          <span class="tree-caret"><svg>▾</svg></span>
          <span class="tree-icon"><svg>📁</svg></span>
          <span class="tree-label">YouTube</span>
        </div>
        <ul>
          <li class="tree-item is-leaf"><div class="tree-node"><span class="tree-caret"></span><span class="tree-icon"><svg>📄</svg></span><span class="tree-label">15초 세로 소재</span></div></li>
        </ul>
      </li>
      <li class="tree-item is-collapsed">   <!-- 접힘: 자식 숨김 + 캐럿 회전 -->
        <div class="tree-node">
          <span class="tree-caret"><svg>▾</svg></span>
          <span class="tree-icon"><svg>📁</svg></span>
          <span class="tree-label">Instagram</span>
        </div>
        <ul> … </ul>
      </li>
    </ul>
  </li>
</ul>
```

- **구조**: `.tree`(ul) > `.tree-item`(li) > `.tree-node`(행: `.tree-caret` + `.tree-icon` + `.tree-label` + 선택적 `.tree-count`) + 자식 `.tree`(ul).
- **펼침/접힘**: `.tree-item.is-collapsed`면 자식 `ul`이 숨고 캐럿이 −90° 회전. 기본은 펼침.
- **리프**: `.tree-item.is-leaf`(자식 없음) — 캐럿을 숨기되 자리(들여쓰기)는 유지.
- **선택**: 선택 노드에 `.tree-node.is-selected`(브랜드 하이라이트).
- **가이드 라인**: 루트에 `.tree-lines`를 주면 자식 목록 왼쪽에 세로 연결선 표시.
- **체크박스형**: `.tree.checkable` + 각 노드에 `.tree-check`(체크 SVG). 선택은 `.tree-node.is-checked`로 채움. 다중 선택 트리에 사용.
- **들여쓰기**: 자식 `ul`의 `padding-left`(22px)로 자동. 아이콘은 폴더(가지)/파일(리프)로 구분 권장.
- **JS(자동 초기화)**: `design-system.js`가 `.tree`를 자동 초기화한다 — 캐럿/노드 클릭 시 `.is-collapsed` 토글(펼침/접힘), 노드 선택 `.is-selected`(단일) 또는 `.tree.checkable`이면 `.is-checked` 토글, `aria-expanded` 자동 관리. 값 변경 훅: `root.addEventListener('tree:change', e => e.detail.node)`.

---

## 5. 접근성 & 상태 규칙 (Rules)

1. **포커스 가시성**: `:focus-visible` → `--sh-focus` (brand 연동).
2. **필수 입력**: 라벨 `<span class="req">*</span>`.
3. **에러 표기**: 보더 + 헬퍼 + 아이콘 동시 (색상만 의존 금지).
4. **disabled**: `disabled` + `not-allowed` + 명도 저하.
5. **한글 가독성**: `line-height:1.5~1.65`, 라벨-인풋 6px.
6. **터치 타깃**: 최소 40×40px.
7. **색상 대비**: 본문 `--ink-700` 이상, placeholder `--ink-400`. 다크 모드에서도 invert된 ink scale 기준.
8. **테마**: `color-scheme`는 `themes.css`에서 light/dark별 설정.

---

## 6. 화면별 컴포넌트 매핑 (기능 → 컴포넌트)

| 기능 | 주요 컴포넌트 |
|------|---------------|
| UTM Builder | `.input`(+`.input-group` addon), `.select`, `.btn-primary`, `.codeblock` + 복사 `.btn-icon` |
| AppsFlyer OneLink | `.check`(radio), `.switch`(deep_link), `.input` |
| 파라미터 매핑 | `.select`, `.check`, table |
| 대량생성 | `.dropzone`, `.textarea`, 진행 상태 `.alert` |
| 링크 파싱/검증기 | `.textarea`, `.input-success`/`.input-error`, `.badge` |
| 히스토리 | `.input-wrap`(search), table, `.badge`, `.btn-ghost` |

---

## 7. AI 코드 생성 체크리스트

- [ ] 색상·radius·shadow는 `var(--토큰)` 사용, **임의 hex 금지**
- [ ] 표면은 `var(--surface)` / `var(--bg-page)`, brand tinted는 `var(--brand-subtle)` · `var(--brand-muted-*)`
- [ ] Tailwind 유틸 금지 → `design-system.css` 시맨틱 클래스
- [ ] `themes.css` 로드 + `data-theme` (운영 고정 또는 `theme-switcher.js`)
- [ ] brand 변경 시 `palettes.css` 또는 `LinkBuilderPalette.applyCustom()` — `--brand-50` 직접 hover 용도 지양
- [ ] 폰트 body 상속(Pretendard GOV), 링크 결과는 `.codeblock` (`--code-bg` / `--code-fg`)
- [ ] 버튼/인풋 `.btn` / `.input` (40px, radius 8px)
- [ ] 폼: `.field > .label + control + .hint`
- [ ] 생성 링크: `.codeblock` + `.code-copy[data-copy]`
- [ ] 새 컴포넌트는 `design-system.css` + 테마 토큰 준수
