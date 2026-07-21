<?php

declare(strict_types=1);

/**
 * 홈 화면 — 비로그인 시 랜딩, 로그인 시 상단 메뉴 + Takeout zip 업로드 폼.
 *
 * @var int|null $userId    로그인 사용자 id(비로그인 시 null)
 * @var string   $loginUrl
 * @var string   $logoutUrl
 * @var string   $mapUrl
 * @var string   $uploadUrl
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Google Takeout으로 내보낸 사진의 GPS·촬영 시각을 추출해 날짜별 이동 동선을 지도 위에 시각화하는 서비스입니다.">
    <meta property="og:site_name" content="Iter">
    <meta property="og:image" content="/assets/logo-mark-512.png">
    <title>Iter</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
    <style>
        html, body { margin: 0; font-family: system-ui, sans-serif; color: #222; }
        nav { display: flex; gap: 16px; padding: 12px 20px; border-bottom: 1px solid #ddd; align-items: center; }
        nav a { color: #222; text-decoration: none; font-size: 14px; }
        nav .brand { display: inline-flex; }
        nav .brand img { height: 24px; }
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
        .help { margin-top: 16px; font-size: 13px; color: #666; text-align: left; line-height: 1.6; }
        .help a { color: #1a73e8; }
        input[type="file"] { margin-top: 20px; }
        .sr-only {
            position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
        }

        main.landing { max-width: 560px; }
        .landing .logo { height: 40px; margin-bottom: 16px; }
        .landing .lead { font-size: 16px; color: #444; margin-bottom: 8px; }
        .landing .sub { font-size: 14px; color: #777; margin-bottom: 32px; }
        .landing .btn { padding: 12px 28px; font-size: 16px; }
        .steps {
            list-style: none; margin: 32px 0; padding: 0;
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; text-align: left;
        }
        .steps li { background: #f7f8fa; border-radius: 10px; padding: 16px; }
        .steps .num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 50%;
            background: #1a73e8; color: #fff; font-size: 12px; font-weight: 700;
            margin-bottom: 8px;
        }
        .steps .title { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
        .steps .desc { font-size: 13px; color: #666; line-height: 1.5; }
        .privacy-note {
            margin-top: 28px; padding: 14px 16px; border-radius: 10px;
            background: #eef4ff; color: #3a5a9c; font-size: 13px; text-align: left; line-height: 1.6;
        }
        @media (max-width: 520px) {
            .steps { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php if ($userId === null): ?>
    <main class="landing">
        <img class="logo" src="/assets/logo-wordmark.png" alt="Iter">
        <h1 class="sr-only">Iter</h1>
        <p class="lead">내가 찍은 사진 속 GPS로, 여행의 동선을 지도 위에 그려드립니다.</p>
        <p class="sub">Google Takeout에서 내보낸 사진 zip을 업로드하면, 언제 어디를 다녀왔는지 날짜별 경로로 한눈에 확인할 수 있어요.</p>

        <ul class="steps">
            <li>
                <span class="num">1</span>
                <div class="title">Takeout 내보내기</div>
                <div class="desc">Google 계정에서 사진(Google Photos)만 선택해 zip으로 내보냅니다.</div>
            </li>
            <li>
                <span class="num">2</span>
                <div class="title">zip 업로드</div>
                <div class="desc">받은 zip 파일을 그대로 업로드하면 위치·시간을 자동으로 읽어옵니다.</div>
            </li>
            <li>
                <span class="num">3</span>
                <div class="title">동선 확인</div>
                <div class="desc">날짜별 색으로 구분된 마커와 경로선으로 지도 위에서 동선을 확인합니다.</div>
            </li>
        </ul>

        <a class="btn" href="<?= esc($loginUrl, 'attr') ?>">Google로 로그인</a>

        <p class="privacy-note">
            업로드된 zip은 위치·시각 정보 추출이 끝나는 즉시 서버에서 삭제됩니다.
            원본 사진 파일은 저장하지 않습니다.
        </p>

        <p class="legal-footer">
            <a href="/privacy-policy.html">개인정보처리방침</a> ·
            <a href="/terms-of-service.html">서비스 이용약관</a>
        </p>
    </main>
<?php else: ?>
    <nav>
        <a href="/" class="brand"><img src="/assets/logo-mark-512.png" alt="Iter"></a>
        <a href="/">홈</a>
        <a href="<?= esc($mapUrl, 'attr') ?>">지도 보기</a>
        <a href="<?= esc($logoutUrl, 'attr') ?>">로그아웃</a>
        <span class="spacer"></span>
        <span class="legal">
            <a href="/privacy-policy.html">개인정보처리방침</a>
            <a href="/terms-of-service.html">서비스 이용약관</a>
        </span>
    </nav>
    <main id="takeout-flow">
        <h1>사진 가져오기</h1>
        <p class="help">
            <a href="https://takeout.google.com" target="_blank" rel="noopener">takeout.google.com</a>에서
            "Google Photos"만 선택해 내보낸 뒤, 받은 zip 파일을 업로드하세요.
        </p>
        <form id="takeout-form" data-upload-url="<?= esc($uploadUrl, 'attr') ?>" data-map-url="<?= esc($mapUrl, 'attr') ?>">
            <input type="file" id="takeout-file" name="file" accept=".zip">
            <div>
                <button type="submit" id="upload-btn" class="btn">업로드</button>
            </div>
        </form>
        <div id="status"></div>
        <div id="error"></div>
    </main>

    <script>
        (function () {
            var form = document.getElementById('takeout-form');
            var fileInput = document.getElementById('takeout-file');
            var uploadBtn = document.getElementById('upload-btn');
            var statusEl = document.getElementById('status');
            var errorEl = document.getElementById('error');
            var uploadUrl = form.dataset.uploadUrl;
            var mapUrl = form.dataset.mapUrl;

            form.addEventListener('submit', function (evt) {
                evt.preventDefault();

                if (!fileInput.files || fileInput.files.length === 0) {
                    showError('zip 파일을 선택해주세요');
                    return;
                }

                reset();
                setBusy(true);
                statusEl.textContent = '처리 중...';

                var body = new FormData();
                body.append('file', fileInput.files[0]);

                fetch(uploadUrl, { method: 'POST', body: body, headers: { Accept: 'application/json' } })
                    .then(handleJson)
                    .then(function (data) {
                        var message = data.saved + '장 저장됨';
                        if (data.capped) {
                            message += '(' + data.totalCandidates + '장 중 상한까지만 처리됨)';
                        } else if (data.totalCandidates > data.saved) {
                            message += '(위치 정보를 찾지 못한 ' + (data.totalCandidates - data.saved) + '장 제외)';
                        }
                        statusEl.textContent = message;

                        var link = document.createElement('a');
                        link.className = 'btn';
                        link.href = mapUrl;
                        link.textContent = '지도에서 보기';
                        link.style.marginTop = '12px';
                        link.style.display = 'inline-block';
                        document.getElementById('takeout-flow').appendChild(link);
                        setBusy(false);
                    })
                    .catch(onError);
            });

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
                showError((err && err.message) || '오류가 발생했습니다', err && err.status === 401);
            }

            function showError(message, isAuthError) {
                errorEl.textContent = message;
                errorEl.appendChild(document.createElement('br'));

                if (isAuthError) {
                    var loginLink = document.createElement('a');
                    loginLink.className = 'btn';
                    loginLink.href = '<?= esc($loginUrl, 'js') ?>';
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
                uploadBtn.disabled = busy;
            }

            function reset() {
                statusEl.textContent = '';
                errorEl.innerHTML = '';
                setBusy(false);
            }
        })();
    </script>
<?php endif; ?>
</body>
</html>
