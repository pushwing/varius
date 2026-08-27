<?php

/**
 * @var list<array<string, mixed>> $inquiries
 */
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>문의 목록 · 파구스 운영</title></head><body>
<h1>문의 목록</h1><p><a href="/admin">관리 홈으로</a></p>
<?php if (session('message')): ?><p role="status"><?= esc(session('message')) ?></p><?php endif; ?><?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>
<?php if ($inquiries === []): ?>
<p>접수된 문의가 없습니다.</p>
<?php else: ?>
<table><thead><tr><th>이름</th><th>연락처</th><th>상태</th><th>접수일</th><th></th></tr></thead><tbody>
<?php foreach ($inquiries as $inquiry): ?>
<tr><td><?= esc((string) $inquiry['name']) ?></td><td><?= esc((string) ($inquiry['contact'] ?? '')) ?></td><td><?= esc((string) \App\Enums\InquiryStatus::from((string) $inquiry['status'])->label()) ?></td><td><?= esc((string) $inquiry['created_at']) ?></td><td><a href="/admin/inquiries/<?= (int) $inquiry['id'] ?>">상세</a></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</body></html>
