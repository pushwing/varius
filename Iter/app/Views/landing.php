<?php

declare(strict_types=1);

/**
 * 랜딩 화면 — 로그인 여부와 무관하게 '/'에서 항상 렌더된다.
 * 로그인 사용자에게는 상단 메뉴를 보여주고, 로그인 CTA 대신 업로드 화면으로 가는 버튼을 보여준다.
 *
 * @var int|null $userId    로그인 사용자 id(비로그인 시 null)
 * @var string   $loginUrl
 * @var string   $uploadUrl
 * @var string   $mapUrl
 * @var string   $logoutUrl
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
        .legal-footer { margin-top: 24px; font-size: 13px; }
        .legal-footer a { color: #777; }
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
<?php if ($userId !== null): ?>
    <?= view('partials/nav', ['uploadUrl' => $uploadUrl, 'mapUrl' => $mapUrl, 'logoutUrl' => $logoutUrl]) ?>
<?php endif; ?>
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

        <?php if ($userId !== null): ?>
            <a class="btn" href="<?= esc($uploadUrl, 'attr') ?>">사진 가져오기</a>
        <?php else: ?>
            <a class="btn" href="<?= esc($loginUrl, 'attr') ?>">Google로 로그인</a>
        <?php endif; ?>

        <p class="privacy-note">
            업로드된 zip은 위치·시각 정보 추출이 끝나는 즉시 서버에서 삭제됩니다.
            원본 사진 파일은 저장하지 않습니다.
        </p>

        <?php if ($userId === null): ?>
            <p class="legal-footer">
                <a href="/privacy-policy.html">개인정보처리방침</a> ·
                <a href="/terms-of-service.html">서비스 이용약관</a>
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
