<?php
/**
 * 400 Bad Request — CodeIgniter4 가 $code, $message 를 넘겨준다.
 * Config/Exceptions.php 의 $views[400] 에 이 파일을 매핑해야 사용된다.
 */
$aivCode    = $code ?? 400;
$aivHeading = '잘못된 요청입니다';
$aivMessage = (!empty($message)) ? $message : '요청 형식이 올바르지 않습니다. 입력값을 확인한 뒤 다시 시도해 주세요.';

include __DIR__ . '/_partials/page.php';
