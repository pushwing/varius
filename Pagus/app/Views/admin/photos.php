<?php

/**
 * @var array<string, mixed> $restaurant
 * @var list<array<string, mixed>> $photos
 */
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= esc((string) $restaurant['name']) ?> 사진 관리 · 파구스 운영</title></head><body>
<h1><?= esc((string) $restaurant['name']) ?> 사진 관리</h1><p><a href="/admin">목록으로</a></p>
<?php if (session('message')): ?><p role="status"><?= esc(session('message')) ?></p><?php endif; ?><?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>
<form method="post" action="/admin/restaurants/<?= (int) $restaurant['id'] ?>/photos/upload" enctype="multipart/form-data">
<?= csrf_field() ?>
<label>사진 선택(jpg/png/webp, 장당 5MB 이하) <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></label>
<button type="submit">업로드</button>
</form>
<ul>
<?php foreach ($photos as $photo): ?>
<li>
<img src="/admin/restaurants/<?= (int) $restaurant['id'] ?>/photos/<?= (int) $photo['id'] ?>/file" alt="<?= esc((string) $photo['original_name']) ?>" width="160">
<?= esc((string) $photo['original_name']) ?> (<?= (int) $photo['is_hidden'] === 1 ? '숨김' : '공개' ?>)
<form method="post" action="/admin/restaurants/<?= (int) $restaurant['id'] ?>/photos/<?= (int) $photo['id'] ?>/toggle" style="display:inline"><?= csrf_field() ?><button type="submit"><?= (int) $photo['is_hidden'] === 1 ? '공개' : '숨김' ?></button></form>
<form method="post" action="/admin/restaurants/<?= (int) $restaurant['id'] ?>/photos/<?= (int) $photo['id'] ?>/delete" style="display:inline" onsubmit="return confirm('삭제하시겠습니까?');"><?= csrf_field() ?><button type="submit">삭제</button></form>
</li>
<?php endforeach; ?>
</ul>
</body></html>
