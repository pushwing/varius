<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>운영자 로그인 · 파구스</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
    <link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
    <link rel="icon" type="image/svg+xml" href="<?= base_url('favicon.svg') ?>">
    <link rel="apple-touch-icon" href="<?= base_url('apple-touch-icon.png') ?>">
</head>
<body>
<div class="auth-shell">
    <div class="auth-card">
        <h1>파구스 운영자 로그인</h1>
        <?php if (session('error')): ?><p role="alert"><?= esc(session('error')) ?></p><?php endif; ?>
        <form class="form-grid" method="post" action="/login">
            <?= csrf_field() ?>
            <label>이메일 <input type="email" name="email" required autocomplete="username"></label>
            <label>비밀번호 <input type="password" name="password" required autocomplete="current-password"></label>
            <button type="submit">로그인</button>
        </form>
    </div>
</div>
</body>
</html>
