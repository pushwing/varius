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
    <script src="<?= base_url('assets/js/share.js') ?>"></script>
    <link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?= base_url('favicon.svg') ?>">
    <link rel="apple-touch-icon" href="<?= base_url('apple-touch-icon.png') ?>">
</head>
<body>
<header class="site-header">
    <h1><img class="brand-mark" src="<?= base_url('assets/img/logo-mark.svg') ?>" alt="">파구스 · 파주 로컬 맛집 지도</h1>
    <nav aria-label="주요 메뉴">
        <a href="<?= site_url('/') ?>">홈</a>
        <button type="button" class="nav-button" data-contact-open>문의하기</button>
    </nav>
</header>
<dialog id="contact" class="contact-dialog" aria-labelledby="contact-title">
    <div class="contact-dialog__header">
        <h2 id="contact-title">문의하기</h2>
        <button type="button" class="btn-ghost btn-sm" data-contact-close aria-label="문의하기 닫기">닫기</button>
    </div>
    <?php if (session('message')): ?><p role="status"><?= esc(session('message')) ?></p><?php endif; ?>
    <?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>
    <form class="form-grid" method="post" action="<?= site_url('inquiries') ?>">
        <?= csrf_field() ?>
        <label for="contact-name">이름 <input id="contact-name" name="name" required maxlength="100" value="<?= esc(old('name') ?? '') ?>"></label>
        <label for="contact-detail">연락처(선택, 회신용) <input id="contact-detail" name="contact" maxlength="255" value="<?= esc(old('contact') ?? '') ?>"></label>
        <label for="contact-message">문의 내용 <textarea id="contact-message" name="message" required maxlength="2000" rows="4"><?= esc(old('message') ?? '') ?></textarea></label>
        <button type="submit">문의 보내기</button>
    </form>
</dialog>
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
<footer class="site-footer">
    <p>© <?= date('Y') ?> 파구스. All rights reserved.</p>
    <p><a href="mailto:advisor@aivance.kr">advisor@aivance.kr</a></p>
    <p><a href="<?= site_url('login') ?>">로그인</a></p>
</footer>
<script src="https://dapi.kakao.com/v2/maps/sdk.js?autoload=false&appkey=<?= esc(config(\Config\KakaoMaps::class)->jsKey, 'url') ?>"></script>
<script>
    const restaurants = <?= $mapData ?>;
    const contactDialog = document.getElementById('contact');
    const contactOpenButton = document.querySelector('[data-contact-open]');
    const contactCloseButton = document.querySelector('[data-contact-close]');
    const openContactDialog = () => {
        if (!contactDialog.open) contactDialog.showModal();
        history.replaceState(null, '', '#contact');
    };
    contactOpenButton?.addEventListener('click', openContactDialog);
    contactCloseButton?.addEventListener('click', () => {
        contactDialog.close();
        if (window.location.hash === '#contact') history.replaceState(null, '', window.location.pathname + window.location.search);
    });
    contactDialog?.addEventListener('click', (event) => {
        if (event.target === contactDialog) contactCloseButton?.click();
    });
    if (window.location.hash === '#contact') openContactDialog();
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
        function openMarker(marker, infoWindow, restaurantId) {
            if (openInfoWindow) openInfoWindow.close();
            infoWindow.open(map, marker);
            openInfoWindow = infoWindow;
            document.querySelectorAll('.restaurant-card.is-selected').forEach((card) => card.classList.remove('is-selected'));
            document.querySelector(`[data-restaurant-id="${restaurantId}"]`)?.closest('.restaurant-card')?.classList.add('is-selected');
        }
        restaurants.forEach((restaurant) => {
            const lat = Number(restaurant.latitude);
            const lon = Number(restaurant.longitude);
            if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
            const position = new kakao.maps.LatLng(lat, lon);
            const marker = new kakao.maps.Marker({ position, map });
            const content = document.createElement('article');
            content.className = 'map-popup';
            const header = document.createElement('header');
            header.className = 'map-popup__header';
            const kicker = document.createElement('span');
            kicker.className = 'map-popup__kicker';
            kicker.textContent = '파주 로컬 맛집';
            const title = document.createElement('h3');
            title.textContent = restaurant.name;
            header.append(kicker, title);
            content.append(header);
            if (restaurant.category_names) {
                const category = document.createElement('span');
                category.className = 'map-popup__category';
                category.textContent = restaurant.category_names;
                content.append(category);
            }
            const details = document.createElement('dl');
            details.className = 'map-popup__details';
            const appendDetail = (label, value) => {
                if (!value) return;
                const term = document.createElement('dt');
                term.textContent = label;
                const description = document.createElement('dd');
                description.textContent = value;
                details.append(term, description);
            };
            appendDetail('주소', restaurant.address);
            appendDetail('전화', restaurant.phone);
            appendDetail('영업 정보', restaurant.business_hours);
            if (details.childElementCount > 0) {
                content.append(details);
            }
            if (restaurant.description) {
                const summary = document.createElement('p');
                summary.className = 'map-popup__summary';
                summary.textContent = restaurant.description;
                content.append(summary);
            }
            const actions = document.createElement('div');
            actions.className = 'map-popup__actions';
            const detail = document.createElement('a');
            detail.className = 'map-popup__link';
            detail.href = '<?= site_url('restaurants') ?>/' + Number(restaurant.id);
            detail.textContent = '상세 정보 보기';
            const share = document.createElement('button');
            share.className = 'map-popup__share btn-ghost';
            share.type = 'button';
            share.textContent = '이 장소 공유';
            share.addEventListener('click', () => window.sharePlace(restaurant.name, detail.href, share));
            actions.append(detail, share);
            content.append(actions);
            const infoWindow = new kakao.maps.InfoWindow({ content, removable: true });
            kakao.maps.event.addListener(marker, 'click', () => openMarker(marker, infoWindow, restaurant.id));
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
                openMarker(entry.marker, entry.infoWindow, button.dataset.restaurantId);
            });
        });
    });
</script>
</body>
</html>
