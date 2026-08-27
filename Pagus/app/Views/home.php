<?php

/**
 * @var list<array<string, mixed>> $restaurants
 * @var list<array<string, mixed>> $categories
 * @var array{query: string, category_id: ?int, sort: string, page: int} $filters
 * @var CodeIgniter\Pager\Pager $pager
 */
$mapData = json_encode($restaurants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($mapData === false) {
    $mapData = '[]';
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>파구스 — 파주 로컬 맛집 지도</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        :root { color: #17202a; font-family: system-ui, -apple-system, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f5f7f8; }
        header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 5vw; background: #173b35; color: #fff; }
        header h1 { margin: 0; font-size: 1.25rem; }
        header a { color: #d9f2e9; text-decoration: none; }
        main { display: grid; grid-template-columns: minmax(18rem, 28rem) 1fr; min-height: calc(100vh - 4rem); }
        aside { padding: 1.25rem; overflow-y: auto; }
        form { display: grid; gap: .6rem; margin-bottom: 1rem; }
        input, select, button { border: 1px solid #ccd6d2; border-radius: .5rem; padding: .65rem .75rem; font: inherit; background: #fff; }
        button { background: #206b58; color: #fff; border-color: #206b58; cursor: pointer; }
        .result-summary { color: #53635e; font-size: .9rem; margin: .75rem 0; }
        .restaurant { display: block; padding: 1rem 0; border-bottom: 1px solid #dce4e1; }
        .restaurant h2 { margin: 0 0 .3rem; font-size: 1.05rem; }
        .restaurant p { margin: .25rem 0; color: #53635e; font-size: .9rem; }
        .category { color: #206b58; }
        .empty { padding: 2rem 0; color: #53635e; }
        #map { min-height: calc(100vh - 4rem); }
        .pagination { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: 1rem; }
        .pagination a, .pagination strong { padding: .45rem .7rem; border-radius: .4rem; background: #fff; color: #206b58; text-decoration: none; }
        .pagination strong { background: #206b58; color: #fff; }
        @media (max-width: 720px) { main { display: flex; flex-direction: column-reverse; } #map { min-height: 55vh; } aside { min-height: 45vh; } }
    </style>
</head>
<body>
<header>
    <h1>파구스 · 파주 로컬 맛집 지도</h1>
    <nav aria-label="주요 메뉴">
        <a href="<?= site_url('/') ?>">홈</a>
        <a href="<?= site_url('login') ?>">로그인</a>
        <a href="#contact">문의하기</a>
    </nav>
</header>
<main>
    <aside>
        <form method="get" action="<?= site_url('/') ?>" aria-label="맛집 검색">
            <label for="q">맛집 검색</label>
            <input id="q" name="q" type="search" value="<?= esc($filters['query']) ?>" maxlength="100" placeholder="상호명, 주소, 카테고리, 태그">
            <label for="category">카테고리</label>
            <select id="category" name="category">
                <option value="">전체 카테고리</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= esc((string) $category['id'], 'attr') ?>" <?= $filters['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= esc((string) $category['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="sort">정렬</label>
            <select id="sort" name="sort">
                <option value="name" <?= $filters['sort'] === 'name' ? 'selected' : '' ?>>가나다순</option>
                <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>최신 등록순</option>
            </select>
            <button type="submit">검색</button>
        </form>
        <div class="result-summary">공개 맛집 목록</div>
        <?php if ($restaurants === []): ?>
            <p class="empty">조건에 맞는 공개 맛집이 없습니다.</p>
        <?php else: ?>
            <?php foreach ($restaurants as $restaurant): ?>
                <article class="restaurant">
                    <h2><a href="<?= site_url('restaurants/' . (int) $restaurant['id']) ?>"><?= esc((string) $restaurant['name']) ?></a></h2>
                    <p class="category"><?= esc((string) ($restaurant['category_names'] ?? '카테고리 미지정')) ?></p>
                    <p><?= esc((string) $restaurant['address']) ?></p>
                    <?php if ((string) ($restaurant['phone'] ?? '') !== ''): ?><p><?= esc((string) $restaurant['phone']) ?></p><?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?= $pager->links('restaurants') ?>
        <?php endif; ?>
    </aside>
    <div id="map" aria-label="공개 맛집 지도"></div>
</main>
<footer id="contact" style="padding: 1.5rem 5vw; background: #173b35; color: #d9f2e9;">
    <h2 style="font-size: 1rem; margin: 0 0 .4rem;">문의하기</h2>
    <p style="margin: 0;">파주 맛집 정보에 대한 문의는 운영자 로그인 후 관리자에게 남겨주세요.</p>
</footer>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
    const restaurants = <?= $mapData ?>;
    const map = L.map('map').setView([37.7597, 126.7777], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    const bounds = [];
    restaurants.forEach((restaurant) => {
        const position = [Number(restaurant.latitude), Number(restaurant.longitude)];
        if (!Number.isFinite(position[0]) || !Number.isFinite(position[1])) return;
        const popup = document.createElement('div');
        const title = document.createElement('strong');
        title.textContent = restaurant.name;
        const address = document.createElement('div');
        address.textContent = restaurant.address;
        popup.append(title, address);
        if (restaurant.category_names) {
            const category = document.createElement('div');
            category.textContent = restaurant.category_names;
            popup.append(category);
        }
        L.marker(position).addTo(map).bindPopup(popup);
        bounds.push(position);
    });
    if (bounds.length > 0) map.fitBounds(bounds, { padding: [24, 24], maxZoom: 15 });
</script>
</body>
</html>
