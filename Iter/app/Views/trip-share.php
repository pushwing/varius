<?php

declare(strict_types=1);

/**
 * 공유된 여행 — 비로그인 공개 열람용 읽기 전용 요약 페이지.
 *
 * @var array{title: string, body: string, start_date: string, end_date: string} $trip
 * @var list<array{date: string, photo_count: int, thumbnail_urls: list<string>}> $days
 */

$startParts = explode('-', $trip['start_date']);
$endParts = explode('-', $trip['end_date']);
$rangeLabel = $trip['start_date'] === $trip['end_date']
    ? $startParts[0] . '년 ' . (int) $startParts[1] . '월 ' . (int) $startParts[2] . '일'
    : $startParts[0] . '년 ' . (int) $startParts[1] . '월 ' . (int) $startParts[2] . '일 ~ '
        . (($startParts[0] !== $endParts[0]) ? $endParts[0] . '년 ' : '') . (int) $endParts[1] . '월 ' . (int) $endParts[2] . '일';
$title = $trip['title'] !== '' ? $trip['title'] : $rangeLabel . ' 여행';
$description = $trip['body'] !== '' ? $trip['body'] : $rangeLabel . '의 여행 기록을 확인해보세요.';
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
        .trip-header { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 18px 20px; margin-bottom: 22px; }
        .trip-header h1 { margin: 0 0 6px; font-size: 20px; }
        .trip-header .range { font-size: 13px; color: #777; margin-bottom: 8px; }
        .trip-header p { margin: 0; color: #555; white-space: pre-wrap; }
        .day-section { margin-bottom: 26px; }
        .day-section h2 { font-size: 15px; margin: 0 0 8px; color: #1a73e8; }
        .day-photos { display: flex; flex-wrap: wrap; gap: 8px; }
        .day-photos img { width: 110px; height: 110px; object-fit: cover; border-radius: 8px; }
        .empty { color: #777; padding: 30px 0; text-align: center; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">Iter 로 만든 여행 기록</div>
        <div class="trip-header">
            <h1><?= esc($title) ?></h1>
            <div class="range"><?= esc($rangeLabel) ?></div>
            <?php if ($trip['body'] !== '') : ?>
                <p><?= esc($trip['body']) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($days === []) : ?>
            <div class="empty">표시할 사진이 없습니다.</div>
        <?php endif; ?>

        <?php foreach ($days as $day) : ?>
            <div class="day-section">
                <?php $dp = explode('-', $day['date']); ?>
                <h2><?= (int) $dp[1] ?>월 <?= (int) $dp[2] ?>일 · 사진 <?= (int) $day['photo_count'] ?>장</h2>
                <?php if ($day['thumbnail_urls'] !== []) : ?>
                    <div class="day-photos">
                        <?php foreach ($day['thumbnail_urls'] as $url) : ?>
                            <img src="<?= esc($url, 'attr') ?>" alt="" loading="lazy">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
