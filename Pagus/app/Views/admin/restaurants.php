<?php

/**
 * @var list<array<string, mixed>> $restaurants
 */
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>맛집 목록 · 파구스 운영</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?= base_url('favicon.svg') ?>">
    <link rel="apple-touch-icon" href="<?= base_url('apple-touch-icon.png') ?>">
</head>
<body>
<div class="admin-shell">
    <?= view('admin/_header', ['active' => 'restaurants']) ?>
    <main class="admin-main">
        <?php if (session('message')): ?><p role="status"><?= esc(session('message')) ?></p><?php endif; ?>
        <?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>

        <section class="admin-section">
            <h1>맛집 목록</h1>
            <form class="form-row admin-search-form" method="get" action="/admin">
                <input aria-label="맛집 검색" name="q" value="<?= esc((string) (request()->getGet('q') ?? '')) ?>">
                <span class="inline-actions">
                    <button type="submit">검색</button>
                    <a class="btn btn-ghost" href="/admin/restaurants/new">맛집 등록</a>
                </span>
            </form>
            <table class="admin-table">
                <thead><tr><th>상호</th><th>주소</th><th>카테고리</th><th>공개</th><th>관리</th></tr></thead>
                <tbody>
                <?php foreach ($restaurants as $restaurant): ?>
                    <tr>
                        <td><?= esc($restaurant['name']) ?></td>
                        <td><?= esc($restaurant['address']) ?></td>
                        <td><?= esc((string) ($restaurant['category_names'] ?? '')) ?></td>
                        <td><span class="badge <?= (int) $restaurant['is_published'] === 1 ? 'badge-on' : 'badge-off' ?>"><?= (int) $restaurant['is_published'] === 1 ? '공개' : '숨김' ?></span></td>
                        <td>
                            <span class="inline-actions">
                                <a class="btn-ghost btn-sm" href="/admin/restaurants/<?= (int) $restaurant['id'] ?>/edit">수정</a>
                                <a class="btn-ghost btn-sm" href="/admin/restaurants/<?= (int) $restaurant['id'] ?>/photos">사진관리</a>
                                <form class="inline-form" method="post" action="/admin/restaurants/<?= (int) $restaurant['id'] ?>/toggle"><?= csrf_field() ?><button class="btn-ghost btn-sm" type="submit"><?= (int) $restaurant['is_published'] === 1 ? '숨김' : '공개' ?></button></form>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>
    <footer class="site-footer">
        <p>© <?= date('Y') ?> 파구스. All rights reserved.</p>
        <p><a href="mailto:advisor@aivance.kr">advisor@aivance.kr</a></p>
        <p>파구스는 오픈소스 프로젝트입니다. <a href="https://github.com/pushwing/varius/tree/main/Pagus" target="_blank" rel="noopener noreferrer">GitHub에서 소스코드 보기</a></p>
    </footer>
</div>
</body>
</html>
