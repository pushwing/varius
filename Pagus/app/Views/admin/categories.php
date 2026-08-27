<?php

/**
 * @var list<array<string, mixed>> $categories
 * @var array<string, mixed>|null $editingCategory
 */
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>카테고리 관리 · 파구스 운영</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?= base_url('favicon.svg') ?>">
    <link rel="apple-touch-icon" href="<?= base_url('apple-touch-icon.png') ?>">
</head>
<body>
<div class="admin-shell">
    <?= view('admin/_header', ['active' => 'categories']) ?>
    <main class="admin-main">
        <?php if (session('message')): ?><p role="status"><?= esc(session('message')) ?></p><?php endif; ?>
        <?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>

        <section class="admin-section">
            <h1>카테고리 관리</h1>
            <form class="form-row" method="post" action="/admin/categories/save">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $editingCategory === null ? '' : (int) $editingCategory['id'] ?>">
                <label>이름 <input name="name" required maxlength="100" value="<?= esc($editingCategory['name'] ?? old('name')) ?>"></label>
                <span class="inline-actions">
                    <button type="submit"><?= $editingCategory === null ? '등록' : '수정' ?></button>
                    <?php if ($editingCategory !== null): ?><a class="btn-ghost" href="/admin/categories">취소</a><?php endif; ?>
                </span>
            </form>
            <ul class="admin-list">
                <?php foreach ($categories as $category): ?>
                    <li>
                        <span><?= esc($category['name']) ?></span>
                        <span class="badge <?= (int) $category['is_active'] === 1 ? 'badge-on' : 'badge-off' ?>"><?= (int) $category['is_active'] === 1 ? '활성' : '숨김' ?></span>
                        <span class="inline-actions">
                            <a class="btn-ghost btn-sm" href="/admin/categories/<?= (int) $category['id'] ?>/edit">수정</a>
                            <form class="inline-form" method="post" action="/admin/categories/<?= (int) $category['id'] ?>/toggle"><?= csrf_field() ?><button class="btn-ghost btn-sm" type="submit"><?= (int) $category['is_active'] === 1 ? '숨김' : '활성화' ?></button></form>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </main>
</div>
</body>
</html>
