<?php

/**
 * @var list<array<string, mixed>> $inquiries
 */
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>문의 목록 · 파구스 운영</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<div class="admin-shell">
    <?= view('admin/_header', ['active' => 'inquiries']) ?>
    <main class="admin-main">
        <h1>문의 목록</h1>
        <?php if (session('message')): ?><p role="status"><?= esc(session('message')) ?></p><?php endif; ?>
        <?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>
        <?php if ($inquiries === []): ?>
            <p class="empty-state">접수된 문의가 없습니다.</p>
        <?php else: ?>
            <table class="admin-table">
                <thead><tr><th>이름</th><th>연락처</th><th>상태</th><th>접수일</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($inquiries as $inquiry): ?>
                    <tr>
                        <td><?= esc((string) $inquiry['name']) ?></td>
                        <td><?= esc((string) ($inquiry['contact'] ?? '')) ?></td>
                        <td><?= esc((string) \App\Enums\InquiryStatus::from((string) $inquiry['status'])->label()) ?></td>
                        <td><?= esc((string) $inquiry['created_at']) ?></td>
                        <td><a href="/admin/inquiries/<?= (int) $inquiry['id'] ?>">상세</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
