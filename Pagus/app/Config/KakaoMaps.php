<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class KakaoMaps extends BaseConfig
{
    /**
     * 카카오맵 JS SDK용 JavaScript 키.
     * REST API 키(KakaoLocal::$apiKey)와 다른 키이며, 브라우저에 노출되는 값이므로
     * 카카오 개발자 콘솔의 플랫폼 설정에서 사용 도메인을 등록해 오남용을 막는다.
     */
    public string $jsKey = '';
}
