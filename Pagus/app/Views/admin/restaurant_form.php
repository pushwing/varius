<?php

/**
 * @var list<array<string, mixed>> $categories
 * @var array<string, mixed>|null $editingRestaurant
 */
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $editingRestaurant === null ? '맛집 등록' : '맛집 수정' ?> · 파구스 운영</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?= base_url('favicon.svg') ?>">
    <link rel="apple-touch-icon" href="<?= base_url('apple-touch-icon.png') ?>">
</head>
<body>
<div class="admin-shell">
    <?= view('admin/_header', ['active' => 'restaurant-form']) ?>
    <main class="admin-main">
        <?php if (session('message')): ?><p role="status"><?= esc(session('message')) ?></p><?php endif; ?>
        <?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>

        <section class="admin-section">
            <h1><?= $editingRestaurant === null ? '맛집 등록' : '맛집 수정' ?></h1>
            <form class="form-grid" method="post" action="/admin/restaurants/save">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $editingRestaurant === null ? '' : (int) $editingRestaurant['id'] ?>">
                <div class="location-search" id="category-recommendation-form">
                    <label for="restaurant-name">상호</label>
                    <input id="restaurant-name" name="name" required maxlength="150" value="<?= esc($editingRestaurant['name'] ?? old('name')) ?>">
                    <button id="category-recommend-submit" type="button" class="btn-ghost">AI 카테고리 추천</button>
                    <p id="category-recommend-message" class="location-search-message" role="status">상호를 입력하고 추천을 요청하면 카테고리를 자동으로 선택합니다.</p>
                </div>

                <div class="location-search" id="reference-search-form" role="search">
                    <label for="reference-search-query">외부 참고 데이터 검색 (카카오)</label>
                    <input id="reference-search-query" minlength="2" maxlength="100" autocomplete="off" placeholder="상호명으로 검색">
                    <button id="reference-search-submit" type="button" class="btn-ghost">검색</button>
                    <p id="reference-search-message" class="location-search-message" role="status">결과는 참고용입니다. 공개 여부·권한은 운영자가 직접 확인 후 결정하세요. 조회가 안 되면 아래 항목을 직접 입력할 수 있습니다.</p>
                    <div id="reference-search-results"></div>
                    <p class="reference-attribution">검색 결과 제공: 카카오</p>
                </div>
                <div class="location-search" id="address-search-form" role="search">
                    <label for="restaurant-address">주소 (2자 이상 입력 후 검색하면 도로명 주소와 좌표를 채웁니다)</label>
                    <input id="restaurant-address" name="address" required maxlength="255" autocomplete="street-address" value="<?= esc($editingRestaurant['address'] ?? old('address')) ?>">
                    <button id="address-search-submit" type="button" class="btn-ghost">검색</button>
                    <p id="address-search-message" class="location-search-message" role="status">검색 실패 시 주소와 좌표를 직접 입력할 수 있습니다.</p>
                    <div id="address-search-results"></div>
                </div>
                <div id="restaurant-map" aria-label="맛집 위치 선택 지도"></div>

                <div class="form-row">
                    <label>위도 <input id="restaurant-latitude" name="latitude" required type="number" step="any" min="-90" max="90" value="<?= esc($editingRestaurant['latitude'] ?? old('latitude')) ?>"></label>
                    <label>경도 <input id="restaurant-longitude" name="longitude" required type="number" step="any" min="-180" max="180" value="<?= esc($editingRestaurant['longitude'] ?? old('longitude')) ?>"></label>
                </div>
                <div class="form-row">
                    <label>연락처 <input id="restaurant-phone" name="phone" maxlength="30" value="<?= esc($editingRestaurant['phone'] ?? old('phone')) ?>"></label>
                    <label>홈페이지 <input name="homepage_url" type="url" maxlength="2048" value="<?= esc($editingRestaurant['homepage_url'] ?? old('homepage_url')) ?>"></label>
                </div>

                <label class="checkbox-label"><input type="checkbox" name="is_published" value="1" <?= (int) ($editingRestaurant['is_published'] ?? old('is_published') ?? 0) === 1 ? 'checked' : '' ?>> 공개</label>

                <div>
                    <label>카테고리</label>
                    <div class="checkbox-group">
                        <?php
                            $selected = $editingRestaurant['category_ids'] ?? (array) old('category_ids');
