<?php
/**
 * 404 Not Found — CodeIgniter4 기본 $views[404] 매핑 대상(별도 설정 불필요).
 * $code, $message 는 CodeIgniter4 가 넘겨준다.
 */
$aivCode    = $code ?? 404;
$aivHeading = '페이지를 찾을 수 없습니다';
$aivMessage = (!empty($message)) ? $message : '주소를 다시 확인하시거나 홈으로 이동해 주세요.';

include __DIR__ . '/_partials/page.php';
