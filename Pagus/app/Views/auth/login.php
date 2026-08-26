<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>운영자 로그인</title></head><body>
<h1>파구스 운영자 로그인</h1>
<?php if (session('error')): ?><p><?= esc(session('error')) ?></p><?php endif; ?>
<form method="post" action="/login"><?= csrf_field() ?><label>이메일 <input type="email" name="email" required></label><label>비밀번호 <input type="password" name="password" required></label><button type="submit">로그인</button></form>
</body></html>