foreach ($categories as $category): ?>
                            <label class="checkbox-label"><input type="checkbox" name="category_ids[]" value="<?= (int) $category['id'] ?>" <?= in_array((int) $category['id'], array_map('intval', $selected), true) ? 'checked' : '' ?>><?= esc($category['name']) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <label>설명 <textarea name="description" rows="3"><?= esc($editingRestaurant['description'] ?? old('description')) ?></textarea></label>
                <label>메뉴 <textarea name="menu" rows="3"><?= esc($editingRestaurant['menu'] ?? old('menu')) ?></textarea></label>
                <label>영업 정보 <textarea name="business_hours" rows="3"><?= esc($editingRestaurant['business_hours'] ?? old('business_hours')) ?></textarea></label>
                <div>
                    <label for="tag-input">태그 (입력 후 쉼표나 Enter로 추가)</label>
                    <div id="tag-chips" class="tag-chip-list"></div>
                    <input id="tag-input" maxlength="100" autocomplete="off">
                    <input type="hidden" name="tags" id="restaurant-tags" maxlength="500" value="<?= esc($editingRestaurant['tags'] ?? old('tags')) ?>">
                </div>

                <span class="inline-actions">
                    <button type="submit">저장</button>
                    <a class="btn-ghost" href="/admin">취소</a>
                </span>
            </form>
        </section>
    </main>
    <footer class="site-footer">
        <p>© <?= date('Y') ?> 파구스. All rights reserved.</p>
        <p><a href="mailto:advisor@aivance.kr">advisor@aivance.kr</a></p>
        <p>파구스는 오픈소스 프로젝트입니다. <a href="https://github.com/pushwing/varius/tree/main/Pagus" target="_blank" rel="noopener noreferrer">GitHub에서 소스코드 보기</a></p>
    </footer>
