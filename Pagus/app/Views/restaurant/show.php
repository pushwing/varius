<?php

/**
 * @var array<string, mixed> $restaurant
 * @var list<array<string, mixed>> $photos
 * @var list<array<string, mixed>> $reviews
 */
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc((string) $restaurant['name']) ?> · 파구스</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <script src="<?= base_url('assets/js/share.js') ?>"></script>
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
    <?php $shareUrl = site_url('restaurants/' . (int) $restaurant['id']); ?>
    <?php
    $directionsUrl = 'https://map.kakao.com/link/to/'
        . rawurlencode((string) $restaurant['name']) . ','
        . rawurlencode((string) $restaurant['latitude']) . ','
        . rawurlencode((string) $restaurant['longitude']);
?>
    <div class="detail-actions">
        <a class="directions-link" href="<?= esc($directionsUrl, 'attr') ?>" rel="noopener noreferrer" target="_blank"><?= esc((string) $restaurant['name']) ?> 길찾기</a>
        <button class="btn-ghost share-button" type="button" data-share-place>이 장소 공유</button>
    </div>
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

    <section id="reviews" aria-labelledby="reviews-title">
        <h2 id="reviews-title">방문 후기 (<?= count($reviews) ?>)</h2>
        <?php if (session('message')): ?><p role="status"><?= esc(session('message')) ?></p><?php endif; ?>
        <?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>
        <?php if ($reviews === []): ?><p class="empty-state">아직 등록된 후기가 없습니다.</p><?php else: ?>
            <div class="review-list">
                <?php foreach ($reviews as $review): ?>
                    <article class="review-item">
                        <header><strong><?= esc((string) $review['nickname']) ?></strong> · <span aria-label="별점 <?= (int) $review['rating'] ?>점"><?= str_repeat('★', (int) $review['rating']) . str_repeat('☆', 5 - (int) $review['rating']) ?></span></header>
                        <p><?= nl2br(esc((string) $review['content'])) ?></p>
                        <small><?= esc((string) $review['created_at']) ?></small>
                        <details><summary>내 후기 수정·삭제</summary>
                            <form method="post" action="/restaurants/<?= (int) $restaurant['id'] ?>/reviews/<?= (int) $review['id'] ?>">
                                <?= csrf_field() ?><label>닉네임 <input type="text" name="nickname" maxlength="50" value="<?= esc((string) $review['nickname']) ?>" required></label><label>별점 <select name="rating" required><?php for ($rating = 5; $rating >= 1; $rating--): ?><option value="<?= $rating ?>" <?= (int) $review['rating'] === $rating ? 'selected' : '' ?>><?= $rating ?>점</option><?php endfor; ?></select></label><label>후기 내용 <textarea name="content" maxlength="2000" required><?= esc((string) $review['content']) ?></textarea></label><label>작성 시 비밀번호 <input type="password" name="author_password" minlength="8" maxlength="72" required></label><button class="btn-ghost btn-sm" type="submit">수정</button>
                            </form>
                            <form method="post" action="/restaurants/<?= (int) $restaurant['id'] ?>/reviews/<?= (int) $review['id'] ?>/delete" onsubmit="return confirm('후기를 삭제하시겠습니까?');">
                                <?= csrf_field() ?><label>작성 시 비밀번호 <input type="password" name="author_password" minlength="8" maxlength="72" required></label><button class="btn-danger btn-sm" type="submit">삭제</button>
                            </form>
                        </details>
                        <form method="post" action="/reviews/<?= (int) $review['id'] ?>/reports">
                            <?= csrf_field() ?><input type="hidden" name="return_path" value="<?= esc('/restaurants/' . (int) $restaurant['id'], 'attr') ?>">
                            <label>신고 사유 <input type="text" name="reason" maxlength="100" required></label><button class="btn-ghost btn-sm" type="submit">신고</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <section id="review-form" aria-labelledby="review-form-title">
        <h2 id="review-form-title">후기 남기기</h2>
        <p>로그인 없이 작성할 수 있습니다. 다른 사람을 존중하는 후기를 남겨주세요.</p>
        <form method="post" action="/restaurants/<?= (int) $restaurant['id'] ?>/reviews">
            <?= csrf_field() ?>
            <label>닉네임 <input type="text" name="nickname" maxlength="50" value="<?= esc((string) old('nickname')) ?>" required></label>
            <label>별점 <select name="rating" required><option value="">선택</option><?php for ($rating = 5; $rating >= 1; $rating--): ?><option value="<?= $rating ?>" <?= (string) old('rating') === (string) $rating ? 'selected' : '' ?>><?= $rating ?>점</option><?php endfor; ?></select></label>
            <label>후기 내용 <textarea name="content" maxlength="2000" required><?= esc((string) old('content')) ?></textarea></label>
            <label>수정·삭제 비밀번호 <input type="password" name="author_password" minlength="8" maxlength="72" required></label>
            <button type="submit">후기 등록</button>
        </form>
    </section>
</main>
<footer class="site-footer">
    <p>© <?= date('Y') ?> 파구스. All rights reserved.</p>
    <p><a href="mailto:advisor@aivance.kr">advisor@aivance.kr</a></p>
    <p><a href="<?= site_url('login') ?>">로그인</a></p>
    <p>파구스는 오픈소스 프로젝트입니다. <a href="https://github.com/pushwing/varius/tree/main/Pagus" target="_blank" rel="noopener noreferrer">GitHub에서 소스코드 보기</a></p>
</footer>
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
    const sharePlaceButton = document.querySelector('[data-share-place]');
    sharePlaceButton?.addEventListener('click', () => window.sharePlace(<?= json_encode((string) $restaurant['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($shareUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, sharePlaceButton));

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
