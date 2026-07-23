<?php

declare(strict_types=1);

/**
 * 로그인 후 상단 메뉴 — 로그인 상태로 렌더되는 모든 페이지(사진 가져오기·지도 등)가 공유한다.
 *
 * @var string $uploadUrl
 * @var string $mapUrl
 * @var string $tripsUrl
 * @var string $logoutUrl
 */

helper('url'); // 발자국 링크는 nav 가 직접 생성 — 호출 뷰 전부에 파라미터를 추가하지 않기 위함
?>
<nav>
    <a href="/" class="brand"><img src="/assets/logo-mark-512.png" alt="Iter"></a>
    <a href="<?= esc($uploadUrl, 'attr') ?>">사진 가져오기</a>
    <a href="<?= esc($mapUrl, 'attr') ?>">지도 보기</a>
    <a href="<?= esc($tripsUrl, 'attr') ?>">내 여행</a>
    <a href="<?= esc(site_url('footprint'), 'attr') ?>">발자국</a>
    <a href="<?= esc($logoutUrl, 'attr') ?>">로그아웃</a>
    <span class="spacer"></span>
    <span class="legal">
        <a href="/privacy-policy.html">개인정보처리방침</a>
        <a href="/terms-of-service.html">서비스 이용약관</a>
    </span>
</nav>
