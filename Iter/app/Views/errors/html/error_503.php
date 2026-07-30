<?php
/**
 * 503 Service Unavailable — CodeIgniter4 가 $code, $message 를 넘겨준다.
 * Config/Exceptions.php 의 $views[503] 에 이 파일을 매핑해야 사용된다.
 */
$aivCode    = $code ?? 503;
$aivHeading = '서비스 점검 중입니다';
$aivMessage = (!empty($message)) ? $message : '더 나은 서비스를 위해 점검 중입니다. 잠시 후 다시 이용해 주세요.';

include __DIR__ . '/_partials/page.php';
