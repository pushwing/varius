<?php

declare(strict_types=1);

/**
 * 공유된 시간표(여행 스케줄) — 비로그인 공개 열람용 읽기 전용 페이지.
 *
 * @var string                                                                            $date
 * @var array{title: string, body: string}|null                                           $dayNote
 * @var list<array{slot: string, label: string, lat: float|null, lng: float|null, photos: list<array{media_item_id: string, taken_at: string, thumbnail_url: string|null}>, memo: string|null}> $slots
 */

$dateParts = explode('-', $date);
$dateLabel = $dateParts[0] . '년 ' . (int) $dateParts[1] . '월 ' . (int) $dateParts[2] . '일';
$title = ($dayNote['title'] ?? '') !== '' ? $dayNote['title'] : $dateLabel . ' 여행 스케줄';
$description = ($dayNote['body'] ?? '') !== '' ? $dayNote['body'] : $dateLabel . '의 여행 동선을 확인해보세요.';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title) ?> — Iter</title>
    <meta name="robots" content="noindex">
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= esc($title, 'attr') ?>">
    <meta property="og:description" content="<?= esc($description, 'attr') ?>">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <style>
        html, body { margin: 0; font-family: system-ui, sans-serif; background: #f4f5f7; color: #222; }
        .wrap { max-width: 720px; margin: 0 auto; padding: 28px 20px 60px; }
        .brand { font-size: 13px; color: #888; margin-bottom: 18px; }
        .daynote { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 18px 20px; margin-bottom: 22px; }
        .daynote h1 { margin: 0 0 8px; font-size: 20px; }
        .daynote p { margin: 0; color: #555; white-space: pre-wrap; }
        .slot { display: flex; margin-bottom: 20px; }
        .slot-time { flex: none; width: 56px; text-align: right; font-weight: 600; color: #1a73e8; font-size: 13px; padding-top: 2px; }
        .slot-content { flex: 1; min-width: 0; margin-left: 16px; padding-bottom: 4px; border-left: 2px solid #dbe5ff; padding-left: 16px; }
        .slot-photos { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0; }
        .slot-photos img { width: 110px; height: 110px; object-fit: cover; border-radius: 8px; }
        .slot-memo { font-size: 13px; color: #555; margin-top: 6px; }
        .empty { color: #777; padding: 30px 0; text-align: center; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">Iter 로 만든 여행 스케줄</div>
        <div class="daynote">
            <h1><?= esc($title) ?></h1>
            <?php if (($dayNote['body'] ?? '') !== '') : ?>
                <p><?= esc($dayNote['body']) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($slots === []) : ?>
            <div class="empty">이 날짜에 표시할 일정이 없습니다.</div>
        <?php endif; ?>

        <?php foreach ($slots as $slot) : ?>
            <div class="slot">
                <div class="slot-time"><?= esc($slot['label']) ?></div>
                <div class="slot-content">
                    <?php if ($slot['photos'] !== []) : ?>
                        <div class="slot-photos">
                            <?php foreach ($slot['photos'] as $photo) : ?>
                                <?php if ($photo['thumbnail_url'] !== null) : ?>
                                    <img src="<?= esc($photo['thumbnail_url'], 'attr') ?>" alt="" loading="lazy">
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (($slot['memo'] ?? '') !== '') : ?>
                        <div class="slot-memo"><?= esc($slot['memo']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
