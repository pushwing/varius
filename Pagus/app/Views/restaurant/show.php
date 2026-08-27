<?php

/**
 * @var array<string, mixed> $restaurant
 * @var list<array<string, mixed>> $photos
 */
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc((string) $restaurant['name']) ?> · 파구스</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?= base_url('favicon.svg') ?>">
    <link rel="apple-touch-icon" href="<?= base_url('apple-touch-icon.png') ?>">
</head>
<body>
<header class="site-header">
    <h1><img class="brand-mark" src="<?= base_url('assets/img/logo-mark.svg') ?>" alt="">파구스</h1>
    <nav aria-label="주요 메뉴"><a href="<?= site_url('/') ?>">목록으로</a></nav>
</header>
<main class="detail-main">
    <h1><?= esc((string) $restaurant['name']) ?></h1>
    <p class="category"><?= esc((string) ($restaurant['category_names'] ?? '카테고리 미지정')) ?></p>
    <p><?= esc((string) $restaurant['address']) ?></p>
    <?php if ((string) ($restaurant['phone'] ?? '') !== ''): ?><p><?= esc((string) $restaurant['phone']) ?></p><?php endif; ?>
    <?php if ((string) ($restaurant['homepage_url'] ?? '') !== ''): ?><p><a href="<?= esc((string) $restaurant['homepage_url'], 'attr') ?>" rel="noopener noreferrer" target="_blank"><?= esc((string) $restaurant['homepage_url']) ?></a></p><?php endif; ?>

    <?php if ($photos !== []): ?>
        <section aria-label="사진">
            <div class="photo-grid">
                <?php foreach ($photos as $photo): ?>
                    <img src="/photos/<?= (int) $photo['id'] ?>" alt="<?= esc((string) $restaurant['name']) ?> 사진" loading="lazy">
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ((string) ($restaurant['description'] ?? '') !== ''): ?>
        <section><h2>소개</h2><p><?= nl2br(esc((string) $restaurant['description'])) ?></p></section>
    <?php endif; ?>
    <?php if ((string) ($restaurant['menu'] ?? '') !== ''): ?>
        <section><h2>메뉴</h2><p><?= nl2br(esc((string) $restaurant['menu'])) ?></p></section>
    <?php endif; ?>
    <?php if ((string) ($restaurant['business_hours'] ?? '') !== ''): ?>
        <section><h2>영업 정보</h2><p><?= nl2br(esc((string) $restaurant['business_hours'])) ?></p></section>
    <?php endif; ?>
    <?php if ((string) ($restaurant['tags'] ?? '') !== ''): ?>
        <section><h2>태그</h2><p><?= esc((string) $restaurant['tags']) ?></p></section>
    <?php endif; ?>
</main>
</body>
</html>
