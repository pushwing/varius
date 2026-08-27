---
name: 파구스 (Pagus)
description: 파주 로컬 맛집을 지도와 검증된 후기로 발견하는 서비스
colors:
  fern-green: "#206b58"
  pine-shadow: "#173b35"
  mint-whisper: "#d9f2e9"
  warm-linen: "#f7f3ec"
  paper-surface: "#fffdf7"
  warm-charcoal: "#2b2620"
  taupe-grey: "#6b6259"
  toasted-border: "#ded4c3"
  terracotta-error: "#8a3b2b"
  terracotta-error-border: "#e0b7a8"
  terracotta-error-bg: "#f6e4de"
typography:
  title:
    fontFamily: "system-ui, -apple-system, 'Segoe UI', sans-serif"
    fontSize: "1.5rem"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "normal"
  headline:
    fontFamily: "system-ui, -apple-system, 'Segoe UI', sans-serif"
    fontSize: "1.05rem"
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: "normal"
  body:
    fontFamily: "system-ui, -apple-system, 'Segoe UI', sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "normal"
  label:
    fontFamily: "system-ui, -apple-system, 'Segoe UI', sans-serif"
    fontSize: "0.9rem"
    fontWeight: 400
    lineHeight: 1.4
    letterSpacing: "normal"
rounded:
  sm: "6.4px"
  md: "8px"
  pill: "999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.fern-green}"
    textColor: "#ffffff"
    rounded: "{rounded.md}"
    padding: "10px 12px"
  button-primary-hover:
    backgroundColor: "{colors.pine-shadow}"
    textColor: "#ffffff"
    rounded: "{rounded.md}"
    padding: "10px 12px"
  input-field:
    backgroundColor: "#ffffff"
    textColor: "{colors.warm-charcoal}"
    rounded: "{rounded.md}"
    padding: "10px 12px"
  card-restaurant:
    backgroundColor: "{colors.paper-surface}"
    textColor: "{colors.warm-charcoal}"
    rounded: "{rounded.md}"
    padding: "16px 0"
---

# Design System: 파구스 (Pagus)

## Overview

**Creative North Star: "The Trusted Neighbor"**

