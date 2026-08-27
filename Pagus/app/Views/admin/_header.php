<?php

/**
 * @var string $active
 */
?>
<header class="admin-header">
    <a class="admin-header-brand" href="/admin"><img class="brand-mark" src="/assets/img/logo-mark.svg" alt="">파구스 운영</a>
    <nav class="admin-header-nav" aria-label="운영자 메뉴">
        <a href="/admin"<?= $active === 'restaurants' ? ' aria-current="page"' : '' ?>>맛집 목록</a>
        <a href="/admin/restaurants/new"<?= $active === 'restaurant-form' ? ' aria-current="page"' : '' ?>>맛집 등록</a>
        <a href="/admin/categories"<?= $active === 'categories' ? ' aria-current="page"' : '' ?>>카테고리 관리</a>
        <a href="/admin/inquiries"<?= $active === 'inquiries' ? ' aria-current="page"' : '' ?>>문의 관리</a>
        <span class="admin-header-user"><?= esc(session('user_name')) ?>님</span>
        <form class="inline-form" method="post" action="/logout"><?= csrf_field() ?><button class="btn-ghost btn-sm" type="submit">로그아웃</button></form>
    </nav>
</header>
