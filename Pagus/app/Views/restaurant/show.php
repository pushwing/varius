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
    <style>
        :root { color: #17202a; font-family: system-ui, -apple-system, sans-serif; }
        body { margin: 0; background: #f5f7f8; }
        header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 5vw; background: #173b35; color: #fff; }
        header a { color: #d9f2e9; text-decoration: none; }
        main { max-width: 48rem; margin: 0 auto; padding: 1.5rem 5vw; }
        .category { color: #206b58; }
        .photos { display: flex; flex-wrap: wrap; gap: .5rem; margin: 1rem 0; }
        .photos img { width: 12rem; height: 9rem; object-fit: cover; border-radius: .5rem; }
        section { margin: 1.25rem 0; }
        section h2 { font-size: 1rem; margin: 0 0 .3rem; }
    </style>
</head>
<body>
<header>
    <h1>파구스</h1>
    <nav aria-label="주요 메뉴"><a href="<?= site_url('/') ?>">목록으로</a></nav>
</header>
<main>
    <h1><?= esc((string) $restaurant['name']) ?></h1>
    <p class="category"><?= esc((string) ($restaurant['category_names'] ?? '카테고리 미지정')) ?></p>
    <p><?= esc((string) $restaurant['address']) ?></p>
    <?php if ((string) ($restaurant['phone'] ?? '') !== ''): ?><p><?= esc((string) $restaurant['phone']) ?></p><?php endif; ?>
    <?php if ((string) ($restaurant['homepage_url'] ?? '') !== ''): ?><p><a href="<?= esc((string) $restaurant['homepage_url'], 'attr') ?>" rel="noopener noreferrer" target="_blank"><?= esc((string) $restaurant['homepage_url']) ?></a></p><?php endif; ?>

    <?php if ($photos !== []): ?>
        <section aria-label="사진">
            <div class="photos">
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
