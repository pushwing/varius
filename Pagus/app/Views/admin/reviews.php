<?php

/** @var list<array<string, mixed>> $reviews */
?>
<!doctype html>
<html lang="ko">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>후기 관리 · 파구스 운영</title><link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>"></head>
<body><div class="admin-shell">
    <?= view('admin/_header', ['active' => 'reviews']) ?>
    <main class="admin-main">
        <h1>후기 관리</h1>
        <?php if (session('message')): ?><p role="status"><?= esc(session('message')) ?></p><?php endif; ?>
        <?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>
        <?php if ($reviews === []): ?><p class="empty-state">등록된 후기가 없습니다.</p><?php else: ?>
            <table class="admin-table"><thead><tr><th>맛집</th><th>작성자</th><th>별점</th><th>내용</th><th>신고</th><th>상태</th><th></th></tr></thead><tbody>
            <?php foreach ($reviews as $review): ?><tr>
                <td><?= esc((string) $review['restaurant_name']) ?></td><td><?= esc((string) $review['nickname']) ?></td><td><?= (int) $review['rating'] ?>점</td><td><?= esc((string) $review['content']) ?></td><td><?= (int) $review['report_count'] ?>건</td>
                <td><?= (int) $review['is_hidden'] === 1 ? '숨김' : '공개' ?></td><td><form method="post" action="/admin/reviews/<?= (int) $review['id'] ?>/toggle"><?= csrf_field() ?><button class="btn-ghost btn-sm" type="submit"><?= (int) $review['is_hidden'] === 1 ? '공개' : '숨김' ?></button></form></td>
            </tr><?php endforeach; ?></tbody></table>
        <?php endif; ?>
    </main>
</div></body></html>
