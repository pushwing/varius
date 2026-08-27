<?php

/**
 * @var array<string, mixed> $restaurant
 * @var list<array<string, mixed>> $photos
 */
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc((string) $restaurant['name']) ?> 사진 관리 · 파구스 운영</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<div class="admin-shell">
    <header class="admin-header">
        <a class="admin-header-brand" href="/admin">파구스 운영</a>
        <nav class="admin-header-nav" aria-label="운영자 메뉴">
            <a href="/admin">맛집 관리</a>
            <a href="/admin/inquiries">문의 관리</a>
        </nav>
    </header>
    <main class="admin-main">
        <p><a href="/admin">← 목록으로</a></p>
        <h1><?= esc((string) $restaurant['name']) ?> 사진 관리</h1>
        <?php if (session('message')): ?><p role="status"><?= esc(session('message')) ?></p><?php endif; ?>
        <?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>
        <div class="admin-section">
            <form method="post" action="/admin/restaurants/<?= (int) $restaurant['id'] ?>/photos/upload" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <label>사진 선택(jpg/png/webp, 장당 5MB 이하) <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></label>
                <button type="submit">업로드</button>
            </form>
        </div>
        <?php if ($photos === []): ?>
            <p class="empty-state">등록된 사진이 없습니다.</p>
        <?php else: ?>
            <ul class="admin-list">
                <?php foreach ($photos as $photo): ?>
                    <li>
                        <img class="admin-photo-thumb" src="/admin/restaurants/<?= (int) $restaurant['id'] ?>/photos/<?= (int) $photo['id'] ?>/file" alt="<?= esc((string) $photo['original_name']) ?>" width="120" height="90">
                        <span><?= esc((string) $photo['original_name']) ?></span>
                        <span class="badge <?= (int) $photo['is_hidden'] === 1 ? 'badge-off' : 'badge-on' ?>"><?= (int) $photo['is_hidden'] === 1 ? '숨김' : '공개' ?></span>
                        <span class="inline-actions">
                            <form class="inline-form" method="post" action="/admin/restaurants/<?= (int) $restaurant['id'] ?>/photos/<?= (int) $photo['id'] ?>/toggle"><?= csrf_field() ?><button class="btn-ghost btn-sm" type="submit"><?= (int) $photo['is_hidden'] === 1 ? '공개' : '숨김' ?></button></form>
                            <form class="inline-form" method="post" action="/admin/restaurants/<?= (int) $restaurant['id'] ?>/photos/<?= (int) $photo['id'] ?>/delete" onsubmit="return confirm('삭제하시겠습니까?');"><?= csrf_field() ?><button class="btn-danger btn-sm" type="submit">삭제</button></form>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
