<?php
/**
 * 403 Forbidden — CodeIgniter4 가 $code, $message 를 넘겨준다.
 * Config/Exceptions.php 의 $views[403] 에 이 파일을 매핑해야 사용된다.
 */
$aivCode    = $code ?? 403;
$aivHeading = '접근 권한이 없습니다';
$aivMessage = (!empty($message)) ? $message : '이 페이지에 접근할 수 있는 권한이 없습니다.';

include __DIR__ . '/_partials/page.php';
