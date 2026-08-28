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
    <h1><a href="<?= site_url('/') ?>"><img class="brand-mark" src="<?= base_url('assets/img/logo-mark.svg') ?>" alt="">파구스 · 파주 로컬 맛집 지도</a></h1>
    <nav aria-label="주요 메뉴"><a href="<?= site_url('/') ?>">목록으로</a></nav>
</header>
<main class="detail-main">
    <h1><?= esc((string) $restaurant['name']) ?></h1>
    <p class="category"><?= esc((string) ($restaurant['category_names'] ?? '카테고리 미지정')) ?></p>
    <p><?= esc((string) $restaurant['address']) ?></p>
    <?php
    $directionsUrl = 'https://map.kakao.com/link/to/'
        . rawurlencode((string) $restaurant['name']) . ','
        . rawurlencode((string) $restaurant['latitude']) . ','
        . rawurlencode((string) $restaurant['longitude']);
?>
    <p><a class="directions-link" href="<?= esc($directionsUrl, 'attr') ?>" rel="noopener noreferrer" target="_blank"><?= esc((string) $restaurant['name']) ?> 길찾기</a></p>
    <?php if ((string) ($restaurant['phone'] ?? '') !== ''): ?><p><?= esc((string) $restaurant['phone']) ?></p><?php endif; ?>
    <?php if ((string) ($restaurant['homepage_url'] ?? '') !== ''): ?><p><a href="<?= esc((string) $restaurant['homepage_url'], 'attr') ?>" rel="noopener noreferrer" target="_blank"><?= esc((string) $restaurant['homepage_url']) ?></a></p><?php endif; ?>

    <?php if ($photos !== []): ?>
        <section aria-label="사진">
            <div class="photo-grid">
                <?php foreach ($photos as $photoIndex => $photo): ?>
                    <?php $photoAlt = (string) $restaurant['name'] . ' 사진'; ?>
                    <button
                        class="photo-trigger"
                        type="button"
                        data-photo-index="<?= (int) $photoIndex ?>"
                        data-photo-url="/photos/<?= (int) $photo['id'] ?>"
                        data-photo-alt="<?= esc($photoAlt, 'attr') ?>"
                        aria-label="<?= esc($photoAlt) ?> 크게 보기"
                    >
                        <img src="/photos/<?= (int) $photo['id'] ?>" alt="<?= esc($photoAlt, 'attr') ?>" loading="lazy">
                    </button>
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
    <?php
    $tagList = array_values(array_filter(
        array_map('trim', explode(',', (string) ($restaurant['tags'] ?? ''))),
        static fn (string $tag): bool => $tag !== ''
    ));
?>
    <?php if ($tagList !== []): ?>
        <section>
            <h2>태그</h2>
            <div class="tag-chip-list">
                <?php foreach ($tagList as $tag): ?>
                    <a class="tag-chip" href="<?= esc(site_url('/') . '?' . http_build_query(['q' => $tag]), 'attr') ?>">#<?= esc($tag) ?></a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</main>
<dialog id="photo-dialog" class="photo-dialog" aria-labelledby="photo-dialog-title">
    <div class="photo-dialog__header">
        <h2 id="photo-dialog-title">사진 크게 보기 <span id="photo-dialog-count" aria-live="polite">(<?= $photos !== [] ? '1/' . count($photos) : '0/0' ?>)</span></h2>
        <button type="button" class="btn-ghost btn-sm" data-photo-close>닫기</button>
    </div>
    <div class="photo-dialog__viewer">
        <?php if (count($photos) > 1): ?>
            <button type="button" class="photo-nav photo-nav--previous" data-photo-previous aria-label="이전 사진"><span aria-hidden="true">←</span></button>
        <?php endif; ?>
        <img id="photo-dialog-image" src="" alt="">
        <?php if (count($photos) > 1): ?>
            <button type="button" class="photo-nav photo-nav--next" data-photo-next aria-label="다음 사진"><span aria-hidden="true">→</span></button>
        <?php endif; ?>
    </div>
</dialog>
<script>
    const photoDialog = document.getElementById('photo-dialog');
    const photoDialogImage = document.getElementById('photo-dialog-image');
    const photoDialogCount = document.getElementById('photo-dialog-count');
    const photoTriggers = [...document.querySelectorAll('.photo-trigger')];
    const previousPhotoButton = document.querySelector('[data-photo-previous]');
    const nextPhotoButton = document.querySelector('[data-photo-next]');
    let currentPhotoIndex = 0;

    const showPhoto = (index) => {
        const trigger = photoTriggers[index];
        if (!trigger) {
            return;
        }

        currentPhotoIndex = index;
        photoDialogImage.src = trigger.dataset.photoUrl;
        photoDialogImage.alt = trigger.dataset.photoAlt;
        photoDialogCount.textContent = `(${currentPhotoIndex + 1}/${photoTriggers.length})`;
        if (previousPhotoButton && nextPhotoButton) {
            previousPhotoButton.disabled = currentPhotoIndex === 0;
            nextPhotoButton.disabled = currentPhotoIndex === photoTriggers.length - 1;
        }
    };

    photoTriggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            showPhoto(Number(trigger.dataset.photoIndex));
            photoDialog.showModal();
        });
    });

    document.querySelector('[data-photo-close]').addEventListener('click', () => photoDialog.close());
    previousPhotoButton?.addEventListener('click', () => showPhoto(currentPhotoIndex - 1));
    nextPhotoButton?.addEventListener('click', () => showPhoto(currentPhotoIndex + 1));
    photoDialog.addEventListener('click', (event) => {
        if (event.target !== photoDialog) {
            return;
        }

        const bounds = photoDialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
            photoDialog.close();
        }
    });
    photoDialog.addEventListener('close', () => {
        photoDialogImage.src = '';
        photoDialogImage.alt = '';
    });
</script>
</body>
</html>
