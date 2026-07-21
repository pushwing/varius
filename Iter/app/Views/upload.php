<?php

declare(strict_types=1);

/**
 * 사진 가져오기 화면 — Takeout zip 업로드 폼(로그인 사용자 전용, GET /upload).
 *
 * @var string $loginUrl
 * @var string $logoutUrl
 * @var string $mapUrl
 * @var string $uploadUrl
 * @var string $deleteUrl
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>사진 가져오기 — Iter</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
    <link rel="stylesheet" href="/assets/nav.css">
    <style>
        html, body { margin: 0; font-family: system-ui, sans-serif; color: #222; }
        main { padding: 40px 20px; max-width: 480px; margin: 0 auto; text-align: center; }
        .btn {
            display: inline-block; padding: 10px 20px; border-radius: 8px; border: 1.5px solid transparent;
            background: #1a73e8; color: #fff; font-size: 15px; font-weight: 600; cursor: pointer;
            text-decoration: none; text-align: center; transition: background .15s, border-color .15s;
        }
        .btn:hover { background: #1558b0; }
        .btn:disabled { background: #9db8e8; cursor: not-allowed; }
        .btn-block { display: block; width: 100%; box-sizing: border-box; }
        .btn-secondary { background: #fff; color: #1a73e8; border-color: #c7d2e0; }
        .btn-secondary:hover { background: #f0f4ff; border-color: #1a73e8; }
        .help { font-size: 13px; color: #666; text-align: left; line-height: 1.6; }
        .help a { color: #1a73e8; }
        .sr-only {
            position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
        }

        .upload-card {
            margin-top: 24px; background: #fff; border: 1px solid #eee; border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); padding: 28px 24px; text-align: left;
        }
        .upload-card h1 { font-size: 20px; margin: 0 0 10px; text-align: left; }
        .upload-card .lead-text { margin: 0 0 14px; font-size: 14px; color: #444; line-height: 1.6; }
        .upload-card .help { margin-bottom: 20px; }
        .upload-notes {
            margin: 20px 0 0; padding: 14px 16px; border-radius: 10px;
            background: #f7f8fa; color: #666; font-size: 12px; line-height: 1.7;
            list-style: none;
        }
        .upload-notes li { padding-left: 14px; position: relative; }
        .upload-notes li::before { content: "·"; position: absolute; left: 0; color: #aaa; }
        .upload-notes li + li { margin-top: 2px; }
        .file-picker {
            display: block; border: 1.5px dashed #c7d2e0; border-radius: 10px;
            padding: 22px 16px; text-align: center; cursor: pointer;
            background: #f7f9fc; transition: border-color .15s, background .15s;
        }
        .file-picker:hover, .file-picker:focus-within { border-color: #1a73e8; background: #eef4ff; }
        .file-picker .icon { font-size: 22px; display: block; margin-bottom: 6px; }
        .file-picker .primary-text { font-size: 14px; font-weight: 600; color: #333; }
        .file-picker .filename {
            display: block; margin-top: 6px; font-size: 13px; color: #1a73e8; font-weight: 600;
            word-break: break-all;
        }
        .file-picker .hint { display: block; margin-top: 4px; font-size: 12px; color: #999; }
        #upload-btn { margin-top: 16px; }
        .status-box, .error-box {
            margin-top: 18px; padding: 14px 16px; border-radius: 10px; font-size: 14px; line-height: 1.5;
        }
        .status-box { background: #eaf6ec; color: #1e7e34; }
        .error-box { background: #fdecea; color: #c0392b; }
        .status-box[hidden], .error-box[hidden] { display: none; }
        .status-box .action, .error-box .action { margin-top: 10px; }

        .danger-zone {
            margin-top: 20px; background: #fff; border: 1px solid #f3d6d3; border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); padding: 20px 24px; text-align: left;
        }
        .danger-zone h2 { font-size: 15px; margin: 0 0 8px; color: #c0392b; }
        .danger-zone p { margin: 0 0 14px; font-size: 13px; color: #666; line-height: 1.6; }
        .btn-danger { background: #fff; color: #c0392b; border-color: #e6b8b3; }
        .btn-danger:hover { background: #fdecea; border-color: #c0392b; }
        .flash-box {
            margin-top: 18px; padding: 14px 16px; border-radius: 10px; font-size: 14px; line-height: 1.5;
        }
        .flash-message { background: #eaf6ec; color: #1e7e34; }
        .flash-error { background: #fdecea; color: #c0392b; }
    </style>
</head>
<body>
    <?= view('partials/nav', ['uploadUrl' => $uploadUrl, 'mapUrl' => $mapUrl, 'logoutUrl' => $logoutUrl]) ?>
    <main id="takeout-flow">
        <?php if (session()->getFlashdata('message')) : ?>
            <div class="flash-box flash-message"><?= esc(session()->getFlashdata('message')) ?></div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')) : ?>
            <div class="flash-box flash-error"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>
        <div class="upload-card">
            <h1>사진 가져오기</h1>
            <p class="lead-text">
                업로드한 사진의 GPS 좌표와 촬영 시각을 자동으로 추출해, 날짜별 색으로 구분된
                마커와 경로선으로 지도 위에 동선을 그려드립니다.
            </p>
            <p class="help">
                <a href="https://takeout.google.com" target="_blank" rel="noopener">takeout.google.com</a>에서
                "Google Photos"만 선택해 내보낸 뒤, 받은 zip 파일을 업로드하세요.
            </p>
            <form id="takeout-form" data-upload-url="<?= esc($uploadUrl, 'attr') ?>" data-map-url="<?= esc($mapUrl, 'attr') ?>">
                <label class="file-picker" for="takeout-file">
                    <input type="file" id="takeout-file" name="file" accept=".zip" class="sr-only">
                    <span class="icon" aria-hidden="true">📦</span>
                    <span class="primary-text" id="file-picker-text">zip 파일 선택</span>
                    <span class="hint">최대 500MB</span>
                </label>
                <button type="submit" id="upload-btn" class="btn btn-block">업로드</button>
            </form>
            <div id="status" class="status-box" hidden></div>
            <div id="error" class="error-box" hidden></div>
            <ul class="upload-notes">
                <li>zip은 최대 500MB, 좌표는 사용자당 최대 200장까지 추출합니다.</li>
                <li>업로드된 zip은 위치·시각 정보 추출이 끝나는 즉시 서버에서 삭제되며, 원본 사진 파일은 저장하지 않습니다.</li>
                <li>위치 정보가 없는 사진은 지도에 표시되지 않으며, 업로드는 시간당 일정 횟수로 제한됩니다.</li>
            </ul>
        </div>

        <section class="danger-zone">
            <h2>내 데이터 삭제</h2>
            <p>
                지금까지 업로드해 저장된 GPS 좌표·동선과 썸네일, 그리고 Google 계정 연동 토큰을
                모두 영구 삭제합니다. 이 작업은 되돌릴 수 없습니다.
            </p>
            <form id="delete-form" method="post" action="<?= esc($deleteUrl, 'attr') ?>">
                <button type="submit" class="btn btn-block btn-danger">내 데이터 전체 삭제</button>
            </form>
        </section>
    </main>

    <script>
        (function () {
            var form = document.getElementById('takeout-form');
            var fileInput = document.getElementById('takeout-file');
            var filePickerText = document.getElementById('file-picker-text');
            var uploadBtn = document.getElementById('upload-btn');
            var statusEl = document.getElementById('status');
            var errorEl = document.getElementById('error');
            var uploadUrl = form.dataset.uploadUrl;
            var mapUrl = form.dataset.mapUrl;
            var defaultPickerText = filePickerText.textContent;

            fileInput.addEventListener('change', function () {
                filePickerText.textContent = fileInput.files.length
                    ? fileInput.files[0].name
                    : defaultPickerText;
            });

            form.addEventListener('submit', function (evt) {
                evt.preventDefault();

                if (!fileInput.files || fileInput.files.length === 0) {
                    showError('zip 파일을 선택해주세요');
                    return;
                }

                reset();
                setBusy(true);
                showStatus('처리 중...');

                var body = new FormData();
                body.append('file', fileInput.files[0]);

                fetch(uploadUrl, { method: 'POST', body: body, headers: { Accept: 'application/json' } })
                    .then(handleJson)
                    .then(function (data) {
                        var message = data.saved + '장 저장됨';
                        if (data.capped) {
                            message += ' (' + data.totalCandidates + '장 중 상한까지만 처리됨)';
                        } else if (data.totalCandidates > data.saved) {
                            message += ' (위치 정보를 찾지 못한 ' + (data.totalCandidates - data.saved) + '장 제외)';
                        }

                        showStatus(message, mapUrl, '지도에서 보기');
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

            function showStatus(message, actionHref, actionLabel) {
                statusEl.innerHTML = '';
                statusEl.appendChild(document.createTextNode(message));

                if (actionHref) {
                    var link = document.createElement('a');
                    link.className = 'btn action';
                    link.href = actionHref;
                    link.textContent = actionLabel;
                    statusEl.appendChild(document.createElement('br'));
                    statusEl.appendChild(link);
                }

                statusEl.hidden = false;
            }

            function showError(message, isAuthError) {
                errorEl.innerHTML = '';
                errorEl.appendChild(document.createTextNode(message));

                var action = document.createElement('div');
                action.className = 'action';

                if (isAuthError) {
                    var loginLink = document.createElement('a');
                    loginLink.className = 'btn';
                    loginLink.href = '<?= esc($loginUrl, 'js') ?>';
                    loginLink.textContent = '다시 로그인하기';
                    action.appendChild(loginLink);
                } else {
                    var retry = document.createElement('button');
                    retry.className = 'btn btn-secondary';
                    retry.type = 'button';
                    retry.textContent = '다시 시도';
                    retry.addEventListener('click', reset);
                    action.appendChild(retry);
                }

                errorEl.appendChild(action);
                errorEl.hidden = false;
                setBusy(false);
            }

            function setBusy(busy) {
                uploadBtn.disabled = busy;
            }

            function reset() {
                statusEl.hidden = true;
                statusEl.innerHTML = '';
                errorEl.hidden = true;
                errorEl.innerHTML = '';
                setBusy(false);
            }

            var deleteForm = document.getElementById('delete-form');
            if (deleteForm) {
                deleteForm.addEventListener('submit', function (evt) {
                    if (!window.confirm('저장된 GPS 좌표·동선·썸네일과 Google 연동을 모두 삭제합니다. 되돌릴 수 없습니다. 계속할까요?')) {
                        evt.preventDefault();
                    }
                });
            }
        })();
    </script>
</body>
</html>
