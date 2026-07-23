# 경계 GeoJSON 출처

- `world-countries.json` — [Natural Earth](https://www.naturalearthdata.com/) 110m Admin 0 Countries.
  퍼블릭 도메인. 속성을 `{iso, name}` 으로 슬림했고, ISO_A2 미부여 국가는 ISO_A2_EH 로 보완했다.
- `kr-sido.json` — [southkorea/southkorea-maps](https://github.com/southkorea/southkorea-maps)
  KOSTAT 2013 시·도 간략본("Free to share or remix"). 속성을 `{iso(ISO 3166-2:KR 현행), name(현행 명칭)}` 으로 변환했다.

두 파일 모두 백엔드 지역 판별(`RegionResolver`)과 프론트 choropleth 렌더(`footprint.php`)가 공유한다.
