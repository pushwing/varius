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
<script src="https://dapi.kakao.com/v2/maps/sdk.js?autoload=false&appkey=<?= esc(config(\Config\KakaoMaps::class)->jsKey, 'url') ?>"></script>
<script>
    const restaurants = <?= $mapData ?>;
    if (!window.kakao || !window.kakao.maps) {
        document.getElementById('map').textContent = '지도를 불러오지 못했습니다.';
    } else kakao.maps.load(() => {
        const map = new kakao.maps.Map(document.getElementById('map'), {
            center: new kakao.maps.LatLng(37.7597, 126.7777),
            level: 7
        });
        const markers = new Map();
        const bounds = new kakao.maps.LatLngBounds();
        let hasBounds = false;
        let openInfoWindow = null;
        function openMarker(marker, infoWindow) {
            if (openInfoWindow) openInfoWindow.close();
            infoWindow.open(map, marker);
            openInfoWindow = infoWindow;
        }
        restaurants.forEach((restaurant) => {
            const lat = Number(restaurant.latitude);
            const lon = Number(restaurant.longitude);
            if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
            const position = new kakao.maps.LatLng(lat, lon);
            const marker = new kakao.maps.Marker({ position, map });
            const content = document.createElement('div');
            content.className = 'map-popup';
            const title = document.createElement('strong');
            title.textContent = restaurant.name;
            const address = document.createElement('div');
            address.textContent = restaurant.address;
            content.append(title, address);
            if (restaurant.category_names) {
                const category = document.createElement('div');
                category.textContent = restaurant.category_names;
                content.append(category);
            }
            if (restaurant.phone) {
                const phone = document.createElement('div');
                phone.textContent = restaurant.phone;
                content.append(phone);
            }
            const detail = document.createElement('a');
            detail.href = '<?= site_url('restaurants') ?>/' + Number(restaurant.id);
            detail.textContent = '상세 보기';
            content.append(detail);
            const infoWindow = new kakao.maps.InfoWindow({ content });
            kakao.maps.event.addListener(marker, 'click', () => openMarker(marker, infoWindow));
            markers.set(Number(restaurant.id), { marker, infoWindow });
            bounds.extend(position);
            hasBounds = true;
        });
        if (hasBounds) {
            map.setBounds(bounds);
            if (map.getLevel() < 3) map.setLevel(3);
        }
        document.querySelectorAll('[data-restaurant-id]').forEach((button) => {
            button.addEventListener('click', () => {
                const entry = markers.get(Number(button.dataset.restaurantId));
                if (!entry) return;
                map.setCenter(entry.marker.getPosition());
                if (map.getLevel() > 4) map.setLevel(4);
                openMarker(entry.marker, entry.infoWindow);
            });
        });
    });
</script>
</body>
</html>