</div>
<script src="https://dapi.kakao.com/v2/maps/sdk.js?autoload=false&appkey=<?= esc(config(\Config\KakaoMaps::class)->jsKey, 'url') ?>"></script><script>
(() => {
    const name = document.getElementById('restaurant-name'); const phone = document.getElementById('restaurant-phone'); const address = document.getElementById('restaurant-address'); const latitude = document.getElementById('restaurant-latitude'); const longitude = document.getElementById('restaurant-longitude'); const message = document.getElementById('address-search-message'); const results = document.getElementById('address-search-results'); const referenceMessage = document.getElementById('reference-search-message'); const referenceResults = document.getElementById('reference-search-results'); const categoryRecommendMessage = document.getElementById('category-recommend-message'); let map = null; let marker = null;
    const validLocation = (lat, lon) => Number.isFinite(lat) && Number.isFinite(lon) && lat >= -90 && lat <= 90 && lon >= -180 && lon <= 180;
    function setLocation(lat, lon, label = '') {
        if (!validLocation(lat, lon)) return;
        latitude.value = lat.toFixed(7); longitude.value = lon.toFixed(7);
        if (label !== '') address.value = label;
        if (!map) return;
        const position = new kakao.maps.LatLng(lat, lon);
        if (marker) marker.setPosition(position); else marker = new kakao.maps.Marker({ position, map });
        map.setCenter(position);
        if (map.getLevel() > 4) map.setLevel(4);
    }
    if (window.kakao && window.kakao.maps) {
        kakao.maps.load(() => {
            map = new kakao.maps.Map(document.getElementById('restaurant-map'), { center: new kakao.maps.LatLng(37.76, 126.78), level: 7 });
            kakao.maps.event.addListener(map, 'click', (event) => setLocation(event.latLng.getLat(), event.latLng.getLng()));
            const initialLat = Number(latitude.value); const initialLon = Number(longitude.value);
            if (validLocation(initialLat, initialLon)) setLocation(initialLat, initialLon);
        });
    } else {
        document.getElementById('restaurant-map').textContent = '지도를 불러오지 못했습니다. 아래 좌표를 직접 입력하세요.';
    }
    const searchAddress = async () => { results.replaceChildren(); const query = address.value.trim(); if (query.length < 2 || query.length > 100) { message.textContent = '주소는 2자 이상 100자 이하로 입력 후 검색하세요.'; return; } const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 7000); message.textContent = '주소 검색 중...'; try { const response = await fetch('/admin/restaurants/search-address?q=' + encodeURIComponent(query), {signal: controller.signal, headers: {'Accept': 'application/json'}}); const payload = await response.json(); if (!response.ok) throw new Error(payload.error || '주소 검색을 사용할 수 없습니다.'); if (!payload.results.length) { message.textContent = '검색 결과가 없습니다. 주소와 좌표를 직접 입력하세요.'; return; } message.textContent = '결과를 선택하면 주소와 좌표가 입력됩니다.'; payload.results.forEach((item) => { const button = document.createElement('button'); button.type = 'button'; button.className = 'address-result'; button.textContent = item.display_name; button.addEventListener('click', () => setLocation(item.latitude, item.longitude, item.display_name)); results.append(button); }); } catch { message.textContent = '주소 검색을 사용할 수 없습니다. 주소와 좌표를 직접 입력하세요.'; } finally { clearTimeout(timer); } };
    document.getElementById('address-search-submit').addEventListener('click', searchAddress); address.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); searchAddress(); } });
    const searchReference = async () => { referenceResults.replaceChildren(); const query = document.getElementById('reference-search-query').value.trim(); if (query.length < 2 || query.length > 100) { referenceMessage.textContent = '참고 데이터 검색어는 2자 이상 100자 이하로 입력하세요.'; return; } const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 7000); referenceMessage.textContent = '참고 데이터 조회 중...'; try { const response = await fetch('/admin/restaurants/search-reference?q=' + encodeURIComponent(query), {signal: controller.signal, headers: {'Accept': 'application/json'}}); const payload = await response.json(); if (!response.ok) throw new Error(payload.error || '참고 데이터 조회를 사용할 수 없습니다.'); if (!payload.results.length) { referenceMessage.textContent = '참고 데이터가 없습니다. 상호·주소·좌표를 직접 입력하세요.'; return; } referenceMessage.textContent = '결과는 참고용입니다. 선택하면 상호·주소·연락처·좌표가 채워지며, 공개 여부는 저장 전 직접 확인하세요.'; payload.results.forEach((item) => { const button = document.createElement('button'); button.type = 'button'; button.className = 'address-result'; button.textContent = item.name + ' · ' + item.address; button.addEventListener('click', () => { name.value = item.name; if (item.phone) phone.value = item.phone; setLocation(item.latitude, item.longitude, item.address); }); referenceResults.append(button); }); } catch { referenceMessage.textContent = '참고 데이터 조회를 사용할 수 없습니다. 상호·주소·좌표를 직접 입력하세요.'; } finally { clearTimeout(timer); } };
    document.getElementById('reference-search-submit').addEventListener('click', searchReference); document.getElementById('reference-search-query').addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); searchReference(); } });
    const recommendCategory = async () => { const query = name.value.trim(); if (query.length < 1 || query.length > 150) { categoryRecommendMessage.textContent = '상호를 1자 이상 150자 이하로 입력하세요.'; return; } categoryRecommendMessage.textContent = 'AI 카테고리 추천 중...'; try { const response = await fetch('/admin/restaurants/recommend-category?name=' + encodeURIComponent(query), {headers: {'Accept': 'application/json'}}); const payload = await response.json(); if (!response.ok) throw new Error(payload.error || 'Groq API 호출에 실패했습니다. 카테고리를 직접 선택하세요.'); const ids = new Set((payload.category_ids || []).map((id) => String(id))); document.querySelectorAll('input[name="category_ids[]"]').forEach((input) => { if (ids.has(input.value)) input.checked = true; }); categoryRecommendMessage.textContent = ids.size > 0 ? '추천 카테고리를 선택했습니다. 저장 전 직접 확인하세요.' : (payload.message || 'Groq API는 정상 응답했지만 입력된 상호에 맞는 카테고리를 찾지 못했습니다. 직접 선택하세요.'); } catch (error) { categoryRecommendMessage.textContent = error.message || 'Groq API 호출에 실패했습니다. 카테고리를 직접 선택하세요.'; } };
    document.getElementById('category-recommend-submit').addEventListener('click', recommendCategory); name.addEventListener('blur', () => { if (name.value.trim() !== '') recommendCategory(); });
    const tagsHidden = document.getElementById('restaurant-tags'); const tagInput = document.getElementById('tag-input'); const tagChips = document.getElementById('tag-chips');
    let tags = tagsHidden.value.split(',').map((tag) => tag.trim()).filter((tag) => tag !== '');
    function renderTags() { tagChips.replaceChildren(); tags.forEach((tag, index) => { const chip = document.createElement('span'); chip.className = 'tag-chip'; chip.textContent = tag + ' '; const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.setAttribute('aria-label', tag + ' 태그 삭제'); remove.addEventListener('click', () => { tags.splice(index, 1); syncTags(); }); chip.append(remove); tagChips.append(chip); }); }
    function syncTags() { tagsHidden.value = tags.join(','); renderTags(); }
    let isComposingTag = false;
    function commitTagInput() {
        tagInput.value.split(',').forEach((part) => { const value = part.trim(); if (value !== '' && !tags.includes(value)) tags.push(value); });
        syncTags();
        // 한글(IME) 조합 종료 직후 값을 바로 비우면 조합 중이던 마지막 글자가 다시 끼어드는
        // 브라우저가 있어, 다음 이벤트 루프 틱으로 값 초기화를 미룬다.
        setTimeout(() => { tagInput.value = ''; }, 0);
    }
    tagInput.addEventListener('compositionstart', () => { isComposingTag = true; });
    tagInput.addEventListener('compositionend', () => { isComposingTag = false; if (tagInput.value.includes(',')) commitTagInput(); });
    tagInput.addEventListener('input', () => { if (!isComposingTag && tagInput.value.includes(',')) commitTagInput(); });
    tagInput.addEventListener('keydown', (event) => { if (event.key === 'Enter' && !isComposingTag) { event.preventDefault(); commitTagInput(); } });
    tagInput.addEventListener('blur', commitTagInput);
    renderTags();
})();
</script></body></html>