파구스는 광고성 나열이 아니라 "동네 사람이 직접 추천해주는" 신뢰감을 시각적으로 전달해야 한다. 현재 구현(공개 목록 `/`, 상세 `/restaurants/{id}`)은 진한 숲색 헤더(#173b35)와 초록 포인트(#206b58)로 브랜드 정체성을 이미 절반쯤 갖췄지만, 배경·본문 색이 차가운 회색조(#f5f7f8, #17202a)라 "동네 사람의 온기"보다는 사무적인 툴 느낌에 가깝다. 이 시스템은 기존 초록 정체성은 그대로 이어받되, 중립색을 따뜻한 토프·크림 톤으로 옮기고 그림자 대신 또렷한 테두리로 입체감을 표현해 "종이에 손으로 쓴 동네 추천장" 같은 촉감을 만든다.

운영자 화면(`/admin`, `/login`, `/admin/inquiries`)은 현재 이 시스템을 전혀 공유하지 않는다 — 브라우저 기본 스타일 그대로이며 공개 화면과 완전히 단절되어 있다. 이는 확정된 디자인이 아니라 **미착수 영역**이며, 다음 폴리시 작업에서 이 시스템을 그대로 적용해야 할 대상이다.

**확정된 시각적 거부 사항:** 배달앱류의 빨강·주황 포인트 컬러, 구글맵류의 차가운 파랑 액센트는 쓰지 않는다 — 초록 하나를 신뢰 신호로 유지한다.

**Key Characteristics:**
- 따뜻한 크림·토프 중립 바탕 위에 짙은 숲색·초록 액센트 하나만 신뢰 신호로 사용
- 그림자 없는 평면 위에 또렷한 테두리로 카드·구획을 구분(종이 질감)
- 시스템 폰트 기반의 절제된 타이포, 장식적 요소 없음
- 지도와 목록이 항상 나란히 공존하는 레이아웃

## Colors

따뜻한 크림·토프 중립 위에 짙은 숲색 하나만 신뢰 신호로 쓰는, 절제되고 온기 있는 팔레트다.

### Primary
- **Fern Green** (`#206b58`): 버튼, 링크, 카테고리 태그, 활성 페이지네이션 등 상호작용 가능한 요소의 유일한 액센트. 화면당 노출 면적을 좁게 유지해 희소성이 신뢰 신호가 되게 한다.
- **Pine Shadow** (`#173b35`): 헤더·푸터 배경, 버튼 hover/active 상태. Fern Green보다 한 단계 어두운 동일 색상군.

### Neutral
- **Warm Linen** (`#f7f3ec`): 페이지 기본 배경. 기존 차가운 회백색(#f5f7f8)을 대체하는 따뜻한 크림.
- **Paper Surface** (`#fffdf7`): 카드·입력창 등 배경 위에 얹히는 표면. Warm Linen보다 살짝 밝아 종이 질감의 층을 만든다.
- **Warm Charcoal** (`#2b2620`): 본문·제목 텍스트. 기존 차가운 슬레이트(#17202a)를 대체하는 따뜻한 다크 브라운블랙.
- **Taupe Grey** (`#6b6259`): 보조 텍스트(주소, 결과 요약, 메타 정보).
- **Toasted Border** (`#ded4c3`): 카드 구분선, 입력창 테두리. 그림자 대신 이 테두리가 입체감을 만든다.
- **Mint Whisper** (`#d9f2e9`): Pine Shadow 배경 위(헤더·푸터) 링크·보조 텍스트 전용 밝은 톤.

### Error / Danger (semantic, non-브랜드)
- **Terracotta Error** (`#8a3b2b`): 삭제·오류 등 파괴적 액션의 텍스트·아이콘 색. `role="alert"` 배너, 삭제 버튼(`.btn-danger`) 전용.
- **Terracotta Error Border** (`#e0b7a8`): 위 요소의 테두리.
- **Terracotta Error Tint** (`#f6e4de`): 위 요소의 배경.

이 세 색은 브랜드 액센트가 아니라 시스템 시맨틱(파괴적 액션·유효성 오류) 전용이다 — One Green Rule이 금지하는 "두 번째 브랜드 액센트"에 해당하지 않는다.

### Named Rules
**The One Green Rule.** 초록은 화면당 하나의 역할(주 액션·활성 상태·카테고리)에만 쓴다. 두 번째 액센트 컬러를 추가하지 않는다 — 초록의 희소성이 신뢰 신호다. Error/Danger 시맨틱 컬러는 이 규칙의 예외다.

## Typography

**Body Font:** `system-ui, -apple-system, 'Segoe UI', sans-serif` (플랫폼 기본 폰트, 별도 웹폰트 로드 없음)

**Character:** 장식 없는 시스템 폰트로 "정보를 꾸미지 않고 있는 그대로 전달한다"는 태도를 표현한다. 굵기 대비만으로 위계를 만든다.

### Hierarchy
- **Title** (700, 1.5rem, line-height 1.25): 맛집 상세·페이지 최상위 제목(`<h1>` 본문 영역).
- **Headline** (600, 1.05rem, line-height 1.3): 목록 카드 제목(맛집명), 섹션 제목(`<h2>`: 소개·메뉴·영업 정보).
- **Body** (400, 1rem, line-height 1.5): 본문 설명, 메뉴·영업 정보 텍스트.
- **Label** (400, 0.9rem, line-height 1.4): 폼 레이블, 보조 메타 텍스트(주소·카테고리·결과 요약).

현재 헤더 h1(1.25rem)처럼 위 네 단계에 정확히 맞지 않는 값이 일부 있다 — 향후 작업에서 이 네 단계로 정규화한다.

## Layout

- **반응형 2단 구성**: 데스크톱은 `grid-template-columns: minmax(18rem, 28rem) 1fr` — 좌측 목록(aside), 우측 지도. 720px 이하에서는 `flex-direction: column-reverse`로 전환해 목록이 위, 지도가 아래로 쌓인다.
- **상세 페이지**는 단일 컬럼, `max-width: 48rem`, 좌우 여백은 `5vw`.
- **간격 스케일**: 4 / 8 / 16 / 24 / 32px(대략 .25 / .5 / 1 / 1.5 / 2rem)를 기준으로 폼 요소 간격(.6rem), 카드 패딩(1rem), 섹션 간격(1.25rem)이 이 스케일 근방에서 느슨하게 쓰인다 — 향후 작업에서 정확히 이 스케일에 맞춰 정리한다.
- **헤더·푸터**는 항상 `padding: 1rem 5vw`(헤더) / `1.5rem 5vw`(푸터)로 뷰포트 폭에 비례해 좌우 여백을 준다.

## Elevation & Depth

이 시스템은 그림자를 쓰지 않는 완전한 플랫 시스템이다. 깊이는 그림자가 아니라 **배경색 단계(Warm Linen → Paper Surface)와 또렷한 테두리(Toasted Border)** 로 표현한다 — 종이를 겹쳐 놓은 듯한 촉감을 목표로 한다.

### Named Rules
**The No-Shadow Rule.** `box-shadow`를 쓰지 않는다. 카드·구획 구분은 배경색 대비 또는 1px 테두리로만 표현한다.

## Shapes

- **모서리**: 입력창·버튼·사진 썸네일은 `.5rem`(8px) 라운드, 페이지네이션 링크는 `.4rem`(6.4px)로 더 촘촘하다. 상태 배지(공개/숨김 등)만 예외적으로 완전 원형(`999px`) 필(pill) 형태를 쓴다 — 박스형 요소와 구분되는 신호 그 자체이기 때문이다.
- **테두리**: 입력창은 `1px solid #ccd6d2` 계열 톤(신규 팔레트에서는 Toasted Border로 대체) — 채움보다 테두리로 형태를 규정한다.
- 원형·픽셀 아이콘·비대칭 클리핑 등 장식적 형태 언어는 없다 — 사각형과 절제된 라운드만 쓴다.

## Components

### Buttons
- **Shape:** 8px 라운드, 1px 보더(배경과 동일 색).
- **Primary:** 배경 Fern Green(#206b58), 텍스트 흰색, 패딩 `.65rem .75rem`.
- **Hover/Focus:** 배경이 Pine Shadow(#173b35)로 어두워진다(현재 hover 상태는 명시적으로 구현되어 있지 않음 — 향후 작업에서 추가 필요).
- 현재 Secondary/Ghost 버튼 변형은 없다 — 모든 버튼이 Primary 스타일 하나뿐이다.

### Cards (맛집 목록 항목)
- **Corner Style:** 라운드 없음(블록 요소, 테두리는 하단 구분선만).
- **Background:** Warm Linen 배경 위에 얹힌 투명 블록.
- **Divider:** 하단 1px Toasted Border로 다음 항목과 구분(그림자 없음).
- **Internal Padding:** 위아래 `1rem`.

### Inputs / Fields
- **Style:** 1px Toasted Border, Paper Surface 배경, 8px 라운드, 패딩 `.65rem .75rem`.
- **Focus:** 명시적 focus 스타일 없음 — 브라우저 기본 아웃라인에 의존 중(접근성 관점에서 향후 작업 시 명시적 focus-visible 스타일 추가 필요).

### Navigation
- 헤더: Pine Shadow 배경, 흰색 제목, Mint Whisper 링크, 언더라인 없음.
- 상세 페이지 nav는 "목록으로" 단일 링크만 제공.

### Pagination
- **Style:** CI4 기본 Pager 템플릿(`<ul class="pagination"><li><a>`) 기준. Paper Surface 배경에 Fern Green 텍스트, 6.4px 라운드. 활성 페이지(`<li class="active">`)는 배경이 Fern Green으로 채워지고 텍스트가 흰색으로 반전 — 기존 구현은 `<strong>` 셀렉터를 썼으나 실제 Pager가 렌더링하는 마크업과 달라 활성 상태가 한 번도 스타일링되지 않았다(로컬 결함, 이번 폴리시에서 수정).

### 지도 (Leaflet)
- 좌측 목록과 나란히 배치되는 1급 컴포넌트. 마커 팝업은 기본 Leaflet 스타일 그대로 — 브랜드 팔레트가 아직 적용되지 않은 영역.

## Do's and Don'ts

### Do:
- **Do** 초록(Fern Green/Pine Shadow)을 화면당 하나의 신뢰 신호로만 쓰고, 배경·텍스트는 항상 따뜻한 토프·크림 중립으로 맞춘다.
- **Do** 카드·구획의 입체감은 그림자 대신 배경색 단계와 1px Toasted Border로 표현한다.
- **Do** 운영자 화면(`/admin`, `/login`, `/admin/inquiries`)에도 이 토큰(색·타이포·버튼·인풋)을 동일하게 적용한다 — 현재 완전히 미적용 상태다.
- **Do** 입력창·버튼에 명시적 `:focus-visible` 스타일을 추가한다 — 현재 브라우저 기본값에 의존 중이다.

### Don't:
- **Don't** 배달앱류 빨강·주황이나 지도앱류 파랑을 액센트로 추가하지 않는다 — 초록 하나만 쓴다.
- **Don't** `box-shadow`를 도입하지 않는다 — 이 시스템은 완전한 플랫 시스템이다.
- **Don't** 페이지마다 `<style>` 블록을 새로 복제하지 않는다 — 현재 `home.php`, `restaurant/show.php`가 거의 동일한 CSS를 각자 인라인으로 중복 정의하고 있다. 공용 스타일시트로 통합이 필요하다.
