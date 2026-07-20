<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Google OAuth2 설정.
 *
 * 시크릿은 .env 에서만 로드한다(하드코딩 금지):
 *   google.oauth.clientId, google.oauth.clientSecret, google.oauth.redirectUri
 */
class GoogleOAuth extends BaseConfig
{
    /**
     * Google OAuth2 클라이언트 ID (.env: google.oauth.clientId).
     */
    public string $clientId = '';

    /**
     * Google OAuth2 클라이언트 시크릿 (.env: google.oauth.clientSecret).
     */
    public string $clientSecret = '';

    /**
     * 콜백 리다이렉트 URI (.env: google.oauth.redirectUri).
     */
    public string $redirectUri = '';

    /**
     * 요청 스코프.
     *
     * openid/email/profile 은 사용자 식별(sub·email·name)용,
     * photospicker.mediaitems.readonly 는 Picker API 접근용.
     *
     * @var list<string>
     */
    public array $scopes = [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/photospicker.mediaitems.readonly',
    ];

    public function __construct()
    {
        parent::__construct();

        // .env 의 google.oauth.* 키에서 명시적으로 로드한다(하드코딩 금지).
        $this->clientId = $this->envString('google.oauth.clientId', $this->clientId);
        $this->clientSecret = $this->envString('google.oauth.clientSecret', $this->clientSecret);
        $this->redirectUri = $this->envString('google.oauth.redirectUri', $this->redirectUri);
    }

    private function envString(string $key, string $default): string
    {
        $value = env($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
