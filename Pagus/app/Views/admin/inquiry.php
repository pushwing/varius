<?php

/**
 * @var array<string, mixed> $inquiry
 * @var list<\App\Enums\InquiryStatus> $statuses
 */
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>문의 상세 · 파구스 운영</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<div class="admin-shell">
    <?= view('admin/_header', ['active' => 'inquiries']) ?>
    <main class="admin-main">
        <p><a href="/admin/inquiries">← 목록으로</a></p>
        <h1>문의 상세</h1>
        <?php if (session('message')): ?><p role="status"><?= esc(session('message')) ?></p><?php endif; ?>
        <?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>
        <div class="admin-section">
            <p>이름: <?= esc((string) $inquiry['name']) ?></p>
            <p>연락처: <?= esc((string) ($inquiry['contact'] ?? '없음')) ?></p>
            <p>내용:<br><?= nl2br(esc((string) $inquiry['message'])) ?></p>
            <p>접수일: <?= esc((string) $inquiry['created_at']) ?></p>
            <form class="form-row" method="post" action="/admin/inquiries/<?= (int) $inquiry['id'] ?>/status">
                <?= csrf_field() ?>
                <label>처리 상태
                    <select name="status">
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= esc($status->value, 'attr') ?>" <?= $inquiry['status'] === $status->value ? 'selected' : '' ?>><?= esc($status->label()) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">저장</button>
            </form>
        </div>
    </main>
</div>
</body>
</html>
