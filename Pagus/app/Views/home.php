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
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<header class="site-header">
    <h1>파구스 · 파주 로컬 맛집 지도</h1>
    <nav aria-label="주요 메뉴">
        <a href="<?= site_url('/') ?>">홈</a>
        <a href="<?= site_url('login') ?>">로그인</a>
        <a href="#contact">문의하기</a>
    </nav>
</header>
<main class="home-main">
    <aside class="home-aside">
        <form class="search-form form-grid" method="get" action="<?= site_url('/') ?>" aria-label="맛집 검색">
            <label for="q">맛집 검색
                <input id="q" name="q" type="search" value="<?= esc($filters['query']) ?>" maxlength="100" placeholder="상호명, 주소, 카테고리, 태그">
            </label>
            <label for="category">카테고리
                <select id="category" name="category">
                    <option value="">전체 카테고리</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= esc((string) $category['id'], 'attr') ?>" <?= $filters['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= esc((string) $category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="sort">정렬
                <select id="sort" name="sort">
                    <option value="name" <?= $filters['sort'] === 'name' ? 'selected' : '' ?>>가나다순</option>
                    <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>최신 등록순</option>
                </select>
            </label>
            <button type="submit">검색</button>
        </form>
        <div class="result-summary"><?= count($restaurants) ?>곳의 공개 맛집</div>
        <?php if ($restaurants === []): ?>
            <p class="empty-state">조건에 맞는 공개 맛집이 없습니다. 검색어나 카테고리를 바꿔보세요.</p>
        <?php else: ?>
            <?php foreach ($restaurants as $restaurant): ?>
                <article class="restaurant-card">
                    <h2><button class="restaurant-title" type="button" data-restaurant-id="<?= (int) $restaurant['id'] ?>"><?= esc((string) $restaurant['name']) ?></button></h2>
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
<footer id="contact" class="site-footer">
    <h2>문의하기</h2>
    <?php if (session('message')): ?><p role="status"><?= esc(session('message')) ?></p><?php endif; ?>
    <?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>
    <form class="form-grid" method="post" action="<?= site_url('inquiries') ?>">
        <?= csrf_field() ?>
        <label>이름 <input name="name" required maxlength="100" value="<?= esc(old('name') ?? '') ?>"></label>
        <label>연락처(선택, 회신용) <input name="contact" maxlength="255" value="<?= esc(old('contact') ?? '') ?>"></label>
        <label>문의 내용 <textarea name="message" required maxlength="2000" rows="4"><?= esc(old('message') ?? '') ?></textarea></label>
        <button type="submit">문의 보내기</button>
    </form>
</footer>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
    const restaurants = <?= $mapData ?>;
    const markers = new Map();
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
        if (restaurant.phone) {
            const phone = document.createElement('div');
            phone.textContent = restaurant.phone;
            popup.append(phone);
        }
        const detail = document.createElement('a');
        detail.href = '<?= site_url('restaurants') ?>/' + Number(restaurant.id);
        detail.textContent = '상세 보기';
        popup.append(detail);
        const marker = L.marker(position).addTo(map).bindPopup(popup);
        markers.set(Number(restaurant.id), marker);
        bounds.push(position);
    });
    if (bounds.length > 0) map.fitBounds(bounds, { padding: [24, 24], maxZoom: 15 });
    document.querySelectorAll('[data-restaurant-id]').forEach((button) => {
        button.addEventListener('click', () => {
            const marker = markers.get(Number(button.dataset.restaurantId));
            if (!marker) return;
            map.setView(marker.getLatLng(), Math.max(map.getZoom(), 15));
            marker.openPopup();
        });
    });
</script>
</body>
</html>
