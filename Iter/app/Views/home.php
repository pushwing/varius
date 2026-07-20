<?php

declare(strict_types=1);

/**
 * 홈 화면 — 비로그인 시 랜딩, 로그인 시 상단 메뉴 + Picker 흐름 UI.
 *
 * @var int|null $userId    로그인 사용자 id(비로그인 시 null)
 * @var string   $loginUrl
 * @var string   $logoutUrl
 * @var string   $mapUrl
 * @var string   $sessionsUrl
 * @var string   $statusUrl
 * @var string   $ingestUrl
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iter — 내 동선</title>
    <style>
        html, body { margin: 0; font-family: system-ui, sans-serif; color: #222; }
        nav { display: flex; gap: 16px; padding: 12px 20px; border-bottom: 1px solid #ddd; align-items: center; }
        nav a { color: #222; text-decoration: none; font-size: 14px; }
        nav a:hover { text-decoration: underline; }
        nav .spacer { flex: 1; }
        nav .legal { display: flex; gap: 16px; }
        nav .legal a { color: #777; font-size: 13px; }
        main { padding: 40px 20px; max-width: 480px; margin: 0 auto; text-align: center; }
        .btn {
            display: inline-block; padding: 10px 20px; border-radius: 6px; border: none;
            background: #1a73e8; color: #fff; font-size: 15px; cursor: pointer; text-decoration: none;
        }
        .btn:disabled { background: #9db8e8; cursor: not-allowed; }
        #status { margin-top: 20px; font-size: 14px; color: #555; }
        #error { margin-top: 20px; font-size: 14px; color: #c0392b; }
        .legal-footer { margin-top: 24px; font-size: 13px; }
        .legal-footer a { color: #777; }
    </style>
</head>
<body>
<?php if ($userId === null): ?>
    <main>
        <h1>Iter</h1>
        <p>구글 포토에서 사진을 선택하면 촬영 위치를 지도 위 동선으로 보여줍니다.</p>
        <a class="btn" href="<?= esc($loginUrl, 'attr') ?>">Google로 로그인</a>
        <p class="legal-footer">
            <a href="/privacy-policy.html">개인정보처리방침</a> ·
            <a href="/terms-of-service.html">서비스 이용약관</a>
        </p>
    </main>
<?php else: ?>
    <nav>
        <a href="/">홈</a>
        <a href="<?= esc($mapUrl, 'attr') ?>">지도 보기</a>
        <a href="<?= esc($logoutUrl, 'attr') ?>">로그아웃</a>
        <span class="spacer"></span>
        <span class="legal">
            <a href="/privacy-policy.html">개인정보처리방침</a>
            <a href="/terms-of-service.html">서비스 이용약관</a>
        </span>
    </nav>
    <main
        id="picker-flow"
        data-sessions-url="<?= esc($sessionsUrl, 'attr') ?>"
        data-status-url="<?= esc($statusUrl, 'attr') ?>"
        data-ingest-url="<?= esc($ingestUrl, 'attr') ?>"
        data-map-url="<?= esc($mapUrl, 'attr') ?>"
        data-login-url="<?= esc($loginUrl, 'attr') ?>"
    >
        <h1>사진 가져오기</h1>
        <button id="start-picker" class="btn">사진 선택하기</button>
        <div id="status"></div>
        <div id="error"></div>
    </main>

    <script>
        (function () {
            var root = document.getElementById('picker-flow');
            var startBtn = document.getElementById('start-picker');
            var statusEl = document.getElementById('status');
            var errorEl = document.getElementById('error');
            var pollTimer = null;
            var pollCount = 0;
            var MAX_POLLS = 150;
            var POLL_INTERVAL_MS = 2000;

            var urls = {
                sessions: root.dataset.sessionsUrl,
                status: root.dataset.statusUrl,
                ingest: root.dataset.ingestUrl,
                map: root.dataset.mapUrl,
                login: root.dataset.loginUrl
            };

            startBtn.addEventListener('click', startFlow);

            function startFlow() {
                reset();
                setBusy(true);
                statusEl.textContent = '세션을 만드는 중...';

                fetch(urls.sessions, { method: 'POST', headers: { Accept: 'application/json' } })
                    .then(handleJson)
                    .then(function (data) {
                        if (!data.pickerUri) { throw new Error('pickerUri 를 받지 못했습니다'); }
                        openPicker(data.pickerUri);
                        statusEl.textContent = '선택을 기다리는 중...';
                        pollCount = 0;
                        pollTimer = setTimeout(pollStatus, POLL_INTERVAL_MS);
                    })
                    .catch(onError);
            }

            function openPicker(pickerUri) {
                var win = window.open(pickerUri, '_blank');
                if (!win) {
                    var link = document.createElement('a');
                    link.id = 'picker-link';
                    link.href = pickerUri;
                    link.target = '_blank';
                    link.textContent = '여기를 눌러 구글 포토에서 사진을 선택하세요';
                    root.insertBefore(link, statusEl);
                }
            }

            function pollStatus() {
                pollCount++;
                if (pollCount > MAX_POLLS) {
                    onError(new Error('선택 시간이 초과됐습니다'));
                    return;
                }

                fetch(urls.status, { headers: { Accept: 'application/json' } })
                    .then(handleJson)
                    .then(function (data) {
                        if (data.mediaItemsSet) {
                            statusEl.textContent = '사진을 저장하는 중...';
                            ingest();
                        } else {
                            pollTimer = setTimeout(pollStatus, POLL_INTERVAL_MS);
                        }
                    })
                    .catch(onError);
            }

            function ingest() {
                fetch(urls.ingest, { method: 'POST', headers: { Accept: 'application/json' } })
                    .then(handleJson)
                    .then(function (data) {
                        statusEl.textContent = data.saved + '장 저장됨';
                        var link = document.createElement('a');
                        link.className = 'btn';
                        link.href = urls.map;
                        link.textContent = '지도에서 보기';
                        link.style.marginTop = '12px';
                        link.style.display = 'inline-block';
                        root.appendChild(link);
                        setBusy(false);
                    })
                    .catch(onError);
            }

            function handleJson(res) {
                if (!res.ok) {
                    return res.json().catch(function () { return {}; }).then(function (body) {
                        var err = new Error(body.error || ('요청 실패(' + res.status + ')'));
                        err.status = res.status;
                        throw err;
                    });
                }
                return res.json();
            }

            function onError(err) {
                clearTimeout(pollTimer);
                errorEl.textContent = (err && err.message) || '오류가 발생했습니다';
                errorEl.appendChild(document.createElement('br'));

                if (err && err.status === 401) {
                    // 세션 만료 등 인증 실패는 재시도 대신 로그인 페이지로 안내한다.
                    var loginLink = document.createElement('a');
                    loginLink.className = 'btn';
                    loginLink.href = urls.login;
                    loginLink.textContent = '다시 로그인하기';
                    errorEl.appendChild(loginLink);
                } else {
                    var retry = document.createElement('button');
                    retry.className = 'btn';
                    retry.type = 'button';
                    retry.textContent = '다시 시도';
                    retry.addEventListener('click', reset);
                    errorEl.appendChild(retry);
                }
                setBusy(false);
            }

            function setBusy(busy) {
                startBtn.disabled = busy;
            }

            function reset() {
                clearTimeout(pollTimer);
                pollCount = 0;
                statusEl.textContent = '';
                errorEl.innerHTML = '';
                var existingLink = document.getElementById('picker-link');
                if (existingLink) { existingLink.remove(); }
                setBusy(false);
            }
        })();
    </script>
<?php endif; ?>
</body>
</html>
